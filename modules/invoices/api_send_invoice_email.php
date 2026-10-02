<?php
/**
 * api_send_invoice_email.php
 * Sends an invoice as a PDF attachment via BlueHost mail().
 * Called via AJAX (POST) from invoice_view.php.
 *
 * POST fields:
 *   invoice_id  int
 *   to          string  — comma-separated To addresses
 *   cc          string  — comma-separated CC addresses (optional)
 *   subject     string
 *   body        string
 */
require_once 'config.php';

header('Content-Type: application/json');

// ── Auth: must be a logged-in invoice user ────────────────────────────────────
requireInvoiceAccess();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$db         = db();
$invoiceId  = (int)($_POST['invoice_id'] ?? 0);
$to         = trim($_POST['to']      ?? '');
$cc         = trim($_POST['cc']      ?? '');
$subject    = trim($_POST['subject'] ?? '');
$body       = trim($_POST['body']    ?? '');

if (!$invoiceId || !$to || !$subject || !$body) {
    echo json_encode(['ok' => false, 'error' => 'Missing required fields (invoice_id, to, subject, body)']);
    exit;
}

// ── Validate To addresses ─────────────────────────────────────────────────────
function parseAddrs(string $str): array {
    $out = [];
    foreach (preg_split('/[,;]+/', $str) as $a) {
        $a = trim($a);
        if ($a !== '' && filter_var($a, FILTER_VALIDATE_EMAIL)) {
            $out[] = $a;
        }
    }
    return $out;
}

$toList = parseAddrs($to);
$ccList = parseAddrs($cc);

if (empty($toList)) {
    echo json_encode(['ok' => false, 'error' => 'No valid To address found']);
    exit;
}

// ── Load invoice + render PDF (includes/invoice_service.php) ─────────────────
$inv = inv_get($db, $invoiceId);
if (!$inv) {
    echo json_encode(['ok' => false, 'error' => 'Invoice not found']);
    exit;
}
try {
    $pdfData = inv_pdf($inv, inv_items($db, $invoiceId), inv_payments($db, $invoiceId, true));
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    exit;
}

// ── Compose multipart email ───────────────────────────────────────────────────
$filename    = 'Invoice_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $inv['invoice_number']) . '.pdf';
$boundary    = '----_Part_' . md5(uniqid('', true));
$fromHeader  = 'noreply@savannahexplorers.com';

$headers  = "From: Savannah Explorers <{$fromHeader}>\r\n";
$headers .= "Reply-To: accountant@savannahexplorers.com\r\n";
$headers .= "MIME-Version: 1.0\r\n";
$headers .= "Content-Type: multipart/mixed; boundary=\"{$boundary}\"\r\n";
if (!empty($ccList)) {
    $headers .= "Cc: " . implode(', ', $ccList) . "\r\n";
}

$msgBody  = "--{$boundary}\r\n";
$msgBody .= "Content-Type: text/plain; charset=UTF-8\r\n";
$msgBody .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
$msgBody .= $body . "\r\n\r\n";

$msgBody .= "--{$boundary}\r\n";
$msgBody .= "Content-Type: application/pdf; name=\"{$filename}\"\r\n";
$msgBody .= "Content-Disposition: attachment; filename=\"{$filename}\"\r\n";
$msgBody .= "Content-Transfer-Encoding: base64\r\n\r\n";
$msgBody .= chunk_split(base64_encode($pdfData)) . "\r\n";

$msgBody .= "--{$boundary}--";

$toStr = implode(', ', $toList);
$ok    = mail($toStr, $subject, $msgBody, $headers);

echo json_encode($ok
    ? ['ok' => true, 'message' => 'Email sent to ' . $toStr]
    : ['ok' => false, 'error'  => 'mail() returned false — check BlueHost mail log']
);
exit;
