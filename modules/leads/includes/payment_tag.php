<?php
/**
 * payment_tag.php — keep requests.payment_status and the Dropbox folder's
 * payment tag (_DEPOSIT / _BALANCE / _BALANCE-CASH / _PAID) in step.
 *
 * The folder name is the source of truth. When the payment status is changed
 * in the Hub, the folder is renamed to carry the matching tag (any trailing
 * marker such as _CK is kept); when a folder is renamed, the payment status
 * follows its tag (see ck_apply in ck_lib.php for renames seen by the scan).
 *
 * Requires dropbox_helper.php (for payment_rename_folder).
 */

const PAYMENT_TAGS = [
    'Deposit'      => '_DEPOSIT',
    'Balance'      => '_BALANCE',
    'Balance-Cash' => '_BALANCE-CASH',
    'Paid'         => '_PAID',
];

/** The payment tag as a token: '_BALANCE-CASH' before '_BALANCE', then '_' or end. */
const PAYMENT_TAG_RE = '/_(BALANCE[-_]CASH|BALANCE|DEPOSIT|PAID)(?=_|$)/i';

/** Payment status carried by a folder name ('' when it has no payment tag). */
function payment_status_from_folder(string $name): string {
    if (!preg_match(PAYMENT_TAG_RE, $name, $m)) return '';
    $t = strtoupper(str_replace('_', '-', $m[1]));
    return ['BALANCE-CASH' => 'Balance-Cash', 'BALANCE' => 'Balance',
            'DEPOSIT' => 'Deposit', 'PAID' => 'Paid'][$t];
}

/**
 * The folder name with its payment tag replaced by the one for $ps, or null
 * when the folder has no payment tag to replace (nothing is appended).
 */
function folder_with_payment_tag(string $name, string $ps): ?string {
    if (!isset(PAYMENT_TAGS[$ps]) || !preg_match(PAYMENT_TAG_RE, $name)) return null;
    return preg_replace(PAYMENT_TAG_RE, PAYMENT_TAGS[$ps], $name, 1);
}

/**
 * Rename a request's Dropbox folder to $newName and store practice_code,
 * dropbox_url and payment_status (from the new tag). The Dropbox path comes
 * from the stored dropbox_url; if not found there, /2026 <-> /001_Safari is
 * tried (the folder may have moved on confirmation).
 *
 * @return array{ok:bool, msg:string}
 */
function payment_rename_folder(PDO $db, array $req, string $newName): array {
    $old = trim((string)($req['practice_code'] ?? ''));
    if ($old === '' || $old === $newName) return ['ok' => true, 'msg' => ''];
    $prefix = 'https://www.dropbox.com/home';
    $url    = trim((string)($req['dropbox_url'] ?? ''));
    $parent = str_starts_with($url, $prefix)
        ? dirname(urldecode(substr($url, strlen($prefix))))
        : (($req['status'] ?? '') === 'Booked' ? '/001_Safari' : '/2026');
    $token  = dropbox_get_access_token();
    $tries  = [$parent];
    if ($parent === '/2026') $tries[] = '/001_Safari';
    elseif ($parent === '/001_Safari') $tries[] = '/2026';
    $done = null; $err = '';
    foreach ($tries as $p) {
        try {
            dropbox_move_folder($token, "$p/$old", "$p/$newName");
            $done = $p;
            break;
        } catch (Throwable $e) {
            $err = $e->getMessage();
            if (!str_contains($err, 'not_found')) break;
        }
    }
    if ($done === null) {
        return ['ok' => false, 'msg' => "Dropbox rename failed ($old → $newName): $err"];
    }
    $newUrl = $prefix . implode('/', array_map('rawurlencode', explode('/', "$done/$newName")));
    $ps     = payment_status_from_folder($newName);
    $db->prepare("UPDATE requests SET practice_code = ?, dropbox_url = ?, payment_status = ? WHERE id = ?")
       ->execute([$newName, $newUrl, $ps !== '' ? $ps : ($req['payment_status'] ?? null), (int)$req['id']]);
    if (function_exists('ck_record_rename')) {
        try { ck_record_rename($db, $old, $newName, null); } catch (Throwable $ig) {}
    }
    return ['ok' => true, 'msg' => "Dropbox folder renamed to $newName."];
}
