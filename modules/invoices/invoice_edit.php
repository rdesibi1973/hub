<?php
require_once 'config.php';
$pageTitle = 'Edit Invoice';
$db  = db();
$id  = (int)($_GET['id'] ?? 0);
$errors = [];

$s = $db->prepare("SELECT * FROM invoices WHERE id=?"); $s->execute([$id]);
$inv = $s->fetch();
if (!$inv) { flash('Invoice not found.','error'); header('Location: invoices.php'); exit; }

$items = $db->prepare("SELECT * FROM invoice_items WHERE invoice_id=? ORDER BY sort_order,id");
$items->execute([$id]); $items = $items->fetchAll();

$customers = $db->query("SELECT id, name, type, address, city, country FROM customers WHERE active=1 ORDER BY name")->fetchAll();

// ── Handle POST ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $issuer     = $_POST['issuer']      ?? $inv['issuer'];
    $currency   = $_POST['currency']    ?? $inv['currency'];
    $billToName = trim($_POST['bill_to_name'] ?? '');
    $billToAddr = trim($_POST['bill_to_address'] ?? '');
    $customerId = (int)($_POST['customer_id'] ?? 0) ?: null;
    $reqId      = (int)($_POST['request_id'] ?? 0) ?: null;
    $issueDate  = $_POST['issue_date']  ?? $inv['issue_date'];
    $dueDate    = $_POST['due_date']    ?: null;
    $terms      = trim($_POST['terms']  ?? $inv['terms']);
    $notes      = trim($_POST['notes']  ?? '');
    $tc         = trim($_POST['terms_conditions'] ?? '');

    if (!$billToName) $errors[] = 'Bill To name is required.';
    if (!$issueDate)  $errors[] = 'Issue date is required.';

    $newItems = [];
    foreach ($_POST['items'] ?? [] as $item) {
        $desc  = trim($item['description'] ?? '');
        $qty   = (float)($item['quantity']   ?? 1);
        $price = (float)($item['unit_price'] ?? 0);
        if ($desc) $newItems[] = ['description'=>$desc,'quantity'=>$qty,'unit_price'=>$price,'line_total'=>round($qty*$price,2)];
    }
    if (empty($newItems)) $errors[] = 'At least one item is required.';

    if (!$errors) {
        $db->prepare("UPDATE invoices SET
            customer_id=?, bill_to_name=?, bill_to_address=?, issuer=?, currency=?,
            issue_date=?, due_date=?, terms=?, notes=?, terms_conditions=?, updated_at=NOW()
            WHERE id=?")
           ->execute([$customerId,$billToName,$billToAddr?:null,$issuer,$currency,
                      $issueDate,$dueDate,$terms,$notes?:null,$tc?:null,$id]);

        // Replace items
        $db->prepare("DELETE FROM invoice_items WHERE invoice_id=?")->execute([$id]);
        $sort = 0;
        foreach ($newItems as $item) {
            $db->prepare("INSERT INTO invoice_items (invoice_id,sort_order,description,quantity,unit_price,line_total) VALUES (?,?,?,?,?,?)")
               ->execute([$id,$sort++,$item['description'],$item['quantity'],$item['unit_price'],$item['line_total']]);
        }
        recalculate_invoice($db, $id);
        sync_request_value($db, $id);

        flash("Invoice {$inv['invoice_number']} updated.");
        header("Location: invoice_view.php?id=$id"); exit;
    }
}

include 'includes/header.php';
?>

<div class="page-header">
  <div>
    <h2>Edit — <?= h($inv['invoice_number']) ?></h2>
    <div class="sub"><a href="invoice_view.php?id=<?= $id ?>" style="color:var(--grey-mid);text-decoration:none">← View Invoice</a></div>
  </div>
  <span class="badge <?= INV_STATUSES[$inv['status']] ?? '' ?>"><?= h($inv['status']) ?></span>
</div>

<?php if ($errors): ?>
  <div class="flash flash-error"><?= implode('<br>', array_map('h', $errors)) ?></div>
<?php endif; ?>

<form method="POST" id="invForm">
<input type="hidden" name="customer_id" id="customerId" value="<?= (int)($inv['customer_id'] ?? 0) ?>">
<input type="hidden" name="request_id"  value="<?= (int)($inv['request_id'] ?? 0) ?>">

<div class="form-card">

  <div class="form-grid">
    <div class="form-group">
      <label>Issuer *</label>
      <select name="issuer" id="issuerSel">
        <?php foreach (INV_ISSUERS as $iss): ?>
          <option value="<?= h($iss) ?>" <?= $inv['issuer']===$iss?'selected':'' ?>><?= h($iss) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group">
      <label>Invoice Number</label>
      <input type="text" value="<?= h($inv['invoice_number']) ?>" readonly style="background:var(--off-white);color:var(--grey-mid);cursor:default">
    </div>
    <div class="form-group">
      <label>Currency *</label>
      <select name="currency" id="currency" onchange="recalcAll()">
        <?php foreach (INV_CURRENCIES as $c): ?>
          <option value="<?= $c ?>" <?= $inv['currency']===$c?'selected':'' ?>><?= $c ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group">
      <label>Terms</label>
      <input type="text" name="terms" value="<?= h($inv['terms'] ?? 'Due on Receipt') ?>" list="termsList">
      <datalist id="termsList">
        <?php foreach (INV_TERMS_OPTS as $t): ?><option value="<?= h($t) ?>"><?php endforeach; ?>
      </datalist>
    </div>
    <div class="form-group">
      <label>Issue Date *</label>
      <input type="date" name="issue_date" value="<?= h($inv['issue_date']) ?>" required>
    </div>
    <div class="form-group">
      <label>Due Date</label>
      <input type="date" name="due_date" value="<?= h($inv['due_date'] ?? '') ?>">
    </div>
  </div>

  <div class="form-section" style="margin-top:28px;">Bill To</div>
  <div class="form-grid">
    <div class="form-group full">
      <label>Customer / Select from list</label>
      <input type="text" id="customerSearch" placeholder="Type to search saved customers…" autocomplete="off"
             value="<?= h($inv['bill_to_name']) ?>" style="margin-bottom:6px">
      <div id="customerDrop" style="display:none;position:fixed;z-index:100;background:#fff;border:1.5px solid var(--grey-lt);border-radius:7px;box-shadow:0 4px 16px rgba(0,0,0,.12);max-height:220px;overflow-y:auto;"></div>
    </div>
    <div class="form-group full">
      <label>Bill To Name *</label>
      <input type="text" name="bill_to_name" id="billToName" required value="<?= h($inv['bill_to_name']) ?>">
    </div>
    <div class="form-group full">
      <label>Bill To Address</label>
      <textarea name="bill_to_address" rows="3"><?= h($inv['bill_to_address'] ?? '') ?></textarea>
    </div>
  </div>

  <div class="form-section">Line Items</div>
  <table class="items-table">
    <thead>
      <tr>
        <th style="width:50%"># &nbsp; Description</th>
        <th class="text-right" style="width:90px">Qty</th>
        <th class="text-right" style="width:120px">Rate</th>
        <th class="text-right" style="width:110px">Amount</th>
        <th style="width:40px"></th>
      </tr>
    </thead>
    <tbody id="itemsBody"></tbody>
  </table>
  <button type="button" onclick="addItem()" class="btn btn-outline btn-sm" style="margin-bottom:20px">+ Add Line</button>

  <div style="display:flex;justify-content:flex-end;margin-bottom:28px;">
    <div class="totals-box">
      <div class="totals-row"><span>Sub Total</span><span id="subtotalDisplay">—</span></div>
      <div class="totals-row total"><span>Total</span><span id="totalDisplay">—</span></div>
    </div>
  </div>

  <div class="form-section">Notes &amp; Terms</div>
  <div class="form-grid">
    <div class="form-group full">
      <label>Notes</label>
      <textarea name="notes"><?= h($inv['notes'] ?? INV_DEFAULT_NOTES) ?></textarea>
    </div>
    <div class="form-group full">
      <label>Terms &amp; Conditions</label>
      <textarea name="terms_conditions" class="tall"><?= h($inv['terms_conditions'] ?? INV_DEFAULT_TC) ?></textarea>
    </div>
  </div>

  <div class="form-actions">
    <button type="submit" class="btn btn-red">Save Changes</button>
    <a href="invoice_view.php?id=<?= $id ?>" class="btn btn-grey">Cancel</a>
  </div>

</div>
</form>

<style>
#customerSearch { width:100%;padding:9px 13px;border:1.5px solid var(--grey-lt);border-radius:7px;font-family:inherit;font-size:.85rem; }
.cust-drop-item { padding:9px 14px;cursor:pointer;font-size:.85rem;border-bottom:1px solid var(--grey-lt); }
.cust-drop-item:last-child { border-bottom:none; }
.cust-drop-item:hover { background:var(--off-white); }
.cust-drop-sub { font-size:.72rem;color:var(--grey-mid); }
</style>
<script>
var customers = <?= json_encode(array_map(fn($c)=>['id'=>$c['id'],'name'=>$c['name'],'type'=>$c['type'],
  'addr'=>implode(', ',array_filter([$c['address'],$c['city'],$c['country']]))], $customers)) ?>;

var customerSearch = document.getElementById('customerSearch');
var customerDrop   = document.getElementById('customerDrop');

customerSearch.addEventListener('input', function() {
  var q = this.value.toLowerCase();
  if (!q) { customerDrop.style.display='none'; return; }
  var matches = customers.filter(function(c){ return c.name.toLowerCase().includes(q); }).slice(0,8);
  if (!matches.length) { customerDrop.style.display='none'; return; }
  var rect = customerSearch.getBoundingClientRect();
  customerDrop.style.left  = rect.left+'px';
  customerDrop.style.top   = rect.bottom+'px';
  customerDrop.style.width = rect.width+'px';
  customerDrop.innerHTML = matches.map(function(c) {
    return '<div class="cust-drop-item" onclick="selectCustomer('+c.id+',\''+escJs(c.name)+'\',\''+escJs(c.addr)+'\')">'
         + '<div>'+escHtml(c.name)+'</div><div class="cust-drop-sub">'+c.type+(c.addr?' · '+escHtml(c.addr):'')+'</div></div>';
  }).join('');
  customerDrop.style.display='block';
});

function selectCustomer(id, name, addr) {
  document.getElementById('customerId').value = id;
  document.getElementById('billToName').value = name;
  document.querySelector('[name="bill_to_address"]').value = addr;
  customerSearch.value = name;
  customerDrop.style.display='none';
}
document.addEventListener('click', function(e){ if (!customerDrop.contains(e.target) && e.target!==customerSearch) customerDrop.style.display='none'; });

// Items
var itemIdx = 0;
function addItem(desc, qty, price) {
  desc=desc!==undefined?desc:''; qty=qty!==undefined?qty:1; price=price!==undefined?price:'';
  var tbody=document.getElementById('itemsBody'); var i=itemIdx++;
  var tr=document.createElement('tr');
  tr.innerHTML='<td style="padding:4px 4px 4px 0"><input type="text" class="desc-input" name="items['+i+'][description]" value="'+escHtml(desc)+'" placeholder="Description" required></td>'
    +'<td><input type="number" class="qty-input" name="items['+i+'][quantity]" value="'+qty+'" step="0.01"></td>'
    +'<td><input type="number" class="price-input" name="items['+i+'][unit_price]" value="'+price+'" step="0.01" placeholder="Neg. for discount"></td>'
    +'<td class="total-cell" data-val="'+(qty*(price||0))+'">'+fmtAmt(qty*(price||0))+'</td>'
    +'<td style="text-align:center"><button type="button" onclick="removeItem(this)" class="btn btn-danger btn-sm">✕</button></td>';
  tr.querySelector('.qty-input').addEventListener('input', calcRow);
  tr.querySelector('.price-input').addEventListener('input', calcRow);
  tbody.appendChild(tr); recalcAll();
}
function calcRow(e) {
  var row=e.target.closest('tr');
  var qty=parseFloat(row.querySelector('.qty-input').value)||0;
  var price=parseFloat(row.querySelector('.price-input').value)||0;
  var cell=row.querySelector('.total-cell'); cell.dataset.val=qty*price; cell.textContent=fmtAmt(qty*price); recalcAll();
}
function removeItem(btn){ btn.closest('tr').remove(); recalcAll(); }
function recalcAll(){
  var total=0; document.querySelectorAll('#itemsBody .total-cell').forEach(function(c){total+=parseFloat(c.dataset.val)||0;});
  document.getElementById('subtotalDisplay').textContent=fmtAmt(total);
  document.getElementById('totalDisplay').textContent=fmtAmt(total);
}
function fmtAmt(n){ var sym=document.getElementById('currency').value==='EUR'?'€':'$'; return sym+parseFloat(n).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}); }
function escHtml(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function escJs(s){ return String(s).replace(/'/g,"\\'").replace(/\n/g,' '); }

// Pre-load existing items
<?php foreach ($items as $item): ?>
addItem(<?= json_encode($item['description']) ?>, <?= (float)$item['quantity'] ?>, <?= (float)$item['unit_price'] ?>);
<?php endforeach; ?>
</script>

<?php include 'includes/footer.php'; ?>
