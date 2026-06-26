<?php
require_once 'config.php';
$db = db();
$id = (int)($_GET['id'] ?? 0);

$s = $db->prepare(
    "SELECT cn.*, i.invoice_number
     FROM credit_notes cn LEFT JOIN invoices i ON i.id = cn.invoice_id
     WHERE cn.id=?"
);
$s->execute([$id]);
$cn = $s->fetch();
if (!$cn) { die('Credit note not found.'); }

$items = $db->prepare("SELECT * FROM credit_note_items WHERE credit_note_id=? ORDER BY sort_order,id");
$items->execute([$id]); $items = $items->fetchAll();

$sym = $cn['currency'] === 'EUR' ? '€' : '$';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Credit Note <?= htmlspecialchars($cn['cn_number']) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@300;400;600;700&display=swap" rel="stylesheet">
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'Open Sans', Arial, sans-serif; font-size: 13px; color: #333; background: #f4f4f4; }
.page-wrap { max-width: 800px; margin: 30px auto; background: #fff; box-shadow: 0 2px 20px rgba(0,0,0,.12); border-radius: 4px; }
.invoice-body { padding: 48px 52px; }
.print-bar { background: #C0211B; padding: 12px 52px; display: flex; align-items: center; justify-content: space-between; border-radius: 4px 4px 0 0; }
.print-bar span { color: rgba(255,255,255,.85); font-size: 12px; }
.print-btn { background: #fff; color: #C0211B; border: none; padding: 8px 22px; border-radius: 5px; font-family: inherit; font-size: 13px; font-weight: 700; cursor: pointer; }
.inv-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 36px; }
.inv-logo { height: 90px; width: auto; object-fit: contain; }
.inv-title-block { text-align: right; }
.inv-title-block h1 { font-size: 30px; font-weight: 300; color: #C0211B; letter-spacing: 1px; margin-bottom: 4px; }
.inv-number { font-size: 13px; color: #555; margin-bottom: 12px; }
.inv-balance-label { font-size: 11px; color: #888; text-transform: uppercase; letter-spacing: .08em; }
.inv-balance-amount { font-size: 24px; font-weight: 700; color: #C0211B; margin-top: 2px; }
.issuer-block { margin-bottom: 32px; }
.issuer-name { font-weight: 700; font-size: 14px; color: #1A1A1A; }
.issuer-addr { font-size: 12px; color: #666; line-height: 1.6; margin-top: 2px; }
.meta-row { display: flex; justify-content: space-between; margin-bottom: 36px; }
.bill-to-label { font-size: 11px; color: #888; text-transform: uppercase; letter-spacing: .08em; margin-bottom: 4px; }
.bill-to-name  { font-weight: 700; font-size: 14px; color: #1A1A1A; }
.bill-to-addr  { font-size: 12px; color: #666; line-height: 1.6; margin-top: 2px; }
.date-table { text-align: right; }
.date-table td { padding: 3px 0 3px 24px; font-size: 12px; }
.date-table td:first-child { color: #888; }
.date-table td:last-child  { font-weight: 600; color: #333; }
.items-tbl { width: 100%; border-collapse: collapse; }
.items-tbl thead th { background: #404040; color: #fff; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; padding: 10px 14px; text-align: left; }
.items-tbl thead th.r { text-align: right; }
.items-tbl tbody td { padding: 12px 14px; border-bottom: 1px solid #eee; font-size: 12.5px; vertical-align: top; }
.items-tbl tbody tr:last-child td { border-bottom: 2px solid #ddd; }
.items-tbl .r { text-align: right; }
.totals-wrap { display: flex; justify-content: flex-end; }
.totals-tbl { width: 280px; }
.totals-tbl td { padding: 7px 14px; font-size: 12.5px; border-bottom: 1px solid #eee; }
.totals-tbl tr:last-child td { border-bottom: none; }
.totals-tbl .l { text-align: left; color: #555; }
.totals-tbl .r { text-align: right; font-weight: 600; }
.totals-tbl .balance-row td { font-weight: 700; font-size: 14px; color: #C0211B; background: #fdf3f2; }
.footer-sections { margin-top: 36px; }
.footer-label { font-size: 11px; color: #888; text-transform: uppercase; letter-spacing: .08em; margin-bottom: 5px; }
.footer-text  { font-size: 12px; color: #555; line-height: 1.65; }
.ref-line { margin-top: 4px; font-size: 12px; color: #666; }
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
  <div class="print-bar">
    <span><?= htmlspecialchars($cn['cn_number']) ?> — <?= htmlspecialchars($cn['bill_to_name']) ?></span>
    <button class="print-btn" onclick="window.print()">🖨 Print / Save as PDF</button>
  </div>

  <div class="invoice-body">

    <div class="inv-header">
      <?php if ($cn['issuer'] === 'Savannah Holidays Ltd'): ?>
        <img class="inv-logo" src="assets/logo_sh.png" alt="Savannah Holidays Ltd">
      <?php else: ?>
        <img class="inv-logo" src="assets/logo_se.png" alt="Savannah Explorers Ltd">
      <?php endif; ?>
      <div class="inv-title-block">
        <h1>Credit Note</h1>
        <div class="inv-number"># <?= htmlspecialchars($cn['cn_number']) ?></div>
        <?php if ($cn['invoice_number']): ?>
          <div class="ref-line">Against invoice <?= htmlspecialchars($cn['invoice_number']) ?></div>
        <?php endif; ?>
        <div class="inv-balance-label" style="margin-top:10px">Total Credit</div>
        <div class="inv-balance-amount"><?= $sym . number_format((float)$cn['total'], 2) ?></div>
      </div>
    </div>

    <div class="issuer-block">
      <div class="issuer-name"><?= htmlspecialchars($cn['issuer']) ?></div>
      <?php if ($cn['issuer'] === 'Savannah Explorers Ltd'): ?>
        <div class="issuer-addr">Arusha, P.O. Box 16726<br>Tanzania</div>
      <?php else: ?>
        <div class="issuer-addr">Certificate of Incorporation No. 212622<br>H21 Home Scene Building, Healthscape, Forbach - Mauritius<br>info@savannahholidays.net</div>
      <?php endif; ?>
    </div>

    <div class="meta-row">
      <div>
        <div class="bill-to-label">Credit To</div>
        <div class="bill-to-name"><?= htmlspecialchars($cn['bill_to_name']) ?></div>
        <?php if ($cn['bill_to_address']): ?>
          <div class="bill-to-addr"><?= nl2br(htmlspecialchars($cn['bill_to_address'])) ?></div>
        <?php endif; ?>
      </div>
      <table class="date-table">
        <tr><td>Credit Note Date :</td><td><?= date('d M Y', strtotime($cn['issue_date'])) ?></td></tr>
        <?php if ($cn['invoice_number']): ?>
        <tr><td>Original Invoice :</td><td><?= htmlspecialchars($cn['invoice_number']) ?></td></tr>
        <?php endif; ?>
        <?php if ($cn['reason']): ?>
        <tr><td>Reason :</td><td><?= htmlspecialchars($cn['reason']) ?></td></tr>
        <?php endif; ?>
      </table>
    </div>

    <table class="items-tbl">
      <thead>
        <tr>
          <th style="width:36px">#</th>
          <th>Item &amp; Description</th>
          <th class="r" style="width:70px">Qty</th>
          <th class="r" style="width:100px">Amount</th>
          <th class="r" style="width:100px">Credit</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($items as $n => $item): ?>
        <tr>
          <td style="color:#aaa"><?= $n+1 ?></td>
          <td><?= htmlspecialchars($item['description']) ?></td>
          <td class="r"><?= rtrim(rtrim(number_format($item['quantity'],2),'0'),'.') ?></td>
          <td class="r"><?= number_format((float)$item['unit_price'],2) ?></td>
          <td class="r" style="color:#C0211B"><?= number_format((float)$item['line_total'],2) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <div class="totals-wrap">
      <table class="totals-tbl">
        <tr><td class="l">Sub Total</td><td class="r"><?= number_format((float)$cn['subtotal'],2) ?></td></tr>
        <tr class="balance-row"><td class="l">Total Credit</td><td class="r"><?= $sym.number_format((float)$cn['total'],2) ?></td></tr>
      </table>
    </div>

    <?php if ($cn['notes']): ?>
    <div class="footer-sections">
      <div class="footer-label">Notes</div>
      <div class="footer-text"><?= nl2br(htmlspecialchars($cn['notes'])) ?></div>
    </div>
    <?php endif; ?>

  </div>
</div>

<?php if (!empty($_GET['download'])): ?>
<script>window.addEventListener('load', function () { window.print(); });</script>
<?php endif; ?>

</body>
</html>
