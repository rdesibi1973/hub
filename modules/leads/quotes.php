<?php
require_once 'config.php';
$pageTitle = 'Quotes';
$db = db();

$isStaff      = isLeadsRestricted();
$staffAgentId = $isStaff ? getStaffAgentId() : 0;

// Build query
$where  = ['1=1'];
$params = [];

if ($isStaff) {
    $where[]  = 'q.agent_id = ?';
    $params[] = $staffAgentId;
}

// Optional filters
$search = trim($_GET['q'] ?? '');
if ($search) {
    $where[]  = '(q.customer_name LIKE ? OR q.quote_number LIKE ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$sql = "
    SELECT q.*, r.practice_code
    FROM   quotes q
    LEFT JOIN requests r ON r.id = q.request_id
    WHERE  " . implode(' AND ', $where) . "
    ORDER BY q.id DESC
";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$quotes = $stmt->fetchAll();

include 'includes/header.php';
?>

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;">
  <h2 style="font-family:'Merriweather',serif;font-size:1.4rem;color:var(--red-dk);">
    <?= $isStaff ? 'My Quotes' : 'All Quotes' ?>
    <span style="font-size:.85rem;font-weight:400;color:var(--grey-mid);margin-left:8px;"><?= count($quotes) ?> total</span>
  </h2>
</div>

<!-- Search bar -->
<form method="get" style="display:flex;gap:8px;margin-bottom:20px;max-width:480px;">
  <input name="q" value="<?= h($search) ?>" placeholder="Search by customer or quote #…"
    style="flex:1;padding:8px 12px;border:1px solid var(--grey-lt);border-radius:6px;font-size:.88rem;">
  <button type="submit"
    style="padding:8px 16px;background:var(--red-dk);color:#fff;border:none;border-radius:6px;font-size:.88rem;cursor:pointer;">Search</button>
  <?php if ($search): ?>
    <a href="quotes.php" style="padding:8px 12px;border:1px solid var(--grey-lt);border-radius:6px;font-size:.88rem;color:var(--grey-dk);text-decoration:none;line-height:1.6;">Clear</a>
  <?php endif; ?>
</form>

<?php if (empty($quotes)): ?>
  <div style="background:#fff;border-radius:10px;padding:60px 20px;text-align:center;color:var(--grey-mid);">
    <div style="font-size:2.5rem;margin-bottom:10px;">📋</div>
    <div style="font-weight:600;font-size:1rem;margin-bottom:6px;">No quotes yet</div>
    <div style="font-size:.85rem;">Open a request and click <strong>New Quote</strong> to get started.</div>
  </div>
<?php else: ?>
  <div style="background:#fff;border-radius:10px;box-shadow:0 1px 6px rgba(0,0,0,.07);overflow:hidden;">
    <table style="width:100%;border-collapse:collapse;font-size:.85rem;">
      <thead>
        <tr style="background:var(--black);color:rgba(255,255,255,.85);">
          <th style="padding:10px 14px;text-align:left;font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;">#</th>
          <th style="padding:10px 14px;text-align:left;font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;">Customer</th>
          <?php if (!$isStaff): ?>
          <th style="padding:10px 14px;text-align:left;font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;">Agent</th>
          <?php endif; ?>
          <th style="padding:10px 14px;text-align:left;font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;">Program</th>
          <th style="padding:10px 14px;text-align:left;font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;">Pax</th>
          <th style="padding:10px 14px;text-align:right;font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;">Total Price</th>
          <th style="padding:10px 14px;text-align:left;font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;">Status</th>
          <th style="padding:10px 14px;text-align:left;font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;">Date</th>
          <th style="padding:10px 14px;"></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($quotes as $q):
          $pax = (int)$q['adults'] + (int)$q['teens'] + (int)$q['children'];
        ?>
        <tr style="border-bottom:1px solid var(--grey-lt);" onmouseenter="this.style.background='#fbf8f5'" onmouseleave="this.style.background=''">
          <td style="padding:10px 14px;font-weight:700;color:var(--red-dk);font-family:monospace;"><?= h($q['quote_number']) ?></td>
          <td style="padding:10px 14px;font-weight:600;">
            <a href="quote_view.php?id=<?= $q['id'] ?>" style="color:var(--black);text-decoration:none;"><?= h($q['customer_name']) ?></a>
            <?php if ($q['practice_code']): ?>
              <span style="font-size:.72rem;color:var(--grey-mid);margin-left:6px;"><?= h($q['practice_code']) ?></span>
            <?php endif; ?>
          </td>
          <?php if (!$isStaff): ?>
          <td style="padding:10px 14px;color:var(--grey-dk);"><?= h($q['agent_name'] ?? '—') ?></td>
          <?php endif; ?>
          <td style="padding:10px 14px;color:var(--grey-dk);"><?= h($q['program'] ?? '—') ?></td>
          <td style="padding:10px 14px;"><?= $pax ?></td>
          <td style="padding:10px 14px;text-align:right;font-weight:700;font-family:monospace;">
            $<?= number_format((float)$q['total_price'], 0, '.', ',') ?>
          </td>
          <td style="padding:10px 14px;">
            <?php if ($q['status'] === 'final'): ?>
              <span style="background:var(--green-lt);color:var(--green);border-radius:4px;padding:2px 8px;font-size:.72rem;font-weight:700;">Final</span>
            <?php else: ?>
              <span style="background:var(--amber-lt);color:var(--amber);border-radius:4px;padding:2px 8px;font-size:.72rem;font-weight:700;">Draft</span>
            <?php endif; ?>
          </td>
          <td style="padding:10px 14px;color:var(--grey-mid);font-size:.8rem;"><?= date('d M Y', strtotime($q['created_at'])) ?></td>
          <td style="padding:10px 14px;text-align:right;white-space:nowrap;">
            <a href="quote_new.php?edit=<?= $q['id'] ?>&request_id=<?= $q['request_id'] ?>"
               style="font-size:.75rem;padding:4px 10px;border:1px solid var(--red);border-radius:5px;color:var(--red-dk);text-decoration:none;margin-right:4px;">Edit</a>
            <a href="quote_view.php?id=<?= $q['id'] ?>"
               style="font-size:.75rem;padding:4px 10px;border:1px solid var(--grey-lt);border-radius:5px;color:var(--grey-dk);text-decoration:none;margin-right:4px;">View</a>
            <button onclick="deleteQuote(<?= $q['id'] ?>, '<?= h($q['quote_number']) ?>')"
                    style="font-size:.75rem;padding:4px 10px;border:1px solid #fca5a5;border-radius:5px;background:#fff;color:#dc2626;cursor:pointer;">Delete</button>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<script>
function deleteQuote(id, num) {
  if (!confirm('Delete quote ' + num + '?\n\nThis will permanently remove the quote and all its days. This cannot be undone.')) return;
  var fd = new FormData();
  fd.append('id', id);
  fetch('api_delete_quote.php', {method:'POST', body:fd})
    .then(function(r){ return r.json(); })
    .then(function(data){
      if (data.success) { location.reload(); }
      else { alert('Error: ' + data.message); }
    })
    .catch(function(){ alert('Request failed'); });
}
</script>
<?php include 'includes/footer.php'; ?>
