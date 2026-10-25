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
 *   type group     group folders 60 days before arrival (to finalise, ARK-ZNZ seats)
 *   type ck        findings of the booking check before the CK (Cowork, booking-check skill, sent with
 *                  the Agent API ck_findings_upsert), routed by audience: booking → the CK folder's
 *                  booking owner, sales → its CK owner, accounts → Roberto (ck_lib.php owners)
 *   type safaricheck  SafariCheck points about bookings (ck_sc_booking_points(): stays, transfers, flights, dates,
 *                  pax), written when the runner posts a result; ticking the point in SafariCheck closes the line
 *   type manual    added by staff from the page (a problem found by phone …); closed by staff only
 *
 * One list (migration 074): a line found by several checks is ONE line. `sources` keeps every check still
 * reporting it ({source: {key, issue, text, since}}); a new finding about the same service (followup_service_match():
 * request, service type, date, supplier, or the supplier ref) is added to the open line as a source instead of a new
 * line. A source that no longer reports it leaves `sources`; the line closes when none is left. A confirmed supplier
 * document clears every "provisional" source of that service. `due_date` = the nearest deadline (followup_due()),
 * priority comes from it.
 *
 * Owners: any active Hub user; on insert (every source) the default owner — booking → the CK folder's booking owner,
 * sales → its CK owner, accounts → Roberto, no booking owner → nobody; never reassigned automatically afterwards.
 * The booking team (module booking_followup_edit) works every line;
 * the current owner of a line works it too (a seller owning a sales line, without the module).
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

const FU_CLASSIFY_VERSION = 3;          // bump when followup_classify() reads differently: cached documents are read again (3: payment_days)
const FU_TICKET_DAYS      = 14;         // ticket scan: flights in the next N days
const FU_SCAN_BUDGET      = 240;        // seconds a supplier scan may spend downloading PDFs (the rest: next run)
const FU_GROUP_DAYS       = 60;         // group check: arrivals in the next N days
const FU_ISSUES  = ['provisional', 'provisional_expired', 'on_request', 'orangi_rooms_provisional', 'ticket_missing', 'unreadable',
                    'group_to_finalize', 'group_znz_flight'];
const FU_STATUSES = ['open', 'in_progress', 'problem', 'resolved', 'not_relevant'];
const FU_REASONS = ['auto_confirmed', 'auto_ticket_saved', 'auto_trip_ended', 'auto_not_found', 'auto_resolved', 'manual'];
const FU_TYPES   = ['supplier', 'ticket', 'group', 'ck', 'safaricheck', 'manual'];
/** The check behind each line type, as `sources` names it. */
const FU_TYPE_SOURCE = ['supplier' => 'supplier_scan', 'ticket' => 'ticket_scan', 'group' => 'group_scan', 'ck' => 'claude_check',
                        'safaricheck' => 'safaricheck', 'manual' => 'manual'];
const FU_SOURCE_LABELS = ['supplier_scan' => 'Supplier scan', 'ticket_scan' => 'Ticket scan', 'group_scan' => 'Group check',
                          'safaricheck' => 'SafariCheck', 'claude_check' => 'Claude check', 'manual' => 'Manual'];
/** Issues meaning "a booking is still provisional": they block the _CK (ck_pipeline_block()) and close on a confirmed document. */
const FU_PROVISIONAL_ISSUES = ['provisional', 'provisional_expired', 'on_request', 'orangi_rooms_provisional', 'booking_provisional'];
const FU_SERVICE_TYPES = ['stay', 'flight', 'transfer', 'activity', 'payment', 'invoice', 'client_info', 'dates', 'group', 'other'];
const FU_DUE_URGENT = 7;                // priority: due overdue or ≤ 7 days → 1-URGENT, ≤ 30 → 2-HIGH, later → 3-NORMAL
const FU_DUE_HIGH   = 30;
const FU_PAY_DAYS   = 30;               // supplier payment: days before check-in (Orangi Collection always; others unless the document says)
const FU_GROUP_RELEASE_DAYS = 60;       // group blocks released / finalised 60 days before arrival
const FU_TICKET_DUE_DAYS = 7;           // domestic ticket issued 7 days before the flight (no ticketing limit on the pro-forma)
const FU_SERVICE_DUE_DAYS = 14;         // anything else open: 14 days before the service
const FU_AUDIENCES = ['booking', 'sales', 'accounts'];   // ck lines; NULL (supplier / ticket / group) = booking
const FU_AUDIENCE_LABELS = ['booking' => 'Booking', 'sales' => 'Sales', 'accounts' => 'Accounts'];
/** ck issue codes the booking check uses (free VARCHAR(40): other codes are kept as sent). */
const FU_CK_ISSUE_LABELS = ['transfer_missing' => 'Transfer missing', 'booking_provisional' => 'Booking provisional',
                            'flight_unpaid' => 'Flight not paid / ticketed', 'client_flights_missing' => 'Client flights missing',
                            'invoice_mismatch' => 'Invoice mismatch', 'payment_mismatch' => 'Payment mismatch', 'other' => 'Other'];
const FU_ACCOUNTS_OWNER = 'rdesibi';     // accounts findings (client invoices / payments): Roberto
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
                          'auto_resolved' => 'no longer reported by the check', 'manual' => 'closed by staff'];
const FU_EDIT_PERMISSION = 'booking_followup_edit';

// ── Schema (same DDL as migrations 069, 070, 073, 074) ───────────────────────

function followup_schema(PDO $db): void {
    static $done = false;
    if ($done) return;
    $db->exec("CREATE TABLE IF NOT EXISTS booking_followups (
        id              INT AUTO_INCREMENT PRIMARY KEY,
        type            ENUM('supplier','ticket','group','ck','safaricheck','manual') NOT NULL,
        audience        ENUM('booking','sales','accounts') NULL,
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
        issue           VARCHAR(40) NOT NULL,
        doc_status_text VARCHAR(500) NULL,
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
        close_reason    ENUM('auto_confirmed','auto_ticket_saved','auto_trip_ended','auto_not_found','auto_resolved','manual') NULL,
        scan_source     VARCHAR(10) NULL,
        details         TEXT NULL,
        sources         TEXT NULL,
        service_key     VARCHAR(190) NULL,
        due_date        DATE NULL,
        due_reason      VARCHAR(80) NULL,
        UNIQUE KEY uq_followup_key (dedupe_key),
        KEY idx_followup_request (request_id),
        KEY idx_followup_closed (closed_at),
        KEY idx_followup_owner (owner_user_id),
        KEY idx_followup_service (service_key),
        KEY idx_followup_due (due_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    followup_schema_upgrade($db);
    $db->exec("CREATE TABLE IF NOT EXISTS booking_followup_meta (
        k VARCHAR(40) NOT NULL PRIMARY KEY,
        v VARCHAR(255) NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
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
 * Tables of an older version: group type + `details` (migration 070), ck type, free issue code, audience,
 * auto_resolved (migration 073), safaricheck / manual types, sources, service_key, due date (migration 074).
 * Checked once in INFORMATION_SCHEMA; each ALTER may fail harmlessly.
 */
function followup_schema_upgrade(PDO $db): void {
    try {
        $st = $db->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'booking_followups' AND COLUMN_NAME IN ('details', 'audience', 'sources')");
        $have = $st->fetchAll(PDO::FETCH_COLUMN);
        if (count($have) === 3) return;
    } catch (Throwable $e) {
        return;   // no INFORMATION_SCHEMA (tests)
    }
    $sql = ["ALTER TABLE booking_followups MODIFY type ENUM('supplier','ticket','group','ck','safaricheck','manual') NOT NULL"];
    if (!in_array('audience', $have, true)) {
        array_push($sql, "ALTER TABLE booking_followups MODIFY issue VARCHAR(40) NOT NULL",
                   "ALTER TABLE booking_followups MODIFY doc_status_text VARCHAR(500) NULL",
                   "ALTER TABLE booking_followups MODIFY close_reason ENUM('auto_confirmed','auto_ticket_saved','auto_trip_ended','auto_not_found','auto_resolved','manual') NULL",
                   "ALTER TABLE booking_followups ADD COLUMN audience ENUM('booking','sales','accounts') NULL AFTER type");
    }
    if (!in_array('details', $have, true)) $sql[] = "ALTER TABLE booking_followups ADD COLUMN details TEXT NULL";
    if (!in_array('sources', $have, true)) {
        $sql[] = "ALTER TABLE booking_followups ADD COLUMN sources TEXT NULL, ADD COLUMN service_key VARCHAR(190) NULL,
                  ADD COLUMN due_date DATE NULL, ADD COLUMN due_reason VARCHAR(80) NULL,
                  ADD KEY idx_followup_service (service_key), ADD KEY idx_followup_due (due_date)";
    }
    foreach ($sql as $q) {
        try { $db->exec($q); } catch (Throwable $e) { error_log('followup_schema_upgrade: ' . $e->getMessage()); }
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

/** May work this line (take in charge, contacted, status, notes): the booking team, or its current owner. */
function followup_can_work(array $user, array $row): bool {
    return followup_can_edit($user) || ($row['owner_user_id'] !== null && (int)$row['owner_user_id'] === (int)($user['id'] ?? 0));
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

/**
 * dedupe_key of a supplier document: request + reference, else request + supplier + service date.
 * $requestId: the owner request, or "F:<folder stem>" for a folder without a confirmed booking.
 */
function followup_supplier_key($requestId, array $d): string {
    if (($d['issue'] ?? null) === 'unreadable') return followup_key_fit('S|' . $requestId . '|file|' . followup_norm(basename((string)$d['source_file'])));
    if (!empty($d['supplier_ref'])) return followup_key_fit('S|' . $requestId . '|' . strtoupper(preg_replace('/\s+/', '', $d['supplier_ref'])));
    return followup_key_fit('S|' . $requestId . '|' . followup_norm((string)$d['supplier']) . '|' . ($d['service_date'] ?? ''));
}

/**
 * Folder as the lines store it: path under /001_Safari ("10_27OCT_…/member"), or an absolute Dropbox
 * path for a folder elsewhere ("/itineraries/…/Diamante/2027-Groups/01_19JAN_Panorama02_…").
 */
function followup_folder_norm(string $f): string {
    $f = trim($f);
    if ($f !== '' && $f[0] === '/' && !preg_match('#^/001_Safari(/|$)#i', $f)) return '/' . trim($f, '/');
    return trim(preg_replace('#^/?001_Safari/#i', '', $f), '/');
}

/** Dropbox path of a stored folder. */
function followup_folder_dir(string $folder): string {
    return $folder !== '' && $folder[0] === '/' ? $folder : CK_BASE . '/' . $folder;
}

/** Key scope of a folder without a confirmed booking: "F:" + its stem (status tags off, so a rename keeps the lines). */
function followup_folder_scope(string $folder): string {
    return 'F:' . dropbox_folder_stem_safe(basename($folder));
}

/** Keys fit the 190-character unique index. */
function followup_key_fit(string $k): string {
    return strlen($k) <= 190 ? $k : substr($k, 0, 149) . '#' . sha1($k);
}

/**
 * Lines for one unit from its classified documents.
 * A reference with a clean Confirmed document is confirmed (its provisional / on-request
 * lines close); a voucher note "rooms still provisional" stays until the voucher no longer says it.
 * → ['lines' => [key => line], 'confirmed' => [key => source_file], 'confirmed_docs' => [key => line shape of the
 *    confirmed document] (followup_clear_confirmed(): clears the "provisional" sources of the same service)]
 */
function followup_supplier_candidates(array $u, array $docs, string $today): array {
    $rid = !empty($u['request']['id']) ? (int)$u['request']['id'] : null;   // null: folder without a confirmed booking
    $lines = []; $confirmed = []; $confirmedDocs = [];
    foreach ($docs as $d) {
        if (in_array($d['issue'] ?? null, ['provisional', 'provisional_expired'], true)) {   // expired is relative to today, not to when the PDF was read
            $d['issue'] = (!empty($d['expiry_date']) && $d['expiry_date'] < $today) ? 'provisional_expired' : 'provisional';
        }
        $key = followup_supplier_key($rid ?? followup_folder_scope($u['folder']), $d);
        // Terms read in the document: supplier payment days, ticketing time limit (due date, followup_due()).
        $terms = array_filter(['payment_days' => !empty($d['payment_days']) ? (int)$d['payment_days'] : null,
                               'ticket_limit' => $d['ticket_limit'] ?? null]);
        $line = ['type' => 'supplier', 'dedupe_key' => $key, 'request_id' => $rid, 'folder' => $u['folder'],
                 'start_date' => $u['start_date'], 'end_date' => $u['end_date'],
                 'service_date' => $d['service_date'] ?? null, 'supplier' => $d['supplier'] ?? null,
                 'supplier_ref' => $d['supplier_ref'] ?? null, 'service' => null, 'source_file' => $d['source_file'],
                 'issue' => $d['issue'] ?? null, 'doc_status_text' => $d['doc_status_text'] ?? null,
                 'expiry_date' => $d['expiry_date'] ?? null, 'booked_by' => $d['booked_by'] ?? null,
                 'details' => $terms ? json_encode($terms, JSON_UNESCAPED_UNICODE) : null];
        if (($d['status'] ?? '') === 'confirmed' && empty($d['issue'])) { $confirmed[$key] = $d['source_file']; $confirmedDocs[$key] = $line; continue; }
        if (empty($d['issue'])) continue;                                          // no status printed (receipts, our own invoices …)
        if (!isset($lines[$key]) || FU_ISSUE_RANK[$line['issue']] < FU_ISSUE_RANK[$lines[$key]['issue']]) {
            if (isset($lines[$key])) $line['booked_by'] = $line['booked_by'] ?? $lines[$key]['booked_by'];
            $lines[$key] = $line;
        }
    }
    foreach ($lines as $k => $l) {
        if (isset($confirmed[$k]) && $l['issue'] !== 'orangi_rooms_provisional' && $l['issue'] !== 'unreadable') unset($lines[$k]);
    }
    foreach ($confirmedDocs as $k => $l) if (isset($lines[$k])) unset($confirmedDocs[$k]);   // same reference still provisional elsewhere
    return ['lines' => $lines, 'confirmed' => $confirmed, 'confirmed_docs' => $confirmedDocs];
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

// ── One list: sources, same service, deadlines, default owners ───────────────

/**
 * The checks still reporting a line: [source => {key, issue, text, since}] (source: FU_TYPE_SOURCE values).
 * NULL (lines written before migration 074) = the line's own check, with its dedupe_key.
 */
function followup_sources(array $row): array {
    $s = isset($row['sources']) && $row['sources'] !== null && $row['sources'] !== '' ? json_decode((string)$row['sources'], true) : null;
    if (is_array($s)) return $s;
    return [FU_TYPE_SOURCE[$row['type']] ?? (string)$row['type'] => ['key' => $row['dedupe_key'], 'issue' => $row['issue'],
            'text' => isset($row['doc_status_text']) ? mb_substr((string)$row['doc_status_text'], 0, 300) : null,
            'since' => $row['first_seen_at'] ?? null]];
}

function followup_sources_json(array $s): string {
    return $s ? json_encode($s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '{}';
}

/** What a check reports about a line, as `sources` keeps it. */
function followup_source_entry(array $l, string $key, string $since): array {
    return ['key' => $key, 'issue' => (string)$l['issue'],
            'text' => isset($l['doc_status_text']) && $l['doc_status_text'] !== null ? mb_substr((string)$l['doc_status_text'], 0, 300) : null,
            'since' => $since];
}

/** Airline in a supplier / service text ("Auric Air", "Coastal Aviation", "flight"…). */
function followup_is_airline(string $s): bool {
    return followup_airline($s) !== '' || (bool)preg_match('/\b(?:air(?:lines?|link|ways)?|aviation|flights?)\b/i', $s);
}

/**
 * Service type of a line or finding: stay | flight | transfer | activity | payment | invoice | client_info | dates | group | other.
 * An explicit service_type (ck findings, SafariCheck points) wins, then the stored service_key, then the line itself.
 */
function followup_service_type(array $l): string {
    if (!empty($l['service_type']) && in_array($l['service_type'], FU_SERVICE_TYPES, true)) return $l['service_type'];
    if (!empty($l['service_key'])) {
        $p = explode('|', (string)$l['service_key']);
        if (isset($p[1]) && in_array($p[1], FU_SERVICE_TYPES, true)) return $p[1];
    }
    $txt = trim(($l['supplier'] ?? '') . ' ' . ($l['service'] ?? ''));
    switch ($l['type'] ?? '') {
        case 'ticket': return 'flight';
        case 'group':  return 'group';
        case 'supplier': return followup_is_airline($txt . ' ' . ($l['source_file'] ?? '')) ? 'flight' : 'stay';
    }
    $byIssue = ['transfer_missing' => 'transfer', 'flight_unpaid' => 'flight', 'client_flights_missing' => 'client_info',
                'invoice_mismatch' => 'invoice', 'payment_mismatch' => 'payment'];
    if (isset($byIssue[$l['issue'] ?? ''])) return $byIssue[$l['issue']];
    // SafariCheck points (ck_sc_booking_points()): transfer / flight / dates, every other one is about a stay.
    if (strpos((string)($l['issue'] ?? ''), 'sc_') === 0) {
        return ['sc_transfer' => 'transfer', 'sc_flight' => 'flight', 'sc_dates' => 'dates'][$l['issue']] ?? 'stay';
    }
    if (preg_match('/\btransfers?\b|pick[\s-]?up|drop[\s-]?off/i', $txt)) return 'transfer';
    if (($l['issue'] ?? '') === 'booking_provisional') return followup_is_airline($txt) ? 'flight' : 'stay';
    if (followup_is_airline($txt)) return 'flight';
    if (preg_match('/\b(?:lodge|camp|hotel|resort|tented|nights?|stay|room|check[\s-]?in)\b/i', $txt)) return 'stay';
    if (preg_match('/\b(?:balloon|walking|game drive|excursion|tour|visit|activity|park fees?)\b/i', $txt)) return 'activity';
    return 'other';
}

/** Name words of a supplier, without the generic ones ("Serengeti Classic View Camp" → serengeti, classic, view). */
function followup_supplier_tokens(string $s, bool $keepGeneric = false): array {
    static $generic = ['the', 'and', 'camp', 'camps', 'lodge', 'lodges', 'tented', 'tent', 'tents', 'hotel', 'hotels', 'resort', 'resorts',
                       'safari', 'safaris', 'ltd', 'limited', 'co', 'by', 'luxury', 'retreat', 'house', 'villa', 'villas', 'boutique',
                       'mobile', 'collection', 'inv', 'invoice', 'proforma', 'pro', 'forma', 'confirmation', 'voucher', 'booking', 'pdf',
                       'de', 'la', 'le', 'il', 'at'];
    $a = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
    $toks = preg_split('/[^a-z0-9]+/', strtolower($a !== false ? $a : $s), -1, PREG_SPLIT_NO_EMPTY);
    return array_values(array_filter($toks, fn($t) => strlen($t) > 1 && ($keepGeneric || !in_array($t, $generic, true))));
}

/**
 * Same supplier written by two checks: equal words, one inside the other, ≥ 2/3 of the words in common, or the
 * initials ("SCVC(Ndutu)" = Serengeti Classic View Camp).
 */
function followup_supplier_same(string $a, string $b): bool {
    $ta = followup_supplier_tokens($a); $tb = followup_supplier_tokens($b);
    if (!$ta || !$tb) return false;
    $ja = implode('', $ta); $jb = implode('', $tb);
    if ($ja === $jb) return true;
    if (strlen($ja) >= 5 && strlen($jb) >= 5 && (strpos($ja, $jb) !== false || strpos($jb, $ja) !== false)) return true;
    $common = count(array_intersect(array_unique($ta), array_unique($tb)));
    if ($common > 0 && $common / min(count(array_unique($ta)), count(array_unique($tb))) >= 0.67) return true;
    // Initials, with or without the generic last word ("SCVC" = Serengeti Classic View [Camp]).
    foreach ([[$a, $tb], [$b, $ta]] as [$full, $other]) {
        $ini = implode('', array_map(fn($t) => $t[0], followup_supplier_tokens($full, true)));
        if (strlen($ini) < 3) continue;
        foreach ($other as $t) if ($t === $ini || (strlen($t) === strlen($ini) + 1 && strpos($t, $ini) === 0)) return true;
    }
    return false;
}

/** request | service type | service date | supplier words — the service a line is about (merging, see followup_service_match()). */
function followup_service_key(array $l): string {
    $who = $l['supplier'] ?? '';
    if ($who === '' || $who === null) $who = $l['service'] ?? '';
    return followup_key_fit('R' . (int)($l['request_id'] ?? 0) . '|' . followup_service_type($l) . '|' . (($l['service_date'] ?? null) ?: '-')
                            . '|' . substr(implode('', followup_supplier_tokens((string)$who)), 0, 60));
}

/**
 * Two lines of different checks about the same service of a booking: same request and audience, and the same
 * supplier reference, or the same service type + service date + supplier (a line without a date: the same words).
 * Transfers and activities: type + date is enough — SafariCheck has no supplier and writes the Calc route, the Claude
 * check writes "ZNZ airport → hotel" and the transfer supplier. Stays keep the lodge match (two lodges can share a date).
 */
function followup_service_match(array $a, array $b): bool {
    if (empty($a['request_id']) || (int)$a['request_id'] !== (int)($b['request_id'] ?? 0)) return false;
    if ((($a['audience'] ?? null) ?: 'booking') !== (($b['audience'] ?? null) ?: 'booking')) return false;
    $ref = fn($l) => strtoupper(preg_replace('/\s+/', '', (string)($l['supplier_ref'] ?? '')));
    if ($ref($a) !== '' && $ref($a) === $ref($b)) return true;
    if (followup_service_type($a) !== followup_service_type($b)) return false;
    $who = fn($l) => trim((string)(($l['supplier'] ?? '') ?: ($l['service'] ?? '')));
    $da = ($a['service_date'] ?? null) ?: null; $db = ($b['service_date'] ?? null) ?: null;
    if ($da && $db && in_array(followup_service_type($a), ['transfer', 'activity'], true)) return $da === $db;
    if ($da && $db) return $da === $db && followup_supplier_same($who($a), $who($b));
    $ta = followup_supplier_tokens($who($a)); $tb = followup_supplier_tokens($who($b));
    return $ta && $ta === $tb;
}

/**
 * The line's deadline: the earliest of
 *   provisional expiry printed on the document (expiry_date; the ticketing limit on a group ZNZ flight line),
 *   supplier payment of a stay: check-in − 30 days (Orangi Collection always; other lodges: the days read in the
 *   document's terms, details.payment_days),
 *   group block release: arrival − 60 days (GRP / Il Diamante / _PROVISIONAL groups),
 *   domestic flight: the ticketing time limit on the pro-forma (details.ticket_limit), else the flight − 7 days,
 *   and, when none of these applies, the service (or arrival) − 14 days. Manual lines: the date staff set.
 * → ['due_date' => Y-m-d|null, 'due_reason' => text|null]
 */
function followup_due(array $l): array {
    $det = is_array($l['details'] ?? null) ? $l['details'] : (json_decode((string)($l['details'] ?? ''), true) ?: []);
    if (($l['type'] ?? '') === 'manual' && !empty($det['due_date'])) return ['due_date' => $det['due_date'], 'due_reason' => 'set by hand'];
    $minus = fn(string $d, int $n) => date('Y-m-d', strtotime($d . ' -' . $n . ' days'));
    $c = [];
    if (!empty($l['expiry_date'])) {
        $c[] = [$l['expiry_date'], ($l['issue'] ?? '') === 'group_znz_flight' ? 'ticketing time limit' : 'provisional expiry'];
    }
    $svc = ($l['service_date'] ?? null) ?: ($l['start_date'] ?? null);
    $st = followup_service_type($l);
    if ($st === 'stay' && $svc) {
        $orangi = ($l['issue'] ?? '') === 'orangi_rooms_provisional'
               || preg_match('/orangi/i', ($l['supplier'] ?? '') . ' ' . ($l['source_file'] ?? '') . ' ' . ($l['service'] ?? ''));
        $n = $orangi ? FU_PAY_DAYS : (!empty($det['payment_days']) ? (int)$det['payment_days'] : FU_PAY_DAYS);
        $c[] = [$minus($svc, $n), 'supplier payment (' . $n . ' d before check-in' . ($orangi ? ', Orangi' : '') . ')'];
    }
    $top = explode('/', ltrim((string)($l['folder'] ?? ''), '/'))[0];
    if ((($l['type'] ?? '') === 'group' || ($top !== '' && followup_group_kind($top))) && !empty($l['start_date'])) {
        $c[] = [$minus($l['start_date'], FU_GROUP_RELEASE_DAYS), 'group release (' . FU_GROUP_RELEASE_DAYS . ' d before arrival)'];
    }
    if ($st === 'flight' && $svc) {
        $c[] = !empty($det['ticket_limit']) ? [$det['ticket_limit'], 'ticketing time limit']
                                             : [$minus($svc, FU_TICKET_DUE_DAYS), 'ticketing (' . FU_TICKET_DUE_DAYS . ' d before the flight)'];
    }
    if (!$c && $svc) $c[] = [$minus($svc, FU_SERVICE_DUE_DAYS), FU_SERVICE_DUE_DAYS . ' d before the service'];
    if (!$c) return ['due_date' => null, 'due_reason' => null];
    usort($c, fn($x, $y) => strcmp($x[0], $y[0]));
    return ['due_date' => $c[0][0], 'due_reason' => $c[0][1]];
}

/**
 * Default owner of a new line: booking → the booking owner of the request's CK folder, sales → its CK owner,
 * accounts → Roberto (ck_lib.php owners); no CK folder / no booking owner → nobody.
 */
function followup_default_owner(PDO $db, ?int $requestId, ?string $audience): ?int {
    static $byReq = null;
    $aud = $audience ?: 'booking';
    if ($aud === 'accounts') { $u = ck_user_find($db, FU_ACCOUNTS_OWNER); return $u ? (int)$u['id'] : null; }
    if (!$requestId) return null;
    if ($byReq === null) {
        $byReq = [];
        try {
            require_once __DIR__ . '/../../leads/dropbox_helper.php';
            foreach (ck_tracker_rows($db) as $r) foreach ($r['reqs'] as $q) $byReq[(int)$q['id']] = $r;
        } catch (Throwable $e) {
            error_log('followup_default_owner: ' . $e->getMessage());
        }
    }
    if (!isset($byReq[$requestId])) return null;
    $o = ck_owners_resolve($db, $byReq[$requestId]);
    $who = $aud === 'sales' ? $o['ck_owner'] : $o['booking_owner'];
    return $who ? (int)$who['user_id'] : null;
}

function followup_meta_get(PDO $db, string $k): ?string {
    $st = $db->prepare("SELECT v FROM booking_followup_meta WHERE k = ?");
    $st->execute([$k]);
    $v = $st->fetchColumn();
    return $v === false ? null : (string)$v;
}

function followup_meta_set(PDO $db, string $k, string $v): void {
    $db->prepare("INSERT INTO booking_followup_meta (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)")->execute([$k, $v]);
}

/**
 * Once (25 Oct 2026, SafariCheck issues typed by followup_service_type()): service_key recomputed on every open line,
 * so lines written without one (or with the old type guess) merge with the other checks' findings.
 */
function followup_backfill_keys(PDO $db): void {
    try {
        if (followup_meta_get($db, 'backfill_keys_2') !== null) return;
        followup_meta_set($db, 'backfill_keys_2', date('Y-m-d H:i:s'));
        $up = $db->prepare("UPDATE booking_followups SET service_key = ? WHERE id = ?");
        // Lines without a key, and SafariCheck lines (their type now comes from the issue). A ck line keeps the type
        // the Claude check sent (service_type lives only in its key).
        foreach ($db->query("SELECT * FROM booking_followups WHERE closed_at IS NULL AND (service_key IS NULL OR type = 'safaricheck')")
                    ->fetchAll(PDO::FETCH_ASSOC) as $l) {
            $l['service_key'] = null;                                      // recompute the type too, not read it back
            $k = followup_service_key($l);
            $up->execute([$k, (int)$l['id']]);
        }
    } catch (Throwable $e) {
        error_log('followup_backfill_keys: ' . $e->getMessage());
    }
}

/**
 * Once, after migration 074: the SafariCheck points of every upcoming booking's last check become lines, and the
 * open lines get their service_key / due date and, when nobody owns them, their default owner (lines with an owner
 * are never reassigned). Runs on the first scan / page view; marked done in booking_followup_meta.
 */
function followup_backfill(PDO $db): void {
    static $done = false;
    if ($done) return;
    $done = true;
    followup_schema($db);
    followup_backfill_keys($db);
    try {
        if (followup_meta_get($db, 'backfill_074') !== null) return;
        followup_meta_set($db, 'backfill_074', date('Y-m-d H:i:s'));   // first: a failure below is not retried on every page
        require_once __DIR__ . '/../../leads/dropbox_helper.php';
        $today = date('Y-m-d');
        $rows = [];
        foreach (ck_tracker_rows($db) as $r) $rows[(int)$r['id']] = $r;
        $st = $db->query("SELECT c.ck_folder_id, c.checks_json, c.facts_json FROM ck_checks c JOIN ck_folders f ON f.last_check_id = c.id
                          WHERE f.gone = 0 AND (f.end_date IS NULL OR f.end_date >= CURDATE())");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $row = $rows[(int)$c['ck_folder_id']] ?? null;
            if (!$row || $row['other'] || !$row['reqs']) continue;
            followup_safaricheck_sync($db, $row, json_decode((string)$c['checks_json'], true) ?: [], json_decode((string)$c['facts_json'], true) ?: [], true, $today);
        }
        foreach ($db->query("SELECT * FROM booking_followups WHERE closed_at IS NULL")->fetchAll(PDO::FETCH_ASSOC) as $l) {
            $due = followup_due($l);
            $set = ['service_key' => followup_service_key($l), 'due_date' => $due['due_date'], 'due_reason' => $due['due_reason']];
            if ($l['sources'] === null) $set['sources'] = followup_sources_json(followup_sources($l));
            $owner = null;
            if ($l['owner_user_id'] === null && ($owner = followup_default_owner($db, $l['request_id'] !== null ? (int)$l['request_id'] : null, $l['audience']))) {
                $set['owner_user_id'] = $owner;
            }
            $db->prepare("UPDATE booking_followups SET " . implode(', ', array_map(fn($c) => "$c = ?", array_keys($set))) . " WHERE id = ?")
               ->execute([...array_values($set), (int)$l['id']]);
            if ($owner) followup_event($db, (int)$l['id'], null, 'owner', null, $owner);
        }
    } catch (Throwable $e) {
        error_log('followup_backfill: ' . $e->getMessage());
    }
}

// ── Apply: insert / update / merge / close / re-open ─────────────────────────

/** Event row (user NULL = the check). */
function followup_event(PDO $db, int $id, ?int $userId, string $field, $old, $new): void {
    $db->prepare("INSERT INTO booking_followup_events (followup_id, at, user_id, field, old_value, new_value) VALUES (?,?,?,?,?,?)")
       ->execute([$id, date('Y-m-d H:i:s'), $userId, $field, $old === null ? null : (string)$old, $new === null ? null : (string)$new]);
}

/** A line as the summary lists it. */
function followup_brief(array $l): array {
    return array_filter(['id' => isset($l['id']) ? (int)$l['id'] : null, 'request_id' => (int)$l['request_id'], 'folder' => $l['folder'],
                         'audience' => $l['audience'] ?? null, 'owner_user_id' => isset($l['owner_user_id']) ? (int)$l['owner_user_id'] : null,
                         'supplier' => $l['supplier'] ?? null, 'ref' => $l['supplier_ref'] ?? null, 'service' => $l['service'] ?? null,
                         'service_date' => $l['service_date'] ?? null, 'issue' => $l['issue'], 'file' => $l['source_file'] ?? null,
                         'status' => $l['doc_status_text'] ?? null], fn($v) => $v !== null && $v !== '');
}

/** Empty summary of followup_apply(). */
function followup_sum_init(): array {
    return ['added' => [], 'updated' => [], 'merged' => [], 'closed' => [], 'cleared' => [], 'reopened' => [], 'unreadable' => [], 'unchanged' => 0];
}

/** Add summary $s to $sum (lists appended, counts summed). */
function followup_sum_add(array $sum, array $s): array {
    foreach ($s as $k => $v) $sum[$k] = is_array($v) ? array_merge($sum[$k] ?? [], $v) : ($sum[$k] ?? 0) + $v;
    return $sum;
}

/**
 * Write the check's result for one type (its source: FU_TYPE_SOURCE).
 * $cand  [key => line] found now (a ck / safaricheck finding may carry service_type).
 * $scope 'requests'  request_ids whose documents were all read (a line of theirs not found → auto_not_found)
 *        'confirmed' [key => file] references now confirmed (→ auto_confirmed)
 *        'ok'        request_ids whose tickets are saved (→ auto_ticket_saved)
 *        'only'      request_ids the run was limited to (null = all; every candidate must belong to one)
 *        'only_folder' key scope "F:<stem>" of a folder without a confirmed booking (its lines only;
 *                    'requests' => [0] when its documents were all read)
 * One list: a new finding about a service another check already has open (followup_service_match()) is added to
 * that line's `sources` (summary `merged`, no new line); a line this source was merged into is updated by the key it
 * reported it with. Not reported any more → the source leaves `sources` (`cleared`); the line closes when none is
 * left (`closed`; trip ended: always). auto_confirmed also clears the other "provisional" sources.
 * Dry run ($confirm false): the same summary, nothing written.
 */
function followup_apply(PDO $db, string $type, array $cand, array $scope, bool $confirm, string $source, string $today): array {
    followup_schema($db);
    $now = date('Y-m-d H:i:s');
    $tag = FU_TYPE_SOURCE[$type];
    $sum = followup_sum_init();
    $only = isset($scope['only']) ? array_map('intval', (array)$scope['only']) : null;   // request_ids the run looked at (null = all)
    $inOnly = $only !== null ? " AND request_id IN (" . implode(',', $only ?: [0]) . ")" : '';
    if (isset($scope['only_folder'])) {
        $prefix = 'S|' . $scope['only_folder'] . '|';
        $st = $db->prepare("SELECT * FROM booking_followups WHERE type = ? AND request_id IS NULL AND LEFT(dedupe_key, ?) = ?");
        $st->execute([$type, strlen($prefix), $prefix]);
    } else {
        $st = $db->prepare("SELECT * FROM booking_followups WHERE type = ?" . $inOnly);
        $st->execute([$type]);
    }
    $have = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $have[$r['dedupe_key']] = $r;
    // Lines of other checks this source was merged into, by the key it reported them with.
    if (!isset($scope['only_folder'])) {
        $st = $db->prepare("SELECT * FROM booking_followups WHERE type <> ? AND sources LIKE ?" . $inOnly);
        $st->execute([$type, '%"' . $tag . '"%']);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $k = followup_sources($r)[$tag]['key'] ?? null;
            if ($k !== null && !isset($have[$k])) $have[$k] = $r + ['_guest' => true];
        }
    }
    // Open lines of other checks a new finding may join: same request.
    $pool = [];
    $rids = array_values(array_unique(array_filter(array_map(fn($l) => (int)($l['request_id'] ?? 0), $cand))));
    if ($rids && $type !== 'manual') {
        $st = $db->prepare("SELECT * FROM booking_followups WHERE closed_at IS NULL AND type <> ? AND request_id IN (" . implode(',', $rids) . ")");
        $st->execute([$type]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $pool[(int)$r['request_id']][(int)$r['id']] = $r;
    }
    $docFields = ['audience', 'request_id', 'folder', 'start_date', 'end_date', 'service_date', 'supplier', 'supplier_ref', 'service',
                  'source_file', 'issue', 'doc_status_text', 'expiry_date', 'booked_by', 'details'];
    $logged = ['issue', 'doc_status_text', 'expiry_date', 'source_file', 'service_date'];
    $srcLabel = fn(array $l) => $tag . ': ' . followup_issue_label((string)$l['issue']) . (!empty($l['doc_status_text']) ? ' — ' . mb_substr((string)$l['doc_status_text'], 0, 200) : '');
    // Not re-opened: not relevant (admin), trip ended, and a group the booking team closed (they finalise it).
    $mayReopen = fn(array $old) => $old['closed_at'] !== null && $old['staff_status'] !== 'not_relevant' && $old['close_reason'] !== 'auto_trip_ended'
                                   && !($old['type'] === 'group' && $old['close_reason'] === 'manual');
    $reopenSql = function (array $old, array &$set) {
        $set[] = 'closed_at = NULL'; $set[] = 'closed_by = NULL'; $set[] = 'close_reason = NULL';
        if ($old['staff_status'] === 'resolved') $set[] = "staff_status = 'open'";
    };
    $reopenEvents = function (int $id, array $old, string $what) use ($db) {
        followup_event($db, $id, null, 'reopened', ($old['close_reason'] ?? '') . ($old['staff_status'] === 'resolved' ? ' / resolved' : ''), $what);
        if ($old['staff_status'] === 'resolved') followup_event($db, $id, null, 'staff_status', 'resolved', 'open');
    };

    foreach ($cand as $key => $l) {
        $l['type'] = $type;
        if ($l['issue'] === 'unreadable') $sum['unreadable'][] = followup_brief($l);
        $old = $have[$key] ?? null;
        $entry = followup_source_entry($l, $key, $now);

        // A line of another check this source was merged into: only this source's entry changes.
        if ($old && !empty($old['_guest'])) {
            $id = (int)$old['id'];
            $src = followup_sources($old);
            $prev = $src[$tag] ?? null;
            $src[$tag] = ['since' => $prev['since'] ?? $now] + $entry;
            $changed = !$prev || $prev['issue'] !== $entry['issue'] || $prev['text'] !== $entry['text'];
            $reopen = $mayReopen($old);
            if ($old['closed_at'] !== null && !$reopen) { $sum['unchanged']++; continue; }
            if ($confirm) {
                $set = ['last_seen_at = ?', 'sources = ?']; $args = [$now, followup_sources_json($src)];
                if ($reopen) $reopenSql($old, $set);
                $args[] = $id;
                $db->prepare("UPDATE booking_followups SET " . implode(', ', $set) . " WHERE id = ?")->execute($args);
                if ($changed) followup_event($db, $id, null, 'source', $prev ? $tag . ': ' . followup_issue_label((string)$prev['issue']) : null, $srcLabel($l));
                if ($reopen) $reopenEvents($id, $old, 'still reported by ' . $tag);
            }
            $b = followup_brief(['id' => $id] + $l) + ['merged_into' => $id, 'into_type' => $old['type']];
            if ($reopen)      $sum['reopened'][] = $b + ['was' => $old['close_reason'] . ($old['staff_status'] === 'resolved' ? ' / resolved' : '')];
            elseif ($changed) $sum['updated'][] = $b + ['changed' => ['sources']];
            else              $sum['unchanged']++;
            continue;
        }

        if (!$old) {
            // The same service already open from another check: this source joins that line.
            $target = null;
            foreach ($pool[(int)($l['request_id'] ?? 0)] ?? [] as $p) {
                if (isset(followup_sources($p)[$tag])) continue;            // this check already reports something else on it
                if (followup_service_match($p, $l)) { $target = $p; break; }
            }
            if ($target) {
                $tid = (int)$target['id'];
                $src = followup_sources($target);
                $src[$tag] = $entry;
                $pool[(int)$target['request_id']][$tid]['sources'] = followup_sources_json($src);
                $sum['merged'][] = followup_brief(['id' => $tid] + $l) + ['into' => $tid, 'into_type' => $target['type'],
                                                                         'into_issue' => $target['issue'], 'sources' => array_keys($src)];
                if ($confirm) {
                    $db->prepare("UPDATE booking_followups SET sources = ?, last_seen_at = ? WHERE id = ?")->execute([followup_sources_json($src), $now, $tid]);
                    followup_event($db, $tid, null, 'source', null, $srcLabel($l));
                }
                continue;
            }
            $owner = array_key_exists('owner_user_id', $l) ? $l['owner_user_id']
                   : followup_default_owner($db, !empty($l['request_id']) ? (int)$l['request_id'] : null, $l['audience'] ?? null);
            $due = followup_due($l);
            $sum['added'][] = followup_brief($l + ['owner_user_id' => $owner]);
            if ($confirm) {
                // The default owner on insert only (staff reassign later; the check never touches it again).
                $cols = array_merge(['type', 'dedupe_key'], $docFields, ['owner_user_id', 'first_seen_at', 'last_seen_at', 'scan_source',
                                                                         'sources', 'service_key', 'due_date', 'due_reason']);
                $vals = array_merge([$type, $key], array_map(fn($f) => $l[$f] ?? null, $docFields),
                                    [$owner, $now, $now, $source, followup_sources_json([$tag => $entry]), followup_service_key($l), $due['due_date'], $due['due_reason']]);
                $db->prepare("INSERT INTO booking_followups (" . implode(',', $cols) . ") VALUES (" . implode(',', array_fill(0, count($cols), '?')) . ")")
                   ->execute($vals);
                $nid = (int)$db->lastInsertId();
                followup_event($db, $nid, null, 'created', null, ($l['doc_status_text'] ?? '') . (!empty($l['source_file']) ? ' — ' . $l['source_file'] : ''));
                if ($owner) followup_event($db, $nid, null, 'owner', null, $owner);
            }
            continue;
        }

        // This check's own line.
        $id = (int)$old['id'];
        $diff = [];
        foreach ($docFields as $f) {
            $nv = $l[$f] ?? null; $ov = $old[$f];
            if ((string)$nv !== (string)$ov && !($nv === null && $f !== 'issue' && $ov !== null && in_array($f, ['booked_by', 'supplier_ref'], true))) $diff[$f] = [$ov, $nv];
        }
        $src = followup_sources($old);
        $prev = $src[$tag] ?? null;
        $src[$tag] = ['since' => $prev['since'] ?? $now] + $entry;
        $merged = $l;
        foreach ($docFields as $f) if (!isset($diff[$f])) $merged[$f] = $old[$f];   // the line as it will be
        $due = followup_due($merged);
        // Kept up to date with the line, not counted as a change.
        $meta = [];
        foreach (['sources' => followup_sources_json($src), 'service_key' => followup_service_key($merged),
                  'due_date' => $due['due_date'], 'due_reason' => $due['due_reason']] as $f => $v) {
            if ((string)$v !== (string)($old[$f] ?? '')) $meta[$f] = $v;
        }
        $reopen = $mayReopen($old);
        if ($old['closed_at'] !== null && !$reopen) {                      // stays closed
            if ($confirm) $db->prepare("UPDATE booking_followups SET last_seen_at = ? WHERE id = ?")->execute([$now, $id]);
            $sum['unchanged']++;
            continue;
        }
        if ($confirm) {
            $set = ['last_seen_at = ?']; $args = [$now];
            foreach ($diff as $f => $v) { $set[] = "$f = ?"; $args[] = $v[1]; }
            foreach ($meta as $f => $v) { $set[] = "$f = ?"; $args[] = $v; }
            if ($reopen) $reopenSql($old, $set);
            $args[] = $id;
            $db->prepare("UPDATE booking_followups SET " . implode(', ', $set) . " WHERE id = ?")->execute($args);
            foreach ($diff as $f => $v) if (in_array($f, $logged, true)) followup_event($db, $id, null, $f, $v[0], $v[1]);
            if (!$prev && $old['sources'] !== null) followup_event($db, $id, null, 'source', null, $srcLabel($l));   // reported again by its own check
            if ($reopen) {
                $what = $type === 'ticket' ? 'ticket still missing' : ($type === 'group' ? 'group still to finalize'
                      : ($type === 'ck' ? 'still reported by CK check' : ($type === 'safaricheck' ? 'still reported by SafariCheck'
                      : 'still ' . strtolower(followup_issue_label((string)$l['issue'])) . (!empty($l['source_file']) ? ' in ' . $l['source_file'] : ''))));
                $reopenEvents($id, $old, $what);
            }
        }
        if ($reopen)    $sum['reopened'][] = followup_brief(['id' => $id] + $l) + ['was' => $old['close_reason'] . ($old['staff_status'] === 'resolved' ? ' / resolved' : '')];
        elseif ($diff)  $sum['updated'][]  = followup_brief(['id' => $id] + $l) + ['changed' => array_keys($diff)];
        else            $sum['unchanged']++;
    }

    // Open lines this check no longer reports.
    $done = array_flip(array_map('intval', $scope['requests'] ?? []));
    foreach ($have as $key => $old) {
        if ($old['closed_at'] !== null || isset($cand[$key])) continue;
        $src = followup_sources($old);
        if (!isset($src[$tag])) continue;                                  // already left by this check (kept open by another one)
        $rid = (int)$old['request_id'];
        $reason = null;
        if (($old['end_date'] ?: $old['start_date']) && ($old['end_date'] ?: $old['start_date']) < $today) $reason = 'auto_trip_ended';
        elseif ($type === 'supplier' && isset($scope['confirmed'][$key])) $reason = 'auto_confirmed';
        elseif ($type === 'ticket' && isset($scope['ok'][$rid])) $reason = 'auto_ticket_saved';
        elseif ($type === 'group' && !empty($scope['complete_type'])) $reason = 'auto_not_found';   // folder renamed / cancelled / moved
        elseif ($type === 'ck' || $type === 'safaricheck') { if (isset($done[$rid])) $reason = 'auto_resolved'; }   // the new check of the booking no longer reports it
        elseif ($type === 'manual') $reason = null;                                                     // staff close their own lines
        elseif (isset($done[$rid]) && ($type === 'supplier'
                || $old['service_date'] >= $today && $old['service_date'] <= ($scope['to'] ?? '9999-12-31'))) $reason = 'auto_not_found';
        if (!$reason) continue;
        unset($src[$tag]);
        if ($reason === 'auto_confirmed') {                                 // a confirmed document: no other check's "provisional" stands
            foreach ($src as $t => $e) if (in_array($e['issue'] ?? '', FU_PROVISIONAL_ISSUES, true)) unset($src[$t]);
        }
        $extra = $reason === 'auto_confirmed' ? ['confirmed_in' => $scope['confirmed'][$key]] : [];
        if ($src && $reason !== 'auto_trip_ended') {
            // Still reported by another check: the line stays open.
            $sum['cleared'][] = followup_brief($old) + ['source' => $tag, 'reason' => $reason, 'still' => array_keys($src)] + $extra;
            if ($confirm) {
                $db->prepare("UPDATE booking_followups SET sources = ? WHERE id = ?")->execute([followup_sources_json($src), (int)$old['id']]);
                followup_event($db, (int)$old['id'], null, 'source', $tag, $reason . ' — still reported by ' . implode(', ', array_keys($src)));
            }
            continue;
        }
        $sum['closed'][] = followup_brief($old) + ['reason' => $reason] + $extra;
        if ($confirm) {
            $db->prepare("UPDATE booking_followups SET closed_at = ?, closed_by = 'system', close_reason = ?, sources = ? WHERE id = ? AND closed_at IS NULL")
               ->execute([$now, $reason, followup_sources_json($src), (int)$old['id']]);
            followup_event($db, (int)$old['id'], null, 'closed', null,
                           $reason . ($reason === 'auto_confirmed' ? ' — ' . $scope['confirmed'][$key] : '') . ($tag !== FU_TYPE_SOURCE[$old['type']] ? ' (' . $tag . ')' : ''));
        }
    }
    return $sum;
}

/**
 * A confirmed supplier document clears every "provisional" source (Claude check booking_provisional, …) of the same
 * service on the lines of other checks; a line with no source left closes as auto_confirmed.
 * $docs [key => confirmed document, followup_supplier_candidates() 'confirmed_docs'].
 */
function followup_clear_confirmed(PDO $db, array $docs, bool $confirm, string $today): array {
    $sum = followup_sum_init();
    $byReq = [];
    foreach ($docs as $k => $d) if (!empty($d['request_id'])) $byReq[(int)$d['request_id']][$k] = $d;
    if (!$byReq) return $sum;
    $st = $db->query("SELECT * FROM booking_followups WHERE closed_at IS NULL AND type <> 'supplier' AND request_id IN (" . implode(',', array_keys($byReq)) . ")");
    $now = date('Y-m-d H:i:s');
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $l) {
        foreach ($byReq[(int)$l['request_id']] as $k => $d) {
            if (!followup_service_match($l, $d + ['audience' => 'booking'])) continue;
            $src = followup_sources($l);
            $gone = array_keys(array_filter($src, fn($e) => in_array($e['issue'] ?? '', FU_PROVISIONAL_ISSUES, true)));
            if (!$gone) break;
            foreach ($gone as $t) unset($src[$t]);
            $b = followup_brief($l) + ['reason' => 'auto_confirmed', 'confirmed_in' => $d['source_file'], 'sources_cleared' => $gone];
            if ($src) $sum['cleared'][] = $b + ['still' => array_keys($src)];
            else      $sum['closed'][] = $b;
            if ($confirm) {
                if ($src) {
                    $db->prepare("UPDATE booking_followups SET sources = ? WHERE id = ?")->execute([followup_sources_json($src), (int)$l['id']]);
                    followup_event($db, (int)$l['id'], null, 'source', implode(', ', $gone), 'auto_confirmed — ' . $d['source_file']);
                } else {
                    $db->prepare("UPDATE booking_followups SET closed_at = ?, closed_by = 'system', close_reason = 'auto_confirmed', sources = '{}' WHERE id = ? AND closed_at IS NULL")
                       ->execute([$now, (int)$l['id']]);
                    followup_event($db, (int)$l['id'], null, 'closed', null, 'auto_confirmed — ' . $d['source_file']);
                }
            }
            break;
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
    if ($confirm) followup_backfill($db);
    $token = null;

    $reader = followup_pdf_reader();
    if ($type === 'supplier' || $type === 'all') {
        // No PDF reader here: listing every invoices/ folder would find nothing new (the office PC scan sends them).
        $units = $reader !== null ? followup_units($db, $rid, $today) : [];
        $token = $token ?? cp_retry(fn() => dropbox_get_access_token());
        $deadline = microtime(true) + (float)($o['budget'] ?? FU_SCAN_BUDGET);
        $cand = []; $confirmed = []; $confirmedDocs = []; $doneReq = []; $partialReq = []; $errors = []; $complete = 0;
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
            $cand += $c['lines']; $confirmed += $c['confirmed']; $confirmedDocs += $c['confirmed_docs'];
            if ($res['complete']) { $doneReq[] = $owner; $complete++; }
            else $partialReq[$owner] = true;
        }
        // A request owning several folders (GRP leader: group + own sub-folder) is complete only when all were read.
        $doneReq = array_values(array_filter(array_unique($doneReq), fn($x) => !isset($partialReq[$x])));
        $owners = array_values(array_unique(array_map(fn($u) => (int)$u['request']['id'], $units)));   // a GRP member's run reads its group's lines too
        $sum = followup_apply($db, 'supplier', $cand, ['requests' => $doneReq, 'confirmed' => $confirmed, 'only' => $rid ? $owners : null],
                              $confirm, 'server', $today);
        $sum = followup_sum_add($sum, followup_clear_confirmed($db, $confirmedDocs, $confirm, $today));
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
    foreach (['added', 'updated', 'merged', 'closed', 'cleared', 'reopened', 'unreadable'] as $k) $c[$k] = count($s[$k] ?? []);
    foreach (['units', 'units_complete', 'reader', 'partial'] as $k) if (isset($s[$k])) $c[$k] = $s[$k];
    if (isset($s['groups'])) $c['groups'] = count($s['groups']);
    return $c;
}

// ── Upsert from outside the server (office PC, Cowork) ───────────────────────

/**
 * Supplier documents read elsewhere. $rows[] {folder | request_id, source_file, supplier?, supplier_ref?,
 * service_date?, issue? (or status: confirmed), doc_status_text?, expiry_date?, booked_by?, payment_days?, ticket_limit?};
 * $scanned = folders / request_ids whose invoices/ were read completely (their lines not sent close
 * as auto_not_found, confirmed rows close their reference). Same rules as the server scan.
 * → summary + {matched, unmatched[]}
 */
function followup_upsert(PDO $db, array $rows, array $scanned, bool $confirm, string $source = 'agent', ?string $today = null): array {
    $today = $today ?? date('Y-m-d');
    followup_schema($db);
    if ($confirm) followup_backfill($db);
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
    // A folder without a confirmed booking gets its own lines (request_id NULL, keys "S|F:<stem>|…"):
    // group folders in /001_Safari (provisional GRP / set departures) and folders outside it (Diamante 20xx-Groups).
    $folderUnit = function ($ref) use (&$units) {
        if ($ref === null || is_int($ref) || ctype_digit((string)$ref)) return null;
        $rel = followup_folder_norm((string)$ref);
        if ($rel === '' || ($rel[0] !== '/' && !followup_group_kind(explode('/', $rel)[0]))) return null;
        $k = 'f:' . strtolower($rel);
        if (!isset($units[$k])) {
            $d = parse_folder_dates(basename($rel));
            $units[$k] = ['dir' => followup_folder_dir($rel), 'folder' => $rel, 'request' => null, 'request_ids' => [],
                          'start_date' => $d['start_date'], 'end_date' => $d['end_date']];
        }
        return $k;
    };
    // Outside /001_Safari: never matched by name (a confirmed copy of the group in /001_Safari has its own lines).
    $locate = fn($ref) => (is_string($ref) && strncmp(followup_folder_norm($ref), '/', 1) === 0) ? $folderUnit($ref) : ($find($ref) ?? $folderUnit($ref));
    $docs = []; $unmatched = []; $cached = 0;
    foreach ($rows as $i => $r) {
        $ref = $r['request_id'] ?? $r['folder'] ?? null;
        $k = $ref !== null ? $locate($ref) : null;
        // The folder as it is now on disk (the Hub record may keep an old status tag): the 📂 link must open it.
        if ($k !== null && !empty($r['folder'])) {
            $units[$k]['folder'] = followup_folder_norm((string)$r['folder']);
            $units[$k]['dir'] = followup_folder_dir($units[$k]['folder']);
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
                       'expiry_date' => $date($r['expiry_date'] ?? null), 'booked_by' => trim((string)($r['booked_by'] ?? '')) ?: null,
                       'payment_days' => isset($r['payment_days']) && (int)$r['payment_days'] > 0 && (int)$r['payment_days'] <= 180 ? (int)$r['payment_days'] : null,
                       'ticket_limit' => $date($r['ticket_limit'] ?? null)];
    }
    $doneUnits = [];
    foreach ($scanned as $s) if (($k = $locate($s)) !== null) { $doneUnits[$k] = true; $docs[$k] = $docs[$k] ?? []; }
    $cand = []; $confirmed = []; $confirmedDocs = []; $doneReq = []; $partialOwner = []; $folderDocs = [];
    foreach ($docs as $k => $list) {
        if (!$units[$k]['request']) { $folderDocs[$k] = $list; continue; }
        if ($confirm) followup_adopt_folder_lines($db, $units[$k]);
        $c = followup_supplier_candidates($units[$k], $list, $today);
        $cand += $c['lines']; $confirmed += $c['confirmed']; $confirmedDocs += $c['confirmed_docs'];
        if (isset($doneUnits[$k])) $doneReq[] = (int)$units[$k]['request']['id'];
        else $partialOwner[(int)$units[$k]['request']['id']] = true;   // documents sent from a folder not read completely
    }
    $doneReq = array_values(array_filter(array_unique($doneReq), fn($x) => !isset($partialOwner[$x])));
    // Only the requests sent are in scope: other bookings' lines are left alone.
    $sum = followup_apply_subset($db, $cand, ['requests' => $doneReq, 'confirmed' => $confirmed],
                                 array_unique(array_map(fn($k) => (int)$units[$k]['request']['id'], array_diff(array_keys($docs), array_keys($folderDocs)))),
                                 $confirm, $source, $today);
    $sum = followup_sum_add($sum, followup_clear_confirmed($db, $confirmedDocs, $confirm, $today));
    // Folders without a confirmed booking: one apply each, limited to that folder's lines.
    foreach ($folderDocs as $k => $list) {
        $c = followup_supplier_candidates($units[$k], $list, $today);
        $s = followup_apply($db, 'supplier', $c['lines'], ['requests' => isset($doneUnits[$k]) ? [0] : [], 'confirmed' => $c['confirmed'],
                                                         'only_folder' => followup_folder_scope($units[$k]['folder'])], $confirm, $source, $today);
        $sum = followup_sum_add($sum, $s);
    }
    return $sum + ['matched' => count($rows) - count($unmatched), 'unmatched' => $unmatched, 'folders' => count($docs),
                   'folders_without_booking' => count($folderDocs), 'documents_kept' => $cached];
}

/**
 * A folder followed before its booking was confirmed (lines "S|F:<stem>|…", request_id NULL): its lines
 * pass to the booking, with their owner, notes and history. A key the booking already has stays as it is.
 */
function followup_adopt_folder_lines(PDO $db, array $u): void {
    $rid = (int)$u['request']['id'];
    $from = 'S|' . followup_folder_scope($u['folder']) . '|';
    $st = $db->prepare("SELECT id, dedupe_key FROM booking_followups WHERE type = 'supplier' AND request_id IS NULL AND LEFT(dedupe_key, ?) = ?");
    $st->execute([strlen($from), $from]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $l) {
        $key = followup_key_fit('S|' . $rid . '|' . substr($l['dedupe_key'], strlen($from)));
        try {
            $db->prepare("UPDATE booking_followups SET request_id = ?, dedupe_key = ? WHERE id = ?")->execute([$rid, $key, (int)$l['id']]);
            followup_event($db, (int)$l['id'], null, 'booking', null, 'confirmed booking #' . $rid);
        } catch (Throwable $e) {
            error_log('followup_adopt_folder_lines: ' . $e->getMessage());   // duplicate key: the booking already follows it
        }
    }
}

/** A document read elsewhere, stored like a server reading (rev 'local') so the group check can use it. */
function followup_cache_sent_doc(PDO $db, array $r, ?string $unitDir): bool {
    $file = trim((string)($r['source_file'] ?? ''));
    if ($file === '') return false;
    if (stripos($file, 'invoices/') !== 0) $file = 'invoices/' . ltrim($file, '/');
    $dir = !empty($r['folder']) ? followup_folder_dir(followup_folder_norm((string)$r['folder'])) : $unitDir;
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
    $sum = followup_sum_init();
    foreach ($requestIds as $rid) {
        $c = array_filter($cand, fn($l) => (int)$l['request_id'] === $rid);
        $sum = followup_sum_add($sum, followup_apply($db, 'supplier', $c, $scope + ['only' => [$rid]], $confirm, $source, $today));
    }
    return $sum;
}

// ── CK findings (booking check before the CK) ────────────────────────────────

/** Passport numbers never stored: "passport no. YA1234567" / "passport: …" → "[removed]". */
function followup_strip_passport(string $s): string {
    return preg_replace('/(pass(?:port|aporto)\w*\s*(?:no\.?|n\.?|nr\.?|number|numero|#)?\s*[:#]?\s*)[A-Z]{0,3}\d[A-Z0-9]{4,}/iu', '$1[removed]', $s);
}

/** Label of an issue code (supplier / ticket / group / ck / SafariCheck; an unknown ck code is shown as written). */
function followup_issue_label(string $issue): string {
    return FU_ISSUE_LABELS[$issue] ?? FU_CK_ISSUE_LABELS[$issue] ?? (defined('CK_SC_ISSUE_LABELS') ? CK_SC_ISSUE_LABELS[$issue] ?? null : null)
        ?? ($issue === 'manual' ? 'Added by hand' : ucfirst(str_replace('_', ' ', $issue)));
}

/**
 * The findings of one booking check replace that booking's open ck lines: new ones are added with their
 * default owner (booking → the CK folder's booking owner, sales → its CK owner, accounts → Roberto), the
 * ones still reported are updated (document fields only), the ones not reported any more close as
 * auto_resolved; a line staff closed as resolved and still reported re-opens.
 * One list: a finding about a service another check already has open (supplier scan, SafariCheck…) is merged into
 * that line (`merged`: its id, owner and sources — no new line, its owner is kept); when the check no longer reports
 * it, claude_check leaves the line's sources (`cleared`) and the line closes only if no other check reports it.
 * $findings[] {audience, issue, service_date?, service, supplier?, text, source_file?, service_type?};
 * $meta {checked_at?, report_path?}. $ckRow: ck_tracker_rows() row of the booking (ck_tracker_row_for()).
 * → ['ok', 'dry_run', 'would_add'|'added', 'would_merge'|'merged', 'would_update'|'updated', 'would_close'|'closed',
 *    'would_clear'|'cleared', 'reopened', 'unchanged', 'owners', 'errors'] (each line with owner username);
 *    ['ok' => false, 'error'] on bad input.
 */
function followup_ck_upsert(PDO $db, int $requestId, array $ckRow, array $findings, array $meta, bool $confirm, ?string $today = null): array {
    followup_schema($db);
    ck_ensure_schema($db);
    if ($confirm) followup_backfill($db);
    $today = $today ?? date('Y-m-d');
    $owners = ck_owners_resolve($db, $ckRow);
    $acc = ck_user_find($db, FU_ACCOUNTS_OWNER);
    $ownerOf = ['booking' => $owners['booking_owner']['user_id'] ?? null, 'sales' => $owners['ck_owner']['user_id'] ?? null,
                'accounts' => $acc ? (int)$acc['id'] : null];
    $date = fn($v) => ($v && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$v)) ? (string)$v : null;
    $report = trim((string)($meta['report_path'] ?? ''));
    $cand = []; $errors = [];
    foreach ($findings as $i => $f) {
        if (!is_array($f)) { $errors[] = ['finding' => $i, 'error' => 'not an object']; continue; }
        $aud = (string)($f['audience'] ?? '');
        $issue = strtolower(trim((string)($f['issue'] ?? '')));
        $text = trim(followup_strip_passport((string)($f['text'] ?? '')));
        if (!in_array($aud, FU_AUDIENCES, true)) { $errors[] = ['finding' => $i, 'error' => 'audience must be ' . implode(', ', FU_AUDIENCES)]; continue; }
        if (!preg_match('/^[a-z0-9_]{2,40}$/', $issue)) { $errors[] = ['finding' => $i, 'error' => 'issue: short code, a-z 0-9 _ (e.g. ' . implode(', ', array_keys(FU_CK_ISSUE_LABELS)) . ')']; continue; }
        if ($text === '') { $errors[] = ['finding' => $i, 'error' => 'text is required (what is wrong / what to do)']; continue; }
        $stype = strtolower(trim((string)($f['service_type'] ?? '')));
        if ($stype !== '' && !in_array($stype, FU_SERVICE_TYPES, true)) { $errors[] = ['finding' => $i, 'error' => 'service_type must be ' . implode(', ', FU_SERVICE_TYPES)]; continue; }
        $svc = mb_substr(trim(followup_strip_passport((string)($f['service'] ?? ''))), 0, 255);
        $sd = $date($f['service_date'] ?? null);
        $key = followup_key_fit('CK|' . $requestId . '|' . $aud . '|' . $issue . '|' . ($sd ?? '') . '|' . substr(followup_norm($svc), 0, 80));
        if (isset($cand[$key])) { $errors[] = ['finding' => $i, 'error' => 'same audience / issue / service_date / service as finding ' . $cand[$key]['_i'] . ' — merged into it']; continue; }
        $cand[$key] = ['_i' => $i, 'type' => 'ck', 'audience' => $aud, 'dedupe_key' => $key, 'request_id' => $requestId,
                       'folder' => $ckRow['folder_name'], 'start_date' => $ckRow['start_date'], 'end_date' => $ckRow['end_date'],
                       'service_date' => $sd, 'supplier' => mb_substr(trim((string)($f['supplier'] ?? '')), 0, 150) ?: null,
                       'supplier_ref' => null, 'service' => $svc !== '' ? $svc : null,
                       'source_file' => mb_substr(trim((string)($f['source_file'] ?? '')), 0, 500) ?: null,
                       'issue' => $issue, 'doc_status_text' => mb_substr($text, 0, 500), 'expiry_date' => $date($f['expiry_date'] ?? null),
                       'booked_by' => null, 'details' => $report !== '' ? json_encode(['report_path' => $report], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                       'owner_user_id' => $ownerOf[$aud]] + ($stype !== '' ? ['service_type' => $stype] : []);
    }
    if ($errors && count($errors) === count($findings)) return ['ok' => false, 'error' => 'No valid finding', 'errors' => $errors];
    foreach ($cand as &$c) unset($c['_i']);
    unset($c);
    $sum = followup_apply($db, 'ck', $cand, ['requests' => [$requestId], 'only' => [$requestId]], $confirm, 'agent', $today);
    if ($confirm) {
        $at = trim((string)($meta['checked_at'] ?? ''));
        $db->prepare("UPDATE ck_folders SET findings_checked_at = ?, findings_report_path = ? WHERE id = ?")
           ->execute([preg_match('/^\d{4}-\d{2}-\d{2}/', $at) ? substr(str_replace('T', ' ', $at), 0, 19) : date('Y-m-d H:i:s'),
                      $report !== '' ? mb_substr($report, 0, 500) : null, (int)$ckRow['id']]);
    }
    // Owner of each line as username: the default for a new line, the current one for an existing line.
    $users = ck_users($db);
    $cur = [];
    $st = $db->prepare("SELECT id, owner_user_id FROM booking_followups WHERE request_id = ?");   // merged lines: any type
    $st->execute([$requestId]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $cur[(int)$r['id']] = $r['owner_user_id'];
    $withOwner = fn(array $list) => array_map(function ($b) use ($users, $cur) {
        $oid = isset($b['id']) ? ($cur[$b['id']] ?? null) : ($b['owner_user_id'] ?? null);
        unset($b['owner_user_id']);
        return $b + ['owner' => $oid && isset($users[(int)$oid]) ? $users[(int)$oid]['username'] : null];
    }, $list);
    $p = $confirm ? '' : 'would_';
    return ['ok' => true, 'dry_run' => !$confirm, 'request_id' => $requestId, 'ck_folder_id' => (int)$ckRow['id'], 'folder' => $ckRow['folder_name'],
            $p . 'add' => $withOwner($sum['added']), ($confirm ? 'merged' : 'would_merge') => $withOwner($sum['merged']), $p . 'update' => $withOwner($sum['updated']),
            $p . 'close' => $withOwner($sum['closed']), ($confirm ? 'cleared' : 'would_clear') => $withOwner($sum['cleared']), $p . 'reopen' => $withOwner($sum['reopened']),
            'unchanged' => $sum['unchanged'],
            'owners' => ['booking' => $owners['booking_owner']['username'] ?? null, 'sales' => $owners['ck_owner']['username'] ?? null,
                         'accounts' => $acc['username'] ?? null],
            'errors' => $errors];
}

/**
 * Open lines per request: [request_id => ['ck' => n, 'supplier' => n, 'ticket' => n, 'group' => n, 'safaricheck' => n,
 * 'manual' => n, 'total' => n, 'audience' => {booking, sales, accounts} (all types), 'ck_audience' => {…} (ck lines)]].
 */
function followup_open_counts(PDO $db): array {
    followup_schema($db);
    $out = [];
    foreach ($db->query("SELECT request_id, type, COALESCE(audience, 'booking') AS aud, COUNT(*) AS n FROM booking_followups
                         WHERE closed_at IS NULL AND request_id IS NOT NULL GROUP BY request_id, type, aud")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $rid = (int)$r['request_id'];
        $out[$rid] = $out[$rid] ?? followup_open_zero();
        $out[$rid][$r['type']] += (int)$r['n'];
        $out[$rid]['total'] += (int)$r['n'];
        $out[$rid]['audience'][$r['aud']] += (int)$r['n'];
        if ($r['type'] === 'ck') $out[$rid]['ck_audience'][$r['aud']] += (int)$r['n'];
    }
    return $out;
}

function followup_open_zero(): array {
    $aud = ['booking' => 0, 'sales' => 0, 'accounts' => 0];
    return array_fill_keys(FU_TYPES, 0) + ['total' => 0, 'audience' => $aud, 'ck_audience' => $aud];
}

/** The open counts of a CK tracker row (all its requests summed). */
function followup_open_for_row(array $counts, array $ckRow): array {
    $s = followup_open_zero();
    foreach ($ckRow['reqs'] as $q) {
        $c = $counts[(int)$q['id']] ?? null;
        if (!$c) continue;
        foreach (array_merge(FU_TYPES, ['total']) as $k) $s[$k] += $c[$k];
        foreach (['audience', 'ck_audience'] as $g) foreach ($c[$g] as $a => $n) $s[$g][$a] += $n;
    }
    return $s;
}

/**
 * Mail drafts of the open ck lines, one per owner, in English. Read-only: nothing is sent (Cowork turns
 * each into an .eml for Roberto). $f: request_ids [] or since (Y-m-d: lines seen by a check since then).
 * → [{to_user, to_name, to_email, subject, html, text, lines[]}] (lines without an owner: to_user null).
 */
function followup_ck_drafts(PDO $db, array $f, ?string $today = null): array {
    $today = $today ?? date('Y-m-d');
    $ids = array_values(array_filter(array_map('intval', (array)($f['request_ids'] ?? []))));
    $rows = array_filter(followup_rows($db, ['status' => 'open', 'type' => 'ck'], $today), function ($r) use ($ids, $f) {
        if ($ids && !in_array((int)$r['request_id'], $ids, true)) return false;
        if (!empty($f['since']) && substr((string)$r['last_seen_at'], 0, 10) < $f['since']) return false;
        return true;
    });
    $users = ck_users($db);
    $by = [];
    foreach ($rows as $r) $by[(int)$r['owner_user_id']][] = $r;
    $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $page = 'https://hub.savannahexplorers.com/modules/operations/booking_followup.php';
    $out = [];
    foreach ($by as $oid => $lines) {
        $u = $oid ? ($users[$oid] ?? null) : null;
        $first = $u ? strtok((string)($u['full_name'] ?: $u['username']), ' ') : 'team';
        usort($lines, fn($a, $b) => [$a['start_date'] ?? '9999', $a['folder'], $a['service_date'] ?? ''] <=> [$b['start_date'] ?? '9999', $b['folder'], $b['service_date'] ?? '']);
        $book = [];
        foreach ($lines as $l) $book[$l['folder']][] = $l;
        $html = '<p>Dear ' . $e($first) . ',</p><p>the booking check before the CK found these open points. '
              . 'Please fix them and update the line in Booking follow-up (status / note) when done.</p>';
        $text = "Dear $first,\n\nthe booking check before the CK found these open points. Please fix them and update the line in Booking follow-up (status / note) when done.\n";
        foreach ($book as $folder => $ls) {
            $l0 = $ls[0];
            $title = ($l0['customer'] ?: $l0['folder_name']) . ($l0['start_date'] ? ' — arrival ' . date('d M Y', strtotime($l0['start_date'])) : '');
            $html .= '<h3 style="font-size:14px;margin:16px 0 4px">' . $e($title) . '</h3><p style="margin:0 0 6px;color:#666;font-size:12px">' . $e($folder) . '</p><ul>';
            $text .= "\n" . $title . "\n" . $folder . "\n";
            foreach ($ls as $l) {
                $what = trim(($l['service_date'] ? date('d M', strtotime($l['service_date'])) . ' · ' : '') . ($l['service'] ?: $l['supplier'] ?: ''));
                $html .= '<li><b>' . $e($l['issue_label']) . '</b>' . ($what !== '' ? ' — ' . $e($what) : '') . ': ' . $e($l['doc_status_text'])
                       . ($l['source_file'] ? ' <span style="color:#666">(' . $e($l['source_file']) . ')</span>' : '') . '</li>';
                $text .= '- ' . $l['issue_label'] . ($what !== '' ? ' — ' . $what : '') . ': ' . $l['doc_status_text'] . ($l['source_file'] ? ' (' . $l['source_file'] . ')' : '') . "\n";
            }
            $html .= '</ul>';
            if (!empty($l0['report_url'])) { $html .= '<p style="font-size:12px"><a href="' . $e($l0['report_url']) . '">Booking check report</a></p>'; $text .= 'Report: ' . $l0['report_url'] . "\n"; }
        }
        $html .= '<p><a href="' . $page . '">Open Booking follow-up</a></p><p>Thank you,<br>Roberto</p>';
        $text .= "\nBooking follow-up: $page\n\nThank you,\nRoberto\n";
        $n = count($lines); $nb = count($book);
        $out[] = ['to_user' => $u['username'] ?? null, 'to_name' => $u ? ($u['full_name'] ?: $u['username']) : null, 'to_email' => $u['email'] ?? null,
                  'subject' => 'CK check: ' . $n . ' open point' . ($n === 1 ? '' : 's') . ' on ' . $nb . ' booking' . ($nb === 1 ? '' : 's')
                             . ($nb === 1 ? ' — ' . ($lines[0]['customer'] ?: $lines[0]['folder_name']) : ''),
                  'html' => '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#222;line-height:1.4">' . $html . '</div>',
                  'text' => $text,
                  'lines' => array_map(fn($l) => ['id' => $l['id'], 'request_id' => $l['request_id'], 'folder' => $l['folder'], 'audience' => $l['audience'],
                                                  'issue' => $l['issue'], 'service_date' => $l['service_date'], 'service' => $l['service'],
                                                  'text' => $l['doc_status_text'], 'staff_status' => $l['staff_status']], $lines)];
    }
    usort($out, fn($a, $b) => [$a['to_user'] === null, $a['to_name']] <=> [$b['to_user'] === null, $b['to_name']]);
    return $out;
}

// ── SafariCheck points and manual lines ──────────────────────────────────────

/** dedupe_key of a SafariCheck point (its confirmation key, stable while the point says the same thing). */
function followup_sc_key(int $requestId, string $scKey): string {
    return followup_key_fit('SC|' . $requestId . '|' . substr(followup_norm($scKey), 0, 150));
}

/** The request owning a CK folder's lines: the first one (a GRP's group lines belong to its first request too). */
function followup_ck_row_request(array $ckRow): ?int {
    return $ckRow['reqs'] ? min(array_map(fn($q) => (int)$q['id'], $ckRow['reqs'])) : null;
}

/**
 * A SafariCheck result → the booking's safaricheck lines: the booking points (ck_sc_booking_points(): stays, transfers,
 * flights, dates, pax — red / yellow, not ticked) replace the folder's open safaricheck lines like a booking check
 * (new ones added or merged into the same service's line, the ones no longer reported leave / close as auto_resolved).
 * Naming and document points stay in the CK tracker only. $checks / $facts: the result's checks_json / facts_json.
 */
function followup_safaricheck_sync(PDO $db, array $ckRow, array $checks, array $facts, bool $confirm = true, ?string $today = null): array {
    $rid = followup_ck_row_request($ckRow);
    if (!$rid || $ckRow['other']) return followup_sum_init();
    $cand = [];
    foreach (ck_sc_booking_points($checks, $facts) as $p) {
        $key = followup_sc_key($rid, $p['key']);
        $cand[$key] = ['type' => 'safaricheck', 'audience' => 'booking', 'dedupe_key' => $key, 'request_id' => $rid,
                       'folder' => $ckRow['folder_name'], 'start_date' => $ckRow['start_date'], 'end_date' => $ckRow['end_date'],
                       'service_date' => $p['service_date'], 'supplier' => $p['supplier'] ? mb_substr($p['supplier'], 0, 150) : null,
                       'supplier_ref' => null, 'service' => mb_substr($p['title'], 0, 255), 'source_file' => null, 'issue' => $p['issue'],
                       'doc_status_text' => mb_substr(trim(preg_replace('/\s+/', ' ', $p['detail'] !== '' ? $p['detail'] : $p['title'])), 0, 500),
                       'expiry_date' => null, 'booked_by' => null, 'service_type' => $p['service_type'],
                       'details' => json_encode(['sc_key' => $p['key'], 'sc_status' => $p['status'], 'section' => $p['section'],
                                                 'check_id' => !empty($ckRow['last_check_id']) ? (int)$ckRow['last_check_id'] : null], JSON_UNESCAPED_UNICODE)];
    }
    return followup_apply($db, 'safaricheck', $cand, ['requests' => [$rid], 'only' => [$rid]], $confirm, 'agent', $today ?? date('Y-m-d'));
}

/**
 * A SafariCheck point ticked "correct / confirmed" (ck_confirm.php): SafariCheck leaves its line's sources and the line
 * closes (auto_resolved) when no other check reports it. → number of lines changed.
 */
function followup_safaricheck_confirmed(PDO $db, array $ckRow, string $scKey, string $by): int {
    followup_schema($db);
    $rid = followup_ck_row_request($ckRow);
    if (!$rid) return 0;
    $key = followup_sc_key($rid, $scKey);
    $st = $db->prepare("SELECT * FROM booking_followups WHERE closed_at IS NULL AND request_id = ? AND (dedupe_key = ? OR sources LIKE ?)");
    $st->execute([$rid, $key, '%"safaricheck"%']);
    $n = 0;
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $l) {
        $src = followup_sources($l);
        if (($src['safaricheck']['key'] ?? null) !== $key) continue;
        unset($src['safaricheck']);
        if ($src) {
            $db->prepare("UPDATE booking_followups SET sources = ? WHERE id = ?")->execute([followup_sources_json($src), (int)$l['id']]);
            followup_event($db, (int)$l['id'], null, 'source', 'safaricheck', 'confirmed in SafariCheck by ' . $by . ' — still reported by ' . implode(', ', array_keys($src)));
        } else {
            $db->prepare("UPDATE booking_followups SET closed_at = ?, closed_by = 'system', close_reason = 'auto_resolved', sources = '{}' WHERE id = ?")
               ->execute([date('Y-m-d H:i:s'), (int)$l['id']]);
            followup_event($db, (int)$l['id'], null, 'closed', null, 'confirmed in SafariCheck by ' . $by);
        }
        $n++;
    }
    return $n;
}

/**
 * A line added by hand from the page (a problem found by phone …): request_id, audience, text, due_date?, service?,
 * supplier?, service_date?, owner? (default: the audience's default owner). Closed by staff only.
 * Who: the booking team (module booking_followup_edit) or admin. → ['ok', 'row'] or ['ok' => false, 'error', 'code'].
 */
function followup_manual_add(PDO $db, array $user, array $in, bool $confirm = true): array {
    followup_schema($db);
    if (!followup_can_edit($user)) return ['ok' => false, 'error' => 'Only the booking team can add lines (module ' . FU_EDIT_PERMISSION . ')', 'code' => 403];
    $rid = (int)($in['request_id'] ?? 0);
    $st = $db->prepare("SELECT id, customer_name, practice_code, group_folder, dropbox_url, start_date FROM requests WHERE id = ?");
    $st->execute([$rid]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) return ['ok' => false, 'error' => 'Request #' . $rid . ' not found', 'code' => 404];
    $aud = (string)($in['audience'] ?? 'booking');
    if (!in_array($aud, FU_AUDIENCES, true)) return ['ok' => false, 'error' => 'audience must be ' . implode(', ', FU_AUDIENCES), 'code' => 400];
    $text = trim(followup_strip_passport((string)($in['text'] ?? '')));
    if ($text === '') return ['ok' => false, 'error' => 'Write what is wrong / what to do', 'code' => 400];
    $date = fn($v) => ($v && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$v)) ? (string)$v : null;
    $due = $date($in['due_date'] ?? null);
    $rel = folder_rel_path($r);
    $folder = followup_folder_norm($rel !== '' ? (stripos($rel, '001_Safari/') === 0 ? $rel : '/' . $rel) : '');
    $d = parse_folder_dates(basename($rel));
    $owner = null;
    if (!empty($in['owner'])) {
        $u = ck_user_find($db, $in['owner']);
        if (!$u) return ['ok' => false, 'error' => 'User "' . $in['owner'] . '" not found', 'code' => 400];
        $owner = (int)$u['id'];
    } else {
        $owner = followup_default_owner($db, $rid, $aud);
    }
    $key = followup_key_fit('M|' . $rid . '|' . date('YmdHis') . '|' . substr(sha1($text . microtime()), 0, 10));
    $svc = mb_substr(trim((string)($in['service'] ?? '')), 0, 255);
    $line = ['type' => 'manual', 'audience' => $aud, 'dedupe_key' => $key, 'request_id' => $rid, 'folder' => $folder !== '' ? $folder : null,
             'start_date' => $d['start_date'] ?: $r['start_date'], 'end_date' => $d['end_date'] ?: null,
             'service_date' => $date($in['service_date'] ?? null), 'supplier' => mb_substr(trim((string)($in['supplier'] ?? '')), 0, 150) ?: null,
             'supplier_ref' => null, 'service' => $svc !== '' ? $svc : null, 'source_file' => null, 'issue' => 'manual',
             'doc_status_text' => mb_substr($text, 0, 500), 'expiry_date' => null, 'booked_by' => null, 'owner_user_id' => $owner,
             'details' => json_encode(array_filter(['due_date' => $due, 'added_by' => $user['full_name'] ?? $user['username'] ?? null]), JSON_UNESCAPED_UNICODE)];
    $sum = followup_apply($db, 'manual', [$key => $line], ['only' => [$rid]], $confirm, 'page', date('Y-m-d'));
    if (!$confirm) return ['ok' => true, 'dry_run' => true, 'would_add' => $sum['added']];
    $st = $db->prepare("SELECT id FROM booking_followups WHERE dedupe_key = ?");
    $st->execute([$key]);
    $id = (int)$st->fetchColumn();
    if ($id && !empty($user['id'])) {   // "created" by the user, not by the check
        $db->prepare("UPDATE booking_followup_events SET user_id = ? WHERE followup_id = ? AND field = 'created'")->execute([(int)$user['id'], $id]);
    }
    return ['ok' => true, 'dry_run' => false, 'row' => followup_rows($db, ['ids' => [$id], 'status' => 'all'])[0] ?? null];
}

/** dropbox_folder_stem() without loading the Dropbox helper first. */
function dropbox_folder_stem_safe(string $name): string {
    require_once __DIR__ . '/../../leads/dropbox_helper.php';
    return dropbox_folder_stem($name);
}

// ── Reading the lines ────────────────────────────────────────────────────────

/**
 * '1-URGENT' | '2-HIGH' | '3-NORMAL' from the line's deadline (followup_due()): overdue or ≤ 7 days → urgent,
 * ≤ 30 days → high, later (or no date at all) → normal.
 */
function followup_priority(array $l, string $today): string {
    $due = followup_due($l)['due_date'];
    if (!$due) return '3-NORMAL';
    $days = (int)round((strtotime($due) - strtotime($today)) / 86400);
    return $days <= FU_DUE_URGENT ? '1-URGENT' : ($days <= FU_DUE_HIGH ? '2-HIGH' : '3-NORMAL');
}

/** Dropbox web link of a path under /home. */
function followup_dropbox_url(string $path, ?string $previewFile = null): string {
    $enc = implode('/', array_map('rawurlencode', array_filter(explode('/', trim($path, '/')), 'strlen')));
    return 'https://www.dropbox.com/home/' . $enc . ($previewFile !== null ? '?preview=' . rawurlencode($previewFile) : '');
}

/**
 * Lines with owner / customer / priority. $f: type, audience (booking includes the lines without one),
 * status open (default) | closed | all, owner (user id, username or 'none'), priority, request_id (+ its GRP's lines), q, ids,
 * source (a check in `sources`: supplier_scan, ticket_scan, group_scan, safaricheck, claude_check, manual),
 * due_before (Y-m-d: deadline on or before).
 */
function followup_rows(PDO $db, array $f = [], ?string $today = null): array {
    followup_schema($db);
    $today = $today ?? date('Y-m-d');
    $w = []; $a = [];
    $status = $f['status'] ?? 'open';
    if ($status === 'open') $w[] = 'b.closed_at IS NULL';
    elseif ($status === 'closed') $w[] = 'b.closed_at IS NOT NULL';
    if (!empty($f['type']) && in_array($f['type'], FU_TYPES, true)) { $w[] = 'b.type = ?'; $a[] = $f['type']; }
    if (!empty($f['audience']) && in_array($f['audience'], FU_AUDIENCES, true)) { $w[] = "COALESCE(b.audience, 'booking') = ?"; $a[] = $f['audience']; }
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
    $found = $st->fetchAll(PDO::FETCH_ASSOC);
    $log = followup_notes_log($db, array_map(fn($r) => (int)$r['id'], $found));
    $out = [];
    foreach ($found as $r) {
        $r = followup_out($r, $today) + ['notes_log' => $log[(int)$r['id']] ?? []];
        if (!empty($f['priority']) && $r['priority'] !== $f['priority'] && substr($r['priority'], 0, 1) !== (string)$f['priority']) continue;
        if (!empty($f['source']) && !in_array($f['source'], array_column($r['sources'], 'source'), true)) continue;
        if (!empty($f['due_before']) && (!$r['due_date'] || $r['due_date'] > $f['due_before'])) continue;
        $out[] = $r;
    }
    // The booking-check report (sent with the ck lines) on every line of the booking.
    $rep = [];
    foreach ($out as $r) if ($r['report_url'] && $r['request_id'] && !isset($rep[$r['request_id']])) $rep[$r['request_id']] = [$r['report_url'], $r['report_open_url']];
    foreach ($out as &$r) if (!$r['report_url'] && isset($rep[$r['request_id']])) [$r['report_url'], $r['report_open_url']] = $rep[$r['request_id']];
    unset($r);
    usort($out, 'followup_sort');
    return $out;
}

/**
 * Notes written on the lines, newest first: [followup_id => [{at, by, text}]]. Each note saved is
 * an event (field notes); the line's `notes` column keeps the last one.
 */
function followup_notes_log(PDO $db, array $ids): array {
    if (!$ids) return [];
    $out = [];
    $st = $db->query("SELECT e.followup_id, e.at, e.new_value, u.full_name, u.username
                      FROM booking_followup_events e LEFT JOIN users u ON u.id = e.user_id
                      WHERE e.field = 'notes' AND e.followup_id IN (" . implode(',', array_map('intval', $ids)) . ")
                      ORDER BY e.at DESC, e.id DESC");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $e) {
        if (trim((string)$e['new_value']) === '') continue;
        $out[(int)$e['followup_id']][] = ['at' => $e['at'], 'by' => $e['full_name'] ?: $e['username'] ?: 'system', 'text' => $e['new_value']];
    }
    return $out;
}

/** Public shape of a line (page JSON and Agent API). */
function followup_out(array $r, string $today): array {
    $svc = $r['service_date'] ?: $r['start_date'];
    $dir = followup_folder_dir((string)$r['folder']);
    $open = fn(string $p) => 'savannah://open?path=' . implode('/', array_map('rawurlencode', explode('/', ltrim($p, '/'))));
    $details = !empty($r['details']) ? json_decode((string)$r['details'], true) : null;
    // ck lines: the folder's booking-check.html (path under the folder, or a full Dropbox / local path).
    $report = null;
    if ($r['type'] === 'ck' && !empty($details['report_path'])) {
        $rp = str_replace('\\', '/', (string)$details['report_path']);
        if (preg_match('#(?:^|/)(001_Safari/.+)$#i', $rp, $m)) $rp = '/' . $m[1];
        elseif ($rp[0] !== '/') $rp = $dir . '/' . ltrim($rp, '/');
        $report = $rp;
    }
    $due = followup_due($r);
    $top = explode('/', ltrim((string)$r['folder'], '/'))[0];
    $sources = [];
    foreach ($r['closed_at'] === null || !empty($r['sources']) ? followup_sources($r) : [] as $t => $e) {
        $sources[] = ['source' => $t, 'label' => FU_SOURCE_LABELS[$t] ?? $t, 'issue' => $e['issue'] ?? null,
                      'issue_label' => followup_issue_label((string)($e['issue'] ?? '')), 'text' => $e['text'] ?? null, 'since' => $e['since'] ?? null];
    }
    return [
        'id' => (int)$r['id'], 'type' => $r['type'], 'audience' => $r['audience'] ?? 'booking',
        'request_id' => $r['request_id'] !== null ? (int)$r['request_id'] : null,
        'customer' => $r['customer_name'] ?? null, 'folder' => $r['folder'], 'folder_name' => basename((string)$r['folder']),
        'folder_url' => $r['folder'] ? followup_dropbox_url($dir) : null,
        // Same as the Hub's "📂 Open": Windows Explorer on the office PCs (tools/savannah-open).
        'folder_open_url' => $r['folder'] ? $open($dir) : null,
        'file_url' => $r['source_file'] ? followup_dropbox_url($dir . (dirname($r['source_file']) !== '.' ? '/' . dirname($r['source_file']) : ''),
                                                                basename($r['source_file'])) : null,
        // The document on the PC (handler opens PDF / Office files; an older handler selects it in Explorer).
        'file_open_url' => $r['folder'] && $r['source_file'] ? $open($dir . '/' . $r['source_file']) . '&open=file' : null,
        'start_date' => $r['start_date'], 'end_date' => $r['end_date'], 'service_date' => $r['service_date'],
        'days_left' => $svc ? (int)round((strtotime($svc) - strtotime($today)) / 86400) : null,
        'supplier' => $r['supplier'], 'supplier_ref' => $r['supplier_ref'], 'service' => $r['service'],
        'source_file' => $r['source_file'], 'issue' => $r['issue'], 'issue_label' => followup_issue_label((string)$r['issue']),
        'report_url' => $report ? followup_dropbox_url(dirname($report), basename($report)) : null,
        'report_open_url' => $report ? $open($report) . '&open=file' : null,
        'doc_status_text' => $r['doc_status_text'], 'expiry_date' => $r['expiry_date'],
        'expired' => $r['expiry_date'] !== null && $r['expiry_date'] < $today,
        'booked_by' => $r['booked_by'],
        'owner_user_id' => $r['owner_user_id'] !== null ? (int)$r['owner_user_id'] : null,
        'owner' => $r['owner_username'] ?? null, 'owner_name' => ($r['owner_name'] ?? null) ?: ($r['owner_username'] ?? null),
        'contacted_at' => $r['contacted_at'], 'staff_status' => $r['staff_status'], 'notes' => $r['notes'],
        'priority' => followup_priority($r, $today),
        'due_date' => $due['due_date'], 'due_reason' => $due['due_reason'],
        'due_days' => $due['due_date'] ? (int)round((strtotime($due['due_date']) - strtotime($today)) / 86400) : null,
        'service_type' => followup_service_type($r), 'service_key' => $r['service_key'] ?? null,
        // The checks reporting the line now (one line per service: supplier scan, SafariCheck, Claude check…).
        'sources' => $sources,
        // The booking's CK tracker row (search on the folder, status tags off).
        'ck_tracker_url' => $r['folder'] && $top !== '' && $top[0] !== '/' && strpos((string)$r['folder'], '/') !== 0
                            ? '../leads/ck_tracker.php?' . http_build_query(['q' => dropbox_folder_stem_safe($top)]) : null,
        'first_seen_at' => $r['first_seen_at'], 'last_seen_at' => $r['last_seen_at'],
        'closed_at' => $r['closed_at'], 'closed_by' => $r['closed_by'] === 'system' ? 'system' : (($r['closed_by_name'] ?? null) ?: $r['closed_by']),
        'close_reason' => $r['close_reason'], 'close_reason_label' => $r['close_reason'] ? FU_REASON_LABELS[$r['close_reason']] : null,
        'events_count' => (int)($r['events_count'] ?? 0), 'source' => $r['scan_source'],
        'details' => $details,
    ];
}

/** Priority (from the deadline), nearest deadline, not taken in charge, problems, service date. */
function followup_sort(array $a, array $b): int {
    return [$a['closed_at'] !== null, $a['priority'], $a['due_date'] ?? '9999', $a['owner_user_id'] !== null, $a['staff_status'] !== 'problem', $a['service_date'] ?? $a['start_date'] ?? '9999', $a['id']]
       <=> [$b['closed_at'] !== null, $b['priority'], $b['due_date'] ?? '9999', $b['owner_user_id'] !== null, $b['staff_status'] !== 'problem', $b['service_date'] ?? $b['start_date'] ?? '9999', $b['id']];
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
    $s = ['untaken' => 0, 'urgent' => 0, 'problem' => 0, 'expiring' => 0, 'tickets' => 0, 'groups' => 0, 'ck' => 0, 'merged' => 0, 'closed7' => count($closedRecent)];
    foreach ($open as $r) {
        if ($r['owner_user_id'] === null) $s['untaken']++;
        if ($r['priority'] === '1-URGENT') $s['urgent']++;
        if ($r['staff_status'] === 'problem') $s['problem']++;
        if ($r['expiry_date'] && !$r['expired'] && (strtotime($r['expiry_date']) - time()) / 86400 <= 7) $s['expiring']++;
        if ($r['issue'] === 'ticket_missing') $s['tickets']++;
        if ($r['issue'] === 'group_to_finalize') $s['groups']++;
        if (in_array($r['type'], ['ck', 'safaricheck'], true) || array_intersect(['claude_check', 'safaricheck'], array_column($r['sources'], 'source'))) $s['ck']++;
        if (count($r['sources']) > 1) $s['merged']++;
    }
    return $s;
}

/** Everything the page renders: open lines, closed ones (last 120 days), stats, runs, rights, staff. */
function followup_page_data(PDO $db, array $user): array {
    followup_backfill($db);
    $open   = followup_rows($db, ['status' => 'open']);
    $closed = array_values(array_filter(followup_rows($db, ['status' => 'closed']),
                                        fn($r) => $r['closed_at'] >= date('Y-m-d H:i:s', time() - 120 * 86400)));
    $recent = array_filter($closed, fn($r) => $r['closed_at'] >= date('Y-m-d H:i:s', time() - 7 * 86400));
    return ['rows' => $open, 'closed' => $closed, 'stats' => followup_stats($open, $recent), 'runs' => followup_last_runs($db),
            'me' => ['id' => (int)$user['id'], 'can_edit' => followup_can_edit($user), 'is_admin' => followup_is_admin($user)],
            'users' => followup_owner_users($db), 'source_labels' => FU_SOURCE_LABELS,
            'today' => date('Y-m-d')];
}

/** Who a line can be assigned to: every active Hub user, [{id, name}]. */
function followup_owner_users(PDO $db): array {
    return array_map(fn($u) => ['id' => (int)$u['id'], 'name' => $u['full_name'] ?: $u['username']],
                     $db->query("SELECT id, username, full_name FROM users WHERE is_active = 1 ORDER BY full_name, username")->fetchAll(PDO::FETCH_ASSOC));
}

// ── Staff changes ────────────────────────────────────────────────────────────

/**
 * Change a line as a Hub user. $ch: take (bool), owner (user id | username of an active user | null),
 * contacted_at (Y-m-d | ''), staff_status, notes, due_date (Y-m-d | '': lines added by hand only).
 * $user: current_user() shape (id, role_name, permissions).
 * Who: take / contacted / status / notes → the booking team (module booking_followup_edit) or the line's
 * current owner; reassign → admin or the current owner (a line nobody owns: also the booking team);
 * not_relevant → admin or the current owner.
 * resolved / not_relevant close the line (manual); another status on a closed line re-opens it.
 * → ['ok', 'changes' => [field => [old, new]], 'row'] or ['ok' => false, 'error']. Dry run: nothing written.
 */
function followup_update(PDO $db, int $id, array $ch, array $user, bool $confirm = true): array {
    followup_schema($db);
    $st = $db->prepare("SELECT * FROM booking_followups WHERE id = ?");
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return ['ok' => false, 'error' => 'Line ' . $id . ' not found', 'code' => 404];
    if (!followup_can_work($user, $row)) {
        return ['ok' => false, 'error' => 'You can work only the lines you own (or ask Roberto for the module ' . FU_EDIT_PERMISSION . ')', 'code' => 403];
    }
    $admin = followup_is_admin($user);
    $uid = (int)$user['id'];
    $isOwner = $row['owner_user_id'] !== null && (int)$row['owner_user_id'] === $uid;
    $new = [];

    if (!empty($ch['take'])) {
        if ($row['owner_user_id'] !== null && !$isOwner && !$admin) {
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
        // Reassign: admin or the current owner; a line nobody owns can be assigned by the booking team too.
        $changing = $oid !== ($row['owner_user_id'] !== null ? (int)$row['owner_user_id'] : null);
        if ($changing && !$admin && !$isOwner && !($row['owner_user_id'] === null && followup_can_edit($user))) {
            return ['ok' => false, 'error' => 'Only an admin or the current owner can reassign this line', 'code' => 403];
        }
        $new['owner_user_id'] = $oid;
    }
    if (array_key_exists('contacted_at', $ch)) {
        $d = trim((string)$ch['contacted_at']);
        if ($d !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) return ['ok' => false, 'error' => 'contacted_at must be YYYY-MM-DD', 'code' => 400];
        $new['contacted_at'] = $d ?: null;
    }
    if (array_key_exists('notes', $ch)) $new['notes'] = trim((string)$ch['notes']) ?: null;
    if (array_key_exists('due_date', $ch)) {
        $d = trim((string)$ch['due_date']);
        if ($row['type'] !== 'manual') return ['ok' => false, 'error' => 'The deadline of a line found by a check comes from the check — only lines added by hand take a date', 'code' => 400];
        if ($d !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) return ['ok' => false, 'error' => 'due_date must be YYYY-MM-DD', 'code' => 400];
        $det = json_decode((string)$row['details'], true) ?: [];
        if ($d !== '') $det['due_date'] = $d; else unset($det['due_date']);
        $new['details'] = json_encode($det, JSON_UNESCAPED_UNICODE);
        $due = followup_due(['details' => $new['details']] + $row);
        $new['due_date'] = $due['due_date'];
        $new['due_reason'] = $due['due_reason'];
    }
    if (array_key_exists('staff_status', $ch)) {
        $s = (string)$ch['staff_status'];
        if (!in_array($s, FU_STATUSES, true)) return ['ok' => false, 'error' => 'staff_status must be one of ' . implode(', ', FU_STATUSES), 'code' => 400];
        if ($s === 'not_relevant' && !$admin && !$isOwner && $row['staff_status'] !== 'not_relevant') {
            return ['ok' => false, 'error' => 'Only an admin or the current owner can close a line as not relevant', 'code' => 403];
        }
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
    foreach ($changes as $f => $v) {
        if (in_array($f, ['details', 'due_reason'], true)) continue;   // the deadline is logged as due_date
        followup_event($db, $id, $uid, $f === 'owner_user_id' ? 'owner' : $f, $v[0], $v[1]);
    }
    if ($close)  followup_event($db, $id, $uid, 'closed', null, 'manual — ' . $finalStatus);
    if ($reopen) followup_event($db, $id, $uid, 'reopened', $row['close_reason'], $finalStatus);
    return ['ok' => true, 'dry_run' => false, 'changes' => $changes, 'closed' => $close, 'reopened' => $reopen,
            'row' => followup_rows($db, ['ids' => [$id], 'status' => 'all'])[0] ?? null];
}

// ── Memo digest ──────────────────────────────────────────────────────────────

/**
 * What Roberto's morning digest lists: new lines since $since, problems, lines nobody took in
 * charge for > 2 days, provisional expiring ≤ 7 days, tickets missing ≤ 7 days.
 * Check findings (Claude check, SafariCheck) apart: ck_new (since $since), ck_old (open > 3 days), ck_soon (arrival < 60 days).
 * Bookings pipeline: urgent (every URGENT line — deadline overdue or ≤ 7 days) by owner name ('' = nobody);
 * the bookings blocked from the CK / needing a check come from ck_pipeline_digest().
 */
function followup_digest_data(PDO $db, ?string $since, ?string $today = null): array {
    $today = $today ?? date('Y-m-d');
    $open = followup_rows($db, ['status' => 'open'], $today);
    $d = ['new' => [], 'problem' => [], 'untaken' => [], 'expiring' => [], 'tickets' => [], 'ck_new' => [], 'ck_old' => [], 'ck_soon' => [],
          'urgent' => []];
    $in7 = date('Y-m-d', strtotime($today . ' +7 days'));
    $in60 = date('Y-m-d', strtotime($today . ' +60 days'));
    $old = date('Y-m-d H:i:s', time() - 2 * 86400);
    $old3 = date('Y-m-d H:i:s', time() - 3 * 86400);
    foreach ($open as $r) {
        if ($r['priority'] === '1-URGENT') $d['urgent'][(string)$r['owner_name']][] = $r;
        if ($r['type'] === 'ck' || $r['type'] === 'safaricheck') {
            if ($since && $r['first_seen_at'] > $since) $d['ck_new'][] = $r;
            elseif ($r['first_seen_at'] < $old3) $d['ck_old'][] = $r;
            if ($r['start_date'] && $r['start_date'] >= $today && $r['start_date'] < $in60) $d['ck_soon'][$r['folder']][] = $r;
            continue;
        }
        if ($since && $r['first_seen_at'] > $since) $d['new'][] = $r;
        if ($r['staff_status'] === 'problem') $d['problem'][] = $r;
        if ($r['owner_user_id'] === null && $r['first_seen_at'] < $old) $d['untaken'][] = $r;
        if ($r['type'] === 'supplier' && $r['expiry_date'] && $r['expiry_date'] >= $today && $r['expiry_date'] <= $in7) $d['expiring'][] = $r;
        if ($r['issue'] === 'ticket_missing' && $r['service_date'] && $r['service_date'] <= $in7) $d['tickets'][] = $r;
    }
    ksort($d['urgent']);
    return $d;
}
