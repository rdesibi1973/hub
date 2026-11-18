<?php
/**
 * rename_folder on GRP members vs group parents (folder_service.php fs_rename_plan / bo_do_rename, folder_link_service.php
 * fl_member_find). Local checks, no MySQL, no Dropbox: in-memory SQLite + an in-memory Dropbox tree (as folder_link_test.php).
 *   C:/Utility/PHP/php.exe -d extension_dir=C:/Utility/PHP/ext -d extension=pdo_sqlite -d extension=mbstring tools/tests/folder_rename_group_test.php
 * Real case (18 Nov 2026): GRP2710 parent renamed by hand (…_BALANCE → …_PAID_CK); re-linking member #2799 with current_path =
 * its sub-folder set group_folder = the member name, doubled the member in dropbox_url and rewrote every other member.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// ── Dropbox stub ──────────────────────────────────────────────────────────────
$DBX = [];
function dbx_add(string $path, string $id): void { global $DBX; $DBX[strtolower($path)] = ['path' => $path, 'id' => $id]; }
function dbx_mv(string $from, string $to): void {
    global $DBX;
    $f = strtolower($from); $new = [];
    foreach ($DBX as $k => $v) {
        if ($k === $f || strpos($k, $f . '/') === 0) { $p = $to . substr($v['path'], strlen($from)); $new[strtolower($p)] = ['path' => $p, 'id' => $v['id']]; }
        else $new[$k] = $v;
    }
    $DBX = $new;
}
function dropbox_get_metadata(string $token, string $path): ?array {
    global $DBX;
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
function dropbox_search_folders(string $token, string $q, string $base = '', int $limit = 10): array {
    global $DBX; $out = [];
    foreach ($DBX as $v) if (stripos(basename($v['path']), $q) !== false && ($base === '' || stripos($v['path'], $base . '/') === 0)) $out[] = ['path' => $v['path'], 'name' => basename($v['path'])];
    return array_slice($out, 0, $limit);
}
function dropbox_list_folder_entries(string $token, string $path): array { return []; }
function dropbox_move_folder(string $token, string $from, string $to): array {
    if (!dropbox_path_exists($token, $from)) throw new RuntimeException('from_lookup/not_found');
    if (strcasecmp($from, $to) !== 0 && dropbox_path_exists($token, $to)) throw new RuntimeException('to/conflict');
    dbx_mv($from, $to); return ['metadata' => dropbox_get_metadata($token, $to)];
}

class ShimPDO extends PDO {
    public function exec(string $sql): int|false {
        if (preg_match('/^\s*(CREATE TABLE IF NOT EXISTS|ALTER TABLE)/i', $sql)) return 0;
        return parent::exec($sql);
    }
    public function query(string $query, ?int $fetchMode = null, mixed ...$args): PDOStatement|false {
        if (stripos($query, 'INFORMATION_SCHEMA') !== false) { $query = 'SELECT 1'; }
        return $fetchMode === null ? parent::query($query) : parent::query($query, $fetchMode, ...$args);
    }
}
$db = new ShimPDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
function db() { global $db; return $db; }
define('DROPBOX_BASE_PATH', '/2026');

$db->exec("CREATE TABLE requests (id INTEGER PRIMARY KEY, customer_name TEXT, practice_code TEXT, group_folder TEXT, dropbox_url TEXT,
           dropbox_id TEXT, status TEXT, payment_status TEXT, start_date TEXT, date_received TEXT, agent_id INT, confirmation_date TEXT)");
$db->exec("CREATE TABLE request_timeline (id INTEGER PRIMARY KEY AUTOINCREMENT, request_id INT, event_at TEXT, event_type TEXT, title TEXT, body TEXT,
           next_step TEXT, author_type TEXT, user_id INT, source TEXT, refs TEXT, pinned INT, created_at TEXT)");

require_once __DIR__ . '/../../modules/leads/includes/folder_link_service.php';
require_once __DIR__ . '/../../modules/leads/includes/folder_service.php';

$fail = 0;
$t = function (string $name, bool $ok, $got = null) use (&$fail) {
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $got === null ? '' : '  got: ' . json_encode($got, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . "\n";
    if (!$ok) $fail++;
};
$row = function (int $id) use ($db) { $s = $db->prepare("SELECT * FROM requests WHERE id = ?"); $s->execute([$id]); return $s->fetch(PDO::FETCH_ASSOC); };
$ins = function (int $id, string $cust, string $pc, ?string $gf, string $path, ?string $did, string $status, ?string $ps = null) use ($db) {
    $db->prepare("INSERT INTO requests VALUES (?,?,?,?,?,?,?,?,?,?,1,?)")
       ->execute([$id, $cust, $pc, $gf, bo_url_from_path($path), $did, $status, $ps, '2026-10-27', '2026-09-01', '2026-09-10']);
};
/** Dry run (validate + preview) then confirm (bo_do_rename with the same plan), as rename_folder does without the gate / CK. */
$rename = function (int $rid, string $newName, string $cur, string $scope = '', bool $go = true) use ($db) {
    $v = fs_rename_validate($db, $rid, $newName, $cur, $scope);
    if (!$v['ok']) return ['stage' => 'validate', 'v' => $v];
    $pv = fs_rename_preview($db, $v, 'tok', false, false);
    if ($pv['error'] !== null || !$go) return ['stage' => 'preview', 'v' => $v, 'pv' => $pv];
    $curPath = $v['relink'] ? fs_relink_resolve('tok', $v['current_path'])['path'] : null;
    $res = bo_do_rename($db, 'tok', $v['r'], $v['is_group'], $v['folder'], $v['new_name'], $v['status'], $v['ps'], $v['set_status'], $curPath, $v['scope']);
    return ['stage' => 'done', 'v' => $v, 'pv' => $pv, 'res' => $res];
};
$pathOf = function (int $id) use ($row) { return req_folder_path($row($id)); };

dbx_add('/001_Safari', 'id:root'); dbx_add('/2026', 'id:y26'); dbx_add('/2027', 'id:y27');

// ── 1. GRP2710: parent renamed by hand, members re-linked one after the other ──
$OLD = '10_27OCT_MattiaFerri_GRP2710(GoWorld-Roberto)_START27OCT_MIDT01NOV_END06NOV2026_BALANCE';
$NEW = '10_27OCT_MattiaFerri_GRP2710(GoWorld-Roberto)_START27OCT_MIDT01NOV_END06NOV2026_PAID_CK';
$BP  = 'BrunoPizi_GRP2710(HTSViaggi-Daniela)_PAID';
$AS  = 'AndreaSpreafico_GRP2710(Imbarco71-PS-Daniela)_PAID';
$MF  = 'MattiaFerri_GRP2710(GoWorld-Roberto)_PAID';
dbx_add('/001_Safari/' . $OLD, 'id:p2710');
dbx_add('/001_Safari/' . $OLD . '/' . $BP, 'id:bp');
dbx_add('/001_Safari/' . $OLD . '/' . $AS, 'id:as');
dbx_add('/001_Safari/' . $OLD . '/' . $MF, 'id:mf');
$ins(2799, 'Bruno Pizi', $BP, $OLD, '/001_Safari/' . $OLD . '/' . $BP, null, 'Booked', 'Paid');
$ins(2844, 'Andrea Spreafico', $AS, $OLD, '/001_Safari/' . $OLD . '/' . $AS, null, 'Booked', 'Paid');
$ins(2850, 'Mattia Ferri', $MF, $OLD, '/001_Safari/' . $OLD . '/' . $MF, null, 'Booked', 'Paid');
dbx_mv('/001_Safari/' . $OLD, '/001_Safari/' . $NEW);
dbx_add('/001_Safari/' . $NEW . '/' . $MF . '_moved', 'id:x');   // noise
unset($GLOBALS['DBX'][strtolower('/001_Safari/' . $NEW . '/' . $MF)]); // Mattia's sub-folder no longer there (left as is)

$r = $rename(2799, $BP, '/001_Safari/' . $NEW . '/' . $BP, '', false);
$t('member default scope = member', $r['v']['scope'] === 'member' && !$r['v']['is_group'], $r['v']);
$aff = $r['pv']['affected'];
$t('dry run affected[]: Bruno (own folder) + Andrea (found in the parent), Mattia skipped',
   array_column($aff, 'id') === [2799, 2844] && count($r['pv']['skipped']) === 1 && $r['pv']['skipped'][0]['id'] === 2850, [$aff, $r['pv']['skipped']]);
$t('dry run Bruno: group_folder → real parent, folder unchanged, url = parent/member',
   $aff[0]['group_folder_to'] === $NEW && $aff[0]['folder_to'] === $BP && $aff[0]['dropbox_url_to'] === bo_url_from_path('/001_Safari/' . $NEW . '/' . $BP), $aff[0]);

$r = $rename(2799, $BP, '/001_Safari/' . $NEW . '/' . $BP);
$t('confirm: ok, message says Member', $r['res']['ok'] && strpos($r['res']['msg'], 'Member "' . $BP . '"') !== false, $r['res']);
$t('confirm Bruno: own folder, real group_folder', $pathOf(2799) === '/001_Safari/' . $NEW . '/' . $BP && $row(2799)['group_folder'] === $NEW
   && $row(2799)['practice_code'] === $BP, $row(2799));
$t('confirm Andrea: followed the parent name, still its own folder', $pathOf(2844) === '/001_Safari/' . $NEW . '/' . $AS && $row(2844)['group_folder'] === $NEW, $row(2844));
$t('confirm Mattia (sub-folder not found): untouched', $row(2850)['group_folder'] === $OLD, $row(2850));
$t('confirm: folder id returned for the member', $r['res']['folder_id'] === 'id:bp', $r['res']['folder_id']);
$t('confirm: payment_status kept (Paid)', $row(2799)['payment_status'] === 'Paid' && $row(2844)['payment_status'] === 'Paid');
$pvW = array_map(function ($a) { return [$a['id'], $a['group_folder_to'], $a['dropbox_url_to']]; }, $r['pv']['affected']);
$reW = array_map(function ($a) { return [$a['id'], $a['group_folder_to'], $a['dropbox_url_to']]; }, $r['res']['affected']);
$t('dry run affected[] = what confirm wrote', $pvW === $reW, [$pvW, $reW]);

// Second member re-linked afterwards: only itself, Bruno untouched.
$r = $rename(2844, $AS, '/001_Safari/' . $NEW . '/' . $AS);
$t('second member: current_path = stored path → plain rename refused as identical (already healed)',
   $r['stage'] === 'validate' && $r['v']['code'] === 400, $r['v']);
$t('second member: Bruno still on his own folder', $pathOf(2799) === '/001_Safari/' . $NEW . '/' . $BP, $pathOf(2799));

// ── 2. Two members with the same stale group_folder, both needing a re-link ─────
$G1 = '01_03JAN_SofiaDeCicco_GRP0301(DemidoffViaggi-Daniela)_START03JAN_END08JAN2027_PROGRESS';
$G1b = '01_03JAN_SofiaDeCicco_GRP0301(DemidoffViaggi-Daniela)_START03JAN_END08JAN2027_BALANCE';
$SD = 'SofiaDeCicco(DemidoffViaggi-Daniela)_BALANCE';
$DS = 'DanieleSandri_GRP0301(Daniela-LAM)';
dbx_add('/001_Safari/' . $G1b, 'id:p0301');
dbx_add('/001_Safari/' . $G1b . '/' . $SD, 'id:sd');
dbx_add('/001_Safari/' . $G1b . '/' . $DS, 'id:ds');
$ins(2813, 'Sofia De Cicco', $SD, $G1, '/001_Safari/' . $G1 . '/' . $SD, null, 'Booked', 'Balance');
// Old #2867 case: its stored folder was the parent's name.
$ins(2867, 'Daniele Sandri', $DS, $G1, '/001_Safari/' . $G1, null, 'Booked', 'Balance');
$r = $rename(2867, $DS, '/001_Safari/' . $G1b . '/' . $DS);
$t('member whose stored path was the parent: re-linked to its own sub-folder', $r['stage'] === 'done' && $r['res']['ok']
   && $pathOf(2867) === '/001_Safari/' . $G1b . '/' . $DS && $row(2867)['group_folder'] === $G1b, [$r['res'] ?? $r, $row(2867)]);
$t('…and Sofia followed (her sub-folder is in the parent)', $pathOf(2813) === '/001_Safari/' . $G1b . '/' . $SD && $row(2813)['group_folder'] === $G1b, $row(2813));
$t('…never pointing to each other', $pathOf(2813) !== $pathOf(2867));

// ── 3. Group parent rename (scope group): parent + all members, as before ──────
$r = $rename(2813, $G1b . '_CK', '', 'group');
$t('group rename: parent renamed in Dropbox', $r['stage'] === 'done' && $r['res']['ok'] && dropbox_path_exists('tok', '/001_Safari/' . $G1b . '_CK/' . $SD), $r['res'] ?? $r);
$t('group rename: every member follows', $row(2813)['group_folder'] === $G1b . '_CK' && $row(2867)['group_folder'] === $G1b . '_CK'
   && $pathOf(2867) === '/001_Safari/' . $G1b . '_CK/' . $DS, [$row(2813), $row(2867)]);
$t('group rename: dry run affected[] listed both members', array_column($r['pv']['affected'], 'id') === [2813, 2867], $r['pv']['affected']);

// ── 4. Guards ─────────────────────────────────────────────────────────────────
$r = $rename(2813, $G1b . '_X', '');
$t('member + a group-shaped name without scope → 400 (asks for scope group)', $r['stage'] === 'validate' && $r['v']['code'] === 400
   && strpos($r['v']['error'], 'scope "group"') !== false, $r['v']);
$r = $rename(2813, 'Whatever', '/001_Safari/' . $G1b . '_CK/' . $SD, 'group', false);
$t('scope group with a member path → refused (dry run)', $r['stage'] === 'preview' && $r['pv']['error'] !== null
   && strpos($r['pv']['error'], 'member sub-folder') !== false, $r['pv'] ?? $r);
dbx_add('/001_Safari/' . $SD . 'X', 'id:loose');
$r = $rename(2813, $SD . 'X', '/001_Safari/' . $SD . 'X', '', false);
$t('scope member with a folder directly in /001_Safari → refused (not inside a group folder)', $r['stage'] === 'preview'
   && strpos((string)$r['pv']['error'], 'not inside a group folder') !== false, $r['pv'] ?? $r);
$ins(900, 'Private', 'Priv(X-Y)', null, '/2026/Priv(X-Y)', null, 'Quoted');
$r = $rename(900, 'Priv(X-Y)2', '', 'member');
$t('scope on a private request → 400', $r['stage'] === 'validate' && $r['v']['code'] === 400, $r['v']);

// ── 5. Member rename (new name ≠ basename): sub-folder renamed on disk, only this request ─
$r = $rename(2867, 'DanieleSandri_GRP0301(Daniela-LAM)_BALANCE', '');
$t('member rename: sub-folder renamed, status from its tag, group_folder kept', $r['stage'] === 'done' && $r['res']['ok']
   && dropbox_path_exists('tok', '/001_Safari/' . $G1b . '_CK/DanieleSandri_GRP0301(Daniela-LAM)_BALANCE')
   && $row(2867)['group_folder'] === $G1b . '_CK' && $row(2867)['payment_status'] === 'Balance' && $pathOf(2813) === '/001_Safari/' . $G1b . '_CK/' . $SD,
   [$r['res'] ?? $r, $row(2867)]);

// ── 6. Nightly heal: member without id, parent renamed by hand ────────────────
$G3 = '10_16OCT_FabioZanelli_GRP1610(Creo-LAM-Roberto)_START16OCT_END21OCT2026_BALANCE_CK';
$G3b = '10_16OCT_FabioZanelli_GRP1610(Creo-LAM-Roberto)_START16OCT_END21OCT2026_PAID_CK';
$AC = 'AnnamariaCaciolli(Africando-Daniela)';
$FZ = 'FabioZanelli_GRP1610(Creo-LAM-Roberto)';
dbx_add('/001_Safari/' . $G3b, 'id:p1610');
dbx_add('/001_Safari/' . $G3b . '/' . $AC, 'id:ac');
dbx_add('/001_Safari/' . $G3b . '/' . $FZ, 'id:fz');
$ins(2861, 'Annamaria Caciolli', $AC, $G3, '/001_Safari/' . $G3 . '/' . $AC, null, 'Booked', 'Paid');
$ins(353, 'Fabio Zanelli', $FZ, $G3, '/001_Safari/' . $G3 . '/' . $FZ, null, 'Booked', 'Paid');
$h = folder_heal($db, 'tok', $row(2861));
$t('heal by group code: member relinked to the renamed parent', $h['_folder']['state'] === 'relinked'
   && $pathOf(2861) === '/001_Safari/' . $G3b . '/' . $AC && $row(2861)['dropbox_id'] === 'id:ac', [$h['_folder'], $row(2861)]);
$t('heal: the other member under the old parent follows (group_folder too)', $row(353)['group_folder'] === $G3b
   && $pathOf(353) === '/001_Safari/' . $G3b . '/' . $FZ, $row(353));

echo $fail ? "\n$fail FAILED\n" : "\nALL PASS\n";
exit($fail ? 1 : 0);
