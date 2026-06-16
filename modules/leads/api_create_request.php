<?php
// api_create_request.php
require_once 'config.php';
require_once 'dropbox_helper.php';
require_once 'notifications.php';
header('Content-Type: application/json');

if (($_SERVER['HTTP_X_API_KEY'] ?? '') !== API_KEY) {
    http_response_code(401); echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); echo json_encode(['success'=>false,'message'=>'Method not allowed']); exit;
}

$body           = json_decode(file_get_contents('php://input'), true) ?? [];
$userId         = (int)($body['user_id']        ?? 0);
$agentId        = (int)($body['agent_id']       ?? 0);
$customerName   = trim($body['customer_name']   ?? '');
$channel        = strtolower(trim($body['channel'] ?? ''));
$agencyId       = (int)($body['agency_id']      ?? 0);
$source         = trim($body['source']          ?? 'Email');
$destination    = trim($body['destination']     ?? '');
$initialRequest = trim($body['initial_request'] ?? '');
$pax            = isset($body['pax']) ? (int)$body['pax'] : null;
$email          = trim($body['email'] ?? $body['mail'] ?? ''); // Java sends "mail", web sends "email"
$notifyAgent    = (bool)($body['notify_agent']  ?? false); // ← checkbox from Java

if (!$userId || !$agentId || $customerName === '' || $channel === '') {
    http_response_code(400); echo json_encode(['success'=>false,'message'=>'Missing required fields']); exit;
}
if ($channel === 'agency' && !$agencyId) {
    http_response_code(400); echo json_encode(['success'=>false,'message'=>'agency_id required for channel=agency']); exit;
}

$db = db();

// Verify user
$userStmt = $db->prepare('SELECT role_id, agent_id FROM users WHERE id = ? AND is_active = 1');
$userStmt->execute([$userId]);
$user = $userStmt->fetch();
if (!$user) {
    http_response_code(401); echo json_encode(['success'=>false,'message'=>'User not found']); exit;
}
$canSelectAgent = (int)$user['role_id'] <= ROLE_CAN_SELECT_AGENT;
if (!$canSelectAgent && $agentId !== (int)$user['agent_id']) {
    http_response_code(403); echo json_encode(['success'=>false,'message'=>'Not allowed to assign to other agents']); exit;
}

// Get selected agent name — this goes into the folder name
$agStmt = $db->prepare('SELECT name FROM agents WHERE id = ?');
$agStmt->execute([$agentId]);
$agentRow = $agStmt->fetch();
if (!$agentRow) {
    http_response_code(400); echo json_encode(['success'=>false,'message'=>'Agent not found']); exit;
}
$agentName = str_replace(' ', '', $agentRow['name']); // e.g. "RobertoCapri", "Anderson"

// Get agency short name if needed
$agencyNome = '';
if ($channel === 'agency' && $agencyId) {
    // Prefer the pre-computed value sent by Java (already includes -PS / -LAM).
    // Fall back to DB lookup with dynamic suffix composition from type.
    if (!empty($body['agency_short_name'])) {
        $agencyNome = preg_replace('/[^\w\-]/', '', $body['agency_short_name']);
    } else {
        $agencyStmt = $db->prepare('SELECT nome, short_name, type FROM agencies WHERE id = ?');
        $agencyStmt->execute([$agencyId]);
        $agency = $agencyStmt->fetch();
        if (!$agency) {
            http_response_code(400); echo json_encode(['success'=>false,'message'=>'Agency not found']); exit;
        }
        $sn = preg_replace('/[^\w\-]/', '', $agency['short_name'] ?: $agency['nome']);
        // Strip any stale suffix then recompose from type (DB stores without suffix)
        $sn = preg_replace('/-PS$|-LAM$/', '', $sn);
        if ($agency['type'] === 'promoservice') $sn .= '-PS';
        elseif ($agency['type'] === 'lamprati') $sn .= '-LAM';
        $agencyNome = $sn;
    }
}

// Build folder name
function toCamelCase(string $name): string {
    $name = trim($name);
    // Already compact CamelCase (no spaces) — keep as-is
    if (strpos($name, ' ') === false && strpos($name, '-') === false) return $name;
    return implode('', array_map('ucfirst', array_map('mb_strtolower', preg_split('/[\s\-]+/', $name))));
}
$namePart = toCamelCase($customerName);
switch ($channel) {
    case 'agency': $suffix = "({$agencyNome}-{$agentName})"; break;
    case 'direct': $suffix = "({$agentName}-Drct)";          break;
    case 'sb':     $suffix = "({$agentName}-SB)";            break;
    default:       $suffix = "({$agentName})";               break;
}
$folderName  = $namePart . $suffix;
$dropboxPath = DROPBOX_BASE_PATH . '/' . $folderName;

$dropboxWebUrl = 'https://www.dropbox.com/home' . $dropboxPath;

// ── Idempotency check: if a record with this practice_code already exists,
//    return it instead of creating a duplicate (handles client retries). ──────
$existingStmt = $db->prepare('SELECT id FROM requests WHERE practice_code = ? LIMIT 1');
$existingStmt->execute([$folderName]);
$existingRow = $existingStmt->fetch();
if ($existingRow) {
    echo json_encode([
        'success'     => true,
        'request_id'  => (int)$existingRow['id'],
        'folder_name' => $folderName,
        'dropbox_path'=> $dropboxPath,
        'message'     => 'Request already exists — returning existing record.',
        'notify_sent' => false,
        'notify_error'=> null,
    ]);
    exit;
}

// ── Dropbox: main folder + subfolders + CustomerInfo.txt ─────────────────────
// Create Dropbox FIRST — if this fails, no DB record is created (no orphan).
$dropboxErrors = [];
try {
    $token = dropbox_get_access_token();

    // Main folder
    dropbox_create_folder($token, $dropboxPath);

    // Subfolders — one by one (reliable)
    foreach (['bookings','complain','flights','guestcomments','insurance',
              'IntFlights','invoices','mails','old','passports','vouchers'] as $sub) {
        try {
            dropbox_create_folder($token, $dropboxPath . '/' . $sub);
        } catch (RuntimeException $e) {
            $dropboxErrors[] = $sub . ': ' . $e->getMessage();
        }
    }

    // CustomerInfo.txt — matches template exactly
    $txtContent =
        "REQUEST DETAILS:\r\n\r\n"
      . $initialRequest . "\r\n\r\n\r\n"
      . "WHATSAPP link\r\n"
      . "Add phone number with international code without + or spaces and use the following link to chat with customer on whatsapp web\r\n"
      . "https://web.whatsapp.com/send?phone=\r\n\r\n"
      . "CUSTOMERS FULL NAMES:\r\n\r\n\r\n\r\n"
      . "ARRIVAL/DEPARTURE DETAILS - FLIGHTS:\r\n\r\n\r\n\r\n\r\n\r\n"
      . "DIETARY RESTRICTIONS:\r\n\r\n\r\n\r\n"
      . "NOTES:\r\n\r\n";
    dropbox_upload_text($token, $dropboxPath . '/CustomerInfo.txt', $txtContent);

} catch (RuntimeException $e) {
    // Dropbox failed — no DB record created, client can safely retry
    echo json_encode([
        'success'      => false,
        'folder_name'  => $folderName,
        'dropbox_path' => $dropboxPath,
        'message'      => 'Dropbox folder creation failed: ' . $e->getMessage(),
        'notify_sent'  => false,
        'notify_error' => null,
    ]);
    exit;
}

// ── Insert DB record (only after Dropbox succeeded) ───────────────────────────
try {
    $db->prepare(
        'INSERT INTO requests (date_received, customer_name, email, source, agent_id, destination, initial_request, status, pax, practice_code, dropbox_url, created_at)
         VALUES (CURDATE(), ?, ?, ?, ?, ?, ?, "Inquiry", ?, ?, ?, NOW())'
    )->execute([$customerName, $email ?: null, $source, $agentId, $destination ?: null, $initialRequest ?: null, $pax, $folderName, $dropboxWebUrl]);
    $requestId = (int)$db->lastInsertId();
} catch (\Throwable $e) {
    // The Dropbox folder was already created above, but the DB INSERT failed.
    // This is exactly the "orphan folder" scenario (folder on Dropbox, no
    // request in the DB). Surface it explicitly with HTTP 500 so the client
    // shows a clear error instead of failing silently.
    http_response_code(500);
    echo json_encode([
        'success'      => false,
        'folder_name'  => $folderName,
        'dropbox_path' => $dropboxPath,
        'message'      => 'ATTENTION: the Dropbox folder was created but the request '
                        . 'could NOT be saved in the database. The request is INCOMPLETE '
                        . '(orphan folder). Please retry or contact the administrator. '
                        . 'Database error: ' . $e->getMessage(),
    ]);
    exit;
}

// ── Send agent notification (non-fatal) ───────────────────────────────────────
// A notification failure must never break an otherwise-successful request.
$notif = ['sent' => false, 'error' => null];
try {
    $notif = notify_agent_new_request(
        $db, $agentId, $userId, $requestId, $customerName, $folderName, $notifyAgent
    );
} catch (\Throwable $e) {
    $notif['error'] = 'Notification failed: ' . $e->getMessage();
}

echo json_encode([
    'success'          => true,
    'request_id'       => $requestId,
    'folder_name'      => $folderName,
    'dropbox_path'     => $dropboxPath,
    'message'          => 'Request created successfully',
    'subfolder_errors' => $dropboxErrors,
    'notify_sent'      => $notif['sent'],
    'notify_error'     => $notif['error'],
]);
