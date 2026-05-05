<?php
require_once 'config.php';
$pageTitle = 'Reports';
$db = db();

$fyear = (int)($_GET['year'] ?? date('Y'));
$fcurr = $_GET['currency'] ?? 'USD';
if (!in_array($fcurr, INV_CURRENCIES)) $fcurr = 'USD';

$years = $db->query("SELECT DISTINCT YEAR(issue_date) y FROM invoices ORDER BY y DESC")->fetchAll(PDO::FETCH_COLUMN);
if (!$years) $years = [date('Y')];
if (!in_array($fyear, $years)) $fyear = (int)($years[0] ?? date('Y'));

$sym = $fcurr === 'EUR' ? '€' : '$';
// Used in single-table queries (no alias needed)
$baseWhere = "status != 'Cancelled' AND currency = '$fcurr' AND YEAR(issue_date) = $fyear";
// Used in joined queries — prefix i. to avoid ambiguity with requests.status, requests.currency etc.
$baseWhereI = "i.status != 'Cancelled' AND i.currency = '$fcurr' AND YEAR(i.issue_date) = $fyear";

// ── Top stats ─────────────────────────────────────────────────────────────
$stats = $db->query("
    SELECT
        COUNT(*) AS inv_count,
        COALESCE(SUM(total),0) AS total_invoiced,
        COALESCE(SUM(amount_paid),0) AS total_paid,
        COALESCE(SUM(balance_due),0) AS total_outstanding,
        SUM(status='Paid') AS fully_paid,
        SUM(status='Partially Paid') AS partially_paid,
        SUM(status IN ('New','Partially Paid')) AS open_count
    FROM invoices
    WHERE $baseWhere
")->fetch();

// ── Monthly breakdown ─────────────────────────────────────────────────────
$monthly = $db->query("
    SELECT
        MONTH(issue_date) AS m,
        MONTHNAME(issue_date) AS month_name,
        COUNT(*) AS cnt,
        COALESCE(SUM(total),0) AS invoiced,
        COALESCE(SUM(amount_paid),0) AS paid,
        COALESCE(SUM(balance_due),0) AS outstanding
    FROM invoices
    WHERE $baseWhere
    GROUP BY MONTH(issue_date), MONTHNAME(issue_date)
    ORDER BY m
")->fetchAll();

// ── By Issuer ─────────────────────────────────────────────────────────────
$byIssuer = $db->query("
    SELECT issuer,
        COUNT(*) AS cnt,
        COALESCE(SUM(total),0) AS invoiced,
        COALESCE(SUM(amount_paid),0) AS paid
    FROM invoices
    WHERE $baseWhere
    GROUP BY issuer
")->fetchAll();

// ── By Agent (via request → agent join) ──────────────────────────────────
$byAgent = $db->query("
    SELECT
        COALESCE(a.name, 'Unassigned') AS agent_name,
        COUNT(i.id) AS cnt,
        COALESCE(SUM(i.total),0) AS invoiced,
        COALESCE(SUM(i.amount_paid),0) AS paid,
        COALESCE(SUM(i.balance_due),0) AS outstanding
    FROM invoices i
    LEFT JOIN requests r ON r.id = i.request_id
    LEFT JOIN agents   a ON a.id = r.agent_id
    WHERE $baseWhereI
    GROUP BY a.id, a.name
    ORDER BY invoiced DESC
")->fetchAll();

// Max for bar charts
$maxMonth = max(array_column($monthly, 'invoiced') ?: [1]);
$maxAgent = max(array_column($byAgent, 'invoiced')  ?: [1]);

include 'includes/header.php';
?>

<!-- Filters -->
<div class="page-header">
  <h2>Revenue Reports — <?= $fyear ?> &nbsp;·&nbsp; <?= $fcurr ?></h2>
</div>

<form method="GET" class="filters" style="margin-bottom:28px">
  <div>
    <label>Year</label>
    <select name="year">
      <?php foreach ($years as $y): ?>
        <option value="<?= $y ?>" <?= $fyear===$y?'selected':'' ?>><?= $y ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div>
    <label>Currency</label>
    <select name="currency">
      <?php foreach (INV_CURRENCIES as $c): ?>
        <option value="<?= $c ?>" <?= $fcurr===$c?'selected':'' ?>><?= $c ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div><label>&nbsp;</label><button type="submit" class="btn btn-outline">Apply</button></div>
</form>

<!-- ── TOP STATS ── -->
<div class="stat-grid">
  <div class="stat-card blue">
    <div class="stat-label">Total Invoiced</div>
    <div class="stat-value"><?= $sym.number_format($stats['total_invoiced'],2) ?></div>
    <div class="stat-sub"><?= $stats['inv_count'] ?> invoices</div>
  </div>
  <div class="stat-card green">
    <div class="stat-label">Collected</div>
    <div class="stat-value green"><?= $sym.number_format($stats['total_paid'],2) ?></div>
    <div class="stat-sub"><?= $stats['fully_paid'] ?> fully paid</div>
  </div>
  <div class="stat-card amber">
    <div class="stat-label">Outstanding</div>
    <div class="stat-value amber"><?= $sym.number_format($stats['total_outstanding'],2) ?></div>
    <div class="stat-sub"><?= $stats['open_count'] + $stats['partially_paid'] ?> open invoices</div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Collection Rate</div>
    <div class="stat-value"><?= $stats['total_invoiced'] > 0 ? round($stats['total_paid'] / $stats['total_invoiced'] * 100, 1) : 0 ?>%</div>
    <div class="stat-sub"><?= $stats['partially_paid'] ?> partially paid</div>
  </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;margin-bottom:28px;">

  <!-- ── BY MONTH ── -->
  <div>
    <div class="section-label">Monthly Breakdown</div>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Month</th>
            <th class="text-right">Invoiced</th>
            <th class="text-right">Paid</th>
            <th class="text-right">Outstanding</th>
            <th style="width:90px"></th>
          </tr>
        </thead>
        <tbody>
          <?php if ($monthly): ?>
            <?php foreach ($monthly as $row): ?>
            <tr>
              <td style="font-weight:600"><?= h($row['month_name']) ?></td>
              <td class="text-right"><?= $sym.number_format($row['invoiced'],2) ?></td>
              <td class="text-right text-green"><?= $sym.number_format($row['paid'],2) ?></td>
              <td class="text-right <?= $row['outstanding'] > 0 ? 'text-amber' : '' ?>"><?= $sym.number_format($row['outstanding'],2) ?></td>
              <td>
                <div class="breakdown-bar-wrap">
                  <div class="breakdown-bar" style="width:<?= $maxMonth > 0 ? round($row['invoiced']/$maxMonth*100) : 0 ?>%"></div>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr><td colspan="5" style="text-align:center;padding:20px;color:var(--grey-mid);font-size:.83rem">No data for <?= $fyear ?></td></tr>
          <?php endif; ?>
          <?php if ($monthly): ?>
          <tr style="background:var(--off-white);font-weight:700">
            <td>Total <?= $fyear ?></td>
            <td class="text-right"><?= $sym.number_format($stats['total_invoiced'],2) ?></td>
            <td class="text-right text-green"><?= $sym.number_format($stats['total_paid'],2) ?></td>
            <td class="text-right <?= $stats['total_outstanding'] > 0 ? 'text-amber' : '' ?>"><?= $sym.number_format($stats['total_outstanding'],2) ?></td>
            <td></td>
          </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- ── BY ISSUER ── -->
  <div>
    <div class="section-label">By Issuer</div>
    <div class="table-wrap" style="margin-bottom:24px">
      <table>
        <thead>
          <tr><th>Issuer</th><th class="text-right">Invoices</th><th class="text-right">Invoiced</th><th class="text-right">Paid</th></tr>
        </thead>
        <tbody>
          <?php if ($byIssuer): ?>
            <?php foreach ($byIssuer as $row): ?>
            <tr>
              <td style="font-weight:600;font-size:.82rem"><?= h($row['issuer']) ?></td>
              <td class="text-right text-muted"><?= $row['cnt'] ?></td>
              <td class="text-right"><?= $sym.number_format($row['invoiced'],2) ?></td>
              <td class="text-right text-green"><?= $sym.number_format($row['paid'],2) ?></td>
            </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr><td colspan="4" style="text-align:center;padding:20px;color:var(--grey-mid);font-size:.83rem">No data</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <!-- ── BY AGENT ── -->
    <div class="section-label">By Agent</div>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Agent</th>
            <th class="text-right">Invoiced</th>
            <th class="text-right">Paid</th>
            <th style="width:80px"></th>
          </tr>
        </thead>
        <tbody>
          <?php if ($byAgent): ?>
            <?php foreach ($byAgent as $row): ?>
            <tr>
              <td style="font-weight:600"><?= h($row['agent_name']) ?></td>
              <td class="text-right"><?= $sym.number_format($row['invoiced'],2) ?></td>
              <td class="text-right text-green"><?= $sym.number_format($row['paid'],2) ?></td>
              <td>
                <div class="breakdown-bar-wrap">
                  <div class="breakdown-bar" style="width:<?= $maxAgent > 0 ? round($row['invoiced']/$maxAgent*100) : 0 ?>%"></div>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr><td colspan="4" style="text-align:center;padding:20px;color:var(--grey-mid);font-size:.83rem">
              No data — link invoices to requests to see agent breakdown</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

</div>

<?php include 'includes/footer.php'; ?>
