<?php
require_once 'config.php';
$pageTitle = 'Credit Notes';
$db = db();

$search  = trim($_GET['q'] ?? '');
$fstatus = $_GET['status'] ?? '';
$fyear   = (int)($_GET['year'] ?? date('Y'));

$where = ['1=1']; $params = [];
if ($search)  { $where[] = '(cn.cn_number LIKE ? OR cn.bill_to_name LIKE ? OR i.invoice_number LIKE ?)';
                $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%"; }
if ($fstatus && array_key_exists($fstatus, CN_STATUSES)) { $where[] = 'cn.status = ?'; $params[] = $fstatus; }
if ($fyear > 0) { $where[] = 'YEAR(cn.issue_date) = ?'; $params[] = $fyear; }

$sql = "SELECT cn.*, i.invoice_number
        FROM credit_notes cn
        LEFT JOIN invoices i ON i.id = cn.invoice_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY cn.issue_date DESC, cn.id DESC";
$stmt = $db->prepare($sql); $stmt->execute($params);
$rows = $stmt->fetchAll();

$years = $db->query("SELECT DISTINCT YEAR(issue_date) y FROM credit_notes ORDER BY y DESC")->fetchAll(PDO::FETCH_COLUMN);
if (!$years) $years = [date('Y')];

$totalCredit = array_sum(array_map(fn($r) => $r['status'] !== 'Cancelled' ? (float)$r['total'] : 0, $rows));

include 'includes/header.php';
?>

<div class="page-header">
  <div>
    <h2>Credit Notes</h2>
    <div class="sub"><?= count($rows) ?> credit note<?= count($rows)!==1?'s':'' ?> · active credit $<?= number_format($totalCredit,2) ?></div>
  </div>
</div>

<form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;align-items:end;margin-bottom:20px;max-width:860px;">
  <div class="form-group" style="flex:1;min-width:200px;margin:0">
    <label style="font-size:.7rem;text-transform:uppercase;letter-spacing:.08em;color:var(--grey-mid)">Search</label>
    <input type="text" name="q" value="<?= h($search) ?>" placeholder="CN number, invoice, name…">
  </div>
  <div class="form-group" style="margin:0">
    <label style="font-size:.7rem;text-transform:uppercase;letter-spacing:.08em;color:var(--grey-mid)">Status</label>
    <select name="status">
      <option value="">All</option>
      <?php foreach (CN_STATUSES as $s => $c): ?>
        <option value="<?= h($s) ?>" <?= $fstatus===$s?'selected':'' ?>><?= h($s) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="form-group" style="margin:0">
    <label style="font-size:.7rem;text-transform:uppercase;letter-spacing:.08em;color:var(--grey-mid)">Year</label>
    <select name="year">
      <option value="0">All</option>
      <?php foreach ($years as $y): ?>
        <option value="<?= (int)$y ?>" <?= $fyear==$y?'selected':'' ?>><?= (int)$y ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <button type="submit" class="btn btn-outline btn-sm">Filter</button>
  <?php if ($search || $fstatus): ?><a href="cn_list.php" class="btn btn-grey btn-sm">Clear</a><?php endif; ?>
</form>

<div class="table-wrap" style="max-width:860px">
  <table>
    <thead>
      <tr>
        <th>CN #</th>
        <th>Date</th>
        <th>Credit To</th>
        <th>Invoice</th>
        <th class="text-right">Credit</th>
        <th>Status</th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="6" style="text-align:center;color:var(--grey-mid);padding:24px">No credit notes found.</td></tr>
      <?php else: foreach ($rows as $r): $rsym = $r['currency']==='EUR'?'€':'$'; ?>
      <tr style="cursor:pointer" onclick="location.href='cn_view.php?id=<?= (int)$r['id'] ?>'">
        <td><strong><?= h($r['cn_number']) ?></strong></td>
        <td><?= date('d M Y', strtotime($r['issue_date'])) ?></td>
        <td><?= h($r['bill_to_name']) ?></td>
        <td><a href="invoice_view.php?id=<?= (int)$r['invoice_id'] ?>" style="color:var(--blue)" onclick="event.stopPropagation()"><?= h($r['invoice_number']) ?></a></td>
        <td class="text-right" style="color:var(--red);font-weight:600"><?= $rsym.number_format((float)$r['total'],2) ?></td>
        <td><span class="badge <?= CN_STATUSES[$r['status']] ?? '' ?>"><?= h($r['status']) ?></span></td>
      </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>

<?php include 'includes/footer.php'; ?>
