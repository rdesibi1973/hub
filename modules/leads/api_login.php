<?php
// api_login.php
// Called by GUI Java on startup.
// Method: POST (no API key required — user provides credentials instead)
// Body JSON: { "username": "...", "password": "..." }
// Returns JSON: { "success": true/false, "message": "...",
//                 "user_id": int, "full_name": "...",
//                 "codice_cartella": "...", "agent_id": int|null,
//                 "can_select_agent": true/false }

require_once 'config.php';
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *'); // GUI is not a browser but harmless

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
$username = trim($body['username'] ?? '');
$password = $body['password'] ?? '';

if ($username === '' || $password === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Username and password required']);
    exit;
}

$db = db();
$stmt = $db->prepare(
    'SELECT id, username, password_hash, full_name, role_id, is_active,
            codice_cartella, agent_id
     FROM users WHERE username = ?'
);
$stmt->execute([$username]);
$user = $stmt->fetch();

if (!$user) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Invalid credentials']);
    exit;
}
if (!$user['is_active']) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Account disabled']);
    exit;
}
if (!password_verify($password, $user['password_hash'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Invalid credentials']);
    exit;
}

// Update last_login
$db->prepare('UPDATE users SET last_login = NOW() WHERE id = ?')->execute([$user['id']]);

$canSelectAgent = (int)$user['role_id'] <= ROLE_CAN_SELECT_AGENT;

echo json_encode([
    'success'           => true,
    'message'           => 'OK',
    'user_id'           => (int)$user['id'],
    'full_name'         => $user['full_name'],
    'codice_cartella'   => $user['codice_cartella'] ?? '',
    'agent_id'          => $user['agent_id'] ? (int)$user['agent_id'] : null,
    'can_select_agent'  => $canSelectAgent,
]);
