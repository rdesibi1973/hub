<?php
/**
 * api_get_agent_email.php
 * Returns the email of the agent assigned to a request identified by practice_code.
 * POST: { "folder_name": "01_01JAN_..." }
 * Response: { "success": true, "email": "roberto.capri@savannahexplorers.com" }
 */
define('HS_INCLUDED', true);
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
if (!defined('API_KEY')) {
    require_once __DIR__ . '/config.php';
}

header('Content-Type: application/json');

// ── Auth ──────────────────────────────────────────────────────────────────────
$apiKey = '';
if (function_exists('getallheaders')) {
    $hdrs   = getallheaders();
    $apiKey = $hdrs['X-API-Key'] ?? $hdrs['x-api-key'] ?? '';
}
if (empty($apiKey)) $apiKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
$expectedKey = defined('API_KEY') ? API_KEY : '';
if (empty($expectedKey) || $apiKey !== $expectedKey) {
    http_response_code(401);
    echo json_encode(['success' => false, 'email' => '']);
    exit;
}

// ── Input ─────────────────────────────────────────────────────────────────────
$raw        = file_get_contents('php://input');
$data       = json_decode($raw, true) ?: [];
$folderName = trim($data['folder_name'] ?? $_GET['folder_name'] ?? '');

if (empty($folderName)) {
    echo json_encode(['success' => false, 'email' => '']);
    exit;
}

// ── Lookup ────────────────────────────────────────────────────────────────────
try {
    $email = null;

    // Primary: request → agent_id → users.agent_id (direct FK join)
    $s = $pdo->prepare(
        "SELECT u.email
         FROM requests r
         JOIN users u ON u.agent_id = r.agent_id
         WHERE r.practice_code = :folder
           AND u.email IS NOT NULL AND u.email <> ''
         ORDER BY u.id ASC LIMIT 1"
    );
    $s->execute([':folder' => $folderName]);
    $row = $s->fetch(PDO::FETCH_ASSOC);
    if ($row) $email = $row['email'];

    // Fallback: try with dropbox_url if practice_code doesn't match
    if (!$email) {
        $s = $pdo->prepare(
            "SELECT u.email
             FROM requests r
             JOIN users u ON u.agent_id = r.agent_id
             WHERE r.dropbox_url LIKE :url
               AND u.email IS NOT NULL AND u.email <> ''
             ORDER BY u.id ASC LIMIT 1"
        );
        $s->execute([':url' => '%/' . rawurlencode($folderName) . '%']);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        if ($row) $email = $row['email'];
    }

    echo json_encode($email
        ? ['success' => true,  'email' => $email]
        : ['success' => false, 'email' => '']
    );
} catch (Exception $e) {
    echo json_encode(['success' => false, 'email' => '', 'error' => $e->getMessage()]);
}
