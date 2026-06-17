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
$db   = db();
$stmt = $db->prepare(
    'SELECT id, customer_name, status, dropbox_url, practice_code
     FROM requests WHERE practice_code = ? ORDER BY id DESC LIMIT 1'
);
$stmt->execute([$oldFolderName]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

// Tracks the practice_code value that actually matched a row.
// On an exact full-folder match this stays the new folder name; when a
// fallback matches the bare/core code we PRESERVE that code instead of
// overwriting practice_code with the long confirmed-folder string.
$matchedPracticeCode = $oldFolderName;
$matchedViaFallback  = false;

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
        if ($row) { $matchedPracticeCode = $baseName; $matchedViaFallback = true; }
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
        if ($row) { $matchedPracticeCode = $bare; $matchedViaFallback = true; }
    }
}

// Fallback 2b: strip the trailing "(agency-agent)" suffix from the bare name.
// The DB practice_code is often just the core code (e.g. "TRA1408"),
// while the folder bare name includes the suffix "TRA1408(54traveler-Roberto)".
if (!$row) {
    $core = ($bare !== '' && $bare !== $baseName) ? $bare
          : ($baseName !== $oldFolderName ? $baseName : $oldFolderName);
    $core = preg_replace('/\(.*\)\s*$/', '', $core); // drop trailing (...) group
    $core = trim($core);
    if ($core !== '' && $core !== $oldFolderName) {
        $stmt->execute([$core]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) { $bare = $core; $matchedPracticeCode = $core; $matchedViaFallback = true; }
    }
}

// Fallback 3: search by dropbox_url.
if (!$row) {
    $searchName = ($bare !== '' && $bare !== $baseName) ? $bare
                : ($baseName !== $oldFolderName ? $baseName : $oldFolderName);
    $stmt2 = $db->prepare(
        'SELECT id, customer_name, status, dropbox_url, practice_code
         FROM requests WHERE dropbox_url LIKE ? ORDER BY id DESC LIMIT 1'
    );
    $stmt2->execute(['%/' . rawurlencode($searchName) . '%']);
    $row = $stmt2->fetch(PDO::FETCH_ASSOC);
    if ($row) { $matchedViaFallback = true; }
}

// Fallback 4: GRP parent folder — search by group_folder column.
// For group bookings the practice_code is the subfolder, but the rename
// is performed on the parent (group_folder).  When found this way we must
// update group_folder instead of practice_code.
$matchedViaGroupFolder = false;
if (!$row) {
    $stmtGrp = $db->prepare(
        'SELECT id, customer_name, status, dropbox_url, practice_code
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
// _PROGRESS / _PROVISIONAL / _CANCELLED clear payment_status (set to NULL).
$folderTagMap = [
    '_CANCELLED'    => ['status' => 'Cancelled',   'ps' => null],
    '_BALANCE-CASH' => ['status' => 'Booked',      'ps' => 'Balance-Cash'],
    '_BALANCE'      => ['status' => 'Booked',      'ps' => 'Balance'],
    '_DEPOSIT'      => ['status' => 'Booked',      'ps' => 'Deposit'],
    '_PROVISIONAL'  => ['status' => 'Provisional', 'ps' => null],
    '_PROGRESS'     => ['status' => 'Booked',      'ps' => null],
    '_PAID'         => ['status' => 'Booked',      'ps' => 'Paid'],
];
$newDbStatus      = null;
$newPaymentStatus = false; // false = leave unchanged; null = explicitly clear
foreach ($folderTagMap as $tag => $map) {
    if (str_ends_with(strtoupper($newFolderName), $tag)) {
        $newDbStatus      = $map['status'];
        $newPaymentStatus = $map['ps']; // null means clear it
        break;
    }
}

// ── Update ────────────────────────────────────────────────────────────────────
if ($matchedViaGroupFolder) {
    // GRP parent folder rename: update group_folder, leave practice_code untouched
    if ($newDbStatus !== null) {
        $update = $db->prepare(
            'UPDATE requests SET group_folder = ?, dropbox_url = ?, status = ?, payment_status = ? WHERE id = ?'
        );
        $update->execute([$newFolderName, $newDropboxUrl, $newDbStatus, $newPaymentStatus, (int)$row['id']]);
    } else {
        $update = $db->prepare(
            'UPDATE requests SET group_folder = ?, dropbox_url = ? WHERE id = ?'
        );
        $update->execute([$newFolderName, $newDropboxUrl, (int)$row['id']]);
    }
} else {
    // When matched via a fallback the stored practice_code is the bare/core
    // code (e.g. "TRA1408"); preserve it rather than overwriting with the
    // long confirmed-folder string. Only an exact full-folder match updates it.
    $pcToStore = $matchedViaFallback ? ($row['practice_code'] ?? $matchedPracticeCode)
                                     : $newFolderName;
    if ($newDbStatus !== null) {
        $update = $db->prepare(
            'UPDATE requests SET practice_code = ?, dropbox_url = ?, status = ?, payment_status = ? WHERE id = ?'
        );
        $update->execute([$pcToStore, $newDropboxUrl, $newDbStatus, $newPaymentStatus, (int)$row['id']]);
    } else {
        $update = $db->prepare(
            'UPDATE requests SET practice_code = ?, dropbox_url = ? WHERE id = ?'
        );
        $update->execute([$pcToStore, $newDropboxUrl, (int)$row['id']]);
    }
}

echo json_encode([
    'success'           => true,
    'request_id'        => (int)$row['id'],
    'customer_name'     => $row['customer_name'],
    'old_folder'        => $oldFolderName,
    'new_folder'        => $newFolderName,
    'updated_field'     => $matchedViaGroupFolder ? 'group_folder' : 'practice_code',
    'dropbox_url'       => $newDropboxUrl,
    'new_status'        => $newDbStatus,
    'new_payment_status'=> $newPaymentStatus,
]);
