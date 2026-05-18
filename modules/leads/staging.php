<?php
require_once 'config.php';
$pageTitle = 'Incoming Leads';
$db = db();

// ── Access: admin and manager only ───────────────────────────────────────────
$currentUser = current_user();
if (!in_array($currentUser['role_name'] ?? '', ['admin','manager'])) {
    flash('Access denied.', 'error');
    header('Location: requests.php'); exit;
}

// ── Counts for badge ──────────────────────────────────────────────────────────
$total    = (int)$db->query("SELECT COUNT(*) FROM lead_staging")->fetchColumn();
$nDef     = (int)$db->query("SELECT COUNT(*) FROM lead_staging WHERE dup_flag='definite'")->fetchColumn();
$nPoss    = (int)$db->query("SELECT COUNT(*) FROM lead_staging WHERE dup_flag='possible'")->fetchColumn();
$nClean   = (int)$db->query("SELECT COUNT(*) FROM lead_staging WHERE dup_flag='clean'")->fetchColumn();

// ── Filter ────────────────────────────────────────────────────────────────────
$filterDup = $_GET['dup'] ?? '';
$filterSrc = $_GET['src'] ?? '';
$where = []; $params = [];
if ($filterDup) { $where[] = 'dup_flag = ?'; $params[] = $filterDup; }
if ($filterSrc) { $where[] = 'source = ?';   $params[] = $filterSrc; }
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$leads = $db->prepare("SELECT * FROM lead_staging $whereSql ORDER BY
    CASE dup_flag WHEN 'definite' THEN 1 WHEN 'possible' THEN 2 ELSE 3 END,
    created_at DESC");
$leads->execute($params);
$leads = $leads->fetchAll();

// ── Email duplicate lookup against requests table ─────────────────────────────
$emailMatchMap = []; // email (lower) => [['id'=>...,'name'=>...], ...]
$stagedEmails  = array_filter(array_column($leads, 'email'));
if ($stagedEmails) {
    $lower   = array_values(array_map('strtolower', $stagedEmails));
    $pholds  = implode(',', array_fill(0, count($lower), '?'));
    $emRows  = $db->prepare(
        "SELECT id, customer_name, email FROM requests WHERE LOWER(email) IN ($pholds) ORDER BY id DESC"
    );
    $emRows->execute($lower);
    foreach ($emRows->fetchAll() as $r) {
        $key = strtolower($r['email']);
        $emailMatchMap[$key][] = ['id' => $r['id'], 'name' => $r['customer_name']];
    }
}
// Attach email_matches to each lead
foreach ($leads as &$lead) {
    $key = strtolower($lead['email'] ?? '');
    $lead['email_matches'] = ($key && isset($emailMatchMap[$key])) ? $emailMatchMap[$key] : [];
}
unset($lead);

$agents = $db->query("SELECT * FROM agents WHERE active=1 ORDER BY name")->fetchAll();

include 'includes/header.php';
?>
<style>
.stage-toolbar{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:20px;}
.stage-badge{display:inline-flex;align-items:center;gap:5px;padding:5px 14px;border-radius:20px;font-size:.75rem;font-weight:700;border:1.5px solid;cursor:pointer;text-decoration:none;transition:opacity .15s;}
.stage-badge:hover{opacity:.75;}
.badge-all    {background:#F3F4F6;border-color:#9CA3AF;color:#374151;}
.badge-def    {background:#FEE2E2;border-color:#C0211B;color:#991B1B;}
.badge-poss   {background:#FEF9C3;border-color:#CA8A04;color:#713F12;}
.badge-clean  {background:#D1FAE5;border-color:#059669;color:#065F46;}
.stage-table{width:100%;border-collapse:collapse;font-size:.82rem;}
.stage-table th{background:#1A1A1A;color:rgba(255,255,255,.8);padding:9px 12px;text-align:left;font-size:.65rem;text-transform:uppercase;letter-spacing:.08em;white-space:nowrap;}
.stage-table td{padding:10px 12px;border-bottom:.5px solid #E8E8E8;vertical-align:top;}
.stage-table tr:hover td{background:#FAFAFA;cursor:pointer;}
.dup-pill{display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:10px;font-size:.68rem;font-weight:700;white-space:nowrap;}
.dup-definite{background:#FEE2E2;color:#991B1B;border:1px solid #FECACA;}
.dup-possible{background:#FEF9C3;color:#713F12;border:1px solid #FDE68A;}
.dup-clean   {background:#D1FAE5;color:#065F46;border:1px solid #A7F3D0;}
.src-pill{display:inline-flex;padding:2px 8px;border-radius:10px;font-size:.68rem;font-weight:700;}
.src-Form{background:#EFF6FF;color:#1E3A8A;}
.src-iBot{background:#F5F3FF;color:#4C1D95;}
/* ── Drawer ── */
.drawer-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:900;}
.drawer-overlay.open{display:block;}
.drawer{position:fixed;top:0;right:-600px;width:min(580px,95vw);height:100vh;background:#fff;box-shadow:-4px 0 30px rgba(0,0,0,.18);z-index:901;transition:right .25s ease;overflow-y:auto;display:flex;flex-direction:column;}
.drawer.open{right:0;}
.drawer-head{background:#1A1A1A;padding:18px 22px;display:flex;align-items:center;justify-content:space-between;flex-shrink:0;}
.drawer-head h3{font-family:'Merriweather',serif;font-size:1rem;color:#fff;margin:0;}
.drawer-close{background:none;border:none;color:rgba(255,255,255,.7);font-size:1.4rem;cursor:pointer;line-height:1;}
.drawer-body{padding:22px;flex:1;}
.drawer-section{font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:#888;margin:18px 0 8px;display:flex;align-items:center;gap:6px;}
.drawer-section::before{content:'';display:inline-block;width:7px;height:7px;border-radius:50%;background:#C0211B;}
.info-grid{display:grid;grid-template-columns:120px 1fr;gap:4px 12px;font-size:.82rem;}
.info-grid dt{color:#888;font-weight:600;}
.info-grid dd{color:#1A1A1A;margin:0;}
.req-text{background:#F7F5F2;border-radius:6px;padding:12px;font-size:.8rem;white-space:pre-wrap;line-height:1.6;max-height:280px;overflow-y:auto;}
.dup-match{border-radius:8px;padding:10px 14px;margin-bottom:8px;border:1.5px solid;}
.dup-match-def{background:#FEE2E2;border-color:#FECACA;}
.dup-match-poss{background:#FEF9C3;border-color:#FDE68A;}
.dup-match a{color:inherit;font-weight:700;}
/* ── Action form ── */
.action-bar{background:#F7F5F2;border-top:1px solid #E8E8E8;padding:18px 22px;flex-shrink:0;}
.action-bar h4{font-size:.8rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#444;margin:0 0 12px;}
.action-tabs{display:flex;gap:6px;margin-bottom:14px;}
.action-tab{background:#E8E8E8;border:none;border-radius:6px;padding:7px 16px;font-size:.78rem;font-weight:700;cursor:pointer;color:#444;transition:all .15s;}
.action-tab.active,.action-tab:hover{background:#C0211B;color:#fff;}
.action-panel{display:none;}
.action-panel.active{display:block;}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px;}
.form-row.full{grid-template-columns:1fr;}
select,input[type=text]{width:100%;padding:7px 10px;border:1px solid #D1D5DB;border-radius:6px;font-family:'Open Sans',sans-serif;font-size:.82rem;}
select:focus,input:focus{outline:none;border-color:#C0211B;box-shadow:0 0 0 2px rgba(192,33,27,.12);}
.btn-approve{background:#059669;color:#fff;border:none;border-radius:6px;padding:9px 22px;font-size:.82rem;font-weight:700;cursor:pointer;}
.btn-approve:hover{background:#047857;}
.btn-dismiss{background:#6B7280;color:#fff;border:none;border-radius:6px;padding:9px 22px;font-size:.82rem;font-weight:700;cursor:pointer;}
.btn-dismiss:hover{background:#374151;}
.btn-merge{background:#2563EB;color:#fff;border:none;border-radius:6px;padding:9px 22px;font-size:.82rem;font-weight:700;cursor:pointer;}
.btn-merge:hover{background:#1D4ED8;}
.empty-state{text-align:center;padding:60px 20px;color:#888;}
.empty-state .icon{font-size:3rem;margin-bottom:12px;}
/* ── HubSpot Sync Panel ── */
.hs-panel{background:#fff;border-radius:10px;box-shadow:0 2px 12px rgba(0,0,0,.07);margin-bottom:20px;overflow:hidden;border-left:4px solid #0062B1;}
.hs-panel-head{padding:13px 20px;cursor:pointer;display:flex;align-items:center;justify-content:space-between;user-select:none;}
.hs-panel-head:hover{background:#F7F9FF;}
.hs-panel-head-left{display:flex;align-items:center;gap:10px;font-size:.82rem;}
.hs-panel-head-left strong{color:#0062B1;}
.hs-panel-head-left span{color:#888;font-size:.75rem;font-weight:400;}
.hs-panel-body{display:none;padding:16px 20px 20px;border-top:1px solid #E8E8E8;}
.hs-panel-body.open{display:block;}
.hs-btn{border:none;border-radius:6px;padding:7px 15px;font-size:.78rem;font-weight:700;cursor:pointer;font-family:'Open Sans',sans-serif;transition:background .15s;}
.hs-btn-days{background:#E8E8E8;color:#444;}
.hs-btn-days:hover,.hs-btn-days.hs-active{background:#0062B1;color:#fff;}
.hs-btn-fetch{background:#0062B1;color:#fff;}
.hs-btn-fetch:hover{background:#004D99;}
.hs-btn-import-sel{background:#059669;color:#fff;}
.hs-btn-import-sel:hover{background:#047857;}
.hs-btn-import-all{background:#065F46;color:#fff;}
.hs-btn-import-all:hover{background:#064E3B;}
.hs-preview-table{width:100%;border-collapse:collapse;font-size:.8rem;margin-top:14px;}
.hs-preview-table th{background:#F3F4F6;padding:7px 10px;text-align:left;font-size:.65rem;text-transform:uppercase;letter-spacing:.07em;color:#6B7280;white-space:nowrap;}
.hs-preview-table td{padding:8px 10px;border-bottom:.5px solid #F3F4F6;vertical-align:middle;}
.hs-preview-table tr:hover td{background:#F9FAFB;}
.hs-result-staged{display:inline-block;padding:2px 8px;border-radius:10px;font-size:.68rem;font-weight:700;background:#D1FAE5;color:#065F46;}
.hs-result-skipped{display:inline-block;padding:2px 8px;border-radius:10px;font-size:.68rem;font-weight:700;background:#F3F4F6;color:#6B7280;}
.hs-result-error{display:inline-block;padding:2px 8px;border-radius:10px;font-size:.68rem;font-weight:700;background:#FEE2E2;color:#991B1B;}
</style>

<div class="page-header">
  <div>
    <h2>Incoming Leads
      <?php if ($total): ?>
        <span style="background:#C0211B;color:#fff;border-radius:12px;padding:2px 10px;font-size:.75rem;font-weight:700;margin-left:8px;vertical-align:middle"><?= $total ?></span>
      <?php endif; ?>
    </h2>
    <div class="sub">Auto-imported from HubSpot — review before adding to Requests</div>
  </div>
</div>

<!-- HubSpot Sync Panel -->
<div class="hs-panel">
  <div class="hs-panel-head" onclick="hsTogglePanel()">
    <div class="hs-panel-head-left">
      <span>🔄</span>
      <strong>Sync from HubSpot</strong>
      <span>— fetch contacts &amp; iBot tickets not yet in staging</span>
    </div>
    <span id="hsChevron" style="color:#888;font-size:.8rem">▼</span>
  </div>
  <div class="hs-panel-body" id="hsPanelBody">
    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
      <button class="hs-btn hs-btn-days" onclick="hsFetch(1,this)">Last 24h</button>
      <button class="hs-btn hs-btn-days" onclick="hsFetch(7,this)">Last 7 days</button>
      <span style="color:#ccc">|</span>
      <input type="number" id="hsDaysInput" value="3" min="1" max="30"
             style="width:54px;padding:6px 8px;border:1px solid #D1D5DB;border-radius:6px;font-size:.78rem;text-align:center;">
      <span style="font-size:.78rem;color:#666">days</span>
      <button class="hs-btn hs-btn-fetch"
              onclick="hsFetch(parseInt(document.getElementById('hsDaysInput').value)||1,null)">Fetch</button>
      <span id="hsSpinner" style="display:none;font-size:.78rem;color:#888">&#9203; Loading&hellip;</span>
    </div>
    <div id="hsPreviewArea"></div>
  </div>
</div>

<!-- Toolbar -->
<div class="stage-toolbar">
  <a href="staging.php" class="stage-badge badge-all <?= !$filterDup&&!$filterSrc?'':'inactive' ?>">All <?= $total ?></a>
  <?php if ($nDef): ?>
    <a href="staging.php?dup=definite" class="stage-badge badge-def">🔴 Definite <?= $nDef ?></a>
  <?php endif; ?>
  <?php if ($nPoss): ?>
    <a href="staging.php?dup=possible" class="stage-badge badge-poss">🟡 Possible <?= $nPoss ?></a>
  <?php endif; ?>
  <?php if ($nClean): ?>
    <a href="staging.php?dup=clean"    class="stage-badge badge-clean">🟢 Clean <?= $nClean ?></a>
  <?php endif; ?>
  <div style="margin-left:auto;display:flex;gap:8px;">
    <select onchange="location='staging.php?src='+this.value+(<?= json_encode($filterDup?'&dup='.$filterDup:'') ?>)" style="width:auto;padding:5px 10px;font-size:.78rem;">
      <option value="">All Sources</option>
      <option value="Form" <?= $filterSrc==='Form'?'selected':'' ?>>Form</option>
      <option value="iBot" <?= $filterSrc==='iBot'?'selected':'' ?>>iBot</option>
    </select>
  </div>
</div>

<?php if (empty($leads)): ?>
  <div class="empty-state">
    <div class="icon">📭</div>
    <p>No incoming leads<?= $filterDup||$filterSrc ? ' matching this filter' : '' ?>.</p>
  </div>
<?php else: ?>
<div style="background:#fff;border-radius:10px;box-shadow:0 2px 12px rgba(0,0,0,.07);overflow:hidden;">
<table class="stage-table">
  <thead>
    <tr>
      <th>Received</th>
      <th>Name</th>
      <th>Source</th>
      <th>Pax</th>
      <th>Period</th>
      <th>Destination</th>
      <th>Dup?</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($leads as $lead): ?>
    <tr onclick="openDrawer(<?= $lead['id'] ?>)" data-id="<?= $lead['id'] ?>">
      <td style="white-space:nowrap;color:#888;font-size:.75rem"><?= date('d M', strtotime($lead['date_received'])) ?></td>
      <td><strong><?= htmlspecialchars($lead['customer_name']) ?></strong>
          <?php if ($lead['email']): ?><div style="font-size:.72rem;color:#888"><?= htmlspecialchars($lead['email']) ?></div><?php endif; ?>
      </td>
      <td><span class="src-pill src-<?= $lead['source'] ?>"><?= $lead['source'] ?></span></td>
      <td><?= $lead['pax'] ?? '—' ?></td>
      <td style="font-size:.78rem"><?= htmlspecialchars($lead['period'] ?? '—') ?></td>
      <td style="font-size:.78rem"><?= htmlspecialchars($lead['destination'] ?? '—') ?></td>
      <td>
        <?php $df=$lead['dup_flag']; ?>
        <span class="dup-pill dup-<?= $df ?>">
          <?= $df==='definite'?'🔴 Definite':($df==='possible'?'🟡 Possible':'🟢 Clean') ?>
        </span>
        <?php if (!empty($lead['email_matches'])): ?>
          <span class="dup-pill dup-definite" title="Same email already in Requests">📧 Email</span>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>

<!-- Drawer overlay -->
<div class="drawer-overlay" id="drawerOverlay" onclick="closeDrawer()"></div>
<div class="drawer" id="drawer">
  <div class="drawer-head">
    <h3 id="drawerTitle">Lead Details</h3>
    <button class="drawer-close" onclick="closeDrawer()">✕</button>
  </div>
  <div class="drawer-body" id="drawerBody">
    <!-- populated by JS -->
  </div>
  <div class="action-bar">
    <h4>Action</h4>
    <div class="action-tabs">
      <button class="action-tab active" onclick="switchTab('approve')">✅ Approve</button>
      <button class="action-tab"        onclick="switchTab('merge')">🔗 Merge</button>
      <button class="action-tab"        onclick="switchTab('dismiss')">🗑 Dismiss</button>
    </div>

    <!-- APPROVE -->
    <div class="action-panel active" id="panel-approve">
      <form method="POST" action="staging_action.php">
        <input type="hidden" name="action" value="approve">
        <input type="hidden" name="staging_id" id="approveId">
        <!-- Editable customer name -->
        <div class="form-row full" style="margin-bottom:10px;">
          <div>
            <label style="font-size:.75rem;font-weight:600;display:block;margin-bottom:4px">👤 Customer Name</label>
            <input type="text" name="customer_name_override" id="customerNameInput"
                   placeholder="Customer name" oninput="onCustomerNameInput()">
          </div>
        </div>
        <div class="form-row">
          <div>
            <label style="font-size:.75rem;font-weight:600;display:block;margin-bottom:4px">Assign Agent *</label>
            <select name="agent_id" id="approveAgent" required onchange="updateFolderPreview()">
              <option value="">— Select Agent —</option>
              <?php foreach ($agents as $ag): ?>
                <option value="<?= $ag['id'] ?>" data-name="<?= htmlspecialchars($ag['name']) ?>"><?= htmlspecialchars($ag['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label style="font-size:.75rem;font-weight:600;display:block;margin-bottom:4px">Request Type</label>
            <select name="destination" id="approveDest">
              <option value="">— Keep existing —</option>
              <?php foreach (['Safari','Kilimanjaro','Safari+Beach','Meru Trekking','Tailor-made','Other'] as $dt): ?>
                <option value="<?= $dt ?>"><?= $dt ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <!-- Editable folder name -->
        <div id="folderPreviewRow" style="margin-bottom:10px;display:none;">
          <label style="font-size:.75rem;font-weight:600;display:block;margin-bottom:4px">📁 Dropbox Folder Name</label>
          <input type="text" name="folder_name_override" id="folderNameInput"
                 style="font-family:monospace;font-size:.82rem;color:#C0211B;"
                 oninput="folderUserEdited=true" placeholder="Folder name">
        </div>
        <!-- Notify agent checkbox -->
        <div style="margin:6px 0 12px;">
          <label style="display:flex;align-items:center;gap:8px;font-size:.78rem;font-weight:600;cursor:pointer;">
            <input type="checkbox" name="notify_agent" value="1" checked style="width:14px;height:14px;cursor:pointer;">
            Send email notification to assigned agent
          </label>
        </div>
        <div style="display:flex;gap:8px;margin-top:4px;">
          <button type="submit" class="btn-approve">✅ Approve &amp; Create Request</button>
        </div>
      </form>
    </div>

    <!-- MERGE -->
    <div class="action-panel" id="panel-merge">
      <form method="POST" action="staging_action.php">
        <input type="hidden" name="action" value="merge">
        <input type="hidden" name="staging_id" id="mergeId">
        <div class="form-row full">
          <div>
            <label style="font-size:.75rem;font-weight:600;display:block;margin-bottom:4px">Merge into Request ID</label>
            <input type="text" name="target_request_id" id="mergeTarget" placeholder="e.g. 142" required>
          </div>
        </div>
        <p style="font-size:.75rem;color:#6B7280;margin:4px 0 10px">Appends this lead's initial request to the existing request's notes. Then deletes from staging.</p>
        <button type="submit" class="btn-merge">🔗 Merge</button>
      </form>
    </div>

    <!-- DISMISS -->
    <div class="action-panel" id="panel-dismiss">
      <form method="POST" action="staging_action.php">
        <input type="hidden" name="action" value="dismiss">
        <input type="hidden" name="staging_id" id="dismissId">
        <p style="font-size:.82rem;color:#6B7280;margin:0 0 12px">Permanently removes this lead from staging (spam, test, etc.).</p>
        <button type="submit" class="btn-dismiss">🗑 Dismiss &amp; Delete</button>
      </form>
    </div>
  </div>
</div>

<!-- Lead data for JS -->
<script>
const LEADS = <?= json_encode(array_column($leads, null, 'id'), JSON_UNESCAPED_UNICODE) ?>;

// Folder name helpers (mirror PHP logic in staging_action.php)
function toCamelCase(name) {
    name = name.trim();
    if (!name.includes(' ') && !name.includes('-')) return name;
    return name.split(/[\s\-]+/)
               .filter(p => p.length > 0)
               .map(p => p.charAt(0).toUpperCase() + p.slice(1).toLowerCase())
               .join('');
}
function buildFolderName(customerName, agentName) {
    const namePart  = toCamelCase(customerName);
    const agentPart = agentName.replace(/\s+/g, '');
    return namePart + '(' + agentPart + '-Drct)';
}

let currentLeadName = '';
let folderUserEdited = false;

function updateFolderPreview() {
    const sel = document.getElementById('approveAgent');
    const opt = sel.options[sel.selectedIndex];
    const row = document.getElementById('folderPreviewRow');
    const inp = document.getElementById('folderNameInput');
    const name = document.getElementById('customerNameInput').value.trim();
    if (!sel.value || !name) { row.style.display = 'none'; return; }
    row.style.display = 'block';
    if (!folderUserEdited) {
        inp.value = buildFolderName(name, opt.dataset.name || opt.text);
    }
}

function onCustomerNameInput() {
    if (!folderUserEdited) updateFolderPreview();
}

function openDrawer(id) {
  const l = LEADS[id];
  if (!l) return;

  document.getElementById('drawerTitle').textContent = l.customer_name;
  document.getElementById('approveId').value  = id;
  document.getElementById('mergeId').value    = id;
  document.getElementById('dismissId').value  = id;

  // Populate editable name; reset folder state
  currentLeadName = l.customer_name;
  folderUserEdited = false;
  document.getElementById('customerNameInput').value = l.customer_name;
  document.getElementById('approveAgent').value = '';
  document.getElementById('folderPreviewRow').style.display = 'none';
  document.getElementById('folderNameInput').value = '';

  // Pre-select destination if already set
  const ds = document.getElementById('approveDest');
  for (let i=0;i<ds.options.length;i++) {
    if (ds.options[i].value === l.destination) { ds.selectedIndex=i; break; }
  }

  // Build body HTML
  let html = '';

  // Info grid
  html += '<div class="drawer-section">Contact Info</div><dl class="info-grid">';
  html += row('Source',   srcPill(l.source));
  html += row('Email',    esc(l.email||'—'));
  html += row('Phone',    esc(l.phone||'—'));
  html += row('Pax',      l.pax||'—');
  html += row('Period',   esc(l.period||'—'));
  html += row('Dest.',    esc(l.destination||'—'));
  html += row('Received', esc(l.date_received));
  html += '</dl>';

  // Duplicate warnings (name-based)
  if (l.dup_flag !== 'clean' && l.dup_refs) {
    const refs = JSON.parse(l.dup_refs);
    if (refs && refs.length) {
      html += '<div class="drawer-section">Duplicate Matches (Name)</div>';
      refs.forEach(r => {
        const cls = r.level==='definite' ? 'dup-match-def' : 'dup-match-poss';
        const lbl = r.level==='definite' ? '🔴' : '🟡';
        const url = r.table==='requests' ? 'request_view.php?id='+r.id : '#';
        html += `<div class="dup-match ${cls}">
          ${lbl} <strong>${esc(r.name)}</strong> — ${esc(r.match)} match
          ${r.table==='requests' ? `<a href="${url}" target="_blank" style="margin-left:8px;font-size:.75rem">View →</a>` : '(in staging)'}
        </div>`;
      });
    }
  }

  // Email duplicate warnings
  if (l.email_matches && l.email_matches.length) {
    html += '<div class="drawer-section" style="color:#991B1B;">⚠ Same Email Already on File</div>';
    l.email_matches.forEach(m => {
      html += `<div class="dup-match dup-match-def">
        🔴 <strong>${esc(m.name)}</strong>
        <a href="request_view.php?id=${m.id}" target="_blank" style="margin-left:8px;font-size:.75rem;color:#991B1B;">View Request #${m.id} →</a>
      </div>`;
    });
  }

  // Initial request
  html += '<div class="drawer-section">Initial Request</div>';
  html += `<div class="req-text">${esc(l.initial_request||'—')}</div>`;

  document.getElementById('drawerBody').innerHTML = html;
  document.getElementById('drawer').classList.add('open');
  document.getElementById('drawerOverlay').classList.add('open');
  switchTab('approve');
}

function closeDrawer() {
  document.getElementById('drawer').classList.remove('open');
  document.getElementById('drawerOverlay').classList.remove('open');
}

function switchTab(tab) {
  document.querySelectorAll('.action-tab').forEach((b,i)=>{
    b.classList.toggle('active', ['approve','merge','dismiss'][i]===tab);
  });
  ['approve','merge','dismiss'].forEach(t=>{
    document.getElementById('panel-'+t).classList.toggle('active', t===tab);
  });
}

function row(label, val) {
  return `<dt>${label}</dt><dd>${val}</dd>`;
}
function esc(s) {
  const d=document.createElement('div');
  d.appendChild(document.createTextNode(String(s||'')));
  return d.innerHTML;
}
function srcPill(s) {
  return `<span class="src-pill src-${s}">${s}</span>`;
}

// ── HubSpot Sync Panel ──────────────────────────────────────────────────────
let hsDays = 1;

function hsTogglePanel() {
  const body = document.getElementById('hsPanelBody');
  const chev = document.getElementById('hsChevron');
  const open = body.classList.toggle('open');
  chev.textContent = open ? '▲' : '▼';
}

function hsFetch(days, btn) {
  hsDays = days;
  document.querySelectorAll('.hs-btn-days').forEach(b => b.classList.remove('hs-active'));
  if (btn) btn.classList.add('hs-active');
  document.getElementById('hsSpinner').style.display = 'inline';
  document.getElementById('hsPreviewArea').innerHTML = '';

  fetch('staging_action.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
    body: 'action=hubspot_preview&days=' + encodeURIComponent(days)
  })
  .then(r => r.json())
  .then(data => {
    document.getElementById('hsSpinner').style.display = 'none';
    if (data.error) {
      document.getElementById('hsPreviewArea').innerHTML =
        '<p style="color:#991B1B;margin-top:12px;font-size:.82rem">&#9888; ' + esc(data.error) + '</p>';
      return;
    }
    hsRenderPreview(data);
  })
  .catch(() => {
    document.getElementById('hsSpinner').style.display = 'none';
    document.getElementById('hsPreviewArea').innerHTML =
      '<p style="color:#991B1B;margin-top:12px;font-size:.82rem">&#9888; Request failed.</p>';
  });
}

function hsRenderPreview(data) {
  const area = document.getElementById('hsPreviewArea');
  if (!data.leads || data.leads.length === 0) {
    area.innerHTML = '<p style="color:#059669;margin-top:14px;font-size:.82rem">&#10003; No new leads found — staging is up to date.</p>';
    return;
  }
  let html = `
    <div style="display:flex;align-items:center;justify-content:space-between;margin-top:14px;margin-bottom:6px;flex-wrap:wrap;gap:8px;">
      <span style="font-size:.82rem;color:#444">
        <strong>${data.total}</strong> lead${data.total > 1 ? 's' : ''} found — not yet in staging
      </span>
      <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
        <label style="font-size:.75rem;color:#666;cursor:pointer;display:flex;align-items:center;gap:4px;">
          <input type="checkbox" id="hsSelectAll" onchange="hsToggleAll(this)"> Select all
        </label>
        <button class="hs-btn hs-btn-import-sel" onclick="hsImportSelected()">&#8659; Import Selected</button>
        <button class="hs-btn hs-btn-import-all" onclick="hsImportAll()">&#8659; Import All (${data.total})</button>
      </div>
    </div>
    <div style="overflow-x:auto;">
    <table class="hs-preview-table">
      <thead><tr>
        <th style="width:30px;"></th>
        <th>Name</th>
        <th>Source</th>
        <th>Email / Phone</th>
        <th>Pax</th>
        <th>Period</th>
        <th>Date (GMT+3)</th>
      </tr></thead>
      <tbody>`;
  data.leads.forEach(l => {
    const contactLine = [l.email, l.phone].filter(Boolean).join('<br>');
    html += `<tr>
      <td><input type="checkbox" class="hs-check" value="${esc(l.hubspot_id)}"></td>
      <td><strong>${esc(l.customer_name)}</strong></td>
      <td>${srcPill(l.source)}</td>
      <td style="font-size:.75rem">${contactLine || '—'}</td>
      <td>${l.pax || '—'}</td>
      <td style="font-size:.75rem">${esc(l.period || '—')}</td>
      <td style="font-size:.75rem;white-space:nowrap">${esc(l.date || '—')}</td>
    </tr>`;
  });
  html += '</tbody></table></div>';
  area.innerHTML = html;
}

function hsToggleAll(cb) {
  document.querySelectorAll('.hs-check').forEach(c => c.checked = cb.checked);
}

function hsImportSelected() {
  const ids = [...document.querySelectorAll('.hs-check:checked')].map(c => c.value);
  if (!ids.length) { alert('Select at least one lead to import.'); return; }
  hsDoImport(ids);
}
function hsImportAll() { hsDoImport([]); }

function hsDoImport(selectedIds) {
  document.getElementById('hsSpinner').style.display = 'inline';
  document.getElementById('hsPreviewArea').innerHTML = '';

  fetch('staging_action.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
    body: 'action=hubspot_import&days=' + encodeURIComponent(hsDays)
        + '&selected=' + encodeURIComponent(JSON.stringify(selectedIds))
  })
  .then(r => r.json())
  .then(data => {
    document.getElementById('hsSpinner').style.display = 'none';
    if (data.error) {
      document.getElementById('hsPreviewArea').innerHTML =
        '<p style="color:#991B1B;margin-top:12px;font-size:.82rem">&#9888; ' + esc(data.error) + '</p>';
      return;
    }
    let html = `<div style="margin-top:14px;padding:12px 16px;background:#F0FDF4;border:1px solid #86EFAC;border-radius:8px;font-size:.82rem;">
      &#10003; <strong>Import complete</strong> — staged: <strong>${data.staged}</strong> | skipped: <strong>${data.skipped}</strong>
    </div>`;
    if (data.results && data.results.length) {
      html += '<table class="hs-preview-table" style="margin-top:10px;"><thead><tr><th>Name</th><th>Status</th><th>Detail</th></tr></thead><tbody>';
      data.results.forEach(r => {
        const cls = r.status === 'staged' ? 'hs-result-staged'
                  : r.status === 'error'  ? 'hs-result-error'
                  : 'hs-result-skipped';
        html += `<tr>
          <td><strong>${esc(r.name)}</strong></td>
          <td><span class="${cls}">${esc(r.status)}</span></td>
          <td style="font-size:.75rem;color:#666">${esc(r.message)}</td>
        </tr>`;
      });
      html += '</tbody></table>';
    }
    if (data.staged > 0) {
      html += '<p style="margin-top:12px;font-size:.8rem">'
            + '<a href="staging.php" style="color:#0062B1;font-weight:700">&#8635; Reload staging to see new leads &rarr;</a></p>';
    }
    document.getElementById('hsPreviewArea').innerHTML = html;
  })
  .catch(() => {
    document.getElementById('hsSpinner').style.display = 'none';
    document.getElementById('hsPreviewArea').innerHTML =
      '<p style="color:#991B1B;margin-top:12px;font-size:.82rem">&#9888; Request failed.</p>';
  });
}
</script>

<?php include 'includes/footer.php'; ?>
