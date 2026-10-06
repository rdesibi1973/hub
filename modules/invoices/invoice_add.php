<?php
require_once 'config.php';
require_once __DIR__ . '/includes/invoice_calc.php';
$db = db();

// ?from=excel: the lines are filled from the request's Calc Excel.
$fromExcel = ($_GET['from'] ?? '') === 'excel';
$pageTitle = $fromExcel ? 'New Invoice from Excel' : 'New Invoice';

$errors     = [];
$checkFails = [];   // failed Excel checks (key => message), shown with an "ignore" checkbox

// ── Pre-fill from request ─────────────────────────────────────────────────
$prefill = [];
$req = null;
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
        if (preg_match('/_START(\d{1,2})([A-Z]{3})(?:_MIDT\d{1,2}[A-Z]{3})?_END(\d{1,2})([A-Z]{3})(\d{4})/i', $folder, $dm)) {
            $year = (int)$dm[5];
            $startStr = sprintf('%02d-%s-%d', (int)$dm[1], strtoupper($dm[2]), $year);
            $endStr   = sprintf('%02d-%s-%d', (int)$dm[3], strtoupper($dm[4]), $year);
        }
        // Fallback: requests.period
        if ((!$startStr || !$endStr) && !empty($req['period'])) {
            $period = trim($req['period']);
            // Pattern A: "11 Jun - 18 Jun 2026"  or  "11 Jun – 18 Jun 2026"
            if (preg_match('/(\d{1,2})\s+([A-Za-z]+)\s*[-–]\s*(\d{1,2})\s+([A-Za-z]+)\s+(\d{4})/i', $period, $pm)) {
                $startStr = sprintf('%02d %s', (int)$pm[1], ucfirst(strtolower($pm[2])));
                $endStr   = sprintf('%02d %s %s', (int)$pm[3], ucfirst(strtolower($pm[4])), $pm[5]);
            }
            // Pattern B: "11-18 Jun 2026"
            elseif (preg_match('/(\d{1,2})\s*[-–]\s*(\d{1,2})\s+([A-Za-z]+)\s+(\d{4})/i', $period, $pm)) {
                $startStr = sprintf('%02d %s', (int)$pm[1], ucfirst(strtolower($pm[3])));
                $endStr   = sprintf('%02d %s %s', (int)$pm[2], ucfirst(strtolower($pm[3])), $pm[4]);
            }
            // Pattern C: use the period string as-is if it has any content
            elseif ($period !== '') {
                $startStr = $period;
                $endStr   = '';
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
        // "<customer> <N> pax <tail>": the Excel fill rebuilds it with TOT PAX.
        $tail = ' trip in ' . $dest;
        if ($startStr && $endStr) $tail .= ' from ' . $startStr . ' until ' . $endStr;
        elseif ($startStr)        $tail .= ' from ' . $startStr;
        $prefill['desc_head'] = $req['customer_name'];
        $prefill['desc_tail'] = $tail;
        $prefill['item_desc'] = $req['customer_name'] . ($req['pax'] ? ' ' . $req['pax'] . ' pax' : '') . $tail;

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
                    $prefill['tc']              = INV_AGENCY_TC;
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
    // Validation and lines: same rules as the Agent API (create_invoice).
    $prep     = inv_prepare(array_merge($_POST, ['items' => array_values((array)($_POST['items'] ?? []))]));
    $errors   = $prep['errors'];
    $items    = $prep['items'];
    $reqId    = $prep['fields']['request_id'];
    $currency = $prep['fields']['currency'];

    // ── Excel checks (request invoices): pax = TOT PAX, total = Tot price ──
    $calc = null; $ignored = [];
    if (!$errors && $reqId) {
        $rs = $db->prepare("SELECT id, customer_name, practice_code, group_folder, dropbox_url FROM requests WHERE id=?");
        $rs->execute([$reqId]);
        $reqRow = $rs->fetch(PDO::FETCH_ASSOC);
        if ($reqRow) {
            $calc   = ic_read_request($reqRow, trim($_POST['calc_sheet'] ?? ''));
            $fails  = ic_check($calc, $items, $currency);
            $ignore = array_filter((array)($_POST['ignore'] ?? []));
            $ignored = array_values(array_intersect_key($fails, $ignore));
            if (array_diff_key($fails, $ignore)) {
                $checkFails = $fails;
                $errors[]   = 'The invoice does not match the Calc Excel. Fix it, or tick the difference to ignore it and create the invoice again.';
            }
        }
    }

    if (!$errors) {
        $uid    = current_user()['id'];
        $res    = inv_create($db, $prep['fields'], $items, $uid);
        $invId  = $res['invoice_id'];
        $invNum = $res['invoice_number'];
        if ($calc !== null) ic_log($db, $invId, $reqId, $calc, $ignored, $fromExcel, $uid);

        flash("Invoice {$invNum} created.");
        header("Location: invoice_view.php?id=$invId"); exit;
    }
}

// ── Form values: what was posted (redisplay after an error), else the defaults ──
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$fv = function (string $k, $default = '') use ($isPost) {
    return $isPost ? ($_POST[$k] ?? $default) : $default;
};
if ($isPost) {
    $initItems = $items;
} elseif (!empty($prefill['item_desc'])) {
    $initItems = [['description' => $prefill['item_desc'], 'quantity' => (float)($prefill['item_qty'] ?? 1), 'unit_price' => (float)($prefill['item_price'] ?? 0)]];
} else {
    $initItems = [];
}

include 'includes/header.php';
?>

<div class="page-header">
  <div>
    <h2><?= $fromExcel ? 'New Invoice from Excel' : 'New Invoice' ?></h2>
    <div class="sub">
      <a href="invoices.php<?= $requestId ? '?request_id=' . $requestId : '' ?>" style="color:var(--grey-mid);text-decoration:none">← Invoices</a>
      <?php if ($req): ?>&nbsp;·&nbsp; <a href="../leads/request_view.php?id=<?= $requestId ?>" style="color:var(--grey-mid)"><?= h($req['customer_name']) ?></a><?php endif; ?>
    </div>
  </div>
</div>

<?php if ($errors): ?>
  <div class="flash flash-error">
    <?= implode('<br>', array_map('h', $errors)) ?>
    <?php foreach ($checkFails as $key => $msg): ?>
      <label style="display:flex;align-items:center;gap:8px;margin-top:8px;font-weight:600;cursor:pointer">
        <input type="checkbox" name="ignore[<?= h($key) ?>]" value="1" form="invForm" style="width:16px;height:16px" <?= !empty($_POST['ignore'][$key]) ? 'checked' : '' ?>>
        <?= h($msg) ?>
      </label>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<form method="POST" id="invForm">

<!-- Hidden fields -->
<input type="hidden" name="request_id"  value="<?= (int)($prefill['request_id'] ?? 0) ?>">
<input type="hidden" name="calc_sheet"  id="calcSheet" value="<?= h($fv('calc_sheet')) ?>">

<div class="form-card">

  <!-- ── Header: Issuer + Currency ── -->
  <div class="form-grid">
    <div class="form-group">
      <label>Issuer *</label>
      <select name="issuer" id="issuerSel" onchange="updateInvNum()">
        <?php foreach (INV_ISSUERS as $iss): ?>
          <option value="<?= h($iss) ?>" <?= $fv('issuer') === $iss ? 'selected' : '' ?>><?= h($iss) ?></option>
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
          <option value="<?= $c ?>" <?= $fv('currency') === $c ? 'selected' : '' ?>><?= $c ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group">
      <label>Terms</label>
      <input type="text" name="terms" value="<?= h($fv('terms', 'Due on Receipt')) ?>" list="termsList">
      <datalist id="termsList">
        <?php foreach (INV_TERMS_OPTS as $t): ?><option value="<?= h($t) ?>"><?php endforeach; ?>
      </datalist>
    </div>
    <div class="form-group">
      <label>Issue Date *</label>
      <input type="date" name="issue_date" value="<?= h($fv('issue_date', date('Y-m-d'))) ?>" required>
    </div>
    <div class="form-group">
      <label>Due Date</label>
      <input type="date" name="due_date" value="<?= h($fv('due_date')) ?>">
    </div>
  </div>

  <!-- ── Bill To ── -->
  <div class="form-section" style="margin-top:28px;">Bill To</div>
  <div class="form-grid">
    <div class="form-group full">
      <label>Bill To *</label>
      <div style="position:relative;">
        <input type="text" id="billToSearch" name="bill_to_name" required autocomplete="off"
               value="<?= h($fv('bill_to_name', $prefill['bill_to_name'] ?? '')) ?>"
               placeholder="Type to search agencies, or enter name manually…">
        <div id="billToDrop"></div>
      </div>
      <input type="hidden" id="billToSourceType" name="bill_to_source_type" value="<?= h($fv('bill_to_source_type', $prefill['bill_to_type'] ?? '')) ?>">
      <input type="hidden" id="billToSourceId"   name="bill_to_source_id"   value="<?= h($fv('bill_to_source_id', $prefill['bill_to_id'] ?? '')) ?>">
    </div>

    <div class="form-group full">
      <label>Address</label>
      <textarea name="bill_to_address" id="billToAddress" rows="3" placeholder="Auto-filled from selection, or enter manually"><?= h($fv('bill_to_address', $prefill['bill_to_address'] ?? '')) ?></textarea>
    </div>
  </div>

  <!-- ── Line Items ── -->
  <div class="form-section">Line Items</div>
  <?php if ($req): ?>
    <div id="calcPanel" class="calc-panel">📊 Reading the Calc Excel…</div>
  <?php endif; ?>
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

  <div style="display:flex;align-items:center;gap:10px;margin-bottom:20px;flex-wrap:wrap;">
    <button type="button" onclick="addItem()" class="btn btn-outline btn-sm">+ Add Line</button>
    <select id="quickAddSel" onchange="quickAdd(this)"
            style="font-size:.78rem;padding:5px 10px;border:1px solid var(--grey-lt);border-radius:6px;cursor:pointer;color:var(--grey-dk);">
      <option value="">⚡ Quick add…</option>
      <option value="single_supplement">Single Supplement</option>
      <option value="zanzibar">Zanzibar airport transfers</option>
      <option value="teenager">Teenager discount</option>
      <option value="child">Child discount</option>
    </select>
  </div>

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
      <textarea name="notes"><?= h($fv('notes', INV_DEFAULT_NOTES)) ?></textarea>
    </div>
    <div class="form-group full">
      <label>
        Terms &amp; Conditions
        <span id="tcBadge" style="display:none;margin-left:8px;font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;padding:2px 8px;border-radius:4px;background:#EDE7F6;color:#6A1B9A;vertical-align:middle;">Agency — 45 days</span>
      </label>
      <textarea name="terms_conditions" id="tcTextarea" class="tall"><?= h($fv('terms_conditions', $prefill['tc'] ?? INV_DEFAULT_TC)) ?></textarea>
    </div>
  </div>

  <!-- ── Payment follow-up ── -->
  <div class="form-section">Payment Follow-up</div>
  <div class="form-grid">
    <div class="form-group full">
      <label style="display:flex;align-items:center;gap:10px;cursor:pointer;font-weight:600;">
        <input type="checkbox" name="follow_up" id="followUpCheck" value="1" onchange="onAddFollowUpToggle()" style="width:17px;height:17px;cursor:pointer;" <?= $fv('follow_up') ? 'checked' : '' ?>>
        <span>&#9873; Flag for payment follow-up</span>
        <span style="font-weight:400;color:var(--grey-mid);font-size:.78rem;">— for extra services billed on an already-settled trip</span>
      </label>
    </div>
    <div class="form-group full" id="followUpNoteWrap" style="display:none;">
      <label>Follow-up note</label>
      <textarea name="follow_up_note" id="followUpNote" maxlength="255" rows="3" placeholder="e.g. ask for payment together with practice TRA1408…"><?= h($fv('follow_up_note')) ?></textarea>
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
.calc-panel { border:1.5px solid var(--grey-lt);background:var(--off-white);border-radius:8px;padding:10px 14px;margin-bottom:14px;font-size:.8rem;line-height:1.7; }
.calc-panel select { font-size:.78rem;padding:2px 6px;border:1px solid var(--grey-lt);border-radius:5px; }
.calc-panel .ck-ok  { color:#1A6B3A;font-weight:600; }
.calc-panel .ck-bad { color:#C0211B;font-weight:600; }
.calc-panel .ck-off { color:var(--grey-mid); }
</style>

<script>
// ── T&C constants ─────────────────────────────────────────────────────────
var TC_DEFAULT = <?= json_encode(INV_DEFAULT_TC) ?>;
var TC_AGENCY  = <?= json_encode(INV_AGENCY_TC)  ?>;

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
    return '<div class="bt-drop-item"'
         + ' data-id="'   + c.id + '"'
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
  // mousedown fires before blur; we use it so the input doesn't lose focus
  // before we can read the click target
  var item = e.target.closest('.bt-drop-item');
  if (!item) return;
  e.preventDefault();
  btSearch.value = item.dataset.name;
  document.getElementById('billToSourceId').value   = item.dataset.id;
  document.getElementById('billToSourceType').value = item.dataset.type;
  document.getElementById('billToAddress').value    = item.dataset.addr;
  btDrop.style.display = 'none';
  applyTcForType(item.dataset.type);
});

function selectBillTo(id, name, addr, type) {
  // kept for any legacy calls; normally handled via mousedown above
  btSearch.value = name;
  document.getElementById('billToSourceId').value   = id;
  document.getElementById('billToSourceType').value = type;
  document.getElementById('billToAddress').value    = addr;
  btDrop.style.display = 'none';
  applyTcForType(type);
}

function applyTcForType(type) {
  var tc    = document.getElementById('tcTextarea');
  var badge = document.getElementById('tcBadge');
  if (type === 'agency') {
    tc.value = TC_AGENCY;
    badge.style.display = 'inline-block';
  } else {
    tc.value = TC_DEFAULT;
    badge.style.display = 'none';
  }
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

function quickAdd(sel) {
  var v = sel.value;
  sel.value = '';           // reset dropdown
  if (!v) return;
  if (v === 'single_supplement') { addItem('Single Supplement', 1, ''); return; }
  if (v === 'zanzibar')  { addItem('Zanzibar airport transfers', 1, ''); return; }
  if (v === 'teenager')  { addItem('Teenager discount', 1, ''); return; }
  if (v === 'child')     { addItem('Child discount',    1, ''); return; }
}

function addItem(desc, qty, price, lockQty) {
  desc  = desc  !== undefined ? desc  : '';
  qty   = qty   !== undefined ? qty   : 1;
  price = price !== undefined ? price : '';
  lockQty = lockQty || false;
  var tbody = document.getElementById('itemsBody');
  var i = itemIdx++;
  var tr = document.createElement('tr');
  var qtyAttrs = lockQty
    ? 'value="1" step="1" min="1" max="1" readonly style="width:48px;background:var(--off-white);color:var(--grey-mid);"'
    : 'value="'+qty+'" step="1" min="1"';
  var pricePlaceholder = lockQty ? 'Neg. amount' : 'Neg. for discount';
  var priceStyle = lockQty ? 'color:#C0211B;' : '';
  tr.innerHTML =
    '<td style="padding:4px 4px 4px 0;vertical-align:top"><textarea class="desc-input" name="items['+i+'][description]" rows="2" placeholder="Description" required style="width:100%;resize:vertical;min-height:38px;">'+escHtml(desc)+'</textarea></td>'
   +'<td style="vertical-align:top"><input type="number" class="qty-input" name="items['+i+'][quantity]" '+qtyAttrs+'></td>'
   +'<td style="vertical-align:top"><input type="number" class="price-input" name="items['+i+'][unit_price]" value="'+price+'" step="0.01" placeholder="'+pricePlaceholder+'" style="'+priceStyle+'"></td>'
   +'<td class="total-cell" style="vertical-align:top" data-val="'+(qty*(price||0))+'">'+fmtAmt(qty*(price||0))+'</td>'
   +'<td style="text-align:center;vertical-align:top"><button type="button" onclick="removeItem(this)" class="btn btn-danger btn-sm" title="Remove">✕</button></td>';
  tr.querySelector('.qty-input').addEventListener('input', calcRow);
  tr.querySelector('.price-input').addEventListener('input', calcRow);
  tr.querySelector('.desc-input').addEventListener('input', updateCalcCheck);
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
  updateCalcCheck();
}

function fmtAmt(n) {
  var sym = document.getElementById('currency').value === 'EUR' ? '€' : '$';
  return sym + parseFloat(n).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});
}

function escHtml(s)  { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function escAttr(s)  { return String(s).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/\n/g,' '); }
function escJs(s)    { return String(s).replace(/'/g,"\\'").replace(/\n/g,' '); }
function ucfirst(s) { return s.charAt(0).toUpperCase()+s.slice(1); }
function onAddFollowUpToggle() {
  var checked = document.getElementById('followUpCheck').checked;
  document.getElementById('followUpNoteWrap').style.display = checked ? 'block' : 'none';
}

// Init TC badge if page was pre-filled with an agency (the T&C text is already set)
(function() {
  if (document.getElementById('billToSourceType').value === 'agency') {
    document.getElementById('tcBadge').style.display = 'inline-block';
  }
})();
onAddFollowUpToggle();

// ── Calc Excel: values, live check, fill lines ────────────────────────────
// The server re-checks on Create (pax = TOT PAX, total = Tot price in USD).
var CALC_REQ  = <?= $req ? (int)$requestId : 0 ?>;
var CALC_FILL = <?= ($fromExcel && !$isPost) ? 'true' : 'false' ?>;   // fill the lines once loaded
var DESC_HEAD = <?= json_encode($prefill['desc_head'] ?? '') ?>;
var DESC_TAIL = <?= json_encode($prefill['desc_tail'] ?? '') ?>;
var calcData  = null;

function money0(n) {
  n = parseFloat(n);
  return '$' + n.toLocaleString('en-US', {minimumFractionDigits: n % 1 ? 2 : 0, maximumFractionDigits: 2});
}

function loadCalc(sheet) {
  var panel = document.getElementById('calcPanel');
  if (!CALC_REQ || !panel) return;
  panel.innerHTML = '📊 Reading the Calc Excel…';
  fetch('ajax_calc.php?request_id=' + CALC_REQ + '&sheet=' + encodeURIComponent(sheet || ''))
    .then(function(r) { return r.json(); })
    .then(function(d) {
      calcData = d;
      if (d.status === 'ok') document.getElementById('calcSheet').value = d.sheet;
      renderCalc();
      if (d.status === 'ok' && CALC_FILL) fillFromExcel();
    })
    .catch(function(e) { panel.innerHTML = '⚠ Could not read the Calc Excel: ' + escHtml(String(e)); });
}

function sheetPicker(d) {
  if (!d.sheets || d.sheets.length < 2) return '<strong>' + escHtml(d.sheet) + '</strong>';
  var cur = d.status === 'ok' ? d.sheet : '';
  return '<select onchange="loadCalc(this.value)">'
    + (cur ? '' : '<option value="">— choose the sheet —</option>')
    + d.sheets.map(function(s) {
        return '<option value="' + escAttr(s) + '"' + (s === cur ? ' selected' : '') + '>' + escHtml(s) + '</option>';
      }).join('')
    + '</select>';
}

function renderCalc() {
  var d = calcData, panel = document.getElementById('calcPanel');
  if (!d || !panel) return;
  if (d.status === 'ambiguous') {
    panel.innerHTML = '📊 <strong>' + escHtml(d.file) + '</strong> · ' + escHtml(d.msg) + ' ' + sheetPicker(d);
    return;
  }
  if (d.status !== 'ok') { panel.innerHTML = '⚠ Calc Excel not checked — ' + escHtml(d.msg || 'not readable'); return; }
  var mix = [];
  if (d.teen)  mix.push(d.teen + ' teen');
  if (d.child) mix.push(d.child + ' child');
  panel.innerHTML =
      '📊 <strong>' + escHtml(d.file) + '</strong> · sheet ' + sheetPicker(d)
    + ' &nbsp;<a href="#" onclick="fillFromExcel();return false" title="Replace the lines with the Excel values">↻ Fill lines from Excel</a><br>'
    + 'TOT PAX <strong>' + (d.pax === null ? '?' : d.pax) + '</strong>' + (mix.length ? ' (' + d.adults + ' adults, ' + mix.join(', ') + ')' : '')
    + ' · Price to customer <strong>' + (d.price_pp === null ? '?' : money0(d.price_pp)) + '</strong>'
    + ' · Tot price <strong>' + (d.total === null ? '?' : money0(d.total)) + '</strong>'
    + '<div id="calcCheck"></div>';
  updateCalcCheck();
}

// Same rules as ic_check(): the first line is the trip line.
function updateCalcCheck() {
  var box = document.getElementById('calcCheck');
  if (!box || !calcData || calcData.status !== 'ok') return;
  var d = calcData, out = [];
  var row = document.querySelector('#itemsBody tr');
  var qty = row ? parseInt(row.querySelector('.qty-input').value, 10) || 0 : 0;
  var m   = row ? row.querySelector('.desc-input').value.match(/(\d+)\s*pax\b/i) : null;
  var dPax = m ? parseInt(m[1], 10) : null;
  if (d.pax !== null && qty === d.pax && dPax === d.pax) out.push('<span class="ck-ok">✓ Pax ' + d.pax + '</span>');
  else out.push('<span class="ck-bad">✗ Pax: Excel ' + (d.pax === null ? '?' : d.pax) + ', invoice qty ' + qty
              + (dPax === null ? ', no "N pax" in the description' : ', description ' + dPax + ' pax') + '</span>');
  if (document.getElementById('currency').value !== 'USD') {
    out.push('<span class="ck-off">Total not checked (the Excel is in USD)</span>');
  } else {
    var sum = 0;
    document.querySelectorAll('#itemsBody .total-cell').forEach(function(c) { sum += parseFloat(c.dataset.val) || 0; });
    if (d.total !== null && Math.abs(sum - d.total) <= 1) out.push('<span class="ck-ok">✓ Total ' + money0(d.total) + '</span>');
    else out.push('<span class="ck-bad">✗ Total: Excel ' + (d.total === null ? '?' : money0(d.total)) + ', invoice ' + money0(sum) + '</span>');
  }
  box.innerHTML = out.join(' &nbsp;·&nbsp; ');
}

// Trip line: TOT PAX × Price to customer (else Tot price / pax); teen / child
// discount lines with their count, rate left to fill in.
function fillFromExcel() {
  var d = calcData;
  if (!d || d.status !== 'ok') return;
  CALC_FILL = true;   // keep filling when the sheet is changed
  var pax  = d.pax || 1;
  var rate = d.price_pp !== null ? d.price_pp : (d.total !== null ? Math.round(d.total / pax * 100) / 100 : '');
  document.getElementById('itemsBody').innerHTML = '';
  addItem(DESC_HEAD + ' ' + pax + ' pax' + DESC_TAIL, pax, rate);
  if (d.teen)  addItem('Teenager discount', d.teen, '');
  if (d.child) addItem('Child discount', d.child, '');
}

// Lines: the posted ones after an error, else the request pre-fill (replaced by
// the Excel values in "from Excel" mode).
<?php if ($initItems): ?>
  <?php foreach ($initItems as $it): ?>
addItem(<?= json_encode($it['description']) ?>, <?= json_encode((float)$it['quantity']) ?>, <?= json_encode((float)$it['unit_price']) ?>);
  <?php endforeach; ?>
<?php else: ?>
addItem();
<?php endif; ?>
loadCalc(document.getElementById('calcSheet').value);
</script>

<?php include 'includes/footer.php'; ?>
