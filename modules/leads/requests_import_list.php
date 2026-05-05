<?php
require_once 'config.php';
requireLogin();
if (isLeadsRestricted()) { header('Location: requests.php'); exit; }
$pageTitle = 'Historical Requests';
$db = db();

if (session_status() === PHP_SESSION_NONE) session_start();

// Clear filters
if (isset($_GET['clear'])) {
    unset($_SESSION['imp_filters']);
    header('Location: requests_import_list.php');
    exit;
}

$filter_submitted = array_key_exists('q', $_GET) || array_key_exists('status', $_GET)
                 || array_key_exists('agent', $_GET) || array_key_exists('year', $_GET)
                 || array_key_exists('month', $_GET);

if ($filter_submitted) {
    $_SESSION['imp_filters'] = [
        'q'      => trim($_GET['q']      ?? ''),
        'status' => $_GET['status']      ?? '',
        'agent'  => (int)($_GET['agent'] ?? 0),
        'year'   => (int)($_GET['year']  ?? 2026),
        'month'  => (int)($_GET['month'] ?? 0),
    ];
}

$f      = $_SESSION['imp_filters'] ?? [];
$search = $f['q']      ?? '';
$status = $f['status'] ?? '';
$agent  = (int)($f['agent'] ?? 0);
$year   = (int)($f['year']  ?? 2026);
$month  = (int)($f['month'] ?? 0);

// Build query
$where  = ['1=1'];
$params = [];

if ($search) {
    $where[]  = '(r.customer_name LIKE ? OR r.practice_code LIKE ? OR r.agency_name LIKE ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($status) {
    $where[]  = 'r.status = ?';
    $params[] = $status;
}
if ($agent === -1) {
    $where[]  = 'r.agent_id IS NULL';
} elseif ($agent > 0) {
    $where[]  = 'r.agent_id = ?';
    $params[] = $agent;
}
if ($year > 0) {
    $where[]  = 'YEAR(r.date_received) = ?';
    $params[] = $year;
}
if ($month > 0) {
    $where[]  = 'MONTH(r.date_received) = ?';
    $params[] = $month;
}

$sql = "
    SELECT r.*, a.name AS agent_name
    FROM requests_import r
    LEFT JOIN agents a ON a.id = r.agent_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY r.date_received ASC, r.id ASC
";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Agents for filter
$agents = $db->query("SELECT * FROM agents ORDER BY name")->fetchAll();

// Years
$years = $db->query("SELECT DISTINCT YEAR(date_received) y FROM requests_import ORDER BY y DESC")->fetchAll(PDO::FETCH_COLUMN);
if (!$years) $years = [2026];

// Statuses
$statuses = $db->query("SELECT DISTINCT status FROM requests_import WHERE status IS NOT NULL ORDER BY status")->fetchAll(PDO::FETCH_COLUMN);

$months = [1=>'January',2=>'February',3=>'March',4=>'April',5=>'May',6=>'June',
           7=>'July',8=>'August',9=>'September',10=>'October',11=>'November',12=>'December'];

include 'includes/header.php';
?>

<div class="page-header">
  <div style="display:flex;align-items:center;gap:12px;">
    <a href="<?= defined('BASE_URL') ? BASE_URL.'/hub.php' : '../../hub.php' ?>" class="btn btn-outline btn-sm" style="font-size:.72rem;">&#8592; Hub</a>
    <div>
      <h2>Historical Requests</h2>
      <div class="sub"><?= count($rows) ?> result<?= count($rows)!==1?'s':'' ?> — imported from Dropbox folders</div>
    </div>
  </div>
  <div style="display:flex;gap:8px;align-items:center;">
    <button class="btn btn-outline" onclick="location.reload()" title="Refresh">&#8635; Refresh</button>
    <a href="reports_import.php" class="btn btn-outline">📊 Reports</a>
  </div>
</div>

<!-- FILTERS -->
<form method="GET" class="filters">
  <div>
    <label>Search</label>
    <input type="text" name="q" placeholder="Customer, folder or agency…" value="<?= h($search) ?>">
  </div>
  <div>
    <label>Status</label>
    <select name="status">
      <option value="">All statuses</option>
      <?php foreach ($statuses as $s): ?>
        <option value="<?= h($s) ?>" <?= $status===$s?'selected':'' ?>><?= h($s) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div>
    <label>Agent</label>
    <select name="agent">
      <option value="0">All agents</option>
      <option value="-1" <?= $agent===-1?'selected':'' ?>>Unassigned</option>
      <?php foreach ($agents as $ag): ?>
        <option value="<?= $ag['id'] ?>" <?= $agent===(int)$ag['id']?'selected':'' ?>><?= h($ag['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div>
    <label>Year</label>
    <select name="year">
      <option value="0">All years</option>
      <?php foreach ($years as $y): ?>
        <option value="<?= $y ?>" <?= $year===(int)$y?'selected':'' ?>><?= $y ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div>
    <label>Month</label>
    <select name="month">
      <option value="0">All months</option>
      <?php foreach ($months as $n => $name): ?>
        <option value="<?= $n ?>" <?= $month===$n?'selected':'' ?>><?= $name ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div>
    <label>&nbsp;</label>
    <button type="submit" class="btn btn-outline">Filter</button>
  </div>
  <div>
    <label>&nbsp;</label>
    <a href="requests_import_list.php?clear=1" class="btn btn-outline btn-grey">✕ Clear</a>
  </div>
</form>

<!-- TABLE -->
<div class="table-wrap">
  <table>
    <thead>
      <tr>
        <th>Date</th>
        <th>Dropbox Folder</th>
        <th>Customer</th>
        <th>Agency</th>
        <th>Agent</th>
        <th>Status</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php if ($rows): ?>
        <?php foreach ($rows as $r): ?>
        <tr>
          <td class="text-muted" style="white-space:nowrap"><?= date('d M Y', strtotime($r['date_received'])) ?></td>
          <td style="font-size:.72rem;color:var(--grey-mid);max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
            <?= h($r['practice_code'] ?? '—') ?>
          </td>
          <td style="font-weight:600"><?= h($r['customer_name']) ?></td>
          <td class="text-muted" style="font-size:.8rem"><?= h($r['agency_name'] ?? '—') ?></td>
          <td><?= h($r['agent_name'] ?? '—') ?></td>
          <td>
            <?php
            $cls = match($r['status']) {
                'Confirmed'    => 'badge-green',
                'In Progress'  => 'badge-blue',
                'Balance Due'  => 'badge-amber',
                'Cancelled'    => 'badge-red',
                default        => ''
            };
            ?>
            <span class="badge <?= $cls ?>"><?= h($r['status'] ?? '—') ?></span>
          </td>
          <td>
            <a href="request_import_edit.php?id=<?= $r['id'] ?>" class="btn btn-outline btn-sm">Edit</a>
          </td>
        </tr>
        <?php endforeach; ?>
      <?php else: ?>
        <tr><td colspan="7">
          <div class="empty-state">
            <div class="icon">🔍</div>
            <p>No historical requests found<?= ($search||$status||$agent)?' for the selected filters.':' yet.' ?></p>
          </div>
        </td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<style>
.badge-green  { background:#D6EDD9; color:#1A6B3A; }
.badge-blue   { background:#E5F0FC; color:#0062B1; }
.badge-amber  { background:#FEF0E5; color:#E87722; }
.badge-red    { background:#FAE8E7; color:#C0211B; }
</style>

<?php include 'includes/footer.php'; ?>
