<?php
require_once 'config.php';
$pageTitle = 'Invoices';
$db = db();

// ── Filters ───────────────────────────────────────────────────────────────
$search    = trim($_GET['q']          ?? '');
$fstatus   = $_GET['status']          ?? '';
$fissuer   = $_GET['issuer']          ?? '';
$fyear     = (int)($_GET['year']      ?? date('Y'));
$fcurr     = $_GET['currency']        ?? '';
$freqid    = (int)($_GET['request_id'] ?? 0);

$where  = ['1=1']; $params = [];
if ($search)  { $where[] = '(i.invoice_number LIKE ? OR i.bill_to_name LIKE ?)'; $params[] = "%$search%"; $params[] = "%$search%"; }
if ($fstatus && array_key_exists($fstatus, INV_STATUSES)) { $where[] = 'i.status = ?'; $params[] = $fstatus; }
if ($fissuer && in_array($fissuer, INV_ISSUERS))           { $where[] = 'i.issuer = ?'; $params[] = $fissuer; }
if ($fyear > 0) { $where[] = 'YEAR(i.issue_date) = ?'; $params[] = $fyear; }
if ($fcurr && in_array($fcurr, INV_CURRENCIES))            { $where[] = 'i.currency = ?'; $params[] = $fcurr; }
if ($freqid)                                               { $where[] = 'i.request_id = ?'; $params[] = $freqid; }

$sql = "SELECT i.*, u.full_name AS created_by_name
        FROM invoices i
        LEFT JOIN users u ON u.id = i.created_by
        WHERE " . implode(' AND ', $where) . "
        ORDER BY i.issue_date DESC, i.id DESC";
$stmt = $db->prepare($sql); $stmt->execute($params);
$rows = $stmt->fetchAll();

// Years for filter
$years = $db->query("SELECT DISTINCT YEAR(issue_date) y FROM invoices ORDER BY y DESC")->fetchAll(PDO::FETCH_COLUMN);
if (!$years) $years = [date('Y')];

// ── Summary stats (current filter) ───────────────────────────────────────
$totalAmt = array_sum(array_column($rows, 'total'));
$paidAmt  = array_sum(array_column($rows, 'amount_paid'));
$balAmt   = array_sum(array_column($rows, 'balance_due'));
$sym = ($fcurr === 'EUR') ? '€' : '$';

include 'includes/header.php';
?>

<div class="page-header">
  <div>
    <h2>Invoices</h2>
    <div class="sub"><?= count($rows) ?> invoice<?= count($rows)!==1?'s':'' ?></div>
  </div>
  <div class="gap-8">
    <?php if ($freqid): ?>
      <a href="invoices.php" class="btn btn-grey">✕ Clear filter</a>
      <a href="invoice_add.php?request_id=<?= $freqid ?>" class="btn btn-red">+ New Invoice for this request</a>
    <?php endif; ?>
  </div>
</div>

<?php if ($freqid): ?>
  <?php
    $reqInfo = $db->prepare('SELECT customer_name, practice_code FROM requests WHERE id = ?');
    $reqInfo->execute([$freqid]);
    $reqRow = $reqInfo->fetch();
  ?>
  <?php if ($reqRow): ?>
    <div style="background:#E8F5E9;border:1px solid #A5D6A7;border-radius:8px;padding:10px 16px;margin-bottom:18px;font-size:.83rem;color:#1A6B3A;">
      Showing invoices for <strong><?= h($reqRow['customer_name']) ?></strong>
      <?= $reqRow['practice_code'] ? '— <code>'.h($reqRow['practice_code']).'</code>' : '' ?>
      &nbsp;·&nbsp; <a href="../leads/request_view.php?id=<?= $freqid ?>" style="color:#1A6B3A;">View request</a>
    </div>
  <?php endif; ?>
<?php endif; ?>

<!-- STATS -->
<div class="stat-grid">
  <div class="stat-card blue">
    <div class="stat-label">Total Invoiced</div>
    <div class="stat-value"><?= $sym.number_format($totalAmt,2) ?></div>
    <div class="stat-sub"><?= count($rows) ?> invoices</div>
  </div>
  <div class="stat-card green">
    <div class="stat-label">Paid</div>
    <div class="stat-value green"><?= $sym.number_format($paidAmt,2) ?></div>
    <div class="stat-sub"><?= count(array_filter($rows, fn($r)=>$r['status']==='Paid')) ?> fully paid</div>
  </div>
  <div class="stat-card amber">
    <div class="stat-label">Outstanding</div>
    <div class="stat-value amber"><?= $sym.number_format($balAmt,2) ?></div>
    <div class="stat-sub"><?= count(array_filter($rows, fn($r)=>in_array($r['status'],['New','Partially Paid']))) ?> open</div>
  </div>
  <div class="stat-card red">
    <div class="stat-label">Cancelled</div>
    <div class="stat-value"><?= count(array_filter($rows, fn($r)=>$r['status']==='Cancelled')) ?></div>
    <div class="stat-sub">invoices</div>
  </div>
</div>

<!-- FILTERS -->
<form method="GET" class="filters">
  <div><label>Search</label><input type="text" name="q" value="<?= h($search) ?>" placeholder="Number or customer…"></div>
  <div>
    <label>Status</label>
    <select name="status">
      <option value="">All statuses</option>
      <?php foreach (INV_STATUSES as $s=>$cls): ?>
        <option value="<?= h($s) ?>" <?= $fstatus===$s?'selected':'' ?>><?= h($s) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div>
    <label>Issuer</label>
    <select name="issuer">
      <option value="">Both issuers</option>
      <?php foreach (INV_ISSUERS as $iss): ?>
        <option value="<?= h($iss) ?>" <?= $fissuer===$iss?'selected':'' ?>><?= h($iss) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div>
    <label>Currency</label>
    <select name="currency">
      <option value="">All</option>
      <?php foreach (INV_CURRENCIES as $c): ?>
        <option value="<?= $c ?>" <?= $fcurr===$c?'selected':'' ?>><?= $c ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div>
    <label>Year</label>
    <select name="year">
      <option value="0">All years</option>
      <?php foreach ($years as $y): ?>
        <option value="<?= $y ?>" <?= $fyear===(int)$y?'selected':'' ?>><?= $y ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div><label>&nbsp;</label><button type="submit" class="btn btn-outline">Filter</button></div>
  <div><label>&nbsp;</label><a href="invoices.php" class="btn btn-grey">✕ Clear</a></div>
</form>

<!-- TABLE -->
<div class="table-wrap">
  <table>
    <thead>
      <tr>
        <th>#</th>
        <th>Bill To</th>
        <th>Issuer</th>
        <th>Curr.</th>
        <th style="text-align:right;min-width:110px">Total</th>
        <th style="text-align:right;min-width:110px">Paid</th>
        <th style="text-align:right;min-width:110px">Balance</th>
        <th style="min-width:110px">Status</th>
        <th>Date</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php if ($rows): ?>
        <?php foreach ($rows as $r): ?>
        <tr>
          <td style="font-weight:700;white-space:nowrap">
            <a href="invoice_view.php?id=<?= $r['id'] ?>" style="color:var(--red-dk);text-decoration:none"><?= h($r['invoice_number']) ?></a>
          </td>
          <td>
            <a href="invoice_view.php?id=<?= $r['id'] ?>" style="font-weight:600;color:var(--black);text-decoration:none"><?= h($r['bill_to_name']) ?></a>
            <?php if ($r['request_id']): ?>
              <div style="font-size:.7rem;color:var(--grey-mid)">Req #<?= $r['request_id'] ?></div>
            <?php endif; ?>
          </td>
          <td style="font-size:.75rem;color:var(--grey-mid)"><?= $r['issuer'] === 'Savannah Explorers Ltd' ? 'SE' : 'SH' ?></td>
          <td style="font-size:.78rem"><?= h($r['currency']) ?></td>
          <td class="text-right" style="font-weight:600"><?= fmt_money((float)$r['total'], $r['currency']) ?></td>
          <td class="text-right text-green"><?= $r['amount_paid'] > 0 ? fmt_money((float)$r['amount_paid'], $r['currency']) : '<span class="text-muted">—</span>' ?></td>
          <td class="text-right <?= (float)$r['balance_due'] > 0 ? 'text-amber' : 'text-green' ?>">
            <?= fmt_money((float)$r['balance_due'], $r['currency']) ?>
          </td>
          <td><?php $sc = INV_STATUSES[$r['status']] ?? ''; ?>
            <span class="badge <?= $sc ?>" <?= !$sc ? 'style="background:var(--grey-lt);color:var(--grey-dk)"' : '' ?>><?= $r['status'] ? h($r['status']) : '—' ?></span></td>
          <td class="text-muted" style="white-space:nowrap"><?= date('d M Y', strtotime($r['issue_date'])) ?></td>
          <td>
            <div class="gap-8">
              <a href="invoice_view.php?id=<?= $r['id'] ?>" class="btn btn-outline btn-sm">View</a>
              <a href="invoice_edit.php?id=<?= $r['id'] ?>" class="btn btn-outline btn-sm">Edit</a>
              <a href="invoice_pdf.php?id=<?= $r['id'] ?>"  class="btn btn-outline btn-sm" target="_blank">PDF</a>
              <?php if ($r['request_id']): ?>
                <a href="invoice_add.php?request_id=<?= $r['request_id'] ?>" class="btn btn-red btn-sm">+ Invoice</a>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      <?php else: ?>
        <tr><td colspan="10">
          <div class="empty-state"><div class="icon">🧾</div><p>No invoices found<?= $search||$fstatus?' for the selected filters.':' yet.' ?></p></div>
        </td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<?php include 'includes/footer.php'; ?>
