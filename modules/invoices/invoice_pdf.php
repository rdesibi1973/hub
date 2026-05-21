<?php
require_once 'config.php';
$db = db();
$id = (int)($_GET['id'] ?? 0);

$s = $db->prepare("SELECT * FROM invoices WHERE id=?"); $s->execute([$id]);
$inv = $s->fetch();
if (!$inv) { die('Invoice not found.'); }

$items = $db->prepare("SELECT * FROM invoice_items WHERE invoice_id=? ORDER BY sort_order,id");
$items->execute([$id]); $items = $items->fetchAll();

// Active payments only
$pStmt = $db->prepare("SELECT * FROM invoice_payments WHERE invoice_id=? AND cancelled_at IS NULL ORDER BY payment_date");
$pStmt->execute([$id]); $payments = $pStmt->fetchAll();

$sym = $inv['currency'] === 'EUR' ? '€' : '$';

function p(float $n, string $sym): string { return $sym . number_format($n, 2); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Invoice <?= htmlspecialchars($inv['invoice_number']) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@300;400;600;700&display=swap" rel="stylesheet">
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }

body {
  font-family: 'Open Sans', Arial, sans-serif;
  font-size: 13px;
  color: #333;
  background: #f4f4f4;
}

/* Screen wrapper */
.page-wrap {
  max-width: 800px;
  margin: 30px auto;
  background: #fff;
  box-shadow: 0 2px 20px rgba(0,0,0,.12);
  border-radius: 4px;
}

.invoice-body {
  padding: 48px 52px;
}

/* Print button */
.print-bar {
  background: #C0211B;
  padding: 12px 52px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  border-radius: 4px 4px 0 0;
}
.print-bar span { color: rgba(255,255,255,.8); font-size: 12px; }
.print-btn {
  background: #fff;
  color: #C0211B;
  border: none;
  padding: 8px 22px;
  border-radius: 5px;
  font-family: inherit;
  font-size: 13px;
  font-weight: 700;
  cursor: pointer;
}
.print-btn:hover { background: #f0fdf4; }

/* Header row: logo left, invoice info right */
.inv-header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  margin-bottom: 36px;
}
.inv-logo {
  height: 90px;
  width: auto;
  object-fit: contain;
}
.inv-title-block {
  text-align: right;
}
.inv-title-block h1 {
  font-size: 32px;
  font-weight: 300;
  color: #333;
  letter-spacing: 1px;
  margin-bottom: 4px;
}
.inv-number {
  font-size: 13px;
  color: #555;
  margin-bottom: 12px;
}
.inv-balance-label { font-size: 11px; color: #888; text-transform: uppercase; letter-spacing: .08em; }
.inv-balance-amount { font-size: 24px; font-weight: 700; color: #1A1A1A; margin-top: 2px; }

/* Issuer block */
.issuer-block {
  margin-bottom: 32px;
}
.issuer-name { font-weight: 700; font-size: 14px; color: #1A1A1A; }
.issuer-addr { font-size: 12px; color: #666; line-height: 1.6; margin-top: 2px; }

/* Meta row: Bill To left, Dates right */
.meta-row {
  display: flex;
  justify-content: space-between;
  margin-bottom: 36px;
}
.bill-to-label { font-size: 11px; color: #888; text-transform: uppercase; letter-spacing: .08em; margin-bottom: 4px; }
.bill-to-name  { font-weight: 700; font-size: 14px; color: #1A1A1A; }
.bill-to-addr  { font-size: 12px; color: #666; line-height: 1.6; margin-top: 2px; }

.date-table { text-align: right; }
.date-table td { padding: 3px 0 3px 24px; font-size: 12px; }
.date-table td:first-child { color: #888; }
.date-table td:last-child  { font-weight: 600; color: #333; }

/* Items table */
.items-tbl {
  width: 100%;
  border-collapse: collapse;
  margin-bottom: 0;
}
.items-tbl thead th {
  background: #404040;
  color: #fff;
  font-size: 11px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: .06em;
  padding: 10px 14px;
  text-align: left;
}
.items-tbl thead th.r { text-align: right; }
.items-tbl tbody td {
  padding: 12px 14px;
  border-bottom: 1px solid #eee;
  font-size: 12.5px;
  vertical-align: top;
}
.items-tbl tbody tr:last-child td { border-bottom: 2px solid #ddd; }
.items-tbl .r { text-align: right; }

/* Totals */
.totals-wrap {
  display: flex;
  justify-content: flex-end;
  margin-top: 0;
}
.totals-tbl {
  width: 280px;
}
.totals-tbl td {
  padding: 7px 14px;
  font-size: 12.5px;
  border-bottom: 1px solid #eee;
}
.totals-tbl tr:last-child td { border-bottom: none; }
.totals-tbl .l { text-align: left; color: #555; }
.totals-tbl .r { text-align: right; font-weight: 600; }
.totals-tbl .payment-row td { color: #C0211B; }
.totals-tbl .balance-row td {
  font-weight: 700;
  font-size: 14px;
  color: #1A1A1A;
  background: #f7f7f7;
}

/* Notes + T&C */
.footer-sections {
  margin-top: 36px;
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 24px;
}
.footer-label { font-size: 11px; color: #888; text-transform: uppercase; letter-spacing: .08em; margin-bottom: 5px; }
.footer-text  { font-size: 12px; color: #555; line-height: 1.65; }

/* Bank details */
.bank-details {
  margin-top: 32px;
  padding: 16px 20px;
  background: #f9f9f9;
  border-left: 3px solid #C0211B;
  border-radius: 2px;
}
.bank-details table { border-collapse: collapse; width: 100%; max-width: 480px; }
.bank-details td { padding: 3px 0; font-size: 12px; }
.bank-details td:first-child { color: #888; width: 160px; }
.bank-details td:last-child { font-weight: 600; color: #1A1A1A; }

/* ── PRINT ── */
@media print {
  body { background: #fff; }
  .page-wrap { box-shadow: none; margin: 0; max-width: 100%; }
  .invoice-body { padding: 24px 32px; }
  .print-bar { display: none !important; }
}
</style>
</head>
<body>

<div class="page-wrap">

  <!-- Print bar (hidden in print) -->
  <div class="print-bar">
    <span><?= htmlspecialchars($inv['invoice_number']) ?> — <?= htmlspecialchars($inv['bill_to_name']) ?></span>
    <button class="print-btn" onclick="window.print()">🖨 Print / Save as PDF</button>
  </div>

  <div class="invoice-body">

    <!-- Header -->
    <div class="inv-header">
      <?php if ($inv['issuer'] === 'Savannah Holidays Ltd'): ?>
        <img class="inv-logo" src="assets/logo_sh.png" alt="Savannah Holidays Ltd"
             style="height:90px;width:auto;object-fit:contain;">
      <?php else: ?>
        <img class="inv-logo" src="assets/logo_se.png" alt="Savannah Explorers Ltd"
             style="height:90px;width:auto;object-fit:contain;">
      <?php endif; ?>
      <div class="inv-title-block">
        <h1>Invoice</h1>
        <div class="inv-number"># <?= htmlspecialchars($inv['invoice_number']) ?></div>
        <div class="inv-balance-label">Balance Due</div>
        <div class="inv-balance-amount"><?= p((float)$inv['balance_due'], $sym) ?></div>
      </div>
    </div>

    <!-- Issuer -->
    <div class="issuer-block">
      <div class="issuer-name"><?= htmlspecialchars($inv['issuer']) ?></div>
      <?php if ($inv['issuer'] === 'Savannah Explorers Ltd'): ?>
        <div class="issuer-addr">Arusha, P.O. Box 16726<br>Tanzania</div>
      <?php else: ?>
        <div class="issuer-addr">Port Louis, Mauritius</div>
      <?php endif; ?>
    </div>

    <!-- Bill To + Dates -->
    <div class="meta-row">
      <div>
        <div class="bill-to-label">Bill To</div>
        <div class="bill-to-name"><?= htmlspecialchars($inv['bill_to_name']) ?></div>
        <?php if ($inv['bill_to_address']): ?>
          <div class="bill-to-addr"><?= nl2br(htmlspecialchars($inv['bill_to_address'])) ?></div>
        <?php endif; ?>
      </div>
      <table class="date-table">
        <tr>
          <td>Invoice Date :</td>
          <td><?= date('d M Y', strtotime($inv['issue_date'])) ?></td>
        </tr>
        <?php if ($inv['terms']): ?>
        <tr>
          <td>Terms :</td>
          <td><?= htmlspecialchars($inv['terms']) ?></td>
        </tr>
        <?php endif; ?>
        <?php if ($inv['due_date']): ?>
        <tr>
          <td>Due Date :</td>
          <td><?= date('d M Y', strtotime($inv['due_date'])) ?></td>
        </tr>
        <?php endif; ?>
      </table>
    </div>

    <!-- Line Items -->
    <table class="items-tbl">
      <thead>
        <tr>
          <th style="width:36px">#</th>
          <th>Item &amp; Description</th>
          <th class="r" style="width:70px">Qty</th>
          <th class="r" style="width:100px">Rate</th>
          <th class="r" style="width:100px">Amount</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($items as $n => $item): ?>
        <tr>
          <td class="text-muted" style="color:#aaa"><?= $n+1 ?></td>
          <td><?= htmlspecialchars($item['description']) ?></td>
          <td class="r"><?= rtrim(rtrim(number_format($item['quantity'],2),'0'),'.') ?></td>
          <td class="r"><?= number_format((float)$item['unit_price'],2) ?></td>
          <td class="r" style="<?= $item['line_total'] < 0 ? 'color:#C0211B' : '' ?>">
            <?= number_format((float)$item['line_total'],2) ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <!-- Totals -->
    <div class="totals-wrap">
      <table class="totals-tbl">
        <tr>
          <td class="l">Sub Total</td>
          <td class="r"><?= number_format((float)$inv['subtotal'],2) ?></td>
        </tr>
        <tr>
          <td class="l"><strong>Total</strong></td>
          <td class="r"><strong><?= $sym.number_format((float)$inv['total'],2) ?></strong></td>
        </tr>
        <?php if ($inv['amount_paid'] > 0): ?>
        <tr class="payment-row">
          <td class="l">Payment Made</td>
          <td class="r">(-) <?= number_format((float)$inv['amount_paid'],2) ?></td>
        </tr>
        <?php endif; ?>
        <tr class="balance-row">
          <td class="l">Balance Due</td>
          <td class="r"><?= $sym.number_format((float)$inv['balance_due'],2) ?></td>
        </tr>
      </table>
    </div>

    <!-- Notes + T&C -->
    <?php if ($inv['notes'] || $inv['terms_conditions']): ?>
    <div class="footer-sections">
      <?php if ($inv['notes']): ?>
      <div>
        <div class="footer-label">Notes</div>
        <div class="footer-text"><?= nl2br(htmlspecialchars($inv['notes'])) ?></div>
      </div>
      <?php endif; ?>
      <?php if ($inv['terms_conditions']): ?>
      <div>
        <div class="footer-label">Terms &amp; Conditions</div>
        <div class="footer-text"><?= nl2br(htmlspecialchars($inv['terms_conditions'])) ?></div>
      </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($inv['issuer'] === 'Savannah Holidays Ltd'): ?>
    <div class="bank-details">
      <div class="footer-label">Bank Details for Payment</div>
      <table>
        <tr><td>Beneficiary Bank:</td><td>AfrAsia Bank Ltd</td></tr>
        <tr><td>Bank Address:</td><td>Bowen Square, 10, Dr Ferriere Street, Port Louis, Mauritius</td></tr>
        <tr><td>Account Name:</td><td>SAVANNAH HOLIDAYS LTD</td></tr>
        <?php if ($inv['currency'] === 'USD'): ?>
        <tr><td>IBAN:</td><td>MU55AFBL2501138053000000013USD</td></tr>
        <tr><td>Account Number:</td><td>138053000000013</td></tr>
        <tr><td>Currency:</td><td>USD</td></tr>
        <?php else: ?>
        <tr><td>IBAN:</td><td>MU40AFBL2501138053000000024EUR</td></tr>
        <tr><td>Account Number:</td><td>138053000000024</td></tr>
        <tr><td>Currency:</td><td>EUR</td></tr>
        <?php endif; ?>
        <tr><td>Swift Code:</td><td>AFBLMUMU</td></tr>
      </table>
    </div>
    <?php endif; ?>

  </div><!-- /invoice-body -->
</div><!-- /page-wrap -->

<?php if (!empty($_GET['download'])): ?>
<script>
window.addEventListener('load', function () {
  window.print();
});
</script>
<?php endif; ?>

</body>
</html>
