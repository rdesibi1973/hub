<?php
/**
 * folder_service.php — free rename of a booking's Dropbox folder, shared by the
 * BackOffice ("Rename…" and "Change status", backoffice.php) and the Agent API
 * (rename_folder).
 *
 *   Validate  : fs_rename_validate()   request, folder, new name, current_path (no Dropbox call)
 *   Preview   : fs_rename_preview()    Dropbox paths, destination clash, name / Calc warnings
 *   Rename    : folder_rename()        Dropbox move + DB + CK tracker + timeline
 *   Low level : bo_do_rename()         Dropbox move + DB only (also used by Change status)
 *               fs_rename_record()     CK tracker + timeline after a rename
 *
 * A group carries its name on the shared parent folder (group_folder): renaming a
 * group renames that parent and updates every request in it.
 *
 * Re-link: a folder moved / renamed outside the Hub is no longer at the stored path nor
 * findable by its stored name. The caller then gives its real path (current_path): the
 * rename starts from there (new name may equal its basename = re-link only).
 *
 * Requires the leads config.php (db(), …). Keep PHP-7 compatible.
 */

require_once __DIR__ . '/booking_service.php';   // bo_url_from_path, bs_confirm_months, timeline_log
require_once __DIR__ . '/ck_lib.php';            // ck_record_rename

/** Characters a folder name may not contain (Dropbox / Windows sync). */
const FS_BAD_CHARS_RE = '#[\\\\/:*?"<>|]#';

/**
 * Folder suffix → [status, payment_status]. "Contains" match, longest first
 * (tolerates a trailing _CK). Used to keep the DB in sync after a free rename.
 */
function bo_tag_status(): array {
    return [
        '_BALANCE-CASH' => ['Booked',      'Balance-Cash'],
        '_BALANCE_CASH' => ['Booked',      'Balance-Cash'],
        '_BALANCE'      => ['Booked',      'Balance'],
        '_DEPOSIT'      => ['Booked',      'Deposit'],
        '_PAID'         => ['Booked',      'Paid'],
        '_PROGRESS'     => ['Booked',      null],
        '_CONFIRMED'    => ['Booked',      null],
        '_PROVISIONAL'  => ['Provisional', null],
        '_CANCELLED'    => ['Cancelled',   null],
    ];
}

/** Derive [status, payment_status, matched] from a folder name, or matched=false. */
function bo_status_from_name(string $name, ?array $tagStatus = null): array {
    $up = strtoupper($name);
    foreach ($tagStatus ?? bo_tag_status() as $tag => $sp) {
        if (strpos($up, $tag) !== false) return ['status' => $sp[0], 'ps' => $sp[1], 'matched' => true];
    }
    return ['status' => null, 'ps' => null, 'matched' => false];
}

/**
 * Current Dropbox path of the folder being renamed ($folder: the booking folder, or the
 * group parent). Tries the path in the stored dropbox_url first (exact, kept fresh by every
 * rename), then Dropbox search — which lags for a folder renamed minutes ago. Null if not found.
 */
function fs_locate_folder(string $token, array $r, string $folder): ?string {
    $p = bo_path_from_url((string)($r['dropbox_url'] ?? ''));
    while ($p !== '' && $p !== '/') {
        if (strcasecmp(basename($p), $folder) === 0) {
            try { if (dropbox_path_exists($token, $p)) return $p; } catch (Throwable $ig) {}
            break;
        }
        $p = rtrim(dirname($p), '/');   // group: the URL points to the client sub-folder
    }
    return dropbox_find_folder($token, $folder);
}

/**
 * Dropbox roots a booking folder may live in: the inquiry year root (DROPBOX_BASE_PATH)
 * and the next year's, plus 001_Safari (CK_BASE). E.g. ['/2026', '/2027', '/001_Safari'].
 */
function fs_allowed_roots(): array {
    $base  = defined('DROPBOX_BASE_PATH') ? rtrim(DROPBOX_BASE_PATH, '/') : '/' . date('Y');
    $roots = [$base];
    if (preg_match('#^/(\d{4})$#', $base, $m)) $roots[] = '/' . ((int)$m[1] + 1);
    $roots[] = CK_BASE;
    return $roots;
}

/** "/001_Safari/Foo/" or "001_Safari/Foo" → "/001_Safari/Foo" ('' if empty). */
function fs_norm_path(string $p): string {
    $p = trim(str_replace('\\', '/', $p));
    $p = preg_replace('#/+#', '/', $p);
    $p = rtrim((string)$p, '/');
    if ($p === '') return '';
    return $p[0] === '/' ? $p : '/' . $p;
}

/**
 * Not-found error for a folder moved outside the Hub: suggests current_path and lists up
 * to 3 folders in 001_Safari found by the customer name (Dropbox search, best-effort).
 * Returns ['msg' => string, 'candidates' => [path, …]].
 */
function fs_not_found(string $token, array $r, string $folder): array {
    $queries = [];
    $core = preg_replace('/\(.*$/', '', $folder);                     // drop "(Agency-Agent)…"
    $core = preg_replace('/^\d{1,2}_\d{1,2}[A-Za-z]{3}_/', '', (string)$core);   // drop "07_27JUL_"
    if (strlen((string)$core) >= 3) $queries[] = $core;
    $words = preg_split('/\s+/', trim((string)($r['customer_name'] ?? '')));
    $last  = (string)end($words);
    if (strlen($last) >= 3 && strcasecmp($last, (string)$core) !== 0) $queries[] = preg_replace('/\(.*$/', '', $last);

    $cands = [];
    foreach ($queries as $q) {
        try {
            foreach (dropbox_search_folders($token, $q, CK_BASE, 10) as $h) {
                if ($h['path'] !== '' && !in_array($h['path'], $cands, true)) $cands[] = $h['path'];
                if (count($cands) >= 3) break 2;
            }
        } catch (Throwable $ig) { /* suggestion only */ }
    }
    $msg = 'Could not find the folder "' . $folder . '" in Dropbox (new folders can lag ~1h in search). '
         . 'If it was moved or renamed outside the Hub, give its current Dropbox path (current_path) to re-link it.';
    if ($cands) $msg .= ' Possible matches: ' . implode(' · ', $cands);
    return ['msg' => $msg, 'candidates' => $cands];
}

/**
 * Rename a booking's Dropbox folder (private = its own folder; group = the shared
 * parent) and sync the DB. When $setStatus, also writes status/payment_status
 * (for a group: status only — each client keeps its own payment_status —
 * to every request in it, rebuilding each sub's dropbox_url).
 * Dropbox move happens first; the DB is only touched if it succeeds.
 * $curPath: the folder's real path when already known (re-link); null = locate it from
 * the stored URL / by name. A new name equal to its basename = no Dropbox move.
 * Requires dropbox_helper.php.
 */
function bo_do_rename(PDO $db, string $token, array $r, bool $isGrp,
                      string $folder, string $newFolder,
                      ?string $newStatus, $newPs, bool $setStatus, ?string $curPath = null): array {
    if ($curPath === null) $curPath = fs_locate_folder($token, $r, $folder);
    if ($curPath === null) {
        $nf = fs_not_found($token, $r, $folder);
        return ['ok' => false, 'not_found' => true, 'msg' => $nf['msg'], 'candidates' => $nf['candidates']];
    }
    $parentDir = rtrim(substr($curPath, 0, strrpos($curPath, '/')), '/');
    $newPath   = $parentDir . '/' . $newFolder;

    if (strcmp(basename($curPath), $newFolder) !== 0) {
        dropbox_move_folder($token, $curPath, $newPath);   // subfolders move with the parent
    }

    if ($isGrp) {
        // Each client keeps its own payment_status (its invoice / sub-folder):
        // the group tag only sets the booking status, and never revives a
        // cancelled client unless the whole group is cancelled.
        $subs = $db->prepare("SELECT id, practice_code, status FROM requests WHERE group_folder = ?");
        $subs->execute([$folder]);
        $rowsG = $subs->fetchAll(PDO::FETCH_ASSOC);
        $upd   = $db->prepare("UPDATE requests SET group_folder=?, practice_code=?, dropbox_url=? WHERE id=?");
        $updSt = $db->prepare("UPDATE requests SET group_folder=?, practice_code=?, dropbox_url=?, status=? WHERE id=?");
        $n = 0;
        foreach ($rowsG as $g) {
            $sub    = trim($g['practice_code'] ?? '');
            // Imported group (practice_code = group_folder): the folder itself, no sub-folder.
            if ($sub === $folder) $sub = '';
            $subUrl = bo_url_from_path($sub !== '' ? $newPath . '/' . $sub : $newPath);
            $code   = $sub !== '' ? $g['practice_code'] : ($g['practice_code'] === $folder ? $newFolder : $g['practice_code']);
            $keep   = ($g['status'] ?? '') === 'Cancelled' && $newStatus !== 'Cancelled';
            if ($setStatus && $newStatus !== null && !$keep) $updSt->execute([$newFolder, $code, $subUrl, $newStatus, (int)$g['id']]);
            else                                              $upd->execute([$newFolder, $code, $subUrl, (int)$g['id']]);
            $n++;
        }
        return ['ok' => true, 'msg' => '✔ Group "' . $newFolder . '": renamed (' . $n . ' booking(s) updated).',
                'old_path' => $curPath, 'new_path' => $newPath];
    }

    $newUrl = bo_url_from_path($newPath);
    if ($setStatus) {
        $db->prepare("UPDATE requests SET practice_code=?, dropbox_url=?, status=?, payment_status=? WHERE id=?")
           ->execute([$newFolder, $newUrl, $newStatus, $newPs, (int)$r['id']]);
    } else {
        $db->prepare("UPDATE requests SET practice_code=?, dropbox_url=? WHERE id=?")
           ->execute([$newFolder, $newUrl, (int)$r['id']]);
    }
    return ['ok' => true, 'msg' => '✔ ' . $r['customer_name'] . ': renamed to "' . $newFolder . '".',
            'old_path' => $curPath, 'new_path' => $newPath];
}

/**
 * Check a free-rename request without touching Dropbox. Returns
 *   ['ok'=>false, 'error'=>msg, 'code'=>404|400|409]  or
 *   ['ok'=>true, 'r'=>row, 'is_group'=>bool, 'folder'=>current name, 'new_name'=>…,
 *    'set_status'=>bool, 'status'=>?string, 'ps'=>?string,
 *    'relink'=>bool, 'current_path'=>?string, 'stored_path'=>string]
 * The renamed folder is the group parent for a group, the folder itself otherwise.
 *
 * $currentPath (re-link): the folder's real Dropbox path when it was moved outside the
 * Hub. It must sit under fs_allowed_roots() and no other request may point to it. Equal
 * to the stored path = ignored (plain rename).
 */
function fs_rename_validate(PDO $db, int $requestId, string $newName, string $currentPath = ''): array {
    $st = $db->prepare("SELECT id, customer_name, practice_code, group_folder, dropbox_url, status, payment_status,
                               confirmation_date, start_date
                        FROM requests WHERE id = ?");
    $st->execute([$requestId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) return ['ok' => false, 'code' => 404, 'error' => 'Request not found.'];

    $isGrp  = trim($r['group_folder'] ?? '') !== '';
    $folder = $isGrp ? trim($r['group_folder']) : trim($r['practice_code'] ?? '');
    if ($folder === '') return ['ok' => false, 'code' => 400, 'error' => 'This request has no folder to rename.'];

    $newName = trim($newName);
    if ($newName === '') return ['ok' => false, 'code' => 400, 'error' => 'New name is empty.'];
    if (preg_match(FS_BAD_CHARS_RE, $newName)) {
        return ['ok' => false, 'code' => 400, 'error' => 'Invalid characters in the new name (\\ / : * ? " < > | are not allowed).'];
    }

    // Stored path of the renamed folder (group: the parent of the member's URL).
    $stored = bo_path_from_url((string)($r['dropbox_url'] ?? ''));
    $p = $stored;
    while ($p !== '' && $p !== '/' && strcasecmp(basename($p), $folder) !== 0) $p = rtrim(dirname($p), '/');
    if ($p !== '' && $p !== '/') $stored = $p;

    $cur = fs_norm_path($currentPath);
    if ($cur !== '' && strcasecmp($cur, $stored) === 0) $cur = '';   // same as stored: plain rename
    if ($cur !== '') {
        $inRoot = false;
        foreach (fs_allowed_roots() as $root) {
            if (stripos($cur, $root . '/') === 0 && strlen($cur) > strlen($root) + 1) { $inRoot = true; break; }
        }
        if (!$inRoot) {
            return ['ok' => false, 'code' => 400, 'error' => 'current_path must be a folder under ' . implode(', ', fs_allowed_roots()) . '.'];
        }
        foreach (explode('/', ltrim($cur, '/')) as $seg) {
            if ($seg === '' || $seg === '.' || $seg === '..' || preg_match('#[:*?"<>|]#', $seg)) {
                return ['ok' => false, 'code' => 400, 'error' => 'current_path is not a valid Dropbox path.'];
            }
        }
        // Another request already pointing to that folder (or inside it). Members of this
        // group live inside the group parent: not a clash.
        $base = basename($cur);
        $q = $db->prepare("SELECT id, practice_code, group_folder, dropbox_url FROM requests
                           WHERE id <> ? AND (practice_code = ? OR group_folder = ? OR dropbox_url LIKE ?)");
        $q->execute([(int)$r['id'], $base, $base, '%' . rawurlencode($base) . '%']);
        $clash = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $o) {
            if ($isGrp && trim($o['group_folder'] ?? '') === $folder) continue;
            $op = bo_path_from_url((string)($o['dropbox_url'] ?? ''));
            if (($op !== '' && (strcasecmp($op, $cur) === 0 || stripos($op, $cur . '/') === 0))
                || ($op === '' && (strcasecmp((string)$o['practice_code'], $base) === 0 || strcasecmp((string)$o['group_folder'], $base) === 0))) {
                $clash[] = (int)$o['id'];
            }
        }
        if ($clash) {
            return ['ok' => false, 'code' => 409, 'error' => 'Request(s) ' . implode(', ', array_slice($clash, 0, 5))
                  . ' already point to ' . $cur . ' — re-link refused.'];
        }
    } elseif (strcmp($newName, $folder) === 0) {
        return ['ok' => false, 'code' => 400, 'error' => 'The new name is identical — nothing to change.'];
    }

    // Keep the DB status in sync with the new name's suffix (if it has one).
    $d = bo_status_from_name($newName);
    return ['ok' => true, 'r' => $r, 'is_group' => $isGrp, 'folder' => $folder, 'new_name' => $newName,
            'set_status' => $d['matched'], 'status' => $d['status'], 'ps' => $d['ps'],
            'relink' => $cur !== '', 'current_path' => $cur !== '' ? $cur : null, 'stored_path' => $stored];
}

/**
 * Re-link: check current_path in Dropbox. Returns ['path' => real-case path] or
 * ['error' => msg, 'code' => 404|400]. Requires dropbox_helper.php.
 */
function fs_relink_resolve(string $token, string $path): array {
    $m = dropbox_get_metadata($token, $path);
    if ($m === null)            return ['error' => 'current_path "' . $path . '" does not exist in Dropbox.', 'code' => 404];
    if ($m['tag'] !== 'folder') return ['error' => 'current_path "' . $path . '" is a file, not a folder.', 'code' => 400];
    return ['path' => $m['path']];
}

/**
 * True when this rename turns the request Booked without a Hub confirmation (re-link of a
 * folder confirmed outside the Hub): no pre_confirm snapshot, confirmation_date empty.
 */
function fs_relink_books(array $v): bool {
    return !empty($v['relink']) && $v['set_status'] && $v['status'] === 'Booked'
        && ($v['r']['status'] ?? '') !== 'Booked' && empty($v['r']['confirmation_date']);
}

/**
 * Files inside $dir (recursive) named "NN_<old name>…" for one of $oldNames → the same
 * with $newName. Returns [['from' => rel path, 'to' => rel path], …]. Requires dropbox_helper.php.
 */
function fs_file_renames(string $token, string $dir, array $oldNames, string $newName): array {
    $olds = array_values(array_unique(array_filter($oldNames, 'strlen')));
    usort($olds, function ($a, $b) { return strlen($b) - strlen($a); });   // longest first
    $out = [];
    foreach (dropbox_list_recursive($token, $dir) as $e) {
        if ($e['tag'] !== 'file') continue;
        $rel  = $e['path'];
        $slash = strrpos($rel, '/');
        $sub  = $slash === false ? '' : substr($rel, 0, $slash + 1);
        $name = $slash === false ? $rel : substr($rel, $slash + 1);
        foreach ($olds as $old) {
            if (strcasecmp($old, $newName) === 0) continue;
            if (preg_match('/^(\d{1,3}_)' . preg_quote($old, '/') . '(?=[_.])/i', $name, $m)) {
                $out[] = ['from' => $rel, 'to' => $sub . $m[1] . $newName . substr($name, strlen($m[0]))];
                break;
            }
        }
    }
    return $out;
}

/** Apply fs_file_renames() inside $dir. Returns ['done' => [to, …], 'failed' => [from => error, …]]. */
function fs_apply_file_renames(string $token, string $dir, array $plan): array {
    $res = ['done' => [], 'failed' => []];
    foreach ($plan as $f) {
        try {
            dropbox_move_folder($token, $dir . '/' . $f['from'], $dir . '/' . $f['to']);   // move_v2: files too
            $res['done'][] = $f['to'];
        } catch (Throwable $e) {
            $res['failed'][$f['from']] = $e->getMessage();
        }
    }
    return $res;
}

/**
 * Advisory checks on a folder name (never blocking): date tags well formed and in
 * order, MM_DDMON prefix = START, _CK last, tags dropped compared with $oldName.
 * Returns a list of messages.
 */
function fs_name_warnings(string $name, string $oldName = ''): array {
    $w      = [];
    $up     = strtoupper($name);
    $months = bs_confirm_months();

    // Date tags, in name order: START, MIDT…, END (with year).
    $tags = [];
    if (preg_match_all('/_(START|MIDT|END)([0-9A-Z]*)/', $up, $m, PREG_SET_ORDER)) {
        foreach ($m as $t) {
            $kind = $t[1]; $val = $t[2];
            $re   = $kind === 'END' ? '/^(\d{2})([A-Z]{3})(\d{4})$/' : '/^(\d{2})([A-Z]{3})$/';
            if (!preg_match($re, $val, $p) || !isset($months[$p[2]]) || (int)$p[1] < 1 || (int)$p[1] > 31) {
                $w[] = '_' . $kind . $val . ' is not a valid date tag (expected ' . ($kind === 'END' ? 'ENDddMONyyyy, e.g. END08NOV2026' : $kind . 'ddMON, e.g. ' . $kind . '29OCT') . ').';
                continue;
            }
            $tags[] = ['kind' => $kind, 'tag' => $kind . $val, 'day' => (int)$p[1], 'mon' => (int)$months[$p[2]],
                       'year' => isset($p[3]) ? (int)$p[3] : null];
        }
    }
    $kinds = array_column($tags, 'kind');
    $hasStart = in_array('START', $kinds, true);
    $hasEnd   = in_array('END', $kinds, true);
    if ($hasStart && !$hasEnd && strpos($up, '_END') === false) $w[] = 'The name has a START date but no _ENDddMONyyyy tag.';
    if ($hasEnd && !$hasStart && strpos($up, '_START') === false) $w[] = 'The name has an END date but no _STARTddMON tag.';

    // Order START ≤ MIDT ≤ … ≤ END. Years resolved backwards from END as parse_folder_dates()
    // does: an earlier date is in the previous year only when its month is > next month + 1.
    if ($hasEnd && count($tags) >= 2 && end($tags)['kind'] === 'END') {
        $n = count($tags);
        $tags[$n - 1]['ymd'] = sprintf('%04d-%02d-%02d', $tags[$n - 1]['year'], $tags[$n - 1]['mon'], $tags[$n - 1]['day']);
        $nextMon = $tags[$n - 1]['mon']; $nextYr = $tags[$n - 1]['year'];
        for ($i = $n - 2; $i >= 0; $i--) {
            $yr = $tags[$i]['mon'] > $nextMon + 1 ? $nextYr - 1 : $nextYr;
            $tags[$i]['ymd'] = sprintf('%04d-%02d-%02d', $yr, $tags[$i]['mon'], $tags[$i]['day']);
            $nextMon = $tags[$i]['mon']; $nextYr = $yr;
        }
        for ($i = 1; $i < $n; $i++) {
            if ($tags[$i]['ymd'] < $tags[$i - 1]['ymd']) {
                $w[] = 'Dates not in order: ' . $tags[$i]['tag'] . ' (' . $tags[$i]['ymd'] . ') is before '
                     . $tags[$i - 1]['tag'] . ' (' . $tags[$i - 1]['ymd'] . ').';
            }
        }
        $span = (int)round((strtotime($tags[$n - 1]['ymd']) - strtotime($tags[0]['ymd'])) / 86400);
        if ($span > 60) $w[] = 'The trip spans ' . $span . ' days (' . $tags[0]['tag'] . ' → ' . $tags[$n - 1]['tag'] . ') — check the dates.';
    } elseif ($hasEnd && end($tags)['kind'] !== 'END') {
        $w[] = 'The END date is not the last date tag.';
    }

    // MM_DDMON_ prefix must match START.
    $start = null;
    foreach ($tags as $t) { if ($t['kind'] === 'START') { $start = $t; break; } }
    if ($start) {
        $want = sprintf('%02d_%s', $start['mon'], substr($start['tag'], 5));
        if (!preg_match('/^(\d{2})_(\d{2}[A-Z]{3})_/', $up, $pm)) {
            $w[] = 'The name does not start with the MM_DDMON_ prefix (expected "' . $want . '_").';
        } elseif ($pm[1] . '_' . $pm[2] !== $want) {
            $w[] = 'Prefix "' . $pm[1] . '_' . $pm[2] . '_" does not match ' . $start['tag'] . ' (expected "' . $want . '_").';
        }
    }

    // _CK is the last tag.
    $hasCk = (bool)preg_match('/_CK(?=_|$)/', $up);
    if ($hasCk && !preg_match('/_CK$/', $up)) $w[] = '_CK is not the last tag (it must stay at the end).';

    // Tags the old name had and the new one drops.
    if ($oldName !== '') {
        $oldUp = strtoupper($oldName);
        if (!$hasCk && preg_match('/_CK(?=_|$)/', $oldUp)) $w[] = 'The _CK marker is removed (the CK tracker will record it as un-checked).';
        $old = bo_status_from_name($oldName); $new = bo_status_from_name($name);
        if ($old['matched'] && !$new['matched']) $w[] = 'The status tag is removed — the Hub status / payment status stay as they are.';
        foreach (['START', 'END'] as $k) {
            if (strpos($oldUp, '_' . $k) !== false && strpos($up, '_' . $k) === false) $w[] = 'The _' . $k . ' date tag is removed.';
        }
    }
    return $w;
}

/**
 * Dry-run detail for a validated rename ($v from fs_rename_validate): current and
 * new Dropbox paths, destination clash, name warnings and (with $withCalc) the Calc
 * Excel dates vs the new name. Read-only. Requires dropbox_helper.php.
 *
 * Returns ['error'=>?string, 'code'=>int, 'dropbox_path_old', 'current_path', 'dropbox_path_new',
 *          'warnings'=>[], 'calc_dates'=>?array, 'candidates'=>[], 'file_renames'=>?array]
 * error set = the rename would fail (folder not found, destination exists).
 * Re-link ($v['relink']): dropbox_path_old = the stored path, current_path = the real one.
 * $withFiles: also list the "NN_<old name>…" files that rename_files would rename.
 */
function fs_rename_preview(PDO $db, array $v, string $token, bool $withCalc = true, bool $withFiles = false): array {
    $out = ['error' => null, 'code' => 200, 'dropbox_path_old' => null, 'current_path' => null, 'dropbox_path_new' => null,
            'warnings' => [], 'calc_dates' => null, 'candidates' => [], 'file_renames' => null];

    if (!empty($v['relink'])) {
        $rl = fs_relink_resolve($token, $v['current_path']);
        if (isset($rl['error'])) { $out['error'] = $rl['error']; $out['code'] = $rl['code']; return $out; }
        $curPath = $rl['path'];
        $out['dropbox_path_old'] = $v['stored_path'] !== '' ? $v['stored_path'] : null;
        $out['current_path']     = $curPath;
        if (fs_relink_books($v)) {
            $out['warnings'][] = 'Booked via re-link: no confirm snapshot, Rollback not available. confirmation_date will be set to today.';
        }
    } else {
        $curPath = fs_locate_folder($token, $v['r'], $v['folder']);
        if ($curPath === null) {
            $nf = fs_not_found($token, $v['r'], $v['folder']);
            $out['error'] = $nf['msg']; $out['candidates'] = $nf['candidates'];
            $out['code']  = 409;
            return $out;
        }
        $out['dropbox_path_old'] = $curPath;
    }
    $curName = basename($curPath);
    $out['warnings'] = array_merge(fs_name_warnings($v['new_name'], $curName), $out['warnings']);
    $newPath = rtrim(substr($curPath, 0, strrpos($curPath, '/')), '/') . '/' . $v['new_name'];
    $out['dropbox_path_new'] = $newPath;

    // A case-only change finds the folder itself — not a clash.
    if (strcasecmp($v['new_name'], $curName) !== 0 && dropbox_path_exists($token, $newPath)) {
        $out['error'] = 'A folder "' . $v['new_name'] . '" already exists in ' . dirname($newPath) . '.';
        $out['code']  = 409;
        return $out;
    }

    if ($withFiles) {
        try {
            $out['file_renames'] = fs_file_renames($token, $curPath, [$v['folder'], $curName], $v['new_name']);
        } catch (Throwable $e) {
            $out['warnings'][] = 'File list skipped — ' . $e->getMessage();
        }
    }

    // Another request already pointing at that name.
    $col = $v['is_group'] ? 'group_folder' : 'practice_code';
    $st  = $db->prepare("SELECT id FROM requests WHERE $col = ? AND id <> ? LIMIT 3");
    $st->execute([$v['new_name'], (int)$v['r']['id']]);
    if ($ids = $st->fetchAll(PDO::FETCH_COLUMN)) {
        $out['warnings'][] = 'Request(s) ' . implode(', ', $ids) . ' already use this ' . ($v['is_group'] ? 'group' : 'folder') . ' name in Hub.';
    }

    // Calc Excel dates vs the new name (same reader as the Confirm preview).
    if ($withCalc) {
        $nd = parse_folder_dates($v['new_name']);
        try {
            $xlsx = sc_fetch_calc_xlsx($token, $curPath);
            if ($xlsx) {
                $trip = sc_trip_dates(sc_xlsx_cells($xlsx));
                @unlink($xlsx);
                $out['calc_dates'] = ['start' => $trip['start'], 'end' => $trip['end']];
                if ($trip['start'] && $nd['start_date'] && $trip['start'] !== $nd['start_date']) {
                    $out['warnings'][] = 'Calc start ' . sc_fmt($trip['start']) . ' ≠ new name START ' . sc_fmt($nd['start_date']) . '.';
                }
                if ($trip['end'] && $nd['end_date'] && $trip['end'] !== $nd['end_date']) {
                    $out['warnings'][] = 'Calc end ' . sc_fmt($trip['end']) . ' ≠ new name END ' . sc_fmt($nd['end_date']) . '.';
                }
            }
        } catch (Throwable $e) {
            $out['warnings'][] = 'Calc check skipped — ' . $e->getMessage();
        }
    }
    return $out;
}

/**
 * After a successful bo_do_rename(): record it in the CK tracker (with the user) and
 * log a status_change timeline event — on every request of the group for a group.
 * $newStatus = the status written (null = status untouched).
 * $relinkedFrom = the stored path on a re-link (the CK tracker knows the folder by its
 * real name, $ckOld; the timeline notes the re-link).
 */
function fs_rename_record(PDO $db, int $requestId, bool $isGrp, string $folder, string $newFolder,
                          ?string $newStatus, ?int $userId, string $relinkedFrom = '', string $ckOld = ''): void {
    $ckFrom = $ckOld !== '' ? $ckOld : $folder;
    if ($ckFrom !== $newFolder) {
        try { ck_record_rename($db, $ckFrom, $newFolder, $userId ?: null); }
        catch (Throwable $ig) { /* tracking only — the next scan catches it */ }
    }

    $tlTitle = ($relinkedFrom !== '' ? 'Folder re-linked' : 'Folder renamed') . ($newStatus !== null ? ' — status ' . $newStatus : '');
    $tlBody  = $folder . "\n→ " . $newFolder;
    if ($relinkedFrom !== '') {
        $tlBody .= "\nrelinked from " . $relinkedFrom . ($ckOld !== '' && $ckOld !== $folder ? ' (found as ' . $ckOld . ')' : '');
    }
    if ($isGrp) {
        $tlIds = $db->prepare("SELECT id FROM requests WHERE group_folder = ?");
        $tlIds->execute([$newFolder]);
        foreach ($tlIds->fetchAll(PDO::FETCH_COLUMN) as $tlId) timeline_log((int)$tlId, 'status_change', 'Group ' . $tlTitle, ['body' => $tlBody]);
    } else {
        timeline_log($requestId, 'status_change', $tlTitle, ['body' => $tlBody]);
    }
}

/**
 * Free rename (BackOffice "Rename…", API rename_folder): rename the Dropbox folder,
 * update the Hub (practice_code / group_folder, dropbox_url, status / payment_status
 * when the new suffix carries a status tag), record it in the CK tracker and log a
 * status_change timeline event (every request of a group). Sends no email.
 *
 * $opt: 'current_path' (re-link a folder moved outside the Hub, see fs_rename_validate),
 *       'rename_files' (bool: also rename the "NN_<old name>…" files inside, e.g. the Calc).
 * A re-link that makes the request Booked also sets confirmation_date (if empty) and
 * start_date from the new name.
 *
 * Returns ['ok'=>bool, 'msg'=>string, 'code'=>int (on failure), 'not_found'=>bool, 'candidates'=>[],
 *          'is_group', 'old_name', 'new_name', 'dropbox_path_old', 'dropbox_path_new', 'relink',
 *          'files_renamed'=>[], 'files_failed'=>[]].
 */
function folder_rename(PDO $db, int $requestId, string $newName, ?int $userId, array $opt = []): array {
    $v = fs_rename_validate($db, $requestId, $newName, (string)($opt['current_path'] ?? ''));
    if (!$v['ok']) return ['ok' => false, 'msg' => $v['error'], 'code' => $v['code']];

    require_once __DIR__ . '/../dropbox_helper.php';
    $folder = $v['folder']; $newFolder = $v['new_name']; $isGrp = $v['is_group'];
    $curPath = null;
    try {
        $token = dropbox_get_access_token();
        if ($v['relink']) {
            $rl = fs_relink_resolve($token, $v['current_path']);
            if (isset($rl['error'])) return ['ok' => false, 'msg' => $rl['error'], 'code' => $rl['code']];
            $curPath = $rl['path'];
        }
        $res = bo_do_rename($db, $token, $v['r'], $isGrp, $folder, $newFolder, $v['status'], $v['ps'], $v['set_status'], $curPath);
    } catch (Throwable $e) {
        return ['ok' => false, 'msg' => 'Dropbox/DB error — nothing was changed: ' . $e->getMessage(), 'code' => 502];
    }
    if (!$res['ok']) {
        return ['ok' => false, 'msg' => $res['msg'], 'code' => 409,
                'not_found' => !empty($res['not_found']), 'candidates' => $res['candidates'] ?? []];
    }
    $curName = basename($res['old_path']);

    // Booked by a re-link (confirmed outside the Hub): what Confirm Safari would have set.
    if (fs_relink_books($v)) {
        $pd = parse_folder_dates($newFolder);
        $db->prepare("UPDATE requests SET confirmation_date = CURDATE(), start_date = COALESCE(?, start_date)
                      WHERE " . ($isGrp ? "group_folder = ?" : "id = ?") . " AND confirmation_date IS NULL")
           ->execute([$pd['start_date'], $isGrp ? $newFolder : (int)$v['r']['id']]);
    }

    fs_rename_record($db, (int)$v['r']['id'], $isGrp, $folder, $newFolder, $v['set_status'] ? $v['status'] : null, $userId,
                     $v['relink'] ? ($v['stored_path'] !== '' ? $v['stored_path'] : $folder) : '', $v['relink'] ? $curName : '');

    $msg = $res['msg'];
    if ($v['relink']) {
        $msg = '✔ ' . ($isGrp ? 'Group "' . $newFolder . '"' : $v['r']['customer_name']) . ': re-linked to ' . $res['new_path']
             . ($curName !== $newFolder ? ' (renamed from "' . $curName . '")' : '') . '.';
    }

    $files = ['done' => [], 'failed' => []];
    if (!empty($opt['rename_files'])) {
        try {
            $plan  = fs_file_renames($token, $res['new_path'], [$folder, $curName], $newFolder);
            $files = fs_apply_file_renames($token, $res['new_path'], $plan);
        } catch (Throwable $e) {
            $files['failed']['*'] = $e->getMessage();
        }
        if ($files['done'])   $msg .= ' ' . count($files['done']) . ' file(s) renamed.';
        if ($files['failed']) $msg .= ' ⚠ ' . count($files['failed']) . ' file(s) not renamed.';
    }

    return ['ok' => true, 'msg' => $msg, 'is_group' => $isGrp, 'old_name' => $folder, 'new_name' => $newFolder,
            'dropbox_path_old' => $res['old_path'], 'dropbox_path_new' => $res['new_path'], 'relink' => $v['relink'],
            'files_renamed' => $files['done'], 'files_failed' => $files['failed']];
}
