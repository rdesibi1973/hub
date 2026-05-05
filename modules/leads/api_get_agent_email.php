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

    // Primary: practice_code → agents → users (exact match)
    $s = $pdo->prepare(
        "SELECT u.email
         FROM requests r
         JOIN agents a ON a.id = r.agent_id
         JOIN users u  ON REPLACE(LOWER(u.display_name), ' ', '') = REPLACE(LOWER(a.name), ' ', '')
         WHERE r.practice_code = :folder
           AND u.email IS NOT NULL AND u.email <> ''
         LIMIT 1"
    );
    $s->execute([':folder' => $folderName]);
    $row = $s->fetch(PDO::FETCH_ASSOC);
    if ($row) $email = $row['email'];

    // Fallback: extract agent name from folder name and match users directly
    // Folder format: MM_DDMON_Customer(AgentName-...  or  (Agency-AgentName-...
    if (!$email) {
        // Extract token inside first parenthesis pair
        if (preg_match('/\(([^)]+)\)/', $folderName, $m)) {
            $parts = explode('-', $m[1]);
            // For agency bookings: (Agency-AgentName-...), agent is parts[1]
            // For direct: (AgentName-Drct), agent is parts[0]
            $candidates = array_unique([$parts[0] ?? '', $parts[1] ?? '']);
            foreach ($candidates as $candidate) {
                $candidate = trim($candidate);
                if (empty($candidate) || strlen($candidate) < 2) continue;
                $nl = strtolower($candidate);
                $s2 = $pdo->prepare(
                    "SELECT email FROM users
                     WHERE (REPLACE(LOWER(display_name), ' ', '') = :nl OR username = :nl)
                       AND email IS NOT NULL AND email <> ''
                     ORDER BY id ASC LIMIT 1"
                );
                $s2->execute([':nl' => $nl]);
                $row2 = $s2->fetch(PDO::FETCH_ASSOC);
                if ($row2) { $email = $row2['email']; break; }
            }
        }
    }

    echo json_encode($email
        ? ['success' => true,  'email' => $email]
        : ['success' => false, 'email' => '']
    );
} catch (Exception $e) {
    echo json_encode(['success' => false, 'email' => '', 'error' => $e->getMessage()]);
}
