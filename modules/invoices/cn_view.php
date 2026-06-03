<?php
require_once 'config.php';
$db = db();
$id = (int)($_GET['id'] ?? 0);

// ── AJAX actions ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'];
    $cnId   = (int)($_POST['cn_id'] ?? 0);

    if ($action === 'cancel_cn') {
        try {
            $db->prepare("UPDATE credit_notes SET status='Cancelled', updated_at=NOW() WHERE id=? AND status='Issued'")
               ->execute([$cnId]);
            sync_cn_invoice_payment($db, $cnId); // cancels the linked refund + resyncs
            echo json_encode(['ok'=>true]); exit;
        } catch (\Throwable $e) { echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); exit; }
    }

    if ($action === 'restore_cn') {
        try {
            $db->prepare("UPDATE credit_notes SET status='Issued', updated_at=NOW() WHERE id=? AND status='Cancelled'")
               ->execute([$cnId]);
            sync_cn_invoice_payment($db, $cnId); // re-applies the refund + resyncs
            echo json_encode(['ok'=>true]); exit;
        } catch (\Throwable $e) { echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); exit; }
    }

    echo json_encode(['ok'=>false,'error'=>'Unknown action']); exit;
}

// ── Load credit note ────────────────────────────────────────────────────────
$s = $db->prepare(
    "SELECT cn.*, u.full_name AS created_by_name,
            i.invoice_number, i.total AS invoice_total, i.balance_due AS invoice_balance
     FROM credit_notes cn
     LEFT JOIN users u ON u.id = cn.created_by
     LEFT JOIN invoices i ON i.id = cn.invoice_id
     WHERE cn.id=?"
);
$s->execute([$id]);
$cn = $s->fetch();
if (!$cn) { flash('Credit note not found.','error'); header('Location: cn_list.php'); exit; }

$items = $db->prepare("SELECT * FROM credit_note_items WHERE credit_note_id=? ORDER BY sort_order,id");
$items->execute([$id]); $items = $items->fetchAll();

$pageTitle = $cn['cn_number'];
include 'includes/header.php';

$sym = $cn['currency'] === 'EUR' ? '€' : '$';
?>

<div class="page-header">
  <div>
    <h2><?= h($cn['cn_number']) ?></h2>
    <div class="sub">
      <a href="cn_list.php" style="text-decoration:none;color:var(--grey-mid)">← Credit Notes</a>
      &nbsp;·&nbsp;
      <span class="badge <?= CN_STATUSES[$cn['status']] ?? '' ?>"><?= h($cn['status']) ?></span>
      &nbsp;·&nbsp; <?= date('d M Y', strtotime($cn['issue_date'])) ?>
      &nbsp;·&nbsp; against
      <a href="invoice_view.php?id=<?= (int)$cn['invoice_id'] ?>" style="color:var(--blue)"><?= h($cn['invoice_number']) ?></a>
      <?php if ($cn['request_id']): ?>
        &nbsp;·&nbsp; <a href="../leads/request_view.php?id=<?= (int)$cn['request_id'] ?>" style="color:var(--blue)">Req #<?= (int)$cn['request_id'] ?></a>
      <?php endif; ?>
    </div>
  </div>
  <div class="gap-8">
    <a href="cn_pdf.php?id=<?= $id ?>&download=1" class="btn btn-outline" target="_blank">🖨 PDF</a>
    <?php if ($cn['status'] === 'Issued' && isInvoiceAdmin()): ?>
      <button class="btn btn-danger" onclick="cancelCn()">Cancel</button>
    <?php elseif ($cn['status'] === 'Cancelled' && isInvoiceAdmin()): ?>
      <button class="btn btn-outline" onclick="restoreCn()">↩ Restore</button>
    <?php endif; ?>
  </div>
</div>

<?php if ($cn['status'] === 'Cancelled'): ?>
<div class="flash" style="background:#FAE8E7;color:#A01A14;border-left:4px solid #C0211B;max-width:860px;">
  This credit note is cancelled — the refund has been removed from <?= h($cn['invoice_number']) ?>.
</div>
<?php else: ?>
<div class="flash" style="background:#E8F5E9;color:#1A6B3A;border-left:4px solid #2E7D32;max-width:860px;">
  A refund of <strong><?= fmt_money((float)$cn['total'], $cn['currency']) ?></strong>
  has been applied to invoice <strong><?= h($cn['invoice_number']) ?></strong>.
</div>
<?php endif; ?>

<!-- Details -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;max-width:860px;margin-bottom:24px;">
  <div class="table-wrap" style="margin-bottom:0">
    <div class="detail-grid">
      <div class="detail-label">Issuer</div>
      <div class="detail-value" style="font-weight:600"><?= h($cn['issuer']) ?></div>
      <div class="detail-label">Credit To</div>
      <div class="detail-value">
        <strong><?= h($cn['bill_to_name']) ?></strong>
        <?php if ($cn['bill_to_address']): ?>
          <br><span style="font-size:.8rem;color:var(--grey-mid)"><?= nl2br(h($cn['bill_to_address'])) ?></span>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div class="table-wrap" style="margin-bottom:0">
    <div class="detail-grid">
      <div class="detail-label">Issue Date</div>
      <div class="detail-value"><?= date('d M Y', strtotime($cn['issue_date'])) ?></div>
      <div class="detail-label">Currency</div>
      <div class="detail-value"><?= h($cn['currency']) ?></div>
      <div class="detail-label">Reason</div>
      <div class="detail-value"><?= $cn['reason'] ? h($cn['reason']) : '<span style="color:var(--grey-mid)">—</span>' ?></div>
    </div>
  </div>
</div>

<!-- Credit lines -->
<div class="table-wrap" style="max-width:860px;margin-bottom:0">
  <table>
    <thead>
      <tr>
        <th>#</th>
        <th>Description</th>
        <th class="text-right">Qty</th>
        <th class="text-right">Amount (<?= h($cn['currency']) ?>)</th>
        <th class="text-right">Credit</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($items as $n => $item): ?>
      <tr>
        <td class="text-muted"><?= $n+1 ?></td>
        <td><?= h($item['description']) ?></td>
        <td class="text-right"><?= rtrim(rtrim(number_format($item['quantity'],2),'0'),'.') ?></td>
        <td class="text-right"><?= fmt_money((float)$item['unit_price'], $cn['currency']) ?></td>
        <td class="text-right" style="font-weight:600;color:var(--red)"><?= fmt_money((float)$item['line_total'], $cn['currency']) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<!-- Totals -->
<div style="display:flex;justify-content:flex-end;max-width:860px;margin-bottom:28px;">
  <div class="totals-box">
    <div class="totals-row"><span>Sub Total</span><span><?= fmt_money((float)$cn['subtotal'], $cn['currency']) ?></span></div>
    <div class="totals-row total"><span>Total Credit</span><span style="color:var(--red)"><?= fmt_money((float)$cn['total'], $cn['currency']) ?></span></div>
  </div>
</div>

<?php if ($cn['notes']): ?>
<div style="max-width:860px;margin-bottom:28px;">
  <div style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--grey-mid);margin-bottom:6px;">Notes</div>
  <div style="font-size:.83rem;color:var(--grey-dk);line-height:1.6"><?= nl2br(h($cn['notes'])) ?></div>
</div>
<?php endif; ?>

<script>
async function cancelCn() {
  if (!confirm('Cancel credit note <?= h($cn['cn_number']) ?>?\n\nThe refund will be removed from <?= h($cn['invoice_number']) ?> and the request value restored.')) return;
  var fd = new FormData();
  fd.append('action','cancel_cn'); fd.append('cn_id','<?= $id ?>');
  var r = await fetch('', {method:'POST', body:fd});
  var d = await r.json();
  if (d.ok) location.reload(); else alert(d.error || 'Error');
}
async function restoreCn() {
  if (!confirm('Restore credit note <?= h($cn['cn_number']) ?>?\n\nThe refund will be re-applied to <?= h($cn['invoice_number']) ?>.')) return;
  var fd = new FormData();
  fd.append('action','restore_cn'); fd.append('cn_id','<?= $id ?>');
  var r = await fetch('', {method:'POST', body:fd});
  var d = await r.json();
  if (d.ok) location.reload(); else alert(d.error || 'Error');
}
</script>

<?php include 'includes/footer.php'; ?>
