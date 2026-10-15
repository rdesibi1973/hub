<?php
/**
 * api_send_invoice_email.php
 * Sends an invoice as a PDF attachment via BlueHost mail().
 * Called via AJAX (POST) from invoice_view.php. Logic: inv_send_email() in
 * includes/invoice_service.php (shared with the Agent API send_invoice_email).
 *
 * POST fields:
 *   invoice_id  int
 *   to          string  — comma-separated To addresses
 *   cc          string  — comma-separated CC addresses (optional; the form pre-fills
 *                         accountant@ + the request's sales, see inv_mail_default_cc())
 *   subject     string
 *   body        string
 */
ob_start();
require_once 'config.php';

header('Content-Type: application/json');

// ── Auth: must be a logged-in invoice user ────────────────────────────────────
requireInvoiceAccess();

function send_out(array $res): void {
    ob_end_clean();
    echo json_encode($res);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    send_out(['ok' => false, 'error' => 'Method not allowed']);
}

$db         = db();
$invoiceId  = (int)($_POST['invoice_id'] ?? 0);
$to         = trim($_POST['to']      ?? '');
$cc         = trim($_POST['cc']      ?? '');
$subject    = trim($_POST['subject'] ?? '');
$body       = trim($_POST['body']    ?? '');

if (!$invoiceId || !$to || !$subject || !$body) {
    send_out(['ok' => false, 'error' => 'Missing required fields (invoice_id, to, subject, body)']);
}

$inv = inv_get($db, $invoiceId);
if (!$inv) send_out(['ok' => false, 'error' => 'Invoice not found']);

try {
    $sent = inv_send_email($db, $inv, $to, $cc, $subject, $body);
} catch (Throwable $e) {
    send_out(['ok' => false, 'error' => $e->getMessage()]);
}
send_out(['ok' => true, 'message' => 'Email sent to ' . implode(', ', $sent['to'])]);
