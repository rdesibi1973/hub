<?php
/**
 * Booking follow-up — manual expiry when the supplier extends by mail (migration 081). Local checks, no MySQL, no Dropbox:
 * an in-memory SQLite database behind a small MySQL shim, Dropbox replaced by callables.
 *   C:/Utility/PHP/php.exe -d extension_dir=C:/Utility/PHP/ext -d extension=pdo_sqlite -d extension=mbstring tools/tests/followup_expiry_override_test.php
 * Real case: REMCO1108, Ngorongoro Uzuri P247/P248 — pro-forma of 19/10 expires 30/10/2026, hold confirmed by mail to 30/11/2026.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../../modules/operations/includes/followup_service.php';
require_once __DIR__ . '/../../modules/operations/includes/eml_sanitize.php';

$fail = 0;
$t = function (string $name, bool $ok, $got = null) use (&$fail) {
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $got === null ? '' : '  got: ' . json_encode($got, JSON_UNESCAPED_SLASHES)) . "\n";
    if (!$ok) $fail++;
};

/** MySQL → SQLite: the service's own DDL is skipped (tables made below), INFORMATION_SCHEMA "not available". */
class ShimPDO extends PDO {
    public function exec(string $sql): int|false {
        if (preg_match('/^\s*(CREATE TABLE IF NOT EXISTS|ALTER TABLE)/i', $sql)) return 0;
        return parent::exec($sql);
    }
    public function query(string $query, ?int $fetchMode = null, mixed ...$args): PDOStatement|false {
        if (stripos($query, 'INFORMATION_SCHEMA') !== false) throw new PDOException('no INFORMATION_SCHEMA here');
        if (preg_match('/^\s*SHOW COLUMNS FROM (\w+)/i', $query, $m)) $query = "SELECT name FROM pragma_table_info('" . $m[1] . "')";
        return $fetchMode === null ? parent::query($query) : parent::query($query, $fetchMode, ...$args);
    }
}
$db = new ShimPDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->sqliteCreateFunction('regexp', fn($p, $s) => (int)preg_match('/' . str_replace('/', '\/', (string)$p) . '/', (string)$s), 2);
$db->exec("CREATE TABLE booking_followups (id INTEGER PRIMARY KEY AUTOINCREMENT, type TEXT NOT NULL, audience TEXT, dedupe_key TEXT NOT NULL UNIQUE,
  request_id INT, folder TEXT, start_date TEXT, end_date TEXT, service_date TEXT, supplier TEXT, supplier_ref TEXT, service TEXT, source_file TEXT,
  issue TEXT NOT NULL, doc_status_text TEXT, expiry_date TEXT, expiry_override TEXT, expiry_override_by INT, expiry_override_at TEXT,
  expiry_override_proof TEXT, booked_by TEXT, owner_user_id INT, contacted_at TEXT, staff_status TEXT NOT NULL DEFAULT 'open', notes TEXT,
  first_seen_at TEXT NOT NULL, last_seen_at TEXT NOT NULL, closed_at TEXT, closed_by TEXT, close_reason TEXT, scan_source TEXT, details TEXT,
  sources TEXT, service_key TEXT, due_date TEXT, due_reason TEXT)");
$db->exec("CREATE TABLE booking_followup_events (id INTEGER PRIMARY KEY AUTOINCREMENT, followup_id INT, at TEXT, user_id INT, field TEXT, old_value TEXT, new_value TEXT)");
$db->exec("CREATE TABLE booking_followup_meta (k TEXT PRIMARY KEY, v TEXT)");
$db->exec("INSERT INTO booking_followup_meta VALUES ('reasons_3', 'x'), ('expiry_override', 'x')");
$db->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, full_name TEXT, is_active INT DEFAULT 1)");
$db->exec("INSERT INTO users VALUES (7, 'glady', 'Glady', 1), (9, 'seller', 'Seller', 1), (99, 'claude_agent', 'Claude Agent', 1)");
$db->exec("CREATE TABLE requests (id INTEGER PRIMARY KEY, customer_name TEXT, dropbox_url TEXT, group_folder TEXT)");
$db->exec("INSERT INTO requests VALUES (1108, 'Remco', NULL, NULL)");
$db->exec("CREATE TABLE ck_folders (id INTEGER PRIMARY KEY, folder_name TEXT, gone INT DEFAULT 0)");

$TODAY  = '2026-11-12';
$FOLDER = '11_25NOV_Remco(ABC-Lydia)_BALANCE';
$DIR    = '/001_Safari/' . $FOLDER;
$PROOF  = 'mails/2026-10-22_Uzuri_P247 extension to 30-11.eml';
$glady  = ['id' => 7, 'role_name' => 'booking', 'permissions' => [FU_EDIT_PERMISSION], 'followup_admin' => false];
$seller = ['id' => 9, 'role_name' => 'sales', 'permissions' => [], 'followup_admin' => false];
$agent  = ['id' => 99, 'role_name' => 'admin', 'permissions' => [FU_EDIT_PERMISSION], 'followup_admin' => true];
$files  = [$DIR . '/' . $PROOF => true];
$opt    = ['today' => $TODAY, 'dir' => $DIR, 'proof_exists' => fn($p) => isset($files[$p])];

$unit = ['request' => ['id' => 1108], 'folder' => $FOLDER, 'start_date' => '2026-11-25', 'end_date' => '2026-12-05'];
$doc = fn(string $file, ?string $expiry, string $status = 'provisional') => ['status' => $status, 'issue' => $status === 'confirmed' ? null : 'provisional',
    'source_file' => $file, 'supplier' => 'Ngorongoro Uzuri', 'supplier_ref' => 'P247/P248', 'service_date' => '2027-01-10',
    'doc_status_text' => $status === 'confirmed' ? 'Confirmed' : 'Provisional', 'expiry_date' => $expiry];
$scan = function (array $docs, string $today) use ($db, $unit) {
    $c = followup_supplier_candidates($unit, $docs, $today);
    return followup_apply($db, 'supplier', $c['lines'], ['requests' => [1108], 'confirmed' => $c['confirmed'], 'only' => [1108]], true, 'local', $today);
};
$line = function () use ($db) { return $db->query("SELECT * FROM booking_followups WHERE request_id = 1108")->fetch(PDO::FETCH_ASSOC); };
$out = fn(string $today) => followup_out($line() + ['customer_name' => 'Remco', 'events_count' => 0, 'expiry_override_by_name' => 'Glady'], $today);
$events = fn(string $field) => $db->query("SELECT old_value, new_value, user_id FROM booking_followup_events WHERE field = '" . $field . "' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

// ── The line as the scan creates it from the pro-forma of 19/10 (expires 30/10) ──
$s = $scan([$doc('invoices/Uzuri proforma P247 19-10.pdf', '2026-10-30')], $TODAY);
$t('scan creates the Uzuri line', count($s['added']) === 1, $s);
$db->exec("UPDATE booking_followups SET first_seen_at = '2026-10-20 08:00:00'");
$o = $out($TODAY);
$t('before: provisional expired', $o['issue'] === 'provisional_expired' && $o['expired'] === true, $o['issue']);
$t('before: urgent, 13 d late', $o['priority'] === '1-URGENT' && $o['due_date'] === '2026-10-30' && $o['due_days'] === -13, [$o['priority'], $o['due_date'], $o['due_days']]);
$id = (int)$line()['id'];

// ── Refusals ──
$r = followup_update($db, $id, ['expiry_override' => '2026-11-30'], $glady, true, $opt);
$t('saving without a proof is refused (400)', !$r['ok'] && $r['code'] === 400 && stripos($r['error'], 'proof') !== false, $r);
$r = followup_update($db, $id, ['expiry_override' => '2026-11-30', 'expiry_override_proof' => 'mails/missing.eml'], $glady, true, $opt);
$t('a proof that does not exist is refused (404)', !$r['ok'] && $r['code'] === 404, $r);
$r = followup_update($db, $id, ['expiry_override' => '2026-11-30', 'expiry_override_proof' => '/001_Safari/Other(X-Y)/mails/a.eml'], $glady, true, $opt);
$t('a proof outside the booking folder is refused', !$r['ok'] && $r['code'] === 400, $r);
$r = followup_update($db, $id, ['expiry_override' => '30/11/2026', 'expiry_override_proof' => $PROOF], $glady, true, $opt);
$t('a date not YYYY-MM-DD is refused', !$r['ok'] && $r['code'] === 400, $r);
$r = followup_update($db, $id, ['expiry_override' => '2026-11-30', 'expiry_override_proof' => $PROOF], $seller, true, $opt);
$t('a seller cannot set the expiry (403)', !$r['ok'] && $r['code'] === 403, $r);
$db->exec("UPDATE booking_followups SET owner_user_id = 9");
$r = followup_update($db, $id, ['expiry_override' => '2026-11-30', 'expiry_override_proof' => $PROOF], $seller, true, $opt);
$t('… not even as the line\'s owner (403)', !$r['ok'] && $r['code'] === 403, $r);
$db->exec("UPDATE booking_followups SET owner_user_id = NULL");
$t('nothing written by the refusals', $line()['expiry_override'] === null && !$events('expiry_override'));

// ── Agent API dry run (followup_update, confirm false): nothing written ──
$before = $line();
$nEv = (int)$db->query("SELECT COUNT(*) FROM booking_followup_events")->fetchColumn();
$r = followup_update($db, $id, ['expiry_override' => '2026-11-30', 'expiry_override_proof' => $DIR . '/' . $PROOF], $agent, false, $opt);
$t('dry run: ok, changes listed', $r['ok'] && $r['dry_run'] === true && ($r['changes']['expiry_override'][1] ?? null) === '2026-11-30', $r['changes'] ?? $r);
$t('dry run: full Dropbox path stored as relative', ($r['changes']['expiry_override_proof'][1] ?? null) === $PROOF, $r['changes']['expiry_override_proof'] ?? null);
$t('dry run writes nothing', $line() == $before && (int)$db->query("SELECT COUNT(*) FROM booking_followup_events")->fetchColumn() === $nEv);

// ── Set: 30/11/2026 with the proof ──
$r = followup_update($db, $id, ['expiry_override' => '2026-11-30', 'expiry_override_proof' => $PROOF], $glady, true, $opt);
$t('override saved', $r['ok'] && !$r['dry_run'], $r);
$l = $line();
$t('columns: date, by, at, proof', $l['expiry_override'] === '2026-11-30' && (int)$l['expiry_override_by'] === 7 && $l['expiry_override_at'] && $l['expiry_override_proof'] === $PROOF, $l);
$t('document expiry kept', $l['expiry_date'] === '2026-10-30');
$t('stored issue / deadline re-judged', $l['issue'] === 'provisional' && $l['due_date'] === '2026-11-30', [$l['issue'], $l['due_date']]);
$ev = $events('expiry_override');
$t('one history event, by Glady, with the proof', count($ev) === 1 && (int)$ev[0]['user_id'] === 7 && strpos($ev[0]['new_value'], '2026-11-30 (by mail) — proof: ' . $PROOF) === 0, $ev);
$o = $out($TODAY);
$t('REMCO1108/Uzuri no longer late', $o['issue'] === 'provisional' && $o['expired'] === false, [$o['issue'], $o['expired']]);
$t('priority from 30/11 (18 d → high)', $o['priority'] === '2-HIGH' && $o['due_date'] === '2026-11-30' && $o['due_days'] === 18, [$o['priority'], $o['due_date'], $o['due_days']]);
$t('deadline reason says by mail', strpos((string)$o['due_reason'], 'extended by mail') !== false, $o['due_reason']);
$t('output: effective + document expiry', $o['expiry_date'] === '2026-11-30' && $o['doc_expiry_date'] === '2026-10-30', [$o['expiry_date'], $o['doc_expiry_date']]);
$t('output: who, when, proof link', $o['expiry_override']['by'] === 'Glady' && $o['expiry_override']['proof_path'] === $DIR . '/' . $PROOF
   && strpos($o['expiry_override']['proof_url'], 'preview=2026-10-22_Uzuri_P247%20extension%20to%2030-11.eml') !== false, $o['expiry_override']);
$st = followup_stats([$o], []);
$t('stat card: not "expiring ≤ 7 days" (30/11 is 18 d away)', $st['expiring'] === 0 && $st['urgent'] === 0, $st);
$t('ck_pipeline: effective expiry', followup_effective_expiry($line()) === '2026-11-30');

// ── Supplier scan: same pro-forma again → the override stays ──
$s = $scan([$doc('invoices/Uzuri proforma P247 19-10.pdf', '2026-10-30')], $TODAY);
$l = $line();
$t('scan keeps the override', $l['expiry_override'] === '2026-11-30' && $l['expiry_override_proof'] === $PROOF && $l['issue'] === 'provisional', $l);
$t('scan keeps the deadline from the override', $l['due_date'] === '2026-11-30', $l['due_date']);
$s = $scan([$doc('invoices/Uzuri proforma P247 v2.pdf', '2026-11-20')], $TODAY);
$t('a newer document with expiry < override: override kept', $line()['expiry_override'] === '2026-11-30' && $line()['expiry_date'] === '2026-11-20', $line());

// ── Override passed: expired / urgent again ──
$o = $out('2026-12-01');
$t('after 30/11: provisional expired, urgent, 1 d late', $o['issue'] === 'provisional_expired' && $o['expired'] && $o['priority'] === '1-URGENT' && $o['due_days'] === -1,
   [$o['issue'], $o['priority'], $o['due_days']]);
$s = $scan([$doc('invoices/Uzuri proforma P247 v2.pdf', '2026-11-20')], '2026-12-01');
$t('scan after 30/11 stores provisional_expired (override kept)', $line()['issue'] === 'provisional_expired' && $line()['expiry_override'] === '2026-11-30', $line()['issue']);

// ── A new pro-forma expiring 15/12 clears the override, logged ──
$s = $scan([$doc('invoices/Uzuri proforma P247 v3.pdf', '2026-12-15')], $TODAY);
$l = $line();
$t('new pro-forma 15/12 clears the override', $l['expiry_override'] === null && $l['expiry_override_by'] === null && $l['expiry_override_proof'] === null, $l);
$t('… expiry now the document\'s', $l['expiry_date'] === '2026-12-15' && $l['issue'] === 'provisional' && $l['due_date'] === '2026-12-11', [$l['expiry_date'], $l['issue'], $l['due_date']]);
$ev = $events('expiry_override');
$t('event "override superseded by <file>"', end($ev)['new_value'] === 'override superseded by invoices/Uzuri proforma P247 v3.pdf (expires 2026-12-15)'
   && end($ev)['old_value'] === '2026-11-30' && end($ev)['user_id'] === null, end($ev));
$t('summary lists the change', in_array('expiry_override', $s['updated'][0]['changed'] ?? [], true), $s['updated']);

// ── Clear by hand ──
followup_update($db, $id, ['expiry_override' => '2026-12-31', 'expiry_override_proof' => $PROOF], $glady, true, $opt);
$r = followup_update($db, $id, ['expiry_override' => null], $glady, true, $opt);
$l = $line();
$t('cleared by hand', $r['ok'] && $l['expiry_override'] === null && $l['expiry_override_proof'] === null && $l['due_date'] === '2026-12-11', $l);
$ev = $events('expiry_override');
$t('… logged as cleared', strpos(end($ev)['new_value'], 'cleared') === 0, end($ev));

// ── Confirmed document closes the line (with an override standing) ──
followup_update($db, $id, ['expiry_override' => '2026-12-31', 'expiry_override_proof' => $PROOF], $glady, true, $opt);
$s = $scan([$doc('invoices/Uzuri confirmation P247.pdf', null, 'confirmed')], $TODAY);
$l = $line();
$t('confirmed document → auto_confirmed', $l['closed_at'] !== null && $l['close_reason'] === 'auto_confirmed', [$l['closed_at'], $l['close_reason']]);
$r = followup_update($db, $id, ['expiry_override' => '2027-01-05', 'expiry_override_proof' => $PROOF], $glady, true, $opt);
$t('no expiry on a closed line', !$r['ok'] && $r['code'] === 409, $r);

// ── .eml: sanitize, save, set expiry with an uploaded mail ──
$mail = "From: Uzuri Reservations <res@uzuri.example>\r\nTo: glady@savannahexplorers.com\r\nDate: Thu, 22 Oct 2026 10:15:00 +0300\r\n"
      . "Subject: RE: P247/P248 hold\r\nMIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"b1\"\r\n\r\npreamble\r\n"
      . "--b1\r\nContent-Type: text/plain; charset=utf-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\nDear Glady, we hold P247/P248 until 30/11/2026.=\r\n Guest passport no. YA1234567.\r\n"
      . "--b1\r\nContent-Type: text/html; charset=utf-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode('<p>Passport: XB7654321</p>'), 76, "\r\n")
      . "--b1\r\nContent-Type: application/pdf; name=\"Passport Rossi.pdf\"\r\nContent-Disposition: attachment; filename=\"Passport Rossi.pdf\"\r\nContent-Transfer-Encoding: base64\r\n\r\nJVBERi0xLjQK\r\n"
      . "--b1\r\nContent-Type: application/pdf; name=\"Uzuri proforma.pdf\"\r\nContent-Disposition: attachment; filename=\"Uzuri proforma.pdf\"\r\nContent-Transfer-Encoding: base64\r\n\r\nJVBERi0xLjUK\r\n"
      . "--b1--\r\nepilogue\r\n";
$c = eml_sanitize($mail);
$t('eml: passport attachment replaced', $c['removed'] === ['Passport Rossi.pdf'] && strpos($c['raw'], 'JVBERi0xLjQK') === false && strpos($c['raw'], 'removed by the Hub') !== false, $c['removed']);
$t('eml: other attachment kept byte for byte', strpos($c['raw'], "filename=\"Uzuri proforma.pdf\"\r\nContent-Transfer-Encoding: base64\r\n\r\nJVBERi0xLjUK\r\n") !== false);
$t('eml: numbers masked (qp + base64 parts)', $c['masked'] === 2 && strpos(quoted_printable_decode($c['raw']), 'YA1234567') === false
   && strpos($c['raw'], base64_encode('<p>Passport: XB7654321</p>')) === false, $c['masked']);
$t('eml: text still there, structure kept', strpos(quoted_printable_decode($c['raw']), 'until 30/11/2026') !== false
   && substr_count($c['raw'], "--b1\r\n") === 4 && strpos($c['raw'], "--b1--\r\nepilogue") !== false && strpos($c['raw'], "\r\n\r\npreamble") !== false);
$t('eml: a clean mail is unchanged', eml_sanitize("From: a@b\r\nSubject: hi\r\n\r\nhello\r\n")['raw'] === "From: a@b\r\nSubject: hi\r\n\r\nhello\r\n");
$t('eml name from the mail date', followup_eml_name(null, 'Ngorongoro Uzuri', 'P247/P248', '2026-11-30', followup_eml_date($mail))
   === '2026-10-22_Ngorongoro Uzuri_P247_P248 extension to 30-11.eml', followup_eml_name(null, 'Ngorongoro Uzuri', 'P247/P248', '2026-11-30', followup_eml_date($mail)));

$uploads = [];
$sopt = ['exists' => fn($p) => isset($files[$p]), 'upload' => function ($p, $content, $ow) use (&$uploads, &$files) { $uploads[$p] = $content; $files[$p] = true; return ['path_display' => $p]; }];
$r = followup_save_eml($DIR, 'x.eml', $mail, false, false, $sopt);
$t('save eml dry run: nothing uploaded', $r['ok'] && $r['dry_run'] && !$uploads && $r['proof'] === 'mails/x.eml', $r);
$r = followup_save_eml($DIR, 'x.eml', "not a mail", true, false, $sopt);
$t('save eml: not a mail refused', !$r['ok'] && $r['code'] === 400, $r);
$r = followup_save_eml($DIR, basename($PROOF), $mail, true, false, $sopt);
$t('save eml: existing file not overwritten (409)', !$r['ok'] && $r['code'] === 409 && !$uploads, $r);

// A second open line to set with an upload (page "Set expiry": upload + set in one call).
$db->exec("INSERT INTO booking_followups (type, dedupe_key, request_id, folder, start_date, end_date, service_date, supplier, supplier_ref, source_file, issue,
           expiry_date, first_seen_at, last_seen_at, staff_status) VALUES ('supplier', 'S|1108|P249', 1108, '$FOLDER', '2026-11-25', '2026-12-05', '2027-01-10',
           'Ngorongoro Uzuri', 'P247/P248', 'invoices/Uzuri proforma P249.pdf', 'provisional_expired', '2026-10-30', '2026-10-20', '2026-10-20', 'open')");
$id2 = (int)$db->lastInsertId();
$r = followup_set_expiry($db, $id2, ['date' => '2026-11-30', 'upload' => ['name' => '', 'content' => $mail]], $glady, true, $opt + $sopt);
$p2 = $DIR . '/mails/2026-10-22_Ngorongoro Uzuri_P247_P248 extension to 30-11.eml';
$t('set expiry with upload: saved into mails/', $r['ok'] && isset($uploads[$p2]) && strpos($uploads[$p2], 'YA1234567') === false, $r['error'] ?? array_keys($uploads));
$l2 = $db->query("SELECT * FROM booking_followups WHERE id = $id2")->fetch(PDO::FETCH_ASSOC);
$t('… and the override points to it', $l2['expiry_override'] === '2026-11-30' && $l2['expiry_override_proof'] === 'mails/2026-10-22_Ngorongoro Uzuri_P247_P248 extension to 30-11.eml', $l2);
$r = followup_set_expiry($db, $id2, ['date' => '2026-12-10', 'proof' => ''], $glady, true, $opt + $sopt);
$t('set expiry without proof refused', !$r['ok'] && $r['code'] === 400, $r);
$r = followup_set_expiry($db, $id2, ['date' => '2026-12-10', 'upload' => ['content' => $mail]], $seller, true, $opt + $sopt);
$t('set expiry by a seller refused', !$r['ok'] && $r['code'] === 403, $r);
$r = followup_proof_files($db, $id2, $glady, ['dir' => $DIR, 'list' => fn($p) => [['name' => 'b.eml', 'size' => 1, 'modified' => '2026-10-22T07:00:00Z'],
                                                                                  ['name' => 'scan.pdf', 'size' => 1, 'modified' => '2026-10-23T07:00:00Z']]]);
$t('mails/ listing: .eml only', $r['ok'] && count($r['files']) === 1 && $r['files'][0]['proof'] === 'mails/b.eml', $r);
$r = followup_proof_files($db, $id2, $seller, ['dir' => $DIR, 'list' => fn($p) => []]);
$t('mails/ listing refused to a seller', !$r['ok'] && $r['code'] === 403, $r);
// (A "followup_default_owner: … ck_checks" line on stderr is expected: no CK tables in this database.)

echo $fail ? "\n$fail FAILED\n" : "\nALL PASS\n";
exit($fail ? 1 : 0);
