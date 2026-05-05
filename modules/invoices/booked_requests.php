<?php
/**
 * booked_requests.php
 *
 * Invoice-module view of Booked requests.
 * Shows all requests (default: Booked) with invoice status,
 * allows status changes, and links to invoice creation.
 *
 * Accessible to: accountant, admin, manager
 */
require_once 'config.php';

// STATUSES is defined in leads/config.php — load it if not already defined
if (!defined('STATUSES')) {
    $leadsConfig = dirname(__DIR__) . '/leads/config.php';
    if (file_exists($leadsConfig)) require_once $leadsConfig;
}
// Fallback: define inline in case leads config can't be loaded
if (!defined('STATUSES')) {
    define('STATUSES', [
        'Inquiry'   => 'status-inquiry',
        'Quoted'    => 'status-quoted',
        'Booked'    => 'status-booked',
        'Cancelled' => 'status-cancelled',
        'Lost'      => 'status-lost',
    ]);
}

$pageTitle = 'Requests';
$db = db();

// ── Filters ───────────────────────────────────────────────────────────────
$search    = trim($_GET['q']      ?? '');
$fstatus   = $_GET['status']      ?? 'Booked';   // default Booked
$fagent    = (int)($_GET['agent'] ?? 0);
$fyear     = (int)($_GET['year']  ?? 0);
$finvoice  = $_GET['invoice']     ?? '';          // '' | 'yes' | 'no'

$where   = ['1=1']; $params = [];
if ($search) {
    $where[]  = '(r.customer_name LIKE ? OR r.practice_code LIKE ?)';
    $params[] = "%$search%"; $params[] = "%$search%";
}
if ($fstatus && array_key_exists($fstatus, STATUSES)) {
    $where[] = 'r.status = ?'; $params[] = $fstatus;
}
if ($fagent > 0) { $where[] = 'r.agent_id = ?'; $params[] = $fagent; }
if ($fyear  > 0) { $where[] = 'YEAR(r.date_received) = ?'; $params[] = $fyear; }
if ($finvoice === 'yes') { $where[] = '(SELECT COUNT(*) FROM invoices i WHERE i.request_id = r.id) > 0'; }
if ($finvoice === 'no')  { $where[] = '(SELECT COUNT(*) FROM invoices i WHERE i.request_id = r.id) = 0'; }

$sql = "
    SELECT r.*, a.name AS agent_name,
           (SELECT COUNT(*) FROM invoices inv WHERE inv.request_id = r.id) AS invoice_count,
           (SELECT id   FROM invoices inv WHERE inv.request_id = r.id ORDER BY id LIMIT 1) AS invoice_id,
           (SELECT invoice_number FROM invoices inv WHERE inv.request_id = r.id ORDER BY id LIMIT 1) AS invoice_number
    FROM requests r
    LEFT JOIN agents a ON a.id = r.agent_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY r.confirmation_date DESC, r.date_received DESC, r.id DESC
";
$stmt = $db->prepare($sql); $stmt->execute($params);
$rows = $stmt->fetchAll();

// ── Handle inline status change ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_status'])) {
    $rid     = (int)$_POST['request_id'];
    $nstatus = $_POST['new_status'] ?? '';
    if ($rid && array_key_exists($nstatus, STATUSES)) {
        $db->prepare('UPDATE requests SET status = ? WHERE id = ?')->execute([$nstatus, $rid]);
    }
    // Redirect to preserve filters
    header('Location: ' . $_SERVER['REQUEST_URI']); exit;
}

$agents = $db->query("SELECT id, name FROM agents WHERE active=1 ORDER BY name")->fetchAll();
$years  = $db->query("SELECT DISTINCT YEAR(date_received) y FROM requests ORDER BY y DESC")->fetchAll(PDO::FETCH_COLUMN);
if (!$years) $years = [date('Y')];

include 'includes/header.php';
?>

<div class="page-header">
  <div>
    <h2>Requests</h2>
    <div class="sub"><?= count($rows) ?> request<?= count($rows) !== 1 ? 's' : '' ?></div>
  </div>
</div>

<!-- FILTERS -->
<form method="GET" class="filters" style="flex-wrap:wrap;gap:10px 14px;">
  <div>
    <label>Search</label>
    <input type="text" name="q" value="<?= h($search) ?>" placeholder="Name or folder…">
  </div>
  <div>
    <label>Status</label>
    <select name="status">
      <option value="">All statuses</option>
      <?php foreach (STATUSES as $s => $cls): ?>
        <option value="<?= h($s) ?>" <?= $fstatus === $s ? 'selected' : '' ?>><?= h($s) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div>
    <label>Agent</label>
    <select name="agent">
      <option value="0">All agents</option>
      <?php foreach ($agents as $ag): ?>
        <option value="<?= $ag['id'] ?>" <?= $fagent === (int)$ag['id'] ? 'selected' : '' ?>><?= h($ag['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div>
    <label>Invoice</label>
    <select name="invoice">
      <option value="">All</option>
      <option value="yes" <?= $finvoice === 'yes' ? 'selected' : '' ?>>Has invoice</option>
      <option value="no"  <?= $finvoice === 'no'  ? 'selected' : '' ?>>No invoice yet</option>
    </select>
  </div>
  <div>
    <label>Year</label>
    <select name="year">
      <option value="0">All years</option>
      <?php foreach ($years as $y): ?>
        <option value="<?= $y ?>" <?= $fyear === (int)$y ? 'selected' : '' ?>><?= $y ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div><label>&nbsp;</label><button type="submit" class="btn btn-outline">Filter</button></div>
  <div><label>&nbsp;</label><a href="booked_requests.php" class="btn btn-grey">✕ Clear</a></div>
</form>

<!-- TABLE -->
<div class="table-wrap">
  <table>
    <thead>
      <tr>
        <th>Customer</th>
        <th>Agent</th>
        <th>Folder</th>
        <th>Confirmed</th>
        <th>Pax</th>
        <th>Status</th>
        <th style="text-align:center">Invoice(s)</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php if ($rows): ?>
        <?php foreach ($rows as $r): ?>
        <tr>
          <td style="font-weight:600"><?= h($r['customer_name']) ?></td>
          <td style="font-size:.78rem;color:var(--grey-mid)"><?= h($r['agent_name'] ?? '—') ?></td>
          <td style="font-size:.72rem;color:var(--grey-mid);max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= h($r['practice_code'] ?? '') ?>">
            <?= h($r['practice_code'] ?? '—') ?>
          </td>
          <td style="font-size:.78rem;white-space:nowrap;color:#1A6B3A">
            <?= $r['confirmation_date'] ? date('d M Y', strtotime($r['confirmation_date'])) : '—' ?>
          </td>
          <td style="text-align:center"><?= $r['pax'] ?: '—' ?></td>

          <!-- Status with inline change -->
          <td>
            <form method="POST" style="display:inline-flex;align-items:center;gap:6px;">
              <input type="hidden" name="request_id" value="<?= $r['id'] ?>">
              <select name="new_status" onchange="this.form.submit()"
                      style="font-size:.75rem;padding:3px 6px;border:1px solid var(--grey-lt);border-radius:5px;cursor:pointer;">
                <?php foreach (STATUSES as $s => $cls): ?>
                  <option value="<?= h($s) ?>" <?= $r['status'] === $s ? 'selected' : '' ?>>
                    <?= h($s) ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <input type="hidden" name="set_status" value="1">
            </form>
          </td>

          <!-- Invoice column -->
          <td style="text-align:center">
            <?php $ic = (int)$r['invoice_count']; ?>
            <?php if ($ic === 1): ?>
              <a href="invoice_view.php?id=<?= $r['invoice_id'] ?>"
                 title="<?= h($r['invoice_number']) ?>"
                 style="text-decoration:none;font-size:.78rem;font-weight:700;color:#1A6B3A;">
                🧾 <?= h($r['invoice_number']) ?>
              </a>
            <?php elseif ($ic > 1): ?>
              <a href="invoices.php?request_id=<?= $r['id'] ?>"
                 style="text-decoration:none;font-size:.78rem;font-weight:700;color:#C0211B;">
                🧾 <?= $ic ?> invoices
              </a>
            <?php else: ?>
              <span style="font-size:.72rem;color:var(--grey-mid)">—</span>
            <?php endif; ?>
          </td>

          <!-- Actions -->
          <td>
            <div class="gap-8">
              <?php if ($r['status'] === 'Booked'): ?>
                <a href="invoice_add.php?request_id=<?= $r['id'] ?>"
                   class="btn btn-red btn-sm">+ Invoice</a>
              <?php endif; ?>
              <a href="../leads/request_view.php?id=<?= $r['id'] ?>"
                 class="btn btn-outline btn-sm" target="_blank">View</a>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      <?php else: ?>
        <tr><td colspan="8">
          <div class="empty-state">
            <div class="icon">📋</div>
            <p>No requests found<?= ($search || $fstatus || $fagent) ? ' for the selected filters.' : '.' ?></p>
          </div>
        </td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<?php include 'includes/footer.php'; ?>
