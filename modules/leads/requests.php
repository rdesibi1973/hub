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
    SELECT r.*, a.name AS agent_name,
           (SELECT COUNT(*) FROM invoices inv WHERE inv.request_id = r.id) AS invoice_count,
           (SELECT id   FROM invoices inv WHERE inv.request_id = r.id ORDER BY id LIMIT 1) AS invoice_id,
           (SELECT invoice_number FROM invoices inv WHERE inv.request_id = r.id ORDER BY id LIMIT 1) AS invoice_number
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
        <th style="width:120px;"></th>
        <th>Customer</th>
        <th>Email</th>
        <th>WhatsApp</th>
        <th>Pax</th>
        <th>Status</th>
        <?php if (!$isStaff): ?><th style="text-align:center;width:48px;">Inv</th><?php endif; ?>
        <?php if (!$isStaff): ?><th class="text-right">Value (USD)</th><?php endif; ?>
        <?php if (!$isStaff): ?><th>Agent</th><?php endif; ?>
        <th>Date</th>
        <th>Dropbox Folder</th>
        <th>Source</th>
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
          <td style="white-space:nowrap;">
            <div class="gap-8">
              <a href="request_view.php?id=<?= $r['id'] ?>"  class="btn btn-outline btn-sm">View</a>
              <?php if (!$isStaff || (int)$r['agent_id'] === $staffAgentId): ?>
              <a href="request_edit.php?id=<?= $r['id'] ?>"  class="btn btn-outline btn-sm">Edit</a>
              <?php endif; ?>
              <?php if (!$isStaff): ?>
              <button class="btn btn-outline btn-sm btn-danger" onclick="deleteRequest(<?= $r['id'] ?>, '<?= addslashes(h($r['customer_name'])) ?>', '<?= addslashes(h($r['practice_code'] ?? '')) ?>', '<?= addslashes(h($r['status'] ?? '')) ?>', null)">Delete</button>
              <?php endif; ?>
            </div>
          </td>
          <td>
            <a href="request_view.php?id=<?= $r['id'] ?>" style="font-weight:600;color:var(--black);text-decoration:none">
              <?= h($r['customer_name']) ?>
            </a>
            <?php if ($r['destination']): ?>
              <div style="font-size:.7rem;color:var(--grey-mid)"><?= h($r['destination']) ?><?= $r['period'] ? ' · '.h($r['period']) : '' ?></div>
            <?php endif; ?>
          </td>
          <td style="font-size:.78rem;">
            <?= $r['email'] ? '<a href="mailto:'.h($r['email']).'" style="color:var(--grey-dk);text-decoration:none;">'.h($r['email']).'</a>' : '<span class="text-muted">—</span>' ?>
          </td>
          <td style="font-size:.78rem;white-space:nowrap;">
            <?php if ($r['whatsapp']):
              $wa_num = preg_replace('/\D/', '', $r['whatsapp']);
            ?>
              <a href="https://wa.me/<?= h($wa_num) ?>" target="_blank"
                 style="display:inline-flex;align-items:center;gap:5px;color:var(--grey-dk);text-decoration:none;"
                 title="Open in WhatsApp Web">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="14" height="14" fill="#25D366" style="flex-shrink:0;">
                  <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z"/>
                  <path d="M12 0C5.373 0 0 5.373 0 12c0 2.117.554 4.103 1.523 5.824L.057 23.882a.5.5 0 0 0 .61.61l6.058-1.466A11.945 11.945 0 0 0 12 24c6.627 0 12-5.373 12-12S18.627 0 12 0zm0 21.9a9.877 9.877 0 0 1-5.031-1.378l-.36-.214-3.733.903.919-3.628-.235-.374A9.857 9.857 0 0 1 2.1 12C2.1 6.533 6.533 2.1 12 2.1S21.9 6.533 21.9 12 17.467 21.9 12 21.9z"/>
                </svg>
                <?= h($r['whatsapp']) ?>
              </a>
            <?php else: ?>
              <span class="text-muted">—</span>
            <?php endif; ?>
          </td>
          <td class="text-muted"><?= $r['pax'] ?: '—' ?></td>
          <td><span class="badge <?= STATUSES[$r['status']] ?? '' ?>"><?= h($r['status']) ?></span><?php
            if ($r['status'] === 'Booked' && !empty($r['confirmation_date'])):
                echo ' <span style="font-size:.72rem;color:#2E6B3E;white-space:nowrap;">'
                   . date('d M Y', strtotime($r['confirmation_date']))
                   . '</span>';
            endif;
          ?></td>
          <?php if (!$isStaff): ?>
          <td style="text-align:center;">
            <?php if ((int)$r['invoice_count'] === 1): ?>
              <a href="../invoices/invoice_view.php?id=<?= $r['invoice_id'] ?>"
                 title="<?= h($r['invoice_number']) ?>"
                 style="text-decoration:none;font-size:1rem;">🧾</a>
            <?php elseif ((int)$r['invoice_count'] > 1): ?>
              <a href="../invoices/invoices.php?request_id=<?= $r['id'] ?>"
                 title="<?= (int)$r['invoice_count'] ?> invoices"
                 style="text-decoration:none;font-size:.8rem;font-weight:700;color:#C0211B;">
                🧾<?= (int)$r['invoice_count'] ?>
              </a>
            <?php elseif ($r['status'] === 'Booked'): ?>
              <a href="../invoices/invoice_add.php?request_id=<?= $r['id'] ?>"
                 title="Create invoice"
                 style="text-decoration:none;font-size:.75rem;font-weight:700;color:#C0211B;white-space:nowrap;border:1px solid #C0211B;border-radius:4px;padding:2px 7px;">+ Invoice</a>
            <?php endif; ?>
          </td>
          <?php endif; ?>
          <?php if (!$isStaff): ?><td class="text-right"><?= $r['value_usd'] ? '$'.number_format($r['value_usd'],0) : '—' ?></td><?php endif; ?>
          <?php if (!$isStaff): ?><td><?= h($r['agent_name'] ?? '—') ?></td><?php endif; ?>
          <td class="text-muted" style="white-space:nowrap"><?= date('d M Y', strtotime($r['date_received'])) ?></td>
          <td style="font-size:.75rem;color:var(--grey-mid)">
            <?php if ($r['practice_code']): ?>
              <?= h($r['practice_code']) ?>
            <?php else: ?>
              <span class="text-muted">—</span>
            <?php endif; ?>
          </td>
          <td class="text-muted"><?= h($r['source'] ?? '—') ?></td>
        </tr>
        <?php endforeach; ?>
      <?php else: ?>
        <tr><td colspan="<?= $isStaff ? 10 : 14 ?>">
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
.table-wrap{overflow-x:auto !important;}
table{min-width:900px;}
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