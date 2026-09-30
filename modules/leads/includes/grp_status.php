<?php
/**
 * grp_status.php — the payment tag of a GRP parent folder follows its clients.
 *
 * Each client of a group has its own sub-folder (…/LeandroLodi(…)_BALANCE) and
 * its own payment_status. The parent folder's tag (…_GRP1912…_DEPOSIT) is the
 * tag of the client furthest behind with payments:
 *     DEPOSIT  <  BALANCE / BALANCE-CASH  <  PAID
 * Only clients with one of those tags count (cancelled or not yet confirmed
 * clients are left out); with none, the parent is left as it is.
 *
 *   grp_sync_parent_tag()  rename the parent in Dropbox + update the group's
 *                          requests (group_folder, dropbox_url) when its tag
 *                          no longer matches; payment_status is never touched.
 *
 * Needs dropbox_helper.php (dropbox_find_folder, dropbox_move_folder) only,
 * so the invoices module can use it too.
 * Keep PHP-7 style (no match / str_contains).
 */

const GRP_PAY_RANK = ['DEPOSIT' => 1, 'BALANCE' => 2, 'BALANCE-CASH' => 2, 'PAID' => 3];
const GRP_ALL_TAGS = ['BALANCE-CASH', 'BALANCE', 'DEPOSIT', 'PROGRESS', 'PROVISIONAL', 'PAID', 'CANCELLED', 'BOOKED'];

/** [base name without tag, tag or '', has _CK] of a folder name. */
function grp_split_tag(string $name): array {
    $ck = (bool)preg_match('/_CK$/i', $name);
    if ($ck) $name = substr($name, 0, -3);
    foreach (GRP_ALL_TAGS as $tag) {
        $suf = '_' . $tag;
        if (strcasecmp(substr($name, -strlen($suf)), $suf) === 0) {
            return [substr($name, 0, -strlen($suf)), $tag, $ck];
        }
    }
    return [$name, '', $ck];
}

/** Payment tag of one client: its sub-folder tag, else its payment_status; '' if none counts. */
function grp_client_tag(array $req): string {
    if (($req['status'] ?? '') === 'Cancelled' || ($req['payment_status'] ?? '') === 'Cancelled') return '';
    $tag = grp_split_tag(trim($req['practice_code'] ?? ''))[1];
    if (!array_key_exists($tag, GRP_PAY_RANK)) {
        $tag = strtoupper(trim($req['payment_status'] ?? ''));
    }
    return array_key_exists($tag, GRP_PAY_RANK) ? $tag : '';
}

/**
 * The tag the parent should carry, from its clients' tags ('' = no opinion).
 * Same rank, different tags (BALANCE + BALANCE-CASH) → BALANCE.
 */
function grp_parent_tag(array $clientTags): string {
    $clientTags = array_values(array_filter($clientTags));
    if (!$clientTags) return '';
    $min = min(array_map(function ($t) { return GRP_PAY_RANK[$t]; }, $clientTags));
    $at  = array_values(array_unique(array_filter($clientTags, function ($t) use ($min) { return GRP_PAY_RANK[$t] === $min; })));
    return count($at) === 1 ? $at[0] : 'BALANCE';
}

/**
 * Bring the parent folder's tag in line with its clients.
 * @return array{ok:bool, changed:bool, msg:string, new_name?:string}
 */
function grp_sync_parent_tag(PDO $db, string $token, string $groupFolder): array {
    $groupFolder = trim($groupFolder);
    if ($groupFolder === '') return ['ok' => true, 'changed' => false, 'msg' => ''];

    $st = $db->prepare("SELECT id, practice_code, status, payment_status FROM requests WHERE group_folder = ?");
    $st->execute([$groupFolder]);
    $members = $st->fetchAll(PDO::FETCH_ASSOC);
    $want = grp_parent_tag(array_map('grp_client_tag', $members));

    [$base, $cur, $ck] = grp_split_tag($groupFolder);
    if ($want === '' || $want === $cur) return ['ok' => true, 'changed' => false, 'msg' => ''];
    // Only a payment tag is replaced: a PROVISIONAL / CANCELLED parent is a decision, not a sum.
    if ($cur !== '' && !array_key_exists($cur, GRP_PAY_RANK) && $cur !== 'PROGRESS' && $cur !== 'BOOKED') {
        return ['ok' => true, 'changed' => false, 'msg' => ''];
    }
    $newName = $base . '_' . $want . ($ck ? '_CK' : '');

    $curPath = dropbox_find_folder($token, $groupFolder);
    if ($curPath === null) {
        return ['ok' => false, 'changed' => false, 'msg' => 'Group folder "' . $groupFolder . '" not found in Dropbox — rename it to …_' . $want . ' by hand.'];
    }
    $parentDir = rtrim(substr($curPath, 0, strrpos($curPath, '/')), '/');
    $newPath   = $parentDir . '/' . $newName;
    dropbox_move_folder($token, $curPath, $newPath);   // the clients' sub-folders move with it

    $upd = $db->prepare("UPDATE requests SET group_folder = ?, dropbox_url = ? WHERE id = ?");
    foreach ($members as $m) {
        $sub = trim($m['practice_code'] ?? '');
        $path = $sub !== '' ? $newPath . '/' . $sub : $newPath;
        $url  = 'https://www.dropbox.com/home/' . implode('/', array_map('rawurlencode', explode('/', ltrim($path, '/'))));
        $upd->execute([$newName, $url, (int)$m['id']]);
    }
    return ['ok' => true, 'changed' => true, 'new_name' => $newName,
            'msg' => 'Group folder renamed to …_' . $want . ' (' . count($members) . ' booking(s)).'];
}
