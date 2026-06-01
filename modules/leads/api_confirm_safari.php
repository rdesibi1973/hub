<?php
/**
 * api_confirm_safari.php
 *
 * Called by the Java BackOffice when a safari is confirmed (Confirm Safari button).
 * Updates the matching request record:
 *   - practice_code  → new Dropbox folder name
 *   - dropbox_url    → updated Dropbox web URL
 *   - status         → 'Booked'
 *   - confirmation_date → today
 *
 * POST JSON body:
 *   old_folder_name  string  – current value of practice_code in DB
 *   new_folder_name  string  – new folder name (with dates, e.g. 05JAN_...)
 *
 * Authentication: X-API-Key header must match API_KEY constant.
 *
 * Place this file in: /modules/leads/
 */

require_once 'config.php';
require_once 'includes/folder_parser.php';
header('Content-Type: application/json');

// ── Auth ──────────────────────────────────────────────────────────────────────
if (($_SERVER['HTTP_X_API_KEY'] ?? '') !== API_KEY) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// ── Method ────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// ── Input ─────────────────────────────────────────────────────────────────────
$body          = json_decode(file_get_contents('php://input'), true) ?? [];
$oldFolderName = trim($body['old_folder_name'] ?? '');
$newFolderName = trim($body['new_folder_name'] ?? '');
$grpAction     = trim($body['grp_action']     ?? 'NONE');  // NONE | CREATE | ADD
$grpSubfolder  = trim($body['grp_subfolder']  ?? '');      // CREATE: individual subfolder name
$grpMainFolder = trim($body['grp_main_folder'] ?? '');     // ADD: main GRP folder name

if ($oldFolderName === '' || $newFolderName === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'old_folder_name and new_folder_name are required']);
    exit;
}

// ── Lookup request by current practice_code ───────────────────────────────────
$db = db();
$stmt = $db->prepare(
    'SELECT id, customer_name FROM requests WHERE practice_code = ? ORDER BY id DESC LIMIT 1'
);
$stmt->execute([$oldFolderName]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

// Fallback 1: strip known status suffix (e.g. _PROGRESS) and retry.
$statusTags = ['_PROGRESS','_PROVISIONAL','_DEPOSIT','_BALANCE-CASH','_BALANCE','_CANCELLED','_CK','_PAID'];
$baseName   = $oldFolderName;
foreach ($statusTags as $tag) {
    if (str_ends_with($baseName, $tag)) {
        $baseName = substr($baseName, 0, -strlen($tag));
        break;
    }
}
if (!$row && $baseName !== $oldFolderName) {
    $stmt->execute([$baseName]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
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

// Fallback 3: match by dropbox_url (created at request creation, always set).
if (!$row) {
    $searchName = ($bare !== '' && $bare !== $baseName) ? $bare : $baseName;
    $stmt2 = $db->prepare(
        'SELECT id, customer_name FROM requests WHERE dropbox_url LIKE ? ORDER BY id DESC LIMIT 1'
    );
    $stmt2->execute(['%/' . rawurlencode($searchName) . '%']);
    $row = $stmt2->fetch(PDO::FETCH_ASSOC);
}

if (!$row) {
    http_response_code(404);
    echo json_encode([
        'success' => false,
        'message' => 'No request found with practice_code = ' . $oldFolderName
                   . '. File rename succeeded but DB was not updated.',
    ]);
    exit;
}

$id = (int) $row['id'];

// ── Ensure columns exist (safe ALTER — ignored if already present) ────────────
try { $db->exec("ALTER TABLE requests ADD COLUMN confirmation_date DATE NULL DEFAULT NULL");
} catch (PDOException $ignored) {}
try { $db->exec("ALTER TABLE requests ADD COLUMN group_folder VARCHAR(255) NULL DEFAULT NULL");
} catch (PDOException $ignored) {}

// ── Parse start_date from new folder name ────────────────────────────────────
$parsedDates = parse_folder_dates($newFolderName);
$startDate   = $parsedDates['start_date']; // null if not parseable

// ── Update ────────────────────────────────────────────────────────────────────
if ($grpAction === 'CREATE' && $grpSubfolder !== '') {
    // CREATE GRP: practice_code = individual subfolder, group_folder = main GRP folder
    $newDropboxUrl = 'https://www.dropbox.com/home/001_Safari/'
                   . rawurlencode($newFolderName) . '/' . rawurlencode($grpSubfolder);
    $update = $db->prepare(
        "UPDATE requests
         SET practice_code     = ?,
             group_folder      = ?,
             dropbox_url       = ?,
             status            = 'Booked',
             confirmation_date = CURDATE(),
             start_date        = COALESCE(?, start_date)
         WHERE id = ?"
    );
    $update->execute([$grpSubfolder, $newFolderName, $newDropboxUrl, $startDate, $id]);
    $responseFolder = $grpSubfolder;

} elseif ($grpAction === 'ADD' && $grpMainFolder !== '') {
    // ADD GRP: practice_code = customer folder (unchanged), group_folder = main GRP folder
    $newDropboxUrl = 'https://www.dropbox.com/home/001_Safari/'
                   . rawurlencode($grpMainFolder) . '/' . rawurlencode($newFolderName);
    $update = $db->prepare(
        "UPDATE requests
         SET practice_code     = ?,
             group_folder      = ?,
             dropbox_url       = ?,
             status            = 'Booked',
             confirmation_date = CURDATE(),
             start_date        = COALESCE(?, start_date)
         WHERE id = ?"
    );
    $update->execute([$newFolderName, $grpMainFolder, $newDropboxUrl, $startDate, $id]);
    $responseFolder = $newFolderName;

} else {
    // Normal (NONE): standard confirm safari flow
    $newDropboxUrl = 'https://www.dropbox.com/home/001_Safari/' . rawurlencode($newFolderName);
    $update = $db->prepare(
        "UPDATE requests
         SET practice_code     = ?,
             dropbox_url       = ?,
             status            = 'Booked',
             confirmation_date = CURDATE(),
             start_date        = COALESCE(?, start_date)
         WHERE id = ?"
    );
    $update->execute([$newFolderName, $newDropboxUrl, $startDate, $id]);
    $responseFolder = $newFolderName;
}

echo json_encode([
    'success'       => true,
    'request_id'    => $id,
    'customer_name' => $row['customer_name'],
    'new_folder'    => $responseFolder,
    'grp_action'    => $grpAction,
    'message'       => 'Status set to Booked, confirmation_date = today, start_date = ' . ($startDate ?? 'not parsed'),
]);
