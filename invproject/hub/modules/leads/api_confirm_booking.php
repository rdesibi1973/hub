<?php
/**
 * api_confirm_booking.php
 *
 * Called by the Java Back Office when "Confirm Safari" succeeds.
 * Finds the request by practice_code (= old Dropbox folder name),
 * renames it to the confirmed folder name, sets status = 'Booked',
 * and saves today's date as booking_date.
 *
 * POST JSON body:
 *   { "old_folder": "CustomerName(AgentCode-Drct)",
 *     "new_folder": "05_10MAY_CustomerName(...)_START10MAY_END20MAY2026_PROGRESS" }
 *
 * Response JSON:
 *   { "success": true,  "request_id": 42, "new_folder": "...", "message": "..." }
 *   { "success": false, "message": "..." }
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
$raw   = file_get_contents('php://input');
$input = json_decode($raw, true);

$oldFolder = trim($input['old_folder'] ?? '');
$newFolder = trim($input['new_folder'] ?? '');

if ($oldFolder === '' || $newFolder === '') {
    echo json_encode(['success' => false, 'message' => 'Missing old_folder or new_folder']);
    exit;
}

$db = db();

// ── Ensure booking_date column exists (safe to run repeatedly) ────────────────
try {
    $db->exec("ALTER TABLE requests ADD COLUMN booking_date DATE NULL DEFAULT NULL");
} catch (PDOException $e) {
    // Column already exists — ignore
}

// ── Lookup — 3-tier case-insensitive strategy ─────────────────────────────────
// Tier 1: exact case-insensitive match (handles "RobertoTest" vs "RobertoTEST")
$stmt = $db->prepare(
    "SELECT id, customer_name, status FROM requests
     WHERE LOWER(practice_code) = LOWER(?) LIMIT 1"
);
$stmt->execute([$oldFolder]);
$req = $stmt->fetch(PDO::FETCH_ASSOC);

// Tier 2: old_folder is a substring of practice_code
if (!$req) {
    $stmt2 = $db->prepare(
        "SELECT id, customer_name, status FROM requests
         WHERE LOWER(practice_code) LIKE LOWER(?) LIMIT 1"
    );
    $stmt2->execute(['%' . $oldFolder . '%']);
    $req = $stmt2->fetch(PDO::FETCH_ASSOC);
}

// Tier 3: practice_code is contained inside new_folder (most robust — handles
// case drift when the user typed the name manually in Customer Request).
// e.g. DB has "RobertoTest(EkoSpostamenti-Roberto)", new_folder has
// "08_01AUG_RobertoTEST(EkoSpostamenti-Roberto)_START..." → match found.
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
        'message' => "No request found with practice_code matching: $oldFolder"
    ]);
    exit;
}

// ── Update: new folder name, status = Booked, booking_date = today ────────────
$db->prepare("
    UPDATE requests
    SET practice_code  = ?,
        status         = 'Booked',
        booking_date   = CURDATE()
    WHERE id = ?
")->execute([$newFolder, $req['id']]);

echo json_encode([
    'success'    => true,
    'message'    => 'Request updated — status set to Booked',
    'request_id' => (int)$req['id'],
    'customer'   => $req['customer_name'],
    'new_folder' => $newFolder,
    'old_status' => $req['status'],
]);
