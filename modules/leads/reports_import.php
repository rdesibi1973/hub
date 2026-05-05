<?php
require_once 'config.php';
$pageTitle = 'Historical Reports';
$db = db();

// ── Parameters ───────────────────────────────────────────────────
$year         = (int)($_GET['year']  ?? 0);          // 0 = all years
$agent_filter = (int)($_GET['agent'] ?? 0);
$sort         = $_GET['sort'] ?? 'total';
$dir          = $_GET['dir']  ?? 'desc';
$allowed_sort = ['total','confirmed','rate'];
if (!in_array($sort, $allowed_sort)) $sort = 'total';
$dir = ($dir === 'asc') ? 'asc' : 'desc';

// ── Year range ───────────────────────────────────────────────────
$years = $db->query("SELECT DISTINCT YEAR(date_received) y FROM requests_import ORDER BY y DESC")->fetchAll(PDO::FETCH_COLUMN);
if (!$years) $years = [date('Y')];

// ── All agents (include historical NULL ones from import) ─────────
$all_agents     = $db->query("SELECT * FROM agents WHERE active=1 ORDER BY name")->fetchAll();
$all_agents_map = $db->query("SELECT * FROM agents")->fetchAll(PDO::FETCH_ASSOC);

// ── Sort helpers ─────────────────────────────────────────────────
function sortUrl(string $col, string $cur, string $dir, array $params): string {
    $nd = ($cur === $col && $dir === 'desc') ? 'asc' : 'desc';
    return '?' . http_build_query(array_merge($params, ['sort' => $col, 'dir' => $nd]));
}
function sortArrow(string $col, string $cur, string $dir): string {
    if ($cur !== $col) return '<span style="opacity:.3;font-size:.7rem"> ↕</span>';
    return $dir === 'desc' ? '<span style="font-size:.7rem"> ▼</span>' : '<span style="font-size:.7rem"> ▲</span>';
}

// ── Build WHERE ───────────────────────────────────────────────────
$where  = ['1=1'];
$params = [];
if ($year > 0) {
    $where[]  = 'YEAR(date_received) = ?';
    $params[] = $year;
}
if ($agent_filter > 0) {
    $where[]  = 'agent_id = ?';
    $params[] = $agent_filter;
} elseif ($agent_filter === -1) {
    $where[] = 'agent_id IS NULL';
}
$whereStr = implode(' AND ', $where);

// ── Per-agent summary ─────────────────────────────────────────────
// Get all distinct agent_ids in the import table
$agentIds = $db->prepare("SELECT DISTINCT agent_id FROM requests_import WHERE $whereStr ORDER BY agent_id");
$agentIds->execute($params);
$agentIds = $agentIds->fetchAll(PDO::FETCH_COLUMN);

// Build agent id→name map
$agentMap = [];
foreach ($all_agents_map as $a) $agentMap[$a['id']] = $a['name'];

$rows = [];
$totals = ['agent' => 'TOTAL', 'total' => 0, 'confirmed' => 0, 'rate' => 0];

foreach ($agentIds as $aid) {
    $wWhere  = $whereStr . ($aid === null ? ' AND agent_id IS NULL' : ' AND agent_id = ?');
    $wParams = $params;
    if ($aid !== null) $wParams[] = $aid;

    $stmt = $db->prepare("
        SELECT
            COUNT(*)                                    AS total,
            SUM(status IN ('Confirmed','CK','Booked'))  AS confirmed,
            SUM(status = 'Cancelled')                   AS cancelled,
            SUM(status = 'In Progress')                 AS in_progress,
            SUM(status = 'Balance Due')                 AS balance_due
        FROM requests_import
        WHERE $wWhere
    ");
    $stmt->execute($wParams);
    $d = $stmt->fetch();

    $total     = (int)$d['total'];
    $confirmed = (int)$d['confirmed'];
    $rate      = $total > 0 ? round($confirmed / $total * 100, 1) : 0;
    $agentName = $aid ? ($agentMap[$aid] ?? "Agent #$aid") : '— unassigned —';

    // Status breakdown
    $rows[] = [
        'agent_id'    => $aid,
        'agent'       => $agentName,
        'total'       => $total,
        'confirmed'   => $confirmed,
        'cancelled'   => (int)$d['cancelled'],
        'in_progress' => (int)$d['in_progress'],
        'balance_due' => (int)$d['balance_due'],
        'rate'        => $rate,
    ];

    $totals['total']     += $total;
    $totals['confirmed'] += $confirmed;
}
$totals['rate'] = $totals['total'] > 0 ? round($totals['confirmed'] / $totals['total'] * 100, 1) : 0;

// Sort
usort($rows, function($a, $b) use ($sort, $dir) {
    return $dir === 'desc' ? $b[$sort] <=> $a[$sort] : $a[$sort] <=> $b[$sort];
});

// ── Status breakdown totals ───────────────────────────────────────
$statusStmt = $db->prepare("SELECT status, COUNT(*) n FROM requests_import WHERE $whereStr GROUP BY status ORDER BY n DESC");
$statusStmt->execute($params);
$statusBreakdown = $statusStmt->fetchAll();

// ── Year breakdown ────────────────────────────────────────────────
$yearStmt = $db->prepare("SELECT YEAR(date_received) y, COUNT(*) n, SUM(status IN ('Confirmed','CK','Booked')) c FROM requests_import WHERE $whereStr GROUP BY y ORDER BY y DESC");
$yearStmt->execute($params);
$yearBreakdown = $yearStmt->fetchAll();

$base_params = ['year' => $year, 'agent' => $agent_filter];

include 'includes/header.php';
?>

<div style="display:flex;gap:0;border-bottom:2px solid var(--grey-lt);margin-bottom:18px;">
  <a href="reports.php" style="padding:8px 20px;font-size:.82rem;font-weight:600;color:var(--grey-mid);text-decoration:none;">Current (2026)</a>
  <a href="reports_import.php" style="padding:8px 20px;font-size:.82rem;font-weight:700;color:#C0211B;border-bottom:2px solid #C0211B;margin-bottom:-2px;text-decoration:none;">Historical</a>
</div>

<div class="page-header">
  <div style="display:flex;align-items:center;gap:12px;">
    <a href="<?= defined('BASE_URL') ? BASE_URL.'/hub.php' : '../../hub.php' ?>" class="btn btn-outline btn-sm" style="font-size:.72rem;">&#8592; Hub</a>
    <div>
      <h2>Historical Reports</h2>
      <div class="sub">Based on <?= number_format($totals['total']) ?> imported records from Dropbox folders</div>
    </div>
  </div>
</div>

<!-- FILTERS -->
<form method="GET" class="filters" style="margin-bottom:20px;">
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
    <label>Agent</label>
    <select name="agent">
      <option value="0">All agents</option>
      <option value="-1" <?= $agent_filter===-1?'selected':'' ?>>Unassigned</option>
      <?php foreach ($all_agents as $ag): ?>
        <option value="<?= $ag['id'] ?>" <?= $agent_filter===(int)$ag['id']?'selected':'' ?>><?= h($ag['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div>
    <label>&nbsp;</label>
    <button type="submit" class="btn btn-outline">Filter</button>
  </div>
  <div>
    <label>&nbsp;</label>
    <a href="reports_import.php" class="btn btn-outline btn-grey">✕ Clear</a>
  </div>
</form>

<!-- YEAR BREAKDOWN -->
<?php if (!$year): ?>
<div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:24px;">
  <?php foreach ($yearBreakdown as $yb): ?>
  <div style="background:#fff;border-radius:8px;box-shadow:0 1px 6px rgba(0,0,0,.07);padding:14px 20px;min-width:130px;text-align:center;">
    <div style="font-size:1.1rem;font-weight:700;color:var(--red-dk)"><?= $yb['y'] ?></div>
    <div style="font-size:.8rem;color:var(--grey-mid);margin-top:4px"><?= $yb['n'] ?> requests</div>
    <div style="font-size:.75rem;color:#1A6B3A;margin-top:2px"><?= $yb['c'] ?> confirmed</div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- AGENT TABLE -->
<div class="table-wrap">
  <table>
    <thead>
      <tr style="background:var(--green);color:white">
        <th style="background:var(--green);color:white;font-size:.72rem">Agent</th>
        <?php
        $th_cols = ['total' => 'Total Requests', 'confirmed' => 'Confirmed', 'rate' => 'Conv. Rate'];
        foreach ($th_cols as $col => $label):
        ?>
        <th style="background:var(--green);color:white;font-size:.72rem;text-align:right;cursor:pointer;white-space:nowrap"
            onclick="location.href='<?= sortUrl($col, $sort, $dir, $base_params) ?>'">
          <?= $label ?><?= sortArrow($col, $sort, $dir) ?>
        </th>
        <?php endforeach; ?>
        <th style="background:var(--green);color:white;font-size:.72rem;text-align:right">In Progress</th>
        <th style="background:var(--green);color:white;font-size:.72rem;text-align:right">Balance Due</th>
        <th style="background:var(--green);color:white;font-size:.72rem;text-align:right">Cancelled</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
      <tr>
        <td style="font-weight:600"><?= h($r['agent']) ?></td>
        <td style="text-align:right"><?= $r['total'] ?></td>
        <td style="text-align:right;color:#1A6B3A;font-weight:600"><?= $r['confirmed'] ?></td>
        <td style="text-align:right"><?= $r['rate'] ?>%</td>
        <td style="text-align:right;color:var(--grey-mid)"><?= $r['in_progress'] ?></td>
        <td style="text-align:right;color:var(--grey-mid)"><?= $r['balance_due'] ?></td>
        <td style="text-align:right;color:var(--red)"><?= $r['cancelled'] ?></td>
      </tr>
      <?php endforeach; ?>
      <!-- TOTAL ROW -->
      <tr style="font-weight:700;border-top:2px solid var(--grey-lt);background:var(--off-white)">
        <td>TOTAL</td>
        <td style="text-align:right"><?= $totals['total'] ?></td>
        <td style="text-align:right;color:#1A6B3A"><?= $totals['confirmed'] ?></td>
        <td style="text-align:right"><?= $totals['rate'] ?>%</td>
        <td colspan="3"></td>
      </tr>
    </tbody>
  </table>
</div>

<!-- STATUS BREAKDOWN -->
<div style="margin-top:32px;">
  <div class="form-section-title">Status Breakdown</div>
  <div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:12px;">
    <?php foreach ($statusBreakdown as $sb): ?>
    <div style="background:#fff;border-radius:8px;box-shadow:0 1px 6px rgba(0,0,0,.07);padding:12px 18px;text-align:center;min-width:120px;">
      <div style="font-size:1.2rem;font-weight:700;color:var(--red-dk)"><?= $sb['n'] ?></div>
      <div style="font-size:.75rem;color:var(--grey-mid);margin-top:2px"><?= h($sb['status'] ?? '—') ?></div>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<?php include 'includes/footer.php'; ?>
