<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once 'config.php';
requireLogin();
if (isLeadsRestricted()) { header('Location: requests.php'); exit; }
$pageTitle = 'Dashboard';

// ── Period resolution ────────────────────────────────────────────
$year   = (int)($_GET['year']   ?? date('Y'));
$period = $_GET['period'] ?? date('m');       // 'year' | '01'..'12'  — default: current month
$mode   = $_GET['mode']   ?? 'period';        // 'period' | 'ytd'

$months = ['01'=>'January','02'=>'February','03'=>'March','04'=>'April',
           '05'=>'May','06'=>'June','07'=>'July','08'=>'August',
           '09'=>'September','10'=>'October','11'=>'November','12'=>'December'];

if ($period === 'year') {
    $start       = "$year-01-01";
    $end         = "$year-12-31";
    $periodLabel = "Full Year $year";
    $showToggle  = false;
    $mode        = 'period';
} else {
    $m = str_pad((int)$period, 2, '0', STR_PAD_LEFT);
    $showToggle  = true;
    if ($mode === 'ytd') {
        $start       = "$year-01-01";
        $end         = date('Y-m-t', strtotime("$year-$m-01"));
        $periodLabel = "Jan – " . date('M Y', strtotime("$year-$m-01")) . " (YTD)";
    } else {
        $start       = "$year-$m-01";
        $end         = date('Y-m-t', strtotime("$year-$m-01"));
        $periodLabel = date('F Y', strtotime("$year-$m-01"));
    }
}

$db = db();

// ── Stats ────────────────────────────────────────────────────────
$total = $db->prepare("SELECT COUNT(*) FROM requests WHERE date_received BETWEEN ? AND ?");
$total->execute([$start, $end]);
$totalCount = (int)$total->fetchColumn();

$booked = $db->prepare("SELECT COUNT(*) FROM requests WHERE status='Booked' AND date_received BETWEEN ? AND ? AND (practice_code NOT LIKE '%\_STAFF%' OR practice_code IS NULL)");
$booked->execute([$start, $end]);
$bookedCount = (int)$booked->fetchColumn();

$salesRate = $totalCount > 0 ? round($bookedCount / $totalCount * 100, 1) : 0;

$value = $db->prepare("SELECT COALESCE(SUM(value_usd),0) FROM requests WHERE status='Booked' AND date_received BETWEEN ? AND ? AND (practice_code NOT LIKE '%\_STAFF%' OR practice_code IS NULL)");
$value->execute([$start, $end]);
$totalValue = (float)$value->fetchColumn();

$comm = $db->prepare("SELECT COALESCE(SUM(commission_usd),0) FROM requests WHERE date_received BETWEEN ? AND ?");
$comm->execute([$start, $end]);
$totalComm = (float)$comm->fetchColumn();

$lost = $db->prepare("SELECT COUNT(*) FROM requests WHERE status='Lost' AND date_received BETWEEN ? AND ?");
$lost->execute([$start, $end]);
$lostCount = (int)$lost->fetchColumn();

// ── Per-agent breakdown — sorted by total requests received ──────
$byAgent = $db->prepare("
    SELECT a.name,
           COUNT(r.id)                          AS total,
           SUM(r.status='Booked' AND (r.practice_code NOT LIKE '%\_STAFF%' OR r.practice_code IS NULL)) AS booked,
           COALESCE(SUM(r.commission_usd),0)    AS comm
    FROM agents a
    LEFT JOIN requests r ON r.agent_id = a.id
          AND r.date_received BETWEEN ? AND ?
    WHERE a.active = 1
    GROUP BY a.id, a.name
    HAVING total > 0
    ORDER BY total DESC, booked DESC
");
$byAgent->execute([$start, $end]);
$agentRows = $byAgent->fetchAll();

$maxTotal = $agentRows ? max(1, ...array_column($agentRows, 'total')) : 1;

// ── Recent requests (last 10) ────────────────────────────────────
$recent = $db->query("
    SELECT r.*, a.name AS agent_name
    FROM requests r
    LEFT JOIN agents a ON a.id = r.agent_id
    ORDER BY r.created_at DESC
    LIMIT 10
")->fetchAll();

// ── URL helper ───────────────────────────────────────────────────
function dashUrl($overrides = []) {
    global $year, $period, $mode;
    $params = array_merge(['year'=>$year,'period'=>$period,'mode'=>$mode], $overrides);
    return '?' . http_build_query($params);
}

include 'includes/header.php';
?>

<style>
.dash-filter-bar {
  display: flex; align-items: center; gap: 14px; flex-wrap: wrap;
  margin-bottom: 20px;
}
.dash-filter-bar label {
  font-size: .7rem; font-weight: 700; text-transform: uppercase;
  letter-spacing: .1em; color: var(--grey-mid);
}
.dash-filter-bar select {
  font-family: 'Open Sans', sans-serif; font-size: .85rem; font-weight: 600;
  padding: 7px 12px; border: 1.5px solid var(--grey-lt); border-radius: 7px;
  background: var(--white); color: var(--black); cursor: pointer; transition: border-color .15s;
}
.dash-filter-bar select:focus { outline: none; border-color: var(--red); }
.period-toggle { display: flex; background: var(--grey-lt); border-radius: 7px; overflow: hidden; }
.period-toggle a {
  font-size: .72rem; font-weight: 700; text-transform: uppercase;
  letter-spacing: .07em; padding: 7px 14px; text-decoration: none;
  color: var(--grey-dk); transition: all .15s;
}
.period-toggle a.active { background: var(--red-dk); color: var(--white); border-radius: 6px; }
.period-toggle a:hover:not(.active) { background: #ddd; }
.dash-filter-sep { width: 1px; height: 24px; background: var(--grey-lt); }
.bbar-wrap {
  position: relative; flex: 1; height: 8px;
  background: var(--grey-lt); border-radius: 3px; overflow: hidden;
}
.bbar-total { position: absolute; left:0; top:0; bottom:0; background: #d8e8d8; border-radius:3px; }
.bbar-booked { position: absolute; left:0; top:0; bottom:0; background: var(--green); border-radius:3px; }
</style>

<div class="page-header">
  <div>
    <h2>Dashboard</h2>
    <div class="sub"><?= h($periodLabel) ?> · as of <?= date('d M Y') ?></div>
  </div>
  <a href="request_add.php" class="btn btn-red">+ New Request</a>
</div>

<!-- PERIOD FILTER BAR -->
<div class="dash-filter-bar">
  <label>Year</label>
  <select onchange="applyParam('year',this.value)">
    <?php foreach ([(int)date('Y')-1, (int)date('Y'), (int)date('Y')+1] as $y): ?>
      <option value="<?= $y ?>" <?= $y == $year ? 'selected' : '' ?>><?= $y ?></option>
    <?php endforeach; ?>
  </select>

  <div class="dash-filter-sep"></div>

  <label>Period</label>
  <select onchange="applyPeriod(this.value)">
    <option value="year" <?= $period==='year' ? 'selected' : '' ?>>Full Year</option>
    <?php foreach ($months as $num => $name): ?>
      <option value="<?= $num ?>" <?= $period===$num ? 'selected' : '' ?>><?= $name ?></option>
    <?php endforeach; ?>
  </select>

  <?php if ($showToggle): ?>
  <div class="dash-filter-sep"></div>
  <label>View</label>
  <div class="period-toggle">
    <a href="<?= dashUrl(['mode'=>'period']) ?>" class="<?= $mode==='period' ? 'active' : '' ?>">This month</a>
    <a href="<?= dashUrl(['mode'=>'ytd'])   ?>" class="<?= $mode==='ytd'    ? 'active' : '' ?>">YTD</a>
  </div>
  <?php endif; ?>
</div>

<script>
function applyParam(key, val) {
  var u = new URL(location.href);
  u.searchParams.set(key, val);
  location.href = u.toString();
}
function applyPeriod(val) {
  var u = new URL(location.href);
  u.searchParams.set('period', val);
  if (val === 'year') u.searchParams.set('mode', 'period');
  location.href = u.toString();
}
</script>

<!-- STAT CARDS -->
<div class="stat-grid">
  <div class="stat-card blue">
    <div class="stat-label">Requests Received</div>
    <div class="stat-value"><?= $totalCount ?></div>
    <div class="stat-sub"><?= h($periodLabel) ?></div>
  </div>
  <div class="stat-card green">
    <div class="stat-label">Booked</div>
    <div class="stat-value green"><?= $bookedCount ?></div>
    <div class="stat-sub"><?= $lostCount ?> lost</div>
  </div>
  <div class="stat-card amber">
    <div class="stat-label">Sales Rate</div>
    <div class="stat-value"><?= $salesRate ?>%</div>
    <div class="stat-sub">booked / received</div>
  </div>
  <div class="stat-card green">
    <div class="stat-label">Value Sold</div>
    <div class="stat-value" style="font-size:1.35rem">$<?= number_format($totalValue, 0) ?></div>
    <div class="stat-sub">USD confirmed</div>
  </div>
  <div class="stat-card red">
    <div class="stat-label">Commissions</div>
    <div class="stat-value" style="font-size:1.35rem">$<?= number_format($totalComm, 0) ?></div>
    <div class="stat-sub">USD total</div>
  </div>
</div>

<!-- AGENT BREAKDOWN + RECENT -->
<div style="display:grid;grid-template-columns:1fr 2fr;gap:20px;align-items:start">

  <div class="breakdown-card">
    <h3>By Agent — <?= h($periodLabel) ?></h3>
    <div style="font-size:.63rem;color:var(--grey-mid);margin-bottom:10px;text-transform:uppercase;letter-spacing:.07em;">
      Ordered by requests &nbsp;·&nbsp; <span style="color:var(--green);">■</span> booked &nbsp;/ total
    </div>
    <?php if ($agentRows): ?>
      <?php foreach ($agentRows as $row):
        $totalPct  = round($row['total'] / $maxTotal * 100);
        $bookedPct = $row['total'] > 0 ? round($row['booked'] / $maxTotal * 100) : 0;
      ?>
      <div class="breakdown-row">
        <span class="breakdown-agent"><?= h($row['name']) ?></span>
        <div class="bbar-wrap">
          <div class="bbar-total"  style="width:<?= $totalPct  ?>%"></div>
          <div class="bbar-booked" style="width:<?= $bookedPct ?>%"></div>
        </div>
        <span class="breakdown-val"><?= $row['booked'] ?> / <?= $row['total'] ?></span>
        <span class="breakdown-val text-muted">$<?= number_format($row['comm'],0) ?></span>
      </div>
      <?php endforeach; ?>
    <?php else: ?>
      <div class="breakdown-row text-muted">No data for this period.</div>
    <?php endif; ?>
  </div>

  <div>
    <div class="section-label">Recent Requests</div>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Date</th><th>Customer</th><th>Agent</th><th>Status</th><th>Booked Date</th><th>Value (USD)</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($recent): ?>
            <?php foreach ($recent as $r): ?>
            <tr>
              <td class="text-muted"><?= date('d M', strtotime($r['date_received'])) ?></td>
              <td>
                <a href="request_view.php?id=<?= $r['id'] ?>" style="color:var(--black);font-weight:600;text-decoration:none">
                  <?= h($r['customer_name']) ?>
                </a>
                <?php if ($r['practice_code']): ?>
                  <div style="font-size:.68rem;color:var(--grey-mid)"><?= h($r['practice_code']) ?></div>
                <?php endif; ?>
              </td>
              <td class="text-muted"><?= h($r['agent_name'] ?? '—') ?></td>
              <td><span class="badge <?= STATUSES[$r['status']] ?? '' ?>"><?= h($r['status']) ?></span></td>
              <td class="text-muted" style="white-space:nowrap;font-size:.78rem">
                <?php if ($r['status']==='Booked' && !empty($r['confirmation_date'])): ?>
                  <?= date('d M Y', strtotime($r['confirmation_date'])) ?>
                <?php else: ?>
                  —
                <?php endif; ?>
              </td>
              <td class="text-right"><?= $r['value_usd'] ? '$'.number_format($r['value_usd'],0) : '—' ?></td>
            </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr><td colspan="5">
              <div class="empty-state"><div class="icon">📋</div><p>No requests yet.</p></div>
            </td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
    <a href="requests.php" class="btn btn-outline btn-sm">View all requests →</a>
  </div>
</div>

<?php include 'includes/footer.php'; ?>
