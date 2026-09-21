<?php
/**
 * backoffice.php — Hub BackOffice (replaces the Java desktop tool, incrementally).
 *
 * Feature 1: Change booking status.
 *   Renames the Dropbox folder server-side (Dropbox API) and updates the DB
 *   (practice_code, dropbox_url, status, payment_status) in one atomic action.
 *   No local Dropbox sync / Java runtime needed — works from any browser.
 *
 * Handles private safaris and group bookings. A group carries its status tag on
 * the shared parent folder: changing a group renames that parent and updates
 * every request in the group (status, payment_status and each sub-booking's
 * dropbox_url), since the subfolders move with the parent.
 */
require_once 'config.php';
require_once 'includes/folder_parser.php';
require_once 'includes/safari_check.php';
$pageTitle = 'BackOffice';
$db = db();

// ── Access: admin + manager only ──────────────────────────────────────────────
$currentUser = current_user();
if (!in_array($currentUser['role_name'] ?? '', ['admin','manager'], true)) {
    flash('Access denied.', 'error');
    header('Location: requests.php'); exit;
}

// Column that records the pre-confirmation state so a confirm can be rolled back.
// Created lazily (MySQL: no IF NOT EXISTS) so the listing query can always read it.
try { $db->exec("ALTER TABLE requests ADD COLUMN pre_confirm_json TEXT NULL DEFAULT NULL"); }
catch (PDOException $ignored) {}

// ── Target status → folder tag + DB status/payment_status ─────────────────────
// Mirrors the Java tool and api_rename_folder.php. A null 'ps' clears payment_status.
$STATUS_MAP = [
    'Progress'     => ['tag' => 'PROGRESS',     'status' => 'Booked',      'ps' => null],
    'Provisional'  => ['tag' => 'PROVISIONAL',  'status' => 'Provisional', 'ps' => null],
    'Deposit'      => ['tag' => 'DEPOSIT',      'status' => 'Booked',      'ps' => 'Deposit'],
    'Balance'      => ['tag' => 'BALANCE',      'status' => 'Booked',      'ps' => 'Balance'],
    'Balance-Cash' => ['tag' => 'BALANCE-CASH', 'status' => 'Booked',      'ps' => 'Balance-Cash'],
    'Paid'         => ['tag' => 'PAID',         'status' => 'Booked',      'ps' => 'Paid'],
    'Cancelled'    => ['tag' => 'CANCELLED',    'status' => 'Cancelled',   'ps' => null],
];

// Existing folder tags to detect + replace (longest / most specific first).
$KNOWN_TAGS = ['_BALANCE-CASH','_BALANCE_CASH','_BALANCE','_DEPOSIT','_PAID',
               '_PROGRESS','_CONFIRMED','_PROVISIONAL','_CANCELLED'];

/**
 * Build the new folder name: replace the current status tag in place (keeping any
 * trailing marker such as _CK), or append the new tag if none is present.
 */
function bo_new_folder_name(string $folder, string $newTag, array $knownTags): string {
    foreach ($knownTags as $tag) {
        if (stripos($folder, $tag) !== false) {
            return str_ireplace($tag, '_' . $newTag, $folder);
        }
    }
    return $folder . '_' . $newTag;
}

/** Rebuild a Dropbox web URL from an API path_display ('/001_Safari/Foo/Bar'). */
function bo_url_from_path(string $path): string {
    $enc = implode('/', array_map('rawurlencode', explode('/', ltrim($path, '/'))));
    return 'https://www.dropbox.com/home/' . $enc;
}

// Folder suffix → [status, payment_status]. "Contains" match, longest first
// (tolerates a trailing _CK). Used to keep the DB in sync after a free rename.
$BO_TAG_STATUS = [
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

/** Derive [status, payment_status, matched] from a folder name, or matched=false. */
function bo_status_from_name(string $name, array $tagStatus): array {
    $up = strtoupper($name);
    foreach ($tagStatus as $tag => $sp) {
        if (strpos($up, $tag) !== false) return ['status' => $sp[0], 'ps' => $sp[1], 'matched' => true];
    }
    return ['status' => null, 'ps' => null, 'matched' => false];
}

/**
 * Rename a booking's Dropbox folder (private = its own folder; group = the shared
 * parent) and sync the DB. When $setStatus, also writes status/payment_status
 * (for a group, to every request in it, rebuilding each sub's dropbox_url).
 * Dropbox move happens first; the DB is only touched if it succeeds.
 */
function bo_do_rename(PDO $db, string $token, array $r, bool $isGrp,
                      string $folder, string $newFolder,
                      ?string $newStatus, $newPs, bool $setStatus): array {
    $curPath = dropbox_find_folder($token, $folder);
    if ($curPath === null) {
        return ['ok' => false, 'msg' => 'Could not find the folder "' . $folder . '" in Dropbox. Check the name, then retry.'];
    }
    $parentDir = rtrim(substr($curPath, 0, strrpos($curPath, '/')), '/');
    $newPath   = $parentDir . '/' . $newFolder;

    dropbox_move_folder($token, $curPath, $newPath);   // subfolders move with the parent

    if ($isGrp) {
        $subs = $db->prepare("SELECT id, practice_code FROM requests WHERE group_folder = ?");
        $subs->execute([$folder]);
        $rowsG = $subs->fetchAll(PDO::FETCH_ASSOC);
        $upd = $setStatus
            ? $db->prepare("UPDATE requests SET group_folder=?, dropbox_url=?, status=?, payment_status=? WHERE id=?")
            : $db->prepare("UPDATE requests SET group_folder=?, dropbox_url=? WHERE id=?");
        $n = 0;
        foreach ($rowsG as $g) {
            $sub    = trim($g['practice_code'] ?? '');
            $subUrl = bo_url_from_path($sub !== '' ? $newPath . '/' . $sub : $newPath);
            if ($setStatus) $upd->execute([$newFolder, $subUrl, $newStatus, $newPs, (int)$g['id']]);
            else            $upd->execute([$newFolder, $subUrl, (int)$g['id']]);
            $n++;
        }
        return ['ok' => true, 'msg' => '✔ Group "' . $newFolder . '": renamed (' . $n . ' booking(s) updated).'];
    }

    $newUrl = bo_url_from_path($newPath);
    if ($setStatus) {
        $db->prepare("UPDATE requests SET practice_code=?, dropbox_url=?, status=?, payment_status=? WHERE id=?")
           ->execute([$newFolder, $newUrl, $newStatus, $newPs, (int)$r['id']]);
    } else {
        $db->prepare("UPDATE requests SET practice_code=?, dropbox_url=? WHERE id=?")
           ->execute([$newFolder, $newUrl, (int)$r['id']]);
    }
    return ['ok' => true, 'msg' => '✔ ' . $r['customer_name'] . ': renamed to "' . $newFolder . '".'];
}

/** API path ('/001_Safari/…') from a stored dropbox_url, or '' if unparseable. */
function bo_path_from_url(string $url): string {
    $url = trim($url);
    if ($url === '') return '';
    $p = parse_url($url, PHP_URL_PATH);
    if (!$p) return '';
    $p = urldecode($p);
    $p = preg_replace('#^/?home/#i', '', ltrim($p, '/')); // strip leading /home/
    $p = trim((string)$p, '/');
    return $p !== '' ? '/' . $p : '';
}

// ── Confirm Safari: destinations + folder-name builder (ports the Java tool) ──
// label => [folder suffix inserted inside (), requests.destination value].
$CONFIRM_DESTINATIONS = [
    'Safari / Safari & Beach — Tanzania' => ['',             'Tanzania'],
    'Trekking Kilimanjaro / Meru'        => ['-TREK',        'Tanzania'],
    'Only Zanzibar'                      => ['-ZNZ',         'Tanzania'],
    'Safari Kenya-Tanzania'              => ['-TZ-KENYA',    'Kenya'],
    'Safari Kenya'                       => ['-KENYA',       'Kenya'],
    'Uganda'                             => ['-UGANDA',      'Uganda'],
    'Namibia'                            => ['-NAMIBIA',     'Namibia'],
    'South Africa'                       => ['-SOUTHAFRICA', 'South Africa'],
    'Rwanda'                             => ['-RWANDA',      'Rwanda'],
    'Madagascar'                         => ['-MADAGASCAR',  'Madagascar'],
    'Botswana'                           => ['-BOTSWANA',    'Botswana'],
    'Staff / Internal'                   => ['-STAFF',       'Staff'],
];

$CONFIRM_MONTHS = ['JAN'=>'01','FEB'=>'02','MAR'=>'03','APR'=>'04','MAY'=>'05','JUN'=>'06',
                   'JUL'=>'07','AUG'=>'08','SEP'=>'09','OCT'=>'10','NOV'=>'11','DEC'=>'12'];

/**
 * Build the confirmed folder name from the entered dates, mirroring the Java
 * "Confirm Safari" rules exactly:
 *   {MM}_{START}_{custname}_START{START}[_MIDT{MID}][_MIDT{MID2}]_END{END}_PROGRESS
 * with the destination suffix inserted before the last ')'. Hard-format errors
 * (bad month / wrong length) are returned in $errors and yield null.
 *
 * $start/$mid/$mid2 are DDMMM (e.g. 05JAN); $end is DDMMMYYYY (e.g. 18JAN2026).
 * "NA" or empty middles are skipped.
 */
function bo_confirmed_name(string $custname, string $start, string $mid, string $mid2,
                           string $end, string $destSuffix, array $months, array &$errors): ?string {
    $errors = [];
    $custname = trim($custname);
    $start = strtoupper(trim($start));
    $mid   = strtoupper(trim($mid));
    $mid2  = strtoupper(trim($mid2));
    $end   = strtoupper(trim($end));

    $monOf = function (string $s) use ($months): string {
        foreach ($months as $abbr => $num) { if (strpos($s, $abbr) !== false) return $num; }
        return '00';
    };

    // Start date: DDMMM, valid month, exactly 5 chars.
    $month = $monOf($start);
    if ($month === '00') { $errors[] = 'Start Date month is wrong — use DDMMM, e.g. 05JAN.'; }
    if (strpos($start, 'NA') !== false || strlen($start) !== 5) {
        $errors[] = 'Start Date must be DDMMM (5 characters), e.g. 05JAN.';
    }

    // End date: DDMMMYYYY, valid month, exactly 9 chars.
    $endMonth = $monOf($end);
    if ($endMonth === '00') { $errors[] = 'End Date month is wrong — use DDMMMYYYY, e.g. 18JAN2026.'; }
    if (strpos($end, 'NA') !== false || strlen($end) !== 9) {
        $errors[] = 'End Date must be DDMMMYYYY (9 characters), e.g. 18JAN2026.';
    }

    // Middle dates (optional): DDMMM. Middle 2 only considered if middle 1 is set.
    $hasMid  = ($mid  !== '' && strpos($mid, 'NA')  === false);
    $hasMid2 = $hasMid && ($mid2 !== '' && strpos($mid2, 'NA') === false);
    if ($hasMid  && strlen($mid)  !== 5) { $errors[] = 'Middle Date must be DDMMM (5 characters), e.g. 10JAN.'; }
    if ($hasMid2 && strlen($mid2) !== 5) { $errors[] = 'Middle Date 2 must be DDMMM (5 characters), e.g. 12JAN.'; }

    if ($errors) return null;

    $name = $month . '_' . $start . '_' . $custname . '_START' . $start;
    if ($hasMid)  $name .= '_MIDT' . $mid;
    if ($hasMid2) $name .= '_MIDT' . $mid2;
    $name .= '_END' . $end . '_PROGRESS';

    if ($destSuffix !== '') {
        $close = strrpos($name, ')');
        if ($close !== false) {
            $name = substr($name, 0, $close) . $destSuffix . substr($name, $close);
        }
    }
    return $name;
}

/** Insert "_GRP{code}" before the first '(' of the customer folder (Java CREATE rule). */
function bo_grp_insert(string $cust, string $code): string {
    $paren = strpos($cust, '(');
    return $paren !== false
        ? substr($cust, 0, $paren) . '_GRP' . $code . substr($cust, $paren)
        : $cust . '_GRP' . $code;
}

/** Valid GRP code = 4 digits DDMM, day 1-31, month 1-12 (mirrors the Java check). */
function bo_grp_code_valid(string $code): bool {
    if (!preg_match('/^\d{4}$/', $code)) return false;
    $d = (int)substr($code, 0, 2); $m = (int)substr($code, 2);
    return $d >= 1 && $d <= 31 && $m >= 1 && $m <= 12;
}

/** 'DDMM' from a Y-m-d start date, or '' if not parseable. */
function bo_grp_code_from_ymd(?string $ymd): string {
    if (!$ymd) return '';
    $ts = strtotime($ymd);
    return $ts !== false ? date('dm', $ts) : '';
}

/** Existing GRP folder names under /001_Safari whose name contains "GRP{code}". */
function bo_find_grps(string $token, string $code): array {
    if ($code === '' || !function_exists('dropbox_list_folder')) return [];
    try { $all = dropbox_list_folder($token, '/001_Safari'); }
    catch (Throwable $e) { return []; }
    $needle = 'GRP' . $code;
    $out = [];
    foreach ($all as $name) { if (stripos($name, $needle) !== false) $out[] = $name; }
    sort($out, SORT_NATURAL | SORT_FLAG_CASE);
    return $out;
}

/**
 * Build the post-confirmation booking-notification email (ports the Java
 * showSafariBookingEmailDialog template). $grpMain != '' → the "added to group"
 * variant. Returns ['to','cc','subject','body'] (to/cc comma-separated).
 */
function bo_booking_email(string $folder, string $agentEmail, string $grpMain, string $sessionFullName): array {
    // Agent code = last token inside the last (…) block, e.g. "(GoWorld-PS-Roberto)" → "Roberto".
    $agentCode = '';
    if (preg_match_all('/\(([^)]+)\)/', $folder, $m) && !empty($m[1])) {
        $parts = array_values(array_filter(array_map('trim', explode('-', (string)end($m[1]))), fn($x) => $x !== ''));
        if ($parts) $agentCode = (string)end($parts);
    }
    $grpAdd  = ($grpMain !== '');
    $fnLower = strtolower(trim($sessionFullName));

    $to = ['accountant@savannahexplorers.com', 'glady@savannahexplorers.com', 'operations@savannahexplorers.com'];
    $addNuru = in_array(strtolower($agentCode), ['roberto','robertocapri','eleonoraongaro','alessia','daniela'], true)
            || in_array($fnLower, ['roberto','roberto capri','alessia','daniela'], true);
    if ($addNuru) $to[] = 'nuru@savannahexplorers.com';

    $cc = [];
    if ($agentEmail !== '') $cc[] = $agentEmail;
    $cc[] = 'savannah.explorers@gmail.com';
    $cc[] = 'saruni@savannahexplorers.com';

    if ($grpAdd) {
        $subject = $grpMain . '\\' . $folder;
    } else {
        $cust = preg_replace('/^\d+_\d+[A-Za-z]+_/', '', $folder);
        $cust = preg_replace('/_START.+$/', '', $cust);
        $subject = $cust . ' safari bookings';
    }

    $agentDisplay = $sessionFullName !== '' ? $sessionFullName : $agentCode;
    $b  = "Hi Glady/Lydia,\n";
    if ($grpAdd) {
        $b .= "         this customer has been added to group " . $grpMain . ",\n";
        $b .= "         please check the extra services to book as in the excel file (transfers, hotels, Zanzibar, etc. — if any)\n\n";
        $b .= "Dropbox folder is   " . $grpMain . "\\" . $folder . "\n\n";
    } else {
        $b .= "         you can book for this safari as in the excel file\n\n";
        $b .= "Dropbox folder is   " . $folder . "\n\n";
    }
    $b .= "Kindly check domestic flights, invoices, transfers and activities are correctly"
        . " booked and invoiced for the correct price/date/pax before saving."
        . " Put invoice details and your name in Excel after it's checked.\n\n";
    if ($addNuru) $b .= "Nuru - prepare the final program when bookings are completed\n\n";
    $b .= "Esther - prepare the not paid invoice\n";
    $b .= "\nThanks\nBest Regards,\n" . $agentDisplay;

    return [
        'to'      => implode(', ', array_values(array_unique($to))),
        'cc'      => implode(', ', array_values(array_unique($cc))),
        'subject' => $subject,
        'body'    => $b,
    ];
}

/** Restore the request row to its pre-confirmation state (used by rollback). */
function bo_rollback_db(PDO $db, int $id, string $name, string $path, array $pre): void {
    $status = $pre['status'] ?? '';
    // Never leave it Booked after a rollback; fall back to Inquiry if unknown.
    if ($status === '' || strcasecmp($status, 'Booked') === 0) $status = 'Inquiry';
    $pay   = $pre['pay']   ?? null;
    $group = trim($pre['group'] ?? '');
    $db->prepare(
        "UPDATE requests
         SET practice_code=?, group_folder=?, dropbox_url=?, status=?, payment_status=?,
             confirmation_date=NULL, pre_confirm_json=NULL
         WHERE id=?"
    )->execute([$name, ($group !== '' ? $group : null), bo_url_from_path($path), $status, ($pay !== '' ? $pay : null), $id]);
}

/**
 * Re-group a confirmed booking that was filed under the wrong grouping: move ONLY
 * this booking's Dropbox folder and sync the DB. $targetGrp === '' → make it a
 * private (top-level) safari; otherwise it becomes a member of that GRP (the GRP
 * folder is created if it does not exist). GRP siblings are left untouched.
 *
 * The customer folder name (practice_code) is preserved — only its parent changes.
 */
function bo_do_regroup(PDO $db, string $token, array $r, string $targetGrp): array {
    $base = '/001_Safari';
    $cust = trim($r['practice_code'] ?? '');
    if ($cust === '') {
        return ['ok' => false, 'msg' => 'This booking has no customer folder (practice_code) to move.'];
    }

    // Where the folder is now: trust dropbox_url, fall back to group_folder/practice_code.
    $curPath = bo_path_from_url($r['dropbox_url'] ?? '');
    if ($curPath === '') {
        $oldGrp  = trim($r['group_folder'] ?? '');
        $curPath = $base . '/' . ($oldGrp !== '' ? $oldGrp . '/' : '') . $cust;
    }

    if ($targetGrp === '') {
        $newParent = $base;
        $newGroup  = null;
    } else {
        $newParent = $base . '/' . $targetGrp;
        $newGroup  = $targetGrp;
        dropbox_create_folder($token, $newParent, false); // idempotent: makes a new GRP, no-op if it exists
    }
    $newPath = $newParent . '/' . $cust;

    if (strcasecmp($newPath, $curPath) === 0) {
        return ['ok' => false, 'msg' => 'The booking is already in that location — nothing to change.'];
    }

    $oldParent = rtrim(substr($curPath, 0, (int)strrpos($curPath, '/')), '/');

    dropbox_move_folder($token, $curPath, $newPath); // 409 conflict (name clash) throws → caller reports it

    $db->prepare("UPDATE requests SET group_folder = ?, dropbox_url = ? WHERE id = ?")
       ->execute([$newGroup, bo_url_from_path($newPath), (int)$r['id']]);

    $msg = '✔ ' . ($r['customer_name'] ?? 'Booking') . ': moved to '
         . ($newGroup !== null ? 'group “' . $newGroup . '”' : 'private (no group)') . '.';

    // If it came out of a GRP that is now empty, flag the orphan folder (do not auto-delete).
    $wasGrp = trim($r['group_folder'] ?? '') !== '';
    if ($wasGrp && $oldParent !== $base && strcasecmp($oldParent, $newParent) !== 0) {
        try {
            if (count(dropbox_list_folder($token, $oldParent)) === 0) {
                $msg .= ' The old group folder “' . basename($oldParent)
                      . '” has no sub-folders left — delete it in Dropbox if it is no longer needed.';
            }
        } catch (Throwable $e) { /* advisory only — ignore */ }
    }
    return ['ok' => true, 'msg' => $msg];
}

// ── Actions: change status / free rename ──────────────────────────────────────
$act = $_POST['action'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($act, ['change_status', 'rename'], true)) {
    $reqId = (int)($_POST['request_id'] ?? 0);

    $stmt = $db->prepare("SELECT id, customer_name, practice_code, group_folder, dropbox_url, status, payment_status
                          FROM requests WHERE id = ?");
    $stmt->execute([$reqId]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$r) {
        flash('Request not found.', 'error');
    } elseif (($folder = ($isGrp = trim($r['group_folder'] ?? '') !== '')
                       ? trim($r['group_folder'])
                       : trim($r['practice_code'] ?? '')) === '') {
        // tag lives on the parent for a group, on the folder itself for a private safari.
        flash('This request has no folder to rename.', 'error');
    } else {
        // Work out the new folder name + whether/how to touch status.
        $newFolder = '';
        $newStatus = null; $newPs = null; $setStatus = false;
        $ok = true;

        if ($act === 'change_status') {
            $target = trim($_POST['new_status'] ?? '');
            if (!isset($STATUS_MAP[$target])) { flash('Invalid target status.', 'error'); $ok = false; }
            else {
                $newFolder = bo_new_folder_name($folder, $STATUS_MAP[$target]['tag'], $KNOWN_TAGS);
                $newStatus = $STATUS_MAP[$target]['status'];
                $newPs     = $STATUS_MAP[$target]['ps'];
                $setStatus = true;
            }
        } else { // rename
            $newFolder = trim($_POST['new_name'] ?? '');
            if ($newFolder === '') { flash('New name is empty.', 'error'); $ok = false; }
            elseif (preg_match('#[\\\\/:*?"<>|]#', $newFolder)) { flash('Invalid characters in the new name (\\ / : * ? " < > | are not allowed).', 'error'); $ok = false; }
            else {
                // Keep the DB status in sync with the new name's suffix (if it has one).
                $d = bo_status_from_name($newFolder, $BO_TAG_STATUS);
                $setStatus = $d['matched'];
                $newStatus = $d['status'];
                $newPs     = $d['ps'];
            }
        }

        if ($ok && strcmp($newFolder, $folder) === 0) {
            flash('The new name is identical — nothing to change.', 'error'); $ok = false;
        }

        if ($ok) {
            require_once 'dropbox_helper.php';
            try {
                $token = dropbox_get_access_token();
                $res   = bo_do_rename($db, $token, $r, $isGrp, $folder, $newFolder, $newStatus, $newPs, $setStatus);
                flash($res['msg'], $res['ok'] ? 'info' : 'error');
            } catch (Throwable $e) {
                flash('Dropbox/DB error — nothing was changed: ' . $e->getMessage(), 'error');
            }
        }
    }
    $qs = array_filter([
        'q'        => trim($_POST['q'] ?? ''),
        'root'     => trim($_POST['root'] ?? ''),
        'show_all' => !empty($_POST['show_all']) ? '1' : '',
    ], fn($x) => $x !== '');
    header('Location: backoffice.php' . ($qs ? '?' . http_build_query($qs) : ''));
    exit;
}

// ── Action: re-group a booking (fix a wrong private/GRP confirmation) ──────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'regroup') {
    $reqId = (int)($_POST['request_id'] ?? 0);
    $type  = trim($_POST['regroup_type'] ?? 'private');   // private | grp
    $grp   = trim($_POST['target_grp'] ?? '');

    $stmt = $db->prepare("SELECT id, customer_name, practice_code, group_folder, dropbox_url, status, payment_status
                          FROM requests WHERE id = ?");
    $stmt->execute([$reqId]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$r) {
        flash('Request not found.', 'error');
    } elseif ($type === 'grp' && $grp === '') {
        flash('Enter or pick a group folder name.', 'error');
    } elseif ($type === 'grp' && preg_match('#[\\\\/:*?"<>|]#', $grp)) {
        flash('Invalid characters in the group name (\\ / : * ? " < > | are not allowed).', 'error');
    } else {
        require_once 'dropbox_helper.php';
        try {
            $token = dropbox_get_access_token();
            $res   = bo_do_regroup($db, $token, $r, $type === 'grp' ? $grp : '');
            flash($res['msg'], $res['ok'] ? 'info' : 'error');
        } catch (Throwable $e) {
            flash('Dropbox/DB error — nothing was changed: ' . $e->getMessage(), 'error');
        }
    }
    $qs = array_filter([
        'q'        => trim($_POST['q'] ?? ''),
        'root'     => trim($_POST['root'] ?? ''),
        'show_all' => !empty($_POST['show_all']) ? '1' : '',
    ], fn($x) => $x !== '');
    header('Location: backoffice.php' . ($qs ? '?' . http_build_query($qs) : ''));
    exit;
}

// ── Confirm Safari: preview (build name + checks) / commit (move + DB) ────────
$previewFor  = 0;      // request_id whose inline preview panel to render
$previewData = null;   // ['new_name','errors','checks','dates']

if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && in_array($_POST['action'] ?? '', ['confirm_preview', 'confirm_safari'], true)) {

    $reqId    = (int)($_POST['request_id'] ?? 0);
    $action   = $_POST['action'];
    $fStart   = strtoupper(trim($_POST['cs_start'] ?? ''));
    $fMid     = strtoupper(trim($_POST['cs_mid']   ?? ''));
    $fMid2    = strtoupper(trim($_POST['cs_mid2']  ?? ''));
    $fEnd     = strtoupper(trim($_POST['cs_end']   ?? ''));
    $fDestKey = $_POST['cs_dest'] ?? '';
    [$destSuffix, $destValue] = $CONFIRM_DESTINATIONS[$fDestKey] ?? ['', ''];

    $grpAction = strtoupper(trim($_POST['cs_grp'] ?? 'NONE'));
    if (!in_array($grpAction, ['NONE', 'CREATE', 'ADD'], true)) $grpAction = 'NONE';
    $grpCode   = preg_replace('/\D/', '', $_POST['cs_grpcode'] ?? '');   // digits only (DDMM)
    $grpMain   = trim($_POST['cs_grpmain'] ?? '');                       // chosen existing GRP (ADD)
    $proceed   = !empty($_POST['cs_proceed']);                           // "Proceed anyway" override

    // Preserve the search context so the list re-renders / the redirect returns here.
    $_GET['q']        = trim($_POST['q'] ?? '');
    $_GET['root']     = trim($_POST['root'] ?? '2026');
    $_GET['show_all'] = !empty($_POST['show_all']) ? '1' : '';
    $backQs = http_build_query(array_filter(['q'=>$_GET['q'], 'root'=>$_GET['root'], 'show_all'=>$_GET['show_all']]));

    $stmt = $db->prepare("SELECT id, customer_name, practice_code, group_folder, dropbox_url, status, payment_status
                          FROM requests WHERE id = ?");
    $stmt->execute([$reqId]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    $oldFolder = $r ? trim($r['practice_code'] ?? '') : '';

    if (!$r) {
        flash('Request not found.', 'error');
        header('Location: backoffice.php' . ($backQs ? '?' . $backQs : '')); exit;
    }
    if ($oldFolder === '') {
        flash('This request has no folder (practice_code) to confirm.', 'error');
        header('Location: backoffice.php' . ($backQs ? '?' . $backQs : '')); exit;
    }
    $confirmedMailId = 0;   // set on a successful confirm → open the booking email after redirect

    // GRP code defaults to DDMM derived from the Start date when left blank.
    $deriveCode = function (string $start) use ($CONFIRM_MONTHS): string {
        $s = strtoupper(trim($start));
        if (strlen($s) < 5 || !ctype_digit(substr($s, 0, 2))) return '';
        foreach ($CONFIRM_MONTHS as $ab => $num) { if (strpos($s, $ab) !== false) return substr($s, 0, 2) . $num; }
        return '';
    };
    if ($grpAction !== 'NONE' && $grpCode === '') $grpCode = $deriveCode($fStart);

    // ── Build the target name(s) + validate per booking type ──────────────────
    $errs       = [];
    $newName    = null;   // GRP main / private confirmed folder (NONE, CREATE)
    $subName    = null;   // CREATE: member subfolder = original practice_code
    $memberName = null;   // ADD:    member folder that moves into the GRP (unchanged)
    $pd         = ['start_date' => null, 'end_date' => null];

    if ($grpAction === 'ADD') {
        // No dates: the customer folder keeps its name and moves into the existing GRP.
        $memberName = $oldFolder;
        if ($grpCode === '')                 $errs[] = 'Enter the GRP code (DDMM) to find the existing group.';
        elseif (!bo_grp_code_valid($grpCode)) $errs[] = 'GRP code must be 4 digits DDMM (e.g. 2306 = 23 Jun).';
    } else {
        $custForName = $oldFolder;
        if ($grpAction === 'CREATE') {
            if (!bo_grp_code_valid($grpCode)) $errs[] = 'GRP code must be 4 digits DDMM (e.g. 2306 = 23 Jun).';
            else $custForName = bo_grp_insert($oldFolder, $grpCode);
        }
        $buildErr = [];
        $newName  = bo_confirmed_name($custForName, $fStart, $fMid, $fMid2, $fEnd, $destSuffix, $CONFIRM_MONTHS, $buildErr);
        $errs     = array_merge($errs, $buildErr);
        if ($newName !== null) { $pd = parse_folder_dates($newName); $subName = $oldFolder; }
    }

    $csFields = [
        'fStart'    => $fStart, 'fMid' => $fMid, 'fMid2' => $fMid2, 'fEnd' => $fEnd,
        'fDestKey'  => $fDestKey, 'grpAction' => $grpAction, 'grpCode' => $grpCode, 'grpMain' => $grpMain,
    ];

    if ($errs) {
        // Hard format error — show it in the inline panel, move nothing.
        $previewFor  = $reqId;
        $previewData = ['new_name' => $newName, 'errors' => $errs, 'checks' => [],
                        'fields' => $csFields, 'block' => true, 'can_override' => false, 'grps' => []];
        // fall through to render
    } else {
        require_once 'dropbox_helper.php';

        // Resolve the folder's current Dropbox path (url first — no search lag).
        $token = null; $curPath = null; $dbxErr = '';
        try {
            $token   = dropbox_get_access_token();
            $curPath = bo_path_from_url($r['dropbox_url'] ?? '');
            if ($curPath === '' || !dropbox_path_exists($token, $curPath)) {
                $curPath = dropbox_find_folder($token, $oldFolder);
            }
        } catch (Throwable $e) { $dbxErr = $e->getMessage(); }

        // Existing GRP folders for this date/code.
        $grps = ($grpAction !== 'NONE' && $token) ? bo_find_grps($token, $grpCode) : [];

        // ADD: auto-select the single match; resolve the group dates for the checks.
        if ($grpAction === 'ADD') {
            if (count($grps) === 1) $grpMain = $grps[0];
            $csFields['grpMain'] = $grpMain;
            if ($grpMain !== '') {
                $gpd = parse_folder_dates($grpMain);
                $pd  = ['start_date' => $gpd['start_date'], 'end_date' => $gpd['end_date']];
            }
        }

        // ── Decide whether the confirmation is blocked ────────────────────────
        $block = false; $canOverride = false; $blockMsg = '';
        if ($grpAction === 'CREATE' && $grps) {
            $block = true; $canOverride = true;
            $blockMsg = 'A GRP already exists for ' . $grpCode . ': ' . implode(', ', $grps)
                      . '. Add to it instead — or tick “Proceed anyway” to create a second GRP.';
        } elseif ($grpAction === 'ADD' && !$grps) {
            $block = true; $canOverride = false;
            $blockMsg = 'No existing GRP found for code ' . $grpCode . ' in 001_Safari. Use “Create new GRP” instead.';
        } elseif ($grpAction === 'ADD' && count($grps) > 1 && ($grpMain === '' || !in_array($grpMain, $grps, true))) {
            $block = true; $canOverride = false;
            $blockMsg = 'Several GRP folders match ' . $grpCode . ' — choose one below.';
        }

        // ── COMMIT ────────────────────────────────────────────────────────────
        if ($action === 'confirm_safari') {
            // Re-enforce the block server-side (CREATE dup requires the override).
            if ($block && !($canOverride && $proceed)) {
                flash($blockMsg !== '' ? $blockMsg : 'Confirmation is blocked — review the checks.', 'error');
                header('Location: backoffice.php' . ($backQs ? '?' . $backQs : '')); exit;
            }
            try {
                if ($token === null) throw new RuntimeException($dbxErr ?: 'No Dropbox token.');
                if ($curPath === null || $curPath === '') {
                    flash('Could not find "' . $oldFolder . '" in Dropbox — nothing changed. Verify the folder, then retry.', 'error');
                } else {
                    try { $db->exec("ALTER TABLE requests ADD COLUMN confirmation_date DATE NULL DEFAULT NULL"); } catch (PDOException $ig) {}
                    try { $db->exec("ALTER TABLE requests ADD COLUMN group_folder VARCHAR(255) NULL DEFAULT NULL"); } catch (PDOException $ig) {}

                    // Snapshot the pre-confirmation state so this can be rolled back exactly.
                    $preConfirm = json_encode([
                        'name'   => $oldFolder,
                        'path'   => $curPath,
                        'status' => $r['status'] ?? '',
                        'pay'    => $r['payment_status'] ?? null,
                        'action' => $grpAction,
                        'group'  => trim($r['group_folder'] ?? ''),
                        'sub'    => $subName,
                    ], JSON_UNESCAPED_UNICODE);

                    if ($grpAction === 'ADD') {
                        $destPath = '/001_Safari/' . $grpMain . '/' . $memberName;
                        if (dropbox_path_exists($token, $destPath)) {
                            flash('"' . $memberName . '" already exists inside GRP "' . $grpMain . '" — nothing changed.', 'error');
                        } else {
                            dropbox_move_folder($token, $curPath, $destPath);
                            $gpd = parse_folder_dates($grpMain);
                            $db->prepare(
                                "UPDATE requests
                                 SET practice_code=?, group_folder=?, dropbox_url=?, status='Booked', confirmation_date=CURDATE(),
                                     start_date=COALESCE(?, start_date),
                                     destination=CASE WHEN ?<>'' THEN ? ELSE destination END,
                                     pre_confirm_json=?
                                 WHERE id=?"
                            )->execute([$memberName, $grpMain, bo_url_from_path($destPath), $gpd['start_date'], $destValue, $destValue, $preConfirm, $reqId]);
                            $confirmedMailId = $reqId;
                            flash('✔ ' . ($r['customer_name'] ?? 'Booking') . ' added to GRP "' . $grpMain . '" (status → Booked).', 'info');
                        }
                    } else { // NONE or CREATE — move to a top-level 001_Safari folder
                        $newPath = '/001_Safari/' . $newName;
                        if (dropbox_path_exists($token, $newPath)) {
                            flash('A folder named "' . $newName . '" already exists in 001_Safari — nothing changed.', 'error');
                        } else {
                            dropbox_move_folder($token, $curPath, $newPath);

                            if ($grpAction === 'CREATE') {
                                // Create the member subfolder and move the loose docs (keep the group xlsx at GRP root).
                                $subPath = $newPath . '/' . $subName;
                                dropbox_create_folder($token, $subPath, false);
                                foreach (dropbox_list_files($token, $newPath) as $fn) {
                                    if (preg_match('/\.xlsx?$/i', $fn)) continue;         // group calc stays at GRP root
                                    try { dropbox_move_folder($token, $newPath . '/' . $fn, $subPath . '/' . $fn); }
                                    catch (Throwable $ig) { /* best-effort per file */ }
                                }
                                $db->prepare(
                                    "UPDATE requests
                                     SET practice_code=?, group_folder=?, dropbox_url=?, status='Booked', confirmation_date=CURDATE(),
                                         start_date=COALESCE(?, start_date),
                                         destination=CASE WHEN ?<>'' THEN ? ELSE destination END,
                                         pre_confirm_json=?
                                     WHERE id=?"
                                )->execute([$subName, $newName, bo_url_from_path($subPath), $pd['start_date'], $destValue, $destValue, $preConfirm, $reqId]);
                                $confirmedMailId = $reqId;
                                flash('✔ ' . ($r['customer_name'] ?? 'Booking') . ' — new GRP "' . $newName . '" created (status → Booked).', 'info');
                            } else { // NONE
                                $db->prepare(
                                    "UPDATE requests
                                     SET practice_code=?, dropbox_url=?, status='Booked', confirmation_date=CURDATE(),
                                         start_date=COALESCE(?, start_date),
                                         destination=CASE WHEN ?<>'' THEN ? ELSE destination END,
                                         pre_confirm_json=?
                                     WHERE id=?"
                                )->execute([$newName, bo_url_from_path($newPath), $pd['start_date'], $destValue, $destValue, $preConfirm, $reqId]);
                                $confirmedMailId = $reqId;
                                flash('✔ ' . ($r['customer_name'] ?? 'Booking') . ' confirmed → "' . $newName . '" (status → Booked).', 'info');
                            }
                        }
                    }
                }
            } catch (Throwable $e) {
                flash('Dropbox/DB error — nothing was changed: ' . $e->getMessage(), 'error');
            }
            $qsParts = $backQs;
            if ($confirmedMailId) {
                $qsParts .= ($qsParts ? '&' : '') . 'mail_for=' . $confirmedMailId . '&mail_action=' . rawurlencode($grpAction);
            }
            header('Location: backoffice.php' . ($qsParts ? '?' . $qsParts : '')); exit;
        }

        // ── PREVIEW: run non-blocking QC + GRP checks, render inline ───────────
        $checks = [];
        try {
            $xlsx   = ($token && $curPath) ? sc_fetch_calc_xlsx($token, $curPath) : null;
            $checks = sc_run_checks([
                'xlsx_path'  => $xlsx,
                'start'      => $pd['start_date'],
                'end'        => $pd['end_date'],
                'today'      => date('Y-m-d'),
                'grp_action' => $grpAction,
                'grp_code'   => $grpCode,
            ]);
            if ($xlsx) @unlink($xlsx);
        } catch (Throwable $e) {
            $checks[] = ['level' => 'info', 'msg' => 'Excel checks skipped — ' . $e->getMessage()];
        }
        if ($dbxErr !== '') {
            $checks[] = ['level' => 'info', 'msg' => 'Dropbox error while checking: ' . $dbxErr];
        } elseif (!$curPath) {
            array_unshift($checks, ['level' => 'warn',
                'msg' => 'Folder "' . $oldFolder . '" not found in Dropbox search yet (new folders can lag ~1h). You can still confirm if you know it exists.']);
        }

        // GRP existence check lines.
        if ($grpAction === 'CREATE') {
            $checks[] = $grps
                ? ['level' => 'warn', 'msg' => $blockMsg]
                : ['level' => 'ok',   'msg' => 'No existing GRP for ' . $grpCode . ' — safe to create a new group.'];
        } elseif ($grpAction === 'ADD') {
            if (!$grps)                  $checks[] = ['level' => 'warn', 'msg' => $blockMsg];
            elseif (count($grps) === 1)  $checks[] = ['level' => 'ok',   'msg' => 'Will add to existing GRP: ' . $grps[0] . '.'];
            elseif ($grpMain !== '')     $checks[] = ['level' => 'ok',   'msg' => 'Will add to GRP: ' . $grpMain . '.'];
            else                          $checks[] = ['level' => 'warn', 'msg' => $blockMsg];
        }

        $previewFor  = $reqId;
        $previewData = [
            'new_name'     => ($grpAction === 'ADD') ? ($grpMain !== '' ? $grpMain . ' / ' . $memberName : '(choose a GRP)') : $newName,
            'errors'       => [],
            'checks'       => $checks,
            'fields'       => $csFields,
            'block'        => $block,
            'can_override' => $canOverride,
            'grps'         => $grps,
        ];
    }
}

// ── Rollback a confirmation: move the folder back to its source and restore DB ─
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'rollback_confirm') {
    $reqId  = (int)($_POST['request_id'] ?? 0);
    $backQs = http_build_query(array_filter([
        'q'        => trim($_POST['q'] ?? ''),
        'root'     => trim($_POST['root'] ?? ''),
        'show_all' => !empty($_POST['show_all']) ? '1' : '',
    ], fn($x) => $x !== ''));

    $stmt = $db->prepare("SELECT id, customer_name, practice_code, group_folder, dropbox_url, pre_confirm_json
                          FROM requests WHERE id = ?");
    $stmt->execute([$reqId]);
    $r   = $stmt->fetch(PDO::FETCH_ASSOC);
    $pre = $r ? json_decode($r['pre_confirm_json'] ?? '', true) : null;

    if (!$r) {
        flash('Request not found.', 'error');
    } elseif (!$pre || empty($pre['name']) || empty($pre['path'])) {
        flash('No rollback data stored for this booking (only Hub-confirmed bookings can be rolled back).', 'error');
    } else {
        require_once 'dropbox_helper.php';
        try {
            $token    = dropbox_get_access_token();
            $act      = strtoupper($pre['action'] ?? 'NONE');
            $origName = $pre['name'];
            $destPath = $pre['path'];                        // e.g. /2026/CustName(Ag-Handler)
            $group    = trim($r['group_folder'] ?? '');
            $member   = trim($r['practice_code'] ?? '');

            if (dropbox_path_exists($token, $destPath)) {
                flash('The original location "' . $destPath . '" already exists — resolve it manually; nothing changed.', 'error');
            } elseif ($act === 'CREATE') {
                // Safe only while this is the group's sole member (authoritative: the DB).
                $cnt = $db->prepare("SELECT COUNT(*) FROM requests WHERE group_folder = ?");
                $cnt->execute([$group]);
                $members = (int)$cnt->fetchColumn();
                if ($group === '') {
                    flash('Missing group folder — cannot roll back automatically.', 'error');
                } elseif ($members > 1) {
                    flash('This group has ' . $members . ' members — remove the others first, then roll back the last one.', 'error');
                } else {
                    // Move the member's loose files back to the group root, drop the empty
                    // subfolder, then move the (renamed) group folder back to its source.
                    $sub     = trim($pre['sub'] ?? $member);
                    $grpPath = '/001_Safari/' . $group;
                    $subPath = $grpPath . '/' . $sub;
                    foreach (dropbox_list_files($token, $subPath) as $fn) {
                        try { dropbox_move_folder($token, $subPath . '/' . $fn, $grpPath . '/' . $fn); }
                        catch (Throwable $ig) { /* best-effort per file */ }
                    }
                    try { dropbox_delete_folder($token, $subPath); } catch (Throwable $ig) { /* empty subfolder */ }
                    dropbox_move_folder($token, $grpPath, $destPath);
                    bo_rollback_db($db, $reqId, $origName, $destPath, $pre);
                    flash('↩ Rolled back "' . ($r['customer_name'] ?? 'Booking') . '" — single-member group undone, folder restored to ' . $destPath . '.', 'info');
                }
            } else {
                // NONE / ADD — a single folder move back to the source path.
                $curPath = bo_path_from_url($r['dropbox_url'] ?? '');
                if ($curPath === '') {
                    $curPath = ($act === 'ADD' && $group !== '')
                        ? '/001_Safari/' . $group . '/' . $member
                        : '/001_Safari/' . $member;
                }
                dropbox_move_folder($token, $curPath, $destPath);
                bo_rollback_db($db, $reqId, $origName, $destPath, $pre);
                flash('↩ Rolled back "' . ($r['customer_name'] ?? 'Booking') . '" — folder restored to ' . $destPath . '.', 'info');
            }
        } catch (Throwable $e) {
            flash('Dropbox/DB error — nothing was changed: ' . $e->getMessage(), 'error');
        }
    }
    header('Location: backoffice.php' . ($backQs ? '?' . $backQs : '')); exit;
}

// ── Post-confirm booking email (GET, right after a successful confirmation) ────
$bookingEmail = null;   // ['to','cc','subject','body','request_id'] when set
if (($_GET['mail_for'] ?? '') !== '') {
    $mid  = (int)$_GET['mail_for'];
    $mact = strtoupper($_GET['mail_action'] ?? 'NONE');
    $ms   = $db->prepare("SELECT id, customer_name, practice_code, group_folder, agent_id FROM requests WHERE id = ?");
    $ms->execute([$mid]);
    $mr = $ms->fetch(PDO::FETCH_ASSOC);
    if ($mr) {
        $agentEmail = '';
        if (!empty($mr['agent_id'])) {
            $es = $db->prepare("SELECT email FROM users WHERE agent_id = ? AND email IS NOT NULL AND email <> '' ORDER BY id ASC LIMIT 1");
            $es->execute([(int)$mr['agent_id']]);
            $agentEmail = (string)($es->fetchColumn() ?: '');
        }
        $grpMain = '';
        $folder  = trim($mr['practice_code'] ?? '');
        if     ($mact === 'ADD')    { $grpMain = trim($mr['group_folder'] ?? ''); }
        elseif ($mact === 'CREATE') { $folder  = trim($mr['group_folder'] ?? '') ?: $folder; }
        $bookingEmail = bo_booking_email($folder, $agentEmail, $grpMain, trim($currentUser['full_name'] ?? ''));
        $bookingEmail['request_id'] = $mid;
    }
}

// ── Search ────────────────────────────────────────────────────────────────────
// Folder-root filter: which Dropbox root the request's folder lives in
// (matched literally against dropbox_url). 'All' removes the restriction.
$ROOT_MAP = [
    '2026'       => '/home/2026/',
    '001_Safari' => '/home/001_Safari/',
];
// Contracts are NOT bookings — they live only as Dropbox folders under this root.
// Selecting "Contracts" searches Dropbox directly (recursively, subfolders included)
// instead of the requests table. Keep in sync with the old Java BackOffice.
$CONTRACTS_ROOT = '/000_Contracts';

$q       = trim($_GET['q'] ?? '');
$root    = $_GET['root'] ?? '2026';
$showAll = !empty($_GET['show_all']);   // include Cancelled/Lost bookings too
if ($root !== 'All' && $root !== 'Contracts' && !isset($ROOT_MAP[$root])) $root = '2026';

$rows          = [];   // booking rows (from requests table)
$contractRows  = [];   // contract folders (from Dropbox search)
$searchError   = '';
$isContracts   = ($root === 'Contracts');

if ($q !== '' && $isContracts) {
    // ── Contracts: recursive Dropbox folder search ─────────────────────────────
    require_once 'dropbox_helper.php';
    try {
        $token        = dropbox_get_access_token();
        $contractRows = dropbox_search_folders($token, $q, $CONTRACTS_ROOT, 40);
    } catch (Throwable $e) {
        $searchError = 'Dropbox search failed: ' . $e->getMessage();
    }
} elseif ($q !== '') {
    // ── Bookings: search the requests table ────────────────────────────────────
    // '*' acts as a wildcard (like the old Java search): turn '*' into a SQL '%'.
    $like   = '%' . str_replace('*', '%', $q) . '%';
    $sql    = "SELECT r.id, r.customer_name, r.practice_code, r.group_folder, r.status, r.payment_status,
                      r.dropbox_url, r.pre_confirm_json, a.name AS agent_name
               FROM requests r LEFT JOIN agents a ON a.id = r.agent_id
               WHERE (r.customer_name LIKE ? OR r.practice_code LIKE ? OR r.group_folder LIKE ?)";
    $params = [$like, $like, $like];
    if (!$showAll) {
        $sql .= " AND r.status NOT IN ('Cancelled','Lost')";
    }
    if (isset($ROOT_MAP[$root])) {
        $sql     .= " AND LOCATE(?, r.dropbox_url) > 0";
        $params[] = $ROOT_MAP[$root];
    }
    $sql .= " ORDER BY r.id DESC LIMIT 60";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Existing GRP names — autocomplete for the "Re-group" action.
$existingGrps = [];
if ($rows) {
    try {
        $existingGrps = $db->query(
            "SELECT DISTINCT group_folder FROM requests
             WHERE group_folder IS NOT NULL AND group_folder <> '' ORDER BY group_folder"
        )->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) { $existingGrps = []; }
}

$extra_css = '
.bo-note{background:#EAF1F8;border-left:4px solid #1a3a5c;padding:12px 16px;border-radius:8px;font-size:.85rem;color:#1a3a5c;margin-bottom:18px}
.bo-table{width:100%;border-collapse:collapse}
.bo-table th{text-align:left;font-size:.68rem;text-transform:uppercase;letter-spacing:.05em;color:var(--grey-mid);padding:8px 10px;border-bottom:1px solid var(--grey-lt)}
.bo-table td{padding:8px 10px;border-bottom:1px solid var(--grey-lt);font-size:.83rem;vertical-align:middle}
.bo-folder{font-family:monospace;font-size:.75rem;word-break:break-all}
.bo-grp{font-size:.66rem;color:#8a6d3b;background:#fcf3e3;border-radius:6px;padding:1px 5px;margin-left:4px}
';
include 'includes/header.php';
?>

<div class="page-header" style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
  <h2>🛠 BackOffice — Bookings &amp; folders</h2>
  <a href="relink_folders.php" class="btn btn-outline btn-sm" title="Refresh Dropbox links after moving folders to an archive">🔗 Re-link folders (bulk)</a>
</div>

<form method="GET" class="filters">
  <div>
    <label>Folder</label>
    <select name="root">
      <?php foreach (['2026'=>'2026','001_Safari'=>'001_Safari','Contracts'=>'Contracts','All'=>'All'] as $val=>$lbl): ?>
        <option value="<?= h($val) ?>" <?= $root===$val?'selected':'' ?>><?= h($lbl) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div>
    <label>Search</label>
    <input type="text" name="q" value="<?= h($q) ?>" placeholder="Customer or folder…" autofocus style="width:260px">
  </div>
  <div>
    <label>&nbsp;</label>
    <label style="font-weight:400;font-size:.82rem;display:flex;align-items:center;gap:6px;white-space:nowrap">
      <input type="checkbox" name="show_all" value="1" <?= $showAll?'checked':'' ?>> Show cancelled / lost
    </label>
  </div>
  <div>
    <label>&nbsp;</label>
    <button type="submit" class="btn btn-outline">Search</button>
  </div>
</form>

<?php if ($q !== ''): ?>
  <?php if ($searchError): ?>
    <div class="bo-note" style="background:#fbeaea;border-left-color:#a33;color:#a33"><?= h($searchError) ?></div>
  <?php endif; ?>
  <?php if ($isContracts): ?>
    <?php if (!$contractRows): ?>
      <?php if (!$searchError): ?><p style="color:var(--grey-mid);padding:20px">No matching contract folders under <?= h($CONTRACTS_ROOT) ?> (subfolders included).</p><?php endif; ?>
    <?php else: ?>
    <div class="table-wrap">
    <table class="bo-table">
      <thead><tr><th>Contract folder</th><th>Location</th><th style="width:240px">Actions</th></tr></thead>
      <tbody>
      <?php foreach ($contractRows as $c):
          $cPath = $c['path'];                                       // /000_Contracts/Zanzibar/Mvuvi
          $cRel  = ltrim($cPath, '/');                               // 000_Contracts/Zanzibar/Mvuvi
          $cWin  = '%DROPBOX_HOME%\\' . str_replace('/', '\\', $cRel);
          $cOpen = 'savannah://open?path=' . implode('/', array_map('rawurlencode', explode('/', $cRel)));
          $cDir  = trim(dirname($cPath), '/');                       // parent path, for context
      ?>
        <tr>
          <td class="bo-folder">📁 <?= h($c['name']) ?></td>
          <td class="bo-folder" style="color:var(--grey-mid)"><?= h($cDir ?: '—') ?></td>
          <td>
            <div style="font-family:'Open Sans',sans-serif">
              <a href="<?= h($cOpen) ?>" title="Open in Windows Explorer" style="font-size:.68rem;text-decoration:none">📂 Open</a>
              <a href="#" data-copy="<?= h($cWin) ?>" onclick="copyPath(this);return false" title="Copy Windows path" style="font-size:.68rem;text-decoration:none;margin-left:8px">📋 Copy path</a>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  <?php else: ?>
  <?php if (!$rows): ?>
    <p style="color:var(--grey-mid);padding:20px">No matching bookings.</p>
  <?php else: ?>
  <datalist id="bo-grp-names">
    <?php foreach ($existingGrps as $g): ?><option value="<?= h($g) ?>"></option><?php endforeach; ?>
  </datalist>
  <div class="table-wrap">
  <table class="bo-table">
    <thead>
      <tr>
        <th>Customer</th>
        <th>Current folder</th>
        <th style="width:110px">Status</th>
        <th style="width:320px">Change to</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $r):
        $isGrp   = trim($r['group_folder'] ?? '') !== '';
        $folder  = $isGrp ? trim($r['group_folder']) : trim($r['practice_code'] ?? '');
        $psLabel = $r['payment_status'] ?: $r['status'];
        // Re-group only makes sense on a confirmed safari (its folder is under 001_Safari).
        $canRegroup = in_array($r['status'] ?? '', ['Booked', 'Provisional'], true);
        // Confirm Safari applies to a not-yet-confirmed private booking (no _START tag,
        // not already Booked/Cancelled, not a group). GRP create/add stays in the Java tool.
        $pcode      = trim($r['practice_code'] ?? '');
        $canConfirm = !$isGrp && $pcode !== ''
                    && stripos($pcode, '_START') === false
                    && !in_array($r['status'] ?? '', ['Booked', 'Cancelled', 'Lost'], true);
        // Rollback is offered for any Hub-confirmed booking (a stored snapshot). For a
        // group-create the handler still guards it: it only proceeds when this is the
        // group's sole member.
        $preRb       = json_decode($r['pre_confirm_json'] ?? '', true);
        $canRollback = is_array($preRb) && !empty($preRb['name'])
                     && in_array(strtoupper($preRb['action'] ?? 'NONE'), ['NONE', 'ADD', 'CREATE'], true);
    ?>
      <?php $isCancelled = in_array($r['status'] ?? '', ['Cancelled', 'Lost'], true); ?>
      <tr<?= $isCancelled ? ' style="background:#fcf0f0"' : '' ?>>
        <td>
          <a href="request_view.php?id=<?= (int)$r['id'] ?>" target="_blank" title="Open this request in a new tab" style="text-decoration:none;color:var(--black,#111)">
            <strong><?= h($r['customer_name']) ?></strong>
          </a>
          <?php if ($isCancelled): ?><span class="bo-grp" style="color:#a33;background:#f7dede"><?= h($r['status']) ?></span><?php endif; ?>
          <?php if ($r['agent_name']): ?><div style="font-size:.7rem;color:var(--grey-mid)">👤 <?= h($r['agent_name']) ?></div><?php endif; ?>
        </td>
        <td class="bo-folder">
          📁 <?= h($folder ?: '—') ?><?php if ($isGrp): ?><span class="bo-grp">GRP</span><?php endif; ?>
          <?php $sPath = savannah_local_path($r); $sUrl = savannah_open_url($r); ?>
          <div style="margin-top:3px;font-family:'Open Sans',sans-serif">
            <a href="request_view.php?id=<?= (int)$r['id'] ?>" target="_blank" title="Open the booking request in the Hub" style="font-size:.68rem;text-decoration:none">🔗 Open Request</a>
            <?php if ($sPath !== ''): ?>
              <a href="<?= h($sUrl) ?>" title="Open in Windows Explorer" style="font-size:.68rem;text-decoration:none;margin-left:8px">📂 Open</a>
              <a href="#" data-copy="<?= h($sPath) ?>" onclick="copyPath(this);return false" title="Copy Windows path" style="font-size:.68rem;text-decoration:none;margin-left:8px">📋 Copy path</a>
            <?php endif; ?>
            <?php if ($folder !== ''): ?>
              <a href="#" data-copy="<?= h($folder) ?>" onclick="copyPath(this);return false" title="Copy the folder name (to paste into an email)" style="font-size:.68rem;text-decoration:none;margin-left:<?= $sPath!==''?'8px':'0' ?>">📄 Copy folder name</a>
              <a href="#" onclick="toggleRename(<?= (int)$r['id'] ?>);return false" title="Rename the Dropbox folder" style="font-size:.68rem;text-decoration:none;margin-left:8px">✏ Rename…</a>
              <?php if ($canConfirm): ?>
              <a href="#" onclick="toggleEl('cs<?= (int)$r['id'] ?>');return false" title="Confirm this safari: set dates, move to 001_Safari, mark Booked" style="font-size:.68rem;text-decoration:none;margin-left:8px;color:#1A6B3A;font-weight:600">✅ Confirm Safari…</a>
              <?php endif; ?>
              <?php if ($canRegroup): ?>
              <a href="#" onclick="toggleEl('rg<?= (int)$r['id'] ?>');return false" title="Fix a wrong confirmation: move this booking between private and a group" style="font-size:.68rem;text-decoration:none;margin-left:8px">👥 Re-group…</a>
              <?php endif; ?>
              <?php if ($canRollback): ?>
              <a href="#" onclick="toggleEl('rb<?= (int)$r['id'] ?>');return false" title="Undo the confirmation: move the folder back and restore the request" style="font-size:.68rem;text-decoration:none;margin-left:8px;color:#B26A00;font-weight:600">↩ Rollback…</a>
              <?php endif; ?>
            <?php endif; ?>
          </div>
          <?php if ($folder !== '' && $canRegroup): ?>
          <?php $curGrp = trim($r['group_folder'] ?? ''); ?>
          <form method="POST" id="rg<?= (int)$r['id'] ?>" style="display:none;margin-top:6px;padding:8px;background:#f6f6f4;border-radius:6px"
                onsubmit="return confirm('Move this booking\'s Dropbox folder and update its group?\n\nThis moves the real Dropbox folder.');">
            <input type="hidden" name="action" value="regroup">
            <input type="hidden" name="request_id" value="<?= (int)$r['id'] ?>">
            <input type="hidden" name="q" value="<?= h($q) ?>">
            <input type="hidden" name="root" value="<?= h($root) ?>">
            <input type="hidden" name="show_all" value="<?= $showAll ? '1' : '' ?>">
            <div style="font-size:.68rem;color:var(--grey-mid);margin-bottom:4px">Now: <?= $curGrp !== '' ? 'group “' . h($curGrp) . '”' : 'private (no group)' ?></div>
            <select name="regroup_type" onchange="var g=document.getElementById('rgn<?= (int)$r['id'] ?>');g.style.display=this.value==='grp'?'block':'none'" style="font-size:.72rem;padding:3px 5px;margin-bottom:4px">
              <option value="grp"<?= $curGrp!==''?' selected':'' ?>>Group (join existing / create new)</option>
              <option value="private"<?= $curGrp!==''?'':' selected' ?>>Private (no group)</option>
            </select>
            <input type="text" id="rgn<?= (int)$r['id'] ?>" name="target_grp" list="bo-grp-names" value="<?= h($curGrp) ?>"
                   placeholder="GRP folder name (existing or new)" spellcheck="false"
                   style="display:<?= $curGrp!==''?'block':'none' ?>;width:100%;font-family:monospace;font-size:.72rem;padding:5px 7px;border:1.5px solid var(--grey-lt);border-radius:5px;margin-bottom:4px">
            <div style="display:flex;gap:6px">
              <button type="submit" class="btn btn-red btn-sm">Move</button>
              <button type="button" class="btn btn-outline btn-sm" onclick="toggleEl('rg<?= (int)$r['id'] ?>')">Cancel</button>
            </div>
          </form>
          <?php endif; ?>
          <?php if ($folder !== ''): ?>
          <form method="POST" id="rn<?= (int)$r['id'] ?>" style="display:none;margin-top:6px"
                onsubmit="return confirm('<?= $isGrp ? 'GROUP: this renames the shared group folder and updates ALL its bookings.\\n\\n' : '' ?>Rename the real Dropbox folder to the new name?');">
            <input type="hidden" name="action" value="rename">
            <input type="hidden" name="request_id" value="<?= (int)$r['id'] ?>">
            <input type="hidden" name="q" value="<?= h($q) ?>">
            <input type="hidden" name="root" value="<?= h($root) ?>">
            <input type="hidden" name="show_all" value="<?= $showAll ? '1' : '' ?>">
            <input type="text" name="new_name" value="<?= h($folder) ?>" spellcheck="false"
                   style="width:100%;font-family:monospace;font-size:.72rem;padding:5px 7px;border:1.5px solid var(--grey-lt);border-radius:5px">
            <div style="margin-top:4px;display:flex;gap:6px">
              <button type="submit" class="btn btn-red btn-sm">Rename</button>
              <button type="button" class="btn btn-outline btn-sm" onclick="toggleRename(<?= (int)$r['id'] ?>)">Cancel</button>
            </div>
          </form>
          <?php endif; ?>

          <?php if ($canRollback): ?>
          <form method="POST" id="rb<?= (int)$r['id'] ?>" style="display:none;margin-top:6px;padding:8px;background:#fff7ec;border:1px solid #f0d9b5;border-radius:6px"
                onsubmit="return confirm('Roll back this confirmation?\n\nThe Dropbox folder is moved back to its original location and the request is set back to un-booked.');">
            <input type="hidden" name="action" value="rollback_confirm">
            <input type="hidden" name="request_id" value="<?= (int)$r['id'] ?>">
            <input type="hidden" name="q" value="<?= h($q) ?>">
            <input type="hidden" name="root" value="<?= h($root) ?>">
            <input type="hidden" name="show_all" value="<?= $showAll ? '1' : '' ?>">
            <div style="font-size:.68rem;color:#B26A00;margin-bottom:6px">↩ Move the folder back to <span style="font-family:monospace"><?= h($preRb['path'] ?? '') ?></span> and restore the request to un-booked.</div>
            <div style="display:flex;gap:6px">
              <button type="submit" class="btn btn-red btn-sm">Roll back</button>
              <button type="button" class="btn btn-outline btn-sm" onclick="toggleEl('rb<?= (int)$r['id'] ?>')">Cancel</button>
            </div>
          </form>
          <?php endif; ?>

          <?php
            $isPreview = ($previewFor === (int)$r['id'] && $previewData !== null);
            $pv = $isPreview ? $previewData['fields']
                             : ['fStart'=>'','fMid'=>'NA','fMid2'=>'NA','fEnd'=>'','fDestKey'=>'','grpAction'=>'NONE','grpCode'=>'','grpMain'=>''];
            $rid = (int)$r['id'];
          ?>
          <?php if ($canConfirm): ?>
          <form method="POST" id="cs<?= $rid ?>" style="display:<?= $isPreview ? 'block' : 'none' ?>;margin-top:6px;padding:10px;background:#eef6f0;border:1px solid #cfe6d6;border-radius:8px">
            <input type="hidden" name="action" value="confirm_preview">
            <input type="hidden" name="request_id" value="<?= $rid ?>">
            <input type="hidden" name="q" value="<?= h($q) ?>">
            <input type="hidden" name="root" value="<?= h($root) ?>">
            <input type="hidden" name="show_all" value="<?= $showAll ? '1' : '' ?>">
            <div style="font-size:.68rem;color:#1A6B3A;font-weight:600;margin-bottom:6px">✅ Confirm Safari — enter dates as in the Excel</div>
            <div style="display:flex;gap:8px;margin-bottom:6px;font-family:'Open Sans',sans-serif">
              <label style="font-size:.66rem;color:var(--grey-mid);flex:1">Booking type
                <select name="cs_grp" onchange="document.getElementById('csgw<?= $rid ?>').style.display=this.value==='NONE'?'none':'block'" style="width:100%;font-size:.74rem;padding:4px 6px;border:1.5px solid var(--grey-lt);border-radius:5px">
                  <option value="NONE"   <?= $pv['grpAction']==='NONE'   ?'selected':'' ?>>Private (no group)</option>
                  <option value="CREATE" <?= $pv['grpAction']==='CREATE' ?'selected':'' ?>>Create new GRP</option>
                  <option value="ADD"    <?= $pv['grpAction']==='ADD'    ?'selected':'' ?>>Add to existing GRP</option>
                </select>
              </label>
              <label id="csgw<?= $rid ?>" style="font-size:.66rem;color:var(--grey-mid);width:130px;display:<?= $pv['grpAction']==='NONE'?'none':'block' ?>">GRP code (DDMM)
                <input type="text" name="cs_grpcode" value="<?= h($pv['grpCode']) ?>" placeholder="from Start" spellcheck="false"
                       style="width:100%;font-family:monospace;font-size:.74rem;padding:4px 6px;border:1.5px solid var(--grey-lt);border-radius:5px"></label>
            </div>
            <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:6px 10px;font-family:'Open Sans',sans-serif">
              <label style="font-size:.66rem;color:var(--grey-mid)">Start (DDMMM)
                <input type="text" name="cs_start" value="<?= h($pv['fStart']) ?>" placeholder="05JAN" spellcheck="false"
                       style="width:100%;font-family:monospace;font-size:.74rem;padding:4px 6px;border:1.5px solid var(--grey-lt);border-radius:5px;text-transform:uppercase"></label>
              <label style="font-size:.66rem;color:var(--grey-mid)">End (DDMMMYYYY)
                <input type="text" name="cs_end" value="<?= h($pv['fEnd']) ?>" placeholder="18JAN2026" spellcheck="false"
                       style="width:100%;font-family:monospace;font-size:.74rem;padding:4px 6px;border:1.5px solid var(--grey-lt);border-radius:5px;text-transform:uppercase"></label>
              <label style="font-size:.66rem;color:var(--grey-mid)">Middle (DDMMM / NA)
                <input type="text" name="cs_mid" value="<?= h($pv['fMid']) ?>" placeholder="NA" spellcheck="false"
                       style="width:100%;font-family:monospace;font-size:.74rem;padding:4px 6px;border:1.5px solid var(--grey-lt);border-radius:5px;text-transform:uppercase"></label>
              <label style="font-size:.66rem;color:var(--grey-mid)">Middle 2 (DDMMM / NA)
                <input type="text" name="cs_mid2" value="<?= h($pv['fMid2']) ?>" placeholder="NA" spellcheck="false"
                       style="width:100%;font-family:monospace;font-size:.74rem;padding:4px 6px;border:1.5px solid var(--grey-lt);border-radius:5px;text-transform:uppercase"></label>
            </div>
            <label style="font-size:.66rem;color:var(--grey-mid);display:block;margin-top:6px;font-family:'Open Sans',sans-serif">Destination
              <select name="cs_dest" style="width:100%;font-size:.74rem;padding:4px 6px;border:1.5px solid var(--grey-lt);border-radius:5px">
                <?php foreach (array_keys($CONFIRM_DESTINATIONS) as $dlabel): ?>
                  <option value="<?= h($dlabel) ?>" <?= ($pv['fDestKey'] === $dlabel || ($pv['fDestKey'] === '' && $dlabel === array_key_first($CONFIRM_DESTINATIONS))) ? 'selected' : '' ?>><?= h($dlabel) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <div style="margin-top:8px;display:flex;gap:6px">
              <button type="submit" class="btn btn-outline btn-sm">🔍 Check &amp; preview</button>
              <button type="button" class="btn btn-outline btn-sm" onclick="toggleEl('cs<?= $rid ?>')">Cancel</button>
            </div>
          </form>
          <?php endif; ?>

          <?php if ($isPreview): ?>
          <?php
            $pvBlock    = !empty($previewData['block']);
            $pvOverride = !empty($previewData['can_override']);
            $pvGrps     = $previewData['grps'] ?? [];
            $pvGrpMult  = ($pv['grpAction'] === 'ADD' && count($pvGrps) > 1 && $pv['grpMain'] === '');
            // Common hidden fields carried into the confirm/re-preview submit.
            $csHidden = function () use ($rid, $pv, $q, $root, $showAll) { ?>
                <input type="hidden" name="request_id" value="<?= $rid ?>">
                <input type="hidden" name="cs_start" value="<?= h($pv['fStart']) ?>">
                <input type="hidden" name="cs_mid"   value="<?= h($pv['fMid']) ?>">
                <input type="hidden" name="cs_mid2"  value="<?= h($pv['fMid2']) ?>">
                <input type="hidden" name="cs_end"   value="<?= h($pv['fEnd']) ?>">
                <input type="hidden" name="cs_dest"  value="<?= h($pv['fDestKey']) ?>">
                <input type="hidden" name="cs_grp"   value="<?= h($pv['grpAction']) ?>">
                <input type="hidden" name="cs_grpcode" value="<?= h($pv['grpCode']) ?>">
                <input type="hidden" name="q" value="<?= h($q) ?>">
                <input type="hidden" name="root" value="<?= h($root) ?>">
                <input type="hidden" name="show_all" value="<?= $showAll ? '1' : '' ?>">
            <?php };
          ?>
          <div style="margin-top:6px;padding:10px;background:#fff;border:1px solid #cfe6d6;border-radius:8px">
            <?php if (!empty($previewData['errors'])): ?>
              <div style="font-size:.72rem;color:#a33;font-weight:600;margin-bottom:4px">Fix these before confirming:</div>
              <ul style="margin:0 0 0 16px;padding:0;font-size:.72rem;color:#a33">
                <?php foreach ($previewData['errors'] as $er): ?><li><?= h($er) ?></li><?php endforeach; ?>
              </ul>
            <?php else: ?>
              <div style="font-size:.66rem;color:var(--grey-mid);margin-bottom:2px"><?= $pv['grpAction']==='ADD' ? 'Will file under' : 'New folder name' ?></div>
              <div class="bo-folder" style="font-size:.74rem;margin-bottom:8px">📁 <?= h($previewData['new_name']) ?></div>
              <?php if (!empty($previewData['checks'])): ?>
                <div style="font-size:.66rem;color:var(--grey-mid);margin-bottom:3px">Pre-flight checks (advisory)</div>
                <ul style="margin:0 0 8px 0;padding:0;list-style:none;font-size:.72rem;line-height:1.5">
                  <?php foreach ($previewData['checks'] as $ck):
                        $lv = $ck['level'] ?? 'info';
                        $ic = $lv === 'ok' ? '✓' : ($lv === 'warn' ? '⚠' : 'ℹ');
                        $cl = $lv === 'ok' ? '#1A6B3A' : ($lv === 'warn' ? '#B26A00' : '#666'); ?>
                    <li style="color:<?= $cl ?>"><?= $ic ?> <?= h($ck['msg']) ?></li>
                  <?php endforeach; ?>
                </ul>
              <?php endif; ?>

              <?php if ($pvGrpMult): ?>
                <!-- ADD, several matching GRPs: pick one, then re-preview. -->
                <form method="POST" style="margin:0 0 4px 0">
                  <input type="hidden" name="action" value="confirm_preview">
                  <?php $csHidden(); ?>
                  <div style="font-size:.66rem;color:var(--grey-mid);margin-bottom:3px">Choose the GRP to add to:</div>
                  <select name="cs_grpmain" style="width:100%;font-family:monospace;font-size:.72rem;padding:4px 6px;border:1.5px solid var(--grey-lt);border-radius:5px;margin-bottom:6px">
                    <?php foreach ($pvGrps as $gname): ?><option value="<?= h($gname) ?>"><?= h($gname) ?></option><?php endforeach; ?>
                  </select>
                  <button type="submit" class="btn btn-outline btn-sm">Use this GRP →</button>
                </form>
              <?php elseif ($pvBlock && !$pvOverride): ?>
                <!-- Blocked, no override (e.g. ADD but no GRP exists): must fix the form above. -->
                <div style="font-size:.7rem;color:#B26A00;font-weight:600">Cannot confirm yet — adjust the booking type / GRP code above and re-check.</div>
              <?php else: ?>
                <form method="POST" onsubmit="return confirm('<?= $pv['grpAction']==='ADD' ? 'Move the folder into the GRP and mark Booked?' : 'Move the Dropbox folder to 001_Safari and mark Booked?' ?>');" style="margin:0">
                  <input type="hidden" name="action" value="confirm_safari">
                  <?php $csHidden(); ?>
                  <input type="hidden" name="cs_grpmain" value="<?= h($pv['grpMain']) ?>">
                  <?php if ($pvBlock && $pvOverride): ?>
                    <label style="display:flex;gap:6px;align-items:flex-start;font-size:.7rem;color:#B26A00;margin-bottom:6px;font-weight:600">
                      <input type="checkbox" name="cs_proceed" value="1" onchange="document.getElementById('csbtn<?= $rid ?>').disabled=!this.checked" style="margin-top:2px">
                      Proceed anyway — a GRP already exists for this date; create a second one deliberately.
                    </label>
                    <button type="submit" id="csbtn<?= $rid ?>" class="btn btn-red btn-sm" disabled>Continue anyway</button>
                  <?php else: ?>
                    <div style="display:flex;gap:6px;align-items:center">
                      <button type="submit" class="btn btn-red btn-sm"><?= $pv['grpAction']==='ADD' ? '✅ Add to GRP' : '✅ Confirm now' ?></button>
                      <span style="font-size:.66rem;color:var(--grey-mid)">Warnings do not block — confirm when you're satisfied.</span>
                    </div>
                  <?php endif; ?>
                </form>
              <?php endif; ?>
            <?php endif; ?>
          </div>
          <?php endif; ?>
        </td>
        <td><span class="badge"><?= h($psLabel) ?></span></td>
        <td>
          <form method="POST" style="display:flex;gap:6px;align-items:center;margin:0"
                onsubmit="return confirm('<?= $isGrp ? 'GROUP: this renames the shared group folder and updates ALL its bookings.\\n\\n' : '' ?>Rename the Dropbox folder and set to ' + this.new_status.value + '?\n\nThis renames the real Dropbox folder.');">
            <input type="hidden" name="action" value="change_status">
            <input type="hidden" name="request_id" value="<?= (int)$r['id'] ?>">
            <input type="hidden" name="q" value="<?= h($q) ?>">
            <input type="hidden" name="root" value="<?= h($root) ?>">
            <input type="hidden" name="show_all" value="<?= $showAll ? '1' : '' ?>">
            <select name="new_status" class="m-input" style="width:150px;padding:5px 8px;font-size:.8rem">
              <?php foreach (array_keys($STATUS_MAP) as $st): ?>
                <option value="<?= h($st) ?>"><?= h($st) ?></option>
              <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-red btn-sm"><?= $isGrp ? 'Apply (group)' : 'Apply' ?></button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
  <?php endif; ?>
<?php else: ?>
  <p style="color:var(--grey-mid);padding:20px">Search a customer name or folder to begin.</p>
<?php endif; ?>

<script>
function toggleRename(id) {
  var f = document.getElementById('rn' + id);
  if (f) f.style.display = (f.style.display === 'none' || !f.style.display) ? 'block' : 'none';
}
// Toggle any inline box (e.g. the re-group form) by full element id.
function toggleEl(elId) {
  var f = document.getElementById(elId);
  if (f) f.style.display = (f.style.display === 'none' || !f.style.display) ? 'block' : 'none';
}
// Copy text (Windows path or folder name) to the clipboard.
function copyPath(el) {
  var t = el.getAttribute('data-copy') || el.getAttribute('data-path') || '';
  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(t).then(function(){ flashCopied(el); }, function(){ fallbackCopy(t, el); });
  } else { fallbackCopy(t, el); }
}
function fallbackCopy(t, el) {
  var ta = document.createElement('textarea');
  ta.value = t; ta.style.position = 'fixed'; ta.style.opacity = '0';
  document.body.appendChild(ta); ta.select();
  try { document.execCommand('copy'); flashCopied(el); } catch (e) { prompt('Copy this path:', t); }
  document.body.removeChild(ta);
}
function flashCopied(el) { var o = el.textContent; el.textContent = '✓ Copied'; setTimeout(function(){ el.textContent = o; }, 1200); }
</script>

<?php if ($bookingEmail): ?>
<!-- Post-confirmation booking email (ports the Java "Send Booking Email" dialog). -->
<div id="mailOverlay" style="display:flex;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:200;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:10px;max-width:720px;width:94%;max-height:92vh;overflow:auto;padding:20px;box-shadow:0 12px 40px rgba(0,0,0,.3)">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
      <div style="font-size:1.05rem;font-weight:700">✉ Send booking email</div>
      <button type="button" class="btn btn-outline btn-sm" onclick="document.getElementById('mailOverlay').style.display='none'">✕</button>
    </div>
    <div id="mailStatus" style="display:none;margin-bottom:10px;border-radius:6px;padding:8px 12px;font-size:.82rem"></div>
    <div class="form-group"><label for="mail_to">To</label>
      <input type="text" id="mail_to" value="<?= h($bookingEmail['to']) ?>" style="width:100%"></div>
    <div class="form-group"><label for="mail_cc">Cc</label>
      <input type="text" id="mail_cc" value="<?= h($bookingEmail['cc']) ?>" style="width:100%"></div>
    <div class="form-group"><label for="mail_subject">Subject</label>
      <input type="text" id="mail_subject" value="<?= h($bookingEmail['subject']) ?>" style="width:100%"></div>
    <div class="form-group"><label for="mail_body">Body</label>
      <textarea id="mail_body" rows="14" style="width:100%;font-family:inherit;line-height:1.5"><?= h($bookingEmail['body']) ?></textarea></div>
    <input type="hidden" id="mail_request_id" value="<?= (int)$bookingEmail['request_id'] ?>">
    <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:8px">
      <button type="button" class="btn btn-outline" onclick="document.getElementById('mailOverlay').style.display='none'">Skip</button>
      <button type="button" class="btn btn-red" id="mail_send_btn" onclick="sendBookingEmail()">Send email</button>
    </div>
  </div>
</div>
<script>
function sendBookingEmail() {
  var btn = document.getElementById('mail_send_btn');
  var st  = document.getElementById('mailStatus');
  var body = new URLSearchParams({
    request_id: document.getElementById('mail_request_id').value,
    to:      document.getElementById('mail_to').value,
    cc:      document.getElementById('mail_cc').value,
    subject: document.getElementById('mail_subject').value,
    body:    document.getElementById('mail_body').value
  });
  btn.disabled = true; btn.textContent = 'Sending…';
  fetch('ajax_booking_email.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body })
    .then(function (r) { return r.json(); })
    .then(function (j) {
      if (j && j.success) {
        st.textContent = '✔ Email sent.'; st.style.background = '#DCFCE7'; st.style.color = '#166534'; st.style.display = 'block';
        setTimeout(function () { document.getElementById('mailOverlay').style.display = 'none'; }, 900);
      } else { throw new Error((j && j.message) || 'Send failed.'); }
    })
    .catch(function (e) { st.textContent = '⚠ ' + e.message; st.style.background = '#FEE2E2'; st.style.color = '#991B1B'; st.style.display = 'block'; })
    .finally(function () { btn.disabled = false; btn.textContent = 'Send email'; });
}
</script>
<?php endif; ?>

<?php include 'includes/footer.php'; ?>
