<?php
/**
 * api_update_status.php
 *
 * Called by the Java Back Office whenever a folder is renamed on disk.
 * Always updates practice_code to the new folder name.
 * Optionally updates status if a valid status value is provided.
 *
 * POST JSON body:
 *   { "old_folder": "05_10MAY_CustomerName(...)_PROGRESS",
 *     "new_folder": "05_10MAY_CustomerName(...)_CANCELLED",
 *     "status":     "Cancelled" }    ← optional; omit to keep current status
 *
 * Response JSON:
 *   { "success": true,  "request_id": 42, "message": "..." }
 *   { "success": false, "message": "..." }
 *
 * Valid status values (must match STATUSES in config.php):
 *   Inquiry | Quoted | Hot | Booked | Cancelled | Lost
 */

require_once 'config.php';

header('Content-Type: application/json');

// ── Auth — same mechanism as all other Java-facing API endpoints ──────────────
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

// ── Parse input ───────────────────────────────────────────────────────────────
$input     = json_decode(file_get_contents('php://input'), true);
$oldFolder = trim($input['old_folder'] ?? '');
$newFolder = trim($input['new_folder'] ?? '');
$status    = trim($input['status']     ?? '');   // optional

if ($oldFolder === '' || $newFolder === '') {
    echo json_encode(['success' => false, 'message' => 'Missing old_folder or new_folder']);
    exit;
}

// Validate status only when provided
$updateStatus   = false;
$allowedStatuses = array_keys(STATUSES);   // ['Inquiry','Quoted','Booked','Cancelled','Lost']
if ($status !== '') {
    if (!in_array($status, $allowedStatuses, true)) {
        echo json_encode([
            'success' => false,
            'message' => "Invalid status \"$status\". Allowed: " . implode(', ', $allowedStatuses)
        ]);
        exit;
    }
    $updateStatus = true;
}

$db = db();

// ── Lookup — 3-tier case-insensitive strategy ─────────────────────────────────
$stmt = $db->prepare(
    "SELECT id, customer_name, status FROM requests
     WHERE LOWER(practice_code) = LOWER(?) LIMIT 1"
);
$stmt->execute([$oldFolder]);
$req = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$req) {
    $stmt2 = $db->prepare(
        "SELECT id, customer_name, status FROM requests
         WHERE LOWER(practice_code) LIKE LOWER(?) LIMIT 1"
    );
    $stmt2->execute(['%' . $oldFolder . '%']);
    $req = $stmt2->fetch(PDO::FETCH_ASSOC);
}

if (!$req) {
    $stmt3 = $db->prepare(
        "SELECT id, customer_name, status FROM requests
         WHERE practice_code IS NOT NULL AND practice_code != ''
           AND LOWER(?) LIKE CONCAT('%', LOWER(practice_code), '%')
         ORDER BY id DESC LIMIT 1"
    );
    $stmt3->execute([$newFolder]);
    $req = $stmt3->fetch(PDO::FETCH_ASSOC);
}

if (!$req) {
    echo json_encode([
        'success' => false,
        'message' => "No request found with practice_code: $oldFolder"
    ]);
    exit;
}

// ── Update: always practice_code, optionally status ──────────────────────────
if ($updateStatus) {
    $db->prepare("
        UPDATE requests SET practice_code = ?, status = ? WHERE id = ?
    ")->execute([$newFolder, $status, $req['id']]);
    $msg = "Folder updated, status set to $status";
} else {
    $db->prepare("
        UPDATE requests SET practice_code = ? WHERE id = ?
    ")->execute([$newFolder, $req['id']]);
    $msg = "Folder updated";
}

echo json_encode([
    'success'    => true,
    'message'    => $msg,
    'request_id' => (int)$req['id'],
    'customer'   => $req['customer_name'],
    'old_status' => $req['status'],
    'new_status' => $updateStatus ? $status : $req['status'],
]);


// ── Lookup — 3-tier case-insensitive strategy ─────────────────────────────────
$stmt = $db->prepare(
    "SELECT id, customer_name, status FROM requests
     WHERE LOWER(practice_code) = LOWER(?) LIMIT 1"
);
$stmt->execute([$oldFolder]);
$req = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$req) {
    $stmt2 = $db->prepare(
        "SELECT id, customer_name, status FROM requests
         WHERE LOWER(practice_code) LIKE LOWER(?) LIMIT 1"
    );
    $stmt2->execute(['%' . $oldFolder . '%']);
    $req = $stmt2->fetch(PDO::FETCH_ASSOC);
}

if (!$req) {
    $stmt3 = $db->prepare(
        "SELECT id, customer_name, status FROM requests
         WHERE practice_code IS NOT NULL AND practice_code != ''
           AND LOWER(?) LIKE CONCAT('%', LOWER(practice_code), '%')
         ORDER BY id DESC LIMIT 1"
    );
    $stmt3->execute([$newFolder]);
    $req = $stmt3->fetch(PDO::FETCH_ASSOC);
}

if (!$req) {
    echo json_encode([
        'success' => false,
        'message' => "No request found with practice_code: $oldFolder"
    ]);
    exit;
}

// ── Update: always practice_code, optionally status ──────────────────────────
if ($updateStatus) {
    $db->prepare("
        UPDATE requests SET practice_code = ?, status = ? WHERE id = ?
    ")->execute([$newFolder, $status, $req['id']]);
    $msg = "Folder updated, status set to $status";
} else {
    $db->prepare("
        UPDATE requests SET practice_code = ? WHERE id = ?
    ")->execute([$newFolder, $req['id']]);
    $msg = "Folder updated";
}

echo json_encode([
    'success'    => true,
    'message'    => $msg,
    'request_id' => (int)$req['id'],
    'customer'   => $req['customer_name'],
    'old_status' => $req['status'],
    'new_status' => $updateStatus ? $status : $req['status'],
]);
