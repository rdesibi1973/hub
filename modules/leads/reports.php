<?php
require_once 'config.php';
requireLogin();
if (isLeadsRestricted()) { header('Location: requests.php'); exit; }
$pageTitle = 'Reports';
$db = db();

// ── Parameters ───────────────────────────────────────────────────
$mode          = $_GET['mode']          ?? 'monthly';   // monthly | global | range
$report_type   = $_GET['rtype']         ?? 'sales';     // sales | travel
// Travel period only makes sense month by month
if ($report_type === 'travel' && $mode !== 'monthly') $mode = 'monthly';
$year          = (int)($_GET['year']    ?? date('Y'));
$month         = (int)($_GET['month']   ?? date('n'));
$range_from    = $_GET['from']          ?? date('Y-01-01');
$range_to      = $_GET['to']            ?? date('Y-m-d');
$agent_filter  = (int)($_GET['agent']   ?? 0);
$sort          = $_GET['sort']          ?? 'total';
$dir           = $_GET['dir']           ?? 'desc';

// ── Available years — merge live + history ────────────────────────
$live_years = $db->query("SELECT DISTINCT YEAR(date_received) y FROM requests ORDER BY y DESC")
                 ->fetchAll(PDO::FETCH_COLUMN);
$hist_years = $db->query("SELECT DISTINCT YEAR(date_received) y FROM requests_import ORDER BY y DESC")
                 ->fetchAll(PDO::FETCH_COLUMN);

// year(int) => 'live' | 'history'  — live wins if year present in both
$all_years_map = [];
foreach ((array)$hist_years as $y) $all_years_map[(int)$y] = 'history';
foreach ((array)$live_years  as $y) $all_years_map[(int)$y] = 'live';
krsort($all_years_map);
$years = array_keys($all_years_map);
if (!$years) $years = [date('Y')];
if (!in_array($year, $years)) $year = $years[0];

$data_source = $all_years_map[$year] ?? 'live';
$is_history  = ($data_source === 'history');

// ── Sort validation — history table has no financial columns ──────
$allowed_sort = $is_history
    ? ['total','confirmed','rate','cancelled','in_progress','balance_due']
    : ['total','confirmed','rate','sales_amount','booked_pax','commission_total'];
if (!in_array($sort, $allowed_sort)) $sort = 'total';
$dir = ($dir === 'asc') ? 'asc' : 'desc';

// ── All active agents ─────────────────────────────────────────────
$all_agents = $db->query("SELECT * FROM agents WHERE active=1 ORDER BY name")->fetchAll();
$agents = $agent_filter > 0
    ? array_filter($all_agents, fn($a) => (int)$a['id'] === $agent_filter)
    : $all_agents;

// ── Request destinations (live table only) ────────────────────────
$dest_rows = $db->query("SELECT DISTINCT destination FROM requests WHERE destination IS NOT NULL ORDER BY destination")->fetchAll(PDO::FETCH_COLUMN);
$dest_list = $dest_rows ?: [];

// ── Helper: summary from LIVE table (requests) ───────────────────
// total/by_dest  → date_received  (volume of incoming requests)
// confirmed/sales/pax/commission → confirmation_date (closed deals)
function buildSummary(PDO $db, string $from, string $to, array $agents, array $dest_list): array {
    $rows = [];
    $totals = ['agent' => 'TOTAL', 'total' => 0, 'confirmed' => 0, 'rate' => 0, 'by_dest' => [],
               'sales_amount' => 0, 'booked_pax' => 0, 'commission_total' => 0];

    foreach ($agents as $ag) {
        // Query 1: total received + destination breakdown (date_received)
        $stmt1 = $db->prepare("
            SELECT COUNT(*) AS total, destination
            FROM requests
            WHERE agent_id = ? AND date_received BETWEEN ? AND ?
            GROUP BY destination
        ");
        $stmt1->execute([$ag['id'], $from, $to]);
        $recv_data = $stmt1->fetchAll();

        $total = 0; $by_dest = [];
        foreach ($recv_data as $d) {
            $total += (int)$d['total'];
            if ($d['destination'] !== null) {
                $key = $d['destination'];
                $by_dest[$key] = ($by_dest[$key] ?? 0) + (int)$d['total'];
            }
        }

        // Query 2: confirmed/sales/pax/commission (confirmation_date)
        $stmt2 = $db->prepare("
            SELECT COUNT(*) AS confirmed,
                   SUM(value_usd)      AS sales_amount,
                   SUM(pax)            AS booked_pax,
                   SUM(commission_usd) AS commission_total
            FROM requests
            WHERE agent_id = ? AND status = 'Booked'
              AND confirmation_date BETWEEN ? AND ?
        ");
        $stmt2->execute([$ag['id'], $from, $to]);
        $conf_data = $stmt2->fetch();

        $confirmed        = (int)($conf_data['confirmed']        ?? 0);
        $sales_amount     = (float)($conf_data['sales_amount']   ?? 0);
        $booked_pax       = (int)($conf_data['booked_pax']       ?? 0);
        $commission_total = (float)($conf_data['commission_total'] ?? 0);

        $rate = $total > 0 ? round($confirmed / $total * 100, 1) : 0;
        $rows[] = [
            'agent_id' => $ag['id'], 'agent' => $ag['name'],
            'total' => $total, 'confirmed' => $confirmed, 'rate' => $rate,
            'by_dest' => $by_dest, 'sales_amount' => $sales_amount,
            'booked_pax' => $booked_pax, 'commission_total' => $commission_total,
        ];
        $totals['total']            += $total;
        $totals['confirmed']        += $confirmed;
        $totals['sales_amount']     += $sales_amount;
        $totals['booked_pax']       += $booked_pax;
        $totals['commission_total'] += $commission_total;
        foreach ($by_dest as $k => $v)
            $totals['by_dest'][$k] = ($totals['by_dest'][$k] ?? 0) + $v;
    }

    // Unassigned
    $stmt1 = $db->prepare("
        SELECT COUNT(*) AS total, destination
        FROM requests WHERE agent_id IS NULL AND date_received BETWEEN ? AND ?
        GROUP BY destination
    ");
    $stmt1->execute([$from, $to]);
    $udata = $stmt1->fetchAll();
    $u_total = 0; $u_dest = [];
    foreach ($udata as $d) {
        $u_total += (int)$d['total'];
        if ($d['destination'] !== null)
            $u_dest[$d['destination']] = ($u_dest[$d['destination']] ?? 0) + (int)$d['total'];
    }

    $stmt2 = $db->prepare("
        SELECT COUNT(*) AS confirmed,
               SUM(value_usd)      AS sales_amount,
               SUM(pax)            AS booked_pax,
               SUM(commission_usd) AS commission_total
        FROM requests
        WHERE agent_id IS NULL AND status = 'Booked'
          AND confirmation_date BETWEEN ? AND ?
    ");
    $stmt2->execute([$from, $to]);
    $uconf = $stmt2->fetch();
    $u_conf  = (int)($uconf['confirmed']        ?? 0);
    $u_sales = (float)($uconf['sales_amount']   ?? 0);
    $u_pax   = (int)($uconf['booked_pax']       ?? 0);
    $u_comm  = (float)($uconf['commission_total'] ?? 0);

    if ($u_total > 0 || $u_conf > 0) {
        $rows[] = [
            'agent_id' => -1, 'agent' => 'Unassigned',
            'total' => $u_total, 'confirmed' => $u_conf,
            'rate' => $u_total > 0 ? round($u_conf / $u_total * 100, 1) : 0,
            'by_dest' => $u_dest, 'sales_amount' => $u_sales,
            'booked_pax' => $u_pax, 'commission_total' => $u_comm,
        ];
        $totals['total'] += $u_total; $totals['confirmed'] += $u_conf;
        $totals['sales_amount'] += $u_sales; $totals['booked_pax'] += $u_pax;
        $totals['commission_total'] += $u_comm;
        foreach ($u_dest as $k => $v)
            $totals['by_dest'][$k] = ($totals['by_dest'][$k] ?? 0) + $v;
    }
    $totals['rate'] = $totals['total'] > 0 ? round($totals['confirmed'] / $totals['total'] * 100, 1) : 0;
    return ['rows' => $rows, 'totals' => $totals];
}

// ── Helper: summary from HISTORY table (requests_import) ─────────
function buildSummaryHistory(PDO $db, string $from, string $to, array $agents): array {
    $rows   = [];
    $totals = ['agent' => 'TOTAL', 'total' => 0, 'confirmed' => 0, 'rate' => 0,
               'cancelled' => 0, 'in_progress' => 0, 'balance_due' => 0];

    $agentMap = [];
    foreach ($agents as $a) $agentMap[$a['id']] = $a['name'];

    foreach ($agents as $ag) {
        $stmt = $db->prepare("
            SELECT COUNT(*) AS total,
                   SUM(status IN ('Confirmed','CK','Booked')) AS confirmed,
                   SUM(status = 'Cancelled')   AS cancelled,
                   SUM(status = 'In Progress') AS in_progress,
                   SUM(status = 'Balance Due') AS balance_due
            FROM requests_import
            WHERE agent_id = ? AND date_received BETWEEN ? AND ?
        ");
        $stmt->execute([$ag['id'], $from, $to]);
        $d = $stmt->fetch();
        $total = (int)$d['total'];
        if ($total === 0) continue;
        $confirmed = (int)$d['confirmed'];
        $rows[] = [
            'agent_id' => $ag['id'], 'agent' => $ag['name'],
            'total' => $total, 'confirmed' => $confirmed,
            'rate' => round($confirmed / $total * 100, 1),
            'cancelled' => (int)$d['cancelled'],
            'in_progress' => (int)$d['in_progress'],
            'balance_due' => (int)$d['balance_due'],
        ];
        $totals['total']       += $total;
        $totals['confirmed']   += $confirmed;
        $totals['cancelled']   += (int)$d['cancelled'];
        $totals['in_progress'] += (int)$d['in_progress'];
        $totals['balance_due'] += (int)$d['balance_due'];
    }

    // Unassigned
    $stmt = $db->prepare("
        SELECT COUNT(*) AS total,
               SUM(status IN ('Confirmed','CK','Booked')) AS confirmed,
               SUM(status = 'Cancelled')   AS cancelled,
               SUM(status = 'In Progress') AS in_progress,
               SUM(status = 'Balance Due') AS balance_due
        FROM requests_import
        WHERE agent_id IS NULL AND date_received BETWEEN ? AND ?
    ");
    $stmt->execute([$from, $to]);
    $d = $stmt->fetch();
    $u_total = (int)$d['total'];
    if ($u_total > 0) {
        $u_conf = (int)$d['confirmed'];
        $rows[] = [
            'agent_id' => -1, 'agent' => 'Unassigned',
            'total' => $u_total, 'confirmed' => $u_conf,
            'rate' => round($u_conf / $u_total * 100, 1),
            'cancelled' => (int)$d['cancelled'],
            'in_progress' => (int)$d['in_progress'],
            'balance_due' => (int)$d['balance_due'],
        ];
        $totals['total']       += $u_total;
        $totals['confirmed']   += $u_conf;
        $totals['cancelled']   += (int)$d['cancelled'];
        $totals['in_progress'] += (int)$d['in_progress'];
        $totals['balance_due'] += (int)$d['balance_due'];
    }
    $totals['rate'] = $totals['total'] > 0 ? round($totals['confirmed'] / $totals['total'] * 100, 1) : 0;
    return ['rows' => $rows, 'totals' => $totals];
}

// ── Build data ────────────────────────────────────────────────────
$summary   = null;
$title_str = '';

$sortFn = function($a, $b) use ($sort, $dir) {
    $va = $a[$sort] ?? 0;
    $vb = $b[$sort] ?? 0;
    return $dir === 'desc' ? $vb <=> $va : $va <=> $vb;
};

if ($mode === 'monthly') {
    $from = sprintf('%04d-%02d-01', $year, $month);
    $to   = date('Y-m-t', strtotime($from));
    $summary = $is_history
        ? buildSummaryHistory($db, $from, $to, $agents)
        : buildSummary($db, $from, $to, $agents, $dest_list);
    usort($summary['rows'], $sortFn);
    $title_str   = date('F Y', strtotime($from));
    $report_from = $from; $report_to = $to;

} elseif ($mode === 'global') {
    if ($is_history) {
        $bounds = $db->prepare("SELECT MIN(date_received) mn, MAX(date_received) mx FROM requests_import WHERE YEAR(date_received) = ?");
        $bounds->execute([$year]);
        $b    = $bounds->fetch();
        $from = $b['mn'] ?? "$year-01-01";
        $to   = $b['mx'] ?? "$year-12-31";
        $summary   = buildSummaryHistory($db, $from, $to, $agents);
        $title_str = "Full Year $year — Historical";
    } else {
        $bounds = $db->query("SELECT MIN(date_received) AS mn, MAX(date_received) AS mx FROM requests")->fetch();
        $from = $bounds['mn'] ?? date('Y-01-01');
        $to   = $bounds['mx'] ?? date('Y-m-d');
        $summary   = buildSummary($db, $from, $to, $agents, $dest_list);
        $title_str = 'Grand Total — All Time';
    }
    usort($summary['rows'], $sortFn);
    $report_from = $from; $report_to = $to;

} elseif ($mode === 'range') {
    $from = $range_from;
    $to   = $range_to;
    $summary     = buildSummary($db, $from, $to, $agents, $dest_list);
    usort($summary['rows'], $sortFn);
    $title_str   = date('d M Y', strtotime($from)) . ' → ' . date('d M Y', strtotime($to));
    $report_from = $from; $report_to = $to;
}

// ── Travel Period report data (report_type=travel, monthly only) ──
$travel_summary = null;
$travel_no_date = 0;
if ($report_type === 'travel' && !$is_history) {
    $tp_from = sprintf('%04d-%02d-01', $year, $month);
    $tp_to   = date('Y-m-t', strtotime($tp_from));

    // Fetch all Booked requests with start_date in the selected month
    $tp_rows = $db->prepare("
        SELECT r.agent_id, r.pax, r.value_usd, r.customer_name, r.start_date
        FROM requests r
        WHERE r.status = 'Booked'
          AND r.start_date BETWEEN ? AND ?
    ");
    $tp_rows->execute([$tp_from, $tp_to]);
    $tp_data = $tp_rows->fetchAll(PDO::FETCH_ASSOC);

    // Count Booked requests with no start_date (for warning)
    $travel_no_date = (int)$db->query("
        SELECT COUNT(*) FROM requests WHERE status='Booked' AND start_date IS NULL
    ")->fetchColumn();

    // Aggregate by agent
    $tp_by_agent = [];
    foreach ($tp_data as $r) {
        $aid = (int)($r['agent_id'] ?? 0);
        if (!isset($tp_by_agent[$aid])) {
            $tp_by_agent[$aid] = ['trips'=>0,'pax'=>0,'sales'=>0.0];
        }
        $tp_by_agent[$aid]['trips']++;
        $tp_by_agent[$aid]['pax']   += (int)$r['pax'];
        $tp_by_agent[$aid]['sales'] += (float)$r['value_usd'];
    }

    // Build rows per agent
    $tp_agent_rows = [];
    foreach ($all_agents as $ag) {
        $aid = (int)$ag['id'];
        if ($agent_filter > 0 && $aid !== $agent_filter) continue;
        $d = $tp_by_agent[$aid] ?? ['trips'=>0,'pax'=>0,'sales'=>0.0];
        $tp_agent_rows[] = ['name'=>$ag['name'],'trips'=>$d['trips'],'pax'=>$d['pax'],'sales'=>$d['sales']];
    }
    // Unassigned
    $d = $tp_by_agent[0] ?? ['trips'=>0,'pax'=>0,'sales'=>0.0];
    if ($d['trips'] > 0) {
        $tp_agent_rows[] = ['name'=>'— Unassigned —','trips'=>$d['trips'],'pax'=>$d['pax'],'sales'=>$d['sales']];
    }

    // Sort by trips desc
    usort($tp_agent_rows, function($a,$b){ return $b['trips'] - $a['trips']; });

    $travel_summary = [
        'rows'       => $tp_agent_rows,
        'totals'     => [
            'trips' => array_sum(array_column($tp_agent_rows,'trips')),
            'pax'   => array_sum(array_column($tp_agent_rows,'pax')),
            'sales' => array_sum(array_column($tp_agent_rows,'sales')),
        ],
    ];
}

// ── Month names ───────────────────────────────────────────────────
$month_names = [
    1=>'January',2=>'February',3=>'March',4=>'April',
    5=>'May',6=>'June',7=>'July',8=>'August',
    9=>'September',10=>'October',11=>'November',12=>'December'
];

// Sort URL helper
function sortUrl(string $col, string $curSort, string $curDir, array $params): string {
    $newDir = ($curSort === $col && $curDir === 'desc') ? 'asc' : 'desc';
    return '?' . http_build_query(array_merge($params, ['sort' => $col, 'dir' => $newDir]));
}
function sortArrow(string $col, string $curSort, string $curDir): string {
    if ($curSort !== $col) return '<span style="opacity:.3;font-size:.7rem"> ↕</span>';
    return $curDir === 'desc'
        ? '<span style="font-size:.7rem"> ▼</span>'
        : '<span style="font-size:.7rem"> ▲</span>';
}

include 'includes/header.php';
?>

<div class="page-header">
  <div>
    <h2>Reports</h2>
    <div class="sub"><?= h($title_str) ?></div>
    <?php if ($report_type === 'sales' && !$is_history): ?>
    <div style="font-size:.72rem;color:var(--grey-mid);margin-top:3px">
      Received = requests received in period &nbsp;·&nbsp; Confirmed + Sales = deals closed in period (by confirmation date)
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- ── FILTER BAR ─────────────────────────────────────────────── -->
<div style="background:var(--white);border-radius:10px;padding:18px 20px;
            box-shadow:0 1px 6px rgba(0,0,0,.06);margin-bottom:<?= $is_history ? '8' : '24' ?>px;">

  <!-- Report type tabs -->
  <?php if (!$is_history): ?>
  <div style="display:flex;gap:8px;margin-bottom:14px;">
    <a href="?mode=<?= $mode ?>&rtype=sales&year=<?= $year ?>&month=<?= $month ?>&from=<?= h($range_from) ?>&to=<?= h($range_to) ?>&agent=<?= $agent_filter ?>"
       class="btn btn-sm <?= $report_type==='sales' ? 'btn-red' : 'btn-outline' ?>">
      📊 Sales Report
    </a>
    <a href="?mode=monthly&rtype=travel&year=<?= $year ?>&month=<?= $month ?>&agent=<?= $agent_filter ?>"
       class="btn btn-sm <?= $report_type==='travel' ? 'btn-red' : 'btn-outline' ?>">
      ✈️ Travel Period
    </a>
  </div>
  <?php endif; ?>

  <!-- Mode tabs -->
  <div style="display:flex;gap:8px;margin-bottom:16px;border-bottom:1px solid var(--grey-lt);padding-bottom:14px;">
    <?php
    $tabs = ['monthly' => 'Monthly', 'global' => $is_history ? "Full Year $year" : 'Grand Total', 'range' => 'Date Range'];
    foreach ($tabs as $k => $label):
    ?>
    <a href="?mode=<?= $k ?>&rtype=<?= h($report_type) ?>&year=<?= $year ?>&month=<?= $month ?>&from=<?= h($range_from) ?>&to=<?= h($range_to) ?>&agent=<?= $agent_filter ?>"
       class="btn btn-sm <?= $mode===$k ? 'btn-red' : 'btn-outline' ?>">
      <?= $label ?>
    </a>
    <?php endforeach; ?>
  </div>

  <!-- Mode-specific controls -->
  <?php if ($mode === 'monthly'): ?>
  <form method="GET" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap">
    <input type="hidden" name="mode" value="monthly">
    <div>
      <label style="font-size:.72rem;font-weight:700;color:var(--grey-dk);display:block;margin-bottom:4px">Year</label>
      <select name="year" onchange="this.form.submit()"
              style="padding:8px 12px;border:1.5px solid var(--grey-lt);border-radius:6px;font-family:'Open Sans',sans-serif;font-size:.82rem">
        <?php foreach ($all_years_map as $y => $src): ?>
          <option value="<?= $y ?>" <?= $y===$year?'selected':'' ?>>
            <?= $y ?><?= $src === 'history' ? ' (hist.)' : '' ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label style="font-size:.72rem;font-weight:700;color:var(--grey-dk);display:block;margin-bottom:4px">Month</label>
      <select name="month" style="padding:8px 12px;border:1.5px solid var(--grey-lt);border-radius:6px;font-family:'Open Sans',sans-serif;font-size:.82rem">
        <?php foreach ($month_names as $n => $name): ?>
          <option value="<?= $n ?>" <?= $n===$month?'selected':'' ?>><?= $name ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label style="font-size:.72rem;font-weight:700;color:var(--grey-dk);display:block;margin-bottom:4px">Agent</label>
      <select name="agent" style="padding:8px 12px;border:1.5px solid var(--grey-lt);border-radius:6px;font-family:'Open Sans',sans-serif;font-size:.82rem">
        <option value="0">All agents</option>
        <?php foreach ($all_agents as $ag): ?>
          <option value="<?= $ag['id'] ?>" <?= $agent_filter===$ag['id']?'selected':'' ?>><?= h($ag['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <button type="submit" class="btn btn-red btn-sm">View</button>
  </form>

  <!-- Month quick-nav -->
  <div style="display:flex;gap:6px;margin-top:14px;flex-wrap:wrap">
    <?php foreach ($month_names as $n => $name):
      $from_chk = sprintf('%04d-%02d-01', $year, $n);
      $to_chk   = date('Y-m-t', strtotime($from_chk));
      if ($is_history) {
          $cnt = $db->prepare("SELECT COUNT(*) FROM requests_import WHERE date_received BETWEEN ? AND ?");
      } else {
          $cnt = $db->prepare("SELECT COUNT(*) FROM requests WHERE date_received BETWEEN ? AND ?");
      }
      $cnt->execute([$from_chk, $to_chk]);
      $has = (int)$cnt->fetchColumn() > 0;
    ?>
    <a href="?mode=monthly&year=<?= $year ?>&month=<?= $n ?>&agent=<?= $agent_filter ?>"
       class="btn btn-sm <?= $n===$month?'btn-red':($has?'btn-outline':'') ?>"
       style="<?= !$has && $n!==$month ? 'color:var(--grey-lt);border-color:var(--grey-lt);cursor:default' : '' ?>">
      <?= substr($name,0,3) ?>
    </a>
    <?php endforeach; ?>
  </div>

  <?php elseif ($mode === 'global'): ?>
  <form method="GET" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap">
    <input type="hidden" name="mode" value="global">
    <div>
      <label style="font-size:.72rem;font-weight:700;color:var(--grey-dk);display:block;margin-bottom:4px">Year</label>
      <select name="year" onchange="this.form.submit()"
              style="padding:8px 12px;border:1.5px solid var(--grey-lt);border-radius:6px;font-family:'Open Sans',sans-serif;font-size:.82rem">
        <?php foreach ($all_years_map as $y => $src): ?>
          <option value="<?= $y ?>" <?= $y===$year?'selected':'' ?>>
            <?= $y ?><?= $src === 'history' ? ' (hist.)' : '' ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label style="font-size:.72rem;font-weight:700;color:var(--grey-dk);display:block;margin-bottom:4px">Agent</label>
      <select name="agent" style="padding:8px 12px;border:1.5px solid var(--grey-lt);border-radius:6px;font-family:'Open Sans',sans-serif;font-size:.82rem">
        <option value="0">All agents</option>
        <?php foreach ($all_agents as $ag): ?>
          <option value="<?= $ag['id'] ?>" <?= $agent_filter===$ag['id']?'selected':'' ?>><?= h($ag['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <button type="submit" class="btn btn-red btn-sm">View</button>
  </form>

  <?php elseif ($mode === 'range'): ?>
  <form method="GET" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap">
    <input type="hidden" name="mode" value="range">
    <div>
      <label style="font-size:.72rem;font-weight:700;color:var(--grey-dk);display:block;margin-bottom:4px">From</label>
      <input type="date" name="from" value="<?= h($range_from) ?>"
             style="padding:8px 12px;border:1.5px solid var(--grey-lt);border-radius:6px;font-family:'Open Sans',sans-serif;font-size:.82rem">
    </div>
    <div>
      <label style="font-size:.72rem;font-weight:700;color:var(--grey-dk);display:block;margin-bottom:4px">To</label>
      <input type="date" name="to" value="<?= h($range_to) ?>"
             style="padding:8px 12px;border:1.5px solid var(--grey-lt);border-radius:6px;font-family:'Open Sans',sans-serif;font-size:.82rem">
    </div>
    <div>
      <label style="font-size:.72rem;font-weight:700;color:var(--grey-dk);display:block;margin-bottom:4px">Agent</label>
      <select name="agent" style="padding:8px 12px;border:1.5px solid var(--grey-lt);border-radius:6px;font-family:'Open Sans',sans-serif;font-size:.82rem">
        <option value="0">All agents</option>
        <?php foreach ($all_agents as $ag): ?>
          <option value="<?= $ag['id'] ?>" <?= $agent_filter===$ag['id']?'selected':'' ?>><?= h($ag['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <button type="submit" class="btn btn-red btn-sm">View</button>
  </form>
  <?php endif; ?>
</div>

<!-- ── HISTORICAL DATA BANNER ──────────────────────────────────── -->
<?php if ($is_history): ?>
<div style="background:#fff8e6;border:1.5px solid #f0c040;border-radius:8px;
            padding:10px 16px;margin-bottom:20px;display:flex;align-items:center;gap:10px;font-size:.82rem;">
  <span style="font-size:1.1rem">📂</span>
  <span>
    <strong>Historical data (<?= $year ?>)</strong> — sourced from the imported records archive.
    Sales amounts and PAX figures are not available for this period.
  </span>
</div>
<?php endif; ?>

<!-- ── SUMMARY TABLE ──────────────────────────────────────────── -->
<?php if ($summary && $report_type === 'sales'): ?>
<?php
  $rows   = $summary['rows'];
  $totals = $summary['totals'];

  $has_data = $totals['total'] > 0;

  // Destination columns (live only)
  $all_dests = [];
  if (!$is_history) {
      $dest_order = ['Safari','Kilimanjaro','Safari+Beach','Meru Trekking','Tailor-made','Other'];
      foreach ($dest_order as $d) {
          if (isset($totals['by_dest'][$d]) && $totals['by_dest'][$d] > 0) $all_dests[] = $d;
      }
      foreach (array_keys($totals['by_dest']) as $d) {
          if (!in_array($d, $all_dests) && $totals['by_dest'][$d] > 0) $all_dests[] = $d;
      }
  }

  $base_params = ['mode'=>$mode,'year'=>$year,'month'=>$month,'from'=>$range_from,'to'=>$range_to,'agent'=>$agent_filter];
?>

<?php if (!$has_data): ?>
  <div class="empty-state">
    <div class="icon">📊</div>
    <p>No requests found for this period.</p>
  </div>
<?php else: ?>

<!-- KPI row (Sales Report only) -->
<?php if ($report_type === 'sales'): ?>
<div class="stat-grid" style="margin-bottom:20px">
  <div class="stat-card blue">
    <div class="stat-label">Received</div>
    <div class="stat-value"><?= $totals['total'] ?></div>
  </div>
  <div class="stat-card green">
    <div class="stat-label">Confirmed</div>
    <div class="stat-value green"><?= $totals['confirmed'] ?></div>
  </div>
  <div class="stat-card amber">
    <div class="stat-label">Sales Rate</div>
    <div class="stat-value"><?= $totals['rate'] ?>%</div>
  </div>
  <?php if (!$is_history): ?>
  <div class="stat-card green">
    <div class="stat-label">Sales Amount</div>
    <div class="stat-value" style="font-size:1.3rem">$<?= number_format($totals['sales_amount'],0) ?></div>
  </div>
  <div class="stat-card blue">
    <div class="stat-label">PAX (Booked)</div>
    <div class="stat-value"><?= $totals['booked_pax'] ?></div>
  </div>
  <div class="stat-card amber">
    <div class="stat-label">Total Comm</div>
    <div class="stat-value" style="font-size:1.3rem">$<?= number_format($totals['commission_total'],0) ?></div>
  </div>
  <?php foreach ($all_dests as $d): ?>
  <div class="stat-card">
    <div class="stat-label"><?= h($d) ?></div>
    <div class="stat-value" style="font-size:1.4rem"><?= $totals['by_dest'][$d] ?? 0 ?></div>
  </div>
  <?php endforeach; ?>
  <?php else: ?>
  <div class="stat-card" style="border-left:4px solid #e05c1a;">
    <div class="stat-label">In Progress</div>
    <div class="stat-value" style="color:#e05c1a"><?= $totals['in_progress'] ?></div>
  </div>
  <div class="stat-card" style="border-left:4px solid #1a6bb3;">
    <div class="stat-label">Balance Due</div>
    <div class="stat-value" style="color:#1a6bb3"><?= $totals['balance_due'] ?></div>
  </div>
  <div class="stat-card" style="border-left:4px solid var(--red);">
    <div class="stat-label">Cancelled</div>
    <div class="stat-value" style="color:var(--red)"><?= $totals['cancelled'] ?></div>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- Detail table -->
<div class="table-wrap">
  <table>
    <thead>
      <tr style="background:var(--green);color:white">
        <th style="background:var(--green);color:white;font-size:.72rem">Agent</th>
        <?php if (!$is_history):
          $th_cols = [
              'total'            => 'Received',
              'confirmed'        => 'Confirmed',
              'rate'             => 'Sales Rate',
              'sales_amount'     => 'Sales Amount',
              'booked_pax'       => 'PAX',
              'commission_total' => 'Total Comm',
          ];
        else:
          $th_cols = [
              'total'       => 'Total Requests',
              'confirmed'   => 'Confirmed',
              'rate'        => 'Conv. Rate',
              'in_progress' => 'In Progress',
              'balance_due' => 'Balance Due',
              'cancelled'   => 'Cancelled',
          ];
        endif;
        foreach ($th_cols as $col => $label): ?>
        <th style="background:var(--green);color:white;font-size:.72rem;text-align:right;white-space:nowrap;cursor:pointer"
            onclick="location.href='<?= sortUrl($col, $sort, $dir, $base_params) ?>'">
          <?= $label ?><?= sortArrow($col, $sort, $dir) ?>
        </th>
        <?php endforeach; ?>
        <?php if (!$is_history): foreach ($all_dests as $d): ?>
          <th style="background:var(--green);color:white;font-size:.72rem;text-align:right"><?= h($d) ?></th>
        <?php endforeach; endif; ?>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $r):
        if ($r['total'] === 0) continue;
        if (!$is_history) {
            $req_url = 'requests.php?' . http_build_query([
                'agent' => $r['agent_id'], 'date_from' => $report_from,
                'date_to' => $report_to, 'year' => 0, 'month' => 0,
            ]);
        }
      ?>
      <tr <?= !$is_history ? "style=\"cursor:pointer\" onclick=\"location.href='".h($req_url)."'\"" : '' ?>
          <?= !$is_history ? "onmouseenter=\"this.style.background='#f0f7f0'\" onmouseleave=\"this.style.background=''\"" : '' ?>>
        <td style="font-weight:600">
          <?= h($r['agent']) ?>
          <?= !$is_history ? '<span style="font-size:.65rem;color:var(--grey-mid);margin-left:4px">↗</span>' : '' ?>
        </td>
        <td class="text-right"><?= $r['total'] ?></td>
        <td class="text-right">
          <?php if (!$is_history && $r['confirmed'] > 0):
            $booked_url = 'requests.php?' . http_build_query([
                'status' => 'Booked', 'agent' => $r['agent_id'],
                'date_from' => $report_from, 'date_to' => $report_to, 'year' => 0,
            ]); ?>
            <a href="<?= h($booked_url) ?>" onclick="event.stopPropagation()" style="text-decoration:none">
              <span class="badge status-booked" style="cursor:pointer"><?= $r['confirmed'] ?></span>
            </a>
          <?php elseif ($r['confirmed'] > 0): ?>
            <span class="badge status-booked"><?= $r['confirmed'] ?></span>
          <?php else: ?>
            <span class="text-muted">0</span>
          <?php endif; ?>
        </td>
        <td class="text-right">
          <?php $rate_color = $r['rate'] >= 10 ? 'var(--green)' : ($r['rate'] >= 5 ? 'var(--amber)' : 'var(--grey-mid)'); ?>
          <span style="font-weight:700;color:<?= $rate_color ?>"><?= $r['rate'] ?>%</span>
        </td>
        <?php if (!$is_history): ?>
        <td class="text-right text-green"><?= $r['sales_amount'] > 0 ? '$'.number_format($r['sales_amount'],0) : '—' ?></td>
        <td class="text-right text-muted"><?= $r['booked_pax'] > 0 ? $r['booked_pax'] : '—' ?></td>
        <td class="text-right text-green"><?= $r['commission_total'] > 0 ? '$'.number_format($r['commission_total'],0) : '—' ?></td>
        <?php foreach ($all_dests as $d): ?>
          <td class="text-right text-muted"><?= $r['by_dest'][$d] ?? 0 ?: '—' ?></td>
        <?php endforeach; ?>
        <?php else: ?>
        <td class="text-right text-muted"><?= $r['in_progress'] > 0 ? $r['in_progress'] : '—' ?></td>
        <td class="text-right" style="color:#1a6bb3"><?= $r['balance_due'] > 0 ? $r['balance_due'] : '—' ?></td>
        <td class="text-right" style="color:var(--red)"><?= $r['cancelled'] > 0 ? $r['cancelled'] : '—' ?></td>
        <?php endif; ?>
      </tr>
      <?php endforeach; ?>

      <!-- TOTALS ROW -->
      <tr style="background:var(--off-white);font-weight:700;border-top:2px solid var(--grey-lt)">
        <td>TOTAL</td>
        <td class="text-right"><?= $totals['total'] ?></td>
        <td class="text-right">
          <?php if (!$is_history):
            $all_booked_url = 'requests.php?' . http_build_query([
                'status' => 'Booked', 'agent' => 0,
                'date_from' => $report_from, 'date_to' => $report_to, 'year' => 0,
            ]); ?>
            <a href="<?= h($all_booked_url) ?>" style="text-decoration:none">
              <span class="badge status-booked" style="cursor:pointer"><?= $totals['confirmed'] ?></span>
            </a>
          <?php else: ?>
            <span class="badge status-booked"><?= $totals['confirmed'] ?></span>
          <?php endif; ?>
        </td>
        <td class="text-right" style="color:var(--green)"><?= $totals['rate'] ?>%</td>
        <?php if (!$is_history): ?>
        <td class="text-right text-green" style="font-weight:700">$<?= number_format($totals['sales_amount'],0) ?></td>
        <td class="text-right" style="font-weight:700"><?= $totals['booked_pax'] ?></td>
        <td class="text-right text-green" style="font-weight:700">$<?= number_format($totals['commission_total'],0) ?></td>
        <?php foreach ($all_dests as $d): ?>
          <td class="text-right"><?= $totals['by_dest'][$d] ?? 0 ?></td>
        <?php endforeach; ?>
        <?php else: ?>
        <td class="text-right" style="color:#e05c1a"><?= $totals['in_progress'] ?></td>
        <td class="text-right" style="color:#1a6bb3"><?= $totals['balance_due'] ?></td>
        <td class="text-right" style="color:var(--red)"><?= $totals['cancelled'] ?></td>
        <?php endif; ?>
      </tr>
    </tbody>
  </table>
</div>

<?php endif; ?>
<?php endif; ?>

<!-- ── TRAVEL PERIOD TABLE ─────────────────────────────────────── -->
<?php if ($report_type === 'travel' && !$is_history && $travel_summary !== null): ?>

<?php if ($travel_no_date > 0): ?>
<div style="background:#fff8e1;border:1px solid #ffe082;border-radius:8px;padding:10px 16px;margin-bottom:16px;font-size:.82rem;color:#6d4c00;">
  ⚠️ <strong><?= $travel_no_date ?></strong> Booked request<?= $travel_no_date > 1 ? 's have' : ' has' ?> no Start Date set and are excluded from this report.
  <a href="requests.php?status=Booked" style="color:var(--red);margin-left:8px">Review →</a>
</div>
<?php endif; ?>

<?php if ($travel_summary['totals']['trips'] === 0): ?>
  <div class="empty-state">
    <div class="icon">✈️</div>
    <p>No trips starting in <?= $month_names[$month] ?> <?= $year ?>.</p>
  </div>
<?php else: ?>

<!-- KPI row -->
<div class="stat-grid" style="margin-bottom:20px">
  <div class="stat-card blue">
    <div class="stat-label">Trips</div>
    <div class="stat-value"><?= $travel_summary['totals']['trips'] ?></div>
  </div>
  <div class="stat-card blue">
    <div class="stat-label">PAX</div>
    <div class="stat-value"><?= $travel_summary['totals']['pax'] ?></div>
  </div>
  <div class="stat-card green">
    <div class="stat-label">Sales Amount</div>
    <div class="stat-value" style="font-size:1.3rem">$<?= number_format($travel_summary['totals']['sales'], 0) ?></div>
  </div>
</div>

<!-- Detail table -->
<div class="table-wrap">
  <table>
    <thead>
      <tr style="background:var(--green);color:white">
        <th style="background:var(--green);color:white;font-size:.72rem">Agent</th>
        <th style="background:var(--green);color:white;font-size:.72rem;text-align:right">Trips</th>
        <th style="background:var(--green);color:white;font-size:.72rem;text-align:right">PAX</th>
        <th style="background:var(--green);color:white;font-size:.72rem;text-align:right">Sales Amount</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($travel_summary['rows'] as $r): ?>
        <?php if ($r['trips'] === 0) continue; ?>
        <tr>
          <td style="font-weight:600"><?= h($r['name']) ?></td>
          <td style="text-align:right"><?= $r['trips'] ?></td>
          <td style="text-align:right"><?= $r['pax'] ?: '—' ?></td>
          <td style="text-align:right;color:var(--green);font-weight:600">$<?= number_format($r['sales'], 0) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr style="font-weight:700;border-top:2px solid var(--grey-lt)">
        <td style="font-weight:700">Total</td>
        <td style="text-align:right;font-weight:700"><?= $travel_summary['totals']['trips'] ?></td>
        <td style="text-align:right;font-weight:700"><?= $travel_summary['totals']['pax'] ?></td>
        <td style="text-align:right;font-weight:700;color:var(--green)">$<?= number_format($travel_summary['totals']['sales'], 0) ?></td>
      </tr>
    </tfoot>
  </table>
</div>

<?php endif; ?>

<?php endif; ?>

<?php include 'includes/footer.php'; ?>
