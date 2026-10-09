<?php

require_once 'config.php';
requireLogin();
if (isLeadsRestricted()) { header('Location: requests.php'); exit; }
$pageTitle = 'Dashboard';

date_default_timezone_set('Africa/Dar_es_Salaam');   // "today" / YTD must follow Arusha, not the US server

// ── Period resolution: one date range, default YTD ───────────────
// ?from=YYYY-MM-DD&to=YYYY-MM-DD. Presets (YTD, full year, single months) just fill the range.
$today = date('Y-m-d');
$curY  = (int)date('Y');
$isYmd = function ($s) {
    if (!is_string($s) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m)) return false;
    return checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
};
$start = $_GET['from'] ?? '';
$end   = $_GET['to']   ?? '';
if (!$isYmd($start) || !$isYmd($end)) { $start = "$curY-01-01"; $end = $today; }
if ($start > $end) [$start, $end] = [$end, $start];

// Presets: [label, from, to]
$presets = [
    'ytd'      => ["YTD $curY",              "$curY-01-01", $today],
    'ytd_prev' => ['YTD ' . ($curY - 1),     ($curY - 1) . '-01-01', ($curY - 1) . substr($today, 4)],
    'year_prev'=> ['Full Year ' . ($curY - 1), ($curY - 1) . '-01-01', ($curY - 1) . '-12-31'],
];
if ($presets['ytd_prev'][2] === ($curY - 1) . '-02-29' && !checkdate(2, 29, $curY - 1)) $presets['ytd_prev'][2] = ($curY - 1) . '-02-28';
$activePreset = null;
foreach ($presets as $k => $p) if ($p[1] === $start && $p[2] === $end) { $activePreset = $k; break; }

// Month quick-picks: this year up to the current month, then all of last year (newest first)
$monthOpts = [];
foreach ([$curY, $curY - 1] as $y) {
    $lastM = $y === $curY ? (int)date('n') : 12;
    for ($mm = $lastM; $mm >= 1; $mm--) {
        $f = sprintf('%04d-%02d-01', $y, $mm);
        $monthOpts[$y][] = [date('F Y', strtotime($f)), $f, date('Y-m-t', strtotime($f))];
    }
}
$isFullMonth = substr($start, 8) === '01' && $end === date('Y-m-t', strtotime($start));

// Human label for the range
$fmt = fn($d, $withYear = true) => date($withYear ? 'j M Y' : 'j M', strtotime($d));
if ($activePreset === 'ytd') {
    $periodLabel = "YTD $curY (1 Jan – " . $fmt($end, false) . ')';
} elseif ($isFullMonth) {
    $periodLabel = date('F Y', strtotime($start));
} elseif (substr($start, 5) === '01-01' && substr($end, 5) === '12-31' && substr($start, 0, 4) === substr($end, 0, 4)) {
    $periodLabel = 'Full Year ' . substr($start, 0, 4);
} elseif ($activePreset === 'ytd_prev') {
    $periodLabel = 'YTD ' . ($curY - 1) . ' (1 Jan – ' . $fmt($end, false) . ')';
} else {
    $periodLabel = $fmt($start, substr($start, 0, 4) !== substr($end, 0, 4)) . ' – ' . $fmt($end);
}

$db = db();

// ── Stats ────────────────────────────────────────────────────────
// received → date_received; booked/value → confirmation_date (closed in the period,
// whenever received) — same split as reports.php
$total = $db->prepare("SELECT COUNT(*) FROM requests WHERE date_received BETWEEN ? AND ?");
$total->execute([$start, $end]);
$totalCount = (int)$total->fetchColumn();

$booked = $db->prepare("SELECT COUNT(*) FROM requests WHERE status='Booked' AND confirmation_date BETWEEN ? AND ? AND (practice_code NOT LIKE '%-STAFF%' OR practice_code IS NULL)");
$booked->execute([$start, $end]);
$bookedCount = (int)$booked->fetchColumn();

// Sales rate = booked in the period (confirmation_date, whenever received) /
// received in the period. STAFF excluded from both, as in reports.php.
$recvNoStaff = $db->prepare("SELECT COUNT(*) FROM requests WHERE date_received BETWEEN ? AND ? AND (practice_code NOT LIKE '%-STAFF%' OR practice_code IS NULL)");
$recvNoStaff->execute([$start, $end]);
$convRecv  = (int)$recvNoStaff->fetchColumn();
$salesRate = $convRecv > 0 ? round($bookedCount / $convRecv * 100, 1) : 0;

$value = $db->prepare("SELECT COALESCE(SUM(value_usd),0) FROM requests WHERE status='Booked' AND confirmation_date BETWEEN ? AND ? AND (practice_code NOT LIKE '%-STAFF%' OR practice_code IS NULL)");
$value->execute([$start, $end]);
$totalValue = (float)$value->fetchColumn();

$comm = $db->prepare("SELECT COALESCE(SUM(commission_usd),0) FROM requests WHERE date_received BETWEEN ? AND ?");
$comm->execute([$start, $end]);
$totalComm = (float)$comm->fetchColumn();

$lost = $db->prepare("SELECT COUNT(*) FROM requests WHERE status='Lost' AND date_received BETWEEN ? AND ?");
$lost->execute([$start, $end]);
$lostCount = (int)$lost->fetchColumn();

// ── Per-agent breakdown — sorted by total requests received ──────
// total/comm by date_received, booked by confirmation_date (may exceed total)
$byAgent = $db->prepare("
    SELECT * FROM (
        SELECT a.name,
               (SELECT COUNT(*) FROM requests r
                 WHERE r.agent_id = a.id AND r.date_received BETWEEN ? AND ?) AS total,
               (SELECT COUNT(*) FROM requests r
                 WHERE r.agent_id = a.id AND r.status = 'Booked'
                   AND r.confirmation_date BETWEEN ? AND ?
                   AND (r.practice_code NOT LIKE '%-STAFF%' OR r.practice_code IS NULL)) AS booked,
               (SELECT COALESCE(SUM(r.commission_usd),0) FROM requests r
                 WHERE r.agent_id = a.id AND r.date_received BETWEEN ? AND ?) AS comm
        FROM agents a
        WHERE a.active = 1
    ) t
    WHERE total > 0 OR booked > 0
    ORDER BY total DESC, booked DESC
");
$byAgent->execute([$start, $end, $start, $end, $start, $end]);
$agentRows = $byAgent->fetchAll();

$maxTotal = $agentRows
    ? max(1, ...array_map(fn($r) => max((int)$r['total'], (int)$r['booked']), $agentRows))
    : 1;

// ── Recent requests (last 10) ────────────────────────────────────
$recent = $db->query("
    SELECT r.*, a.name AS agent_name
    FROM requests r
    LEFT JOIN agents a ON a.id = r.agent_id
    ORDER BY r.created_at DESC
    LIMIT 10
")->fetchAll();

// ── URL helper ───────────────────────────────────────────────────
function rangeUrl($from, $to) {
    return '?' . http_build_query(['from' => $from, 'to' => $to]);
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
.dash-filter-bar select, .dash-filter-bar input[type=date] {
  font-family: 'Open Sans', sans-serif; font-size: .85rem; font-weight: 600;
  padding: 7px 12px; border: 1.5px solid var(--grey-lt); border-radius: 7px;
  background: var(--white); color: var(--black); cursor: pointer; transition: border-color .15s;
}
.dash-filter-bar select:focus, .dash-filter-bar input[type=date]:focus { outline: none; border-color: var(--red); }
.dash-filter-bar input[type=date] { cursor: text; padding: 6px 10px; }
.dash-range-form { display: flex; align-items: center; gap: 8px; margin: 0; }
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

<!-- PERIOD FILTER BAR — one date range; presets and months just fill it -->
<div class="dash-filter-bar">
  <div class="period-toggle">
    <?php foreach ($presets as $k => $p): ?>
      <a href="<?= rangeUrl($p[1], $p[2]) ?>" class="<?= $activePreset === $k ? 'active' : '' ?>"><?= h($p[0]) ?></a>
    <?php endforeach; ?>
  </div>

  <div class="dash-filter-sep"></div>

  <label>Month</label>
  <select autocomplete="off" onchange="if (this.value) location.href = this.value">
    <option value="">—</option>
    <?php foreach ($monthOpts as $y => $opts): ?>
      <optgroup label="<?= $y ?>">
        <?php foreach ($opts as $o): ?>
          <option value="<?= h(rangeUrl($o[1], $o[2])) ?>" <?= $o[1] === $start && $o[2] === $end ? 'selected' : '' ?>><?= h($o[0]) ?></option>
        <?php endforeach; ?>
      </optgroup>
    <?php endforeach; ?>
  </select>

  <div class="dash-filter-sep"></div>

  <form method="get" class="dash-range-form">
    <label for="dash-from">From</label>
    <input type="date" id="dash-from" name="from" value="<?= h($start) ?>" autocomplete="off" required>
    <label for="dash-to">To</label>
    <input type="date" id="dash-to" name="to" value="<?= h($end) ?>" autocomplete="off" required>
    <button type="submit" class="btn btn-outline btn-sm">Apply</button>
  </form>
</div>

<script>
// Back/forward cache can restore stale filter values: reload to resync with the URL
window.addEventListener('pageshow', function (e) { if (e.persisted) location.reload(); });
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
    <div class="stat-sub"><?= $bookedCount ?> booked / <?= $convRecv ?> received</div>
  </div>
  <div class="stat-card green">
    <div class="stat-label">Value Sold</div>
    <div class="stat-value" style="font-size:1.35rem">$<?= number_format($totalValue, 0) ?></div>
    <div class="stat-sub">USD confirmed</div>
  </div>
  <?php if (defined('SHOW_COMMISSIONS') && SHOW_COMMISSIONS): ?>
  <div class="stat-card red">
    <div class="stat-label">Commissions</div>
    <div class="stat-value" style="font-size:1.35rem">$<?= number_format($totalComm, 0) ?></div>
    <div class="stat-sub">USD total</div>
  </div>
  <?php endif; ?>
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
        $bookedPct = round($row['booked'] / $maxTotal * 100);
      ?>
      <div class="breakdown-row">
        <span class="breakdown-agent"><?= h($row['name']) ?></span>
        <div class="bbar-wrap">
          <div class="bbar-total"  style="width:<?= $totalPct  ?>%"></div>
          <div class="bbar-booked" style="width:<?= $bookedPct ?>%"></div>
        </div>
        <span class="breakdown-val"><?= $row['booked'] ?> / <?= $row['total'] ?></span>
        <?php if (defined('SHOW_COMMISSIONS') && SHOW_COMMISSIONS): ?>
        <span class="breakdown-val text-muted">$<?= number_format($row['comm'],0) ?></span>
        <?php endif; ?>
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
