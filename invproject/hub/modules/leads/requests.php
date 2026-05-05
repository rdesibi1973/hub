<?php
require_once 'config.php';
$pageTitle = 'Requests';
$db = db();

// ── Staff: restrict to own requests ──────────────────────────────
$isStaff      = isLeadsRestricted();
$staffAgentId = $isStaff ? getStaffAgentId() : 0;

// ── Session-based filter persistence ────────────────────────────
if (session_status() === PHP_SESSION_NONE) session_start();

// Clear filters
if (isset($_GET['clear'])) {
    unset($_SESSION['req_filters']);
    header('Location: requests.php');
    exit;
}

// If any filter param is present in URL → save to session; otherwise restore
$filter_submitted = array_key_exists('q', $_GET) || array_key_exists('status', $_GET)
                 || array_key_exists('agent', $_GET) || array_key_exists('year', $_GET)
                 || array_key_exists('no_folder', $_GET);

if ($filter_submitted) {
    $_SESSION['req_filters'] = [
        'q'         => trim($_GET['q']      ?? ''),
        'status'    => $_GET['status']      ?? '',
        'agent'     => (int)($_GET['agent'] ?? 0),
        'year'      => (int)($_GET['year']  ?? date('Y')),
        'no_folder' => !empty($_GET['no_folder']),
    ];
}

$f         = $_SESSION['req_filters'] ?? [];
$search    = $f['q']         ?? '';
$status    = $f['status']    ?? '';
$agent     = (int)($f['agent']    ?? 0);
$year      = (int)($f['year']     ?? date('Y'));
$no_folder = !empty($f['no_folder']);

// Build query
$where  = ['1=1'];
$params = [];

if ($search) {
    $where[]  = '(r.customer_name LIKE ? OR r.practice_code LIKE ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($status && array_key_exists($status, STATUSES)) {
    $where[]  = 'r.status = ?';
    $params[] = $status;
}
if ($isStaff && $staffAgentId) {
    // Staff always see only their own requests — ignore any agent filter from session
    $where[]  = 'r.agent_id = ?';
    $params[] = $staffAgentId;
} elseif ($agent === -1) {
    $where[]  = 'r.agent_id IS NULL';
} elseif ($agent > 0) {
    $where[]  = 'r.agent_id = ?';
    $params[] = $agent;
}
if ($year > 0) {
    $where[]  = 'YEAR(r.date_received) = ?';
    $params[] = $year;
}
if ($no_folder) {
    $where[] = "(r.practice_code IS NULL OR r.practice_code = '')";
}

$sql = "
    SELECT r.*, a.name AS agent_name
    FROM requests r
    LEFT JOIN agents a ON a.id = r.agent_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY r.date_received DESC, r.id DESC
";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Agents for filter dropdown
$agents = $db->query("SELECT * FROM agents WHERE active=1 ORDER BY name")->fetchAll();

// Year range for filter
$years = $db->query("SELECT DISTINCT YEAR(date_received) y FROM requests ORDER BY y DESC")->fetchAll(PDO::FETCH_COLUMN);
if (!$years) $years = [date('Y')];

include 'includes/header.php';
?>

<div class="page-header">
  <div style="display:flex;align-items:center;gap:12px;">
    <a href="<?= defined('BASE_URL') ? BASE_URL.'/hub.php' : '../../hub.php' ?>" class="btn btn-outline btn-sm" style="font-size:.72rem;">&#8592; Hub</a>
    <div>
    <h2><?= $isStaff ? 'My Requests' : 'Requests' ?></h2>
    <div class="sub"><?= count($rows) ?> result<?= count($rows)!==1?'s':'' ?><?php if ($no_folder): ?> &nbsp;<span style="background:#FAE8E7;color:#C0211B;font-size:.7rem;font-weight:700;padding:2px 8px;border-radius:10px;">No folder</span><?php endif; ?></div>
    </div>
  </div>
  <div style="display:flex;gap:8px;align-items:center;">
    <?php if (!$isStaff): ?>
    <button id="btnDeleteSel" class="btn btn-outline btn-danger" style="display:none" onclick="bulkDelete()">&#128465; Delete Selected</button>
    <?php endif; ?>
    <button class="btn btn-outline" onclick="location.reload()" title="Refresh">&#8635; Refresh</button>
    <?php if (!$isStaff): ?>
    <a href="request_add.php" class="btn btn-red">+ New Request</a>
    <?php endif; ?>
  </div>
</div>


<!-- FILTERS -->
<form method="GET" class="filters">
  <div>
    <label>Search</label>
    <input type="text" name="q" placeholder="Customer or practice code…" value="<?= h($search) ?>">
  </div>
  <div>
    <label>Status</label>
    <select name="status">
      <option value="">All statuses</option>
      <?php foreach (STATUSES as $s => $cls): ?>
        <option value="<?= h($s) ?>" <?= $status===$s?'selected':'' ?>><?= h($s) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <?php if (!$isStaff): ?>
  <div>
    <label>Agent</label>
    <select name="agent">
      <option value="0">All agents</option>
      <option value="-1" <?= $agent===-1?'selected':'' ?>>Unassigned</option>
      <?php foreach ($agents as $ag): ?>
        <option value="<?= $ag['id'] ?>" <?= $agent===$ag['id']?'selected':'' ?>><?= h($ag['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <?php endif; ?>
  <div>
    <label>Year</label>
    <select name="year">
      <option value="0">All years</option>
      <?php foreach ($years as $y): ?>
        <option value="<?= $y ?>" <?= $year===(int)$y?'selected':'' ?>><?= $y ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div style="display:flex;align-items:flex-end;padding-bottom:4px;">
    <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:.82rem;white-space:nowrap;">
      <input type="checkbox" name="no_folder" value="1" <?= $no_folder ? 'checked' : '' ?>
             style="width:15px;height:15px;cursor:pointer;accent-color:#C0211B;">
      No Dropbox folder
    </label>
  </div>
  <div>
    <label>&nbsp;</label>
    <button type="submit" class="btn btn-outline">Filter</button>
  </div>
  <div>
    <label>&nbsp;</label>
    <a href="requests.php?clear=1" class="btn btn-outline btn-grey">✕ Clear Filters</a>
  </div>
</form>

<!-- TABLE -->
<div class="table-wrap">
  <table>
    <thead>
      <tr>
        <?php if (!$isStaff): ?><th style="width:32px;"><input type="checkbox" id="selAll" onchange="document.querySelectorAll('.row-sel').forEach(el=>el.checked=this.checked)"></th><?php endif; ?>
        <th>Date</th>
        <th>Dropbox Folder</th>
        <th>Customer</th>
        <th>Source</th>
        <th>Agent</th>
        <th>Pax</th>
        <th>Status</th>
        <th class="text-right">Value (USD)</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php if ($rows): ?>
        <?php foreach ($rows as $r): ?>
        <tr>
          <?php if (!$isStaff): ?><td><input type="checkbox" class="row-sel"
            value="<?= $r['id'] ?>"
            data-folder="<?= addslashes(h($r['practice_code'] ?? '')) ?>"
            data-status="<?= h($r['status'] ?? '') ?>"></td><?php endif; ?>
          <td class="text-muted" style="white-space:nowrap"><?= date('d M Y', strtotime($r['date_received'])) ?></td>
          <td style="font-size:.75rem;color:var(--grey-mid)">
            <?php if ($r['practice_code']): ?>
              <?= h($r['practice_code']) ?>
            <?php else: ?>
              <span class="text-muted">—</span>
            <?php endif; ?>
          </td>
          <td>
            <a href="request_view.php?id=<?= $r['id'] ?>" style="font-weight:600;color:var(--black);text-decoration:none">
              <?= h($r['customer_name']) ?>
            </a>
            <?php if ($r['destination']): ?>
              <div style="font-size:.7rem;color:var(--grey-mid)"><?= h($r['destination']) ?><?= $r['period'] ? ' · '.h($r['period']) : '' ?></div>
            <?php endif; ?>
          </td>
          <td class="text-muted"><?= h($r['source'] ?? '—') ?></td>
          <td><?= h($r['agent_name'] ?? '—') ?></td>
          <td class="text-muted"><?= $r['pax'] ?: '—' ?></td>
          <td><span class="badge <?= STATUSES[$r['status']] ?? '' ?>"><?= h($r['status']) ?></span><?php
            if ($r['status'] === 'Booked' && !empty($r['confirmation_date'])):
                echo ' <span style="font-size:.72rem;color:#2E6B3E;white-space:nowrap;">'
                   . date('d M Y', strtotime($r['confirmation_date']))
                   . '</span>';
            endif;
          ?></td>
          <td class="text-right"><?= $r['value_usd'] ? '$'.number_format($r['value_usd'],0) : '—' ?></td>
          <td>
            <div class="gap-8">
              <a href="request_view.php?id=<?= $r['id'] ?>"  class="btn btn-outline btn-sm">View</a>
              <?php if (!$isStaff): ?>
              <a href="request_edit.php?id=<?= $r['id'] ?>"  class="btn btn-outline btn-sm">Edit</a>
              <button class="btn btn-outline btn-sm btn-danger" onclick="deleteRequest(<?= $r['id'] ?>, '<?= addslashes(h($r['customer_name'])) ?>', '<?= addslashes(h($r['practice_code'] ?? '')) ?>', '<?= addslashes(h($r['status'] ?? '')) ?>', null)">Delete</button>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      <?php else: ?>
        <tr><td colspan="9">
          <div class="empty-state">
            <div class="icon">🔍</div>
            <p>No requests found<?= ($search||$status||$agent)?' for the selected filters.':' yet.' ?></p>
          </div>
        </td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>


<style>
.btn-danger{border-color:#C0211B!important;color:#C0211B!important;}
.btn-danger:hover{background:#FAE8E7!important;}
input[type=checkbox]{width:15px;height:15px;cursor:pointer;accent-color:#C0211B;}
</style>
<script>
// Show/hide Delete Selected based on checkboxes
document.addEventListener('change', function(e){
  if(e.target.classList.contains('row-sel') || e.target.id==='selAll'){
    var checked = document.querySelectorAll('.row-sel:checked').length;
    document.getElementById('btnDeleteSel').style.display = checked ? '' : 'none';
  }
});

function bulkDelete() {
  var checkboxes = [...document.querySelectorAll('.row-sel:checked')];
  if (!checkboxes.length) { alert('No requests selected.'); return; }
  var folderMap = {}, statusMap = {};
  checkboxes.forEach(function(el) {
    folderMap[el.value] = el.dataset.folder || '';
    statusMap[el.value] = el.dataset.status || '';
  });
  deleteSelectedRequests(folderMap, statusMap);
}
</script>

<?php include 'includes/footer.php'; ?>