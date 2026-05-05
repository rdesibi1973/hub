<?php
/**
 * api_send_email.php
 * Called by BackOfficeMain.java to send transactional emails (booking notifications etc.)
 * via BlueHost mail(). Expects JSON POST:
 * { "to": "...", "cc": "...", "subject": "...", "body": "...", "agent_name": "..." }
 * "to" and "cc" are comma-separated lists of addresses.
 * "agent_name" is optional — if provided, the agent's real email is looked up in the DB
 * and substituted/prepended in the CC list.
 */

define('HS_INCLUDED', true);   // prevent hubspot_sync.php CLI block if required elsewhere
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
// Also load module config so API_KEY constant is available
if (!defined('API_KEY')) {
    require_once __DIR__ . '/config.php';
}

header('Content-Type: application/json');

// ── Auth — API key (same pattern as api_confirm_safari.php etc.) ─────────────
$apiKey = '';
if (function_exists('getallheaders')) {
    $hdrs = getallheaders();
    $apiKey = $hdrs['X-API-Key'] ?? $hdrs['x-api-key'] ?? '';
}
if (empty($apiKey)) $apiKey = $_SERVER['HTTP_X_API_KEY'] ?? '';

$expectedKey = defined('API_KEY') ? API_KEY : '';
if (empty($expectedKey) || $apiKey !== $expectedKey) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

// ── Parse body ────────────────────────────────────────────────────────────────
$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!$data) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON']);
    exit;
}

$to         = trim($data['to']         ?? '');
$cc         = trim($data['cc']         ?? '');
$subject    = trim($data['subject']    ?? '');
$body       = trim($data['body']       ?? '');
$agentName  = trim($data['agent_name'] ?? '');
$folderName = trim($data['folder_name'] ?? '');
$userId     = intval($data['user_id']  ?? 0);

if (empty($to) || empty($subject) || empty($body)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing required fields (to, subject, body)']);
    exit;
}

// Agent email is resolved by Java before opening the dialog (via api_get_agent_email.php)
// and is already included in the CC list passed here. No server-side lookup needed.

// ── Build recipient list ──────────────────────────────────────────────────────
function parseAddresses(string $str): array {
    $result = [];
    foreach (preg_split('/[,;]+/', $str) as $addr) {
        $addr = trim($addr);
        if ($addr !== '' && filter_var($addr, FILTER_VALIDATE_EMAIL)) {
            $result[] = ['email' => $addr];
        }
    }
    return $result;
}

$toList = parseAddresses($to);
$ccList = parseAddresses($cc);

if (empty($toList)) {
    echo json_encode(['success' => false, 'message' => 'No valid To addresses found']);
    exit;
}

// ── Send via BlueHost mail() ──────────────────────────────────────────────────
$toStr   = implode(', ', array_column($toList, 'email'));
$headers = "From: noreply@savannahexplorers.com\r\n"
         . "MIME-Version: 1.0\r\n"
         . "Content-Type: text/plain; charset=UTF-8\r\n";
if (!empty($ccList)) {
    $headers .= "Cc: " . implode(', ', array_column($ccList, 'email')) . "\r\n";
}

$ok = mail($toStr, $subject, $body, $headers);

echo json_encode($ok
    ? ['success' => true]
    : ['success' => false, 'message' => 'mail() returned false — check BlueHost mail log']
);
