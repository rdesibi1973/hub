<?php
/**
 * ck_lib.php — CK tracker core.
 *
 * Follows every top-level booking folder in /001_Safari (the unit the old
 * MissingCK.bat checked: a private safari or a whole GRP) and records, in
 * ck_folders / ck_events, when it moved from _PROGRESS to _DEPOSIT/_BALANCE/…
 * and when it got (or lost) its _CK "checked" marker.
 *
 * The folder name stays the visible marker everyone already uses; the tables add
 * when / who / for how long. Folders are keyed by their Dropbox file ID, which
 * does not change on rename, so a status or _CK rename is seen as a change of
 * the same folder, not as a new one.
 *
 * Requires dropbox_helper.php (for ck_scan / ck_set_marker) and folder_parser.php.
 */

const CK_BASE = '/001_Safari';

/** Stages meaning "the booking team has finished booking" — CK is due from here. */
const CK_DONE_STAGES = ['Deposit', 'Balance', 'Balance-Cash', 'Paid'];

/** Stages meaning "still being booked". */
const CK_BOOKING_STAGES = ['Progress', 'Confirmed'];

/**
 * No CK outside Tanzania: folders for these destinations are left out of the CK
 * tracker, the request / BackOffice CK links and the automatic checks.
 * Matched anywhere in the folder name, case-insensitive; a trip combined with
 * Tanzania ("TZ-KENYA") is a Tanzania trip.
 */
const CK_OTHER_DEST = ['KENYA', 'UGANDA', 'NAMIBIA', 'SUDAFRICA', 'SOUTHAFRICA', 'MADAGASCAR', 'RWANDA', 'BOTSWANA'];

/** Office time (the server runs on another timezone), 'Y-m-d H:i:s' or other format. */
function ck_now(string $fmt = 'Y-m-d H:i:s'): string {
    return (new DateTimeImmutable('now', new DateTimeZone('Africa/Dar_es_Salaam')))->format($fmt);
}

/** Create the tables if the migration was not run yet (idempotent, once per request). */
function ck_ensure_schema(PDO $db): void {
    static $done = false;
    if ($done) return;
    $db->exec("CREATE TABLE IF NOT EXISTS ck_folders (
        id INT AUTO_INCREMENT PRIMARY KEY,
        dropbox_id VARCHAR(64) NOT NULL,
        folder_name VARCHAR(255) NOT NULL,
        stage VARCHAR(20) NULL,
        has_ck TINYINT(1) NOT NULL DEFAULT 0,
        start_date DATE NULL,
        end_date DATE NULL,
        stage_since DATETIME NULL,
        booking_done_at DATETIME NULL,
        ck_at DATETIME NULL,
        ck_by INT NULL,
        first_seen_at DATETIME NOT NULL,
        last_seen_at DATETIME NOT NULL,
        gone TINYINT(1) NOT NULL DEFAULT 0,
        UNIQUE KEY uq_ck_folders_dbx (dropbox_id),
        KEY idx_ck_folders_name (folder_name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS ck_events (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ck_folder_id INT NOT NULL,
        event VARCHAR(20) NOT NULL,
        from_value VARCHAR(255) NULL,
        to_value VARCHAR(255) NULL,
        user_id INT NULL,
        source VARCHAR(10) NOT NULL,
        created_at DATETIME NOT NULL,
        KEY idx_ck_events_folder (ck_folder_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // SafariCheck results posted by the cloud runner (safariagent ci_check.py).
    $db->exec("CREATE TABLE IF NOT EXISTS ck_checks (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ck_folder_id INT NOT NULL,
        created_at DATETIME NOT NULL,
        trigger_src VARCHAR(12) NULL,
        folder_name VARCHAR(255) NULL,
        overall VARCHAR(8) NULL,
        n_red INT NOT NULL DEFAULT 0,
        n_yellow INT NOT NULL DEFAULT 0,
        n_green INT NOT NULL DEFAULT 0,
        n_grey INT NOT NULL DEFAULT 0,
        fingerprint VARCHAR(40) NULL,
        checks_json MEDIUMTEXT NULL,
        facts_json MEDIUMTEXT NULL,
        files_json MEDIUMTEXT NULL,
        report_html MEDIUMTEXT NULL,
        error TEXT NULL,
        KEY idx_ck_checks_folder (ck_folder_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Notes and sent emails on a folder (migration 059).
    $db->exec("CREATE TABLE IF NOT EXISTS ck_notes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ck_folder_id INT NOT NULL,
        created_by INT NULL,
        note_type VARCHAR(12) NOT NULL,
        recipients VARCHAR(500) NULL,
        subject VARCHAR(255) NULL,
        body MEDIUMTEXT NULL,
        created_at DATETIME NOT NULL,
        KEY idx_ck_notes_folder (ck_folder_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Columns added after the first release (MySQL: no ADD COLUMN IF NOT EXISTS).
    $have = $db->query("SHOW COLUMNS FROM ck_folders")->fetchAll(PDO::FETCH_COLUMN);
    foreach ([
        'check_requested_at' => 'DATETIME NULL',
        'check_trigger'      => 'VARCHAR(12) NULL',
        'last_check_id'      => 'INT NULL',
        'last_checked_at'    => 'DATETIME NULL',
        // Owners and CK findings (migration 073).
        'ck_owner_user_id'        => 'INT NULL',
        'booking_owner_user_id'   => 'INT NULL',
        'owners_updated_at'       => 'DATETIME NULL',
        'owners_updated_by'       => 'INT NULL',
        'booking_owner_auto_id'   => 'INT NULL',
        'booking_owner_auto_text' => 'VARCHAR(120) NULL',
        'booking_owner_auto_at'   => 'DATETIME NULL',
        'findings_checked_at'     => 'DATETIME NULL',
        'findings_report_path'    => 'VARCHAR(500) NULL',
    ] as $col => $def) {
        if (!in_array($col, $have, true)) $db->exec("ALTER TABLE ck_folders ADD COLUMN $col $def");
    }
    $done = true;
}

/** Status stage from a folder name ("contains", longest first — tolerates _CK before or after). */
function ck_stage(string $name): ?string {
    static $tags = [
        '_BALANCE-CASH' => 'Balance-Cash',
        '_BALANCE_CASH' => 'Balance-Cash',
        '_BALANCE'      => 'Balance',
        '_DEPOSIT'      => 'Deposit',
        '_PAID'         => 'Paid',
        '_PROGRESS'     => 'Progress',
        '_CONFIRMED'    => 'Confirmed',
        '_PROVISIONAL'  => 'Provisional',
        '_CANCELLED'    => 'Cancelled',
        '_CANCELED'     => 'Cancelled',
    ];
    $up = strtoupper($name);
    foreach ($tags as $t => $s) if (strpos($up, $t) !== false) return $s;
    return null;
}

/** Does the folder carry the _CK marker (as its own token: "_CK" then "_" or end)? */
function ck_has_marker(string $name): bool {
    return (bool)preg_match('/_CK(?=_|$)/i', $name);
}

/** Name with the _CK marker added at the end / removed wherever it is. */
function ck_name_with_marker(string $name, bool $on): string {
    $clean = preg_replace('/_CK(?=_|$)/i', '', $name);
    return $on ? $clean . '_CK' : $clean;
}

/** Sales person from the (…) block: last token that is not a channel / destination tag. */
function ck_agent_from_name(string $name): string {
    if (!preg_match('/\(([^)]+)\)/', $name, $m)) return '';
    static $skip = ['drct','sb','lam','ps','tz','kenya','trek','znz','uganda','namibia',
                    'southafrica','sudafrica','rwanda','madagascar','botswana','staff'];
    $toks = array_values(array_filter(array_map('trim', explode('-', $m[1])),
        fn($t) => $t !== '' && !in_array(strtolower($t), $skip, true)));
    return $toks ? (string)end($toks) : '';
}

/** Customer / group label: drop "01_03JAN_" and everything from "(" on. */
function ck_customer_label(string $name): string {
    $s = preg_replace('/^\d{1,2}_\d{1,2}[A-Za-z]{3}_/', '', $name);
    $p = strpos($s, '(');
    if ($p !== false) $s = substr($s, 0, $p);
    return trim(str_replace('_', ' ', $s));
}

function ck_is_other_destination(string $name): bool {
    $up = strtoupper($name);
    if (preg_match('/(?<![A-Z])TZ(?![A-Z])/', $up)) return false;   // TZ-KENYA: Tanzania too
    foreach (CK_OTHER_DEST as $d) if (strpos($up, $d) !== false) return true;
    return false;
}

/** Is a top-level /001_Safari entry a booking folder (not 00_2026, 00_CANCELED, …)? */
function ck_is_booking_folder(string $name): bool {
    if (preg_match('/^00_/', $name)) return false;
    return folder_is_booking_leaf($name);
}

function ck_log(PDO $db, int $folderId, string $event, ?string $from, ?string $to,
                ?int $userId, string $source, string $now): void {
    $db->prepare("INSERT INTO ck_events (ck_folder_id, event, from_value, to_value, user_id, source, created_at)
                  VALUES (?,?,?,?,?,?,?)")
       ->execute([$folderId, $event, $from, $to, $userId, $source, $now]);
}

/**
 * Bring a tracked folder up to date with its current name: log what changed
 * (rename, stage, _CK) and update the row. Shared by the Dropbox scan and by
 * renames done from the Hub (which know the user).
 *
 * Returns true when this change means "booking just finished" (first move to
 * Deposit/Balance/Balance-Cash/Paid) — the moment the automatic check is due.
 */
function ck_apply(PDO $db, array $row, string $newName, string $source, ?int $userId, string $now): bool {
    $bookingDone = false;
    $id       = (int)$row['id'];
    $newStage = ck_stage($newName);
    $newCk    = ck_has_marker($newName);
    $dates    = parse_folder_dates($newName);

    $set = ['folder_name' => $newName, 'last_seen_at' => $now, 'gone' => 0,
            'start_date' => $dates['start_date'], 'end_date' => $dates['end_date']];

    if ((int)$row['gone'] === 1) ck_log($db, $id, 'back', null, $newName, $userId, $source, $now);

    if ($row['folder_name'] !== $newName) {
        ck_log($db, $id, 'renamed', $row['folder_name'], $newName, $userId, $source, $now);
    }
    if (($row['stage'] ?? null) !== $newStage) {
        ck_log($db, $id, 'stage', $row['stage'], $newStage, $userId, $source, $now);
        $set['stage']       = $newStage;
        $set['stage_since'] = $now;
        if (in_array($newStage, CK_DONE_STAGES, true) && empty($row['booking_done_at'])) {
            $set['booking_done_at'] = $now;
            $bookingDone = true;
        }
    }
    if ((bool)$row['has_ck'] !== $newCk) {
        ck_log($db, $id, $newCk ? 'ck_set' : 'ck_removed', null, null, $userId, $source, $now);
        $set['has_ck'] = $newCk ? 1 : 0;
        $set['ck_at']  = $newCk ? $now : null;
        $set['ck_by']  = $newCk ? $userId : null;
    }

    $cols = implode(', ', array_map(fn($c) => "$c = ?", array_keys($set)));
    $db->prepare("UPDATE ck_folders SET $cols WHERE id = ?")
       ->execute([...array_values($set), $id]);

    // The request follows its folder: a renamed folder (in Dropbox or from the
    // Hub) updates practice_code/dropbox_url, and a new payment tag updates
    // payment_status, so the two never drift apart.
    if ($row['folder_name'] !== $newName) {
        $ps = in_array($newStage, CK_DONE_STAGES, true) ? $newStage : null;
        $db->prepare("UPDATE requests
                         SET practice_code = ?, dropbox_url = ?,
                             payment_status = IF(status = 'Booked' AND ? IS NOT NULL, ?, payment_status)
                       WHERE practice_code IN (?, ?)")
           ->execute([$newName, ck_url_from_path(CK_BASE . '/' . $newName), $ps, $ps,
                      $row['folder_name'], $newName]);
    }
    return $bookingDone;
}

/**
 * Scan the top of /001_Safari and record every change since the last scan.
 * The very first scan only takes a baseline: times are unknown (NULL) for
 * folders that already existed, so no fake "changed today" history is made.
 *
 * @return array{seen:int,new:int,changed:int,gone:int}
 */
function ck_scan(PDO $db, string $token): array {
    ck_ensure_schema($db);
    $now     = ck_now();
    $entries = array_filter(dropbox_list_folder_entries($token, CK_BASE),
                            fn($e) => ck_is_booking_folder($e['name']));

    $rows = [];
    foreach ($db->query("SELECT * FROM ck_folders", PDO::FETCH_ASSOC) as $r) $rows[$r['dropbox_id']] = $r;
    $initial = !$rows;

    $stats = ['seen' => count($entries), 'new' => 0, 'changed' => 0, 'gone' => 0];
    $ins = $db->prepare("INSERT IGNORE INTO ck_folders
        (dropbox_id, folder_name, stage, has_ck, start_date, end_date, stage_since, booking_done_at,
         ck_at, first_seen_at, last_seen_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?)");

    $toCheck = [];   // folders whose booking just finished -> automatic check
    $db->beginTransaction();
    try {
        $seenIds = [];
        foreach ($entries as $e) {
            $seenIds[$e['id']] = true;
            $r = $rows[$e['id']] ?? null;
            if ($r === null) {
                $stage = ck_stage($e['name']);
                $ck    = ck_has_marker($e['name']);
                $d     = parse_folder_dates($e['name']);
                $ins->execute([
                    $e['id'], $e['name'], $stage, $ck ? 1 : 0, $d['start_date'], $d['end_date'],
                    $initial ? null : $now,
                    (!$initial && in_array($stage, CK_DONE_STAGES, true)) ? $now : null,
                    (!$initial && $ck) ? $now : null,
                    $now, $now,
                ]);
                if ($ins->rowCount()) {
                    $newId = (int)$db->lastInsertId();
                    ck_log($db, $newId, 'first_seen', null, $e['name'], null, 'scan', $now);
                    if (!$initial && in_array($stage, CK_DONE_STAGES, true)) $toCheck[] = $newId;
                    $stats['new']++;
                }
                continue;
            }
            $changed = $r['folder_name'] !== $e['name'] || (int)$r['gone'] === 1;
            if ($changed) {
                if (ck_apply($db, $r, $e['name'], 'scan', null, $now)) $toCheck[] = (int)$r['id'];
                $stats['changed']++;
            } else {
                $db->prepare("UPDATE ck_folders SET last_seen_at = ? WHERE id = ?")->execute([$now, (int)$r['id']]);
            }
        }
        // Folders no longer at the top of 001_Safari (archived to 00_YYYY, cancelled, …).
        foreach ($rows as $dbxId => $r) {
            if (isset($seenIds[$dbxId]) || (int)$r['gone'] === 1) continue;
            $db->prepare("UPDATE ck_folders SET gone = 1 WHERE id = ?")->execute([(int)$r['id']]);
            ck_log($db, (int)$r['id'], 'gone', $r['folder_name'], null, null, 'scan', $now);
            $stats['gone']++;
        }
        $db->commit();
    } catch (Throwable $ex) {
        $db->rollBack();
        throw $ex;
    }
    if ($toCheck) {
        try { ck_request_check($db, $toCheck, 'stage'); }
        catch (Throwable $ig) { /* stays pending — the nightly run picks it up */ }
    }
    $stats['checks'] = count($toCheck);
    return $stats;
}

/**
 * Record a rename done from the Hub (e.g. BackOffice status change) so the
 * history carries the user. No-op if the folder is not tracked.
 */
function ck_record_rename(PDO $db, string $oldName, string $newName, ?int $userId): void {
    ck_ensure_schema($db);
    $st = $db->prepare("SELECT * FROM ck_folders WHERE folder_name = ? AND gone = 0 LIMIT 1");
    $st->execute([$oldName]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row && ck_apply($db, $row, $newName, 'hub', $userId, ck_now())) {
        try { ck_request_check($db, [(int)$row['id']], 'stage'); } catch (Throwable $ig) {}
    }
}

/**
 * Every tracked folder still in /001_Safari with its last check, notes, requests
 * and computed fields (agent, is_grp, other, to_arrival, band, in_stage, waiting,
 * missing). The CK tracker page and the Agent API (ck_status) filter this list.
 * Needs dropbox_helper.php (dropbox_folder_stem).
 */
function ck_tracker_rows(PDO $db): array {
    ck_ensure_schema($db);
    $folders = $db->query("SELECT f.*, u.full_name AS ck_by_name,
                                  c.overall AS chk_overall, c.n_red AS chk_red, c.n_yellow AS chk_yellow,
                                  c.created_at AS chk_at, c.error AS chk_error,
                                  (SELECT COUNT(*) FROM ck_notes n WHERE n.ck_folder_id = f.id) AS note_count,
                                  -- \"type|text\": the body for a note, the subject for a sent email.
                                  (SELECT CONCAT(n2.note_type, '|', COALESCE(IF(n2.note_type = 'manual', n2.body, n2.subject), ''))
                                     FROM ck_notes n2 WHERE n2.ck_folder_id = f.id
                                     ORDER BY n2.created_at DESC, n2.id DESC LIMIT 1) AS last_note_raw,
                                  (SELECT u3.full_name FROM ck_notes n3 LEFT JOIN users u3 ON u3.id = n3.created_by
                                     WHERE n3.ck_folder_id = f.id
                                     ORDER BY n3.created_at DESC, n3.id DESC LIMIT 1) AS last_note_by
                           FROM ck_folders f
                           LEFT JOIN users u ON u.id = f.ck_by
                           LEFT JOIN ck_checks c ON c.id = f.last_check_id
                           WHERE f.gone = 0")->fetchAll(PDO::FETCH_ASSOC);

    // Requests per folder: a private safari by practice_code, a GRP by group_folder.
    $byPc = []; $byGrp = [];
    $rq = $db->query("SELECT r.id, r.customer_name, r.practice_code, r.group_folder, r.dropbox_url, r.pax, r.agent_id, a.name AS agent_name
                      FROM requests r LEFT JOIN agents a ON a.id = r.agent_id
                      WHERE (r.group_folder IS NOT NULL AND r.group_folder <> '')
                         OR r.dropbox_url LIKE '%001_Safari%'");
    foreach ($rq->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (trim($r['group_folder'] ?? '') !== '') $byGrp[mb_strtolower(trim($r['group_folder']))][] = $r;
        elseif (trim($r['practice_code'] ?? '') !== '') $byPc[mb_strtolower(trim($r['practice_code']))][] = $r;
    }
    $byStem = [];
    foreach ($byPc as $k => $list) $byStem[dropbox_folder_stem($k)] = array_merge($byStem[dropbox_folder_stem($k)] ?? [], $list);

    $today = new DateTimeImmutable(ck_now('Y-m-d'));
    $daysFrom = function (?string $dt) use ($today): ?int {
        if (!$dt) return null;
        return (int)$today->diff(new DateTimeImmutable(substr($dt, 0, 10)))->format('%r%a');
    };

    $rows = [];
    foreach ($folders as $f) {
        $key  = mb_strtolower($f['folder_name']);
        $reqs = $byGrp[$key] ?? $byPc[$key] ?? $byStem[dropbox_folder_stem($f['folder_name'])] ?? [];
        // Sales person: the request's agent when all matched requests agree, else the folder's (…) tag.
        $reqAgents = array_values(array_unique(array_filter(array_map(fn($q) => (string)($q['agent_name'] ?? ''), $reqs))));
        $agent = count($reqAgents) === 1 ? $reqAgents[0] : ck_agent_from_name($f['folder_name']);

        $toArrival = $daysFrom($f['start_date']);           // + = days ahead, − = already started
        $band = null;
        if ($toArrival !== null && $toArrival >= 0) $band = $toArrival < 60 ? 'red' : ($toArrival <= 90 ? 'amber' : 'grey');

        [$nType, $nText] = array_pad(explode('|', (string)($f['last_note_raw'] ?? ''), 2), 2, '');
        $rows[] = $f + [
            'last_note'  => $nType === 'email_sent' ? '📧 ' . $nText : $nText,
            'reqs'       => $reqs,
            'agent'      => $agent,
            'is_grp'     => stripos($f['folder_name'], 'GRP') !== false || isset($byGrp[$key]),
            'other'      => ck_is_other_destination($f['folder_name']),
            'to_arrival' => $toArrival,
            'band'       => $band,
            'in_stage'   => $f['stage_since'] ? -$daysFrom($f['stage_since']) : null,
            'waiting'    => $f['booking_done_at'] ? -$daysFrom($f['booking_done_at']) : null,
            'missing'    => in_array($f['stage'], CK_DONE_STAGES, true) && !(int)$f['has_ck'],
        ];
    }
    return $rows;
}

/**
 * Open red / yellow points of stored checks: [check_id => ['reds' => [...], 'yellows' => [...]]].
 * Short labels "Section — Title"; points ticked "Correct / confirmed" are left out.
 */
function ck_check_points(PDO $db, array $checkIds): array {
    $checkIds = array_values(array_filter(array_map('intval', $checkIds)));
    if (!$checkIds) return [];
    $in = implode(',', array_fill(0, count($checkIds), '?'));
    $st = $db->prepare("SELECT id, checks_json FROM ck_checks WHERE id IN ($in)");
    $st->execute($checkIds);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $p = ['reds' => [], 'yellows' => []];
        foreach (json_decode((string)$c['checks_json'], true) ?: [] as $k) {
            if (!empty($k['confirmed'])) continue;
            $label = trim(($k['section'] ?? '') . ' — ' . ($k['title'] ?? ''), ' —');
            if (($k['status'] ?? '') === 'red')    $p['reds'][]    = $label;
            if (($k['status'] ?? '') === 'yellow') $p['yellows'][] = $label;
        }
        $out[(int)$c['id']] = $p;
    }
    return $out;
}

// ── SafariCheck points about bookings → Booking follow-up (type safaricheck) ─
// Only the points about what was booked become follow-up lines (followup_safaricheck_sync()); naming points
// ("Lodge not found in Word: SCVC(Ndutu)", "Lodge in Word not in Excel") and document-only points (prices, Word
// texts, sheets) stay in the CK tracker / report. [section, title regex, issue code, service type]; red / yellow
// points not ticked "correct / confirmed" only. Listed in docs/AGENT_API.md (Booking follow-up — SafariCheck lines).
const CK_SC_BOOKING_POINTS = [
    ['Accommodation', '/^Nights differ: /',        'sc_nights_differ',   'stay'],
    ['Accommodation', '/^Check-in differs: /',     'sc_checkin_differs', 'stay'],
    ['Accommodation', '/^Invoice dates differ: /', 'sc_invoice_dates',   'stay'],
    ['Accommodation', '/^Rooms differ: /',         'sc_rooms_differ',    'stay'],
    ['Accommodation', '/^Basis differs: /',        'sc_basis_differs',   'stay'],
    ['Accommodation', '/^GRP rooms short: /',      'sc_grp_rooms_short', 'stay'],
    ['Accommodation', '/^GRP unsold rooms: /',     'sc_grp_unsold',      'stay'],
    ['Transfers',     '/^Transfer \d{1,2} \w{3} · |^Transfer no date · /', 'sc_transfer', 'transfer'],
    ['Transfers',     '/^Excel note: /',           'sc_transfer',        'transfer'],
    ['Flights',       '/^Flight in Word not in Excel$/', 'sc_flight',    'flight'],
    ['Flights',       '/^Flights invoiced but not quoted$/', 'sc_flight', 'flight'],
    ['Arrival',       '/^Arrival flight not on the trip/',   'sc_dates', 'dates'],
    ['Departure',     '/^Departure flight not on the trip/', 'sc_dates', 'dates'],
    ['Dates',         '/^(?:Start|End) date mismatch$/',     'sc_dates', 'dates'],
    ['PAX',           '/^PAX count mismatch$/',    'sc_pax',             'stay'],
];
const CK_SC_ISSUE_LABELS = ['sc_nights_differ' => 'Nights differ', 'sc_checkin_differs' => 'Check-in differs', 'sc_invoice_dates' => 'Invoice dates differ',
                            'sc_rooms_differ' => 'Rooms differ', 'sc_basis_differs' => 'Basis differs', 'sc_grp_rooms_short' => 'GRP rooms short',
                            'sc_grp_unsold' => 'GRP unsold rooms', 'sc_transfer' => 'Transfer', 'sc_flight' => 'Flight', 'sc_dates' => 'Dates',
                            'sc_pax' => 'PAX'];

/** "29 Dec" inside the trip (folder dates; a January date of a December trip is next year), or null. */
function ck_sc_date(string $dm, ?string $start, ?string $end): ?string {
    if (!preg_match('/\b(\d{1,2})\s+([A-Za-z]{3})/', $dm, $m) || !$start) return null;
    $mon = date_parse($m[2] . ' 1 2000')['month'] ?? null;
    if (!$mon) return null;
    foreach ([(int)substr($start, 0, 4), (int)substr($start, 0, 4) + 1] as $y) {
        if (!checkdate($mon, (int)$m[1], $y)) continue;
        $d = sprintf('%04d-%02d-%02d', $y, $mon, (int)$m[1]);
        if ($d >= date('Y-m-d', strtotime($start . ' -1 day')) && $d <= date('Y-m-d', strtotime(($end ?: $start) . ' +1 day'))) return $d;
    }
    return null;
}

/**
 * The booking points of a SafariCheck result: [{key, section, title, status, detail, issue, service_type, supplier,
 * service_date}]. $checks: checks_json; $facts: facts_json (Excel stays → the check-in date of a lodge point).
 */
function ck_sc_booking_points(array $checks, array $facts): array {
    $start = $facts['excel']['start'] ?? $facts['word']['start'] ?? null;
    $end = $facts['excel']['end'] ?? $facts['word']['end'] ?? null;
    $stays = $facts['excel']['stays'] ?? [];
    $out = [];
    foreach ($checks as $c) {
        if (!in_array($c['status'] ?? '', ['red', 'yellow'], true) || !empty($c['confirmed'])) continue;
        $title = (string)($c['title'] ?? '');
        $hit = null;
        foreach (CK_SC_BOOKING_POINTS as $p) {
            if (strcasecmp($p[0], (string)($c['section'] ?? '')) === 0 && preg_match($p[1], $title)) { $hit = $p; break; }
        }
        if (!$hit) continue;
        $supplier = null; $date = null;
        if ($hit[3] === 'stay' && strpos($title, ': ') !== false) {
            $supplier = trim(substr($title, strpos($title, ': ') + 2));
            foreach ($stays as $s) {
                if (!empty($s['start']) && (strcasecmp((string)$s['lodge'], $supplier) === 0
                    || (function_exists('followup_supplier_same') && followup_supplier_same((string)$s['lodge'], $supplier)))) { $date = $s['start']; break; }
            }
        } elseif ($hit[3] === 'transfer') {
            $date = ck_sc_date($title, $start, $end);
        } elseif ($hit[3] === 'dates') {
            $date = $start;
        }
        $out[] = ['key' => (string)(($c['key'] ?? '') !== '' ? $c['key'] : $c['section'] . '|' . $title), 'section' => (string)$c['section'],
                  'title' => $title, 'status' => $c['status'], 'detail' => trim((string)($c['detail'] ?? '')),
                  'issue' => $hit[2], 'service_type' => $hit[3], 'supplier' => $supplier, 'service_date' => $date];
    }
    return $out;
}

/** Dropbox web URL from an API path (same shape as backoffice.php). */
function ck_url_from_path(string $path): string {
    return 'https://www.dropbox.com/home/' . implode('/', array_map('rawurlencode', explode('/', ltrim($path, '/'))));
}

/**
 * Keep the requests table in step after a top-level folder rename: a private
 * safari's practice_code, or every GRP member's group_folder + dropbox_url.
 * Mirrors bo_do_rename() in includes/folder_service.php.
 */
function ck_sync_requests(PDO $db, string $old, string $new): int {
    $n = 0;
    $st = $db->prepare("SELECT id, practice_code FROM requests WHERE group_folder = ?");
    $st->execute([$old]);
    $upd = $db->prepare("UPDATE requests SET group_folder = ?, practice_code = ?, dropbox_url = ? WHERE id = ?");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $g) {
        $sub = trim($g['practice_code'] ?? '');
        $self = $sub === $old;   // imported group: practice_code = group_folder = the folder itself
        $upd->execute([$new, $self ? $new : $g['practice_code'],
                       ck_url_from_path(CK_BASE . '/' . $new . ($sub !== '' && !$self ? '/' . $sub : '')), (int)$g['id']]);
        $n++;
    }
    $st = $db->prepare("UPDATE requests SET practice_code = ?, dropbox_url = ?
                        WHERE practice_code = ? AND (group_folder IS NULL OR group_folder = '')");
    $st->execute([$new, ck_url_from_path(CK_BASE . '/' . $new), $old]);
    return $n + $st->rowCount();
}

/**
 * Add ($on) or remove the _CK marker: rename the Dropbox folder, sync the
 * requests, and log it with the user. Dropbox first; the DB only if it succeeds.
 * _CK is refused while a booking is still provisional (ck_pipeline_block(), no override).
 *
 * @return array{ok:bool,msg:string,blocked_by?:array}
 */
function ck_set_marker(PDO $db, string $token, int $folderId, bool $on, ?int $userId): array {
    ck_ensure_schema($db);
    $st = $db->prepare("SELECT * FROM ck_folders WHERE id = ?");
    $st->execute([$folderId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row || (int)$row['gone'] === 1) return ['ok' => false, 'msg' => 'Folder not found in 001_Safari.'];

    $old = $row['folder_name'];
    if (ck_has_marker($old) === $on) {
        return ['ok' => false, 'msg' => $on ? 'The folder already has _CK.' : 'The folder has no _CK.'];
    }
    if ($on) {
        require_once __DIR__ . '/ck_pipeline.php';
        $tr = ck_tracker_row_for($db, $folderId, null);
        $block = $tr ? ck_pipeline_block($db, $tr, $token) : ['blocked' => false];
        if ($block['blocked']) {
            return ['ok' => false, 'msg' => '⛔ No CK while a booking is still provisional: ' . implode('; ', $block['by']) . '.',
                    'blocked_by' => $block['by']];
        }
    }
    $new = ck_name_with_marker($old, $on);
    try {
        dropbox_move_folder($token, CK_BASE . '/' . $old, CK_BASE . '/' . $new);
    } catch (Throwable $e) {
        return ['ok' => false, 'msg' => 'Dropbox rename failed — nothing changed (the folder may have been renamed meanwhile; reload). ' . $e->getMessage()];
    }
    $n = ck_sync_requests($db, $old, $new);
    if (ck_apply($db, $row, $new, 'hub', $userId, ck_now())) {
        try { ck_request_check($db, [(int)$row['id']], 'stage'); } catch (Throwable $ig) {}
    }
    return ['ok' => true, 'msg' => ($on ? '✅ CK set: ' : '↩ CK removed: ') . $new
                                   . ($n ? " ($n request" . ($n === 1 ? '' : 's') . ' updated)' : '')];
}

// ── Owners: who answers for the CK (sales side) and who fixes the bookings ────
// ck_folders.ck_owner_user_id / booking_owner_user_id; NULL = the default rule, computed
// when read, so a later change of rule applies to every row nobody set by hand.
//   CK owner       CK_OWNER_DEFAULT (server config): 'fixed:<username>' (default fixed:rdesibi —
//                  Roberto handles every CK for now) or 'seller' (the user linked to the request's
//                  sales agent; a seller who left → CK_SELLER_HANDOVER [username => username]).
//   Booking owner  the booking-team member who signed the Calc CHECKED column most often among
//                  the stays (cached in booking_owner_auto_*), else nobody.
// Shared by the CK tracker page, the Agent API (ck_status / ck_set_owners) and Booking follow-up.

const CK_OWNER_FALLBACK  = 'rdesibi';   // CK owner when the rule finds nobody
const CK_OWNER_AUTO_TTL  = 43200;       // seconds before the Calc CHECKED names are read again

/** Every user (also inactive ones, for names): [id => row]. */
function ck_users(PDO $db): array {
    static $users = null;
    if ($users === null) {
        $users = [];
        foreach ($db->query("SELECT id, username, full_name, email, is_active, agent_id FROM users ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC) as $u) {
            $users[(int)$u['id']] = $u;
        }
    }
    return $users;
}

/** Active users for the owner selects: [{id, username, name}]. */
function ck_active_users(PDO $db): array {
    $out = [];
    foreach (ck_users($db) as $u) {
        if ((int)$u['is_active'] === 1) $out[] = ['id' => (int)$u['id'], 'username' => $u['username'], 'name' => $u['full_name'] ?: $u['username']];
    }
    return $out;
}

/** An active user by id or username, or null. */
function ck_user_find(PDO $db, $ref): ?array {
    $ref = trim((string)$ref);
    if ($ref === '') return null;
    foreach (ck_users($db) as $id => $u) {
        if ((int)$u['is_active'] !== 1) continue;
        if (ctype_digit($ref) ? $id === (int)$ref : strcasecmp($u['username'], $ref) === 0) return $u;
    }
    return null;
}

/** Owner as the page / API show it, or null. */
function ck_owner_out(?array $u, bool $default): ?array {
    if (!$u) return null;
    return ['user_id' => (int)$u['id'], 'username' => $u['username'], 'name' => $u['full_name'] ?: $u['username'],
            'email' => $u['email'] ?: null, 'default' => $default];
}

/** The default rule of the CK owner: 'fixed:<username>' | 'seller'. */
function ck_owner_rule(): string {
    $r = defined('CK_OWNER_DEFAULT') ? trim((string)CK_OWNER_DEFAULT) : '';
    return $r !== '' ? $r : 'fixed:' . CK_OWNER_FALLBACK;
}

/** Default CK owner of a tracker row (ck_tracker_rows() shape: reqs with agent_id). */
function ck_default_ck_owner(PDO $db, array $row): ?array {
    $rule = ck_owner_rule();
    if ($rule === 'seller') {
        $users = ck_users($db);
        $hand  = defined('CK_SELLER_HANDOVER') ? array_change_key_case((array)CK_SELLER_HANDOVER, CASE_LOWER) : [];
        foreach ($row['reqs'] ?? [] as $q) {
            if (empty($q['agent_id'])) continue;
            $seller = null;
            foreach ($users as $u) {
                if ((int)$u['agent_id'] !== (int)$q['agent_id']) continue;
                if (!$seller || ((int)$u['is_active'] === 1 && (int)$seller['is_active'] !== 1)) $seller = $u;
            }
            if (!$seller) continue;
            if ((int)$seller['is_active'] === 1) return $seller;
            if (isset($hand[strtolower($seller['username'])]) && ($h = ck_user_find($db, $hand[strtolower($seller['username'])]))) return $h;
        }
        return ck_user_find($db, CK_OWNER_FALLBACK);
    }
    return ck_user_find($db, preg_replace('/^fixed:/i', '', $rule)) ?: ck_user_find($db, CK_OWNER_FALLBACK);
}

/** CK owner and booking owner of a row: ['ck_owner' => ck_owner_out()|null, 'booking_owner' => …]. */
function ck_owners_resolve(PDO $db, array $row): array {
    $users = ck_users($db);
    $ck = !empty($row['ck_owner_user_id']) && isset($users[(int)$row['ck_owner_user_id']])
        ? ck_owner_out($users[(int)$row['ck_owner_user_id']], false) : ck_owner_out(ck_default_ck_owner($db, $row), true);
    if (!empty($row['booking_owner_user_id']) && isset($users[(int)$row['booking_owner_user_id']])) {
        $bk = ck_owner_out($users[(int)$row['booking_owner_user_id']], false);
    } else {
        $bk = !empty($row['booking_owner_auto_id']) && isset($users[(int)$row['booking_owner_auto_id']])
            ? ck_owner_out($users[(int)$row['booking_owner_auto_id']], true) : null;
    }
    return ['ck_owner' => $ck, 'booking_owner' => $bk];
}

/**
 * The booking-team member named most often in the CHECKED column, counted once per stay
 * (consecutive nights in one hotel). $days: [{hotel, checked}]. Names match a user's username
 * or first name ("Lydia", "checked Glady 12/3"). → ['user' => row|null, 'text' => names seen]
 */
function ck_booking_owner_from_calc(PDO $db, array $days): array {
    $names = [];   // token => user id
    foreach (ck_users($db) as $id => $u) {
        if ((int)$u['is_active'] !== 1) continue;
        foreach ([$u['username'], strtok((string)$u['full_name'], ' ')] as $n) {
            $n = strtolower(trim((string)$n));
            if (strlen($n) >= 3 && !isset($names[$n])) $names[$n] = $id;
        }
    }
    $stays = []; $prev = null;
    foreach ($days as $d) {
        $hotel = trim((string)($d['hotel'] ?? ''));
        if ($hotel === '') { $prev = null; continue; }
        if ($prev !== strtolower($hotel)) $stays[] = '';
        $stays[count($stays) - 1] .= ' ' . trim((string)($d['checked'] ?? ''));
        $prev = strtolower($hotel);
    }
    $count = []; $seen = [];
    foreach ($stays as $txt) {
        $hit = [];
        foreach (preg_split('/[^a-z]+/', strtolower($txt), -1, PREG_SPLIT_NO_EMPTY) as $tok) {
            foreach ($names as $n => $id) {
                if ($tok === $n || (strlen($n) >= 4 && strpos($tok, $n) === 0)) { $hit[$id] = true; $seen[$tok] = true; }
            }
        }
        foreach (array_keys($hit) as $id) $count[$id] = ($count[$id] ?? 0) + 1;
    }
    if (!$count) return ['user' => null, 'text' => ''];
    arsort($count);   // stable: on a tie the first name found wins
    return ['user' => ck_users($db)[(int)array_key_first($count)], 'text' => substr(implode(', ', array_keys($seen)), 0, 120)];
}

/**
 * Read the default booking owner from the Calc (ops_calc(), cached on its Dropbox rev) for rows not read
 * in the last CK_OWNER_AUTO_TTL seconds, within $budget seconds; the rest at the next call. Trips not started,
 * Tanzania only. Updates $rows in place. → number of rows read.
 */
function ck_owners_refresh_auto(PDO $db, array &$rows, float $budget): int {
    require_once __DIR__ . '/../../operations/includes/ops_service.php';
    $deadline = microtime(true) + $budget;
    $stale = date('Y-m-d H:i:s', time() - CK_OWNER_AUTO_TTL);
    $n = 0;
    foreach ($rows as &$r) {
        if ($r['other'] || !$r['reqs'] || ($r['to_arrival'] !== null && $r['to_arrival'] < 0)) continue;
        if ($r['booking_owner_auto_at'] !== null && $r['booking_owner_auto_at'] > $stale) continue;
        if (microtime(true) > $deadline) break;
        $req = $r['reqs'][0];
        foreach ($r['reqs'] as $q) if ((int)$q['id'] < (int)$req['id']) $req = $q;
        $c = ops_calc_safe($db, $req);
        if ($c['status'] === 'error') continue;                 // Dropbox: try again next time
        $g = $c['status'] === 'ok' ? ck_booking_owner_from_calc($db, $c['days']) : ['user' => null, 'text' => ''];
        $r['booking_owner_auto_id']   = $g['user'] ? (int)$g['user']['id'] : null;
        $r['booking_owner_auto_text'] = $g['text'] !== '' ? $g['text'] : null;
        $r['booking_owner_auto_at']   = date('Y-m-d H:i:s');
        $db->prepare("UPDATE ck_folders SET booking_owner_auto_id = ?, booking_owner_auto_text = ?, booking_owner_auto_at = ? WHERE id = ?")
           ->execute([$r['booking_owner_auto_id'], $r['booking_owner_auto_text'], $r['booking_owner_auto_at'], (int)$r['id']]);
        $n++;
    }
    unset($r);
    return $n;
}

/** Admin (isLeadsAdmin) or one of the row's current owners. */
function ck_can_set_owners(PDO $db, array $user, array $row): bool {
    if (!empty($user['ck_admin'])) return true;                                  // Agent API
    if (function_exists('isLeadsAdmin') ? isLeadsAdmin() : in_array($user['role_name'] ?? '', ['admin', 'manager'], true)) return true;
    $o = ck_owners_resolve($db, $row);
    $uid = (int)($user['id'] ?? 0);
    return $uid > 0 && in_array($uid, [(int)($o['ck_owner']['user_id'] ?? 0), (int)($o['booking_owner']['user_id'] ?? 0)], true);
}

/**
 * Set or clear (null / '' = back to the default rule) the owners of a tracker row.
 * $ch: ck_owner?, booking_owner? (user id or username). Logged in ck_events (ck_owner / booking_owner).
 * → ['ok', 'changes' => [field => [from, to]], 'owners' => ck_owners_resolve() after] or ['ok' => false, 'error'].
 */
function ck_set_owners(PDO $db, array $row, array $ch, ?int $userId, bool $confirm): array {
    ck_ensure_schema($db);
    $label = fn(?array $o) => $o ? $o['name'] . ($o['default'] ? ' (default)' : '') : 'nobody';
    $before = ck_owners_resolve($db, $row);
    $set = [];
    foreach (['ck_owner' => 'ck_owner_user_id', 'booking_owner' => 'booking_owner_user_id'] as $k => $col) {
        if (!array_key_exists($k, $ch)) continue;
        $v = $ch[$k];
        if ($v === null || $v === '' || $v === 'default') { $set[$col] = null; continue; }
        $u = ck_user_find($db, $v);
        if (!$u) return ['ok' => false, 'error' => $k . ': no active user "' . $v . '"'];
        $set[$col] = (int)$u['id'];
    }
    if (!$set) return ['ok' => false, 'error' => 'Send ck_owner and / or booking_owner (username, or null = back to default)'];
    $after = $row;
    foreach ($set as $col => $v) $after[$col] = $v;
    $afterO = ck_owners_resolve($db, $after);
    $changes = [];
    foreach (['ck_owner', 'booking_owner'] as $k) {
        if ((int)($row[$k . '_user_id'] ?? 0) !== (int)($after[$k . '_user_id'] ?? 0)) $changes[$k] = [$label($before[$k]), $label($afterO[$k])];
    }
    if ($confirm && $changes) {
        $now = ck_now();
        $cols = implode(', ', array_map(fn($c) => "$c = ?", array_keys($set)));
        $db->prepare("UPDATE ck_folders SET $cols, owners_updated_at = ?, owners_updated_by = ? WHERE id = ?")
           ->execute([...array_values($set), $now, $userId, (int)$row['id']]);
        foreach ($changes as $k => $v) ck_log($db, (int)$row['id'], $k, mb_substr($v[0], 0, 255), mb_substr($v[1], 0, 255), $userId, 'hub', $now);
    }
    return ['ok' => true, 'dry_run' => !$confirm, 'changes' => $changes, 'owners' => $afterO];
}

/** The tracker row of a ck_folders id or of a request (a GRP member finds its group). */
function ck_tracker_row_for(PDO $db, ?int $folderId, ?int $requestId): ?array {
    foreach (ck_tracker_rows($db) as $r) {
        if ($folderId ? (int)$r['id'] === $folderId
                      : in_array((int)$requestId, array_map(fn($x) => (int)$x['id'], $r['reqs']), true)) return $r;
    }
    return null;
}

// ── Automatic SafariCheck (safariagent on GitHub Actions) ─────────────────────
// Server-only settings in includes/config.php:
//   CK_AGENT_TOKEN   token the runner sends to ck_agent_api.php (GitHub secret HUB_TOKEN)
//   CK_GITHUB_TOKEN  fine-grained GitHub token, "Actions: read and write" on the repo
//   CK_GITHUB_REPO   'owner/repo' of the safariagent repository
// Without the GitHub settings the request stays pending and the nightly run does it.

/** Top-level files and invoices/*.pdf are the only files the checks open
 *  (plus SafariCheck_confirmed.json, the human confirmations — ck_confirm.php). */
function ck_agent_file_allowed(string $rel): bool {
    if ($rel === '' || strpos($rel, "\\") !== false) return false;
    $parts = explode('/', trim($rel, '/'));
    // Block path traversal ('..' / '.' segments) — but a file NAME may contain
    // '..' (e.g. 'KIBO PALACE..pdf'), which must still be served.
    foreach ($parts as $p) if ($p === '' || $p === '.' || $p === '..') return false;
    $name  = strtolower(end($parts));
    if (count($parts) === 1) {
        if ($name === 'safaricheck_confirmed.json') return true;
        return (bool)preg_match('/\.(xlsx|xlsm|docx|pdf)$/', $name) && strpos($name, '~$') !== 0;
    }
    if (count($parts) !== 2) return false;
    $dir = strtolower($parts[0]);
    if ($dir === 'invoices') return str_ends_with($name, '.pdf');
    // Supplier booking emails (transfer check). Never anything else in bookings/.
    if ($dir === 'bookings') return str_ends_with($name, '.eml');
    // GRP: each confirmed client's sub-folder holds that client's Word programme
    // (rooms sold) and their invoice ('Invoice SE-....pdf'). Only those, and
    // never from the standard sub-folders.
    $internal = ['passports', 'flights', 'intflights', 'bookings', 'vouchers', 'insurance',
                 'complain', 'guestcomments', 'mails', 'old'];
    if (in_array($dir, $internal, true)) return false;
    if (str_ends_with($name, '.pdf')) return str_contains($name, 'invoice');
    return str_ends_with($name, '.docx') && strpos($name, '~$') !== 0;
}

/** CK emails (tracker / report "Mail"): the booking team first, then every active user. */
const CK_BOOKING_EMAIL = 'operations@savannahexplorers.com';

function ck_mail_suggestions(PDO $db): array {
    $out = [CK_BOOKING_EMAIL => 'Booking / Operations'];
    foreach ($db->query("SELECT full_name, email FROM users
                         WHERE is_active = 1 AND email IS NOT NULL AND email <> '' ORDER BY full_name") as $u) {
        $out[strtolower(trim($u['email']))] ??= $u['full_name'];
    }
    return $out;
}

/** Start the GitHub workflow for these ck_folders ids. */
function ck_github_dispatch(array $ids): array {
    if (!defined('CK_GITHUB_TOKEN') || !defined('CK_GITHUB_REPO') || CK_GITHUB_TOKEN === '' || CK_GITHUB_REPO === '') {
        return ['ok' => false, 'msg' => 'GitHub not configured — the check will run tonight.'];
    }
    $ch = curl_init('https://api.github.com/repos/' . CK_GITHUB_REPO . '/actions/workflows/ck.yml/dispatches');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . CK_GITHUB_TOKEN,
            'Accept: application/vnd.github+json',
            'X-GitHub-Api-Version: 2022-11-28',
            'User-Agent: SavannahHub-CK',
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode(['ref' => 'main', 'inputs' => ['ids' => implode(',', array_map('intval', $ids))]]),
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code === 204) return ['ok' => true, 'msg' => 'Check started — result in about 2 minutes.'];
    return ['ok' => false, 'msg' => "GitHub dispatch failed (HTTP $code) — the check will run tonight. " . substr((string)$body, 0, 200)];
}

/** Mark folders as waiting for a check and start the runner for them. */
function ck_request_check(PDO $db, array $ids, string $trigger): array {
    ck_ensure_schema($db);
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if ($ids) {   // no CK outside Tanzania
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = $db->prepare("SELECT id, folder_name FROM ck_folders WHERE id IN ($in)");
        $st->execute($ids);
        $ids = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $f) {
            if (!ck_is_other_destination($f['folder_name'])) $ids[] = (int)$f['id'];
        }
        if (!$ids) return ['ok' => false, 'msg' => 'No CK outside Tanzania — nothing to check.'];
    }
    if (!$ids) return ['ok' => false, 'msg' => 'Nothing to check.'];
    $in = implode(',', array_fill(0, count($ids), '?'));
    $db->prepare("UPDATE ck_folders SET check_requested_at = ?, check_trigger = ? WHERE id IN ($in)")
       ->execute([ck_now(), $trigger, ...$ids]);
    return ck_github_dispatch($ids);
}

/**
 * Nightly automatic check, started by the Hub itself (ck_cron.php, called
 * every 30 min by cron-job.org): the first call after 05:00 Tanzania each day
 * dispatches the runner on the Hub queue. GitHub's own `schedule` trigger is
 * best-effort and was skipped, so it is not used.
 */
const CK_NIGHTLY_HOUR = 5;

function ck_nightly_dispatch(PDO $db): string {
    $db->exec("CREATE TABLE IF NOT EXISTS ck_meta (
        k VARCHAR(40) PRIMARY KEY,
        v VARCHAR(255) NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $today = ck_now('Y-m-d');
    if ((int)ck_now('G') < CK_NIGHTLY_HOUR) return 'nightly: not yet (before ' . CK_NIGHTLY_HOUR . ':00)';
    $st = $db->prepare("SELECT v FROM ck_meta WHERE k = 'nightly_last'");
    $st->execute();
    if ($st->fetchColumn() === $today) return "nightly: already started today";
    $res = ck_github_dispatch([]);            // empty ids = the Hub queue
    if ($res['ok']) {
        $db->prepare("INSERT INTO ck_meta (k, v) VALUES ('nightly_last', ?)
                      ON DUPLICATE KEY UPDATE v = VALUES(v)")->execute([$today]);
    }
    return 'nightly: ' . $res['msg'];
}
