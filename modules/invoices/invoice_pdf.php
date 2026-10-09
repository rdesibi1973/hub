<?php
// Printable invoice ("Print / Save as PDF"). The layout is includes/invoice_html.php,
// the same one Dompdf renders (save_invoice_pdf, invoice email, ZIP export).
require_once 'config.php';
require_once 'includes/invoice_html.php';
requireInvoiceAccess();   // no header.php here: check the login / invoices permission ourselves
$db = db();
$id = (int)($_GET['id'] ?? 0);

$s = $db->prepare("SELECT * FROM invoices WHERE id=?"); $s->execute([$id]);
$inv = $s->fetch();
if (!$inv) { die('Invoice not found.'); }

$items = $db->prepare("SELECT * FROM invoice_items WHERE invoice_id=? ORDER BY sort_order,id");
$items->execute([$id]); $items = $items->fetchAll();

$doc = inv_doc_from_invoice($inv, $items);
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
body { font-family: 'Open Sans', Arial, sans-serif; font-size: 13px; color: #333; background: #f4f4f4; }
.page-wrap { max-width: 800px; margin: 30px auto; background: #fff; box-shadow: 0 2px 20px rgba(0,0,0,.12); border-radius: 4px; }
.invoice-body { padding: 48px 52px; }
.print-bar { background: #C0211B; padding: 12px 52px; display: flex; align-items: center; justify-content: space-between; border-radius: 4px 4px 0 0; }
.print-bar span { color: rgba(255,255,255,.8); font-size: 12px; }
.print-btn { background: #fff; color: #C0211B; border: none; padding: 8px 22px; border-radius: 5px; font-family: inherit; font-size: 13px; font-weight: 700; cursor: pointer; }
.print-btn:hover { background: #f0fdf4; }
<?= inv_doc_css() ?>

@page { size: A4 portrait; margin: 10mm; }
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
    <span><?= htmlspecialchars($inv['invoice_number']) ?> — <?= htmlspecialchars($inv['bill_to_name']) ?></span>
    <button class="print-btn" onclick="window.print()">🖨 Print / Save as PDF</button>
  </div>
  <?= inv_doc_body($doc, 'assets/' . $doc['issuer']['logo']) ?>
</div>

<?php if (!empty($_GET['download'])): ?>
<script>
window.addEventListener('load', function () {
  window.print();
});
</script>
<?php endif; ?>

</body>
</html>
