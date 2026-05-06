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

// ── Load invoice + items ──────────────────────────────────────────────────────
$stmt = $db->prepare("SELECT * FROM invoices WHERE id = ?");
$stmt->execute([$invoiceId]);
$inv = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$inv) {
    echo json_encode(['ok' => false, 'error' => 'Invoice not found']);
    exit;
}

$itemStmt = $db->prepare("SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY sort_order, id");
$itemStmt->execute([$invoiceId]);
$items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

$payStmt = $db->prepare("SELECT * FROM invoice_payments WHERE invoice_id = ? AND cancelled_at IS NULL ORDER BY payment_date");
$payStmt->execute([$invoiceId]);
$payments = $payStmt->fetchAll(PDO::FETCH_ASSOC);

// ── Generate PDF ──────────────────────────────────────────────────────────────
$vendorAutoload = __DIR__ . '/../../vendor/autoload.php';
if (!file_exists($vendorAutoload)) {
    echo json_encode(['ok' => false, 'error' => 'PDF library not installed. Run: composer install']);
    exit;
}
require_once $vendorAutoload;

use Dompdf\Dompdf;
use Dompdf\Options;

$options = new Options();
$options->set('isHtml5ParserEnabled', true);
$options->set('isRemoteEnabled', false);   // no external resources

$dompdf = new Dompdf($options);
$dompdf->loadHtml(buildInvoiceHtml($inv, $items, $payments));
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();
$pdfData = $dompdf->output();

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

// ─────────────────────────────────────────────────────────────────────────────
// Build clean invoice HTML for dompdf
// (inline styles, table layout — no CDN fonts, no flexbox)
// ─────────────────────────────────────────────────────────────────────────────
function buildInvoiceHtml(array $inv, array $items, array $payments): string
{
    $sym     = $inv['currency'] === 'EUR' ? '€' : '$';
    $f2      = fn(float $n) => number_format($n, 2);
    $money   = fn(float $n) => $sym . $f2($n);
    $d       = fn(string $s) => date('d M Y', strtotime($s));
    $e       = fn(mixed $s)  => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

    $issuerAddr = $inv['issuer'] === 'Savannah Explorers Ltd'
        ? "Arusha, P.O. Box 16726<br>Tanzania"
        : "Savannah Holidays Ltd<br>Tanzania";

    // Line items rows
    $itemRows = '';
    foreach ($items as $n => $item) {
        $qty    = rtrim(rtrim(number_format((float)$item['quantity'], 2), '0'), '.');
        $red    = (float)$item['line_total'] < 0 ? 'color:#C0211B;' : '';
        $rateRed = (float)$item['unit_price'] < 0 ? 'color:#C0211B;' : '';
        $itemRows .= "<tr>
            <td style='padding:10px 12px;border-bottom:1px solid #eee;color:#aaa;'>" . ($n + 1) . "</td>
            <td style='padding:10px 12px;border-bottom:1px solid #eee;'>" . $e($item['description']) . "</td>
            <td style='padding:10px 12px;border-bottom:1px solid #eee;text-align:right;'>{$qty}</td>
            <td style='padding:10px 12px;border-bottom:1px solid #eee;text-align:right;{$rateRed}'>" . $f2((float)$item['unit_price']) . "</td>
            <td style='padding:10px 12px;border-bottom:1px solid #eee;text-align:right;font-weight:600;{$red}'>" . $f2((float)$item['line_total']) . "</td>
        </tr>";
    }

    // Payment rows for totals
    $paymentRows = '';
    if ((float)$inv['amount_paid'] > 0) {
        $paymentRows = "<tr>
            <td style='padding:6px 12px;text-align:left;font-size:12px;color:#C0211B;'>Payment Made</td>
            <td style='padding:6px 12px;text-align:right;font-size:12px;color:#C0211B;'>(-) " . $f2((float)$inv['amount_paid']) . "</td>
        </tr>";
    }

    $balColor = (float)$inv['balance_due'] <= 0 ? '#1A6B3A' : '#1A1A1A';

    // Optional notes / T&C
    $footerHtml = '';
    if ($inv['notes'] || $inv['terms_conditions']) {
        $footerHtml = "<table width='100%' style='margin-top:28px;border-top:1px solid #eee;padding-top:16px;'><tr>";
        if ($inv['notes']) {
            $footerHtml .= "<td style='vertical-align:top;padding-right:20px;font-size:11px;'>
                <div style='font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#888;margin-bottom:5px;'>Notes</div>
                <div style='color:#555;line-height:1.6;'>" . nl2br($e($inv['notes'])) . "</div>
            </td>";
        }
        if ($inv['terms_conditions']) {
            $footerHtml .= "<td style='vertical-align:top;font-size:11px;'>
                <div style='font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#888;margin-bottom:5px;'>Terms &amp; Conditions</div>
                <div style='color:#555;line-height:1.6;'>" . nl2br($e($inv['terms_conditions'])) . "</div>
            </td>";
        }
        $footerHtml .= "</tr></table>";
    }

    $invNum     = $e($inv['invoice_number']);
    $billName   = $e($inv['bill_to_name']);
    $billAddr   = $inv['bill_to_address'] ? '<br><span style="font-size:11px;color:#666;">' . nl2br($e($inv['bill_to_address'])) . '</span>' : '';
    $issuerName = $e($inv['issuer']);
    $issueDate  = $d($inv['issue_date']);
    $dueDate    = $inv['due_date'] ? $d($inv['due_date']) : '—';
    $terms      = $inv['terms'] ? $e($inv['terms']) : '—';
    $currency   = $e($inv['currency']);
    $balDue     = $money((float)$inv['balance_due']);
    $total      = $money((float)$inv['total']);
    $subtotal   = $f2((float)$inv['subtotal']);

    return <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
  body { font-family: Helvetica, Arial, sans-serif; font-size: 13px; color: #333; margin: 0; padding: 0; }
  * { box-sizing: border-box; }
</style>
</head>
<body style="padding:40px 48px;">

  <!-- Header -->
  <table width="100%" style="margin-bottom:32px;">
    <tr>
      <td style="vertical-align:top;">
        <div style="font-size:20px;font-weight:700;color:#C0211B;">SAVANNAH EXPLORERS</div>
        <div style="font-size:12px;color:#666;margin-top:2px;">{$issuerName}</div>
        <div style="font-size:11px;color:#888;margin-top:4px;line-height:1.5;">{$issuerAddr}</div>
      </td>
      <td style="text-align:right;vertical-align:top;">
        <div style="font-size:26px;font-weight:300;color:#333;letter-spacing:1px;">INVOICE</div>
        <div style="font-size:12px;color:#555;margin-top:4px;"># {$invNum}</div>
        <div style="font-size:10px;color:#888;text-transform:uppercase;letter-spacing:.06em;margin-top:10px;">Balance Due</div>
        <div style="font-size:20px;font-weight:700;color:{$balColor};">{$balDue}</div>
      </td>
    </tr>
  </table>

  <!-- Bill To + Dates -->
  <table width="100%" style="margin-bottom:32px;">
    <tr>
      <td style="vertical-align:top;">
        <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#888;margin-bottom:4px;">Bill To</div>
        <div style="font-weight:700;font-size:14px;">{$billName}</div>
        {$billAddr}
      </td>
      <td style="text-align:right;vertical-align:top;font-size:12px;">
        <table style="margin-left:auto;">
          <tr><td style="color:#888;padding:3px 0 3px 20px;">Invoice Date:</td><td style="font-weight:600;padding:3px 0 3px 8px;">{$issueDate}</td></tr>
          <tr><td style="color:#888;padding:3px 0 3px 20px;">Due Date:</td><td style="font-weight:600;padding:3px 0 3px 8px;">{$dueDate}</td></tr>
          <tr><td style="color:#888;padding:3px 0 3px 20px;">Terms:</td><td style="font-weight:600;padding:3px 0 3px 8px;">{$terms}</td></tr>
          <tr><td style="color:#888;padding:3px 0 3px 20px;">Currency:</td><td style="font-weight:600;padding:3px 0 3px 8px;">{$currency}</td></tr>
        </table>
      </td>
    </tr>
  </table>

  <!-- Line Items -->
  <table width="100%" style="border-collapse:collapse;margin-bottom:0;">
    <thead>
      <tr style="background:#404040;color:#fff;">
        <th style="padding:9px 12px;text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.05em;width:32px;">#</th>
        <th style="padding:9px 12px;text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.05em;">Description</th>
        <th style="padding:9px 12px;text-align:right;font-size:11px;text-transform:uppercase;letter-spacing:.05em;width:60px;">Qty</th>
        <th style="padding:9px 12px;text-align:right;font-size:11px;text-transform:uppercase;letter-spacing:.05em;width:90px;">Rate</th>
        <th style="padding:9px 12px;text-align:right;font-size:11px;text-transform:uppercase;letter-spacing:.05em;width:90px;">Amount</th>
      </tr>
    </thead>
    <tbody>
      {$itemRows}
    </tbody>
  </table>

  <!-- Totals -->
  <table width="100%" style="border-collapse:collapse;margin-top:0;">
    <tr>
      <td>&nbsp;</td>
      <td style="width:260px;">
        <table width="100%" style="border-collapse:collapse;">
          <tr>
            <td style="padding:7px 12px;font-size:12px;color:#555;border-bottom:1px solid #eee;">Sub Total</td>
            <td style="padding:7px 12px;font-size:12px;text-align:right;font-weight:600;border-bottom:1px solid #eee;">{$subtotal}</td>
          </tr>
          <tr>
            <td style="padding:7px 12px;font-size:13px;font-weight:700;border-bottom:1px solid #eee;">Total</td>
            <td style="padding:7px 12px;font-size:13px;font-weight:700;text-align:right;border-bottom:1px solid #eee;">{$total}</td>
          </tr>
          {$paymentRows}
          <tr style="background:#f7f7f7;">
            <td style="padding:9px 12px;font-size:14px;font-weight:700;">Balance Due</td>
            <td style="padding:9px 12px;font-size:14px;font-weight:700;text-align:right;color:{$balColor};">{$balDue}</td>
          </tr>
        </table>
      </td>
    </tr>
  </table>

  {$footerHtml}

</body>
</html>
HTML;
}
