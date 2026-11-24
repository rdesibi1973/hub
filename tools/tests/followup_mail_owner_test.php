<?php
/**
 * Booking follow-up — "✉ Mail" to the owner (followup_mail_owner(), 24 Nov 2026). Local checks, no MySQL, no mail server:
 * an in-memory SQLite database behind the same shim as followup_expiry_override_test.php, the send replaced by a callable.
 *   C:/Utility/PHP/php.exe -d extension_dir=C:/Utility/PHP/ext -d extension=pdo_sqlite -d extension=mbstring tools/tests/followup_mail_owner_test.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../../modules/operations/includes/followup_service.php';

$fail = 0;
$t = function (string $name, bool $ok, $got = null) use (&$fail) {
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $got === null ? '' : '  got: ' . json_encode($got, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . "\n";
    if (!$ok) $fail++;
};

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
$db->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, full_name TEXT, email TEXT, is_active INT DEFAULT 1)");
$db->exec("INSERT INTO users VALUES (1, 'rdesibi', 'Roberto De Sibi', 'roberto@example.test', 1), (7, 'glady', 'Glady', 'glady@example.test', 1),
           (9, 'seller', 'Seller', 'seller@example.test', 1), (12, 'nomail', 'No Mail', '', 1), (13, 'gone', 'Gone', 'gone@example.test', 0)");
$db->exec("CREATE TABLE requests (id INTEGER PRIMARY KEY, customer_name TEXT, dropbox_url TEXT, group_folder TEXT)");
$db->exec("INSERT INTO requests VALUES (3343, 'BandiniQuercioli', NULL, NULL)");
$db->exec("CREATE TABLE ck_folders (id INTEGER PRIMARY KEY, folder_name TEXT, gone INT DEFAULT 0)");
$db->exec("CREATE TABLE request_notes (id INTEGER PRIMARY KEY AUTOINCREMENT, request_id INT, created_by INT, note_type TEXT, subject TEXT, body TEXT, attachments TEXT)");
$db->prepare("INSERT INTO booking_followups (type, audience, dedupe_key, request_id, folder, start_date, service_date, supplier, issue, doc_status_text,
              owner_user_id, first_seen_at, last_seen_at, source_file) VALUES ('gate','booking','GT|1',3343,?, '2027-01-18','2027-01-24','Butiama Beach',
              'invoice_mismatch','BLOCKING — Invoice 2,176 vs quoted 1,120 (+1,056)', 7, '2026-11-24 10:16:43', '2026-11-24 10:16:43', 'invoices/BUATIAMA - INV.xls')")
   ->execute(['01_18JAN_BandiniQuercioli(EuphemiaLabTravel-PS-Roberto)_START18JAN_MIDT24JAN_END28JAN2027_DEPOSIT']);
$db->prepare("INSERT INTO booking_followups (type, audience, dedupe_key, request_id, folder, issue, doc_status_text, first_seen_at, last_seen_at)
              VALUES ('manual','booking','M|2',3343,'x','manual','No owner yet','2026-11-24','2026-11-24')")->execute();

$roberto = ['id' => 1, 'role_name' => 'admin', 'permissions' => [FU_EDIT_PERMISSION], 'followup_admin' => true];
$seller  = ['id' => 9, 'role_name' => 'sales', 'permissions' => [], 'followup_admin' => false];
$glady   = ['id' => 7, 'role_name' => 'booking', 'permissions' => [], 'followup_admin' => false];   // owner of line 1, no module
$sent = [];
$send = function (string $to, string $subj, string $body, string $fromName, string $fromEmail) use (&$sent) {
    $sent[] = compact('to', 'subj', 'body', 'fromName', 'fromEmail');
    return true;
};

// 1. Default recipient = the owner; sent as me; note saved with recipient, author = me.
$r = followup_mail_owner($db, 1, $roberto, ['message' => "Please check the Butiama rate with the lodge.\nPassport AB1234567"], $send);
$t('sent to the owner', $r['ok'] && $r['to']['id'] === 7 && $sent[0]['to'] === 'glady@example.test', $r);
$t('from the sender', ($sent[0]['fromEmail'] ?? '') === 'roberto@example.test' && $sent[0]['fromName'] === 'Roberto De Sibi', $sent[0] ?? null);
$t('default subject names client + issue', str_contains($sent[0]['subj'], 'BandiniQuercioli') && str_contains($sent[0]['subj'], 'Booking follow-up'), $sent[0]['subj']);
$t('body has message, line facts and link', str_contains($sent[0]['body'], 'Butiama rate') && str_contains($sent[0]['body'], 'Invoice 2,176')
   && str_contains($sent[0]['body'], 'booking_followup.php?request_id=3343'), null);
$ev = $db->query("SELECT user_id, new_value FROM booking_followup_events WHERE followup_id = 1 AND field = 'notes'")->fetch(PDO::FETCH_ASSOC);
$t('note saved by the sender', $ev && (int)$ev['user_id'] === 1 && str_starts_with($ev['new_value'], '✉ Mail to Glady <glady@example.test>'), $ev);
$t('note in notes_log of the returned row', ($r['row']['notes_log'][0]['by'] ?? '') === 'Roberto De Sibi' || str_contains((string)($r['row']['notes'] ?? ''), '✉ Mail to Glady'), $r['row']['notes_log'] ?? $r['row']['notes'] ?? null);
$t('passport-like text stripped from mail and note', !str_contains($sent[0]['body'], 'AB1234567') && !str_contains($ev['new_value'], 'AB1234567'), $ev['new_value']);
$rn = $db->query("SELECT request_id, created_by, note_type FROM request_notes")->fetch(PDO::FETCH_ASSOC);
$t('request note email_sent', $rn && (int)$rn['request_id'] === 3343 && (int)$rn['created_by'] === 1 && $rn['note_type'] === 'email_sent', $rn);

// 2. Another recipient, own subject; the owner working the line without the module may mail.
$r = followup_mail_owner($db, 1, $glady, ['to_user_id' => 9, 'subject' => "Update the Calc\r\nBcc: x@y", 'message' => 'Rate is 2,176: update the Calc'], $send);
$t('owner mails another user', $r['ok'] && end($sent)['to'] === 'seller@example.test', $r);
$t('subject on one line', !preg_match('/[\r\n]/', end($sent)['subj']), end($sent)['subj']);

// 3. Refusals.
$n = count($sent);
$r = followup_mail_owner($db, 1, $seller, ['message' => 'x'], $send);
$t('not owner, no module → 403', !$r['ok'] && $r['code'] === 403, $r);
$r = followup_mail_owner($db, 2, $roberto, ['message' => 'x'], $send);
$t('no owner, no recipient → 400', !$r['ok'] && $r['code'] === 400, $r);
$r = followup_mail_owner($db, 1, $roberto, ['message' => '  '], $send);
$t('empty message → 400', !$r['ok'] && $r['code'] === 400, $r);
$r = followup_mail_owner($db, 1, $roberto, ['to_user_id' => 12, 'message' => 'x'], $send);
$t('recipient without email → 400', !$r['ok'] && str_contains($r['error'], 'no email'), $r);
$r = followup_mail_owner($db, 1, $roberto, ['to_user_id' => 13, 'message' => 'x'], $send);
$t('inactive recipient → 400', !$r['ok'] && $r['code'] === 400, $r);
$r = followup_mail_owner($db, 99, $roberto, ['message' => 'x'], $send);
$t('unknown line → 404', !$r['ok'] && $r['code'] === 404, $r);
$t('nothing sent on refusals', count($sent) === $n, count($sent));
$notes = (int)$db->query("SELECT COUNT(*) FROM booking_followup_events WHERE field = 'notes'")->fetchColumn();
$r = followup_mail_owner($db, 1, $roberto, ['message' => 'fails'], fn() => false);
$t('send failure → no note', !$r['ok'] && $r['code'] === 502 && (int)$db->query("SELECT COUNT(*) FROM booking_followup_events WHERE field = 'notes'")->fetchColumn() === $notes, $r);

echo "\n" . ($fail ? $fail . ' FAILED' : 'ALL PASS') . "\n";
exit($fail ? 1 : 0);
