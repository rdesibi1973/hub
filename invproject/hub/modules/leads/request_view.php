<?php
require_once 'config.php';

$id  = (int)($_GET['id'] ?? 0);
$stmt = db()->prepare("
    SELECT r.*, a.name AS agent_name
    FROM requests r
    LEFT JOIN agents a ON a.id = r.agent_id
    WHERE r.id = ?
");
$stmt->execute([$id]);
$r = $stmt->fetch();
if (!$r) { flash('Request not found.', 'error'); header('Location: requests.php'); exit; }

// Staff can only view their own requests
if (isLeadsRestricted() && (int)$r['agent_id'] !== getStaffAgentId()) {
    flash('Access denied.', 'error');
    header('Location: requests.php');
    exit;
}

$pageTitle = $r['customer_name'];
include 'includes/header.php';
?>

<div class="page-header">
  <div>
    <h2><?= h($r['customer_name']) ?></h2>
    <div class="sub">
      <a href="requests.php" class="text-muted" style="text-decoration:none">← Requests</a>
      &nbsp;·&nbsp;
      <span class="badge <?= STATUSES[$r['status']] ?? '' ?>"><?= h($r['status']) ?></span>
      &nbsp;·&nbsp; Added <?= date('d M Y', strtotime($r['created_at'])) ?>
    </div>
  </div>
  <div class="gap-8">
    <?php if ($r['dropbox_url']): ?>
      <a href="<?= h($r['dropbox_url']) ?>" target="_blank" class="btn btn-outline">
        📁 Open Dropbox Folder
      </a>
    <?php endif; ?>
    <a href="request_edit.php?id=<?= $r['id'] ?>" class="btn btn-red">Edit</a>
    <button type="button"
            onclick="deleteRequest(<?= $r['id'] ?>, '<?= addslashes(h($r['customer_name'])) ?>', '<?= addslashes(h($r['practice_code'] ?? '')) ?>', '<?= addslashes(h($r['status'] ?? '')) ?>', 'requests.php')"
            class="btn btn-outline"
            style="color:#C0211B;border-color:#C0211B;">🗑 Delete</button>
    <a href="request_add.php" class="btn btn-outline">+ New Request</a>
  </div>
</div>


<!-- MAIN DETAILS -->
<div class="table-wrap" style="max-width:860px;margin-bottom:20px">
  <div class="detail-grid">

    <div class="detail-label">Dropbox Folder</div>
    <div class="detail-value">
      <?= $r['practice_code'] ? h($r['practice_code']) : '<span class="text-muted">—</span>' ?>
    </div>

    <div class="detail-label">Date Received</div>
    <div class="detail-value"><?= date('d M Y', strtotime($r['date_received'])) ?></div>

    <div class="detail-label">Source</div>
    <div class="detail-value"><?= h($r['source'] ?? '—') ?></div>

    <div class="detail-label">Agent</div>
    <div class="detail-value"><?= h($r['agent_name'] ?? '—') ?></div>

    <div class="detail-label">Destination / Type</div>
    <div class="detail-value"><?= $r['destination'] ? h($r['destination']) : '<span class="text-muted">—</span>' ?></div>

    <div class="detail-label">Period</div>
    <div class="detail-value"><?= $r['period'] ? h($r['period']) : '<span class="text-muted">—</span>' ?></div>

    <div class="detail-label">Pax</div>
    <div class="detail-value"><?= $r['pax'] ?: '<span class="text-muted">—</span>' ?></div>

    <div class="detail-label">Status</div>
    <div class="detail-value">
      <span class="badge <?= STATUSES[$r['status']] ?? '' ?>"><?= h($r['status']) ?></span>
    </div>

    <div class="detail-label">Dropbox Folder Link</div>
    <div class="detail-value">
      <?php if ($r['dropbox_url']): ?>
        <a href="<?= h($r['dropbox_url']) ?>" target="_blank">📁 Open Dropbox Folder</a>
      <?php else: ?>
        <span class="text-muted">— not set yet</span>
      <?php endif; ?>
    </div>

  </div>
</div>

<!-- FINANCIALS -->
<div class="section-label">Financials</div>
<div class="table-wrap" style="max-width:860px;margin-bottom:20px">
  <div class="detail-grid">

    <div class="detail-label">Value (USD)</div>
    <div class="detail-value">
      <?= $r['value_usd'] ? '<strong>$'.number_format((float)$r['value_usd'],2).'</strong>' : '<span class="text-muted">—</span>' ?>
    </div>

    <div class="detail-label">Commission %</div>
    <div class="detail-value"><?= $r['commission_pct'] ? h($r['commission_pct']).'%' : '<span class="text-muted">—</span>' ?></div>

    <div class="detail-label">Commission (USD)</div>
    <div class="detail-value text-green">
      <?= $r['commission_usd'] ? '<strong>$'.number_format((float)$r['commission_usd'],2).'</strong>' : '<span class="text-muted">—</span>' ?>
    </div>

    <div class="detail-label">Date Paid</div>
    <div class="detail-value">
      <?= $r['date_paid'] ? date('d M Y', strtotime($r['date_paid'])) : '<span class="text-muted">— not paid yet</span>' ?>
    </div>

  </div>
</div>

<!-- INITIAL REQUEST -->
<?php if ($r['initial_request']): ?>
<div class="section-label">Initial Request</div>
<div class="table-wrap" style="max-width:860px;margin-bottom:20px">
  <div style="padding:20px 22px;font-size:.85rem;line-height:1.7;white-space:pre-wrap;color:var(--grey-dk)"><?= h($r['initial_request']) ?></div>
</div>
<?php endif; ?>

<!-- NOTES -->
<?php if ($r['notes']): ?>
<div class="section-label">Internal Notes</div>
<div class="table-wrap" style="max-width:860px;margin-bottom:20px">
  <div style="padding:20px 22px;font-size:.85rem;line-height:1.7;white-space:pre-wrap;color:var(--grey-dk)"><?= h($r['notes']) ?></div>
</div>
<?php endif; ?>

<!-- META -->
<div style="font-size:.7rem;color:var(--grey-mid);max-width:860px;margin-top:8px">
  Record #<?= $r['id'] ?> &nbsp;·&nbsp;
  Created <?= date('d M Y H:i', strtotime($r['created_at'])) ?> &nbsp;·&nbsp;
  Updated <?= date('d M Y H:i', strtotime($r['updated_at'])) ?>
</div>

<?php include 'includes/footer.php'; ?>
