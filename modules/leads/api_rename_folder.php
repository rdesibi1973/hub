<?php
/**
 * api_rename_folder.php
 *
 * Called by the Java BackOffice after a status rename (e.g. PROGRESS → DEPOSIT).
 * Updates practice_code, dropbox_url, and status in the requests table.
 *
 * POST JSON body:
 *   old_folder_name  string  – current practice_code value in DB
 *   new_folder_name  string  – new folder name after rename
 *
 * Authentication: X-API-Key header must match API_KEY constant.
 *
 * Place this file in: /modules/leads/
 */

require_once 'config.php';
require_once 'includes/booking_service.php';   // bo_path_from_url, bo_url_from_path
header('Content-Type: application/json');

// ── Auth ──────────────────────────────────────────────────────────────────────
if (($_SERVER['HTTP_X_API_KEY'] ?? '') !== API_KEY) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// ── Input ─────────────────────────────────────────────────────────────────────
$body          = json_decode(file_get_contents('php://input'), true) ?? [];
$oldFolderName = trim($body['old_folder_name'] ?? '');
$newFolderName = trim($body['new_folder_name'] ?? '');

if ($oldFolderName === '' || $newFolderName === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'old_folder_name and new_folder_name are required']);
    exit;
}

// ── Find request by current practice_code ─────────────────────────────────────
// practice_code stores the FULL folder string, e.g.
//   08_14AUG_TRA1408(54traveler-Roberto)_START14AUG_END22AUG2026_PROVISIONAL
// Across status renames only the trailing _TAG changes; the rest is stable.
$db   = db();
$stmt = $db->prepare(
    'SELECT id, customer_name, status, dropbox_url, practice_code, group_folder
     FROM requests WHERE practice_code = ? ORDER BY id DESC LIMIT 1'
);
$stmt->execute([$oldFolderName]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

// Fallback 0: trim-resilient match (guards against stray trailing whitespace
// stored in practice_code that breaks the exact equality above).
if (!$row) {
    $stmtTrim = $db->prepare(
        'SELECT id, customer_name, status, dropbox_url, practice_code, group_folder
         FROM requests WHERE TRIM(practice_code) = ? ORDER BY id DESC LIMIT 1'
    );
    $stmtTrim->execute([$oldFolderName]);
    $row = $stmtTrim->fetch(PDO::FETCH_ASSOC);
}

// Fallback 0b: match on the base (status suffix stripped). The base is a
// stable, unique prefix; across renames only a trailing _TAG follows it.
// We match practice_code that equals the base, or equals base + "_<TAG>".
if (!$row) {
    $statusTagsRe = '_(PROGRESS|PROVISIONAL|DEPOSIT|BALANCE-CASH|BALANCE|CANCELLED|CK|PAID)$';
    $incomingBase = preg_replace('/' . $statusTagsRe . '/i', '', $oldFolderName);
    if ($incomingBase !== $oldFolderName) {
        // Escape LIKE wildcards in the base so '(', ')', '%', '_' are literal.
        $likeBase = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $incomingBase);
        $stmtBase = $db->prepare(
            "SELECT id, customer_name, status, dropbox_url, practice_code, group_folder
             FROM requests
             WHERE TRIM(practice_code) = ?
                OR  TRIM(practice_code) LIKE ? ESCAPE '\\\\'
             ORDER BY id DESC LIMIT 1"
        );
        // base + "_" + any tag (no further underscores in tags except BALANCE-CASH which has none after _)
        $stmtBase->execute([$incomingBase, $likeBase . '\\_%']);
        $row = $stmtBase->fetch(PDO::FETCH_ASSOC);
    }
}

// Fallback 1: strip known status suffixes and try base name.
if (!$row) {
    $statusTags = ['_PROGRESS','_PROVISIONAL','_DEPOSIT','_BALANCE-CASH','_BALANCE','_CANCELLED','_CK','_PAID'];
    $baseName   = $oldFolderName;
    foreach ($statusTags as $tag) {
        if (str_ends_with($baseName, $tag)) {
            $baseName = substr($baseName, 0, -strlen($tag));
            break;
        }
    }
    if ($baseName !== $oldFolderName) {
        $stmt->execute([$baseName]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }
} else {
    $baseName = $oldFolderName;
}

// Fallback 2: strip confirmed-folder date wrapper to get bare customer name.
// Confirmed folder format: {prog}_{date}_{customer}_START{date}_END{date}{year}
// e.g. "08_11AUG_TZ260810(STOGranTour-Roberto)_START11AUG_END18AUG2026"
//   → bare customer name "TZ260810(STOGranTour-Roberto)"
$bare = '';
if (!$row) {
    $bare = preg_replace('/^\d+_\d+[A-Z]+_/i', '', $baseName); // strip leading prognum_date_
    $bare = preg_replace('/_START.+$/i',        '', $bare);     // strip trailing _START...
    if ($bare !== '' && $bare !== $baseName) {
        $stmt->execute([$bare]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }
}

// Fallback 3: search by dropbox_url.
if (!$row) {
    $searchName = ($bare !== '' && $bare !== $baseName) ? $bare
                : ($baseName !== $oldFolderName ? $baseName : $oldFolderName);
    $stmt2 = $db->prepare(
        'SELECT id, customer_name, status, dropbox_url, practice_code, group_folder
         FROM requests WHERE dropbox_url LIKE ? ORDER BY id DESC LIMIT 1'
    );
    $stmt2->execute(['%/' . rawurlencode($searchName) . '%']);
    $row = $stmt2->fetch(PDO::FETCH_ASSOC);
}

// Fallback 4: GRP parent folder — search by group_folder column.
// For group bookings the practice_code is the subfolder, but the rename
// is performed on the parent (group_folder).  When found this way we must
// update group_folder instead of practice_code.
$matchedViaGroupFolder = false;
if (!$row) {
    $stmtGrp = $db->prepare(
        'SELECT id, customer_name, status, dropbox_url, practice_code, group_folder
         FROM requests WHERE group_folder = ? ORDER BY id DESC LIMIT 1'
    );
    $stmtGrp->execute([$oldFolderName]);
    $row = $stmtGrp->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $matchedViaGroupFolder = true;
    }
}

if (!$row) {
    http_response_code(404);
    echo json_encode([
        'success' => false,
        'message' => 'No request found with practice_code = ' . $oldFolderName,
    ]);
    exit;
}

// ── Rebuild Dropbox URL preserving the directory (2026 vs 001_Safari) ────────
// The old URL is https://www.dropbox.com/home/<dir>/<old_name>
// We keep the same directory and just swap the folder name.
$newDropboxUrl = '';
$oldUrl = $row['dropbox_url'] ?? '';
if ($oldUrl !== '') {
    // Extract everything up to and including the last '/' before the folder name
    $lastSlash = strrpos($oldUrl, '/');
    if ($lastSlash !== false) {
        $newDropboxUrl = substr($oldUrl, 0, $lastSlash + 1) . rawurlencode($newFolderName);
    }
}
// Fallback: derive from status
if ($newDropboxUrl === '') {
    $dir = ($row['status'] === 'Booked') ? '001_Safari' : '2026';
    $newDropboxUrl = 'https://www.dropbox.com/home/' . $dir . '/' . rawurlencode($newFolderName);
}

// ── Derive new DB status + payment_status from folder name suffix ─────────────
// Each entry: ['status' => ..., 'ps' => payment_status value or null to clear]
// When a tag is matched, both status AND payment_status are always updated.
// _PROGRESS / _CONFIRMED / _PROVISIONAL / _CANCELLED clear payment_status (NULL).
//
// A "contains" match (strpos) is used, NOT str_ends_with, because folders keep a
// trailing document marker after the payment tag (e.g. ..._BALANCE-CASH_CK).
// str_ends_with('_BALANCE-CASH') would miss those and leave payment_status stale.
// Most-specific tags first so _BALANCE-CASH is not shadowed by _BALANCE.
$folderTagMap = [
    '_BALANCE-CASH' => ['status' => 'Booked',      'ps' => 'Balance-Cash'],
    '_BALANCE_CASH' => ['status' => 'Booked',      'ps' => 'Balance-Cash'],
    '_BALANCE'      => ['status' => 'Booked',      'ps' => 'Balance'],
    '_DEPOSIT'      => ['status' => 'Booked',      'ps' => 'Deposit'],
    '_PAID'         => ['status' => 'Booked',      'ps' => 'Paid'],
    '_PROGRESS'     => ['status' => 'Booked',      'ps' => null],
    '_CONFIRMED'    => ['status' => 'Booked',      'ps' => null],
    '_PROVISIONAL'  => ['status' => 'Provisional', 'ps' => null],
    '_CANCELLED'    => ['status' => 'Cancelled',   'ps' => null],
];
$newDbStatus      = null;
$newPaymentStatus = false; // false = leave unchanged; null = explicitly clear
$upperNew = strtoupper($newFolderName);
foreach ($folderTagMap as $tag => $map) {
    if (strpos($upperNew, $tag) !== false) {
        $newDbStatus      = $map['status'];
        $newPaymentStatus = $map['ps']; // null means clear it
        break;
    }
}

// Imported group (practice_code = group_folder): the folder is the group itself.
// It is found via practice_code, never Fallback 4, but it is still a group rename.
if (!$matchedViaGroupFolder
    && trim($row['group_folder'] ?? '') !== ''
    && trim($row['group_folder']) === trim($row['practice_code'] ?? '')) {
    $matchedViaGroupFolder = true;
}

// ── Update ────────────────────────────────────────────────────────────────────
$updatedCount = 1;
if ($matchedViaGroupFolder) {
    // GRP parent folder rename: every request of the group moves with the parent.
    // Members keep their sub-folder (practice_code) and payment_status (own invoice);
    // the group tag only sets the booking status, never reviving a cancelled client.
    // The imported group itself (practice_code = group_folder) takes the new name and,
    // since the tag is on its own folder, the payment_status too.
    $oldGroup = trim($row['group_folder']);
    $members  = $db->prepare(
        'SELECT id, status, practice_code, dropbox_url FROM requests WHERE group_folder = ?'
    );
    $members->execute([$row['group_folder']]);
    $members = $members->fetchAll(PDO::FETCH_ASSOC);

    // Group folder path from the first stored URL that points inside it
    // (keeps the directory, e.g. /001_Safari/00_2026).
    $groupPath = '';
    foreach ($members as $m) {
        $p   = bo_path_from_url($m['dropbox_url'] ?? '');
        $sub = trim($m['practice_code'] ?? '');
        if ($p !== '' && $sub !== '' && $sub !== $oldGroup && str_ends_with($p, '/' . $sub)) {
            $p = substr($p, 0, -strlen('/' . $sub));
        }
        if ($p !== '' && strcasecmp(basename($p), $oldGroup) === 0) { $groupPath = $p; break; }
    }
    if ($groupPath === '') $groupPath = '/001_Safari/' . $oldGroup;
    $newGroupPath = substr($groupPath, 0, (int)strrpos($groupPath, '/')) . '/' . $newFolderName;

    $upd   = $db->prepare(
        'UPDATE requests SET group_folder = ?, practice_code = ?, dropbox_url = ?, status = ? WHERE id = ?'
    );
    $updPs = $db->prepare(
        'UPDATE requests SET group_folder = ?, practice_code = ?, dropbox_url = ?, status = ?, payment_status = ? WHERE id = ?'
    );
    foreach ($members as $m) {
        $sub    = trim($m['practice_code'] ?? '');
        $isSelf = $sub === $oldGroup;
        $code   = $isSelf ? $newFolderName : $m['practice_code'];
        $url    = bo_url_from_path($isSelf || $sub === '' ? $newGroupPath : $newGroupPath . '/' . $sub);
        $keep   = ($m['status'] ?? '') === 'Cancelled' && $newDbStatus !== 'Cancelled';
        $status = ($newDbStatus !== null && !$keep) ? $newDbStatus : $m['status'];
        if ($isSelf && $newDbStatus !== null) {
            $updPs->execute([$newFolderName, $code, $url, $status, $newPaymentStatus, (int)$m['id']]);
        } else {
            $upd->execute([$newFolderName, $code, $url, $status, (int)$m['id']]);
        }
        if ((int)$m['id'] === (int)$row['id']) $newDropboxUrl = $url;
    }
    $updatedCount = count($members);
} else {
    if ($newDbStatus !== null) {
        $update = $db->prepare(
            'UPDATE requests SET practice_code = ?, dropbox_url = ?, status = ?, payment_status = ? WHERE id = ?'
        );
        $update->execute([$newFolderName, $newDropboxUrl, $newDbStatus, $newPaymentStatus, (int)$row['id']]);
    } else {
        $update = $db->prepare(
            'UPDATE requests SET practice_code = ?, dropbox_url = ? WHERE id = ?'
        );
        $update->execute([$newFolderName, $newDropboxUrl, (int)$row['id']]);
    }
}

echo json_encode([
    'success'           => true,
    'request_id'        => (int)$row['id'],
    'customer_name'     => $row['customer_name'],
    'old_folder'        => $oldFolderName,
    'new_folder'        => $newFolderName,
    'updated_field'     => $matchedViaGroupFolder ? 'group_folder' : 'practice_code',
    'updated_count'     => $updatedCount,
    'dropbox_url'       => $newDropboxUrl,
    'new_status'        => $newDbStatus,
    'new_payment_status'=> $newPaymentStatus,
]);
