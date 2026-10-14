<?php
/**
 * followup_service.php — Booking follow-up: open items the booking team chases until the
 * confirmed document is saved in the booking folder. Shared by the page
 * (booking_followup.php + api/followup.php), the Agent API (followup_list / followup_scan /
 * followup_update / followup_upsert), the cron (followup_cron.php) and the memo digest.
 *
 *   type supplier  lodge / camp / flight documents in <booking>/invoices/ (top level, not old/)
 *                  still provisional, provisional expired, on request, or an Orangi voucher
 *                  confirmed with rooms still provisional (followup_classify.php reads them)
 *   type ticket    domestic flights in the next 14 days with no ticket file in the folder
 *                  (ops_flight_tickets_report(), the flight_tickets action)
 *
 * The check writes only the document fields; owner / contacted / staff status / notes are the
 * staff's. Lines are never deleted: they close (auto_confirmed, auto_ticket_saved,
 * auto_trip_ended, auto_not_found, manual) and re-open when the check still finds them.
 * Every change → booking_followup_events (user_id NULL = the check).
 *
 * Reading PDFs on the server needs pdftotext (exec, or FOLLOWUP_PDFTOTEXT = path of a binary)
 * or smalot/pdfparser (Composer). BlueHost has neither today: the supplier scan then reports
 * "no PDF reader" and tools/followup_local_scan.php reads the PDFs on the office PC and sends
 * the results through followup_upsert (same rules, followup_classify.php).
 */
require_once __DIR__ . '/ops_service.php';
require_once __DIR__ . '/followup_classify.php';

const FU_CLASSIFY_VERSION = 2;          // bump when followup_classify() reads differently: cached documents are read again
const FU_TICKET_DAYS      = 14;         // ticket scan: flights in the next N days
const FU_SCAN_BUDGET      = 240;        // seconds a supplier scan may spend downloading PDFs (the rest: next run)
const FU_GROUP_DAYS       = 60;         // group check: arrivals in the next N days
const FU_ISSUES  = ['provisional', 'provisional_expired', 'on_request', 'orangi_rooms_provisional', 'ticket_missing', 'unreadable',
                    'group_to_finalize', 'group_znz_flight'];
const FU_STATUSES = ['open', 'in_progress', 'problem', 'resolved', 'not_relevant'];
const FU_REASONS = ['auto_confirmed', 'auto_ticket_saved', 'auto_trip_ended', 'auto_not_found', 'manual'];
/** Worst first: the issue a line shows when several documents carry the same reference. */
const FU_ISSUE_RANK = ['provisional_expired' => 0, 'provisional' => 1, 'on_request' => 2, 'orangi_rooms_provisional' => 3,
                       'ticket_missing' => 4, 'unreadable' => 5, 'group_to_finalize' => 6, 'group_znz_flight' => 7];
const FU_ISSUE_LABELS = ['provisional' => 'Provisional', 'provisional_expired' => 'Provisional expired', 'on_request' => 'On request',
                         'orangi_rooms_provisional' => 'Rooms still provisional', 'ticket_missing' => 'Ticket missing',
                         'unreadable' => 'Unreadable', 'group_to_finalize' => 'Group to finalize', 'group_znz_flight' => 'Group ZNZ flight'];
const FU_STATUS_LABELS = ['open' => 'Open', 'in_progress' => 'In progress', 'problem' => 'Problem', 'resolved' => 'Resolved',
                          'not_relevant' => 'Not relevant'];
const FU_REASON_LABELS = ['auto_confirmed' => 'confirmed document saved', 'auto_ticket_saved' => 'ticket saved',
                          'auto_trip_ended' => 'trip ended', 'auto_not_found' => 'document no longer in invoices/',
                          'manual' => 'closed by staff'];
const FU_EDIT_PERMISSION = 'booking_followup_edit';

// ── Schema (same DDL as migrations/069_booking_followup.sql) ─────────────────

function followup_schema(PDO $db): void {
    static $done = false;
    if ($done) return;
    $issues = "'" . implode("','", FU_ISSUES) . "'";
    $db->exec("CREATE TABLE IF NOT EXISTS booking_followups (
        id              INT AUTO_INCREMENT PRIMARY KEY,
        type            ENUM('supplier','ticket','group') NOT NULL,
        dedupe_key      VARCHAR(190) NOT NULL,
        request_id      INT NULL,
        folder          VARCHAR(255) NULL,
        start_date      DATE NULL,
        end_date        DATE NULL,
        service_date    DATE NULL,
        supplier        VARCHAR(150) NULL,
        supplier_ref    VARCHAR(60) NULL,
        service         VARCHAR(255) NULL,
        source_file     VARCHAR(500) NULL,
        issue           ENUM($issues) NOT NULL,
        doc_status_text VARCHAR(255) NULL,
        expiry_date     DATE NULL,
        booked_by       VARCHAR(80) NULL,
        owner_user_id   INT NULL,
        contacted_at    DATE NULL,
        staff_status    ENUM('open','in_progress','problem','resolved','not_relevant') NOT NULL DEFAULT 'open',
        notes           TEXT NULL,
        first_seen_at   DATETIME NOT NULL,
        last_seen_at    DATETIME NOT NULL,
        closed_at       DATETIME NULL,
        closed_by       VARCHAR(20) NULL,
        close_reason    ENUM('auto_confirmed','auto_ticket_saved','auto_trip_ended','auto_not_found','manual') NULL,
        scan_source     VARCHAR(10) NULL,
        details         TEXT NULL,
        UNIQUE KEY uq_followup_key (dedupe_key),
        KEY idx_followup_request (request_id),
        KEY idx_followup_closed (closed_at),
        KEY idx_followup_owner (owner_user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    followup_schema_upgrade($db, $issues);
    $db->exec("CREATE TABLE IF NOT EXISTS booking_followup_events (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        followup_id INT NOT NULL,
        at          DATETIME NOT NULL,
        user_id     INT NULL,
        field       VARCHAR(40) NOT NULL,
        old_value   TEXT NULL,
        new_value   TEXT NULL,
        KEY idx_followup_event (followup_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS booking_followup_docs (
        path_hash   CHAR(40) NOT NULL PRIMARY KEY,
        path        VARCHAR(1000) NOT NULL,
        rev         VARCHAR(64) NULL,
        request_id  INT NULL,
        result_json TEXT NULL,
        read_at     DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS booking_followup_runs (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        at           DATETIME NOT NULL,
        type         VARCHAR(10) NOT NULL,
        source       VARCHAR(10) NOT NULL,
        user_id      INT NULL,
        partial      TINYINT(1) NOT NULL DEFAULT 0,
        summary_json MEDIUMTEXT NULL,
        KEY idx_followup_run_at (at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

/**
 * Tables created by migration 069 / the first version: add the group type and `details`
 * (migration 070 does the same). Checked once in INFORMATION_SCHEMA; each ALTER may fail harmlessly.
 */
function followup_schema_upgrade(PDO $db, string $issues): void {
    try {
        $st = $db->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'booking_followups' AND COLUMN_NAME = 'details'");
        if ((int)$st->fetchColumn() > 0) return;
    } catch (Throwable $e) {
        return;   // no INFORMATION_SCHEMA (tests)
    }
    foreach (["ALTER TABLE booking_followups MODIFY type ENUM('supplier','ticket','group') NOT NULL",
              "ALTER TABLE booking_followups MODIFY issue ENUM($issues) NOT NULL",
              "ALTER TABLE booking_followups ADD COLUMN details TEXT NULL"] as $sql) {
        try { $db->exec($sql); } catch (Throwable $e) { error_log('followup_schema_upgrade: ' . $e->getMessage()); }
    }
}

// ── Who may do what ──────────────────────────────────────────────────────────

/** Admin of the follow-up page: reassign owner, close as not_relevant. */
function followup_is_admin(array $user): bool {
    if (isset($user['followup_admin'])) return (bool)$user['followup_admin'];   // Agent API: set by the caller
    if (function_exists('isLeadsAdmin')) return (bool)isLeadsAdmin();
    return in_array($user['role_name'] ?? '', ['admin', 'manager'], true);
}

/** Booking team (module booking_followup_edit) or admin. */
function followup_can_edit(array $user): bool {
    return followup_is_admin($user) || in_array(FU_EDIT_PERMISSION, (array)($user['permissions'] ?? []), true);
}

// ── Bookings and their folders ───────────────────────────────────────────────

/**
 * The booking folders the check reads: every confirmed booking not ended yet (same set as
 * Operations / flight_tickets: _END tag, not cancelled). A GRP's documents live in the group
 * folder: one unit for the group (owner = its first request) and one per member sub-folder.
 * → [dirKey => ['dir', 'folder' (path under /001_Safari), 'request' (owner row), 'request_ids', 'start_date', 'end_date']]
 * $requestId: only the units that request belongs to.
 */
function followup_units(PDO $db, ?int $requestId = null, ?string $today = null): array {
    $today = $today ?? date('Y-m-d');
    $units = [];
    $add = function (string $dir, array $r) use (&$units) {
        $k = strtolower(rtrim($dir, '/'));
        if (!isset($units[$k])) {
            $base = basename($dir);
            $d = parse_folder_dates($base);
            $units[$k] = ['dir' => rtrim($dir, '/'), 'folder' => preg_replace('#^/?001_Safari/#i', '', ltrim($dir, '/')),
                          'request' => $r, 'request_ids' => [],
                          'start_date' => $d['start_date'] ?: $r['start_date'], 'end_date' => $d['end_date'] ?: $r['end_date']];
        }
        $units[$k]['request_ids'][] = (int)$r['id'];
        if ((int)$r['id'] < (int)$units[$k]['request']['id']) $units[$k]['request'] = $r;   // GRP: the first request owns the group lines
    };
    foreach (ops_booked_requests($db, $today, '2099-12-31') as $r) {
        if (($r['end_date'] ?: $r['start_date']) < $today) continue;
        $rel = folder_rel_path($r);
        if ($rel === '' || stripos($rel, '001_Safari/') !== 0) continue;   // confirmed bookings only
        $own  = '/' . $rel;
        $segs = explode('/', $rel);
        $grp  = null;
        if (count($segs) > 2 && folder_is_booking_leaf($segs[count($segs) - 2])) $grp = '/' . implode('/', array_slice($segs, 0, -1));
        elseif (trim((string)$r['group_folder']) !== '' && trim((string)$r['group_folder']) !== trim((string)$r['practice_code'])) {
            $grp = CK_BASE . '/' . trim($r['group_folder']);
            $own = $grp . '/' . trim((string)$r['practice_code']);
        }
        $add($own, $r);
        if ($grp) $add($grp, $r);
    }
    if ($requestId) $units = array_filter($units, fn($u) => in_array($requestId, $u['request_ids'], true));
    return $units;
}

// ── PDF text on the server ───────────────────────────────────────────────────

/** 'pdftotext' | 'smalot' | null (no way to read a PDF here). */
function followup_pdf_reader(): ?string {
    static $r = false;
    if ($r !== false) return $r;
    $r = null;
    $bin = defined('FOLLOWUP_PDFTOTEXT') ? (string)FOLLOWUP_PDFTOTEXT : 'pdftotext';
    if (function_exists('exec') && !in_array('exec', array_map('trim', explode(',', (string)ini_get('disable_functions'))), true)) {
        $out = []; $rc = 1;
        @exec(escapeshellarg($bin) . ' -v 2>&1', $out, $rc);
        if (preg_match('/pdftotext version/i', implode(' ', $out))) return $r = 'pdftotext';
    }
    $auto = __DIR__ . '/../../../vendor/autoload.php';
    if (is_file($auto)) {
        require_once $auto;
        if (class_exists('\Smalot\PdfParser\Parser')) $r = 'smalot';
    }
    return $r;
}

/** Text of a PDF (layout kept with pdftotext). '' when the PDF has no text layer. */
function followup_pdf_text(string $bytes): string {
    $reader = followup_pdf_reader();
    if ($reader === 'pdftotext') {
        $bin = defined('FOLLOWUP_PDFTOTEXT') ? (string)FOLLOWUP_PDFTOTEXT : 'pdftotext';
        $tmp = tempnam(sys_get_temp_dir(), 'fu');
        file_put_contents($tmp, $bytes);
        $out = []; $rc = 1;
        @exec(escapeshellarg($bin) . ' -layout -q ' . escapeshellarg($tmp) . ' - 2>/dev/null', $out, $rc);
        @unlink($tmp);
        return $rc === 0 ? implode("\n", $out) : '';
    }
    if ($reader === 'smalot') {
        try {
            return (string)(new \Smalot\PdfParser\Parser())->parseContent($bytes)->getText();
        } catch (Throwable $e) {
            return '';
        }
    }
    throw new RuntimeException('No PDF reader on this server');
}

// ── Supplier scan ────────────────────────────────────────────────────────────

/**
 * Read the PDFs in a unit's invoices/ (top level; old/ is a sub-folder, never listed).
 * Each document is classified once per Dropbox revision (booking_followup_docs).
 * → ['docs' => [classified + source_file], 'complete' => bool]; complete = every PDF read.
 */
function followup_read_unit(PDO $db, array $u, string $token, float $deadline, bool $canRead, string $today): array {
    $files = cp_retry(fn() => cp_list_files($token, $u['dir'] . '/invoices'));
    $docs = []; $complete = true;
    $get = $db->prepare("SELECT rev, result_json FROM booking_followup_docs WHERE path_hash = ?");
    foreach ($files as $f) {
        if (followup_skip_file($f['name'])) continue;
        $path = $u['dir'] . '/invoices/' . $f['name'];
        $hash = sha1(strtolower($path));
        $get->execute([$hash]);
        $c = $get->fetch(PDO::FETCH_ASSOC);
        $res = $c ? json_decode((string)$c['result_json'], true) : null;
        if (!$res || $c['rev'] !== $f['rev'] || ($res['v'] ?? 0) !== FU_CLASSIFY_VERSION) {
            if (!$canRead || microtime(true) > $deadline) { $complete = false; continue; }
            $bytes = cp_retry(fn() => dropbox_download_text($token, $path));
            if ($bytes === null) continue;                                     // gone since the listing
            $res = followup_classify(followup_pdf_text($bytes), $f['name'], $u, $today) + ['v' => FU_CLASSIFY_VERSION];
            $db->prepare("REPLACE INTO booking_followup_docs (path_hash, path, rev, request_id, result_json, read_at) VALUES (?,?,?,?,?,?)")
               ->execute([$hash, $path, $f['rev'], (int)$u['request']['id'], json_encode($res, JSON_UNESCAPED_UNICODE), date('Y-m-d H:i:s')]);
        }
        $docs[] = $res + ['source_file' => 'invoices/' . $f['name']];
    }
    return ['docs' => $docs, 'complete' => $complete];
}

/** dedupe_key of a supplier document: request + reference, else request + supplier + service date. */
function followup_supplier_key(int $requestId, array $d): string {
    if (($d['issue'] ?? null) === 'unreadable') return followup_key_fit('S|' . $requestId . '|file|' . followup_norm(basename((string)$d['source_file'])));
    if (!empty($d['supplier_ref'])) return followup_key_fit('S|' . $requestId . '|' . strtoupper(preg_replace('/\s+/', '', $d['supplier_ref'])));
    return followup_key_fit('S|' . $requestId . '|' . followup_norm((string)$d['supplier']) . '|' . ($d['service_date'] ?? ''));
}

/** Keys fit the 190-character unique index. */
function followup_key_fit(string $k): string {
    return strlen($k) <= 190 ? $k : substr($k, 0, 149) . '#' . sha1($k);
}

/**
 * Lines for one unit from its classified documents.
 * A reference with a clean Confirmed document is confirmed (its provisional / on-request
 * lines close); a voucher note "rooms still provisional" stays until the voucher no longer says it.
 * → ['lines' => [key => line], 'confirmed' => [key => source_file]]
 */
function followup_supplier_candidates(array $u, array $docs, string $today): array {
    $rid = (int)$u['request']['id'];
    $lines = []; $confirmed = [];
    foreach ($docs as $d) {
        if (in_array($d['issue'] ?? null, ['provisional', 'provisional_expired'], true)) {   // expired is relative to today, not to when the PDF was read
            $d['issue'] = (!empty($d['expiry_date']) && $d['expiry_date'] < $today) ? 'provisional_expired' : 'provisional';
        }
        $key = followup_supplier_key($rid, $d);
        if (($d['status'] ?? '') === 'confirmed' && empty($d['issue'])) { $confirmed[$key] = $d['source_file']; continue; }
        if (empty($d['issue'])) continue;                                          // no status printed (receipts, our own invoices …)
        $line = ['type' => 'supplier', 'dedupe_key' => $key, 'request_id' => $rid, 'folder' => $u['folder'],
                 'start_date' => $u['start_date'], 'end_date' => $u['end_date'],
                 'service_date' => $d['service_date'] ?? null, 'supplier' => $d['supplier'] ?? null,
                 'supplier_ref' => $d['supplier_ref'] ?? null, 'service' => null, 'source_file' => $d['source_file'],
                 'issue' => $d['issue'], 'doc_status_text' => $d['doc_status_text'] ?? null,
                 'expiry_date' => $d['expiry_date'] ?? null, 'booked_by' => $d['booked_by'] ?? null];
        if (!isset($lines[$key]) || FU_ISSUE_RANK[$line['issue']] < FU_ISSUE_RANK[$lines[$key]['issue']]) {
            if (isset($lines[$key])) $line['booked_by'] = $line['booked_by'] ?? $lines[$key]['booked_by'];
            $lines[$key] = $line;
        }
    }
    foreach ($lines as $k => $l) {
        if (isset($confirmed[$k]) && $l['issue'] !== 'orangi_rooms_provisional' && $l['issue'] !== 'unreadable') unset($lines[$k]);
    }
    return ['lines' => $lines, 'confirmed' => $confirmed];
}

// ── Ticket scan ──────────────────────────────────────────────────────────────

/** Airline named in a Calc flight text ("Seronera-Zanzibar Auric UI633" → "Auric Air"), or ''. */
function followup_airline(string $s): string {
    $map = ['auric' => 'Auric Air', 'coastal' => 'Coastal Aviation', 'precision' => 'Precision Air', 'flight ?link' => 'Flightlink',
            'as ?salaam|assalaam' => 'As Salaam Air', 'safari ?air' => 'Safari Air Link', 'regional' => 'Regional Air',
            'unity' => 'Unity Air', 'zanair' => 'ZanAir', 'tropical' => 'Tropical Air', 'air ?tanzania' => 'Air Tanzania'];
    foreach ($map as $re => $name) if (preg_match('/' . $re . '/i', $s)) return $name;
    return '';
}

/**
 * Ticket lines from the flight_tickets report: flights in [today, today + 14] of bookings with
 * no ticket file (status missing), and the Calcs that could not be read (unreadable).
 * The report window starts 30 days back so a trip already running keeps its later flights.
 */
function followup_ticket_candidates(PDO $db, string $today, ?int $requestId = null): array {
    $to  = date('Y-m-d', strtotime($today . ' +' . FU_TICKET_DAYS . ' days'));
    $rep = ops_flight_tickets_report($db, date('Y-m-d', strtotime($today . ' -30 days')), $to);
    $lines = []; $ok = []; $checked = []; $paths = [];
    $ids = array_map(fn($r) => (int)$r['request_id'], $rep['rows']);
    if ($ids) {
        foreach ($db->query("SELECT id, practice_code, group_folder, dropbox_url FROM requests WHERE id IN (" . implode(',', $ids) . ")")
                    ->fetchAll(PDO::FETCH_ASSOC) as $q) {
            $paths[(int)$q['id']] = preg_replace('#^001_Safari/#i', '', folder_rel_path($q));   // folder as the supplier lines store it
        }
    }
    foreach ($rep['rows'] as $r) {
        $rid = (int)$r['request_id'];
        if ($requestId && $rid !== $requestId) continue;
        if (($r['end_date'] ?: $r['start_date']) < $today) continue;
        $base = ['type' => 'ticket', 'request_id' => $rid, 'folder' => ($paths[$rid] ?? '') ?: $r['folder'],
                 'start_date' => $r['start_date'], 'end_date' => $r['end_date'], 'supplier_ref' => null, 'source_file' => null,
                 'expiry_date' => null, 'booked_by' => null];
        if ($r['status'] === 'ok') { $ok[$rid] = true; continue; }
        if ($r['status'] === 'unknown') {
            if ($r['start_date'] < $today) continue;
            $key = 'T|' . $rid . '|calc';
            $lines[$key] = $base + ['dedupe_key' => $key, 'service_date' => $r['start_date'], 'supplier' => null,
                                    'service' => 'Domestic flights (Calc)', 'issue' => 'unreadable',
                                    'doc_status_text' => 'Calc not readable: ' . substr((string)($r['msg'] ?? ''), 0, 200)];
            continue;
        }
        $checked[$rid] = true;
        foreach ($r['flights'] as $f) {
            if ($f['date'] < $today || $f['date'] > $to) continue;
            $key = followup_key_fit('T|' . $rid . '|' . $f['date'] . '|' . substr(followup_norm($f['route']), 0, 60));
            $air = followup_airline($f['route']);
            $lines[$key] = $base + ['dedupe_key' => $key, 'service_date' => $f['date'], 'supplier' => $air ?: null,
                                    'service' => trim(($air && stripos($f['route'], $air) === false ? $air . ' ' : '') . $f['route']),
                                    'issue' => 'ticket_missing', 'doc_status_text' => 'No ticket file in the folder'];
        }
    }
    return ['lines' => $lines, 'ok' => $ok, 'checked' => $checked, 'to' => $to];
}

// ── Groups (60 days before arrival) ──────────────────────────────────────────

/** 'grp' (any GRPnnnn folder), 'sic' (Il Diamante / Panorama set departures), 'private' (agency group tagged _PROVISIONAL), or null. */
function followup_group_kind(string $name): ?string {
    if (preg_match('/GRP\d{4}/i', $name)) return 'grp';
    if (preg_match('/\(Diamante|Panorama/i', $name)) return 'sic';
    if (preg_match('/[_-]PROVISIONAL$/i', preg_replace('/(?:[_-]+CK)+$/i', '', trim($name)))) return 'private';
    return null;
}

const FU_GROUP_LABELS = ['grp' => 'GRP', 'sic' => 'Il Diamante SIC', 'private' => 'Private group'];

/**
 * What a group's Calc says: RECAP total + one entry per client sheet (pax, rooms, ARK-ZNZ flight), or
 * the only sheet (Diamante / private groups / a collective GRP still on its price sheet).
 * Passport numbers are never read (calc_reader.php).
 */
function followup_group_calc(string $token, string $dir, array $calc): array {
    require_once __DIR__ . '/../../leads/includes/calc_reader.php';
    $out = ['file' => $calc['name'], 'recap' => false, 'pax_total' => null, 'rooms' => '', 'clients' => [], 'warnings' => []];
    $bytes = cp_retry(fn() => dropbox_download_text($token, $dir . '/' . $calc['name']));
    if ($bytes === null || $bytes === '') { $out['warnings'][] = 'Calc not downloaded'; return $out; }
    $tmp = tempnam(sys_get_temp_dir(), 'fugrp_');
    file_put_contents($tmp, $bytes);
    try {
        return followup_group_calc_file($tmp, $calc['name']);
    } finally {
        @unlink($tmp);
    }
}

/** followup_group_calc() on a local .xlsx (also used by the tests). */
function followup_group_calc_file(string $path, string $name): array {
    require_once __DIR__ . '/../../leads/includes/calc_reader.php';
    $out = ['file' => $name, 'recap' => false, 'pax_total' => null, 'rooms' => '', 'clients' => [], 'warnings' => []];
    $sum = fn(array $p) => (int)($p['adults'] ?? 0) + (int)($p['teen'] ?? 0) + (int)($p['child'] ?? 0);
    $znz = function (array $c) {
        foreach ($c['days'] as $d) {
            // Towards Zanzibar: the day ends there ("Karatu-Arusha-ZNZ"), not "ZNZ-Arusha".
            $parts = array_values(array_filter(array_map('trim', preg_split('/[-–>\/]+/u', (string)$d['label'])), 'strlen'));
            if ((float)$d['flight_cost'] > 0 && $parts && preg_match('/zanzibar|\bZNZ\b/i', end($parts))) return ['date' => $d['date'], 'route' => trim($d['label'])];
        }
        return null;
    };
    $sheets = sc_xlsx_sheets($path);
    $read = function (string $sheet) use ($path, $sum, $znz, &$out) {
        try {
            $c = calc_read_file($path, $sheet);
        } catch (Throwable $e) {
            $out['warnings'][] = ($sheet ?: 'Calc') . ': ' . $e->getMessage();
            return null;
        }
        $f = $znz($c);
        return ['sheet' => trim($sheet ?: $c['sheet']), 'pax' => $sum($c['pax']), 'rooms' => trim(preg_replace('/\s+/', ' ', (string)$c['room_config'])),
                'znz' => $f, 'guests_tba' => (bool)$c['guests_tba']];
    };
    if (sc_has_recap($sheets)) {
        $out['recap'] = true;
        foreach ($sheets as $s) {
            if (stripos($s, 'recap') !== false) { if ($r = $read($s)) { $out['pax_total'] = $r['pax']; $out['rooms'] = $r['rooms']; } }
        }
        foreach (calc_group_client_sheets($sheets) as $s) if ($r = $read($s)) $out['clients'][] = $r;
    } elseif (count($sheets) === 1 && ($r = $read($sheets[0]))) {
        $out['pax_total'] = $r['pax']; $out['rooms'] = $r['rooms']; $out['clients'][] = $r;
    } else {
        $out['warnings'][] = count($sheets) . ' sheets and no RECAP: ' . implode(', ', array_slice($sheets, 0, 6));
    }
    return $out;
}

/** Classified documents of a group folder's invoices/: server reading if possible, else what the office PC sent. */
function followup_group_docs(PDO $db, string $token, string $dir, bool $canRead, float $deadline, string $today, array $trip): array {
    $files = cp_retry(fn() => cp_list_files($token, $dir . '/invoices'));
    $get = $db->prepare("SELECT rev, result_json FROM booking_followup_docs WHERE path_hash = ?");
    $docs = [];
    foreach ($files as $f) {
        if (followup_skip_file($f['name'])) continue;
        $path = $dir . '/invoices/' . $f['name'];
        $get->execute([sha1(strtolower($path))]);
        $c = $get->fetch(PDO::FETCH_ASSOC);
        $res = $c ? json_decode((string)$c['result_json'], true) : null;
        $fresh = $res && ($res['v'] ?? 0) === FU_CLASSIFY_VERSION && ($c['rev'] === $f['rev'] || ($c['rev'] === 'local' && !$canRead));
        if (!$fresh && $canRead && microtime(true) < $deadline) {
            $bytes = cp_retry(fn() => dropbox_download_text($token, $path));
            if ($bytes !== null) {
                $res = followup_classify(followup_pdf_text($bytes), $f['name'], $trip, $today) + ['v' => FU_CLASSIFY_VERSION];
                $db->prepare("REPLACE INTO booking_followup_docs (path_hash, path, rev, request_id, result_json, read_at) VALUES (?,?,?,?,?,?)")
                   ->execute([sha1(strtolower($path)), $path, $f['rev'], null, json_encode($res, JSON_UNESCAPED_UNICODE), date('Y-m-d H:i:s')]);
                $fresh = true;
            }
        }
        $docs[] = ($res ?: ['status' => 'unknown']) + ['file' => $f['name'], 'read' => (bool)$res];
    }
    return $docs;
}

/**
 * The two group lines for one folder: "group to finalize" (what the Calc, the client sub-folders and the
 * supplier documents say) and, when a Zanzibar flight is involved, seats booked vs clients flying with us.
 * The booking team checks and finalises (release / confirm services, room list, arrival / departure flights).
 */
function followup_group_lines(string $name, string $kind, array $trip, ?array $calc, array $members, array $docs, ?array $req): array {
    $base = ['type' => 'group', 'request_id' => $req ? (int)$req['id'] : null, 'folder' => $name,
             'start_date' => $trip['start_date'], 'end_date' => $trip['end_date'], 'service_date' => $trip['start_date'],
             'supplier' => FU_GROUP_LABELS[$kind], 'supplier_ref' => ops_grp_code($name) ?: null, 'booked_by' => null];
    $key = 'G|' . dropbox_folder_core_safe($name) . '|' . $trip['start_date'];
    $lines = [];
    $unread = count(array_filter($docs, fn($d) => !$d['read']));

    // Sold. GRP: one sub-folder per confirmed client (the Calc sheets of a collective GRP with no client yet are
    // price sheets). Il Diamante / private groups: the Excel.
    $sold = []; $calcTxt = '';
    if ($calc) {
        $pax = array_sum(array_column($calc['clients'], 'pax'));
        if ($calc['recap']) $calcTxt = ($calc['pax_total'] ?? $pax) . ' pax in RECAP, ' . count($calc['clients']) . ' sheet(s)';
        elseif ($calc['clients']) $calcTxt = $pax . ' pax' . ($calc['rooms'] !== '' ? ', ' . (strlen($calc['rooms']) > 90 ? substr($calc['rooms'], 0, 88) . '…' : $calc['rooms']) : '');
        foreach ($calc['clients'] as $c) $sold[] = $c['sheet'] . ': ' . $c['pax'] . ' pax' . ($c['rooms'] !== '' ? ', ' . $c['rooms'] : '');
    }
    if ($kind === 'grp') $soldTxt = count($members) . ' client folder(s)' . ($calcTxt !== '' ? ' · Calc: ' . $calcTxt : ' · no Calc');
    else $soldTxt = $calcTxt !== '' ? $calcTxt : 'no Excel found';

    // Blocked / to release: supplier documents.
    $blocks = []; $open = []; $tba = false; $exp = null;
    foreach ($docs as $d) {
        if (!empty($d['block_text'])) $blocks[] = ($d['supplier'] ?? $d['file']) . (!empty($d['supplier_ref']) ? ' ' . $d['supplier_ref'] : '') . ': ' . $d['block_text'];
        if (in_array($d['status'] ?? '', ['provisional', 'on_request'], true) || ($d['issue'] ?? null) === 'orangi_rooms_provisional') {
            $open[] = ($d['supplier'] ?? $d['file']) . (!empty($d['supplier_ref']) ? ' ' . $d['supplier_ref'] : '') . ' — ' . ($d['doc_status_text'] ?? $d['status']);
            if (!empty($d['expiry_date']) && ($exp === null || $d['expiry_date'] < $exp)) $exp = $d['expiry_date'];
        }
        if (!empty($d['names_tba'])) $tba = true;
    }
    if ($calc) foreach ($calc['clients'] as $c) if ($c['guests_tba']) $tba = true;

    $summary = 'Sold: ' . $soldTxt
             . ($blocks ? ' · Blocked: ' . $blocks[0] . (count($blocks) > 1 ? ' (+' . (count($blocks) - 1) . ')' : '') : '')
             . ($open ? ' · ' . count($open) . ' supplier doc(s) provisional / on request' : '')
             . ($tba ? ' · names TBA' : '') . ($unread ? ' · ' . $unread . ' PDF(s) not read yet' : '');
    $details = ['kind' => $kind, 'calc' => $calc ? ['file' => $calc['file'], 'recap' => $calc['recap'], 'pax_total' => $calc['pax_total'],
                                                    'rooms' => $calc['rooms'], 'warnings' => $calc['warnings']] : null,
                'sold' => $sold, 'client_folders' => $members, 'blocks' => $blocks, 'provisional' => $open, 'names_tba' => $tba,
                'documents' => count($docs), 'documents_not_read' => $unread];
    $k = followup_key_fit($key . '|finalize');
    $lines[$k] = $base + ['dedupe_key' => $k, 'service' => 'Group to finalize',
        'source_file' => $calc ? $calc['file'] : null, 'issue' => 'group_to_finalize', 'doc_status_text' => substr($summary, 0, 255),
        'expiry_date' => $exp, 'details' => json_encode($details, JSON_UNESCAPED_UNICODE)];

    // Zanzibar flight: seats on the flight invoices vs clients with ARK-ZNZ in their Calc sheet.
    $seats = 0; $flights = []; $limit = null; $flightFile = null;
    foreach ($docs as $d) {
        if (empty($d['flight_znz'])) continue;
        $seats += (int)($d['flight_pax'] ?? 0);
        $flights[] = ($d['supplier'] ?? $d['file']) . ': ' . ($d['flight_pax'] ?? '?') . ' seat(s) — ' . ($d['flight_text'] ?? '') . (!empty($d['status']) && $d['status'] !== 'unknown' ? ' (' . $d['status'] . ')' : '');
        if (!empty($d['ticket_limit']) && ($limit === null || $d['ticket_limit'] < $limit)) $limit = $d['ticket_limit'];
        $flightFile = $flightFile ?? $d['file'];
    }
    $flying = []; $flyPax = 0;
    if ($calc) foreach ($calc['clients'] as $c) if ($c['znz']) { $flying[] = $c['sheet'] . ' ' . $c['pax'] . ' pax (' . $c['znz']['route'] . ')'; $flyPax += $c['pax']; }
    if ($flights || $flying) {
        $diff = $seats - $flyPax;
        $verdict = !$flights ? 'no ZNZ flight invoice in invoices/' : ($diff === 0 ? 'seats match' : ($diff > 0 ? $diff . ' seat(s) to release' : abs($diff) . ' pax without a seat'));
        $txt = 'Seats ' . $seats . ' · clients with ARK-ZNZ in the Calc: ' . $flyPax . ' pax → ' . $verdict;
        $k = followup_key_fit($key . '|znz');
        $lines[$k] = $base + ['dedupe_key' => $k, 'service' => 'ARK-ZNZ flight',
            'source_file' => $flightFile ? 'invoices/' . $flightFile : null, 'issue' => 'group_znz_flight',
            'doc_status_text' => substr($txt, 0, 255), 'expiry_date' => $limit,
            'details' => json_encode(['flights' => $flights, 'clients_flying' => $flying, 'seats' => $seats, 'pax_flying' => $flyPax,
                                      'ticketing_limit' => $limit], JSON_UNESCAPED_UNICODE)];
    }
    return $lines;
}

/** The group's Excel: the *_Calc.xlsx, else (Il Diamante "Panorama01_Diamante_05JAN.xlsx") another workbook of the folder. */
function followup_group_pick_excel(array $files): ?array {
    if ($c = cp_pick_calc($files)) return $c;
    $x = array_values(array_filter($files, fn($f) => preg_match('/\.xlsx$/i', $f['name']) && strpos($f['name'], '~$') !== 0
                                                     && stripos($f['name'], 'conflicted copy') === false));
    usort($x, fn($a, $b) => (stripos($b['name'], 'panorama') !== false) <=> (stripos($a['name'], 'panorama') !== false));
    return $x[0] ?? null;
}

/** dropbox_folder_core() without loading the Dropbox helper first. */
function dropbox_folder_core_safe(string $name): string {
    require_once __DIR__ . '/../../leads/dropbox_helper.php';
    return dropbox_folder_core($name);
}

/**
 * Group lines for every group folder at the top of /001_Safari arriving in the next 60 days.
 * → ['lines', 'groups' => [folder names], 'errors' => [], 'complete' => bool]
 */
function followup_group_candidates(PDO $db, string $today, string $token, bool $canRead, float $deadline, ?int $requestId = null): array {
    $to = date('Y-m-d', strtotime($today . ' +' . FU_GROUP_DAYS . ' days'));
    $folders = [];
    foreach (cp_retry(fn() => dropbox_list_folder_entries($token, CK_BASE)) as $e) {
        $kind = followup_group_kind($e['name']);
        if (!$kind) continue;
        $d = parse_folder_dates($e['name']);
        if (!$d['start_date'] || $d['start_date'] < $today || $d['start_date'] > $to) continue;
        $folders[$e['name']] = ['kind' => $kind, 'trip' => $d];
    }
    // Hub requests of each folder (the first one owns the lines; a collective GRP may have none yet).
    $reqs = [];
    if ($folders) {
        foreach ($db->query("SELECT id, customer_name, practice_code, group_folder, dropbox_url FROM requests
                             WHERE (payment_status IS NULL OR payment_status <> 'Cancelled')")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rel = folder_rel_path($r);
            if (!preg_match('#^001_Safari/(?:00_20\d\d/)?([^/]+)#i', $rel, $m)) continue;
            $reqs[dropbox_folder_stem_safe($m[1])][] = $r;
        }
    }
    $lines = []; $errors = []; $names = [];
    foreach ($folders as $name => $g) {
        $owner = null;
        foreach ($reqs[dropbox_folder_stem_safe($name)] ?? [] as $r) if (!$owner || (int)$r['id'] < (int)$owner['id']) $owner = $r;
        if ($requestId && (!$owner || !in_array($requestId, array_map(fn($r) => (int)$r['id'], $reqs[dropbox_folder_stem_safe($name)] ?? []), true))) continue;
        $dir = CK_BASE . '/' . $name;
        try {
            $calcFile = followup_group_pick_excel(cp_retry(fn() => cp_list_files($token, $dir)));
            $calc = $calcFile ? followup_group_calc($token, $dir, $calcFile) : null;
            $members = [];
            foreach (cp_retry(fn() => dropbox_list_folder_entries($token, $dir)) as $sub) if (folder_is_booking_leaf($sub['name'])) $members[] = $sub['name'];
            $docs = followup_group_docs($db, $token, $dir, $canRead, $deadline, $today, $g['trip']);
            $lines += followup_group_lines($name, $g['kind'], $g['trip'], $calc, $members, $docs, $owner);
            $names[] = $name;
        } catch (Throwable $e) {
            $errors[] = ['folder' => $name, 'error' => $e->getMessage()];
        }
    }
    return ['lines' => $lines, 'groups' => $names, 'errors' => $errors, 'complete' => !$errors && !$requestId];
}

// ── Apply: insert / update / close / re-open ─────────────────────────────────

/** Event row (user NULL = the check). */
function followup_event(PDO $db, int $id, ?int $userId, string $field, $old, $new): void {
    $db->prepare("INSERT INTO booking_followup_events (followup_id, at, user_id, field, old_value, new_value) VALUES (?,?,?,?,?,?)")
       ->execute([$id, date('Y-m-d H:i:s'), $userId, $field, $old === null ? null : (string)$old, $new === null ? null : (string)$new]);
}

/** A line as the summary lists it. */
function followup_brief(array $l): array {
    return array_filter(['id' => isset($l['id']) ? (int)$l['id'] : null, 'request_id' => (int)$l['request_id'], 'folder' => $l['folder'],
                         'supplier' => $l['supplier'] ?? null, 'ref' => $l['supplier_ref'] ?? null, 'service' => $l['service'] ?? null,
                         'service_date' => $l['service_date'] ?? null, 'issue' => $l['issue'], 'file' => $l['source_file'] ?? null,
                         'status' => $l['doc_status_text'] ?? null], fn($v) => $v !== null && $v !== '');
}

/**
 * Write the check's result for one type.
 * $cand  [key => line] found now.
 * $scope 'requests'  request_ids whose documents were all read (a line of theirs not found → auto_not_found)
 *        'confirmed' [key => file] references now confirmed (→ auto_confirmed)
 *        'ok'        request_ids whose tickets are saved (→ auto_ticket_saved)
 *        'only'      request_ids the run was limited to (null = all; every candidate must belong to one)
 * Dry run ($confirm false): the same summary, nothing written.
 */
function followup_apply(PDO $db, string $type, array $cand, array $scope, bool $confirm, string $source, string $today): array {
    followup_schema($db);
    $now = date('Y-m-d H:i:s');
    $sum = ['added' => [], 'updated' => [], 'closed' => [], 'reopened' => [], 'unreadable' => [], 'unchanged' => 0];
    $only = isset($scope['only']) ? array_map('intval', (array)$scope['only']) : null;   // request_ids the run looked at (null = all)
    $st = $db->prepare("SELECT * FROM booking_followups WHERE type = ?" . ($only !== null ? " AND request_id IN (" . implode(',', $only ?: [0]) . ")" : ''));
    $st->execute([$type]);
    $have = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $have[$r['dedupe_key']] = $r;
    $docFields = ['request_id', 'folder', 'start_date', 'end_date', 'service_date', 'supplier', 'supplier_ref', 'service',
                  'source_file', 'issue', 'doc_status_text', 'expiry_date', 'booked_by', 'details'];
    $logged = ['issue', 'doc_status_text', 'expiry_date', 'source_file', 'service_date'];

    foreach ($cand as $key => $l) {
        if ($l['issue'] === 'unreadable') $sum['unreadable'][] = followup_brief($l);
        $old = $have[$key] ?? null;
        if (!$old) {
            $sum['added'][] = followup_brief($l);
            if ($confirm) {
                $cols = array_merge(['type', 'dedupe_key'], $docFields, ['first_seen_at', 'last_seen_at', 'scan_source']);
                $vals = array_merge([$type, $key], array_map(fn($f) => $l[$f] ?? null, $docFields), [$now, $now, $source]);
                $db->prepare("INSERT INTO booking_followups (" . implode(',', $cols) . ") VALUES (" . implode(',', array_fill(0, count($cols), '?')) . ")")
                   ->execute($vals);
                followup_event($db, (int)$db->lastInsertId(), null, 'created', null, ($l['doc_status_text'] ?? '') . ($l['source_file'] ? ' — ' . $l['source_file'] : ''));
            }
            continue;
        }
        $id = (int)$old['id'];
        $diff = [];
        foreach ($docFields as $f) {
            $nv = $l[$f] ?? null; $ov = $old[$f];
            if ((string)$nv !== (string)$ov && !($nv === null && $f !== 'issue' && $ov !== null && in_array($f, ['booked_by', 'supplier_ref'], true))) $diff[$f] = [$ov, $nv];
        }
        // Not re-opened: not relevant (admin), trip ended, and a group the booking team closed (they finalise it).
        $reopen = $old['closed_at'] !== null && $old['staff_status'] !== 'not_relevant' && $old['close_reason'] !== 'auto_trip_ended'
               && !($type === 'group' && $old['close_reason'] === 'manual');
        if ($old['closed_at'] !== null && !$reopen) {                      // stays closed
            if ($confirm) $db->prepare("UPDATE booking_followups SET last_seen_at = ? WHERE id = ?")->execute([$now, $id]);
            $sum['unchanged']++;
            continue;
        }
        if ($confirm) {
            $set = ['last_seen_at = ?']; $args = [$now];
            foreach ($diff as $f => $v) { $set[] = "$f = ?"; $args[] = $v[1]; }
            if ($reopen) {
                $set[] = 'closed_at = NULL'; $set[] = 'closed_by = NULL'; $set[] = 'close_reason = NULL';
                if ($old['staff_status'] === 'resolved') $set[] = "staff_status = 'open'";
            }
            $args[] = $id;
            $db->prepare("UPDATE booking_followups SET " . implode(', ', $set) . " WHERE id = ?")->execute($args);
            foreach ($diff as $f => $v) if (in_array($f, $logged, true)) followup_event($db, $id, null, $f, $v[0], $v[1]);
            if ($reopen) {
                $what = $type === 'ticket' ? 'ticket still missing' : ($type === 'group' ? 'group still to finalize'
                      : 'still ' . strtolower(FU_ISSUE_LABELS[$l['issue']]) . ($l['source_file'] ? ' in ' . $l['source_file'] : ''));
                followup_event($db, $id, null, 'reopened', ($old['close_reason'] ?? '') . ($old['staff_status'] === 'resolved' ? ' / resolved' : ''), $what);
                if ($old['staff_status'] === 'resolved') followup_event($db, $id, null, 'staff_status', 'resolved', 'open');
            }
        }
        if ($reopen)    $sum['reopened'][] = followup_brief(['id' => $id] + $l) + ['was' => $old['close_reason'] . ($old['staff_status'] === 'resolved' ? ' / resolved' : '')];
        elseif ($diff)  $sum['updated'][]  = followup_brief(['id' => $id] + $l) + ['changed' => array_keys($diff)];
        else            $sum['unchanged']++;
    }

    // Open lines not found now.
    $done = array_flip(array_map('intval', $scope['requests'] ?? []));
    foreach ($have as $key => $old) {
        if ($old['closed_at'] !== null || isset($cand[$key])) continue;
        $rid = (int)$old['request_id'];
        $reason = null;
        if (($old['end_date'] ?: $old['start_date']) && ($old['end_date'] ?: $old['start_date']) < $today) $reason = 'auto_trip_ended';
        elseif ($type === 'supplier' && isset($scope['confirmed'][$key])) $reason = 'auto_confirmed';
        elseif ($type === 'ticket' && isset($scope['ok'][$rid])) $reason = 'auto_ticket_saved';
        elseif ($type === 'group' && !empty($scope['complete_type'])) $reason = 'auto_not_found';   // folder renamed / cancelled / moved
        elseif (isset($done[$rid]) && ($type === 'supplier'
                || $old['service_date'] >= $today && $old['service_date'] <= ($scope['to'] ?? '9999-12-31'))) $reason = 'auto_not_found';
        if (!$reason) continue;
        $sum['closed'][] = followup_brief($old) + ['reason' => $reason]
                         + ($reason === 'auto_confirmed' ? ['confirmed_in' => $scope['confirmed'][$key]] : []);
        if ($confirm) {
            $db->prepare("UPDATE booking_followups SET closed_at = ?, closed_by = 'system', close_reason = ? WHERE id = ? AND closed_at IS NULL")
               ->execute([$now, $reason, (int)$old['id']]);
            followup_event($db, (int)$old['id'], null, 'closed', null,
                           $reason . ($reason === 'auto_confirmed' ? ' — ' . $scope['confirmed'][$key] : ''));
        }
    }
    return $sum;
}

/** Remember a run (header line of the page, digest). */
function followup_log_run(PDO $db, string $type, string $source, ?int $userId, array $summary, bool $partial): void {
    followup_schema($db);
    $db->prepare("INSERT INTO booking_followup_runs (at, type, source, user_id, partial, summary_json) VALUES (?,?,?,?,?,?)")
       ->execute([date('Y-m-d H:i:s'), $type, $source, $userId, $partial ? 1 : 0, json_encode($summary, JSON_UNESCAPED_UNICODE)]);
}

/** Last run per type: ['supplier' => {at, source, user, partial}, 'ticket' => …]. */
function followup_last_runs(PDO $db): array {
    followup_schema($db);
    $out = [];
    $rows = $db->query("SELECT r.*, u.full_name, u.username FROM booking_followup_runs r
                        JOIN (SELECT type, MAX(id) AS id FROM booking_followup_runs GROUP BY type) x ON x.id = r.id
                        LEFT JOIN users u ON u.id = r.user_id")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $out[$r['type']] = ['at' => $r['at'], 'source' => $r['source'], 'by' => $r['full_name'] ?: $r['username'] ?: $r['source'],
                            'partial' => (bool)$r['partial']];
    }
    return $out;
}

/**
 * Run the check. $o: type supplier|ticket|all, request_id, confirm (bool), source page|api|cron,
 * user_id, budget (seconds for PDF downloads). Dry run unless confirm.
 * → ['dry_run', 'supplier' => summary + {units, units_complete, reader, partial}, 'ticket' => summary + {window}]
 */
function followup_scan(PDO $db, array $o = []): array {
    require_once __DIR__ . '/../../leads/dropbox_helper.php';
    require_once __DIR__ . '/../../leads/includes/calc_payments.php';
    followup_schema($db);
    $type    = in_array($o['type'] ?? 'all', ['supplier', 'ticket', 'group', 'all'], true) ? ($o['type'] ?? 'all') : 'all';
    $rid     = !empty($o['request_id']) ? (int)$o['request_id'] : null;
    $confirm = !empty($o['confirm']);
    $source  = (string)($o['source'] ?? 'api');
    $today   = (string)($o['today'] ?? date('Y-m-d'));
    $out = ['dry_run' => !$confirm, 'today' => $today];
    @set_time_limit(600);
    $token = null;

    $reader = followup_pdf_reader();
    if ($type === 'supplier' || $type === 'all') {
        // No PDF reader here: listing every invoices/ folder would find nothing new (the office PC scan sends them).
        $units = $reader !== null ? followup_units($db, $rid, $today) : [];
        $token = $token ?? cp_retry(fn() => dropbox_get_access_token());
        $deadline = microtime(true) + (float)($o['budget'] ?? FU_SCAN_BUDGET);
        $cand = []; $confirmed = []; $doneReq = []; $partialReq = []; $errors = []; $complete = 0;
        foreach ($units as $u) {
            $owner = (int)$u['request']['id'];
            try {
                $res = followup_read_unit($db, $u, $token, $deadline, $reader !== null, $today);
            } catch (Throwable $e) {
                $errors[] = ['folder' => $u['folder'], 'error' => $e->getMessage()];
                $partialReq[$owner] = true;
                continue;
            }
            $c = followup_supplier_candidates($u, $res['docs'], $today);
            $cand += $c['lines']; $confirmed += $c['confirmed'];
            if ($res['complete']) { $doneReq[] = $owner; $complete++; }
            else $partialReq[$owner] = true;
        }
        // A request owning several folders (GRP leader: group + own sub-folder) is complete only when all were read.
        $doneReq = array_values(array_filter(array_unique($doneReq), fn($x) => !isset($partialReq[$x])));
        $owners = array_values(array_unique(array_map(fn($u) => (int)$u['request']['id'], $units)));   // a GRP member's run reads its group's lines too
        $sum = followup_apply($db, 'supplier', $cand, ['requests' => $doneReq, 'confirmed' => $confirmed, 'only' => $rid ? $owners : null],
                              $confirm, 'server', $today);
        $partial = $complete < count($units);
        $out['supplier'] = $sum + ['units' => count($units), 'units_complete' => $complete, 'partial' => $partial,
                                   'reader' => $reader ?? 'none', 'errors' => $errors]
                         + ($reader === null ? ['warning' => 'No PDF reader on the server (pdftotext / smalot/pdfparser): only documents already read are used. '
                                                           . 'Supplier documents are read on the office PC: tools\followup_local_scan.bat --confirm.'] : [])
                         + ($partial && $reader !== null ? ['hint' => 'Time budget used up — run the check again to read the remaining documents (each PDF is read once).'] : []);
        if ($confirm && $reader !== null) followup_log_run($db, 'supplier', $source, $o['user_id'] ?? null, followup_counts($out['supplier']), $partial);
    }
    if ($type === 'group' || $type === 'all') {
        $token = $token ?? cp_retry(fn() => dropbox_get_access_token());
        $g = followup_group_candidates($db, $today, $token, $reader !== null, microtime(true) + (float)($o['budget'] ?? FU_SCAN_BUDGET), $rid);
        $owners = array_values(array_unique(array_filter(array_map(fn($l) => $l['request_id'], $g['lines']))));
        $sum = followup_apply($db, 'group', $g['lines'], ['complete_type' => $g['complete'], 'only' => $rid ? ($owners ?: [0]) : null],
                              $confirm, 'server', $today);
        $out['group'] = $sum + ['groups' => $g['groups'], 'window' => [$today, date('Y-m-d', strtotime($today . ' +' . FU_GROUP_DAYS . ' days'))],
                                'errors' => $g['errors']];
        if ($confirm) followup_log_run($db, 'group', $source, $o['user_id'] ?? null, followup_counts($out['group']), !$g['complete'] && !$rid);
    }
    if ($type === 'ticket' || $type === 'all') {
        $t = followup_ticket_candidates($db, $today, $rid);
        $sum = followup_apply($db, 'ticket', $t['lines'], ['requests' => array_keys($t['checked']), 'ok' => $t['ok'], 'to' => $t['to'], 'only' => $rid ? [$rid] : null],
                              $confirm, 'server', $today);
        $out['ticket'] = $sum + ['window' => [$today, $t['to']]];
        if ($confirm) followup_log_run($db, 'ticket', $source, $o['user_id'] ?? null, followup_counts($out['ticket']), false);
    }
    return $out;
}

/** Counts of a scan summary (run log, page message). */
function followup_counts(array $s): array {
    $c = [];
    foreach (['added', 'updated', 'closed', 'reopened', 'unreadable'] as $k) $c[$k] = count($s[$k] ?? []);
    foreach (['units', 'units_complete', 'reader', 'partial'] as $k) if (isset($s[$k])) $c[$k] = $s[$k];
    if (isset($s['groups'])) $c['groups'] = count($s['groups']);
    return $c;
}

// ── Upsert from outside the server (office PC, Cowork) ───────────────────────

/**
 * Supplier documents read elsewhere. $rows[] {folder | request_id, source_file, supplier?, supplier_ref?,
 * service_date?, issue? (or status: confirmed), doc_status_text?, expiry_date?, booked_by?};
 * $scanned = folders / request_ids whose invoices/ were read completely (their lines not sent close
 * as auto_not_found, confirmed rows close their reference). Same rules as the server scan.
 * → summary + {matched, unmatched[]}
 */
function followup_upsert(PDO $db, array $rows, array $scanned, bool $confirm, string $source = 'agent', ?string $today = null): array {
    $today = $today ?? date('Y-m-d');
    followup_schema($db);
    $units = followup_units($db, null, $today);
    $byFolder = []; $byReq = [];
    foreach ($units as $k => $u) {
        $byFolder[dropbox_folder_stem_safe(basename($u['dir']))][] = $k;
        $byFolder[dropbox_folder_stem_safe($u['folder'])][] = $k;
        foreach ($u['request_ids'] as $r) {
            // A member's own folder wins over its group's.
            if (!isset($byReq[$r]) || substr_count($u['dir'], '/') > substr_count($units[$byReq[$r]]['dir'], '/')) $byReq[$r] = $k;
        }
    }
    $find = function ($ref) use ($byFolder, $byReq, $units) {
        if (is_int($ref) || ctype_digit((string)$ref)) return $byReq[(int)$ref] ?? null;
        $rel = preg_replace('#^/?001_Safari/#i', '', trim((string)$ref, '/'));
        $s = dropbox_folder_stem_safe($rel);
        $hit = array_values(array_unique($byFolder[$s] ?? $byFolder[dropbox_folder_stem_safe(basename($rel))] ?? []));
        if (count($hit) <= 1) return $hit[0] ?? null;
        // Same stem twice: a Hub record still points to the folder's old name (status tag) — "GRP_DEPOSIT" next to the
        // group's current "GRP_BALANCE", or a member link built on it. The top-level folder wins, then the exact name.
        usort($hit, fn($a, $b) => [substr_count($units[$a]['folder'], '/'), strcasecmp(basename($units[$a]['folder']), basename($rel)) !== 0]
                               <=> [substr_count($units[$b]['folder'], '/'), strcasecmp(basename($units[$b]['folder']), basename($rel)) !== 0]);
        return $hit[0];
    };
    $docs = []; $unmatched = []; $cached = 0;
    foreach ($rows as $i => $r) {
        $ref = $r['request_id'] ?? $r['folder'] ?? null;
        $k = $ref !== null ? $find($ref) : null;
        // The folder as it is now on disk (the Hub record may keep an old status tag): the 📂 link must open it.
        if ($k !== null && !empty($r['folder'])) {
            $units[$k]['folder'] = trim(preg_replace('#^/?001_Safari/#i', '', (string)$r['folder']), '/');
            $units[$k]['dir'] = CK_BASE . '/' . $units[$k]['folder'];
        }
        // Every document sent is kept for the group check (a collective GRP may have no confirmed request yet).
        if ($confirm && followup_cache_sent_doc($db, $r, $k !== null ? $units[$k]['dir'] : null)) $cached++;
        if ($k === null) {
            $grp = followup_group_kind(basename((string)($r['folder'] ?? '')));
            $unmatched[] = ['row' => $i, 'folder' => $r['folder'] ?? null, 'request_id' => $r['request_id'] ?? null,
                            'error' => $grp ? 'group folder without a confirmed booking — kept for the group check only'
                                            : 'no open confirmed booking with this folder / request'];
            continue;
        }
        $issue = $r['issue'] ?? null;
        if ($issue !== null && $issue !== '' && !in_array($issue, FU_ISSUES, true) || $issue === 'ticket_missing') {
            $unmatched[] = ['row' => $i, 'error' => 'issue must be one of ' . implode(', ', array_diff(FU_ISSUES, ['ticket_missing'])) . ' (or status confirmed)'];
            continue;
        }
        $file = trim((string)($r['source_file'] ?? ''));
        if ($file === '') { $unmatched[] = ['row' => $i, 'error' => 'source_file is required']; continue; }
        if (stripos($file, 'invoices/') !== 0) $file = 'invoices/' . ltrim($file, '/');
        $date = fn($v) => ($v && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$v)) ? (string)$v : null;
        $docs[$k][] = ['status' => $issue ? ($issue === 'on_request' ? 'on_request' : 'provisional') : strtolower((string)($r['status'] ?? 'confirmed')),
                       'issue' => $issue ?: null, 'source_file' => $file,
                       'supplier' => trim((string)($r['supplier'] ?? '')) ?: followup_supplier_from_file($file),
                       'supplier_ref' => trim((string)($r['supplier_ref'] ?? '')) ?: null, 'service_date' => $date($r['service_date'] ?? null),
                       'doc_status_text' => substr(trim((string)($r['doc_status_text'] ?? '')), 0, 255) ?: null,
                       'expiry_date' => $date($r['expiry_date'] ?? null), 'booked_by' => trim((string)($r['booked_by'] ?? '')) ?: null];
    }
    $doneUnits = [];
    foreach ($scanned as $s) if (($k = $find($s)) !== null) { $doneUnits[$k] = true; $docs[$k] = $docs[$k] ?? []; }
    $cand = []; $confirmed = []; $doneReq = []; $partialOwner = [];
    foreach ($docs as $k => $list) {
        $c = followup_supplier_candidates($units[$k], $list, $today);
        $cand += $c['lines']; $confirmed += $c['confirmed'];
        if (isset($doneUnits[$k])) $doneReq[] = (int)$units[$k]['request']['id'];
        else $partialOwner[(int)$units[$k]['request']['id']] = true;   // documents sent from a folder not read completely
    }
    $doneReq = array_values(array_filter(array_unique($doneReq), fn($x) => !isset($partialOwner[$x])));
    // Only the requests sent are in scope: other bookings' lines are left alone.
    $sum = followup_apply_subset($db, $cand, ['requests' => $doneReq, 'confirmed' => $confirmed],
                                 array_unique(array_map(fn($k) => (int)$units[$k]['request']['id'], array_keys($docs))), $confirm, $source, $today);
    return $sum + ['matched' => count($rows) - count($unmatched), 'unmatched' => $unmatched, 'folders' => count($docs), 'documents_kept' => $cached];
}

/** A document read elsewhere, stored like a server reading (rev 'local') so the group check can use it. */
function followup_cache_sent_doc(PDO $db, array $r, ?string $unitDir): bool {
    $file = trim((string)($r['source_file'] ?? ''));
    if ($file === '') return false;
    if (stripos($file, 'invoices/') !== 0) $file = 'invoices/' . ltrim($file, '/');
    $dir = !empty($r['folder']) ? CK_BASE . '/' . trim(preg_replace('#^/?001_Safari/#i', '', (string)$r['folder']), '/') : $unitDir;
    if (!$dir) return false;
    $issue = $r['issue'] ?? null;
    $doc = ['status' => $issue ? ($issue === 'on_request' ? 'on_request' : ($issue === 'orangi_rooms_provisional' ? 'confirmed' : 'provisional'))
                               : strtolower((string)($r['status'] ?? 'unknown')),
            'issue' => $issue ?: null, 'supplier' => $r['supplier'] ?? followup_supplier_from_file($file), 'supplier_ref' => $r['supplier_ref'] ?? null,
            'service_date' => $r['service_date'] ?? null, 'doc_status_text' => $r['doc_status_text'] ?? null, 'expiry_date' => $r['expiry_date'] ?? null,
            'booked_by' => $r['booked_by'] ?? null, 'v' => FU_CLASSIFY_VERSION];
    foreach (['block_text', 'names_tba', 'flight_pax', 'flight_znz', 'flight_text', 'ticket_limit'] as $f) $doc[$f] = $r[$f] ?? null;
    $path = $dir . '/' . $file;
    $db->prepare("REPLACE INTO booking_followup_docs (path_hash, path, rev, request_id, result_json, read_at) VALUES (?,?,?,?,?,?)")
       ->execute([sha1(strtolower($path)), $path, 'local', null, json_encode($doc, JSON_UNESCAPED_UNICODE), date('Y-m-d H:i:s')]);
    return true;
}

/** followup_apply() limited to some requests (an upsert does not see the other bookings). */
function followup_apply_subset(PDO $db, array $cand, array $scope, array $requestIds, bool $confirm, string $source, string $today): array {
    $sum = ['added' => [], 'updated' => [], 'closed' => [], 'reopened' => [], 'unreadable' => [], 'unchanged' => 0];
    foreach ($requestIds as $rid) {
        $c = array_filter($cand, fn($l) => (int)$l['request_id'] === $rid);
        $s = followup_apply($db, 'supplier', $c, $scope + ['only' => [$rid]], $confirm, $source, $today);
        foreach ($sum as $k => $v) $sum[$k] = is_array($v) ? array_merge($v, $s[$k]) : $v + $s[$k];
    }
    return $sum;
}

/** dropbox_folder_stem() without loading the Dropbox helper first. */
function dropbox_folder_stem_safe(string $name): string {
    require_once __DIR__ . '/../../leads/dropbox_helper.php';
    return dropbox_folder_stem($name);
}

// ── Reading the lines ────────────────────────────────────────────────────────

/**
 * '1-URGENT' | '2-HIGH' | '3-NORMAL'. Supplier: service ≤ 30 days, or expiry ≤ 7 days, or already
 * expired with the service ≤ 90 days → urgent; service ≤ 90 → high. Tickets: ≤ 7 days urgent.
 */
function followup_priority(array $l, string $today): string {
    $svc = $l['service_date'] ?: $l['start_date'];
    $days = $svc ? (int)round((strtotime($svc) - strtotime($today)) / 86400) : 999;
    if ($l['type'] === 'ticket') return $days <= 7 ? '1-URGENT' : '2-HIGH';
    $exp = $l['expiry_date'] ? (int)round((strtotime($l['expiry_date']) - strtotime($today)) / 86400) : null;
    if ($days <= 30 || ($exp !== null && $exp >= 0 && $exp <= 7) || ($exp !== null && $exp < 0 && $days <= 90)) return '1-URGENT';
    return $days <= 90 ? '2-HIGH' : '3-NORMAL';
}

/** Dropbox web link of a path under /home. */
function followup_dropbox_url(string $path, ?string $previewFile = null): string {
    $enc = implode('/', array_map('rawurlencode', array_filter(explode('/', trim($path, '/')), 'strlen')));
    return 'https://www.dropbox.com/home/' . $enc . ($previewFile !== null ? '?preview=' . rawurlencode($previewFile) : '');
}

/**
 * Lines with owner / customer / priority. $f: type, status open (default) | closed | all, owner
 * (user id, username or 'none'), priority, request_id (+ its GRP's lines), q, ids.
 */
function followup_rows(PDO $db, array $f = [], ?string $today = null): array {
    followup_schema($db);
    $today = $today ?? date('Y-m-d');
    $w = []; $a = [];
    $status = $f['status'] ?? 'open';
    if ($status === 'open') $w[] = 'b.closed_at IS NULL';
    elseif ($status === 'closed') $w[] = 'b.closed_at IS NOT NULL';
    if (!empty($f['type']) && in_array($f['type'], ['supplier', 'ticket', 'group'], true)) { $w[] = 'b.type = ?'; $a[] = $f['type']; }
    if (!empty($f['ids'])) { $ids = array_map('intval', (array)$f['ids']); $w[] = 'b.id IN (' . implode(',', $ids ?: [0]) . ')'; }
    if (isset($f['owner']) && $f['owner'] !== '') {
        if ($f['owner'] === 'none') $w[] = 'b.owner_user_id IS NULL';
        elseif (ctype_digit((string)$f['owner'])) { $w[] = 'b.owner_user_id = ?'; $a[] = (int)$f['owner']; }
        else { $w[] = 'o.username = ?'; $a[] = (string)$f['owner']; }
    }
    if (!empty($f['request_id'])) {
        $rid = (int)$f['request_id'];
        $gf = $db->prepare("SELECT group_folder FROM requests WHERE id = ?");
        $gf->execute([$rid]);
        $g = trim((string)$gf->fetchColumn());
        if ($g !== '') { $w[] = '(b.request_id = ? OR b.folder = ? OR b.folder LIKE ? OR b.folder LIKE ? OR b.folder LIKE ?)'; array_push($a, $rid, $g, $g . '/%', '%/' . $g, '%/' . $g . '/%'); }
        else { $w[] = 'b.request_id = ?'; $a[] = $rid; }
    }
    if (!empty($f['q'])) {
        $q = '%' . trim((string)$f['q']) . '%';
        $w[] = '(b.folder LIKE ? OR b.supplier LIKE ? OR b.supplier_ref LIKE ? OR b.service LIKE ? OR r.customer_name LIKE ? OR b.notes LIKE ?)';
        array_push($a, $q, $q, $q, $q, $q, $q);
    }
    $sql = "SELECT b.*, r.customer_name, r.dropbox_url, o.username AS owner_username, o.full_name AS owner_name,
                   cu.full_name AS closed_by_name,
                   (SELECT COUNT(*) FROM booking_followup_events e WHERE e.followup_id = b.id) AS events_count
            FROM booking_followups b
            LEFT JOIN requests r ON r.id = b.request_id
            LEFT JOIN users o ON o.id = b.owner_user_id
            LEFT JOIN users cu ON b.closed_by REGEXP '^[0-9]+$' AND cu.id = b.closed_by"
         . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . " ORDER BY b.closed_at IS NULL DESC, b.service_date ASC, b.id ASC";
    $st = $db->prepare($sql);
    $st->execute($a);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $r = followup_out($r, $today);
        if (!empty($f['priority']) && $r['priority'] !== $f['priority'] && substr($r['priority'], 0, 1) !== (string)$f['priority']) continue;
        $out[] = $r;
    }
    usort($out, 'followup_sort');
    return $out;
}

/** Public shape of a line (page JSON and Agent API). */
function followup_out(array $r, string $today): array {
    $svc = $r['service_date'] ?: $r['start_date'];
    $dir = '/001_Safari/' . $r['folder'];
    return [
        'id' => (int)$r['id'], 'type' => $r['type'], 'request_id' => $r['request_id'] !== null ? (int)$r['request_id'] : null,
        'customer' => $r['customer_name'] ?? null, 'folder' => $r['folder'], 'folder_name' => basename((string)$r['folder']),
        'folder_url' => $r['folder'] ? followup_dropbox_url($dir) : null,
        // Same as the Hub's "📂 Open": Windows Explorer on the office PCs (tools/savannah-open).
        'folder_open_url' => $r['folder'] ? 'savannah://open?path=' . implode('/', array_map('rawurlencode', explode('/', '001_Safari/' . $r['folder']))) : null,
        'file_url' => $r['source_file'] ? followup_dropbox_url($dir . (dirname($r['source_file']) !== '.' ? '/' . dirname($r['source_file']) : ''),
                                                                basename($r['source_file'])) : null,
        'start_date' => $r['start_date'], 'end_date' => $r['end_date'], 'service_date' => $r['service_date'],
        'days_left' => $svc ? (int)round((strtotime($svc) - strtotime($today)) / 86400) : null,
        'supplier' => $r['supplier'], 'supplier_ref' => $r['supplier_ref'], 'service' => $r['service'],
        'source_file' => $r['source_file'], 'issue' => $r['issue'], 'issue_label' => FU_ISSUE_LABELS[$r['issue']] ?? $r['issue'],
        'doc_status_text' => $r['doc_status_text'], 'expiry_date' => $r['expiry_date'],
        'expired' => $r['expiry_date'] !== null && $r['expiry_date'] < $today,
        'booked_by' => $r['booked_by'],
        'owner_user_id' => $r['owner_user_id'] !== null ? (int)$r['owner_user_id'] : null,
        'owner' => $r['owner_username'] ?? null, 'owner_name' => ($r['owner_name'] ?? null) ?: ($r['owner_username'] ?? null),
        'contacted_at' => $r['contacted_at'], 'staff_status' => $r['staff_status'], 'notes' => $r['notes'],
        'priority' => followup_priority($r, $today),
        'first_seen_at' => $r['first_seen_at'], 'last_seen_at' => $r['last_seen_at'],
        'closed_at' => $r['closed_at'], 'closed_by' => $r['closed_by'] === 'system' ? 'system' : (($r['closed_by_name'] ?? null) ?: $r['closed_by']),
        'close_reason' => $r['close_reason'], 'close_reason_label' => $r['close_reason'] ? FU_REASON_LABELS[$r['close_reason']] : null,
        'events_count' => (int)($r['events_count'] ?? 0), 'source' => $r['scan_source'],
        'details' => !empty($r['details']) ? json_decode((string)$r['details'], true) : null,
    ];
}

/** Not taken in charge first, then problems, priority, service date. */
function followup_sort(array $a, array $b): int {
    return [$a['closed_at'] !== null, $a['owner_user_id'] !== null, $a['staff_status'] !== 'problem', $a['priority'], $a['service_date'] ?? $a['start_date'] ?? '9999', $a['id']]
       <=> [$b['closed_at'] !== null, $b['owner_user_id'] !== null, $b['staff_status'] !== 'problem', $b['priority'], $b['service_date'] ?? $b['start_date'] ?? '9999', $b['id']];
}

/** The history of a line, newest first. */
function followup_events(PDO $db, int $id): array {
    followup_schema($db);
    $st = $db->prepare("SELECT e.at, e.field, e.old_value, e.new_value, e.user_id, u.full_name, u.username
                        FROM booking_followup_events e LEFT JOIN users u ON u.id = e.user_id
                        WHERE e.followup_id = ? ORDER BY e.id DESC");
    $st->execute([$id]);
    $ev = $st->fetchAll(PDO::FETCH_ASSOC);
    $names = [];
    foreach ($db->query("SELECT id, full_name, username FROM users")->fetchAll(PDO::FETCH_ASSOC) as $u) $names[(int)$u['id']] = $u['full_name'] ?: $u['username'];
    $nm = fn($v) => $v === null || $v === '' ? null : ($names[(int)$v] ?? $v);
    return array_map(fn($e) => ['at' => $e['at'], 'by' => $e['user_id'] === null ? 'check' : ($e['full_name'] ?: $e['username']),
                                'field' => $e['field'],
                                'old' => $e['field'] === 'owner' ? $nm($e['old_value']) : $e['old_value'],
                                'new' => $e['field'] === 'owner' ? $nm($e['new_value']) : $e['new_value']], $ev);
}

/** Stat cards of the page. */
function followup_stats(array $open, array $closedRecent): array {
    $s = ['untaken' => 0, 'urgent' => 0, 'problem' => 0, 'expiring' => 0, 'tickets' => 0, 'groups' => 0, 'closed7' => count($closedRecent)];
    foreach ($open as $r) {
        if ($r['owner_user_id'] === null) $s['untaken']++;
        if ($r['priority'] === '1-URGENT') $s['urgent']++;
        if ($r['staff_status'] === 'problem') $s['problem']++;
        if ($r['expiry_date'] && !$r['expired'] && (strtotime($r['expiry_date']) - time()) / 86400 <= 7) $s['expiring']++;
        if ($r['issue'] === 'ticket_missing') $s['tickets']++;
        if ($r['issue'] === 'group_to_finalize') $s['groups']++;
    }
    return $s;
}

/** Everything the page renders: open lines, closed ones (last 120 days), stats, runs, rights, staff. */
function followup_page_data(PDO $db, array $user): array {
    $open   = followup_rows($db, ['status' => 'open']);
    $closed = array_values(array_filter(followup_rows($db, ['status' => 'closed']),
                                        fn($r) => $r['closed_at'] >= date('Y-m-d H:i:s', time() - 120 * 86400)));
    $recent = array_filter($closed, fn($r) => $r['closed_at'] >= date('Y-m-d H:i:s', time() - 7 * 86400));
    $users = $db->query("SELECT id, username, full_name FROM users WHERE is_active = 1 ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);
    return ['rows' => $open, 'closed' => $closed, 'stats' => followup_stats($open, $recent), 'runs' => followup_last_runs($db),
            'me' => ['id' => (int)$user['id'], 'can_edit' => followup_can_edit($user), 'is_admin' => followup_is_admin($user)],
            'users' => array_map(fn($u) => ['id' => (int)$u['id'], 'name' => $u['full_name'] ?: $u['username']], $users),
            'today' => date('Y-m-d')];
}

// ── Staff changes ────────────────────────────────────────────────────────────

/**
 * Change a line as a Hub user. $ch: take (bool), owner (user id | username | null — admin),
 * contacted_at (Y-m-d | ''), staff_status, notes. $user: current_user() shape (id, role_name, permissions).
 * resolved / not_relevant close the line (manual); another status on a closed line re-opens it.
 * → ['ok', 'changes' => [field => [old, new]], 'row'] or ['ok' => false, 'error']. Dry run: nothing written.
 */
function followup_update(PDO $db, int $id, array $ch, array $user, bool $confirm = true): array {
    followup_schema($db);
    $st = $db->prepare("SELECT * FROM booking_followups WHERE id = ?");
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return ['ok' => false, 'error' => 'Line ' . $id . ' not found', 'code' => 404];
    if (!followup_can_edit($user)) return ['ok' => false, 'error' => 'You can only view this page (module ' . FU_EDIT_PERMISSION . ')', 'code' => 403];
    $admin = followup_is_admin($user);
    $uid = (int)$user['id'];
    $new = [];

    if (!empty($ch['take'])) {
        if ($row['owner_user_id'] !== null && (int)$row['owner_user_id'] !== $uid && !$admin) {
            return ['ok' => false, 'error' => 'Already taken in charge by someone else', 'code' => 409];
        }
        $new['owner_user_id'] = $uid;
        if (in_array($row['staff_status'], ['open'], true)) $new['staff_status'] = 'in_progress';
    }
    if (array_key_exists('owner', $ch)) {
        $o = $ch['owner'];
        $oid = null;
        if ($o !== null && $o !== '' && $o !== 'none') {
            $q = $db->prepare(ctype_digit((string)$o) ? "SELECT id FROM users WHERE id = ? AND is_active = 1" : "SELECT id FROM users WHERE username = ? AND is_active = 1");
            $q->execute([$o]);
            $oid = $q->fetchColumn();
            if (!$oid) return ['ok' => false, 'error' => 'User "' . $o . '" not found', 'code' => 400];
            $oid = (int)$oid;
        }
        $self = $oid === $uid && $row['owner_user_id'] === null || $oid === null && (int)$row['owner_user_id'] === $uid;
        if (!$admin && !$self && $oid !== ($row['owner_user_id'] !== null ? (int)$row['owner_user_id'] : null)) {
            return ['ok' => false, 'error' => 'Only an admin can reassign a line', 'code' => 403];
        }
        $new['owner_user_id'] = $oid;
    }
    if (array_key_exists('contacted_at', $ch)) {
        $d = trim((string)$ch['contacted_at']);
        if ($d !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) return ['ok' => false, 'error' => 'contacted_at must be YYYY-MM-DD', 'code' => 400];
        $new['contacted_at'] = $d ?: null;
    }
    if (array_key_exists('notes', $ch)) $new['notes'] = trim((string)$ch['notes']) ?: null;
    if (array_key_exists('staff_status', $ch)) {
        $s = (string)$ch['staff_status'];
        if (!in_array($s, FU_STATUSES, true)) return ['ok' => false, 'error' => 'staff_status must be one of ' . implode(', ', FU_STATUSES), 'code' => 400];
        if ($s === 'not_relevant' && !$admin) return ['ok' => false, 'error' => 'Only an admin can close a line as not relevant', 'code' => 403];
        $new['staff_status'] = $s;
    }
    $finalStatus = $new['staff_status'] ?? $row['staff_status'];
    $finalNotes  = array_key_exists('notes', $new) ? $new['notes'] : $row['notes'];
    if ($finalStatus === 'problem' && $row['staff_status'] !== 'problem' && trim((string)$finalNotes) === '') {
        return ['ok' => false, 'error' => 'A note is required to mark a line as Problem', 'code' => 400];
    }

    $changes = [];
    foreach ($new as $f => $v) if ((string)$v !== (string)$row[$f]) $changes[$f] = [$row[$f], $v];
    $close = isset($changes['staff_status']) && in_array($finalStatus, ['resolved', 'not_relevant'], true) && $row['closed_at'] === null;
    // A status that is not a closing one, on a closed line (auto-closed lines keep their status): re-open.
    $reopen = array_key_exists('staff_status', $ch) && !in_array($finalStatus, ['resolved', 'not_relevant'], true) && $row['closed_at'] !== null;
    if (!$confirm || (!$changes && !$reopen)) {
        return ['ok' => true, 'dry_run' => !$confirm, 'changes' => $changes, 'would_close' => $close, 'would_reopen' => $reopen,
                'row' => followup_rows($db, ['ids' => [$id], 'status' => 'all'])[0] ?? null];
    }
    $set = []; $args = [];
    foreach ($changes as $f => $v) { $set[] = "$f = ?"; $args[] = $v[1]; }
    if ($close)  { $set[] = 'closed_at = ?'; $args[] = date('Y-m-d H:i:s'); $set[] = 'closed_by = ?'; $args[] = (string)$uid; $set[] = "close_reason = 'manual'"; }
    if ($reopen) { $set[] = 'closed_at = NULL'; $set[] = 'closed_by = NULL'; $set[] = 'close_reason = NULL'; }
    $args[] = $id;
    $db->prepare("UPDATE booking_followups SET " . implode(', ', $set) . " WHERE id = ?")->execute($args);
    foreach ($changes as $f => $v) followup_event($db, $id, $uid, $f === 'owner_user_id' ? 'owner' : $f, $v[0], $v[1]);
    if ($close)  followup_event($db, $id, $uid, 'closed', null, 'manual — ' . $finalStatus);
    if ($reopen) followup_event($db, $id, $uid, 'reopened', $row['close_reason'], $finalStatus);
    return ['ok' => true, 'dry_run' => false, 'changes' => $changes, 'closed' => $close, 'reopened' => $reopen,
            'row' => followup_rows($db, ['ids' => [$id], 'status' => 'all'])[0] ?? null];
}

// ── Memo digest ──────────────────────────────────────────────────────────────

/**
 * What Roberto's morning digest lists: new lines since $since, problems, lines nobody took in
 * charge for > 2 days, provisional expiring ≤ 7 days, tickets missing ≤ 7 days.
 */
function followup_digest_data(PDO $db, ?string $since, ?string $today = null): array {
    $today = $today ?? date('Y-m-d');
    $open = followup_rows($db, ['status' => 'open'], $today);
    $d = ['new' => [], 'problem' => [], 'untaken' => [], 'expiring' => [], 'tickets' => []];
    $in7 = date('Y-m-d', strtotime($today . ' +7 days'));
    $old = date('Y-m-d H:i:s', time() - 2 * 86400);
    foreach ($open as $r) {
        if ($since && $r['first_seen_at'] > $since) $d['new'][] = $r;
        if ($r['staff_status'] === 'problem') $d['problem'][] = $r;
        if ($r['owner_user_id'] === null && $r['first_seen_at'] < $old) $d['untaken'][] = $r;
        if ($r['type'] === 'supplier' && $r['expiry_date'] && $r['expiry_date'] >= $today && $r['expiry_date'] <= $in7) $d['expiring'][] = $r;
        if ($r['issue'] === 'ticket_missing' && $r['service_date'] && $r['service_date'] <= $in7) $d['tickets'][] = $r;
    }
    return $d;
}
