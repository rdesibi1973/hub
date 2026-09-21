<?php
/**
 * ajax_booking_email.php
 *
 * Session-authenticated send for the post-confirmation booking email shown by
 * backoffice.php. Mirrors api_send_email.php's mail() logic (multipart + the
 * user's HTML signature) but trusts the Hub session instead of the X-API-Key,
 * so no server secret is exposed to the browser.
 *
 * POST (form-urlencoded): to, cc, subject, body, request_id
 */
require_once 'config.php';
if (!function_exists('get_user_signature_html')) {
    $sig = __DIR__ . '/../../includes/signature_helper.php';
    if (is_file($sig)) require_once $sig;
}
header('Content-Type: application/json');
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$to      = trim($_POST['to']      ?? '');
$cc      = trim($_POST['cc']      ?? '');
$subject = trim($_POST['subject'] ?? '');
$body    = trim($_POST['body']    ?? '');

if ($to === '' || $subject === '' || $body === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing required fields (to, subject, body).']);
    exit;
}

function bmail_parse(string $str): array {
    $out = [];
    foreach (preg_split('/[,;]+/', $str) as $addr) {
        $addr = trim($addr);
        if ($addr !== '' && filter_var($addr, FILTER_VALIDATE_EMAIL)) $out[] = $addr;
    }
    return array_values(array_unique($out));
}

$toList = bmail_parse($to);
$ccList = bmail_parse($cc);
if (empty($toList)) {
    echo json_encode(['success' => false, 'message' => 'No valid To addresses.']);
    exit;
}

// Reply-To + signature from the logged-in user.
$userId  = function_exists('current_user') ? (int)(current_user()['id'] ?? 0) : 0;
$replyTo = '';
$signatureHtml = $signaturePlain = '';
if ($userId > 0) {
    try {
        $u = db()->prepare('SELECT email FROM users WHERE id = ? AND is_active = 1 LIMIT 1');
        $u->execute([$userId]);
        $em = (string)($u->fetchColumn() ?: '');
        if (filter_var($em, FILTER_VALIDATE_EMAIL)) $replyTo = $em;
    } catch (Throwable $e) { /* non-blocking */ }

    if (function_exists('get_user_signature_html')) {
        $sig = get_user_signature_html($userId);
        if ($sig !== '') {
            $signatureHtml  = '<br><br><hr style="border:none;border-top:1px solid #ccc;margin:12px 0;">' . $sig;
            $signaturePlain = "\r\n\r\n--\r\n" . (function_exists('signature_html_to_plain') ? signature_html_to_plain($sig) : strip_tags($sig));
        }
    }
}

$toStr = implode(', ', $toList);

if ($signatureHtml !== '') {
    $boundary  = 'boundary_' . md5(uniqid('', true));
    $plainPart = $body . $signaturePlain;
    $htmlBody  = '<html><body><p style="font-family:Arial,sans-serif;font-size:13px;color:#333;line-height:1.6;white-space:pre-wrap;">'
               . htmlspecialchars($body, ENT_QUOTES, 'UTF-8') . '</p>' . $signatureHtml . '</body></html>';

    $headers = "From: noreply@savannahexplorers.com\r\n"
             . "MIME-Version: 1.0\r\n"
             . "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";
    if ($replyTo !== '')  $headers .= "Reply-To: {$replyTo}\r\n";
    if (!empty($ccList))  $headers .= "Cc: " . implode(', ', $ccList) . "\r\n";

    $message = "--{$boundary}\r\n"
             . "Content-Type: text/plain; charset=UTF-8\r\n"
             . "Content-Transfer-Encoding: quoted-printable\r\n\r\n"
             . quoted_printable_encode($plainPart) . "\r\n"
             . "--{$boundary}\r\n"
             . "Content-Type: text/html; charset=UTF-8\r\n"
             . "Content-Transfer-Encoding: quoted-printable\r\n\r\n"
             . quoted_printable_encode($htmlBody) . "\r\n"
             . "--{$boundary}--";

    $ok = mail($toStr, $subject, $message, $headers);
} else {
    $headers = "From: noreply@savannahexplorers.com\r\n"
             . "MIME-Version: 1.0\r\n"
             . "Content-Type: text/plain; charset=UTF-8\r\n";
    if ($replyTo !== '')  $headers .= "Reply-To: {$replyTo}\r\n";
    if (!empty($ccList))  $headers .= "Cc: " . implode(', ', $ccList) . "\r\n";

    $ok = mail($toStr, $subject, $body, $headers);
}

echo json_encode($ok
    ? ['success' => true]
    : ['success' => false, 'message' => 'mail() returned false — check the BlueHost mail log.']
);
