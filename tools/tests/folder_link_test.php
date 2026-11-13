<?php
/**
 * Stable folder link by Dropbox id (folder_link_service.php, migration 082). Local checks, no MySQL, no Dropbox:
 * in-memory SQLite behind a small MySQL shim, Dropbox replaced by an in-memory tree (path → id).
 *   C:/Utility/PHP/php.exe -d extension_dir=C:/Utility/PHP/ext -d extension=pdo_sqlite tools/tests/folder_link_test.php
 * Real case (13 Nov 2026): two cancelled 2027 folders moved by hand from /001_Safari to /001_Safari/00_CANCELED/00_2027.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// ── Dropbox stub: a tree of folders path → id ──────────────────────────────────
$DBX = [];          // lower-case path => ['path' => display path, 'id' => id]
$DBX_CALLS = 0;
function dbx_add(string $path, string $id): void { global $DBX; $DBX[strtolower($path)] = ['path' => $path, 'id' => $id]; }
function dbx_mv(string $from, string $to): void {   // move a folder and everything inside it
    global $DBX;
    $f = strtolower($from); $new = [];
    foreach ($DBX as $k => $v) {
        if ($k === $f || strpos($k, $f . '/') === 0) { $p = $to . substr($v['path'], strlen($from)); $new[strtolower($p)] = ['path' => $p, 'id' => $v['id']]; }
        else $new[$k] = $v;
    }
    $DBX = $new;
}
function dropbox_get_metadata(string $token, string $path): ?array {
    global $DBX, $DBX_CALLS; $DBX_CALLS++;
    if (strpos($path, 'id:') === 0) {
        foreach ($DBX as $v) if ($v['id'] === $path) return ['tag' => 'folder', 'name' => basename($v['path']), 'path' => $v['path'], 'id' => $v['id']];
        return null;
    }
    $v = $DBX[strtolower(rtrim($path, '/'))] ?? null;
    return $v ? ['tag' => 'folder', 'name' => basename($v['path']), 'path' => $v['path'], 'id' => $v['id']] : null;
}
function dropbox_path_exists(string $token, string $path): bool { return dropbox_get_metadata($token, $path) !== null; }
function dropbox_find_folder(string $token, string $name): ?string { return null; }
function dropbox_get_access_token(): string { return 'tok'; }
function dropbox_list_folder_entries(string $token, string $path): array {
    global $DBX; $out = []; $p = strtolower(rtrim($path, '/')) . '/';
    if (!isset($DBX[rtrim($p, '/')])) throw new RuntimeException('Dropbox list_folder failed (HTTP 409): path/not_found/');
    foreach ($DBX as $k => $v) if (strpos($k, $p) === 0 && strpos(substr($k, strlen($p)), '/') === false) $out[] = ['id' => $v['id'], 'name' => basename($v['path'])];
    return $out;
}
function dropbox_list_folder(string $token, string $path): array { return array_column(dropbox_list_folder_entries($token, $path), 'name'); }
function dropbox_create_folder(string $token, string $path, bool $throw = false): array { if (!dropbox_path_exists($token, $path)) dbx_add($path, 'id:new' . mt_rand()); return []; }
function dropbox_move_folder(string $token, string $from, string $to): array {
    if (!dropbox_path_exists($token, $from)) throw new RuntimeException('from_lookup/not_found');
    dbx_mv($from, $to); return ['metadata' => dropbox_get_metadata($token, $to)];
}

/** MySQL → SQLite shim. */
class ShimPDO extends PDO {
    public function exec(string $sql): int|false {
        if (preg_match('/^\s*(CREATE TABLE IF NOT EXISTS|ALTER TABLE)/i', $sql)) return 0;
        return parent::exec($sql);
    }
    public function query(string $query, ?int $fetchMode = null, mixed ...$args): PDOStatement|false {
        if (stripos($query, 'INFORMATION_SCHEMA') !== false) { $query = 'SELECT 1'; }   // column exists
        $query = str_replace(['CURDATE() - INTERVAL 30 DAY', 'CURDATE() - INTERVAL 400 DAY'], ["date('now','-30 day')", "date('now','-400 day')"], $query);
        return $fetchMode === null ? parent::query($query) : parent::query($query, $fetchMode, ...$args);
    }
}
$db = new ShimPDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
function db() { global $db; return $db; }
define('DROPBOX_BASE_PATH', '/2026');
define('AGENT_MEMO_USER', 'roberto');
define('FOLDER_ORPHAN_ROOTS', ['/001_Safari', '/2099']);   // /2099 does not exist: skipped in silence; /2026 not checked

$db->exec("CREATE TABLE requests (id INTEGER PRIMARY KEY, customer_name TEXT, practice_code TEXT, group_folder TEXT, dropbox_url TEXT,
           dropbox_id TEXT, status TEXT, payment_status TEXT, start_date TEXT, date_received TEXT, agent_id INT)");
$db->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, full_name TEXT, email TEXT, is_active INT DEFAULT 1)");
$db->exec("INSERT INTO users VALUES (1, 'roberto', 'Roberto', 'r@x', 1)");
$db->exec("CREATE TABLE memos (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INT, title TEXT, body TEXT, type TEXT, status TEXT, priority TEXT,
           pinned INT, color TEXT, due_date TEXT, reminder_at TEXT, reminder_emails TEXT, reminder_sent INT, recur_rule TEXT, sort_order INT,
           request_id INT, source TEXT, ext_key TEXT, parent_id INT, next_offset_days INT, created_at TEXT, updated_at TEXT, deleted_at TEXT)");
$db->exec("CREATE TABLE request_timeline (id INTEGER PRIMARY KEY AUTOINCREMENT, request_id INT, event_at TEXT, event_type TEXT, title TEXT, body TEXT,
           next_step TEXT, author_type TEXT, user_id INT, source TEXT, refs TEXT, pinned INT, created_at TEXT)");
$db->exec("CREATE TABLE booking_followups (id INTEGER PRIMARY KEY, request_id INT, folder TEXT, staff_status TEXT)");

require_once __DIR__ . '/../../modules/leads/includes/folder_link_service.php';
require_once __DIR__ . '/../../modules/memo/memo_lib.php';   // memo_schema: the shim answers "column exists"

$fail = 0;
$t = function (string $name, bool $ok, $got = null) use (&$fail) {
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $got === null ? '' : '  got: ' . json_encode($got, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . "\n";
    if (!$ok) $fail++;
};
$row = function (int $id) use ($db) { $s = $db->prepare("SELECT * FROM requests WHERE id = ?"); $s->execute([$id]); return $s->fetch(PDO::FETCH_ASSOC); };
$ins = function (int $id, string $cust, string $pc, ?string $gf, string $path, ?string $did, string $status, ?string $ps = null, ?string $start = '2027-01-28') use ($db) {
    $db->prepare("INSERT INTO requests VALUES (?,?,?,?,?,?,?,?,?,?,1)")
       ->execute([$id, $cust, $pc, $gf, bo_url_from_path($path), $did, $status, $ps, $start, '2026-10-01']);
};

// ── 1. Cancelled folders moved by hand into 00_CANCELED/00_2027 ────────────────
$F1 = '01_28JAN_PatriziaCavazzini(AzetaviaggiTo-LAM-Daniela)_START28JAN_END04FEB2027_CANCELLED';
$F2 = '01_11JAN_EvolutionSereZNZ(GoWorld-Roberto)_START11JAN_MIDT15JAN_END25JAN2027_CANCELLED';
dbx_add('/001_Safari', 'id:root'); dbx_add('/2026', 'id:y26'); dbx_add('/2027', 'id:y27');
dbx_add('/001_Safari/00_CANCELED', 'id:canc'); dbx_add('/001_Safari/00_CANCELED/00_2027', 'id:c27');
dbx_add('/001_Safari/' . $F1, 'id:E-y8MzGu1yUAAAAAAC62WA');
dbx_add('/001_Safari/' . $F2, 'id:E-y8MzGu1yUAAAAAAC7x-Q');
$ins(101, 'Patrizia Cavazzini', $F1, null, '/001_Safari/' . $F1, null, 'Cancelled');
$ins(102, 'Evolution Sere', $F2, null, '/001_Safari/' . $F2, null, 'Booked', 'Deposit', '2027-01-11');

// Backfill dry run → confirm.
$bf = folder_ids_backfill($db, 'tok', []);
$t('backfill dry run: 2 found, nothing saved', $bf['found'] === 2 && $bf['saved'] === 0 && $row(101)['dropbox_id'] === null, $bf);
$bf = folder_ids_backfill($db, 'tok', ['confirm' => true]);
$t('backfill confirm: ids saved', $row(101)['dropbox_id'] === 'id:E-y8MzGu1yUAAAAAAC62WA' && $row(102)['dropbox_id'] === 'id:E-y8MzGu1yUAAAAAAC7x-Q' && $bf['done'], $bf);

// Moved by hand.
dbx_mv('/001_Safari/' . $F1, '/001_Safari/00_CANCELED/00_2027/' . $F1);
dbx_mv('/001_Safari/' . $F2, '/001_Safari/00_CANCELED/00_2027/' . $F2);

$h = folder_heal($db, 'tok', $row(101));
$t('heal: relinked to 00_CANCELED/00_2027', $h['_folder']['state'] === 'relinked' && $h['_folder']['path'] === '/001_Safari/00_CANCELED/00_2027/' . $F1, $h['_folder']);
$t('heal: dropbox_url rewritten in DB', req_folder_path($row(101)) === '/001_Safari/00_CANCELED/00_2027/' . $F1, req_folder_path($row(101)));
$t('heal: no status warning when the Hub already says Cancelled', $h['_folder']['warnings'] === [], $h['_folder']['warnings']);
$ev = $db->query("SELECT event_type, body FROM request_timeline WHERE request_id = 101")->fetchAll(PDO::FETCH_ASSOC);
$t('heal: timeline folder_relinked "auto-relinked: old → new"', count($ev) === 1 && $ev[0]['event_type'] === 'folder_relinked'
   && strpos($ev[0]['body'], 'auto-relinked: /001_Safari/' . $F1) === 0, $ev);
$calls = $DBX_CALLS; folder_heal($db, 'tok', $row(101));
$t('heal: cached — no second Dropbox call in the same run', $DBX_CALLS === $calls);

$h = folder_heal($db, 'tok', $row(102));
$t('heal: Hub Booked vs suffix _CANCELLED → warning, status untouched', $h['_folder']['state'] === 'relinked' && count($h['_folder']['warnings']) === 1
   && $row(102)['status'] === 'Booked', $h['_folder']['warnings']);
$m = $db->query("SELECT title, ext_key, request_id FROM memos WHERE ext_key = 'folder-status:102'")->fetchAll(PDO::FETCH_ASSOC);
$t('heal: memo for Roberto (folder-status:102)', count($m) === 1 && (int)$m[0]['request_id'] === 102, $m);

// ── 2. Renamed by hand: practice_code follows ──────────────────────────────────
$F3 = '03_10MAR_Rossi(BTG-Roberto)_START10MAR_END20MAR2027_PROGRESS';
$F3b = '03_10MAR_Rossi(BTG-Roberto)_START10MAR_END20MAR2027_DEPOSIT';
dbx_add('/001_Safari/' . $F3, 'id:rossi');
$ins(103, 'Mario Rossi', $F3, null, '/001_Safari/' . $F3, 'id:rossi', 'Booked', null, '2027-03-10');
dbx_mv('/001_Safari/' . $F3, '/001_Safari/' . $F3b);
$h = folder_heal($db, 'tok', $row(103));
$t('rename outside: practice_code + url follow', $row(103)['practice_code'] === $F3b && req_folder_path($row(103)) === '/001_Safari/' . $F3b, $row(103));
$t('rename outside: _DEPOSIT vs empty payment status → warning', count($h['_folder']['warnings']) === 1, $h['_folder']['warnings']);

// ── 3. Group folder renamed by hand: every member + group_folder ─────────────
$G = 'GRP2306_23JUN_Mapo(Mapo-Roberto)_START23JUN_END01JUL2027_DEPOSIT';
$G2 = 'GRP2306_23JUN_Mapo(Mapo-Roberto)_START23JUN_END01JUL2027_BALANCE';
dbx_add('/001_Safari/' . $G, 'id:grp');
dbx_add('/001_Safari/' . $G . '/Bianchi(Mapo-Roberto)', 'id:m1');
dbx_add('/001_Safari/' . $G . '/Verdi(Mapo-Roberto)', 'id:m2');
$ins(201, 'Bianchi', 'Bianchi(Mapo-Roberto)', $G, '/001_Safari/' . $G . '/Bianchi(Mapo-Roberto)', 'id:m1', 'Booked', 'Deposit', '2027-06-23');
$ins(202, 'Verdi', 'Verdi(Mapo-Roberto)', $G, '/001_Safari/' . $G . '/Verdi(Mapo-Roberto)', 'id:m2', 'Booked', 'Deposit', '2027-06-23');
dbx_mv('/001_Safari/' . $G, '/001_Safari/' . $G2);
$h = folder_heal($db, 'tok', $row(201));
$t('group renamed: member 1 relinked', $h['_folder']['state'] === 'relinked' && req_folder_path($row(201)) === '/001_Safari/' . $G2 . '/Bianchi(Mapo-Roberto)', req_folder_path($row(201)));
$t('group renamed: member 2 + group_folder updated too', $row(202)['group_folder'] === $G2 && $row(201)['group_folder'] === $G2
   && req_folder_path($row(202)) === '/001_Safari/' . $G2 . '/Verdi(Mapo-Roberto)', $row(202));
$t('group renamed: suffix _BALANCE vs Booked → no status warning (group sets status only)', $h['_folder']['warnings'] === [], $h['_folder']['warnings']);

// ── 4. Deleted folder: clear error, no guess ───────────────────────────────────
$ins(301, 'Gone', 'Gone(X-Y)_START01JAN_END05JAN2027', null, '/001_Safari/Gone(X-Y)_START01JAN_END05JAN2027', 'id:gone', 'Booked');
$h = folder_heal($db, 'tok', $row(301));
$t('deleted: state deleted + clear error, DB untouched', $h['_folder']['state'] === 'deleted' && strpos($h['_folder']['error'], 'deleted or not reachable') !== false
   && req_folder_path($row(301)) === '/001_Safari/Gone(X-Y)_START01JAN_END05JAN2027', $h['_folder']);
$ins(302, 'NoId', 'NoId(X-Y)', null, '/2026/NoId(X-Y)', null, 'Quoted', null, null);
$h = folder_heal($db, 'tok', $row(302));
$t('missing without id: state missing (callers fall back to name search)', $h['_folder']['state'] === 'missing', $h['_folder']);

// ── 5. Path found with another id (folder re-created by the Hub): id refreshed ─
$F4 = 'Neri(BTG-Roberto)';
dbx_add('/2026/' . $F4, 'id:neri-new');
$ins(401, 'Neri', $F4, null, '/2026/' . $F4, 'id:neri-old', 'Quoted', null, null);
$h = folder_heal($db, 'tok', $row(401));
$t('path wins: stored id refreshed', $h['_folder']['state'] === 'ok' && $row(401)['dropbox_id'] === 'id:neri-new', $row(401));

// ── 6. Orphan check ───────────────────────────────────────────────────────────
dbx_add('/001_Safari/99_office', 'id:office');
dbx_add('/001_Safari/07_15JUL_Unknown(ABC-Lydia)_START15JUL_END25JUL2027_PROGRESS', 'id:orph');
dbx_add('/2026/Stray(ABC-Lydia)', 'id:stray');
$o = folder_orphans_run($db, 'tok', ['memo' => true]);
$orph = array_column($o['orphans'], 'path');
sort($orph);
$t('orphans: unknown booking folders of the roots listed, 99_office / 00_* / groups / known / /2026 skipped',
   $orph === ['/001_Safari/07_15JUL_Unknown(ABC-Lydia)_START15JUL_END25JUL2027_PROGRESS'], $orph);
$t('orphans: a missing root is no error', $o['errors'] === [], $o['errors']);
$un = array_column($o['unresolved'], 'id'); sort($un);
$t('orphans: unresolved live requests (deleted + missing)', $un === [301, 302], $o['unresolved']);
$mm = $db->query("SELECT id, title FROM memos WHERE ext_key = 'folders-orphans' AND status = 'open'")->fetchAll(PDO::FETCH_ASSOC);
$t('orphans: one memo folders-orphans', count($mm) === 1 && strpos($mm[0]['title'], ': 3') !== false, $mm);
folder_orphans_run($db, 'tok', ['memo' => true]);
$t('orphans: memo rewritten, not duplicated', (int)$db->query("SELECT COUNT(*) FROM memos WHERE ext_key = 'folders-orphans'")->fetchColumn() === 1);
$db->exec("UPDATE requests SET status = 'Lost' WHERE id IN (301, 302)");
dbx_mv('/001_Safari/07_15JUL_Unknown(ABC-Lydia)_START15JUL_END25JUL2027_PROGRESS', '/001_Safari/00_2027/x');
dbx_mv('/2026/Stray(ABC-Lydia)', '/2026/00_old');
$o = folder_orphans_run($db, 'tok', ['memo' => true]);
$t('orphans: empty list → memo closed', $o['memo'] === 'closed' && (int)$db->query("SELECT COUNT(*) FROM memos WHERE ext_key = 'folders-orphans' AND status = 'open'")->fetchColumn() === 0, $o);

// ── 7. archive_cancelled ───────────────────────────────────────────────────────
$F5 = '02_05FEB_Gialli(BTG-Roberto)_START05FEB_END12FEB2027_CANCELLED';
dbx_add('/001_Safari/' . $F5, 'id:gialli');
$ins(501, 'Gialli', $F5, null, '/001_Safari/' . $F5, 'id:gialli', 'Booked', null, '2027-02-05');
$a = folder_archive_cancelled($db, 'tok', []);
$t('archive dry run: lists the move to 00_CANCELED/00_2027, nothing moved', count($a['moves']) === 1 && $a['moves'][0]['to'] === '/001_Safari/00_CANCELED/00_2027/' . $F5
   && dropbox_path_exists('tok', '/001_Safari/' . $F5), $a);
$t('archive dry run: Booked request listed for status → Cancelled', $a['moves'][0]['status_set'] === [501], $a['moves'][0]);
// (The confirm with a status change also closes the Booking follow-up lines, whose service loads the real Dropbox helper:
//  not loadable next to this stub — the status is set beforehand here.)
$db->exec("UPDATE requests SET status = 'Cancelled' WHERE id = 501");
$a = folder_archive_cancelled($db, 'tok', ['confirm' => true]);
$t('archive confirm: moved, path updated, timeline', !dropbox_path_exists('tok', '/001_Safari/' . $F5)
   && req_folder_path($row(501)) === '/001_Safari/00_CANCELED/00_2027/' . $F5
   && (int)$db->query("SELECT COUNT(*) FROM request_timeline WHERE request_id = 501 AND event_type = 'folder_relinked'")->fetchColumn() === 1, [$a, $row(501)]);
$a = folder_archive_cancelled($db, 'tok', ['request_id' => 103]);
$t('archive request_id: not cancelled → refused', !empty($a['error']) && strpos($a['error'], 'not cancelled') !== false, $a);

echo $fail ? "\n$fail FAILED\n" : "\nALL PASS\n";
exit($fail ? 1 : 0);
