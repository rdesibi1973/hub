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

$requests = $db->query("
    SELECT r.id, r.practice_code, r.customer_name, r.date_received, r.status,
           r.pax, a.name AS agent_name
    FROM   requests r LEFT JOIN agents a ON r.agent_id = a.id
    ORDER  BY r.date_received DESC")->fetchAll();

// Current linked request info
$linkedReq = null;
if ($inv['request_id']) {
    $rs = $db->prepare("SELECT r.id, r.customer_name, r.practice_code, r.date_received, r.status, a.name AS agent_name
                        FROM requests r LEFT JOIN agents a ON r.agent_id=a.id WHERE r.id=?");
    $rs->execute([$inv['request_id']]);
    $linkedReq = $rs->fetch();
}

$billToList = $db->query("
    SELECT id, name, 'customer' AS source_type,
           CONCAT_WS(', ', NULLIF(address,''), NULLIF(city,''), NULLIF(country,'')) AS addr
    FROM customers WHERE active = 1
    UNION ALL
    SELECT id, nome AS name, 'agency' AS source_type,
           COALESCE(address, '') AS addr
    FROM agencies WHERE attiva = 1
    ORDER BY name ASC
")->fetchAll();

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
<input type="hidden" name="request_id" id="hidReqId" value="<?= (int)($inv['request_id'] ?? 0) ?>">

<!-- Link to request -->
<div class="form-card" style="margin-bottom:20px">
  <div class="section-label" style="margin-bottom:16px">Linked request</div>

  <div id="reqLinked" style="<?= $linkedReq ? '' : 'display:none' ?>;background:#eef6ff;border:1.5px solid #b3d4f7;border-radius:8px;padding:10px 14px;margin-bottom:10px">
    <div style="display:flex;align-items:center;justify-content:space-between">
      <div>
        <strong id="reqLinkedName" style="font-size:.9rem"><?= $linkedReq ? h($linkedReq['customer_name'].($linkedReq['practice_code']?' · '.$linkedReq['practice_code']:'')) : '' ?></strong>
        <div id="reqLinkedSub" style="font-size:.75rem;color:var(--grey-mid)"><?= $linkedReq ? h(implode(' · ', array_filter([$linkedReq['date_received'],$linkedReq['status'],$linkedReq['agent_name']?'Agent: '.$linkedReq['agent_name']:'']))) : '' ?></div>
      </div>
      <button type="button" onclick="clearReq()" style="background:none;border:none;cursor:pointer;color:var(--grey-mid);font-size:1.1rem">✕</button>
    </div>
  </div>

  <div id="reqSearchBox" style="<?= $linkedReq ? 'display:none' : '' ?>">
    <input type="text" id="reqQ" placeholder="Search by customer name or practice code…"
           oninput="filterReq(this.value)" autocomplete="off"
           style="width:100%;padding:9px 12px;border:1.5px solid var(--grey-lt);border-radius:7px;font-size:.88rem">
    <div id="reqDrop" style="display:none;position:absolute;left:0;right:0;background:#fff;border:1.5px solid var(--grey-lt);border-radius:0 0 8px 8px;z-index:200;max-height:220px;overflow-y:auto;box-shadow:0 4px 16px rgba(0,0,0,.1)"></div>
    <div style="margin-top:6px;font-size:.75rem;color:var(--grey-mid)">Leave empty to save without linking.</div>
  </div>
</div>

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
      <label>Bill To *</label>
      <div style="position:relative;">
        <input type="text" id="billToSearch" name="bill_to_name" required autocomplete="off"
               value="<?= h($inv['bill_to_name']) ?>"
               placeholder="Type to search agencies or customers…">
        <div id="billToDrop"></div>
      </div>
      <input type="hidden" id="billToSourceType" name="bill_to_source_type" value="">
    </div>
    <div class="form-group full">
      <label>Bill To Address</label>
      <textarea name="bill_to_address" id="billToAddress" rows="3"><?= h($inv['bill_to_address'] ?? '') ?></textarea>
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
.bt-drop-item { padding:9px 14px;cursor:pointer;font-size:.85rem;border-bottom:1px solid var(--grey-lt); }
.bt-drop-item:last-child { border-bottom:none; }
.bt-drop-item:hover { background:var(--off-white); }
.bt-drop-sub { font-size:.72rem;color:var(--grey-mid); }
.bt-badge { display:inline-block;font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;padding:1px 6px;border-radius:4px;margin-right:6px;vertical-align:middle; }
.bt-badge.customer { background:#E8F0FE;color:#1D6FA4; }
.bt-badge.agency   { background:#EDE7F6;color:#6A1B9A; }
#billToDrop { display:none;position:absolute;left:0;right:0;top:100%;z-index:100;background:#fff;border:1.5px solid var(--grey-lt);border-radius:7px;box-shadow:0 4px 16px rgba(0,0,0,.12);max-height:240px;overflow-y:auto; }
</style>
<script>
var billToData = <?= json_encode(array_map(fn($r) => [
    'id'   => $r['id'],
    'name' => $r['name'],
    'type' => $r['source_type'],
    'addr' => $r['addr'],
], $billToList)) ?>;

var btSearch = document.getElementById('billToSearch');
var btDrop   = document.getElementById('billToDrop');

function escAttr(s){ return String(s).replace(/&/g,'&amp;').replace(/"/g,'&quot;'); }

btSearch.addEventListener('input', function() {
  var q = this.value.toLowerCase().trim();
  if (!q) { btDrop.style.display='none'; return; }
  var matches = billToData.filter(function(c){ return c.name.toLowerCase().includes(q); }).slice(0,10);
  if (!matches.length) { btDrop.style.display='none'; return; }
  btDrop.innerHTML = matches.map(function(c) {
    var badge = '<span class="bt-badge '+c.type+'">'+(c.type==='agency'?'Agency':'Customer')+'</span>';
    return '<div class="bt-drop-item"'
         + ' data-id="'   + c.id   + '"'
         + ' data-name="' + escAttr(c.name) + '"'
         + ' data-addr="' + escAttr(c.addr) + '"'
         + ' data-type="' + c.type + '">'
         + '<div>'+badge+escHtml(c.name)+'</div>'
         + (c.addr ? '<div class="bt-drop-sub">'+escHtml(c.addr)+'</div>' : '')
         + '</div>';
  }).join('');
  btDrop.style.display = 'block';
});

btDrop.addEventListener('mousedown', function(e) {
  var item = e.target.closest('.bt-drop-item');
  if (!item) return;
  e.preventDefault();
  btSearch.value = item.dataset.name;
  document.getElementById('billToAddress').value   = item.dataset.addr;
  document.getElementById('billToSourceType').value = item.dataset.type;
  // customer_id: set only for customer records, clear for agency
  document.getElementById('customerId').value = item.dataset.type === 'customer' ? item.dataset.id : 0;
  btDrop.style.display = 'none';
});

document.addEventListener('click', function(e){
  if (!btDrop.contains(e.target) && e.target !== btSearch) btDrop.style.display='none';
});

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


<script>
const REQUESTS_EDIT = <?= json_encode($requests) ?>;
function escH(s){return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/"/g,'&quot;');}
function filterReq(q){
  const drop=document.getElementById('reqDrop');
  if(q.trim().length<2){drop.style.display='none';return;}
  const ql=q.trim().toLowerCase();
  const ms=REQUESTS_EDIT.filter(r=>
    r.customer_name.toLowerCase().includes(ql)||(r.practice_code||'').toLowerCase().includes(ql)
  ).slice(0,25);
  if(ms.length===1){selReq(ms[0]);return;}
  drop.innerHTML=ms.length
    ?ms.map(r=>`<div onclick='selReq(${JSON.stringify(r)})' style="padding:9px 14px;cursor:pointer;border-bottom:1px solid var(--grey-lt);font-size:.85rem" onmouseenter="this.style.background='var(--off-white)'" onmouseleave="this.style.background=''">
        <strong>${escH(r.customer_name)}</strong>${r.practice_code?' <span style="color:var(--grey-mid)">· '+escH(r.practice_code)+'</span>':''}
        <div style="font-size:.75rem;color:var(--grey-mid)">${[r.date_received,r.status,r.agent_name?'Agent: '+r.agent_name:''].filter(Boolean).join(' · ')}</div>
      </div>`).join('')
    :'<div style="padding:10px 14px;font-size:.82rem;color:var(--grey-mid)">No results</div>';
  drop.style.display='block';
}
function selReq(r){
  document.getElementById('hidReqId').value=r.id;
  document.getElementById('reqLinkedName').textContent=r.customer_name+(r.practice_code?' · '+r.practice_code:'');
  document.getElementById('reqLinkedSub').textContent=[r.date_received,r.status,r.agent_name?'Agent: '+r.agent_name:''].filter(Boolean).join(' · ');
  document.getElementById('reqLinked').style.display='block';
  document.getElementById('reqSearchBox').style.display='none';
  document.getElementById('reqDrop').style.display='none';
}
function clearReq(){
  document.getElementById('hidReqId').value='';
  document.getElementById('reqLinked').style.display='none';
  document.getElementById('reqSearchBox').style.display='';
  document.getElementById('reqQ').value='';
}
document.addEventListener('click',e=>{
  if(!e.target.closest('#reqSearchBox'))document.getElementById('reqDrop').style.display='none';
});
</script>
<?php include 'includes/footer.php'; ?>
