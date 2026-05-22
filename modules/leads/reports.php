<?php
require_once 'config.php';
requireLogin();
if (isLeadsRestricted()) { header('Location: requests.php'); exit; }
$pageTitle = 'Reports';
$db = db();

// ── Parameters ───────────────────────────────────────────────────
$mode          = $_GET['mode']          ?? 'monthly';   // monthly | global | range
$year          = (int)($_GET['year']    ?? date('Y'));
$month         = (int)($_GET['month']   ?? date('n'));
$range_from    = $_GET['from']          ?? date('Y-01-01');
$range_to      = $_GET['to']            ?? date('Y-m-d');
$agent_filter  = (int)($_GET['agent']   ?? 0);
$sort          = $_GET['sort']          ?? 'total';    // default: total requests
$dir           = $_GET['dir']           ?? 'desc';
$allowed_sort  = ['total','confirmed','rate','sales_amount','booked_pax','commission_total'];
if (!in_array($sort, $allowed_sort)) $sort = 'total';
$dir = ($dir === 'asc') ? 'asc' : 'desc';

// ── All active agents ─────────────────────────────────────────────
$all_agents = $db->query("SELECT * FROM agents WHERE active=1 ORDER BY name")->fetchAll();

// Apply agent filter
$agents = $agent_filter > 0
    ? array_filter($all_agents, fn($a) => (int)$a['id'] === $agent_filter)
    : $all_agents;

// ── Request types (destinations) ─────────────────────────────────
$dest_rows = $db->query("SELECT DISTINCT destination FROM requests WHERE destination IS NOT NULL ORDER BY destination")->fetchAll(PDO::FETCH_COLUMN);
$dest_list = $dest_rows ?: [];

// ── Helper: build summary table for a date range ─────────────────
function buildSummary(PDO $db, string $from, string $to, array $agents, array $dest_list): array {
    $rows = [];
    $totals = ['agent' => 'TOTAL', 'total' => 0, 'confirmed' => 0, 'rate' => 0, 'by_dest' => [], 'sales_amount' => 0, 'booked_pax' => 0, 'commission_total' => 0];

    foreach ($agents as $ag) {
        $stmt = $db->prepare("
            SELECT
                COUNT(*)                                                    AS total,
                SUM(status = 'Booked')                                      AS confirmed,
                SUM(CASE WHEN status='Booked' THEN value_usd       ELSE 0 END) AS sales_amount,
                SUM(CASE WHEN status='Booked' THEN pax             ELSE 0 END) AS booked_pax,
                SUM(CASE WHEN status='Booked' THEN commission_usd  ELSE 0 END) AS commission_total,
                destination
            FROM requests
            WHERE agent_id = ?
              AND date_received BETWEEN ? AND ?
            GROUP BY destination
        ");
        $stmt->execute([$ag['id'], $from, $to]);
        $dest_data = $stmt->fetchAll();

        $total            = 0;
        $confirmed        = 0;
        $sales_amount     = 0;
        $booked_pax       = 0;
        $commission_total = 0;
        $by_dest          = [];

        foreach ($dest_data as $d) {
            $total            += (int)$d['total'];
            $confirmed        += (int)$d['confirmed'];
            $sales_amount     += (float)$d['sales_amount'];
            $booked_pax       += (int)$d['booked_pax'];
            $commission_total += (float)$d['commission_total'];
            if ($d['destination'] !== null) {
                $key = $d['destination'];
                $by_dest[$key] = ($by_dest[$key] ?? 0) + (int)$d['total'];
            }
        }

        $rate = $total > 0 ? round($confirmed / $total * 100, 1) : 0;

        $rows[] = [
            'agent_id'         => $ag['id'],
            'agent'            => $ag['name'],
            'total'            => $total,
            'confirmed'        => $confirmed,
            'rate'             => $rate,
            'by_dest'          => $by_dest,
            'sales_amount'     => $sales_amount,
            'booked_pax'       => $booked_pax,
            'commission_total' => $commission_total,
        ];

        $totals['total']            += $total;
        $totals['confirmed']        += $confirmed;
        $totals['sales_amount']     += $sales_amount;
        $totals['booked_pax']       += $booked_pax;
        $totals['commission_total'] += $commission_total;
        foreach ($by_dest as $k => $v) {
            $totals['by_dest'][$k] = ($totals['by_dest'][$k] ?? 0) + $v;
        }
    }

    // ── Requests with no agent assigned (agent_id IS NULL) ───────────
    $stmt = $db->prepare("
        SELECT
            COUNT(*)                                                    AS total,
            SUM(status = 'Booked')                                      AS confirmed,
            SUM(CASE WHEN status='Booked' THEN value_usd       ELSE 0 END) AS sales_amount,
            SUM(CASE WHEN status='Booked' THEN pax             ELSE 0 END) AS booked_pax,
            SUM(CASE WHEN status='Booked' THEN commission_usd  ELSE 0 END) AS commission_total,
            destination
        FROM requests
        WHERE agent_id IS NULL
          AND date_received BETWEEN ? AND ?
        GROUP BY destination
    ");
    $stmt->execute([$from, $to]);
    $unassigned_data = $stmt->fetchAll();

    $u_total = 0; $u_confirmed = 0; $u_sales = 0; $u_pax = 0; $u_comm = 0; $u_dest = [];
    foreach ($unassigned_data as $d) {
        $u_total     += (int)$d['total'];
        $u_confirmed += (int)$d['confirmed'];
        $u_sales     += (float)$d['sales_amount'];
        $u_pax       += (int)$d['booked_pax'];
        $u_comm      += (float)$d['commission_total'];
        if ($d['destination'] !== null) {
            $u_dest[$d['destination']] = ($u_dest[$d['destination']] ?? 0) + (int)$d['total'];
        }
    }

    if ($u_total > 0) {
        $rows[] = [
            'agent_id'         => -1,
            'agent'            => 'Unassigned',
            'total'            => $u_total,
            'confirmed'        => $u_confirmed,
            'rate'             => round($u_confirmed / $u_total * 100, 1),
            'by_dest'          => $u_dest,
            'sales_amount'     => $u_sales,
            'booked_pax'       => $u_pax,
            'commission_total' => $u_comm,
        ];
        $totals['total']            += $u_total;
        $totals['confirmed']        += $u_confirmed;
        $totals['sales_amount']     += $u_sales;
        $totals['booked_pax']       += $u_pax;
        $totals['commission_total'] += $u_comm;
        foreach ($u_dest as $k => $v) {
            $totals['by_dest'][$k] = ($totals['by_dest'][$k] ?? 0) + $v;
        }
    }

    $totals['rate'] = $totals['total'] > 0
        ? round($totals['confirmed'] / $totals['total'] * 100, 1) : 0;

    return ['rows' => $rows, 'totals' => $totals];
}

// ── Build data based on mode ──────────────────────────────────────
$summary   = null;
$title_str = '';
$months_data = []; // for monthly mode: array of [label, from, to, summary]

if ($mode === 'monthly') {
    $from = sprintf('%04d-%02d-01', $year, $month);
    $to   = date('Y-m-t', strtotime($from));
    $summary     = buildSummary($db, $from, $to, $agents, $dest_list);
    usort($summary['rows'], function($a, $b) use ($sort, $dir) {
        $va = $a[$sort] ?? 0;
        $vb = $b[$sort] ?? 0;
        return $dir === 'desc' ? $vb <=> $va : $va <=> $vb;
    });
    $title_str   = date('F Y', strtotime($from));
    $report_from = $from; $report_to = $to;

} elseif ($mode === 'global') {
    $bounds = $db->query("SELECT MIN(date_received) AS mn, MAX(date_received) AS mx FROM requests")->fetch();
    $from = $bounds['mn'] ?? date('Y-01-01');
    $to   = $bounds['mx'] ?? date('Y-m-d');
    $summary     = buildSummary($db, $from, $to, $agents, $dest_list);
    usort($summary['rows'], function($a, $b) use ($sort, $dir) {
        $va = $a[$sort] ?? 0;
        $vb = $b[$sort] ?? 0;
        return $dir === 'desc' ? $vb <=> $va : $va <=> $vb;
    });
    $title_str   = 'Grand Total — All Time';
    $report_from = $from; $report_to = $to;

} elseif ($mode === 'range') {
    $from = $range_from;
    $to   = $range_to;
    $summary     = buildSummary($db, $from, $to, $agents, $dest_list);
    usort($summary['rows'], function($a, $b) use ($sort, $dir) {
        $va = $a[$sort] ?? 0;
        $vb = $b[$sort] ?? 0;
        return $dir === 'desc' ? $vb <=> $va : $va <=> $vb;
    });
    $title_str   = date('d M Y', strtotime($from)) . ' → ' . date('d M Y', strtotime($to));
    $report_from = $from; $report_to = $to;
}

// ── Available years ───────────────────────────────────────────────
$years = $db->query("SELECT DISTINCT YEAR(date_received) y FROM requests ORDER BY y DESC")->fetchAll(PDO::FETCH_COLUMN);
if (!$years) $years = [date('Y')];

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
  </div>
</div>

<!-- ── FILTER BAR ─────────────────────────────────────────────── -->
<div style="background:var(--white);border-radius:10px;padding:18px 20px;
            box-shadow:0 1px 6px rgba(0,0,0,.06);margin-bottom:24px;">

  <!-- Mode tabs -->
  <div style="display:flex;gap:8px;margin-bottom:16px;border-bottom:1px solid var(--grey-lt);padding-bottom:14px;">
    <?php
    $tabs = ['monthly' => 'Monthly', 'global' => 'Grand Total', 'range' => 'Date Range'];
    foreach ($tabs as $k => $label):
    ?>
    <a href="?mode=<?= $k ?>&year=<?= $year ?>&month=<?= $month ?>&from=<?= h($range_from) ?>&to=<?= h($range_to) ?>&agent=<?= $agent_filter ?>"
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
      <select name="year" style="padding:8px 12px;border:1.5px solid var(--grey-lt);border-radius:6px;font-family:'Open Sans',sans-serif;font-size:.82rem">
        <?php foreach ($years as $y): ?>
          <option value="<?= $y ?>" <?= (int)$y===$year?'selected':'' ?>><?= $y ?></option>
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
    <?php foreach ($month_names as $n => $name): ?>
    <?php
      $from_chk = sprintf('%04d-%02d-01', $year, $n);
      $to_chk   = date('Y-m-t', strtotime($from_chk));
      $cnt = $db->prepare("SELECT COUNT(*) FROM requests WHERE date_received BETWEEN ? AND ?");
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

<!-- ── SUMMARY TABLE ──────────────────────────────────────────── -->
<?php if ($summary): ?>
<?php
  $rows    = $summary['rows'];
  $totals  = $summary['totals'];
  // Fixed destination order matching Excel
  $dest_order = ['Safari','Kilimanjaro','Safari+Beach','Meru Trekking','Tailor-made','Other'];
  // Only show columns that have data in this period
  $all_dests = [];
  foreach ($dest_order as $d) {
      if (isset($totals['by_dest'][$d]) && $totals['by_dest'][$d] > 0) {
          $all_dests[] = $d;
      }
  }
  // Add any unexpected destination types not in the fixed list
  foreach (array_keys($totals['by_dest']) as $d) {
      if (!in_array($d, $all_dests) && $totals['by_dest'][$d] > 0) {
          $all_dests[] = $d;
      }
  }
  $has_data = $totals['total'] > 0;
  // Base params for sort URLs
  $base_params = ['mode'=>$mode,'year'=>$year,'month'=>$month,'from'=>$range_from,'to'=>$range_to,'agent'=>$agent_filter];
?>

<?php if (!$has_data): ?>
  <div class="empty-state">
    <div class="icon">📊</div>
    <p>No requests found for this period.</p>
  </div>
<?php else: ?>

<!-- KPI row -->
<div class="stat-grid" style="margin-bottom:20px">
  <div class="stat-card blue">
    <div class="stat-label">Total Requests</div>
    <div class="stat-value"><?= $totals['total'] ?></div>
  </div>
  <div class="stat-card green" style="cursor:pointer"
       onclick="location.href='requests.php?<?= h(http_build_query(['status'=>'Booked','agent'=>0,'date_from'=>$report_from,'date_to'=>$report_to,'year'=>0])) ?>'">
    <div class="stat-label">Confirmed</div>
    <div class="stat-value green"><?= $totals['confirmed'] ?></div>
  </div>
  <div class="stat-card amber">
    <div class="stat-label">Sales Rate</div>
    <div class="stat-value"><?= $totals['rate'] ?>%</div>
  </div>
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
</div>

<!-- Detail table -->
<div class="table-wrap">
  <table>
    <thead>
      <tr style="background:var(--green);color:white">
        <th style="background:var(--green);color:white;font-size:.72rem">Agent</th>
        <?php
        $th_cols = [
            'total'            => 'Total Requests',
            'confirmed'        => 'Confirmed',
            'rate'             => 'Sales Rate',
            'sales_amount'     => 'Sales Amount',
            'booked_pax'       => 'PAX (Booked)',
            'commission_total' => 'Total Comm',
        ];
        foreach ($th_cols as $col => $label):
        ?>
        <th style="background:var(--green);color:white;font-size:.72rem;text-align:right;white-space:nowrap;cursor:pointer"
            onclick="location.href='<?= sortUrl($col, $sort, $dir, $base_params) ?>'">
          <?= $label ?><?= sortArrow($col, $sort, $dir) ?>
        </th>
        <?php endforeach; ?>
        <?php foreach ($all_dests as $d): ?>
          <th style="background:var(--green);color:white;font-size:.72rem;text-align:right"><?= h($d) ?></th>
        <?php endforeach; ?>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $r):
        if ($r['total'] === 0) continue;
        $req_url = 'requests.php?' . http_build_query([
            'agent'     => $r['agent_id'],   // -1 = Unknown, handled in requests.php
            'date_from' => $report_from,
            'date_to'   => $report_to,
            'year'      => 0,
            'month'     => 0,
        ]);
      ?>
      <tr style="cursor:pointer" onclick="location.href='<?= h($req_url) ?>'"
          onmouseenter="this.style.background='#f0f7f0'" onmouseleave="this.style.background=''">
        <td style="font-weight:600">
          <?= h($r['agent']) ?>
          <span style="font-size:.65rem;color:var(--grey-mid);margin-left:4px">↗</span>
        </td>
        <td class="text-right"><?= $r['total'] ?></td>
        <td class="text-right">
          <?php if ($r['confirmed'] > 0):
            $booked_url = 'requests.php?' . http_build_query([
                'status'    => 'Booked',
                'agent'     => $r['agent_id'],
                'date_from' => $report_from,
                'date_to'   => $report_to,
                'year'      => 0,
            ]); ?>
            <a href="<?= h($booked_url) ?>" onclick="event.stopPropagation()"
               style="text-decoration:none">
              <span class="badge status-booked" style="cursor:pointer" title="View booked requests"><?= $r['confirmed'] ?></span>
            </a>
          <?php else: ?>
            <span class="text-muted">0</span>
          <?php endif; ?>
        </td>
        <td class="text-right">
          <?php
            $rate_color = $r['rate'] >= 10 ? 'var(--green)' : ($r['rate'] >= 5 ? 'var(--amber)' : 'var(--grey-mid)');
          ?>
          <span style="font-weight:700;color:<?= $rate_color ?>"><?= $r['rate'] ?>%</span>
        </td>
        <td class="text-right text-green"><?= $r['sales_amount'] > 0 ? '$'.number_format($r['sales_amount'],0) : '—' ?></td>
        <td class="text-right text-muted"><?= $r['booked_pax'] > 0 ? $r['booked_pax'] : '—' ?></td>
        <td class="text-right text-green"><?= $r['commission_total'] > 0 ? '$'.number_format($r['commission_total'],0) : '—' ?></td>
        <?php foreach ($all_dests as $d): ?>
          <td class="text-right text-muted"><?= $r['by_dest'][$d] ?? 0 ?: '—' ?></td>
        <?php endforeach; ?>
      </tr>
      <?php endforeach; ?>

      <!-- TOTALS ROW -->
      <tr style="background:var(--off-white);font-weight:700;border-top:2px solid var(--grey-lt)">
        <td>TOTAL</td>
        <td class="text-right"><?= $totals['total'] ?></td>
        <td class="text-right">
          <?php $all_booked_url = 'requests.php?' . http_build_query([
              'status'    => 'Booked',
              'agent'     => 0,
              'date_from' => $report_from,
              'date_to'   => $report_to,
              'year'      => 0,
          ]); ?>
          <a href="<?= h($all_booked_url) ?>" style="text-decoration:none">
            <span class="badge status-booked" style="cursor:pointer" title="View all booked requests"><?= $totals['confirmed'] ?></span>
          </a>
        </td>
        <td class="text-right" style="color:var(--green)"><?= $totals['rate'] ?>%</td>
        <td class="text-right text-green" style="font-weight:700">$<?= number_format($totals['sales_amount'],0) ?></td>
        <td class="text-right" style="font-weight:700"><?= $totals['booked_pax'] ?></td>
        <td class="text-right text-green" style="font-weight:700">$<?= number_format($totals['commission_total'],0) ?></td>
        <?php foreach ($all_dests as $d): ?>
          <td class="text-right"><?= $totals['by_dest'][$d] ?? 0 ?></td>
        <?php endforeach; ?>
      </tr>
    </tbody>
  </table>
</div>

<?php endif; ?>
<?php endif; ?>

<?php include 'includes/footer.php'; ?>
