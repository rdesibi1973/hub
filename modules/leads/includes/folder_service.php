<?php
/**
 * folder_service.php — free rename of a booking's Dropbox folder, shared by the
 * BackOffice ("Rename…" and "Change status", backoffice.php) and the Agent API
 * (rename_folder).
 *
 *   Validate  : fs_rename_validate()   request, folder, new name (no Dropbox call)
 *   Preview   : fs_rename_preview()    Dropbox paths, destination clash, name / Calc warnings
 *   Rename    : folder_rename()        Dropbox move + DB + CK tracker + timeline
 *   Low level : bo_do_rename()         Dropbox move + DB only (also used by Change status)
 *               fs_rename_record()     CK tracker + timeline after a rename
 *
 * A group carries its name on the shared parent folder (group_folder): renaming a
 * group renames that parent and updates every request in it.
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
 * Rename a booking's Dropbox folder (private = its own folder; group = the shared
 * parent) and sync the DB. When $setStatus, also writes status/payment_status
 * (for a group: status only — each client keeps its own payment_status —
 * to every request in it, rebuilding each sub's dropbox_url).
 * Dropbox move happens first; the DB is only touched if it succeeds.
 * Requires dropbox_helper.php.
 */
function bo_do_rename(PDO $db, string $token, array $r, bool $isGrp,
                      string $folder, string $newFolder,
                      ?string $newStatus, $newPs, bool $setStatus): array {
    $curPath = fs_locate_folder($token, $r, $folder);
    if ($curPath === null) {
        return ['ok' => false, 'msg' => 'Could not find the folder "' . $folder . '" in Dropbox. Check the name, then retry.'];
    }
    $parentDir = rtrim(substr($curPath, 0, strrpos($curPath, '/')), '/');
    $newPath   = $parentDir . '/' . $newFolder;

    dropbox_move_folder($token, $curPath, $newPath);   // subfolders move with the parent

    if ($isGrp) {
        // Each client keeps its own payment_status (its invoice / sub-folder):
        // the group tag only sets the booking status, and never revives a
        // cancelled client unless the whole group is cancelled.
        $subs = $db->prepare("SELECT id, practice_code, status FROM requests WHERE group_folder = ?");
        $subs->execute([$folder]);
        $rowsG = $subs->fetchAll(PDO::FETCH_ASSOC);
        $upd   = $db->prepare("UPDATE requests SET group_folder=?, dropbox_url=? WHERE id=?");
        $updSt = $db->prepare("UPDATE requests SET group_folder=?, dropbox_url=?, status=? WHERE id=?");
        $n = 0;
        foreach ($rowsG as $g) {
            $sub    = trim($g['practice_code'] ?? '');
            $subUrl = bo_url_from_path($sub !== '' ? $newPath . '/' . $sub : $newPath);
            $keep   = ($g['status'] ?? '') === 'Cancelled' && $newStatus !== 'Cancelled';
            if ($setStatus && $newStatus !== null && !$keep) $updSt->execute([$newFolder, $subUrl, $newStatus, (int)$g['id']]);
            else                                              $upd->execute([$newFolder, $subUrl, (int)$g['id']]);
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
 *   ['ok'=>false, 'error'=>msg, 'code'=>404|400]  or
 *   ['ok'=>true, 'r'=>row, 'is_group'=>bool, 'folder'=>current name, 'new_name'=>…,
 *    'set_status'=>bool, 'status'=>?string, 'ps'=>?string]
 * The renamed folder is the group parent for a group, the folder itself otherwise.
 */
function fs_rename_validate(PDO $db, int $requestId, string $newName): array {
    $st = $db->prepare("SELECT id, customer_name, practice_code, group_folder, dropbox_url, status, payment_status
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
    if (strcmp($newName, $folder) === 0) {
        return ['ok' => false, 'code' => 400, 'error' => 'The new name is identical — nothing to change.'];
    }

    // Keep the DB status in sync with the new name's suffix (if it has one).
    $d = bo_status_from_name($newName);
    return ['ok' => true, 'r' => $r, 'is_group' => $isGrp, 'folder' => $folder, 'new_name' => $newName,
            'set_status' => $d['matched'], 'status' => $d['status'], 'ps' => $d['ps']];
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
 * Returns ['error'=>?string, 'code'=>int, 'dropbox_path_old', 'dropbox_path_new', 'warnings'=>[], 'calc_dates'=>?array]
 * error set = the rename would fail (folder not found, destination exists).
 */
function fs_rename_preview(PDO $db, array $v, string $token, bool $withCalc = true): array {
    $out = ['error' => null, 'code' => 200, 'dropbox_path_old' => null, 'dropbox_path_new' => null,
            'warnings' => fs_name_warnings($v['new_name'], $v['folder']), 'calc_dates' => null];

    $curPath = fs_locate_folder($token, $v['r'], $v['folder']);
    if ($curPath === null) {
        $out['error'] = 'Could not find the folder "' . $v['folder'] . '" in Dropbox (new folders can lag ~1h in search).';
        $out['code']  = 409;
        return $out;
    }
    $newPath = rtrim(substr($curPath, 0, strrpos($curPath, '/')), '/') . '/' . $v['new_name'];
    $out['dropbox_path_old'] = $curPath;
    $out['dropbox_path_new'] = $newPath;

    // A case-only change finds the folder itself — not a clash.
    if (strcasecmp($v['new_name'], $v['folder']) !== 0 && dropbox_path_exists($token, $newPath)) {
        $out['error'] = 'A folder "' . $v['new_name'] . '" already exists in ' . dirname($newPath) . '.';
        $out['code']  = 409;
        return $out;
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
 */
function fs_rename_record(PDO $db, int $requestId, bool $isGrp, string $folder, string $newFolder,
                          ?string $newStatus, ?int $userId): void {
    try { ck_record_rename($db, $folder, $newFolder, $userId ?: null); }
    catch (Throwable $ig) { /* tracking only — the next scan catches it */ }

    $tlTitle = 'Folder renamed' . ($newStatus !== null ? ' — status ' . $newStatus : '');
    if ($isGrp) {
        $tlIds = $db->prepare("SELECT id FROM requests WHERE group_folder = ?");
        $tlIds->execute([$newFolder]);
        foreach ($tlIds->fetchAll(PDO::FETCH_COLUMN) as $tlId) timeline_log((int)$tlId, 'status_change', 'Group ' . $tlTitle, ['body' => $folder . "\n→ " . $newFolder]);
    } else {
        timeline_log($requestId, 'status_change', $tlTitle, ['body' => $folder . "\n→ " . $newFolder]);
    }
}

/**
 * Free rename (BackOffice "Rename…", API rename_folder): rename the Dropbox folder,
 * update the Hub (practice_code / group_folder, dropbox_url, status / payment_status
 * when the new suffix carries a status tag), record it in the CK tracker and log a
 * status_change timeline event (every request of a group). Sends no email.
 *
 * Returns ['ok'=>bool, 'msg'=>string, 'code'=>int (on failure), 'is_group', 'old_name',
 *          'new_name', 'dropbox_path_old', 'dropbox_path_new'].
 */
function folder_rename(PDO $db, int $requestId, string $newName, ?int $userId): array {
    $v = fs_rename_validate($db, $requestId, $newName);
    if (!$v['ok']) return ['ok' => false, 'msg' => $v['error'], 'code' => $v['code']];

    require_once __DIR__ . '/../dropbox_helper.php';
    $folder = $v['folder']; $newFolder = $v['new_name']; $isGrp = $v['is_group'];
    try {
        $token = dropbox_get_access_token();
        $res   = bo_do_rename($db, $token, $v['r'], $isGrp, $folder, $newFolder, $v['status'], $v['ps'], $v['set_status']);
    } catch (Throwable $e) {
        return ['ok' => false, 'msg' => 'Dropbox/DB error — nothing was changed: ' . $e->getMessage(), 'code' => 502];
    }
    if (!$res['ok']) return ['ok' => false, 'msg' => $res['msg'], 'code' => 409];

    fs_rename_record($db, (int)$v['r']['id'], $isGrp, $folder, $newFolder, $v['set_status'] ? $v['status'] : null, $userId);
    return ['ok' => true, 'msg' => $res['msg'], 'is_group' => $isGrp, 'old_name' => $folder, 'new_name' => $newFolder,
            'dropbox_path_old' => $res['old_path'], 'dropbox_path_new' => $res['new_path']];
}
