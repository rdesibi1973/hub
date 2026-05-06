<?php
require_once 'config.php';
$pageTitle = 'New Invoice';
$db = db();

$errors = [];

// ── Pre-fill from request ─────────────────────────────────────────────────
$prefill = [];
$requestId = (int)($_GET['request_id'] ?? 0);
if ($requestId) {
    $s = $db->prepare("SELECT r.*, a.name AS agent_name FROM requests r LEFT JOIN agents a ON a.id=r.agent_id WHERE r.id=?");
    $s->execute([$requestId]);
    $req = $s->fetch();
    if ($req) {
        $prefill['request_id'] = $requestId;
        $prefill['item_qty']   = $req['pax'] ?: 1;
        $prefill['item_price'] = $req['value_usd'] && $req['pax'] ? round($req['value_usd'] / $req['pax'], 2) : '';

        $folder   = $req['practice_code'] ?? '';
        $monthMap = ['JAN'=>1,'FEB'=>2,'MAR'=>3,'APR'=>4,'MAY'=>5,'JUN'=>6,
                     'JUL'=>7,'AUG'=>8,'SEP'=>9,'OCT'=>10,'NOV'=>11,'DEC'=>12];

        // ── 1. Dates ──────────────────────────────────────────────────────────
        // Primary: _START{dd}{MMM}_END{dd}{MMM}{yyyy} in confirmed folder name
        $startStr = ''; $endStr = '';
        if (preg_match('/_START(\d{1,2})([A-Z]{3})_END(\d{1,2})([A-Z]{3})(\d{4})/i', $folder, $dm)) {
            $year = (int)$dm[5];
            $startStr = sprintf('%02d-%s-%d', (int)$dm[1], strtoupper($dm[2]), $year);
            $endStr   = sprintf('%02d-%s-%d', (int)$dm[3], strtoupper($dm[4]), $year);
        }
        // Fallback: requests.period (e.g. "11 Jun - 18 Jun 2026")
        if ((!$startStr || !$endStr) && !empty($req['period'])) {
            $period = $req['period'];
            if (preg_match('/(\d{1,2}\s+\w+\s*[-–]\s*\d{1,2}\s+\w+\s+\d{4})/i', $period, $pm)) {
                $parts = preg_split('/\s*[-–]\s*/', $pm[1], 2);
                if (count($parts) === 2) {
                    $startStr = trim($parts[0]);
                    $endStr   = trim($parts[1]);
                }
            }
        }

        // ── 2. Destination ────────────────────────────────────────────────────
        // These destination values all mean Tanzania for invoice purposes
        $dest = trim($req['destination'] ?? '');
        $tanzaniaValues = ['', 'trek', 'safari', 'safari+beach', 'beach', 'zanzibar safari'];
        if (in_array(strtolower($dest), $tanzaniaValues)) {
            $destTokens = [
                'TZ-KENYA'    => 'Tanzania & Kenya',
                'SOUTHAFRICA' => 'South Africa',
                'MADAGASCAR'  => 'Madagascar',
                'BOTSWANA'    => 'Botswana',
                'NAMIBIA'     => 'Namibia',
                'UGANDA'      => 'Uganda',
                'RWANDA'      => 'Rwanda',
                'KENYA'       => 'Kenya',
                'ZNZ'         => 'Zanzibar',
            ];
            $innerDest = '';
            if (preg_match('/\(([^)]+)\)/', $folder, $pm)) {
                $inner = strtoupper($pm[1]);
                foreach ($destTokens as $token => $label) {
                    if (strpos($inner, $token) !== false) { $innerDest = $label; break; }
                }
            }
            $dest = $innerDest ?: 'Tanzania';
        }

        // ── 3. Description ────────────────────────────────────────────────────
        $desc  = $req['customer_name'];
        $desc .= $req['pax'] ? ' ' . $req['pax'] . ' pax' : '';
        $desc .= ' trip in ' . $dest;
        if ($startStr && $endStr) $desc .= ' from ' . $startStr . ' to ' . $endStr;
        elseif ($startStr)        $desc .= ' from ' . $startStr;
        $prefill['item_desc'] = $desc;

        // ── 4. Bill To: try to find agency from folder parentheses ────────────
        // Folder format: CustomerName(AgencyShortName-AgentName)
        // Strip status suffix and date wrapper first to get to the base name
        $baseName = preg_replace('/_START.+$/i', '', $folder);
        $baseName = preg_replace('/^\d+_\d+[A-Z]+_/i', '', $baseName);
        $prefill['bill_to_name']    = '';
        $prefill['bill_to_id']      = '';
        $prefill['bill_to_type']    = '';
        $prefill['bill_to_address'] = '';
        if (preg_match('/\(([^)]+)\)/', $baseName, $pm)) {
            $inner = $pm[1];
            // First token before '-' is the agency short name
            $parts = explode('-', $inner);
            $agencyToken = trim($parts[0]);
            if ($agencyToken && !in_array(strtoupper($agencyToken), ['DRCT','SB'])) {
                // Look up agency by short_name (strip -PS/-LAM suffixes)
                $cleanToken = preg_replace('/-PS$|-LAM$/i', '', $agencyToken);
                $agRow = $db->prepare(
                    "SELECT id, nome, COALESCE(address,'') AS address
                     FROM agencies
                     WHERE REPLACE(LOWER(short_name),'-','') = REPLACE(LOWER(?),'-','')
                        OR REPLACE(LOWER(nome),'-','') = REPLACE(LOWER(?),'-','')
                     LIMIT 1"
                );
                $agRow->execute([$cleanToken, $cleanToken]);
                $agency = $agRow->fetch();
                if ($agency) {
                    $prefill['bill_to_name']    = $agency['nome'];
                    $prefill['bill_to_id']      = $agency['id'];
                    $prefill['bill_to_type']    = 'agency';
                    $prefill['bill_to_address'] = $agency['address'];
                }
            }
        }
    }
}

// ── Bill To: merge customers + agencies for autocomplete ─────────────────
$billToList = $db->query("
    SELECT id, name, 'customer' AS source_type,
           CONCAT_WS(', ', NULLIF(address,''), NULLIF(city,''), NULLIF(country,'')) AS addr,
           '' AS vat
    FROM customers WHERE active = 1
    UNION ALL
    SELECT id, nome AS name, 'agency' AS source_type,
           COALESCE(address, '') AS addr,
           '' AS vat
    FROM agencies WHERE attiva = 1
    ORDER BY name ASC
")->fetchAll();

// ── Handle POST ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $issuer     = $_POST['issuer']      ?? INV_ISSUERS[0];
    $currency   = $_POST['currency']    ?? 'USD';
    $billToName = trim($_POST['bill_to_name'] ?? '');
    $billToAddr = trim($_POST['bill_to_address'] ?? '');
    $customerId = (int)($_POST['customer_id'] ?? 0) ?: null;
    $reqId      = (int)($_POST['request_id'] ?? 0) ?: null;
    $issueDate  = $_POST['issue_date']  ?? date('Y-m-d');
    $dueDate    = $_POST['due_date']    ?: null;
    $terms      = trim($_POST['terms']  ?? 'Due on Receipt');
    $notes      = trim($_POST['notes']  ?? INV_DEFAULT_NOTES);
    $tc         = trim($_POST['terms_conditions'] ?? INV_DEFAULT_TC);

    if (!$billToName)                   $errors[] = 'Bill To name is required.';
    if (!in_array($issuer, INV_ISSUERS))$errors[] = 'Invalid issuer.';
    if (!in_array($currency, INV_CURRENCIES)) $errors[] = 'Invalid currency.';
    if (!$issueDate)                    $errors[] = 'Issue date is required.';

    $items = [];
    foreach ($_POST['items'] ?? [] as $item) {
        $desc  = trim($item['description'] ?? '');
        $qty   = (float)($item['quantity']   ?? 1);
        $price = (float)($item['unit_price'] ?? 0);
        if ($desc) $items[] = ['description'=>$desc,'quantity'=>$qty,'unit_price'=>$price,'line_total'=>round($qty*$price,2)];
    }
    if (empty($items)) $errors[] = 'At least one item is required.';

    if (!$errors) {
        $invNum = generate_invoice_number($db, $issuer);
        $uid    = current_user()['id'];

        $db->prepare("INSERT INTO invoices
            (invoice_number, request_id, customer_id, bill_to_name, bill_to_address,
             issuer, currency, issue_date, due_date, terms, notes, terms_conditions,
             status, created_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,'New',?)")
           ->execute([$invNum,$reqId,$customerId,$billToName,$billToAddr?:null,
                      $issuer,$currency,$issueDate,$dueDate,$terms,
                      $notes?:null,$tc?:null,$uid]);

        $invId = (int)$db->lastInsertId();

        $sort = 0;
        foreach ($items as $item) {
            $db->prepare("INSERT INTO invoice_items (invoice_id,sort_order,description,quantity,unit_price,line_total) VALUES (?,?,?,?,?,?)")
               ->execute([$invId,$sort++,$item['description'],$item['quantity'],$item['unit_price'],$item['line_total']]);
        }
        recalculate_invoice($db, $invId);
        sync_request_value($db, $invId);

        flash("Invoice {$invNum} created.");
        header("Location: invoice_view.php?id=$invId"); exit;
    }
}

include 'includes/header.php';
?>

<div class="page-header">
  <div>
    <h2>New Invoice</h2>
    <div class="sub"><a href="invoices.php" style="color:var(--grey-mid);text-decoration:none">← Invoices</a></div>
  </div>
</div>

<?php if ($errors): ?>
  <div class="flash flash-error"><?= implode('<br>', array_map('h', $errors)) ?></div>
<?php endif; ?>

<form method="POST" id="invForm">

<!-- Hidden fields -->
<input type="hidden" name="request_id"  value="<?= (int)($prefill['request_id'] ?? 0) ?>">

<div class="form-card">

  <!-- ── Header: Issuer + Currency ── -->
  <div class="form-grid">
    <div class="form-group">
      <label>Issuer *</label>
      <select name="issuer" id="issuerSel" onchange="updateInvNum()">
        <?php foreach (INV_ISSUERS as $iss): ?>
          <option value="<?= h($iss) ?>"><?= h($iss) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group">
      <label>Invoice Number</label>
      <input type="text" id="invNumDisplay" value="(auto-generated)" readonly
             style="background:var(--off-white);color:var(--grey-mid);cursor:default">
    </div>
    <div class="form-group">
      <label>Currency *</label>
      <select name="currency" id="currency" onchange="recalcAll()">
        <?php foreach (INV_CURRENCIES as $c): ?>
          <option value="<?= $c ?>"><?= $c ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group">
      <label>Terms</label>
      <input type="text" name="terms" value="Due on Receipt" list="termsList">
      <datalist id="termsList">
        <?php foreach (INV_TERMS_OPTS as $t): ?><option value="<?= h($t) ?>"><?php endforeach; ?>
      </datalist>
    </div>
    <div class="form-group">
      <label>Issue Date *</label>
      <input type="date" name="issue_date" value="<?= date('Y-m-d') ?>" required>
    </div>
    <div class="form-group">
      <label>Due Date</label>
      <input type="date" name="due_date">
    </div>
  </div>

  <!-- ── Bill To ── -->
  <div class="form-section" style="margin-top:28px;">Bill To</div>
  <div class="form-grid">
    <div class="form-group full">
      <label>Bill To *</label>
      <div style="position:relative;">
        <input type="text" id="billToSearch" name="bill_to_name" required autocomplete="off"
               value="<?= h($prefill['bill_to_name'] ?? '') ?>"
               placeholder="Type to search agencies, or enter name manually…">
        <div id="billToDrop"></div>
      </div>
      <input type="hidden" id="billToSourceType" name="bill_to_source_type" value="<?= h($prefill['bill_to_type'] ?? '') ?>">
      <input type="hidden" id="billToSourceId"   name="bill_to_source_id"   value="<?= h($prefill['bill_to_id'] ?? '') ?>">
    </div>

    <div class="form-group full">
      <label>Address</label>
      <textarea name="bill_to_address" id="billToAddress" rows="3" placeholder="Auto-filled from selection, or enter manually"><?= h($prefill['bill_to_address'] ?? '') ?></textarea>
    </div>
  </div>

  <!-- ── Line Items ── -->
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

  <!-- ── Totals ── -->
  <div style="display:flex;justify-content:flex-end;margin-bottom:28px;">
    <div class="totals-box">
      <div class="totals-row"><span>Sub Total</span><span id="subtotalDisplay">$0.00</span></div>
      <div class="totals-row total"><span>Total</span><span id="totalDisplay">$0.00</span></div>
    </div>
  </div>

  <!-- ── Notes + T&C ── -->
  <div class="form-section">Notes &amp; Terms</div>
  <div class="form-grid">
    <div class="form-group full">
      <label>Notes (shown on invoice)</label>
      <textarea name="notes"><?= h(INV_DEFAULT_NOTES) ?></textarea>
    </div>
    <div class="form-group full">
      <label>Terms &amp; Conditions</label>
      <textarea name="terms_conditions" class="tall"><?= h(INV_DEFAULT_TC) ?></textarea>
    </div>
  </div>

  <div class="form-actions">
    <button type="submit" class="btn btn-red">Create Invoice</button>
    <a href="invoices.php" class="btn btn-grey">Cancel</a>
  </div>

</div><!-- /form-card -->
</form>

<style>
#billToSearch { width:100%;padding:9px 13px;border:1.5px solid var(--grey-lt);border-radius:7px;font-family:inherit;font-size:.85rem; }
#billToSearch:focus { outline:none;border-color:var(--red); }
#billToDrop { display:none;position:absolute;z-index:100;background:#fff;border:1.5px solid var(--grey-lt);border-radius:7px;box-shadow:0 4px 16px rgba(0,0,0,.12);max-height:240px;overflow-y:auto;width:100%;top:100%;left:0; }
.bt-drop-item { padding:9px 14px;cursor:pointer;font-size:.85rem;border-bottom:1px solid var(--grey-lt); }
.bt-drop-item:last-child { border-bottom:none; }
.bt-drop-item:hover { background:var(--off-white); }
.bt-drop-sub { font-size:.72rem;color:var(--grey-mid); }
.bt-badge { display:inline-block;font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;padding:1px 6px;border-radius:4px;margin-right:6px;vertical-align:middle; }
.bt-badge.customer { background:#E8F0FE;color:#1D6FA4; }
.bt-badge.agency   { background:#EDE7F6;color:#6A1B9A; }
#addBillToPanel input, #addBillToPanel select { display:none; }
</style>

<script>
// ── Bill To unified search (customers + agencies) ────────────────────────
var billToData = <?= json_encode(array_map(fn($r) => [
    'id'   => $r['id'],
    'name' => $r['name'],
    'type' => $r['source_type'],
    'addr' => $r['addr'],
], $billToList)) ?>;

var btSearch = document.getElementById('billToSearch');
var btDrop   = document.getElementById('billToDrop');

btSearch.addEventListener('input', function() {
  var q = this.value.toLowerCase().trim();
  if (!q) { btDrop.style.display='none'; return; }
  var matches = billToData.filter(function(c){ return c.name.toLowerCase().includes(q); }).slice(0,10);
  if (!matches.length) { btDrop.style.display='none'; return; }
  btDrop.innerHTML = matches.map(function(c) {
    var badge = '<span class="bt-badge '+c.type+'">'+(c.type==='agency'?'Agency':'Customer')+'</span>';
    return '<div class="bt-drop-item" onclick="selectBillTo('+c.id+',\''+escJs(c.name)+'\',\''+escJs(c.addr)+'\',\''+c.type+'\')">'
         + '<div>'+badge+escHtml(c.name)+'</div>'
         + (c.addr ? '<div class="bt-drop-sub">'+escHtml(c.addr)+'</div>' : '')
         + '</div>';
  }).join('');
  btDrop.style.display = 'block';
});

function selectBillTo(id, name, addr, type) {
  btSearch.value = name;
  document.getElementById('billToSourceId').value   = id;
  document.getElementById('billToSourceType').value = type;
  document.getElementById('billToAddress').value    = addr;
  btDrop.style.display = 'none';
}

document.addEventListener('click', function(e){
  if (!btDrop.contains(e.target) && e.target !== btSearch) btDrop.style.display='none';
});

// ── Invoice number preview ────────────────────────────────────────────────
function updateInvNum() {
  var prefix = document.getElementById('issuerSel').value.includes('Explorers') ? 'SE' : 'SH';
  document.getElementById('invNumDisplay').value = prefix + '-<?= date('Y') ?>-XXXX (auto)';
}
updateInvNum();

// ── Items table ───────────────────────────────────────────────────────────
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
   +'<td style="vertical-align:top"><input type="number" class="qty-input" name="items['+i+'][quantity]" value="'+qty+'" step="0.01" min="0.01"></td>'
   +'<td style="vertical-align:top"><input type="number" class="price-input" name="items['+i+'][unit_price]" value="'+price+'" step="0.01" placeholder="Neg. for discount"></td>'
   +'<td class="total-cell" style="vertical-align:top" data-val="'+(qty*(price||0))+'">'+fmtAmt(qty*(price||0))+'</td>'
   +'<td style="text-align:center;vertical-align:top"><button type="button" onclick="removeItem(this)" class="btn btn-danger btn-sm" title="Remove">✕</button></td>';
  tr.querySelector('.qty-input').addEventListener('input', calcRow);
  tr.querySelector('.price-input').addEventListener('input', calcRow);
  tbody.appendChild(tr);
  recalcAll();
}

function calcRow(e) {
  var row   = e.target.closest('tr');
  var qty   = parseFloat(row.querySelector('.qty-input').value)   || 0;
  var price = parseFloat(row.querySelector('.price-input').value) || 0;
  var total = qty * price;
  var cell  = row.querySelector('.total-cell');
  cell.dataset.val = total;
  cell.textContent = fmtAmt(total);
  recalcAll();
}

function removeItem(btn) {
  btn.closest('tr').remove();
  recalcAll();
}

function recalcAll() {
  var total = 0;
  document.querySelectorAll('#itemsBody .total-cell').forEach(function(c){
    var val = parseFloat(c.dataset.val) || 0;
    total += val;
    c.textContent = fmtAmt(val); // refresh symbol on currency change
  });
  document.getElementById('subtotalDisplay').textContent = fmtAmt(total);
  document.getElementById('totalDisplay').textContent    = fmtAmt(total);
}

function fmtAmt(n) {
  var sym = document.getElementById('currency').value === 'EUR' ? '€' : '$';
  return sym + parseFloat(n).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});
}

function escHtml(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function escJs(s)   { return String(s).replace(/'/g,"\\'").replace(/\n/g,' '); }
function ucfirst(s) { return s.charAt(0).toUpperCase()+s.slice(1); }

// Pre-populate if coming from a request
<?php if (!empty($prefill['item_desc'])): ?>
addItem(<?= json_encode($prefill['item_desc']) ?>, <?= (float)($prefill['item_qty'] ?? 1) ?>, <?= (float)($prefill['item_price'] ?? 0) ?>);
<?php else: ?>
addItem();
<?php endif; ?>
</script>

<?php include 'includes/footer.php'; ?>
