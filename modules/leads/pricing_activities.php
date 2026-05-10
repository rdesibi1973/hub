<?php
require_once 'config.php';
$pageTitle = 'Pricing — Jeep, Activities & Flights';
$db = db();

$cu = current_user();
if (!in_array($cu['role_name'] ?? '', ['admin','manager'])) {
    flash('Access denied.', 'error');
    header('Location: requests.php'); exit;
}

// ── AJAX actions ──────────────────────────────────────────────────────────────
$action = $_POST['action'] ?? '';
if ($action) {
    header('Content-Type: application/json');

    if ($action === 'save_jeep') {
        $id       = (int)($_POST['id'] ?? 0);
        $type     = in_array($_POST['type']??'', ['half','full','double','contribution']) ? $_POST['type'] : null;
        $validFrom= preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['valid_from']??'') ? $_POST['valid_from'] : null;
        $validTo  = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['valid_to']??'') ? $_POST['valid_to'] : null;
        $rate     = max(0, (float)($_POST['rate'] ?? 0));
        $notes    = substr(trim($_POST['notes']??''), 0, 200);
        if (!$type || !$validFrom) { echo json_encode(['ok'=>false,'error'=>'Missing fields']); exit; }
        if ($id) {
            $db->prepare("UPDATE jeep_rates SET type=?,valid_from=?,valid_to=?,rate=?,notes=? WHERE id=?")
               ->execute([$type,$validFrom,$validTo,$rate,$notes,$id]);
        } else {
            $db->prepare("INSERT INTO jeep_rates (type,valid_from,valid_to,rate,notes) VALUES (?,?,?,?,?)")
               ->execute([$type,$validFrom,$validTo,$rate,$notes]);
        }
        echo json_encode(['ok'=>true]); exit;
    }

    if ($action === 'delete_jeep') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) $db->prepare("DELETE FROM jeep_rates WHERE id=?")->execute([$id]);
        echo json_encode(['ok'=>true]); exit;
    }

    if ($action === 'save_activity') {
        $id       = (int)($_POST['id'] ?? 0);
        $name     = substr(trim($_POST['name']??''), 0, 200);
        $cat      = in_array($_POST['category']??'', ['activity','transfer','safari_fixed']) ? $_POST['category'] : null;
        $itype    = in_array($_POST['item_type']??'', ['pax','fixed']) ? $_POST['item_type'] : 'pax';
        $validFrom= preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['valid_from']??'') ? $_POST['valid_from'] : null;
        $validTo  = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['valid_to']??'') ? $_POST['valid_to'] : null;
        $rate     = max(0, (float)($_POST['rate'] ?? 0));
        $active   = (int)(!empty($_POST['active']));
        $notes    = substr(trim($_POST['notes']??''), 0, 200);
        if (!$name || !$cat || !$validFrom) { echo json_encode(['ok'=>false,'error'=>'Missing fields']); exit; }
        if ($id) {
            $db->prepare("UPDATE activity_rates SET name=?,category=?,item_type=?,valid_from=?,valid_to=?,rate=?,active=?,notes=? WHERE id=?")
               ->execute([$name,$cat,$itype,$validFrom,$validTo,$rate,$active,$notes,$id]);
        } else {
            $db->prepare("INSERT INTO activity_rates (name,category,item_type,valid_from,valid_to,rate,active,notes) VALUES (?,?,?,?,?,?,?,?)")
               ->execute([$name,$cat,$itype,$validFrom,$validTo,$rate,$active,$notes]);
        }
        echo json_encode(['ok'=>true]); exit;
    }

    if ($action === 'delete_activity') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) $db->prepare("DELETE FROM activity_rates WHERE id=?")->execute([$id]);
        echo json_encode(['ok'=>true]); exit;
    }

    if ($action === 'save_flight') {
        $id         = (int)($_POST['id'] ?? 0);
        $routeName  = substr(trim($_POST['route_name']??''), 0, 200);
        $origin     = substr(trim($_POST['origin']??''), 0, 100);
        $dest       = substr(trim($_POST['destination']??''), 0, 100);
        $airline    = substr(trim($_POST['airline']??''), 0, 100);
        $validFrom  = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['valid_from']??'') ? $_POST['valid_from'] : null;
        $validTo    = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['valid_to']??'') ? $_POST['valid_to'] : null;
        $ratePax    = max(0, (float)($_POST['rate_pax'] ?? 0));
        $active     = (int)(!empty($_POST['active']));
        $notes      = substr(trim($_POST['notes']??''), 0, 200);
        if (!$routeName || !$validFrom) { echo json_encode(['ok'=>false,'error'=>'Missing fields']); exit; }
        if ($id) {
            $db->prepare("UPDATE flight_routes SET route_name=?,origin=?,destination=?,airline=?,valid_from=?,valid_to=?,rate_pax=?,active=?,notes=? WHERE id=?")
               ->execute([$routeName,$origin,$dest,$airline,$validFrom,$validTo,$ratePax,$active,$notes,$id]);
        } else {
            $db->prepare("INSERT INTO flight_routes (route_name,origin,destination,airline,valid_from,valid_to,rate_pax,active,notes) VALUES (?,?,?,?,?,?,?,?,?)")
               ->execute([$routeName,$origin,$dest,$airline,$validFrom,$validTo,$ratePax,$active,$notes]);
        }
        echo json_encode(['ok'=>true]); exit;
    }

    if ($action === 'delete_flight') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) $db->prepare("DELETE FROM flight_routes WHERE id=?")->execute([$id]);
        echo json_encode(['ok'=>true]); exit;
    }

    echo json_encode(['ok'=>false,'error'=>'Unknown action']); exit;
}

// ── Load data ─────────────────────────────────────────────────────────────────
$jeepRates     = $db->query("SELECT * FROM jeep_rates ORDER BY type, valid_from DESC")->fetchAll(PDO::FETCH_ASSOC);
$activityRates = $db->query("SELECT * FROM activity_rates ORDER BY category, name, valid_from DESC")->fetchAll(PDO::FETCH_ASSOC);
$flightRoutes  = $db->query("SELECT * FROM flight_routes ORDER BY route_name, airline, valid_from DESC")->fetchAll(PDO::FETCH_ASSOC);

include 'includes/header.php';
?>

<style>
.pa-wrap{max-width:1100px;margin:0 auto;}
.pa-tabs{display:flex;gap:0;border-bottom:2px solid #e5e7eb;margin-bottom:20px;}
.pa-tab{padding:10px 22px;cursor:pointer;font-size:.85rem;font-weight:600;color:#6b7280;border-bottom:3px solid transparent;margin-bottom:-2px;transition:all .15s;}
.pa-tab.active{color:#C0211B;border-bottom-color:#C0211B;}
.pa-panel{display:none;}
.pa-panel.active{display:block;}
.pt{width:100%;border-collapse:collapse;font-size:.84rem;}
.pt th{background:#f9fafb;padding:8px 12px;text-align:left;font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#6b7280;border-bottom:1px solid #e5e7eb;}
.pt td{padding:8px 12px;border-bottom:1px solid #f3f4f6;vertical-align:middle;}
.pt tr:hover td{background:#fafafa;}
.btn-add{padding:8px 18px;background:#C0211B;color:#fff;border:none;border-radius:7px;cursor:pointer;font-size:.83rem;font-weight:700;}
.btn-edit{padding:4px 10px;background:#fef2f2;border:1px solid #fca5a5;color:#C0211B;border-radius:5px;cursor:pointer;font-size:.78rem;}
.btn-del{padding:4px 10px;background:#fff1f2;border:1px solid #fca5a5;color:#dc2626;border-radius:5px;cursor:pointer;font-size:.78rem;}
.card{background:#fff;border-radius:10px;box-shadow:0 1px 5px rgba(0,0,0,.07);padding:18px 20px;margin-bottom:16px;}
.fg{display:grid;gap:10px;}
.fg-2{grid-template-columns:1fr 1fr;}
.fg-3{grid-template-columns:1fr 1fr 1fr;}
.fl{display:block;font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#6b7280;margin-bottom:3px;}
.fi{width:100%;padding:7px 9px;border:1px solid #d1d5db;border-radius:6px;font-size:.86rem;box-sizing:border-box;}
.fi:focus{outline:none;border-color:#C0211B;}
.badge{display:inline-block;padding:2px 7px;border-radius:4px;font-size:.7rem;font-weight:700;}
.badge-act{background:#dbeafe;color:#1d4ed8;}
.badge-tr{background:#fef9c3;color:#a16207;}
.badge-sf{background:#fce7f3;color:#be185d;}
.badge-pax{background:#fef2f2;color:#C0211B;}
.badge-fix{background:#f9fafb;color:#6b7280;}
.badge-on{background:#fee2e2;color:#C0211B;}
.badge-off{background:#fee2e2;color:#dc2626;}
</style>

<div class="pa-wrap">
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
    <h2 style="font-size:1.1rem;font-weight:700;color:#111827;">Pricing — Jeep, Activities &amp; Flights</h2>
    <a href="pricing_lodge.php" style="font-size:.8rem;color:#C0211B;">Lodge Pricing →</a>
  </div>

  <div class="pa-tabs">
    <div class="pa-tab active" onclick="showTab('jeep')">🚙 Jeep Rates</div>
    <div class="pa-tab"       onclick="showTab('activity')">🎯 Activities &amp; Transfers</div>
    <div class="pa-tab"       onclick="showTab('flight')">✈️ Flight Routes</div>
  </div>

  <!-- ══ JEEP RATES ══ -->
  <div class="pa-panel active" id="tab-jeep">
    <div class="card">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
        <div style="font-size:.82rem;color:#6b7280;">Rate per jeep per day, by period.</div>
        <button class="btn-add" onclick="openJeep(null)">+ Add Rate</button>
      </div>
      <table class="pt">
        <thead><tr><th>Type</th><th>Valid From</th><th>Valid To</th><th>Rate/Jeep</th><th>Notes</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($jeepRates as $r): ?>
        <tr>
          <td><strong><?= h(ucfirst($r['type'])) ?></strong></td>
          <td><?= h($r['valid_from']) ?></td>
          <td><?= $r['valid_to'] ? h($r['valid_to']) : '<span style="color:#9ca3af">open</span>' ?></td>
          <td style="font-weight:700;color:#C0211B;">$<?= number_format($r['rate'],2) ?></td>
          <td style="color:#6b7280;font-size:.78rem;"><?= h($r['notes']) ?></td>
          <td style="white-space:nowrap;">
            <button class="btn-edit" onclick="openJeep(<?= json_encode($r) ?>)">Edit</button>
            <button class="btn-del"  onclick="delJeep(<?= $r['id'] ?>)">Del</button>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$jeepRates): ?><tr><td colspan="6" style="color:#9ca3af;text-align:center;">No rates yet.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- ══ ACTIVITIES & TRANSFERS ══ -->
  <div class="pa-panel" id="tab-activity">
    <div class="card">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
        <div style="font-size:.82rem;color:#6b7280;">Activities (per day), Transfers, and Safari Fixed costs (Emergency, Medivac — added once per safari).</div>
        <button class="btn-add" onclick="openActivity(null)">+ Add Rate</button>
      </div>
      <table class="pt">
        <thead><tr><th>Name</th><th>Category</th><th>Type</th><th>Valid From</th><th>Valid To</th><th>Rate</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php
        $catLabels = ['activity'=>'Activity','transfer'=>'Transfer','safari_fixed'=>'Safari Fixed'];
        $catClass  = ['activity'=>'badge-act','transfer'=>'badge-tr','safari_fixed'=>'badge-sf'];
        foreach ($activityRates as $r):
        ?>
        <tr>
          <td><strong><?= h($r['name']) ?></strong><?php if ($r['notes']): ?><br><span style="font-size:.72rem;color:#9ca3af"><?= h($r['notes']) ?></span><?php endif; ?></td>
          <td><span class="badge <?= $catClass[$r['category']] ?>"><?= $catLabels[$r['category']] ?></span></td>
          <td><span class="badge <?= $r['item_type']==='pax'?'badge-pax':'badge-fix' ?>"><?= $r['item_type']==='pax'?'$/pax':'Fixed' ?></span></td>
          <td><?= h($r['valid_from']) ?></td>
          <td><?= $r['valid_to'] ? h($r['valid_to']) : '<span style="color:#9ca3af">open</span>' ?></td>
          <td style="font-weight:700;color:#C0211B;">$<?= number_format($r['rate'],2) ?></td>
          <td><span class="badge <?= $r['active']?'badge-on':'badge-off' ?>"><?= $r['active']?'Active':'Off' ?></span></td>
          <td style="white-space:nowrap;">
            <button class="btn-edit" onclick="openActivity(<?= json_encode($r) ?>)">Edit</button>
            <button class="btn-del"  onclick="delActivity(<?= $r['id'] ?>)">Del</button>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$activityRates): ?><tr><td colspan="8" style="color:#9ca3af;text-align:center;">No rates yet.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- ══ FLIGHT ROUTES ══ -->
  <div class="pa-panel" id="tab-flight">
    <div class="card">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
        <div style="font-size:.82rem;color:#6b7280;">Internal flight routes per airline, with date-effective pricing.</div>
        <button class="btn-add" onclick="openFlight(null)">+ Add Route</button>
      </div>
      <table class="pt">
        <thead><tr><th>Route</th><th>Origin</th><th>Destination</th><th>Airline</th><th>Valid From</th><th>Valid To</th><th>$/pax</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($flightRoutes as $r): ?>
        <tr>
          <td><strong><?= h($r['route_name']) ?></strong></td>
          <td><?= h($r['origin']) ?></td>
          <td><?= h($r['destination']) ?></td>
          <td><?= h($r['airline']) ?></td>
          <td><?= h($r['valid_from']) ?></td>
          <td><?= $r['valid_to'] ? h($r['valid_to']) : '<span style="color:#9ca3af">open</span>' ?></td>
          <td style="font-weight:700;color:#C0211B;">$<?= number_format($r['rate_pax'],2) ?></td>
          <td><span class="badge <?= $r['active']?'badge-on':'badge-off' ?>"><?= $r['active']?'Active':'Off' ?></span></td>
          <td style="white-space:nowrap;">
            <button class="btn-edit" onclick="openFlight(<?= json_encode($r) ?>)">Edit</button>
            <button class="btn-del"  onclick="delFlight(<?= $r['id'] ?>)">Del</button>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$flightRoutes): ?><tr><td colspan="9" style="color:#9ca3af;text-align:center;">No routes yet.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- ══ MODALS ══ -->
<div id="modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9999;display:flex;align-items:center;justify-content:center;">
  <div id="modalBox" style="background:#fff;border-radius:12px;padding:24px 26px;width:480px;max-width:95vw;box-shadow:0 8px 40px rgba(0,0,0,.18);">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
      <h3 id="modalTitle" style="font-size:.95rem;font-weight:700;"></h3>
      <button onclick="closeModal()" style="background:none;border:none;font-size:1.3rem;cursor:pointer;color:#9ca3af;">×</button>
    </div>
    <div id="modalBody"></div>
    <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:18px;">
      <button onclick="closeModal()" style="padding:8px 18px;border:1px solid #d1d5db;border-radius:7px;background:#fff;cursor:pointer;font-size:.84rem;">Cancel</button>
      <button id="modalSave" style="padding:8px 20px;background:#C0211B;color:#fff;border:none;border-radius:7px;cursor:pointer;font-size:.84rem;font-weight:700;">Save</button>
    </div>
  </div>
</div>

<script>
document.getElementById('modal').style.display = 'none';

function showTab(t) {
  document.querySelectorAll('.pa-tab').forEach(function(el,i){
    el.classList.toggle('active', ['jeep','activity','flight'][i]===t);
  });
  document.querySelectorAll('.pa-panel').forEach(function(el){
    el.classList.remove('active');
  });
  document.getElementById('tab-'+t).classList.add('active');
}

function openModal(title, bodyHtml, saveFn) {
  document.getElementById('modalTitle').textContent = title;
  document.getElementById('modalBody').innerHTML = bodyHtml;
  document.getElementById('modal').style.display = 'flex';
  document.getElementById('modalSave').onclick = saveFn;
}
function closeModal() { document.getElementById('modal').style.display = 'none'; }

function fv(id) { var e = document.getElementById(id); return e ? e.value : ''; }
function fi(label, id, type, value, required) {
  return '<div><label class="fl">'+label+(required?' *':'')+'</label>'
       + '<input class="fi" id="'+id+'" type="'+(type||'text')+'" value="'+esc(value||'')+'"'+(required?' required':'')+'/></div>';
}
function fs(label, id, options, value) {
  var opts = options.map(function(o){ return '<option value="'+o.v+'"'+(o.v==value?' selected':'')+'>'+o.l+'</option>'; }).join('');
  return '<div><label class="fl">'+label+'</label><select class="fi" id="'+id+'">'+opts+'</select></div>';
}
function fc(label, id, value) {
  return '<div style="display:flex;align-items:center;gap:6px;padding-top:18px;"><input type="checkbox" id="'+id+'"'+(value?' checked':'')+'> <label for="'+id+'" style="font-size:.84rem;">'+label+'</label></div>';
}
function esc(s) { return String(s||'').replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;'); }

function post(data, onOk) {
  fetch('pricing_activities.php', {
    method:'POST', body: new URLSearchParams(data)
  }).then(function(r){return r.json();}).then(function(res){
    if (res.ok) { closeModal(); location.reload(); }
    else alert('Error: ' + (res.error||'unknown'));
  }).catch(function(e){ alert('Network error: '+e); });
}

// ── Jeep ──
function openJeep(r) {
  var body = '<div class="fg fg-2">'
    + fs('Type','j_type',[
        {v:'half',l:'Half day'},{v:'full',l:'Full day'},
        {v:'double',l:'Double (2 jeeps)'},{v:'contribution',l:'Contribution'}
      ], r ? r.type : 'full')
    + fi('Rate $/jeep','j_rate','number', r ? r.rate : '', true)
    + fi('Valid From','j_from','date', r ? r.valid_from : '', true)
    + fi('Valid To','j_to','date', r ? (r.valid_to||'') : '')
    + '</div>'
    + '<div style="margin-top:10px">'+fi('Notes','j_notes','text', r ? r.notes : '')+'</div>';
  openModal(r ? 'Edit Jeep Rate' : 'New Jeep Rate', body, function() {
    post({action:'save_jeep', id: r ? r.id : 0,
      type:fv('j_type'), rate:fv('j_rate'), valid_from:fv('j_from'),
      valid_to:fv('j_to'), notes:fv('j_notes')});
  });
}
function delJeep(id) {
  if (!confirm('Delete this jeep rate?')) return;
  post({action:'delete_jeep', id:id});
}

// ── Activity ──
function openActivity(r) {
  var body = '<div class="fg fg-2">'
    + fi('Name','a_name','text', r ? r.name : '', true)
    + fs('Category','a_cat',[
        {v:'activity',l:'Activity (per day)'},
        {v:'transfer',l:'Transfer'},
        {v:'safari_fixed',l:'Safari Fixed (Emergency, Medivac…)'}
      ], r ? r.category : 'activity')
    + fs('Pricing','a_itype',[{v:'pax',l:'$/pax'},{v:'fixed',l:'Fixed total'}], r ? r.item_type : 'pax')
    + fi('Rate $','a_rate','number', r ? r.rate : '', true)
    + fi('Valid From','a_from','date', r ? r.valid_from : '', true)
    + fi('Valid To','a_to','date', r ? (r.valid_to||'') : '')
    + '</div>'
    + '<div class="fg fg-2" style="margin-top:10px;">'
    + fi('Notes','a_notes','text', r ? r.notes : '')
    + fc('Active','a_active', r ? r.active : true)
    + '</div>';
  openModal(r ? 'Edit Activity Rate' : 'New Activity Rate', body, function() {
    post({action:'save_activity', id: r ? r.id : 0,
      name:fv('a_name'), category:fv('a_cat'), item_type:fv('a_itype'),
      rate:fv('a_rate'), valid_from:fv('a_from'), valid_to:fv('a_to'),
      notes:fv('a_notes'), active: document.getElementById('a_active').checked ? 1 : 0});
  });
}
function delActivity(id) {
  if (!confirm('Delete this activity rate?')) return;
  post({action:'delete_activity', id:id});
}

// ── Flight ──
function openFlight(r) {
  var body = '<div class="fg fg-2">'
    + fi('Route Name','f_name','text', r ? r.route_name : '', true)
    + fi('Airline','f_airline','text', r ? r.airline : '')
    + fi('Origin','f_origin','text', r ? r.origin : '')
    + fi('Destination','f_dest','text', r ? r.destination : '')
    + fi('Rate $/pax','f_rate','number', r ? r.rate_pax : '', true)
    + fi('Valid From','f_from','date', r ? r.valid_from : '', true)
    + fi('Valid To','f_to','date', r ? (r.valid_to||'') : '')
    + fc('Active','f_active', r ? r.active : true)
    + '</div>'
    + '<div style="margin-top:10px">'+fi('Notes','f_notes','text', r ? r.notes : '')+'</div>';
  openModal(r ? 'Edit Flight Route' : 'New Flight Route', body, function() {
    post({action:'save_flight', id: r ? r.id : 0,
      route_name:fv('f_name'), airline:fv('f_airline'),
      origin:fv('f_origin'), destination:fv('f_dest'),
      rate_pax:fv('f_rate'), valid_from:fv('f_from'), valid_to:fv('f_to'),
      notes:fv('f_notes'), active: document.getElementById('f_active').checked ? 1 : 0});
  });
}
function delFlight(id) {
  if (!confirm('Delete this flight route?')) return;
  post({action:'delete_flight', id:id});
}

// Close modal on backdrop click
document.getElementById('modal').addEventListener('click', function(e){
  if (e.target === this) closeModal();
});
</script>

<?php include 'includes/footer.php'; ?>
