<?php
require_once 'config.php';
$pageTitle = 'New Quote';
$db = db();

$isStaff      = isLeadsRestricted();
$staffAgentId = $isStaff ? getStaffAgentId() : 0;

// Require a linked request
$requestId = (int)($_GET['request_id'] ?? 0);
if (!$requestId) {
    flash('Please open a request first, then click "New Quote".', 'error');
    header('Location: requests.php'); exit;
}

// Load request — enforce staff ownership
$stmt = $db->prepare("
    SELECT r.*, a.name AS agent_name
    FROM   requests r
    LEFT JOIN agents a ON a.id = r.agent_id
    WHERE  r.id = ?
");
$stmt->execute([$requestId]);
$req = $stmt->fetch();
if (!$req) { flash('Request not found.', 'error'); header('Location: requests.php'); exit; }
if ($isStaff && (int)$req['agent_id'] !== $staffAgentId) {
    flash('Access denied.', 'error'); header('Location: requests.php'); exit;
}

// Prefill JSON for JavaScript
$prefill = json_encode([
    'customer' => $req['customer_name'] ?? '',
    'agent'    => $req['agent_name']    ?? '',
    'agency'   => '',
    'pax'      => $req['pax']           ?? '',
    'period'   => $req['period']        ?? '',
]);

// CSRF token — compatible with both leads config and main auth.php
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

include 'includes/header.php';
?>

<style>
/* ── Wizard Chrome ── */
.wiz-wrap{max-width:900px;margin:0 auto;}
.wiz-header{background:linear-gradient(135deg,#14532d,#166534);color:#fff;border-radius:12px;padding:16px 22px;margin-bottom:18px;display:flex;align-items:center;gap:14px;}
.wiz-header-icon{font-size:2.2rem;}
.wiz-header h2{font-family:'Merriweather',serif;font-size:1.15rem;font-weight:700;line-height:1.2;}
.wiz-header p{font-size:.75rem;opacity:.75;margin-top:2px;}
.wiz-prog{display:flex;gap:6px;margin-bottom:18px;}
.wiz-prog-step{flex:1;text-align:center;}
.wiz-prog-bar{height:4px;border-radius:2px;margin-bottom:5px;background:#d1d5db;transition:background .3s;}
.wiz-prog-bar.done{background:#166534;}
.wiz-prog-bar.active{background:#4ade80;}
.wiz-prog-lbl{font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:#9ca3af;}
.wiz-prog-lbl.active{color:#166534;}

/* ── Cards & Fields ── */
.wiz-card{background:#fff;border-radius:12px;padding:20px 22px;margin-bottom:14px;box-shadow:0 1px 6px rgba(0,0,0,.07);}
.wiz-card h3{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.09em;color:#6b7280;margin-bottom:14px;}
.field-grid{display:grid;gap:12px;}
.field-grid-2{grid-template-columns:1fr 1fr;}
.field-grid-3{grid-template-columns:1fr 1fr 1fr;}
.f-lbl{display:block;font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#6b7280;margin-bottom:4px;}
.f-inp{width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:.88rem;box-sizing:border-box;background:#fff;}
.f-inp:focus{outline:none;border-color:#166534;box-shadow:0 0 0 2px rgba(22,101,52,.15);}
.pax-bar{margin-top:12px;padding:10px 14px;background:#f0fdf4;border-radius:8px;font-size:.82rem;color:#166534;display:flex;gap:16px;flex-wrap:wrap;}

/* ── Template picker ── */
.tpl-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px;}
.tpl-card{background:#fff;border-radius:12px;padding:18px;cursor:pointer;box-shadow:0 1px 6px rgba(0,0,0,.07);border:2px solid transparent;transition:border-color .15s;}
.tpl-card:hover{border-color:#166534;}
.tpl-card.selected{border-color:#166534;background:#f0fdf4;}
.tpl-icon{font-size:2.4rem;margin-bottom:6px;}
.tpl-name{font-weight:700;font-size:.95rem;color:#111827;}
.tpl-desc{font-size:.78rem;color:#6b7280;margin-top:3px;}

/* ── Day cards ── */
.day-list{margin-bottom:12px;}
.day-card{background:#fff;border-radius:10px;margin-bottom:6px;box-shadow:0 1px 4px rgba(0,0,0,.07);overflow:hidden;}
.day-head{display:flex;align-items:center;padding:10px 14px;cursor:pointer;gap:10px;user-select:none;}
.day-num{background:#166534;color:#fff;border-radius:5px;padding:3px 7px;font-size:.72rem;font-weight:700;min-width:24px;text-align:center;}
.day-loc{flex:1;font-weight:600;font-size:.88rem;}
.day-lodge{font-size:.78rem;color:#6b7280;}
.day-total{font-weight:700;font-size:.88rem;color:#166534;}
.day-chevron{font-size:.7rem;color:#9ca3af;margin-left:4px;}
.day-body{padding:0 14px 14px;border-top:1px solid #f3f4f6;}
.day-fields{display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;padding-top:12px;}
.day-items{margin-top:10px;background:#f9fafb;border-radius:8px;padding:10px 12px;}
.day-items-lbl{font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#6b7280;margin-bottom:6px;}
.item-row{display:flex;gap:6px;margin-bottom:5px;align-items:center;}
.item-row .f-inp{font-size:.82rem;}
.btn-add-item{font-size:.78rem;color:#166534;background:none;border:none;cursor:pointer;padding:0;}
.btn-rm{background:none;border:none;cursor:pointer;color:#ef4444;font-size:1.1rem;padding:0 4px;line-height:1;}

/* ── Summary table ── */
.sum-table{width:100%;border-collapse:collapse;font-size:.85rem;}
.sum-table th{background:#f9fafb;padding:8px 12px;text-align:left;font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#6b7280;}
.sum-table td{padding:8px 12px;border-bottom:1px solid #f3f4f6;}
.sum-total-row{background:#166534;color:#fff;font-weight:700;font-size:1rem;}
.sum-total-row td{padding:12px;}
.kpi-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-top:14px;}
.kpi{background:#f9fafb;border-radius:8px;padding:10px 12px;border:1px solid #e5e7eb;}
.kpi.hi{background:#f0fdf4;border-color:#bbf7d0;}
.kpi-lbl{font-size:.65rem;text-transform:uppercase;letter-spacing:.05em;color:#6b7280;margin-bottom:3px;}
.kpi-val{font-weight:700;font-size:1.1rem;color:#111827;}
.kpi.hi .kpi-val{color:#166534;}

/* ── Nav buttons ── */
.wiz-nav{display:flex;justify-content:space-between;margin-top:18px;gap:10px;}
.btn-back{padding:10px 20px;border-radius:8px;border:1px solid #d1d5db;background:#fff;cursor:pointer;font-size:.88rem;color:#374151;}
.btn-next{padding:10px 24px;border-radius:8px;border:none;background:#166534;color:#fff;cursor:pointer;font-size:.88rem;font-weight:700;margin-left:auto;}
.btn-next:disabled{background:#9ca3af;cursor:not-allowed;}
.btn-save{padding:12px 28px;border-radius:8px;border:none;background:#166534;color:#fff;cursor:pointer;font-size:.95rem;font-weight:700;width:100%;}

.markup-btns{display:flex;gap:8px;flex-wrap:wrap;align-items:center;}
.mkbtn{padding:8px 16px;border-radius:8px;border:2px solid #d1d5db;background:#fff;cursor:pointer;font-size:.82rem;color:#374151;}
.mkbtn.active{border-color:#166534;background:#f0fdf4;font-weight:700;color:#166534;}
</style>

<div class="wiz-wrap">

<!-- Header -->
<div class="wiz-header">
  <div class="wiz-header-icon">🐘</div>
  <div>
    <h2>New Quote — <?= h($req['customer_name']) ?></h2>
    <p>Request #<?= h($req['practice_code'] ?? $req['id']) ?> &nbsp;·&nbsp;
       <a href="request_view.php?id=<?= $req['id'] ?>" style="color:rgba(255,255,255,.8);text-decoration:none;">← Back to request</a>
    </p>
  </div>
  <div id="progBadge" style="margin-left:auto;background:rgba(255,255,255,.15);border-radius:6px;padding:4px 12px;font-size:.82rem;display:none;"></div>
</div>

<!-- Stepper -->
<div class="wiz-prog">
  <?php foreach (['Program','Client','Itinerary','Summary'] as $i => $lbl): ?>
  <div class="wiz-prog-step">
    <div class="wiz-prog-bar" id="bar<?= $i+1 ?>"></div>
    <div class="wiz-prog-lbl" id="lbl<?= $i+1 ?>"><?= $lbl ?></div>
  </div>
  <?php endforeach; ?>
</div>

<!-- ===== STEP 1: PROGRAM ===== -->
<div id="step1">
  <div class="wiz-card">
    <h3>Select Program Template</h3>
    <div class="tpl-grid" id="tplGrid"></div>
  </div>
  <div class="wiz-nav">
    <div></div>
    <button class="btn-next" id="btnStep1Next" disabled onclick="goTo(2)">Avanti →</button>
  </div>
</div>

<!-- ===== STEP 2: CLIENT ===== -->
<div id="step2" style="display:none">
  <div class="wiz-card">
    <h3>Client &amp; Participants</h3>
    <div class="field-grid field-grid-2">
      <div><label class="f-lbl">Customer Name</label><input class="f-inp" id="fName" placeholder="e.g. Smith Family"></div>
      <div><label class="f-lbl">Start Date</label><input class="f-inp" id="fDate" type="date"></div>
      <div><label class="f-lbl">Agent</label><input class="f-inp" id="fAgent" placeholder="Agent name"></div>
      <div><label class="f-lbl">Agency</label><input class="f-inp" id="fAgency" placeholder="Agency name"></div>
    </div>
    <div style="margin-top:16px;padding-top:14px;border-top:1px solid #f3f4f6;">
      <div class="f-lbl" style="margin-bottom:10px;">Participants</div>
      <div class="field-grid field-grid-3">
        <div><label class="f-lbl">Adults</label><input class="f-inp" id="fAdults" type="number" min="0" value="2"></div>
        <div><label class="f-lbl">Teenagers</label><input class="f-inp" id="fTeens" type="number" min="0" value="0"></div>
        <div><label class="f-lbl">Children (&lt;11)</label><input class="f-inp" id="fChildren" type="number" min="0" value="0"></div>
      </div>
      <div class="pax-bar" id="paxBar">2 PAX · 1 Jeep</div>
    </div>
  </div>
  <div class="wiz-nav">
    <button class="btn-back" onclick="goTo(1)">← Indietro</button>
    <button class="btn-next" id="btnStep2Next" onclick="goTo(3)">Avanti →</button>
  </div>
</div>

<!-- ===== STEP 3: ITINERARY ===== -->
<div id="step3" style="display:none">
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;">
    <div style="font-size:.88rem;font-weight:600;color:#111827;" id="itinTitle">Itinerary</div>
    <button onclick="addDay()" style="background:#166534;color:#fff;border:none;border-radius:8px;padding:7px 14px;cursor:pointer;font-size:.82rem;font-weight:700;">+ Add Day</button>
  </div>
  <div class="day-list" id="dayList"></div>
  <div class="wiz-nav">
    <button class="btn-back" onclick="goTo(2)">← Indietro</button>
    <button class="btn-next" id="btnStep3Next" onclick="goTo(4)">Avanti →</button>
  </div>
</div>

<!-- ===== STEP 4: SUMMARY ===== -->
<div id="step4" style="display:none">
  <!-- Markup -->
  <div class="wiz-card">
    <h3>Markup</h3>
    <div class="markup-btns">
      <button class="mkbtn active" id="mkStd"  onclick="setMarkup('standard')">Standard 25%</button>
      <button class="mkbtn"        id="mkTo"   onclick="setMarkup('to')">Tour Operator 18%</button>
      <button class="mkbtn"        id="mkCust" onclick="setMarkup('custom')">Custom %</button>
      <span id="custMkWrap" style="display:none;display:flex;align-items:center;gap:6px;">
        <input id="custMkVal" type="number" value="25" min="0" max="100"
               style="width:60px;padding:7px;border:1px solid #d1d5db;border-radius:6px;font-size:.88rem;"
               oninput="renderSummary()">
        <span style="font-size:.82rem;color:#6b7280;">%</span>
      </span>
    </div>
  </div>

  <!-- Breakdown -->
  <div class="wiz-card">
    <h3>Cost Breakdown</h3>
    <table class="sum-table">
      <thead>
        <tr>
          <th>Day</th><th>Location</th><th>Lodge</th><th style="text-align:right">Cost</th>
        </tr>
      </thead>
      <tbody id="sumRows"></tbody>
      <tfoot id="sumFoot"></tfoot>
    </table>
    <div class="kpi-grid" id="kpiGrid"></div>
  </div>

  <!-- Save -->
  <div class="wiz-card" id="saveCard">
    <h3>Save Quote</h3>
    <div style="font-size:.82rem;color:#6b7280;margin-bottom:8px;">
      File name: <span id="fnamePreview" style="font-family:monospace;color:#166534;"></span>
    </div>
    <div style="font-size:.78rem;color:#9ca3af;margin-bottom:16px;" id="fnameNote"></div>
    <button class="btn-save" id="btnSave" onclick="saveQuote()">💾 Save &amp; Generate Excel</button>
  </div>

  <div class="wiz-nav">
    <button class="btn-back" onclick="goTo(3)">← Indietro</button>
  </div>
</div>

<!-- Saved confirmation -->
<div id="savedBox" style="display:none;background:#f0fdf4;border:1px solid #86efac;border-radius:12px;padding:28px;text-align:center;max-width:900px;margin:0 auto;">
  <div style="font-size:2.5rem;margin-bottom:8px;">✅</div>
  <div style="font-weight:700;color:#166534;font-size:1.1rem;margin-bottom:4px;">Quote saved!</div>
  <div id="savedFilename" style="font-size:.85rem;color:#6b7280;margin-bottom:16px;font-family:monospace;"></div>
  <div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap;">
    <button onclick="location.href='quote_view.php?id='+window._savedId"
      style="padding:9px 20px;border-radius:8px;border:none;background:#166534;color:#fff;cursor:pointer;font-size:.88rem;font-weight:700;">View Quote</button>
    <button onclick="window.open('api_export_quote.php?id='+window._savedId,'_blank')"
      style="padding:9px 20px;border-radius:8px;border:1px solid #166534;background:#fff;color:#166534;cursor:pointer;font-size:.88rem;font-weight:700;">⬇ Download Excel</button>
    <a href="request_view.php?id=<?= $req['id'] ?>"
      style="padding:9px 20px;border-radius:8px;border:1px solid #d1d5db;background:#fff;color:#374151;cursor:pointer;font-size:.88rem;text-decoration:none;">Back to Request</a>
  </div>
</div>

</div><!-- .wiz-wrap -->

<script>
// ── Data constants ────────────────────────────────────────────────────────────
const LODGES = ['Arusha Explorers HB','Marera View Lodge','Kifaru','Mvuvi Deluxe HB'];
const LODGE_PPP = {'Arusha Explorers HB':105,'Marera View Lodge':125,'Kifaru':263.5,'Mvuvi Deluxe HB':140};

const PARK_FEES = {
  none:       {l:'—',          ppp:0,   fx:0},
  tarangire:  {l:'Tarangire',  ppp:69,  fx:0},
  manyara:    {l:'Manyara',    ppp:60,  fx:0},
  serengeti1: {l:'Serengeti 1° gg', ppp:179, fx:0},
  serengeti2: {l:'Serengeti 2° gg', ppp:96,  fx:0},
  crater:     {l:'Ngorongoro Crater', ppp:83, fx:295},
  custom:     {l:'Custom',     ppp:0,   fx:0},
};
const FLIGHTS = {
  none:   {l:'—',                      ppp:0},
  znz:    {l:'Arusha → Zanzibar',      ppp:210},
  custom: {l:'Custom',                  ppp:0},
};
const TEMPLATES = {
  simba: {
    name:'Simba Safari', icon:'🦁', desc:'6 giorni · Safari Tanzania',
    days:[
      {loc:'Kili-Arusha',   lodge:'Arusha Explorers HB', jeep:'half', park:'none',       flight:'none', items:[{d:'Emergency',t:'p',a:'30'}],      drinks:true},
      {loc:'Tarangire',     lodge:'Marera View Lodge',   jeep:'full', park:'tarangire',  flight:'none', items:[{d:'Lunch boxes',t:'p',a:'10'}],    drinks:true},
      {loc:'Serengeti',     lodge:'Kifaru',              jeep:'full', park:'serengeti1', flight:'none', items:[],                                   drinks:true},
      {loc:'Serengeti',     lodge:'Kifaru',              jeep:'full', park:'serengeti2', flight:'none', items:[],                                   drinks:true},
      {loc:'Crater-Karatu', lodge:'Marera View Lodge',   jeep:'full', park:'crater',     flight:'none', items:[{d:'MEDIVAC',t:'p',a:'5'}],          drinks:true},
      {loc:'Karatu-JRO',    lodge:'',                   jeep:'full', park:'none',       flight:'none', items:[{d:'Natural Walk',t:'f',a:'20'}],    drinks:true},
    ]
  },
  beachkiboko: {
    name:'Beach Kiboko', icon:'🏖️', desc:'14 giorni · Safari + Zanzibar',
    days:[
      {loc:'Kili-Arusha',   lodge:'Arusha Explorers HB', jeep:'half', park:'none',       flight:'none', items:[{d:'Emergency',t:'p',a:'30'}],              drinks:true},
      {loc:'Tarangire',     lodge:'Marera View Lodge',   jeep:'full', park:'tarangire',  flight:'none', items:[{d:'Lunch boxes',t:'p',a:'10'}],            drinks:true},
      {loc:'Manyara',       lodge:'Marera View Lodge',   jeep:'full', park:'manyara',    flight:'none', items:[],                                            drinks:true},
      {loc:'Serengeti',     lodge:'Kifaru',              jeep:'full', park:'serengeti1', flight:'none', items:[],                                            drinks:true},
      {loc:'Serengeti',     lodge:'Kifaru',              jeep:'full', park:'serengeti2', flight:'none', items:[],                                            drinks:true},
      {loc:'Crater-Karatu', lodge:'Marera View Lodge',   jeep:'full', park:'crater',     flight:'none', items:[{d:'MEDIVAC',t:'p',a:'5'}],                  drinks:true},
      {loc:'Karatu-ZNZ',    lodge:'Mvuvi Deluxe HB',    jeep:'full', park:'none',       flight:'znz',  items:[{d:'NatWalk+Transfer',t:'f',a:'80'}],        drinks:true},
      {loc:'Zanzibar',      lodge:'Mvuvi Deluxe HB',    jeep:'none', park:'none',       flight:'none', items:[], drinks:false},
      {loc:'Zanzibar',      lodge:'Mvuvi Deluxe HB',    jeep:'none', park:'none',       flight:'none', items:[], drinks:false},
      {loc:'Zanzibar',      lodge:'Mvuvi Deluxe HB',    jeep:'none', park:'none',       flight:'none', items:[], drinks:false},
      {loc:'Zanzibar',      lodge:'Mvuvi Deluxe HB',    jeep:'none', park:'none',       flight:'none', items:[], drinks:false},
      {loc:'Zanzibar',      lodge:'Mvuvi Deluxe HB',    jeep:'none', park:'none',       flight:'none', items:[], drinks:false},
      {loc:'Zanzibar',      lodge:'Mvuvi Deluxe HB',    jeep:'none', park:'none',       flight:'none', items:[], drinks:false},
      {loc:'ZNZ-Home',      lodge:'',                   jeep:'none', park:'none',       flight:'none', items:[{d:'Transfer',t:'f',a:'60'}],                drinks:false},
    ]
  }
};

// ── State ─────────────────────────────────────────────────────────────────────
const PRE     = <?= $prefill ?>;
const BANK    = 100;
const REQ_ID  = <?= $req['id'] ?>;

var state = {
  step:    1,
  program: null,
  days:    [],
  markup:  'standard',
  custMk:  25,
};

// ── Helpers ───────────────────────────────────────────────────────────────────
function fmt(v) { return '$' + Math.round(v).toLocaleString('en-US'); }
function uid()  { return Math.random().toString(36).slice(2); }

function pax()   { return (+v('fAdults')||0) + (+v('fTeens')||0) + (+v('fChildren')||0); }
function jeeps() { var p = pax(); return p > 7 ? Math.ceil(p/7) : 1; }
function mkPct() {
  if (state.markup === 'standard') return 0.25;
  if (state.markup === 'to')       return 0.18;
  return (+v('custMkVal') / 100) || 0.25;
}

function v(id)    { var el = document.getElementById(id); return el ? el.value : ''; }
function el(id)   { return document.getElementById(id); }
function show(id) { el(id).style.display = ''; }
function hide(id) { el(id).style.display = 'none'; }
function qs(sel)  { return document.querySelector(sel); }

function calcDay(d) {
  var p = pax(), j = jeeps(), t = 0;
  if (LODGE_PPP[d.lodge]) t += LODGE_PPP[d.lodge] * p;
  else if (d.lodge === 'Custom') t += +d.lodgeCust || 0;
  if (d.jeep === 'full') t += 250 * j;
  if (d.jeep === 'half') t += 125 * j;
  if (d.drinks) t += 4 * p;
  if (d.park === 'custom') t += +d.parkCust || 0;
  else { var pk = PARK_FEES[d.park]; if (pk) t += pk.fx + pk.ppp * p; }
  if (d.flight === 'custom') t += +d.flightCust || 0;
  else { var fl = FLIGHTS[d.flight]; if (fl) t += fl.ppp * p; }
  d.items.forEach(function(a) { t += a.t === 'p' ? (+a.a||0) * p : (+a.a||0); });
  return t;
}

function calcTotals() {
  var costs = state.days.reduce(function(s,d){ return s + calcDay(d); }, 0) + BANK;
  var mk = mkPct();
  var price = costs * (1 + mk);
  var p = pax();
  return {
    costs:  costs,
    price:  price,
    ppp:    p > 0 ? price / p : 0,
    pppTo:  p > 0 ? costs * 1.18 / p : 0,
    deposit: price * 0.3,
    mk: mk,
  };
}

// ── Step navigation ───────────────────────────────────────────────────────────
function goTo(step) {
  ['step1','step2','step3','step4'].forEach(function(id,i){ el(id).style.display = i+1===step?'':'none'; });
  el('savedBox').style.display = 'none';
  state.step = step;
  ['bar1','bar2','bar3','bar4'].forEach(function(id,i){
    el(id).className = 'wiz-prog-bar' + (i+1 < step ? ' done' : i+1 === step ? ' active' : '');
    el('lbl'+(i+1)).className = 'wiz-prog-lbl' + (i+1 === step ? ' active' : '');
  });
  if (step === 3) renderDays();
  if (step === 4) renderSummary();
}

// ── Step 1: Template picker ───────────────────────────────────────────────────
(function buildTplGrid() {
  var g = el('tplGrid');
  Object.keys(TEMPLATES).forEach(function(key) {
    var t = TEMPLATES[key];
    var d = document.createElement('div');
    d.className = 'tpl-card'; d.id = 'tpl_'+key;
    d.innerHTML = '<div class="tpl-icon">'+t.icon+'</div>'
                + '<div class="tpl-name">'+t.name+'</div>'
                + '<div class="tpl-desc">'+t.desc+'</div>';
    d.onclick = function() { selectTemplate(key); };
    g.appendChild(d);
  });
  // Placeholder cards
  [['⛰️','Trekking Kili'],['✨','Custom Program']].forEach(function(x){
    var d = document.createElement('div');
    d.className = 'tpl-card'; d.style.cssText='cursor:not-allowed;opacity:.5;border-style:dashed;';
    d.innerHTML = '<div class="tpl-icon">'+x[0]+'</div><div class="tpl-name">'+x[1]+'</div><div class="tpl-desc">Coming soon</div>';
    g.appendChild(d);
  });
})();

function selectTemplate(key) {
  Object.keys(TEMPLATES).forEach(function(k){ el('tpl_'+k).className = 'tpl-card' + (k===key?' selected':''); });
  state.program = key;
  state.days = TEMPLATES[key].days.map(function(t){
    return {id:uid(), loc:t.loc, lodge:t.lodge, lodgeCust:'', jeep:t.jeep,
            park:t.park, parkCust:'', flight:t.flight, flightCust:'',
            items:t.items.map(function(a){return {id:uid(),d:a.d,t:a.t,a:a.a};}),
            drinks:t.drinks};
  });
  el('btnStep1Next').disabled = false;
  el('progBadge').style.display = '';
  el('progBadge').textContent = TEMPLATES[key].icon + ' ' + TEMPLATES[key].name;
}

// ── Step 2: Client data ───────────────────────────────────────────────────────
(function preFill() {
  if (PRE.customer) el('fName').value    = PRE.customer;
  if (PRE.agent)    el('fAgent').value   = PRE.agent;
  if (PRE.agency)   el('fAgency').value  = PRE.agency;
  ['fAdults','fTeens','fChildren','fName','fAgent','fAgency','fDate'].forEach(function(id){
    el(id).addEventListener('input', updatePaxBar);
  });
  updatePaxBar();
})();

function updatePaxBar() {
  var p = pax(), j = jeeps();
  el('paxBar').textContent = p + ' PAX · ' + j + ' Jeep' + (j>1?'s':'') + ' · Bank commission: $'+BANK;
  el('btnStep2Next').disabled = p < 1;
}

// ── Step 3: Day cards ─────────────────────────────────────────────────────────
function addDay() {
  state.days.push({id:uid(),loc:'',lodge:'',lodgeCust:'',jeep:'full',
                   park:'none',parkCust:'',flight:'none',flightCust:'',items:[],drinks:true});
  renderDays();
}

function renderDays() {
  var p = pax();
  el('itinTitle').textContent = 'Itinerary — ' + state.days.length + ' days · ' + p + ' PAX';
  var list = el('dayList');
  list.innerHTML = '';
  state.days.forEach(function(d, idx) { list.appendChild(buildDayCard(d, idx)); });
  el('btnStep3Next').disabled = state.days.length === 0;
}

function buildDayCard(d, idx) {
  var dc = calcDay(d);
  var dateStr = '';
  var sd = v('fDate');
  if (sd) {
    var dt = new Date(sd + 'T00:00:00');
    dt.setDate(dt.getDate() + idx);
    dateStr = dt.toLocaleDateString('it-IT',{day:'2-digit',month:'short'});
  }

  var wrap = document.createElement('div');
  wrap.className = 'day-card'; wrap.id = 'dc_'+d.id;

  // Build lodge options
  var lodgeOpts = '<option value="">— None</option>';
  LODGES.forEach(function(l){ lodgeOpts += '<option value="'+l+'"'+(d.lodge===l?' selected':'')+'>'+l+' · $'+LODGE_PPP[l]+'/pax</option>'; });
  lodgeOpts += '<option value="Custom"'+(d.lodge==='Custom'?' selected':'')+'>🔧 Custom (fixed total)</option>';

  // Park options
  var parkOpts = '';
  Object.keys(PARK_FEES).forEach(function(k){
    var pf = PARK_FEES[k];
    var lbl = pf.l + (k!=='none'&&k!=='custom' ? ' ('+(pf.fx?'$'+pf.fx+'+':'')+'$'+pf.ppp+'/pax)' : '');
    parkOpts += '<option value="'+k+'"'+(d.park===k?' selected':'')+'>'+lbl+'</option>';
  });

  // Flight options
  var flOpts = '';
  Object.keys(FLIGHTS).forEach(function(k){
    var fl = FLIGHTS[k];
    var lbl = fl.l + (k!=='none'&&k!=='custom' ? ' ($'+fl.ppp+'/pax)' : '');
    flOpts += '<option value="'+k+'"'+(d.flight===k?' selected':'')+'>'+lbl+'</option>';
  });

  // Items rows
  var itemsHtml = '';
  d.items.forEach(function(a){
    itemsHtml += '<div class="item-row" id="ir_'+a.id+'">'
      +'<input class="f-inp" style="flex:2" placeholder="Description" value="'+esc(a.d)+'" oninput="updItem(\''+d.id+'\',\''+a.id+'\',\'d\',this.value)">'
      +'<select class="f-inp" style="flex:1" onchange="updItem(\''+d.id+'\',\''+a.id+'\',\'t\',this.value)">'
        +'<option value="p"'+(a.t==='p'?' selected':'')+'>$/pax</option>'
        +'<option value="f"'+(a.t==='f'?' selected':'')+'>Fixed</option>'
      +'</select>'
      +'<input class="f-inp" type="number" style="width:64px" placeholder="$" value="'+esc(a.a)+'" oninput="updItem(\''+d.id+'\',\''+a.id+'\',\'a\',this.value)">'
      +'<button class="btn-rm" onclick="rmItem(\''+d.id+'\',\''+a.id+'\')">×</button>'
    +'</div>';
  });

  wrap.innerHTML =
    '<div class="day-head" onclick="toggleDay(\''+d.id+'\')">'
      +'<div class="day-num">'+(idx+1)+'</div>'
      +(dateStr?'<div style="font-size:.72rem;color:#9ca3af;width:40px;flex-shrink:0">'+dateStr+'</div>':'')
      +'<div class="day-loc">'+(d.loc||'<span style="color:#9ca3af">— New destination</span>')+'</div>'
      +'<div class="day-lodge">'+esc(d.lodge)+'</div>'
      +'<div class="day-total">'+fmt(dc)+'</div>'
      +'<div class="day-chevron" id="chev_'+d.id+'">▼</div>'
    +'</div>'
    +'<div class="day-body" id="db_'+d.id+'" style="display:'+(idx<2?'block':'none')+'">'
      +'<div class="day-fields">'
        +'<div><label class="f-lbl">Location</label>'
          +'<input class="f-inp" value="'+esc(d.loc)+'" placeholder="e.g. Serengeti" oninput="updDay(\''+d.id+'\',\'loc\',this.value)"></div>'
        +'<div><label class="f-lbl">Jeep</label>'
          +'<select class="f-inp" onchange="updDay(\''+d.id+'\',\'jeep\',this.value)">'
            +'<option value="none"'+(d.jeep==='none'?' selected':'')+'>— None</option>'
            +'<option value="half"'+(d.jeep==='half'?' selected':'')+'>Half day ($125)</option>'
            +'<option value="full"'+(d.jeep==='full'?' selected':'')+'>Full day ($250)</option>'
          +'</select></div>'
        +'<div><label class="f-lbl">Drinks &amp; Snacks</label>'
          +'<select class="f-inp" onchange="updDay(\''+d.id+'\',\'drinks\',this.value===\'y\')">'
            +'<option value="y"'+(d.drinks?' selected':'')+'>Yes ($4/pax)</option>'
            +'<option value="n"'+(!d.drinks?' selected':'')+'>No</option>'
          +'</select></div>'
        +'<div style="grid-column:1/span 2"><label class="f-lbl">Lodge</label>'
          +'<select class="f-inp" onchange="updDay(\''+d.id+'\',\'lodge\',this.value)">'+lodgeOpts+'</select>'
          +(d.lodge==='Custom'?'<input class="f-inp" type="number" style="margin-top:6px" placeholder="Fixed total $" value="'+esc(d.lodgeCust)+'" oninput="updDay(\''+d.id+'\',\'lodgeCust\',this.value)">':'')
        +'</div>'
        +'<div><label class="f-lbl">Park Fees</label>'
          +'<select class="f-inp" onchange="updDay(\''+d.id+'\',\'park\',this.value)">'+parkOpts+'</select>'
          +(d.park==='custom'?'<input class="f-inp" type="number" style="margin-top:6px" placeholder="Total fees $" value="'+esc(d.parkCust)+'" oninput="updDay(\''+d.id+'\',\'parkCust\',this.value)">':'')
        +'</div>'
        +'<div style="grid-column:1/span 2"><label class="f-lbl">Internal Flight</label>'
          +'<select class="f-inp" onchange="updDay(\''+d.id+'\',\'flight\',this.value)">'+flOpts+'</select>'
          +(d.flight==='custom'?'<input class="f-inp" type="number" style="margin-top:6px" placeholder="Total flight $" value="'+esc(d.flightCust)+'" oninput="updDay(\''+d.id+'\',\'flightCust\',this.value)">':'')
        +'</div>'
      +'</div>'
      +'<div class="day-items">'
        +'<div class="day-items-lbl">Activities &amp; Extras</div>'
        +itemsHtml
        +'<button class="btn-add-item" onclick="addItem(\''+d.id+'\')">+ Add item</button>'
      +'</div>'
      +'<div style="display:flex;justify-content:space-between;align-items:center;margin-top:10px;">'
        +'<div style="font-size:.8rem;color:#166534;font-weight:600">Day total: '+fmt(dc)+'</div>'
        +'<button onclick="removeDay(\''+d.id+'\')" style="font-size:.78rem;color:#ef4444;background:none;border:none;cursor:pointer;">🗑 Remove</button>'
      +'</div>'
    +'</div>';

  return wrap;
}

function toggleDay(id) {
  var body = el('db_'+id), chev = el('chev_'+id);
  var open = body.style.display !== 'none';
  body.style.display = open ? 'none' : '';
  chev.textContent   = open ? '▼' : '▲';
}

function updDay(did, field, val) {
  var d = state.days.find(function(x){return x.id===did;});
  if (!d) return;
  d[field] = val;
  // Re-render only this card to pick up conditional fields (custom inputs)
  var old = el('dc_'+did);
  var idx = state.days.indexOf(d);
  var newCard = buildDayCard(d, idx);
  // Preserve open/close state
  var wasOpen = el('db_'+did) && el('db_'+did).style.display !== 'none';
  old.replaceWith(newCard);
  if (!wasOpen) el('db_'+did).style.display = 'none';
}

function addItem(did) {
  var d = state.days.find(function(x){return x.id===did;});
  if (d) { d.items.push({id:uid(),d:'',t:'f',a:''}); updDay(did,'loc',d.loc); }
}
function updItem(did, iid, field, val) {
  var d = state.days.find(function(x){return x.id===did;});
  if (!d) return;
  var item = d.items.find(function(x){return x.id===iid;});
  if (item) item[field] = val;
  // Update just the day total display without full re-render
  el('dc_'+did).querySelector('.day-total').textContent = fmt(calcDay(d));
}
function rmItem(did, iid) {
  var d = state.days.find(function(x){return x.id===did;});
  if (d) { d.items = d.items.filter(function(x){return x.id!==iid;}); updDay(did,'loc',d.loc); }
}
function removeDay(did) {
  state.days = state.days.filter(function(d){return d.id!==did;});
  renderDays();
}

function esc(s) { return String(s||'').replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;'); }

// ── Step 4: Summary ───────────────────────────────────────────────────────────
function setMarkup(type) {
  state.markup = type;
  ['Std','To','Cust'].forEach(function(k){ el('mk'+k).className = 'mkbtn'+(state.markup===k.toLowerCase()?' active':''); });
  // fix: map standard→Std, to→To, custom→Cust
  var map = {standard:'Std',to:'To',custom:'Cust'};
  Object.keys(map).forEach(function(k){ el('mk'+map[k]).className = 'mkbtn'+(state.markup===k?' active':''); });
  el('custMkWrap').style.display = type==='custom' ? 'flex' : 'none';
  renderSummary();
}

function renderSummary() {
  var tot = calcTotals();
  var rows = '';
  state.days.forEach(function(d, i) {
    var dc = calcDay(d);
    rows += '<tr>'
      +'<td>'+(i+1)+'</td>'
      +'<td style="font-weight:500">'+(d.loc||'—')+'</td>'
      +'<td style="color:#6b7280">'+(d.lodge||'—')+'</td>'
      +'<td style="text-align:right;font-family:monospace;font-weight:500">'+fmt(dc)+'</td>'
      +'</tr>';
  });
  rows += '<tr style="border-top:2px solid #e5e7eb">'
    +'<td colspan="3" style="text-align:right;color:#6b7280;font-size:.8rem">Bank Commission</td>'
    +'<td style="text-align:right;font-family:monospace;color:#6b7280">'+fmt(BANK)+'</td>'
    +'</tr>';
  el('sumRows').innerHTML = rows;

  var foot = '<tr style="font-weight:700;background:#f9fafb">'
    +'<td colspan="3" style="text-align:right;padding:9px 12px">Net Total Costs</td>'
    +'<td style="text-align:right;font-family:monospace;padding:9px 12px">'+fmt(tot.costs)+'</td>'
    +'</tr>'
    +'<tr style="color:#166534">'
    +'<td colspan="3" style="text-align:right;font-size:.82rem;padding:7px 12px">Markup ('+(tot.mk*100).toFixed(0)+'%)</td>'
    +'<td style="text-align:right;font-family:monospace;font-size:.82rem;padding:7px 12px">+ '+fmt(tot.costs*tot.mk)+'</td>'
    +'</tr>'
    +'<tr class="sum-total-row">'
    +'<td colspan="3" style="text-align:right;padding:12px">TOTAL PRICE</td>'
    +'<td style="text-align:right;font-family:monospace;padding:12px">'+fmt(tot.price)+'</td>'
    +'</tr>';
  el('sumFoot').innerHTML = foot;

  var single = state.program === 'beachkiboko' ? '$650' : '$250';
  el('kpiGrid').innerHTML =
    kpiBox('Price p.p. (rack)', fmt(tot.ppp), true)
   +kpiBox('Price p.p. (T.O.)', fmt(tot.pppTo), false)
   +kpiBox('Single supplement', single, false)
   +kpiBox('Deposit (30%)', fmt(tot.deposit), false);

  // Filename preview
  var custClean = (v('fName')||'Customer').replace(/\s+/g,'');
  el('fnamePreview').textContent = '??_'+custClean+'.xlsx';
  el('fnameNote').textContent    = 'The quote number will be assigned automatically (next available).';
}
function kpiBox(lbl, val, hi) {
  return '<div class="kpi'+(hi?' hi':'')+'"><div class="kpi-lbl">'+lbl+'</div><div class="kpi-val">'+val+'</div></div>';
}

// ── Save ──────────────────────────────────────────────────────────────────────
function saveQuote() {
  var btn = el('btnSave');
  btn.disabled = true; btn.textContent = 'Saving…';

  var payload = {
    csrf:       '<?= $csrfToken ?>',
    request_id: REQ_ID,
    customer_name: v('fName'),
    agent_name:    v('fAgent'),
    agency_name:   v('fAgency'),
    start_date:    v('fDate'),
    adults:        +v('fAdults')||0,
    teens:         +v('fTeens')||0,
    children:      +v('fChildren')||0,
    program:       state.program,
    markup_type:   state.markup,
    markup_pct:    mkPct() * 100,
    bank_commission: BANK,
    days: state.days.map(function(d){
      return {
        location:     d.loc,
        lodge:        d.lodge,
        lodge_custom: +d.lodgeCust||0,
        jeep:         d.jeep,
        drinks:       d.drinks ? 1 : 0,
        park:         d.park,
        park_custom:  +d.parkCust||0,
        flight:       d.flight,
        flight_custom:+d.flightCust||0,
        day_total:    calcDay(d),
        items: d.items.map(function(a){ return {description:a.d, item_type:a.t==='p'?'pax':'fixed', amount:+a.a||0}; })
      };
    })
  };

  var tot = calcTotals();
  payload.total_costs = tot.costs;
  payload.total_price = tot.price;

  fetch('api_save_quote.php', {
    method: 'POST',
    headers: {'Content-Type':'application/json'},
    body: JSON.stringify(payload)
  })
  .then(function(r){ return r.json(); })
  .then(function(res) {
    if (!res.ok) { alert('Error: ' + (res.error||'unknown')); btn.disabled=false; btn.textContent='💾 Save & Generate Excel'; return; }
    window._savedId = res.id;
    var custClean = (v('fName')||'Customer').replace(/\s+/g,'');
    el('savedFilename').textContent = res.quote_number + '_' + custClean + '.xlsx';
    el('step4').style.display = 'none';
    el('savedBox').style.display = '';
  })
  .catch(function(e){ alert('Network error: '+e); btn.disabled=false; btn.textContent='💾 Save & Generate Excel'; });
}

// ── Init ──────────────────────────────────────────────────────────────────────
goTo(1);
</script>

<?php include 'includes/footer.php'; ?>
