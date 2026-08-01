<?php
/**
 * invoice_html.php — shared invoice → PDF HTML builder.
 *
 * buildInvoiceHtml() returns Dompdf-compatible HTML (inline styles, table
 * layout, base64-embedded logo — no flexbox, no CDN fonts) for a single
 * invoice. Used by the email sender (api_send_invoice_email.php) and the
 * bulk ZIP export (invoices_zip.php) so the rendered PDF is identical.
 */

if (!function_exists('buildInvoiceHtml')) {
function buildInvoiceHtml(array $inv, array $items, array $payments): string
{
    $sym     = $inv['currency'] === 'EUR' ? '€' : '$';
    $f2      = fn(float $n) => number_format($n, 2);
    $money   = fn(float $n) => $sym . $f2($n);
    $d       = fn(string $s) => date('d M Y', strtotime($s));
    $e       = fn(mixed $s)  => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

    // Logo — embed as base64 to avoid dompdf file path restrictions
    $logoFile = $inv['issuer'] === 'Savannah Holidays Ltd'
        ? __DIR__ . '/../assets/logo_sh.png'
        : __DIR__ . '/../assets/logo_se.png';
    if (file_exists($logoFile)) {
        $logoSrc = 'data:image/png;base64,' . base64_encode(file_get_contents($logoFile));
        $logoHtml = "<img src=\"{$logoSrc}\" style='height:80px;width:auto;'>";
    } else {
        $logoHtml = "<div style='font-size:18px;font-weight:700;color:#C0211B;'>" . $e($inv['issuer']) . "</div>";
    }

    $issuerAddr = $inv['issuer'] === 'Savannah Explorers Ltd'
        ? "Arusha, P.O. Box 16726<br>Tanzania"
        : "Certificate of Incorporation No. 212622<br>H21 Home Scene Building, Healthscape, Forbach - Mauritius<br>info@savannahholidays.net";

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

    // Bank details — Savannah Holidays only, per currency
    $bankHtml = '';
    if ($inv['issuer'] === 'Savannah Holidays Ltd') {
        if ($inv['currency'] === 'USD') {
            $bankIban   = 'MU55AFBL2501138053000000013USD';
            $bankAcct   = '138053000000013';
            $bankCcy    = 'USD';
        } else {
            $bankIban   = 'MU40AFBL2501138053000000024EUR';
            $bankAcct   = '138053000000024';
            $bankCcy    = 'EUR';
        }
        $bankHtml = "
        <table width='100%' style='margin-top:28px;border-collapse:collapse;'>
          <tr>
            <td style='padding:12px 16px;background:#f9f9f9;border-left:3px solid #C0211B;'>
              <div style='font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#888;margin-bottom:8px;'>Bank Details for Payment</div>
              <table style='border-collapse:collapse;font-size:12px;'>
                <tr><td style='color:#888;padding:2px 0;width:150px;'>Beneficiary Bank:</td><td style='font-weight:600;color:#1A1A1A;padding:2px 0 2px 8px;'>AfrAsia Bank Ltd</td></tr>
                <tr><td style='color:#888;padding:2px 0;'>Bank Address:</td><td style='font-weight:600;color:#1A1A1A;padding:2px 0 2px 8px;'>Bowen Square, 10, Dr Ferriere Street, Port Louis, Mauritius</td></tr>
                <tr><td style='color:#888;padding:2px 0;'>Account Name:</td><td style='font-weight:600;color:#1A1A1A;padding:2px 0 2px 8px;'>SAVANNAH HOLIDAYS LTD</td></tr>
                <tr><td style='color:#888;padding:2px 0;'>IBAN:</td><td style='font-weight:600;color:#1A1A1A;padding:2px 0 2px 8px;'>{$bankIban}</td></tr>
                <tr><td style='color:#888;padding:2px 0;'>Account Number:</td><td style='font-weight:600;color:#1A1A1A;padding:2px 0 2px 8px;'>{$bankAcct}</td></tr>
                <tr><td style='color:#888;padding:2px 0;'>Currency:</td><td style='font-weight:600;color:#1A1A1A;padding:2px 0 2px 8px;'>{$bankCcy}</td></tr>
                <tr><td style='color:#888;padding:2px 0;'>Swift Code:</td><td style='font-weight:600;color:#1A1A1A;padding:2px 0 2px 8px;'>AFBLMUMU</td></tr>
              </table>
            </td>
          </tr>
        </table>";
    }

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
        {$logoHtml}
        <div style="font-size:11px;color:#888;margin-top:6px;line-height:1.5;">{$issuerAddr}</div>
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

  {$bankHtml}

  {$footerHtml}

</body>
</html>
HTML;
}
}
