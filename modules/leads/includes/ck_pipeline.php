<?php
/**
 * ck_pipeline.php — where each booking is between confirmation and _CK, from the CK tracker row (ck_lib.php) and its
 * open Booking follow-up lines (followup_service.php: the one list fed by the supplier / ticket / group scans,
 * SafariCheck, the Claude booking check and staff). Shared by the CK tracker page, the Agent API (ck_status,
 * rename_folder), folder renames (folder_rename(), ck_set_marker()) and the memo digest.
 *
 *   confirmed  booked, bookings not done yet (no supplier document in invoices/)
 *   booked     supplier documents present, not checked yet (no SafariCheck run and no Claude check)
 *   issues     at least one open follow-up line (any check, any audience)
 *   ready      SafariCheck and the Claude check done, nothing open, nothing provisional
 *   ck         _CK set
 *
 * Check early, then again (Roberto, 25 Oct 2026): the first Claude check is due when the bookings are done (booked for
 * more than 7 days without a check), then again when the last check is older than 60 and 30 days before arrival
 * (next_check_reason first_check | recheck_60 | recheck_30).
 * _CK is blocked while a booking is provisional (open line with a provisional issue from any check, or a document in
 * invoices/ read as provisional by the last supplier scan) — no override; other open lines only warn.
 */
require_once __DIR__ . '/ck_lib.php';
require_once __DIR__ . '/../../operations/includes/followup_service.php';

const CK_PL_FIRST_CHECK_DAYS = 7;          // booked this long without a Claude check → first_check
const CK_PL_RECHECKS = [30, 60];           // re-check when the last check is older than N days before arrival (tightest first)
const CK_PL_STAGES = ['confirmed' => 'Confirmed', 'booked' => 'Booked', 'issues' => 'Issues', 'ready' => 'Ready', 'ck' => 'CK'];
const CK_PL_CHECK_REASONS = ['first_check' => 'First check (bookings done)', 'recheck_60' => 'Re-check (60 days before arrival)',
                             'recheck_30' => 'Re-check (30 days before arrival)'];

/** Open follow-up lines with deadline / priority / sources: ['req' => [request_id => [lines]], 'folder' => [stem => [lines]]]. */
function ck_pipeline_lines(PDO $db, string $today, ?array $requestIds = null): array {
    followup_schema($db);
    $sql = "SELECT b.*, u.full_name AS owner_name, u.username AS owner_username FROM booking_followups b
            LEFT JOIN users u ON u.id = b.owner_user_id WHERE b.closed_at IS NULL";
    if ($requestIds !== null) $sql .= " AND (b.request_id IN (" . implode(',', array_map('intval', $requestIds) ?: [0]) . ") OR b.request_id IS NULL)";
    $out = ['req' => [], 'folder' => []];
    foreach ($db->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $l) {
        $l['_due'] = followup_due($l);
        $l['_prio'] = followup_priority($l, $today);
        $l['_src'] = followup_sources($l);
        if ($l['request_id'] !== null) $out['req'][(int)$l['request_id']][] = $l;
        elseif ($l['folder']) $out['folder'][dropbox_folder_stem(explode('/', ltrim((string)$l['folder'], '/'))[0])][] = $l;
    }
    return $out;
}

/** The open lines of a tracker row: its requests' lines + group lines of its folder without a request. */
function ck_pipeline_row_lines(array $lines, array $row): array {
    $ls = [];
    foreach ($row['reqs'] as $q) foreach ($lines['req'][(int)$q['id']] ?? [] as $l) $ls[(int)$l['id']] = $l;
    foreach ($lines['folder'][dropbox_folder_stem($row['folder_name'])] ?? [] as $l) $ls[(int)$l['id']] = $l;
    return array_values($ls);
}

/** A line meaning "a booking is still provisional" (its issue, or the issue any check reports on it). */
function ck_pipeline_is_provisional(array $l): bool {
    if (in_array($l['issue'], FU_PROVISIONAL_ISSUES, true)) return true;
    foreach ($l['_src'] ?? followup_sources($l) as $e) if (in_array($e['issue'] ?? '', FU_PROVISIONAL_ISSUES, true)) return true;
    return false;
}

/** "Serengeti Classic View 29 Dec – provisional, expired 31 Jul". */
function ck_pipeline_block_text(?string $who, ?string $date, string $issue, ?string $expiry, string $today): string {
    $what = in_array($issue, ['on_request'], true) ? 'on request' : ($issue === 'orangi_rooms_provisional' ? 'rooms still provisional' : 'provisional');
    return trim(trim((string)$who) . ($date ? ' ' . date('j M', strtotime($date)) : '')) . ' – ' . $what
         . ($expiry ? ', ' . ($expiry < $today ? 'expired ' : 'expires ') . date('j M', strtotime($expiry)) : '');
}

/**
 * Why _CK cannot be set on this tracker row: open lines with a provisional issue (any check) and — with a Dropbox
 * token — the documents now in invoices/ (not old/) that the last supplier scan read as provisional / on request.
 * → ['blocked' => bool, 'by' => [text], 'lines' => [line ids], 'files' => [invoices/…]]
 */
function ck_pipeline_block(PDO $db, array $row, ?string $token = null, ?array $rowLines = null): array {
    $today = ck_now('Y-m-d');
    if ($rowLines === null) {
        $lines = ck_pipeline_lines($db, $today, array_map(fn($q) => (int)$q['id'], $row['reqs']));
        $rowLines = ck_pipeline_row_lines($lines, $row);
    }
    $by = []; $ids = []; $files = []; $seenFiles = [];
    foreach ($rowLines as $l) {
        if (!ck_pipeline_is_provisional($l)) continue;
        $issue = in_array($l['issue'], FU_PROVISIONAL_ISSUES, true) ? $l['issue']
               : (array_values(array_filter(array_column($l['_src'], 'issue'), fn($i) => in_array($i, FU_PROVISIONAL_ISSUES, true)))[0] ?? 'provisional');
        $by[] = ck_pipeline_block_text($l['supplier'] ?: $l['service'], $l['service_date'], $issue, $l['expiry_date'], $today);
        $ids[] = (int)$l['id'];
        if ($l['source_file']) $seenFiles[strtolower(basename($l['source_file']))] = true;
    }
    if ($token !== null) {
        try {
            require_once __DIR__ . '/calc_payments.php';
            $dir = CK_BASE . '/' . $row['folder_name'];
            $get = $db->prepare("SELECT path, result_json FROM booking_followup_docs WHERE path_hash = ?");
            $like = $db->prepare("SELECT path, result_json FROM booking_followup_docs WHERE path LIKE ? ORDER BY read_at DESC");
            foreach (cp_retry(fn() => cp_list_files($token, $dir . '/invoices')) as $f) {
                if (followup_skip_file($f['name']) || isset($seenFiles[strtolower($f['name'])])) continue;
                $get->execute([sha1(strtolower($dir . '/invoices/' . $f['name']))]);
                $c = $get->fetch(PDO::FETCH_ASSOC);
                if (!$c) {   // read under the folder's earlier name (status tag changed since)
                    $like->execute(['%/invoices/' . str_replace(['%', '_'], ['\%', '\_'], $f['name'])]);
                    foreach ($like->fetchAll(PDO::FETCH_ASSOC) as $x) {
                        if (dropbox_folder_stem(explode('/', ltrim(preg_replace('#^/?001_Safari/#i', '', $x['path']), '/'))[0]) === dropbox_folder_stem($row['folder_name'])) { $c = $x; break; }
                    }
                }
                $d = $c ? json_decode((string)$c['result_json'], true) : null;
                if (!$d || !(in_array($d['status'] ?? '', ['provisional', 'on_request'], true) || in_array($d['issue'] ?? '', FU_PROVISIONAL_ISSUES, true))) continue;
                $by[] = ck_pipeline_block_text(($d['supplier'] ?? '') ?: $f['name'], $d['service_date'] ?? null,
                                               ($d['issue'] ?? '') ?: (string)$d['status'], $d['expiry_date'] ?? null, $today) . ' (invoices/' . $f['name'] . ')';
                $files[] = 'invoices/' . $f['name'];
            }
        } catch (Throwable $e) {
            error_log('ck_pipeline_block: invoices/ not listed: ' . $e->getMessage());
        }
    }
    return ['blocked' => (bool)$by, 'by' => $by, 'lines' => $ids, 'files' => $files];
}

/** Folders with supplier documents: [stem => true] — read by a supplier scan, or listed by the last SafariCheck. */
function ck_pipeline_docs(PDO $db): array {
    $out = [];
    foreach ($db->query("SELECT path FROM booking_followup_docs")->fetchAll(PDO::FETCH_COLUMN) as $p) {
        if (!preg_match('#^/?001_Safari/([^/]+)/(?:[^/]+/)?invoices/#i', (string)$p, $m)) continue;
        $out[dropbox_folder_stem($m[1])] = true;
    }
    $st = $db->query("SELECT f.folder_name, c.files_json FROM ck_folders f JOIN ck_checks c ON c.id = f.last_check_id WHERE f.gone = 0");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $f) {
        $stem = dropbox_folder_stem($f['folder_name']);
        if (isset($out[$stem])) continue;
        foreach (json_decode((string)$f['files_json'], true) ?: [] as $x) {
            if (preg_match('#(?:^|/)invoices/[^/]+\.pdf$#i', (string)($x['path'] ?? ''))) { $out[$stem] = true; break; }
        }
    }
    return $out;
}

/**
 * Add the pipeline to ck_tracker_rows() rows ($r['pl']): stage, open lines (total, by audience, by check, urgent,
 * high), next_due_date / next_due_reason, blocked / blocked_by (open lines only: the invoices/ documents are read
 * when _CK is set), last_claude_check_at, last_safaricheck_at, days_since_check, needs_check / next_check_reason,
 * colour (red: an URGENT line, amber: a HIGH one, else the SafariCheck colour).
 */
function ck_pipeline_annotate(PDO $db, array &$rows, ?string $today = null): void {
    $today = $today ?? ck_now('Y-m-d');
    followup_backfill($db);
    $lines = ck_pipeline_lines($db, $today);
    $docs = ck_pipeline_docs($db);
    $days = fn(?string $a, string $b) => $a ? (int)round((strtotime(substr($b, 0, 10)) - strtotime(substr($a, 0, 10))) / 86400) : null;
    foreach ($rows as &$r) {
        $ls = ck_pipeline_row_lines($lines, $r);
        $aud = ['booking' => 0, 'sales' => 0, 'accounts' => 0]; $bySrc = []; $urgent = 0; $high = 0; $next = null;
        foreach ($ls as $l) {
            $aud[$l['audience'] ?: 'booking']++;
            foreach (array_keys($l['_src']) as $t) $bySrc[$t] = ($bySrc[$t] ?? 0) + 1;
            if ($l['_prio'] === '1-URGENT') $urgent++;
            if ($l['_prio'] === '2-HIGH') $high++;
            if ($l['_due']['due_date'] && (!$next || $l['_due']['due_date'] < $next['due_date'])) $next = $l['_due'] + ['line_id' => (int)$l['id']];
        }
        $block = ck_pipeline_block($db, $r, null, $ls);
        $lastClaude = $r['findings_checked_at'] ?? null;
        $lastSc = $r['last_checked_at'] ?? $r['chk_at'] ?? null;
        $hasDocs = isset($docs[dropbox_folder_stem($r['folder_name'])])
                || (bool)array_filter($ls, fn($l) => in_array($l['type'], ['supplier', 'ticket'], true));
        if ((int)$r['has_ck'])                         $stage = 'ck';
        elseif ($ls)                                   $stage = 'issues';
        elseif ($lastClaude && $lastSc)                $stage = 'ready';
        elseif ($hasDocs || $lastClaude || $lastSc)    $stage = 'booked';
        else                                           $stage = 'confirmed';
        // Check early, then again: Claude check due?
        $reason = null;
        if (!$r['other'] && $r['start_date'] && $r['to_arrival'] !== null && $r['to_arrival'] >= 0) {
            if (!$lastClaude) {
                $since = $r['booking_done_at'] ?: null;   // null: done before tracking started
                if (!(int)$r['has_ck'] && $stage !== 'confirmed' && (!$since || $days($since, $today) > CK_PL_FIRST_CHECK_DAYS)) $reason = 'first_check';
            } else {
                foreach (CK_PL_RECHECKS as $t) {
                    $mark = date('Y-m-d', strtotime($r['start_date'] . ' -' . $t . ' days'));
                    if ($r['to_arrival'] <= $t && substr($lastClaude, 0, 10) < $mark) { $reason = 'recheck_' . $t; break; }
                }
            }
        }
        $sc = ['red' => 'red', 'yellow' => 'amber', 'green' => 'green'][$r['chk_overall'] ?? ''] ?? null;
        $r['pl'] = [
            'stage' => $stage, 'stage_label' => CK_PL_STAGES[$stage],
            'open' => count($ls), 'open_by_audience' => $aud, 'open_by_source' => $bySrc, 'urgent' => $urgent, 'high' => $high,
            'next_due_date' => $next['due_date'] ?? null, 'next_due_reason' => $next['due_reason'] ?? null,
            'next_due_days' => $next ? $days($today, $next['due_date']) : null,
            'blocked' => $block['blocked'], 'blocked_by' => $block['by'],
            'last_claude_check_at' => $lastClaude, 'last_safaricheck_at' => $lastSc,
            'days_since_check' => $lastClaude ? $days($lastClaude, $today) : null,
            'needs_check' => $reason !== null, 'next_check_reason' => $reason,
            'colour' => $urgent ? 'red' : ($high ? 'amber' : $sc),
        ];
    }
    unset($r);
}

/**
 * Memo digest "Bookings pipeline": bookings blocked from the CK by a provisional booking and arriving within
 * 60 days, and bookings waiting for their first check. → ['blocked' => [row], 'first_check' => [row]] (row: folder,
 * customer, start_date, to_arrival, by / reason).
 */
function ck_pipeline_digest(PDO $db, ?string $today = null): array {
    require_once __DIR__ . '/../dropbox_helper.php';
    $today = $today ?? ck_now('Y-m-d');
    $rows = array_values(array_filter(ck_tracker_rows($db), fn($r) => !$r['other'] && $r['to_arrival'] !== null && $r['to_arrival'] >= 0));
    ck_pipeline_annotate($db, $rows, $today);
    $out = ['blocked' => [], 'first_check' => []];
    usort($rows, fn($a, $b) => [$a['start_date'], $a['folder_name']] <=> [$b['start_date'], $b['folder_name']]);
    foreach ($rows as $r) {
        $b = ['ck_folder_id' => (int)$r['id'], 'folder' => $r['folder_name'], 'customer' => ck_customer_label($r['folder_name']),
              'start_date' => $r['start_date'], 'to_arrival' => $r['to_arrival']];
        if ($r['pl']['blocked'] && !(int)$r['has_ck'] && $r['to_arrival'] <= 60) $out['blocked'][] = $b + ['by' => $r['pl']['blocked_by']];
        if ($r['pl']['next_check_reason'] === 'first_check') $out['first_check'][] = $b + ['waiting' => $r['waiting']];
    }
    return $out;
}
