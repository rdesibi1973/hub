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
 * group (scope "group") renames that parent and updates every request in it. A GRP member's
 * own sub-folder is renamed / re-linked alone (scope "member", the default for a member).
 * fs_rename_plan() computes every DB write once: the dry run shows it (affected[]), the
 * confirm writes it, then checks it (rolled back on a mismatch).
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
 * rename), then the request's Dropbox folder id (stable across moves outside the Hub,
 * folder_link_service.php), then Dropbox search — which lags for a folder renamed minutes ago.
 * Null if not found.
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
    require_once __DIR__ . '/folder_link_service.php';
    $byId = folder_locate($token, fl_row(db(), $r), $folder);
    return $byId ?? dropbox_find_folder($token, $folder);
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

/** Is $p one of the booking roots (/2026, /2027, /001_Safari) or a container that is not a booking folder (00_2026 …)? */
function fs_is_container(string $p): bool {
    $p = rtrim($p, '/');
    if ($p === '') return true;
    foreach (fs_allowed_roots() as $root) { if (strcasecmp($p, $root) === 0) return true; }
    return !folder_is_booking_leaf(basename($p));
}

/**
 * Everything a rename / re-link writes, computed once and used by both the dry run (fs_rename_preview → affected[])
 * and the confirm (bo_do_rename), so the dry run shows exactly what the confirm writes.
 *
 * $scope: 'private' (the request's own folder), 'group' (the shared parent group_folder: every request of the group
 * follows), 'member' (a GRP member's own sub-folder: this request only — group_folder becomes the real parent's name; the
 * other members of the old group_folder follow only when their own sub-folder is found inside that parent).
 * $curPath: the folder's real current path (exact case). Dropbox is read only for scope 'member' (other members' folders).
 *
 * Returns ['ok', 'error', 'old_path', 'new_path', 'moved' (Dropbox rename needed), 'affected' => [
 *            {id, customer_name, folder_from, folder_to, group_folder_from, group_folder_to, dropbox_url_from, dropbox_url_to,
 *             path_to, status_from, status_to, payment_status_from, payment_status_to, status_set, payment_status_set}],
 *          'skipped' => [{id, customer_name, folder, reason}], 'folder_id' => member folder id or ''].
 * Requires dropbox_helper.php.
 */
function fs_rename_plan(PDO $db, string $token, array $r, string $scope, string $folder, string $newFolder,
                        ?string $newStatus, $newPs, bool $setStatus, string $curPath): array {
    $parentDir = rtrim(substr($curPath, 0, strrpos($curPath, '/')), '/');
    $newPath   = $parentDir . '/' . $newFolder;
    $out = ['ok' => true, 'error' => null, 'old_path' => $curPath, 'new_path' => $newPath,
            'moved' => strcmp(basename($curPath), $newFolder) !== 0, 'affected' => [], 'skipped' => [], 'folder_id' => ''];
    $item = function (array $g, string $pcTo, ?string $gfTo, string $pathTo, bool $stSet, ?string $stTo, bool $psSet, $psTo) {
        return ['id' => (int)$g['id'], 'customer_name' => (string)($g['customer_name'] ?? ''),
                'folder_from' => $g['practice_code'], 'folder_to' => $pcTo,
                'group_folder_from' => $g['group_folder'], 'group_folder_to' => $gfTo,
                'dropbox_url_from' => $g['dropbox_url'], 'dropbox_url_to' => bo_url_from_path($pathTo), 'path_to' => $pathTo,
                'status_from' => $g['status'] ?? null, 'status_to' => $stSet ? $stTo : ($g['status'] ?? null),
                'payment_status_from' => $g['payment_status'] ?? null, 'payment_status_to' => $psSet ? $psTo : ($g['payment_status'] ?? null),
                'status_set' => $stSet, 'payment_status_set' => $psSet];
    };

    if ($scope === 'group') {
        // The group parent sits in a root / archive container, never inside another booking folder (that is a member).
        if (!fs_is_container($parentDir)) {
            $out['ok'] = false;
            $out['error'] = $curPath . ' is inside the booking folder "' . basename($parentDir) . '" — that is a member sub-folder, not the group folder. '
                          . 'Give the group parent\'s path to rename the group, or use scope "member" (Agent API) to re-link only this member.';
            return $out;
        }
        // Each client keeps its own payment_status (its invoice / sub-folder): the group tag only sets the booking
        // status, and never revives a cancelled client unless the whole group is cancelled.
        $subs = $db->prepare("SELECT id, customer_name, practice_code, group_folder, dropbox_url, status, payment_status
                              FROM requests WHERE group_folder = ? ORDER BY id");
        $subs->execute([$folder]);
        foreach ($subs->fetchAll(PDO::FETCH_ASSOC) as $g) {
            $sub  = trim($g['practice_code'] ?? '');
            if ($sub === $folder) $sub = '';   // imported group (practice_code = group_folder): the folder itself
            $code = $sub !== '' ? (string)$g['practice_code'] : ($g['practice_code'] === $folder ? $newFolder : (string)$g['practice_code']);
            $keep = ($g['status'] ?? '') === 'Cancelled' && $newStatus !== 'Cancelled';
            $out['affected'][] = $item($g, $code, $newFolder, $sub !== '' ? $newPath . '/' . $sub : $newPath,
                                       $setStatus && $newStatus !== null && !$keep, $newStatus, false, null);
        }
        return $out;
    }

    $self = $r + ['payment_status' => null, 'status' => null, 'customer_name' => ''];
    if ($scope === 'private') {
        $out['affected'][] = $item($self, $newFolder, $r['group_folder'] ?? null, $newPath, $setStatus, $newStatus, $setStatus, $newPs);
        return $out;
    }

    // ── member: this request's own sub-folder inside the real group parent ──
    if (fs_is_container($parentDir)) {
        $out['ok'] = false;
        $out['error'] = 'The member folder ' . $curPath . ' is not inside a group folder (its parent is ' . ($parentDir !== '' ? $parentDir : '/')
                      . ') — refused. Give the path of the member\'s own sub-folder inside the group folder, or use scope "group" to rename the group parent.';
        return $out;
    }
    $oldGf = trim((string)($r['group_folder'] ?? ''));
    $newGf = basename($parentDir);
    $out['affected'][] = $item($self, $newFolder, $newGf, $newPath, $setStatus, $newStatus, $setStatus, $newPs);
    if (strcmp($newGf, $oldGf) === 0) return $out;

    // The parent's real name differs from the stored group_folder (renamed by hand): the other members of the same
    // stored group follow only when their own sub-folder is inside this parent (by id when they have one, else by name).
    require_once __DIR__ . '/folder_link_service.php';
    fl_schema($db);
    $o = $db->prepare("SELECT id, customer_name, practice_code, group_folder, dropbox_url, dropbox_id, status, payment_status
                       FROM requests WHERE group_folder = ? AND id <> ? ORDER BY id");
    $o->execute([$oldGf, (int)$r['id']]);
    foreach ($o->fetchAll(PDO::FETCH_ASSOC) as $g) {
        $sub = trim((string)$g['practice_code']);
        $isParentRow = $sub === '' || strcasecmp($sub, $oldGf) === 0;   // imported group / group parent request: the parent itself
        $cand = $isParentRow ? $parentDir : $parentDir . '/' . $sub;
        $m = null;
        try { $m = dropbox_get_metadata($token, $cand); } catch (Throwable $e) { /* reported as skipped */ }
        $gid = (string)($g['dropbox_id'] ?? '');
        if (!$m || $m['tag'] !== 'folder') {
            $out['skipped'][] = ['id' => (int)$g['id'], 'customer_name' => $g['customer_name'], 'folder' => $sub,
                                 'reason' => 'not found in ' . $parentDir . ' — left as it is (re-link it on its own)'];
            continue;
        }
        if ($gid !== '' && ($m['id'] ?? '') !== '' && $m['id'] !== $gid) {
            $out['skipped'][] = ['id' => (int)$g['id'], 'customer_name' => $g['customer_name'], 'folder' => $sub,
                                 'reason' => $cand . ' has another Dropbox id than the one stored — left as it is'];
            continue;
        }
        $out['affected'][] = $item($g, $isParentRow ? $newGf : (string)$g['practice_code'], $newGf, $m['path'], false, null, false, null);
    }
    return $out;
}

/** An affected[] row as shown to callers (dry run / confirm): without the internal *_set flags. */
function fs_affected_public(array $a): array {
    $x = ['id' => $a['id'], 'customer_name' => $a['customer_name'],
          'folder_from' => $a['folder_from'], 'folder_to' => $a['folder_to'],
          'group_folder_from' => $a['group_folder_from'], 'group_folder_to' => $a['group_folder_to'],
          'dropbox_url_from' => $a['dropbox_url_from'], 'dropbox_url_to' => $a['dropbox_url_to']];
    if ($a['status_set'])         { $x['status_from'] = $a['status_from']; $x['status_to'] = $a['status_to']; }
    if ($a['payment_status_set']) { $x['payment_status_from'] = $a['payment_status_from']; $x['payment_status_to'] = $a['payment_status_to']; }
    return $x;
}

/** Write a plan's affected[] rows (fs_rename_plan). $undo: write the *_from values back instead. */
function fs_plan_write(PDO $db, array $affected, bool $undo = false): void {
    $sfx = $undo ? '_from' : '_to';
    foreach ($affected as $a) {
        $set = ['practice_code = ?', 'group_folder = ?', 'dropbox_url = ?'];
        $val = [$a['folder' . $sfx], $a['group_folder' . $sfx], $undo ? $a['dropbox_url_from'] : $a['dropbox_url_to']];
        if ($a['status_set'])         { $set[] = 'status = ?';         $val[] = $a['status' . $sfx]; }
        if ($a['payment_status_set']) { $set[] = 'payment_status = ?'; $val[] = $a['payment_status' . $sfx]; }
        $val[] = (int)$a['id'];
        $db->prepare("UPDATE requests SET " . implode(', ', $set) . " WHERE id = ?")->execute($val);
    }
}

/**
 * After the write: each touched request must now store the planned path (dropbox_url) and folder names, and the renamed
 * folder must exist in Dropbox. Returns [problem text] (empty = fine) and sets $folderId to the renamed folder's id.
 */
function fs_plan_verify(PDO $db, string $token, array $plan, string &$folderId): array {
    $bad = [];
    $st  = $db->prepare("SELECT practice_code, group_folder, dropbox_url FROM requests WHERE id = ?");
    foreach ($plan['affected'] as $a) {
        $st->execute([(int)$a['id']]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $p = bo_path_from_url((string)($row['dropbox_url'] ?? ''));
        if (strcasecmp($p, $a['path_to']) !== 0) {
            $bad[] = 'Request ' . $a['id'] . ' would point to ' . ($p !== '' ? $p : '(nothing)') . ' instead of ' . $a['path_to'];
        } elseif ((string)($row['practice_code'] ?? '') !== (string)$a['folder_to'] || (string)($row['group_folder'] ?? '') !== (string)$a['group_folder_to']) {
            $bad[] = 'Request ' . $a['id'] . ': folder / group_folder not written as planned';
        }
    }
    $m = null;
    try { $m = dropbox_get_metadata($token, $plan['new_path']); } catch (Throwable $e) { $bad[] = 'Dropbox check failed: ' . $e->getMessage(); }
    if ($m === null || ($m['tag'] ?? '') !== 'folder') { if (!$bad) $bad[] = $plan['new_path'] . ' not found in Dropbox after the change'; }
    else $folderId = (string)($m['id'] ?? '');
    return $bad;
}

/**
 * Rename a booking's Dropbox folder and sync the DB.
 * $scope (fs_rename_plan): 'private' | 'group' (the shared parent: every request in it, rebuilding each sub's
 * dropbox_url; status only — each client keeps its own payment_status) | 'member' (a GRP member's own sub-folder,
 * this request only). null = 'group' when $isGrp, else 'private'.
 * When $setStatus, also writes status (and payment_status, not for a group).
 * Dropbox move happens first; the DB is only touched if it succeeds. After the write every touched path is checked:
 * on a mismatch the DB is put back (and the Dropbox move undone) → ok false, code 409, verify_failed[].
 * $curPath: the folder's real path when already known (re-link); null = locate it from
 * the stored URL / by name. A new name equal to its basename = no Dropbox move.
 * Requires dropbox_helper.php.
 */
function bo_do_rename(PDO $db, string $token, array $r, bool $isGrp,
                      string $folder, string $newFolder,
                      ?string $newStatus, $newPs, bool $setStatus, ?string $curPath = null, ?string $scope = null): array {
    $scope = $scope ?? ($isGrp ? 'group' : 'private');
    if ($curPath === null) $curPath = fs_locate_folder($token, $r, $folder);
    if ($curPath === null) {
        $nf = fs_not_found($token, $r, $folder);
        return ['ok' => false, 'not_found' => true, 'msg' => $nf['msg'], 'candidates' => $nf['candidates']];
    }
    if (!array_key_exists('payment_status', $r) || !array_key_exists('customer_name', $r) || !array_key_exists('status', $r)) {
        $q = $db->prepare("SELECT customer_name, status, payment_status FROM requests WHERE id = ?");
        $q->execute([(int)$r['id']]);
        $r = array_merge($q->fetch(PDO::FETCH_ASSOC) ?: [], $r);
    }
    $plan = fs_rename_plan($db, $token, $r, $scope, $folder, $newFolder, $newStatus, $newPs, $setStatus, $curPath);
    if (!$plan['ok']) return ['ok' => false, 'code' => 409, 'msg' => $plan['error']];
    $newPath = $plan['new_path'];

    if ($plan['moved']) dropbox_move_folder($token, $curPath, $newPath);   // subfolders move with the parent
    fs_plan_write($db, $plan['affected']);

    $folderId = '';
    $bad = fs_plan_verify($db, $token, $plan, $folderId);
    if ($bad) {
        fs_plan_write($db, $plan['affected'], true);
        $undo = '';
        if ($plan['moved']) {
            try { dropbox_move_folder($token, $newPath, $curPath); $undo = ' The Dropbox rename was undone.'; }
            catch (Throwable $e) { $undo = ' ⚠ The Dropbox rename could NOT be undone (' . $e->getMessage() . '): the folder is now ' . $newPath . '.'; }
        }
        error_log('bo_do_rename verify failed, request ' . (int)$r['id'] . ': ' . implode(' · ', $bad));
        return ['ok' => false, 'code' => 409, 'verify_failed' => $bad, 'affected' => $plan['affected'],
                'msg' => 'Check after the change failed — the Hub was put back as it was.' . $undo . ' ' . implode(' · ', $bad)];
    }

    $base = ['ok' => true, 'old_path' => $curPath, 'new_path' => $newPath, 'scope' => $scope, 'folder_id' => $folderId,
             'affected' => $plan['affected'], 'skipped' => $plan['skipped']];
    if ($scope === 'group') {
        // Cancelled group: the Booking follow-up lines of the group and of its members close at once (auto_cancelled).
        if (folder_is_cancelled($newFolder)) folder_followup_cancelled($db, null, $newFolder, 'group folder renamed ' . $newFolder);
        return $base + ['msg' => '✔ Group "' . $newFolder . '": renamed (' . count($plan['affected']) . ' booking(s) updated).'];
    }
    // Cancelled trip: its Booking follow-up lines close at once (auto_cancelled).
    if (folder_is_cancelled($newFolder) || ($setStatus && $newStatus === 'Cancelled')) {
        folder_followup_cancelled($db, (int)$r['id'], $newFolder, 'folder renamed ' . $newFolder);
    }
    if ($scope === 'member') {
        $others = count($plan['affected']) - 1;
        return $base + ['msg' => '✔ Member "' . $newFolder . '" (' . $r['customer_name'] . '): renamed in group folder "' . basename(dirname($newPath)) . '"'
                               . ($others > 0 ? ' (' . $others . ' other member(s) follow the group folder name)' : '') . '.'];
    }
    return $base + ['msg' => '✔ ' . $r['customer_name'] . ': renamed to "' . $newFolder . '".'];
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
 *
 * $scope (GRP member = group_folder set and its own sub-folder, practice_code ≠ group_folder):
 *   'member' — the member's own sub-folder, this request only (the default for a member: Roberto 18 Nov 2026, after a
 *              member re-link renamed the whole group); current_path = the member sub-folder;
 *   'group'  — the shared group parent and every request of the group; current_path = the parent folder.
 * A request without group_folder is 'private'; a group request without its own sub-folder (imported group, group
 * parent) is always 'group'. The returned 'scope' is the resolved one; 'is_group' = (scope === 'group').
 */
function fs_rename_validate(PDO $db, int $requestId, string $newName, string $currentPath = '', string $scope = ''): array {
    $st = $db->prepare("SELECT id, customer_name, practice_code, group_folder, dropbox_url, status, payment_status,
                               confirmation_date, start_date
                        FROM requests WHERE id = ?");
    $st->execute([$requestId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) return ['ok' => false, 'code' => 404, 'error' => 'Request not found.'];

    $gf = trim((string)($r['group_folder'] ?? ''));
    $pc = trim((string)($r['practice_code'] ?? ''));
    $scope = strtolower(trim($scope));
    if (!in_array($scope, ['', 'member', 'group'], true)) {
        return ['ok' => false, 'code' => 400, 'error' => 'scope must be "member" or "group".'];
    }
    $hasSub = $gf !== '' && $pc !== '' && strcasecmp($pc, $gf) !== 0;
    $scopeAuto = $scope === '';
    if ($gf === '') {
        if ($scope !== '') return ['ok' => false, 'code' => 400, 'error' => 'This request is not part of a group — leave scope out.'];
        $scope = 'private';
    } elseif (!$hasSub) {
        if ($scope === 'member') return ['ok' => false, 'code' => 400, 'error' => 'This group request has no member sub-folder of its own — only scope "group" applies.'];
        $scope = 'group';
    } elseif ($scope === '') {
        $scope = 'member';
    }
    $isGrp  = $scope === 'group';
    $folder = $isGrp ? $gf : $pc;
    if ($folder === '') return ['ok' => false, 'code' => 400, 'error' => 'This request has no folder to rename.'];

    $newName = trim($newName);
    if ($newName === '') return ['ok' => false, 'code' => 400, 'error' => 'New name is empty.'];
    if (preg_match(FS_BAD_CHARS_RE, $newName)) {
        return ['ok' => false, 'code' => 400, 'error' => 'Invalid characters in the new name (\\ / : * ? " < > | are not allowed).'];
    }
    // A member's own folder never carries the group's MM_DDMON_ prefix: such a name meant the group parent.
    if ($scope === 'member' && $scopeAuto && preg_match('/^\d{1,2}_\d{1,2}[A-Za-z]{3}_/', $newName)) {
        return ['ok' => false, 'code' => 400, 'error' => 'Request ' . $requestId . ' is a member of group "' . $gf . '": "' . $newName
              . '" looks like a group folder name. Pass scope "group" to rename the group parent (all its members), or give the member\'s own sub-folder name.'];
    }

    // Stored path of the renamed folder (group: the parent of the member's URL; member: its own sub-folder).
    if ($scope === 'member') {
        $stored = req_folder_path($r);
    } else {
        $stored = bo_path_from_url((string)($r['dropbox_url'] ?? ''));
        $p = $stored;
        while ($p !== '' && $p !== '/' && strcasecmp(basename($p), $folder) !== 0) $p = rtrim(dirname($p), '/');
        if ($p !== '' && $p !== '/') $stored = $p;
    }

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
        $clash = fs_path_clash($db, (int)$r['id'], $cur, $isGrp ? $folder : '');
        if ($clash) {
            return ['ok' => false, 'code' => 409, 'error' => 'Request(s) ' . implode(', ', array_slice($clash, 0, 5))
                  . ' already point to ' . $cur . ' — re-link refused.'];
        }
    } elseif (strcmp($newName, $folder) === 0) {
        return ['ok' => false, 'code' => 400, 'error' => 'The new name is identical — nothing to change.'];
    }

    // Keep the DB status in sync with the new name's suffix (if it has one).
    $d = bo_status_from_name($newName);
    return ['ok' => true, 'r' => $r, 'is_group' => $isGrp, 'scope' => $scope, 'folder' => $folder, 'new_name' => $newName,
            'set_status' => $d['matched'], 'status' => $d['status'], 'ps' => $d['ps'],
            'relink' => $cur !== '', 'current_path' => $cur !== '' ? $cur : null, 'stored_path' => $stored];
}

/**
 * Other requests already pointing to the folder at $cur (or inside it) → [request id, …]. $groupFolder: members of
 * this group live inside the group parent — not a clash.
 */
function fs_path_clash(PDO $db, int $requestId, string $cur, string $groupFolder = ''): array {
    $base = basename($cur);
    $q = $db->prepare("SELECT id, practice_code, group_folder, dropbox_url FROM requests
                       WHERE id <> ? AND (practice_code = ? OR group_folder = ? OR dropbox_url LIKE ?)");
    $q->execute([$requestId, $base, $base, '%' . rawurlencode($base) . '%']);
    $clash = [];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $o) {
        if ($groupFolder !== '' && trim($o['group_folder'] ?? '') === $groupFolder) continue;
        $op = bo_path_from_url((string)($o['dropbox_url'] ?? ''));
        if (($op !== '' && (strcasecmp($op, $cur) === 0 || stripos($op, $cur . '/') === 0))
            || ($op === '' && (strcasecmp((string)$o['practice_code'], $base) === 0 || strcasecmp((string)$o['group_folder'], $base) === 0))) {
            $clash[] = (int)$o['id'];
        }
    }
    return $clash;
}

/**
 * Dropbox path of the folder after a validated rename ($v from fs_rename_validate): the parent of current_path (re-link)
 * or of the stored path, plus the new name ('' when neither is known). For the booking gate (reopen as a quote).
 */
function fs_dest_path(array $v): string {
    $from = !empty($v['current_path']) ? (string)$v['current_path'] : (string)($v['stored_path'] ?? '');
    if ($from === '' || $from === '/') return '';
    return rtrim(dirname($from), '/') . '/' . $v['new_name'];
}

/**
 * Re-link: check current_path in Dropbox. Returns ['path' => real-case path] or
 * ['error' => msg, 'code' => 404|400]. Requires dropbox_helper.php.
 */
function fs_relink_resolve(string $token, string $path): array {
    $m = dropbox_get_metadata($token, $path);
    if ($m === null)            return ['error' => 'current_path "' . $path . '" does not exist in Dropbox.', 'code' => 404];
    if ($m['tag'] !== 'folder') return ['error' => 'current_path "' . $path . '" is a file, not a folder.', 'code' => 400];
    return ['path' => $m['path'], 'id' => $m['id'] ?? ''];
}

/**
 * True when this rename turns the request Booked without a Hub confirmation (re-link of a
 * folder confirmed outside the Hub): no pre_confirm snapshot, confirmation_date empty.
 */
function fs_relink_books(array $v): bool {
    return !empty($v['relink']) && $v['set_status'] && $v['status'] === 'Booked'
        && ($v['r']['status'] ?? '') !== 'Booked' && empty($v['r']['confirmation_date']);
}

// fs_name_core() (customer part of a folder name) lives in booking_service.php: copy_program uses it too.

/**
 * Files inside $dir (recursive) named "NN_<old>…", <old> = one of $oldNames or its customer
 * part (fs_name_core) → "NN_<customer part of $newName>…" (the file prefix never carries the
 * dates / status, as after a Hub confirm). Returns [['from' => rel path, 'to' => rel path], …].
 * Requires dropbox_helper.php.
 */
function fs_file_renames(string $token, string $dir, array $oldNames, string $newName): array {
    $newName = fs_name_core($newName);
    $olds = [];
    foreach ($oldNames as $o) { $olds[] = $o; $olds[] = fs_name_core($o); }
    $olds = array_values(array_unique(array_filter($olds, 'strlen')));
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
 *          'warnings'=>[], 'calc_dates'=>?array, 'candidates'=>[], 'file_renames'=>?array,
 *          'affected'=>[every request the confirm writes, fs_affected_public()], 'skipped'=>[]]
 * error set = the rename would fail (folder not found, destination exists).
 * Re-link ($v['relink']): dropbox_path_old = the stored path, current_path = the real one.
 * $withFiles: also list the "NN_<old name>…" files that rename_files would rename.
 */
function fs_rename_preview(PDO $db, array $v, string $token, bool $withCalc = true, bool $withFiles = false): array {
    $out = ['error' => null, 'code' => 200, 'dropbox_path_old' => null, 'current_path' => null, 'dropbox_path_new' => null,
            'warnings' => [], 'calc_dates' => null, 'candidates' => [], 'file_renames' => null, 'affected' => [], 'skipped' => []];

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
    // Group parent: the programme right after the code (…_GRP0209Nyumbu(…)) — a warning, not a block.
    if (!empty($v['is_group']) && ($gw = bo_grp_rename_warning($v['new_name'])) !== '') $out['warnings'][] = $gw;
    $newPath = rtrim(substr($curPath, 0, strrpos($curPath, '/')), '/') . '/' . $v['new_name'];
    $out['dropbox_path_new'] = $newPath;

    // A case-only change finds the folder itself — not a clash.
    if (strcasecmp($v['new_name'], $curName) !== 0 && dropbox_path_exists($token, $newPath)) {
        $out['error'] = 'A folder "' . $v['new_name'] . '" already exists in ' . dirname($newPath) . '.';
        $out['code']  = 409;
        return $out;
    }

    // Exactly what the confirm writes (same plan as bo_do_rename).
    $plan = fs_rename_plan($db, $token, $v['r'], (string)($v['scope'] ?? ($v['is_group'] ? 'group' : 'private')), $v['folder'], $v['new_name'],
                           $v['status'], $v['ps'], $v['set_status'], $curPath);
    $out['affected'] = array_map('fs_affected_public', $plan['affected']);
    $out['skipped']  = $plan['skipped'];
    if (!$plan['ok']) { $out['error'] = $plan['error']; $out['code'] = 409; return $out; }
    foreach ($plan['skipped'] as $s) {
        $out['warnings'][] = 'Request ' . $s['id'] . ' (' . $s['customer_name'] . ') keeps its stored folder: ' . $s['reason'] . '.';
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
 * A rename that adds _CK ($v from fs_rename_validate) while the booking still has a provisional booking: the reasons
 * (ck_pipeline_block(): open follow-up lines + with $token the invoices/ documents read as provisional).
 * → ['blocked' => bool, 'by' => [text]] (not adding _CK, or no CK tracker row: not blocked).
 */
function fs_ck_block(PDO $db, array $v, ?string $token): array {
    require_once __DIR__ . '/ck_pipeline.php';
    if (!ck_has_marker($v['new_name']) || ck_has_marker(basename((string)$v['folder']))) return ['blocked' => false, 'by' => []];
    try {
        $row = ck_tracker_row_for($db, null, (int)$v['r']['id']);
        return $row ? ck_pipeline_block($db, $row, $token) : ['blocked' => false, 'by' => []];
    } catch (Throwable $e) {
        error_log('fs_ck_block: ' . $e->getMessage());
        return ['blocked' => false, 'by' => []];
    }
}

/**
 * Free rename (BackOffice "Rename…", API rename_folder): rename the Dropbox folder,
 * update the Hub (practice_code / group_folder, dropbox_url, status / payment_status
 * when the new suffix carries a status tag), record it in the CK tracker and log a
 * status_change timeline event (every request of a group). Sends no email.
 *
 * $opt: 'current_path' (re-link a folder moved outside the Hub, see fs_rename_validate),
 *       'scope' ('member' | 'group' for a GRP member, see fs_rename_validate; default member),
 *       'rename_files' (bool: also rename the "NN_<old name>…" files inside, e.g. the Calc).
 * A re-link that makes the request Booked also sets confirmation_date (if empty) and
 * start_date from the new name.
 *
 * Returns ['ok'=>bool, 'msg'=>string, 'code'=>int (on failure), 'not_found'=>bool, 'candidates'=>[], 'verify_failed'=>?[],
 *          'is_group', 'scope', 'old_name', 'new_name', 'dropbox_path_old', 'dropbox_path_new', 'relink',
 *          'affected'=>[every request written], 'skipped'=>[], 'files_renamed'=>[], 'files_failed'=>[]].
 */
function folder_rename(PDO $db, int $requestId, string $newName, ?int $userId, array $opt = []): array {
    $v = fs_rename_validate($db, $requestId, $newName, (string)($opt['current_path'] ?? ''), (string)($opt['scope'] ?? ''));
    if (!$v['ok']) return ['ok' => false, 'msg' => $v['error'], 'code' => $v['code']];

    require_once __DIR__ . '/../dropbox_helper.php';
    $folder = $v['folder']; $newFolder = $v['new_name']; $isGrp = $v['is_group']; $scope = $v['scope'];
    $curPath = null;
    try {
        $token = dropbox_get_access_token();
        // _CK while a booking is still provisional or a group is not closed: refused, no override (ck_pipeline.php).
        $block = fs_ck_block($db, $v, $token);
        if ($block['blocked']) {
            return ['ok' => false, 'code' => 409, 'msg' => '⛔ No _CK yet — ' . ck_pipeline_block_message($block) . '.',
                    'ck_blocked_by' => $block['by']];
        }
        // Out of _PROGRESS (→ DEPOSIT / BALANCE / BALANCE-CASH / PAID): the booking check must have passed (booking_gate_service.php).
        $gate = null;
        if (!$isGrp) {
            require_once __DIR__ . '/booking_gate_service.php';
            $gate = booking_gate_check($db, (int)$v['r']['id'], $folder, $newFolder, $userId, (string)($opt['gate_via'] ?? 'status_change'),
                                       false, ['dest_path' => fs_dest_path($v)]);
            if ($gate['refused']) return ['ok' => false, 'code' => 409, 'msg' => booking_gate_message($gate), 'booking_gate' => $gate];
        }
        if ($v['relink']) {
            $rl = fs_relink_resolve($token, $v['current_path']);
            if (isset($rl['error'])) return ['ok' => false, 'msg' => $rl['error'], 'code' => $rl['code']];
            $curPath = $rl['path'];
        }
        $res = bo_do_rename($db, $token, $v['r'], $isGrp, $folder, $newFolder, $v['status'], $v['ps'], $v['set_status'], $curPath, $scope);
    } catch (Throwable $e) {
        return ['ok' => false, 'msg' => 'Dropbox/DB error — nothing was changed: ' . $e->getMessage(), 'code' => 502];
    }
    if (!$res['ok']) {
        return ['ok' => false, 'msg' => $res['msg'], 'code' => (int)($res['code'] ?? 409),
                'not_found' => !empty($res['not_found']), 'candidates' => $res['candidates'] ?? [],
                'verify_failed' => $res['verify_failed'] ?? null];
    }
    $curName = basename($res['old_path']);
    // Private folder / member sub-folder: its Dropbox id is the stable link from now on (a group keeps each member's own id).
    if (!$isGrp) {
        $fid = (string)($res['folder_id'] ?? '');
        if ($fid === '' && !empty($rl['id'])) $fid = (string)$rl['id'];
        if ($fid !== '') {
            require_once __DIR__ . '/folder_link_service.php';
            folder_id_save($db, (int)$v['r']['id'], $fid);
        }
    }
    // Member re-link where the parent's real name differs: the other members that followed get their own timeline note.
    if ($scope === 'member') {
        foreach ($res['affected'] as $a) {
            if ((int)$a['id'] === (int)$v['r']['id']) continue;
            timeline_log((int)$a['id'], 'folder_relinked', 'Group folder name updated',
                         ['body' => (string)$a['group_folder_from'] . "\n→ " . (string)$a['group_folder_to'] . "\n(while re-linking request "
                                    . (int)$v['r']['id'] . ' ' . $v['r']['customer_name'] . ')',
                          'refs' => ['dropbox_path' => bo_path_from_url((string)$a['dropbox_url_to'])]]);
        }
    }

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
        $who = $isGrp ? 'Group "' . $newFolder . '"'
             : ($scope === 'member' ? 'Member "' . $newFolder . '" (' . $v['r']['customer_name'] . ')' : $v['r']['customer_name']);
        $others = $scope === 'member' ? count($res['affected']) - 1 : 0;
        $msg = '✔ ' . $who . ': re-linked to ' . $res['new_path']
             . ($curName !== $newFolder ? ' (renamed from "' . $curName . '")' : '')
             . ($others > 0 ? ' — ' . $others . ' other member(s) follow the group folder name' : '') . '.';
    }
    foreach ($res['skipped'] ?? [] as $s) {
        $msg .= ' ⚠ Request ' . $s['id'] . ' (' . $s['customer_name'] . ') keeps its stored folder: ' . $s['reason'] . '.';
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

    if (!empty($gate['gated']) && $gate['reason'] !== '' && !in_array($gate['state'], ['passed', 'overridden'], true)) $msg .= ' ⚠ ' . $gate['reason'];
    if ($isGrp && ($gw = bo_grp_rename_warning($newFolder)) !== '') $msg .= ' ⚠ ' . $gw;
    return ['ok' => true, 'msg' => $msg, 'is_group' => $isGrp, 'scope' => $scope, 'old_name' => $folder, 'new_name' => $newFolder, 'booking_gate' => $gate,
            'dropbox_path_old' => $res['old_path'], 'dropbox_path_new' => $res['new_path'], 'relink' => $v['relink'],
            'affected' => array_map('fs_affected_public', $res['affected']), 'skipped' => $res['skipped'],
            'files_renamed' => $files['done'], 'files_failed' => $files['failed']];
}

// ── Reopen a cancelled booking as a quote ────────────────────────────────────

/** Hub statuses a reopened booking may get. */
const FS_REOPEN_TO = ['Quoted', 'Hot-Quoted', 'Inquiry'];

/**
 * Inquiry form of a booking folder name: "09_28SEP_GaiaSpagnoletto(Anderson-Drct)_START28SEP_END02OCT2026_CANCELLED"
 * → "GaiaSpagnoletto(Anderson-Drct)" (drops MM_DDMON_, START / END, status tag, _CK). '' when there is no (Agency-Agent).
 */
function fs_inquiry_name(string $name): string {
    $s = preg_replace('/^\d{1,2}_\d{1,2}[A-Za-z]{3}_/', '', trim($name));
    return preg_match('/^([^()\/]+\([^()\/]*\))/', (string)$s, $m) ? trim($m[1]) : '';
}

/** Active CK tracker rows of a folder (by its Dropbox id or one of its names). → [['id', 'folder_name'], …]. Never throws. */
function fs_ck_rows_for_folder(PDO $db, string $dbxId, array $names): array {
    try {
        ck_ensure_schema($db);
        $names = array_values(array_filter(array_unique($names), 'strlen'));
        if ($dbxId === '' && !$names) return [];
        $w = []; $a = [];
        if ($dbxId !== '') { $w[] = 'dropbox_id = ?'; $a[] = $dbxId; }
        if ($names) { $w[] = 'folder_name IN (' . implode(',', array_fill(0, count($names), '?')) . ')'; $a = array_merge($a, $names); }
        $st = $db->prepare("SELECT id, folder_name FROM ck_folders WHERE gone = 0 AND (" . implode(' OR ', $w) . ")");
        $st->execute($a);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('fs_ck_rows_for_folder: ' . $e->getMessage());
        return [];
    }
}

/**
 * Reopen a cancelled private booking as a quote (Agent API reopen_cancelled, request page "Reopen as quote", Roberto
 * 17 Nov 2026): its folder goes back from /001_Safari (usually 00_CANCELED/00_<year>/) to /<year>/<Customer>(<Agency>-<Agent>)
 * and the request becomes Quoted (or Hot-Quoted / Inquiry). The booking gate allows it (bg_reopen_quote_ok()).
 *
 * $opt: 'status' (Quoted default | Hot-Quoted | Inquiry), 'new_name' (default fs_inquiry_name() of the current name),
 *       'year' (inquiry root; default the trip year when it is an inquiry root, else DROPBOX_BASE_PATH),
 *       'current_path' (the folder's real path when it was already moved by hand, e.g. into /2026).
 * Refused: request not found (404); bad status / new_name / year / current_path (400); Booked, group, other status
 * than Cancelled / Quoted / Hot-Quoted / Inquiry, not cancelled (by name or status) without a current_path under the
 * inquiry root, destination exists, folder not found (409).
 * !$go = dry run: nothing written except the folder self-heal every reader does.
 * $go: Dropbox move (the folder keeps its id), practice_code / dropbox_url / dropbox_id, payment_status and
 * confirmation_date cleared (snapshot and history stay), status (bs_set_request_status), active CK tracker rows marked
 * gone, timeline folder_relinked. Invoices and Booking follow-up lines are never touched (warnings only).
 *
 * → ['ok', 'code', 'error' | 'msg', 'request_id', 'from', 'stored_path', 'to', 'new_name', 'moved', 'status_from',
 *    'status_to', 'payment_status_from', 'confirmation_date_from', 'invoices' [], 'followup_open', 'ck_rows' [],
 *    'warnings' [], 'candidates' [] (folder not found)]. Throws only when Dropbox cannot be reached before any change.
 * Requires dropbox_helper.php.
 */
function folder_reopen_quote(PDO $db, int $requestId, array $opt, bool $go, ?int $userId): array {
    require_once __DIR__ . '/booking_gate_service.php';   // bg_* rules, followup_open_counts()
    require_once __DIR__ . '/folder_link_service.php';
    $fail = function (int $code, string $msg, array $extra = []) {
        return ['ok' => false, 'code' => $code, 'error' => $msg] + $extra;
    };

    $r = bg_request($db, $requestId);
    if (!$r) return $fail(404, 'Request not found.');
    $statusTo = trim((string)($opt['status'] ?? ''));
    if ($statusTo === '') $statusTo = 'Quoted';
    if (!in_array($statusTo, FS_REOPEN_TO, true)) return $fail(400, 'status must be one of: ' . implode(', ', FS_REOPEN_TO) . '.');
    $statusFrom = (string)($r['status'] ?? '');
    if ($statusFrom === 'Booked') {
        return $fail(409, 'Request ' . $requestId . ' is Booked — only a cancelled booking can be reopened as a quote.');
    }
    if (bg_is_group($r)) return $fail(409, 'Group bookings (GRP member / group parent) cannot be reopened as a quote.');
    if (!in_array($statusFrom, BG_REOPEN_STATUSES, true)) {
        return $fail(409, 'Request status is ' . ($statusFrom !== '' ? $statusFrom : 'empty') . ' — only a Cancelled (or Quoted / Hot-Quoted / Inquiry) request can be reopened as a quote.');
    }
    if (trim((string)($r['practice_code'] ?? '')) === '' && trim((string)($r['dropbox_url'] ?? '')) === '') {
        return $fail(400, 'This request has no folder.');
    }
    $roots    = bg_inquiry_roots();
    $inRoots  = function (string $p, array $rs): bool {
        foreach ($rs as $root) { if (stripos($p, $root . '/') === 0 && strlen($p) > strlen($root) + 1) return true; }
        return false;
    };
    $warnings = [];
    $stored   = req_folder_path($r);
    $token    = dropbox_get_access_token();

    // Where the folder is now.
    $curIn = fs_norm_path((string)($opt['current_path'] ?? ''));
    $curPath = null; $curId = '';
    if ($curIn !== '') {
        if (!$inRoots($curIn, fs_allowed_roots())) return $fail(400, 'current_path must be a folder under ' . implode(', ', fs_allowed_roots()) . '.');
        foreach (explode('/', ltrim($curIn, '/')) as $seg) {
            if ($seg === '' || $seg === '.' || $seg === '..' || preg_match('#[:*?"<>|]#', $seg)) return $fail(400, 'current_path is not a valid Dropbox path.');
        }
        $rl = fs_relink_resolve($token, $curIn);
        if (isset($rl['error'])) return $fail($rl['code'], $rl['error']);
        $curPath = $rl['path']; $curId = (string)($rl['id'] ?? '');
        if (strcasecmp($curPath, $stored) !== 0 && ($clash = fs_path_clash($db, $requestId, $curPath))) {
            return $fail(409, 'Request(s) ' . implode(', ', array_slice($clash, 0, 5)) . ' already point to ' . $curPath . ' — reopen refused.');
        }
    } else {
        $h = folder_heal($db, $token, $r);   // stored path → Dropbox id (heals the link)
        $f = $h['_folder'];
        unset($h['_folder']);
        if (in_array($f['state'], ['ok', 'relinked'], true) && $f['path']) {
            $r = $h; $curPath = $f['path']; $curId = (string)($f['id'] ?? '');
            if ($f['state'] === 'relinked') $warnings[] = 'Folder link healed first: ' . $f['old_path'] . ' → ' . $f['path'] . '.';
        } elseif (trim((string)($r['practice_code'] ?? '')) !== '') {
            $found = dropbox_find_folder($token, trim((string)$r['practice_code']));   // no id stored: by name (also inside 00_CANCELED)
            $m = $found ? dropbox_get_metadata($token, $found) : null;
            if ($m && $m['tag'] === 'folder') { $curPath = $m['path']; $curId = (string)$m['id']; }
        }
        if ($curPath === null) {
            $nf = fs_not_found($token, $r, trim((string)($r['practice_code'] ?? '')));
            return $fail(409, $nf['msg'], ['candidates' => $nf['candidates']]);
        }
    }
    $folder  = trim((string)($r['practice_code'] ?? ''));
    $curName = basename($curPath);
    $cancelled = $statusFrom === 'Cancelled' || bg_tag($folder) === 'CANCELLED' || bg_tag($curName) === 'CANCELLED'
              || folder_path_is_cancelled($curPath);
    if (!$cancelled && !($curIn !== '' && $inRoots($curPath, $roots))) {
        return $fail(409, 'Not a cancelled booking (folder "' . $curName . '", status ' . $statusFrom . '). Give current_path when it was already moved by hand into ' . implode(' / ', $roots) . '.');
    }

    // Destination.
    $newName = trim((string)($opt['new_name'] ?? ''));
    if ($newName === '') $newName = fs_inquiry_name($curName) ?: fs_inquiry_name($folder);
    if ($newName === '' || preg_match(FS_BAD_CHARS_RE, $newName) || !bg_is_inquiry_name($newName)) {
        return $fail(400, 'new_name "' . $newName . '" is not the inquiry form <Customer>(<Agency>-<Agent>) — no MM_DDMON_ prefix, START / END, status tag or _CK.');
    }
    $year = (int)($opt['year'] ?? 0);
    if ($year > 0) {
        $root = '/' . $year;
        if (!in_array($root, $roots, true)) return $fail(400, 'year must be one of: ' . implode(', ', array_map(function ($x) { return ltrim($x, '/'); }, $roots)) . '.');
    } else {
        $root = $roots[0];
        $ty = (int)substr((string)($r['start_date'] ?? ''), 0, 4);
        if ($ty > 0 && in_array('/' . $ty, $roots, true)) $root = '/' . $ty;
    }
    $dest  = $root . '/' . $newName;
    $moved = strcmp($curPath, $dest) !== 0;
    if ($moved && strcasecmp($curPath, $dest) !== 0 && dropbox_path_exists($token, $dest)) {
        return $fail(409, 'A folder "' . $newName . '" already exists in ' . $root . ' — reopen refused.');
    }
    $st = $db->prepare("SELECT id FROM requests WHERE practice_code = ? AND id <> ? LIMIT 3");
    $st->execute([$newName, $requestId]);
    if ($ids = $st->fetchAll(PDO::FETCH_COLUMN)) $warnings[] = 'Request(s) ' . implode(', ', $ids) . ' already use the folder name ' . $newName . ' in Hub.';

    // Same rule as every rename path (refuses anything but a reopen as a quote out of _CANCELLED).
    $gate = booking_gate_check($db, $requestId, $folder !== '' ? $folder : $curName, $newName, $userId, 'reopen', true, ['dest_path' => $dest]);
    if ($gate['refused']) return $fail(409, booking_gate_message($gate));

    // What stays as it was: invoices, Booking follow-up lines, CK tracker.
    $invoices = [];
    try {
        $iq = $db->prepare("SELECT invoice_number, status, currency, total, balance_due FROM invoices WHERE request_id = ? ORDER BY id");
        $iq->execute([$requestId]);
        foreach ($iq->fetchAll(PDO::FETCH_ASSOC) as $i) {
            $invoices[] = ['number' => $i['invoice_number'], 'status' => $i['status'], 'currency' => $i['currency'],
                           'total' => (float)$i['total'], 'balance_due' => (float)$i['balance_due']];
            if ($i['status'] !== 'Cancelled') {
                $warnings[] = 'Invoice ' . $i['invoice_number'] . ' is ' . $i['status'] . ' (balance ' . $i['currency'] . ' '
                            . number_format((float)$i['balance_due'], 2) . ') — not touched: cancel or reuse it by hand.';
            }
        }
    } catch (Throwable $e) { $warnings[] = 'Invoices not checked — ' . $e->getMessage(); }
    $fuOpen = 0;
    try { $fc = followup_open_counts($db); $fuOpen = (int)($fc[$requestId]['total'] ?? 0); }
    catch (Throwable $e) { $warnings[] = 'Booking follow-up not checked — ' . $e->getMessage(); }
    if ($fuOpen > 0) $warnings[] = $fuOpen . ' open Booking follow-up line(s) on this request — not touched: close them if they no longer apply.';
    $ckRows = fs_ck_rows_for_folder($db, $curId, [$folder, $curName]);
    if ($ckRows) $warnings[] = 'CK tracker row(s) still active for this folder (' . implode(', ', array_column($ckRows, 'folder_name')) . ') — marked gone on confirm.';
    if ((string)($r['payment_status'] ?? '') !== '') $warnings[] = 'payment_status ' . $r['payment_status'] . ' will be cleared.';
    if ((string)($r['confirmation_date'] ?? '') !== '') $warnings[] = 'confirmation_date ' . $r['confirmation_date'] . ' will be cleared (the confirm snapshot and the history stay).';

    $out = ['ok' => true, 'request_id' => $requestId, 'from' => $curPath, 'stored_path' => $stored !== '' ? $stored : null,
            'to' => $dest, 'new_name' => $newName, 'moved' => $moved,
            'status_from' => $statusFrom, 'status_to' => $statusTo,
            'payment_status_from' => $r['payment_status'] ?? null, 'confirmation_date_from' => $r['confirmation_date'] ?? null,
            'invoices' => $invoices, 'followup_open' => $fuOpen, 'ck_rows' => $ckRows, 'warnings' => $warnings];
    if (!$go) return $out + ['msg' => 'Dry run — nothing moved. ' . ($moved ? $curPath . ' → ' . $dest : 'Folder already at ' . $dest) . ', status ' . $statusFrom . ' → ' . $statusTo . '.'];

    // Confirm.
    try {
        if ($moved) {
            $res = dropbox_move_folder($token, $curPath, $dest);
            $mid = folder_id_from_meta($res);
            if ($mid !== '') $curId = $mid;
        }
        if ($curId === '') { $m = dropbox_get_metadata($token, $dest); if ($m) $curId = (string)$m['id']; }
    } catch (Throwable $e) {
        return $fail(502, 'Dropbox error — nothing was changed: ' . $e->getMessage(), $out);
    }
    $db->prepare("UPDATE requests SET practice_code = ?, dropbox_url = ?, payment_status = NULL, confirmation_date = NULL WHERE id = ?")
       ->execute([$newName, bo_url_from_path($dest), $requestId]);
    folder_id_save($db, $requestId, $curId);
    $stRes = bs_set_request_status($db, $requestId, $statusTo, '', '', (int)$userId);
    if (!$stRes['ok']) $out['warnings'][] = 'Status not set: ' . $stRes['error'];
    foreach ($ckRows as $ck) {
        try {
            $db->prepare("UPDATE ck_folders SET gone = 1 WHERE id = ?")->execute([(int)$ck['id']]);
            ck_log($db, (int)$ck['id'], 'gone', $ck['folder_name'], $newName, $userId, 'hub', ck_now());
        } catch (Throwable $e) { error_log('folder_reopen_quote CK: ' . $e->getMessage()); }
    }
    timeline_log($requestId, 'folder_relinked', 'Reopened as quote: ' . $curName . ' → ' . $newName,
                 ['body' => $curPath . "\n→ " . $dest . "\nstatus " . $statusFrom . ' → ' . $statusTo
                          . ($invoices ? "\ninvoices untouched: " . implode(', ', array_column($invoices, 'number')) : '')]);
    return $out + ['msg' => '✔ ' . $r['customer_name'] . ': reopened as a quote — ' . $dest . ', status ' . $statusTo . '.'];
}
