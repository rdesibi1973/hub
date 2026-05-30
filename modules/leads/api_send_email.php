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

// ── Resolve Reply-To from logged-in user ─────────────────────────────────────
$replyTo  = '';
$userEmail = '';
if ($userId > 0) {
    $db = db();
    $uStmt = $db->prepare('SELECT email, full_name FROM users WHERE id = ? AND is_active = 1 LIMIT 1');
    $uStmt->execute([$userId]);
    $uRow = $uStmt->fetch(PDO::FETCH_ASSOC);
    if ($uRow && filter_var($uRow['email'], FILTER_VALIDATE_EMAIL)) {
        $replyTo   = $uRow['email'];
        $userEmail = $uRow['email'];
    }
}

// ── Signature (only for rdesibi / info@savannahexplorers.com) ────────────────
$signaturePlain = '';
$signatureHtml  = '';
if ($userEmail === 'info@savannahexplorers.com') {
    $signaturePlain = "\r\n\r\n--\r\nRoberto\r\n\r\n"
        . "Savannah Explorers Ltd\r\n"
        . "Engosheraton - P.O. Box 16726\r\n"
        . "Arusha - Tanzania\r\n"
        . "Roberto +255 784 520 453\r\n"
        . "Office +255 768 900 199\r\n"
        . "Emergency Mobile Tanzania: +255 768 900 199 and +255 747 777 315\r\n"
        . "Zanzibar transfers +255 773 053 725\r\n"
        . "Email: info@savannahexplorers.com\r\n"
        . "Website IT: savannahexplorers.com\r\n"
        . "Website EN: savannahexplorers.net";

    $signatureHtml = '<br><br><hr style="border:none;border-top:1px solid #ccc;margin:12px 0;">'
        . '<p style="font-family:Arial,sans-serif;font-size:13px;color:#333;line-height:1.6;margin:0;">'
        . 'Roberto'
        . '<br><br>'
        . '<strong style="font-size:14px;">Savannah Explorers Ltd</strong><br>'
        . 'Engosheraton - P.O. Box 16726<br>'
        . 'Arusha - Tanzania<br>'
        . 'Roberto +255 784 520 453<br>'
        . 'Office +255 768 900 199<br>'
        . 'Emergency Mobile Tanzania: +255 768 900 199 and +255 747 777 315<br>'
        . 'Zanzibar transfers +255 773 053 725<br>'
        . 'Email: <a href="mailto:info@savannahexplorers.com">info@savannahexplorers.com</a><br>'
        . 'Website IT: <a href="https://savannahexplorers.com">savannahexplorers.com</a><br>'
        . 'Website EN: <a href="https://savannahexplorers.net">savannahexplorers.net</a>'
        . '</p>';
}

// ── Send via BlueHost mail() ──────────────────────────────────────────────────
$toStr = implode(', ', array_column($toList, 'email'));

if ($signatureHtml !== '') {
    // Send multipart/alternative (plain + HTML) so email clients pick the best version
    $boundary = 'boundary_' . md5(uniqid('', true));

    $plainPart = $body . $signaturePlain;

    // Convert plain-text body to basic HTML (preserve line breaks)
    $htmlBody = '<html><body><p style="font-family:Arial,sans-serif;font-size:13px;color:#333;line-height:1.6;white-space:pre-wrap;">'
              . htmlspecialchars($body, ENT_QUOTES, 'UTF-8')
              . '</p>'
              . $signatureHtml
              . '</body></html>';

    $headers = "From: noreply@savannahexplorers.com\r\n"
             . "MIME-Version: 1.0\r\n"
             . "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";
    if ($replyTo !== '') {
        $headers .= "Reply-To: {$replyTo}\r\n";
    }
    if (!empty($ccList)) {
        $headers .= "Cc: " . implode(', ', array_column($ccList, 'email')) . "\r\n";
    }

    $messageBody = "--{$boundary}\r\n"
                 . "Content-Type: text/plain; charset=UTF-8\r\n"
                 . "Content-Transfer-Encoding: quoted-printable\r\n\r\n"
                 . quoted_printable_encode($plainPart) . "\r\n"
                 . "--{$boundary}\r\n"
                 . "Content-Type: text/html; charset=UTF-8\r\n"
                 . "Content-Transfer-Encoding: quoted-printable\r\n\r\n"
                 . quoted_printable_encode($htmlBody) . "\r\n"
                 . "--{$boundary}--";

    $ok = mail($toStr, $subject, $messageBody, $headers);

} else {
    // Standard plain-text email (no signature)
    $headers = "From: noreply@savannahexplorers.com\r\n"
             . "MIME-Version: 1.0\r\n"
             . "Content-Type: text/plain; charset=UTF-8\r\n";
    if ($replyTo !== '') {
        $headers .= "Reply-To: {$replyTo}\r\n";
    }
    if (!empty($ccList)) {
        $headers .= "Cc: " . implode(', ', array_column($ccList, 'email')) . "\r\n";
    }

    $ok = mail($toStr, $subject, $body, $headers);
}

echo json_encode($ok
    ? ['success' => true]
    : ['success' => false, 'message' => 'mail() returned false — check BlueHost mail log']
);
