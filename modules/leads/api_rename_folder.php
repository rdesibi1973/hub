<?php
/**
 * api_rename_folder.php
 *
 * Called by the Java BackOffice after a status rename (e.g. PROGRESS → DEPOSIT).
 * Updates practice_code and dropbox_url in the requests table.
 * Does NOT touch status or confirmation_date — those are managed separately.
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
    'SELECT id, customer_name, status, dropbox_url
     FROM requests WHERE practice_code = ? ORDER BY id DESC LIMIT 1'
);
$stmt->execute([$oldFolderName]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

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
        'SELECT id, customer_name, status, dropbox_url
         FROM requests WHERE dropbox_url LIKE ? ORDER BY id DESC LIMIT 1'
    );
    $stmt2->execute(['%/' . rawurlencode($searchName) . '%']);
    $row = $stmt2->fetch(PDO::FETCH_ASSOC);
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

// ── Update ────────────────────────────────────────────────────────────────────
$update = $db->prepare(
    'UPDATE requests SET practice_code = ?, dropbox_url = ? WHERE id = ?'
);
$update->execute([$newFolderName, $newDropboxUrl, (int)$row['id']]);

echo json_encode([
    'success'       => true,
    'request_id'    => (int)$row['id'],
    'customer_name' => $row['customer_name'],
    'old_folder'    => $oldFolderName,
    'new_folder'    => $newFolderName,
    'dropbox_url'   => $newDropboxUrl,
]);
