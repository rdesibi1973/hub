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
require_once 'includes/booking_service.php';
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

$toList = bs_parse_emails($to);
$ccList = bs_parse_emails($cc);
if (empty($toList)) {
    echo json_encode(['success' => false, 'message' => 'No valid To addresses.']);
    exit;
}

// Reply-To + signature from the logged-in user (includes/booking_service.php).
$userId = function_exists('current_user') ? (int)(current_user()['id'] ?? 0) : 0;
$res    = bs_send_mail($toList, $ccList, $subject, $body, $userId);

echo json_encode($res['success']
    ? ['success' => true]
    : ['success' => false, 'message' => $res['message']]
);
