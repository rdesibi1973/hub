<?php
require_once 'config.php';
$pageTitle = 'New Credit Note';
$db = db();

$errors = [];

// ── A credit note must reference an invoice ───────────────────────────────
$invoiceId = (int)($_GET['invoice_id'] ?? $_POST['invoice_id'] ?? 0);
if (!$invoiceId) {
    flash('A credit note must be created from an invoice.', 'error');
    header('Location: invoices.php'); exit;
}

$s = $db->prepare("SELECT * FROM invoices WHERE id=?");
$s->execute([$invoiceId]);
$inv = $s->fetch();
if (!$inv) { flash('Invoice not found.', 'error'); header('Location: invoices.php'); exit; }

// Items of the source invoice (used to pre-fill the credit lines)
$invItems = $db->prepare("SELECT * FROM invoice_items WHERE invoice_id=? ORDER BY sort_order,id");
$invItems->execute([$invoiceId]);
$invItems = $invItems->fetchAll();

$sym = $inv['currency'] === 'EUR' ? '€' : '$';

// ── Handle POST ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $issueDate = $_POST['issue_date'] ?? date('Y-m-d');
    $reason    = trim($_POST['reason'] ?? '');
    $notes     = trim($_POST['notes'] ?? '');

    if (!$issueDate) $errors[] = 'Issue date is required.';

    $items = [];
    foreach ($_POST['items'] ?? [] as $item) {
        $desc = trim($item['description'] ?? '');
        $qty  = (float)($item['quantity'] ?? 1);
        if ($qty == (int)$qty) $qty = (int)$qty;
        $price = (float)($item['unit_price'] ?? 0);
        // Store credit amounts as positive values; the refund is applied as a
        // negative payment on the invoice. Skip empty / zero lines.
        if ($desc && $price != 0) {
            $items[] = [
                'description' => $desc,
                'quantity'    => $qty,
                'unit_price'  => abs($price),
                'line_total'  => round($qty * abs($price), 2),
            ];
        }
    }
    if (empty($items)) $errors[] = 'At least one credit line with an amount is required.';

    $cnTotal = array_sum(array_column($items, 'line_total'));
    if ($cnTotal > (float)$inv['total'] + 0.001) {
        $errors[] = 'Credit note total (' . fmt_money($cnTotal, $inv['currency'])
                  . ') cannot exceed the invoice total (' . fmt_money((float)$inv['total'], $inv['currency']) . ').';
    }

    if (!$errors) {
        $cnNum = generate_cn_number($db);
        $uid   = current_user()['id'];

        $db->prepare("INSERT INTO credit_notes
            (cn_number, invoice_id, request_id, customer_id, bill_to_name, bill_to_address,
             issuer, currency, issue_date, reason, notes, status, created_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,'Issued',?)")
           ->execute([
                $cnNum, $invoiceId, $inv['request_id'], $inv['customer_id'],
                $inv['bill_to_name'], $inv['bill_to_address'],
                $inv['issuer'], $inv['currency'], $issueDate,
                $reason ?: null, $notes ?: null, $uid,
           ]);

        $cnId = (int)$db->lastInsertId();

        $sort = 0;
        foreach ($items as $item) {
            $db->prepare("INSERT INTO credit_note_items (credit_note_id,sort_order,description,quantity,unit_price,line_total) VALUES (?,?,?,?,?,?)")
               ->execute([$cnId,$sort++,$item['description'],$item['quantity'],$item['unit_price'],$item['line_total']]);
        }

        recalculate_credit_note($db, $cnId);
        sync_cn_invoice_payment($db, $cnId); // creates the negative payment + resyncs

        flash("Credit note {$cnNum} created. Refund applied to {$inv['invoice_number']}.");
        header("Location: cn_view.php?id=$cnId"); exit;
    }
}

include 'includes/header.php';
?>

<div class="page-header">
  <div>
    <h2>New Credit Note</h2>
    <div class="sub">
      <a href="cn_list.php" style="color:var(--grey-mid);text-decoration:none">← Credit Notes</a>
      &nbsp;·&nbsp; against
      <a href="invoice_view.php?id=<?= (int)$inv['id'] ?>" style="color:var(--blue);text-decoration:none"><?= h($inv['invoice_number']) ?></a>
    </div>
  </div>
</div>

<?php if ($errors): ?>
  <div class="flash flash-error"><?= implode('<br>', array_map('h', $errors)) ?></div>
<?php endif; ?>

<div class="flash" style="background:#FFF8E1;color:#8A6D00;border-left:4px solid #E0A800;max-width:860px;">
  This credit note will record a refund of its total against invoice
  <strong><?= h($inv['invoice_number']) ?></strong> (current total
  <strong><?= fmt_money((float)$inv['total'], $inv['currency']) ?></strong>),
  reducing both the invoice balance and the linked request value.
</div>

<form method="POST" id="cnForm">
<input type="hidden" name="invoice_id" value="<?= (int)$inv['id'] ?>">

<div class="form-card">

  <!-- Header -->
  <div class="form-grid">
    <div class="form-group">
      <label>Credit Note Number</label>
      <input type="text" value="CN-<?= date('Y') ?>-XXXX (auto)" readonly
             style="background:var(--off-white);color:var(--grey-mid);cursor:default">
    </div>
    <div class="form-group">
      <label>Issuer</label>
      <input type="text" value="<?= h($inv['issuer']) ?>" readonly
             style="background:var(--off-white);color:var(--grey-mid);cursor:default">
    </div>
    <div class="form-group">
      <label>Currency</label>
      <input type="text" value="<?= h($inv['currency']) ?>" readonly
             style="background:var(--off-white);color:var(--grey-mid);cursor:default">
    </div>
    <div class="form-group">
      <label>Issue Date *</label>
      <input type="date" name="issue_date" value="<?= date('Y-m-d') ?>" required>
    </div>
    <div class="form-group full">
      <label>Reason for credit / refund</label>
      <input type="text" name="reason" placeholder="e.g. Trip cancelled by client, partial refund of park fees…">
    </div>
  </div>

  <!-- Bill To (read-only, inherited from invoice) -->
  <div class="form-section" style="margin-top:28px;">Bill To</div>
  <div class="table-wrap" style="max-width:860px;margin-bottom:24px">
    <div class="detail-grid">
      <div class="detail-label">Name</div>
      <div class="detail-value"><strong><?= h($inv['bill_to_name']) ?></strong></div>
      <?php if ($inv['bill_to_address']): ?>
      <div class="detail-label">Address</div>
      <div class="detail-value" style="font-size:.8rem;color:var(--grey-mid)"><?= nl2br(h($inv['bill_to_address'])) ?></div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Credit lines -->
  <div class="form-section">Credit Lines</div>
  <p style="font-size:.8rem;color:var(--grey-mid);margin:-6px 0 12px">
    Enter the amounts to credit. Use “Copy invoice lines” to credit the full invoice, then adjust for a partial refund.
  </p>
  <table class="items-table">
    <thead>
      <tr>
        <th style="width:50%"># &nbsp; Description</th>
        <th class="text-right" style="width:90px">Qty</th>
        <th class="text-right" style="width:120px">Amount</th>
        <th class="text-right" style="width:110px">Credit</th>
        <th style="width:40px"></th>
      </tr>
    </thead>
    <tbody id="itemsBody"></tbody>
  </table>

  <div style="display:flex;align-items:center;gap:10px;margin-bottom:20px;flex-wrap:wrap;">
    <button type="button" onclick="addItem()" class="btn btn-outline btn-sm">+ Add Line</button>
    <button type="button" onclick="copyInvoiceLines()" class="btn btn-outline btn-sm">⧉ Copy invoice lines</button>
  </div>

  <!-- Totals -->
  <div style="display:flex;justify-content:flex-end;margin-bottom:28px;">
    <div class="totals-box">
      <div class="totals-row"><span>Invoice Total</span><span><?= fmt_money((float)$inv['total'], $inv['currency']) ?></span></div>
      <div class="totals-row total"><span>Credit Total</span><span id="totalDisplay"><?= $sym ?>0.00</span></div>
    </div>
  </div>

  <div class="form-section">Notes</div>
  <div class="form-grid">
    <div class="form-group full">
      <label>Notes (shown on credit note)</label>
      <textarea name="notes"></textarea>
    </div>
  </div>

  <div class="form-actions">
    <button type="submit" class="btn btn-red">Create Credit Note</button>
    <a href="invoice_view.php?id=<?= (int)$inv['id'] ?>" class="btn btn-grey">Cancel</a>
  </div>

</div><!-- /form-card -->
</form>

<script>
var CURRENCY = <?= json_encode($inv['currency']) ?>;
var INVOICE_LINES = <?= json_encode(array_map(fn($it) => [
    'description' => $it['description'],
    'quantity'    => (float)$it['quantity'],
    'unit_price'  => (float)$it['unit_price'],
], $invItems)) ?>;

var itemIdx = 0;

function addItem(desc, qty, price) {
  desc  = desc  !== undefined ? desc  : '';
  qty   = qty   !== undefined ? qty   : 1;
  price = price !== undefined ? price : '';
  var tbody = document.getElementById('itemsBody');
  var i = itemIdx++;
  var tr = document.createElement('tr');
  tr.innerHTML =
    '<td style="padding:4px 4px 4px 0;vertical-align:top"><textarea class="desc-input" name="items['+i+'][description]" rows="2" placeholder="Description" required style="width:100%;resize:vertical;min-height:38px;">'+escHtml(desc)+'</textarea></td>'
   +'<td style="vertical-align:top"><input type="number" class="qty-input" name="items['+i+'][quantity]" value="'+qty+'" step="1" min="1"></td>'
   +'<td style="vertical-align:top"><input type="number" class="price-input" name="items['+i+'][unit_price]" value="'+price+'" step="0.01" placeholder="Amount"></td>'
   +'<td class="total-cell" style="vertical-align:top;color:#C0211B" data-val="'+(qty*(price||0))+'">'+fmtAmt(qty*(price||0))+'</td>'
   +'<td style="text-align:center;vertical-align:top"><button type="button" onclick="removeItem(this)" class="btn btn-danger btn-sm" title="Remove">✕</button></td>';
  tr.querySelector('.qty-input').addEventListener('input', calcRow);
  tr.querySelector('.price-input').addEventListener('input', calcRow);
  tbody.appendChild(tr);
  recalcAll();
}

function copyInvoiceLines() {
  document.getElementById('itemsBody').innerHTML = '';
  itemIdx = 0;
  if (!INVOICE_LINES.length) { addItem(); return; }
  INVOICE_LINES.forEach(function(l) {
    addItem(l.description, l.quantity, Math.abs(l.unit_price));
  });
}

function calcRow(e) {
  var row   = e.target.closest('tr');
  var qty   = parseFloat(row.querySelector('.qty-input').value)   || 0;
  var price = Math.abs(parseFloat(row.querySelector('.price-input').value) || 0);
  var total = qty * price;
  var cell  = row.querySelector('.total-cell');
  cell.dataset.val = total;
  cell.textContent = fmtAmt(total);
  recalcAll();
}

function removeItem(btn) { btn.closest('tr').remove(); recalcAll(); }

function recalcAll() {
  var total = 0;
  document.querySelectorAll('#itemsBody .total-cell').forEach(function(c){
    total += parseFloat(c.dataset.val) || 0;
  });
  document.getElementById('totalDisplay').textContent = fmtAmt(total);
}

function fmtAmt(n) {
  var sym = CURRENCY === 'EUR' ? '€' : '$';
  return sym + parseFloat(n||0).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});
}
function escHtml(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

// Start with the invoice lines copied in, ready to adjust.
copyInvoiceLines();
</script>

<?php include 'includes/footer.php'; ?>
