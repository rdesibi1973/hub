<?php
require_once 'config.php';
$db  = db();
$id  = (int)($_GET['id'] ?? 0);

// ── AJAX: cancel payment ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    if ($action === 'cancel_payment') {
        $pid    = (int)($_POST['payment_id'] ?? 0);
        $invId  = (int)($_POST['invoice_id'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');
        if (!$pid || !$reason) { echo json_encode(['ok'=>false,'error'=>'Missing data']); exit; }
        try {
            $db->prepare("UPDATE invoice_payments SET cancelled_at=NOW(), cancellation_reason=? WHERE id=? AND invoice_id=?")
               ->execute([$reason, $pid, $invId]);
            recalculate_invoice($db, $invId);
            echo json_encode(['ok'=>true]);
        } catch (Exception $e) { echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); }
        exit;
    }

    if ($action === 'mark_sent') {
        $invId = (int)($_POST['invoice_id'] ?? 0);
        $db->prepare("UPDATE invoices SET status='Sent', updated_at=NOW() WHERE id=? AND status='Draft'")->execute([$invId]);
        echo json_encode(['ok'=>true]); exit;
    }

    if ($action === 'cancel_invoice') {
        $invId = (int)($_POST['invoice_id'] ?? 0);
        $db->prepare("UPDATE invoices SET status='Cancelled', updated_at=NOW() WHERE id=?")->execute([$invId]);
        echo json_encode(['ok'=>true]); exit;
    }

    echo json_encode(['ok'=>false,'error'=>'Unknown action']); exit;
}

// ── Add payment (form POST redirect) ─────────────────────────────────────
if (isset($_POST['add_payment'])) {
    $payDate  = $_POST['pay_date']   ?? date('Y-m-d');
    $amount   = (float)($_POST['amount'] ?? 0);
    $method   = $_POST['method']     ?? 'Bank Transfer';
    $ref      = trim($_POST['reference'] ?? '');
    $notes    = trim($_POST['pay_notes'] ?? '');
    $pErrors  = [];
    if ($amount <= 0) $pErrors[] = 'Amount must be greater than zero.';
    if (!in_array($method, INV_METHODS)) $pErrors[] = 'Invalid payment method.';

    if (!$pErrors) {
        $db->prepare("INSERT INTO invoice_payments (invoice_id,payment_date,amount,method,reference,notes) VALUES (?,?,?,?,?,?)")
           ->execute([$id,$payDate,$amount,$method,$ref?:null,$notes?:null]);
        recalculate_invoice($db, $id);
        flash("Payment of " . fmt_money($amount, '') . " recorded.");
        header("Location: invoice_view.php?id=$id"); exit;
    }
}

// ── Load invoice ──────────────────────────────────────────────────────────
$s = $db->prepare("SELECT i.*, u.full_name AS created_by_name FROM invoices i LEFT JOIN users u ON u.id=i.created_by WHERE i.id=?");
$s->execute([$id]);
$inv = $s->fetch();
if (!$inv) { flash('Invoice not found.','error'); header('Location: invoices.php'); exit; }

$items = $db->prepare("SELECT * FROM invoice_items WHERE invoice_id=? ORDER BY sort_order,id");
$items->execute([$id]); $items = $items->fetchAll();

$payments = $db->prepare("SELECT * FROM invoice_payments WHERE invoice_id=? ORDER BY payment_date, id");
$payments->execute([$id]); $payments = $payments->fetchAll();

$pageTitle = $inv['invoice_number'];
include 'includes/header.php';

$sym = $inv['currency'] === 'EUR' ? '€' : '$';
?>

<div class="page-header">
  <div>
    <h2><?= h($inv['invoice_number']) ?></h2>
    <div class="sub">
      <a href="invoices.php" style="text-decoration:none;color:var(--grey-mid)">← Invoices</a>
      &nbsp;·&nbsp;
      <span class="badge <?= INV_STATUSES[$inv['status']] ?? '' ?>"><?= h($inv['status']) ?></span>
      &nbsp;·&nbsp; <?= date('d M Y', strtotime($inv['issue_date'])) ?>
      <?php if ($inv['request_id']): ?>
        &nbsp;·&nbsp; <a href="../leads/request_view.php?id=<?= $inv['request_id'] ?>" style="color:var(--blue)">Req #<?= $inv['request_id'] ?></a>
      <?php endif; ?>
    </div>
  </div>
  <div class="gap-8">
    <?php if ($inv['status'] === 'Draft' && isInvoiceAdmin()): ?>
      <button class="btn btn-amber" onclick="markSent()">✉ Mark as Sent</button>
    <?php endif; ?>
    <a href="invoice_edit.php?id=<?= $id ?>" class="btn btn-outline">Edit</a>
    <a href="invoice_pdf.php?id=<?= $id ?>" class="btn btn-outline" target="_blank">🖨 PDF</a>
    <?php if ($inv['status'] !== 'Cancelled' && isInvoiceAdmin()): ?>
      <button class="btn btn-danger" onclick="cancelInvoice()">Cancel Invoice</button>
    <?php endif; ?>
  </div>
</div>

<!-- ── INVOICE DETAILS ─────────────────────────────────────────────────── -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;max-width:860px;margin-bottom:24px;">

  <div class="table-wrap" style="margin-bottom:0">
    <div class="detail-grid">
      <div class="detail-label">Issuer</div>
      <div class="detail-value" style="font-weight:600"><?= h($inv['issuer']) ?></div>
      <div class="detail-label">Bill To</div>
      <div class="detail-value">
        <strong><?= h($inv['bill_to_name']) ?></strong>
        <?php if ($inv['bill_to_address']): ?>
          <br><span style="font-size:.8rem;color:var(--grey-mid)"><?= nl2br(h($inv['bill_to_address'])) ?></span>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="table-wrap" style="margin-bottom:0">
    <div class="detail-grid">
      <div class="detail-label">Issue Date</div>
      <div class="detail-value"><?= date('d M Y', strtotime($inv['issue_date'])) ?></div>
      <div class="detail-label">Due Date</div>
      <div class="detail-value"><?= $inv['due_date'] ? date('d M Y', strtotime($inv['due_date'])) : '<span style="color:var(--grey-mid)">—</span>' ?></div>
      <div class="detail-label">Terms</div>
      <div class="detail-value"><?= h($inv['terms'] ?? '—') ?></div>
      <div class="detail-label">Currency</div>
      <div class="detail-value"><?= h($inv['currency']) ?></div>
    </div>
  </div>

</div>

<!-- ── LINE ITEMS ─────────────────────────────────────────────────────── -->
<div class="table-wrap" style="max-width:860px;margin-bottom:0">
  <table>
    <thead>
      <tr>
        <th>#</th>
        <th>Description</th>
        <th class="text-right">Qty</th>
        <th class="text-right">Rate (<?= h($inv['currency']) ?>)</th>
        <th class="text-right">Amount</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($items as $n => $item): ?>
      <tr>
        <td class="text-muted"><?= $n+1 ?></td>
        <td><?= h($item['description']) ?></td>
        <td class="text-right"><?= rtrim(rtrim(number_format($item['quantity'],2),'0'),'.') ?></td>
        <td class="text-right <?= $item['unit_price'] < 0 ? 'text-red' : '' ?>">
          <?= fmt_money((float)$item['unit_price'], $inv['currency']) ?>
        </td>
        <td class="text-right" style="font-weight:600 <?= $item['line_total'] < 0 ? ';color:var(--red)' : '' ?>">
          <?= fmt_money((float)$item['line_total'], $inv['currency']) ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<!-- ── TOTALS ─────────────────────────────────────────────────────────── -->
<div style="display:flex;justify-content:flex-end;max-width:860px;margin-bottom:28px;">
  <div class="totals-box">
    <div class="totals-row"><span>Sub Total</span><span><?= fmt_money((float)$inv['subtotal'], $inv['currency']) ?></span></div>
    <div class="totals-row total"><span>Total</span><span><?= fmt_money((float)$inv['total'], $inv['currency']) ?></span></div>
    <?php if ($inv['amount_paid'] > 0): ?>
    <div class="totals-row paid"><span>Payment Made</span><span>(-) <?= fmt_money((float)$inv['amount_paid'], $inv['currency']) ?></span></div>
    <?php endif; ?>
    <div class="totals-row balance">
      <span>Balance Due</span>
      <span style="<?= (float)$inv['balance_due'] <= 0 ? 'color:var(--green)' : 'color:var(--black)' ?>">
        <?= fmt_money((float)$inv['balance_due'], $inv['currency']) ?>
      </span>
    </div>
  </div>
</div>

<!-- ── NOTES + T&C ────────────────────────────────────────────────────── -->
<?php if ($inv['notes'] || $inv['terms_conditions']): ?>
<div style="max-width:860px;display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:28px;">
  <?php if ($inv['notes']): ?>
  <div>
    <div style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--grey-mid);margin-bottom:6px;">Notes</div>
    <div style="font-size:.83rem;color:var(--grey-dk);line-height:1.6"><?= nl2br(h($inv['notes'])) ?></div>
  </div>
  <?php endif; ?>
  <?php if ($inv['terms_conditions']): ?>
  <div>
    <div style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--grey-mid);margin-bottom:6px;">Terms &amp; Conditions</div>
    <div style="font-size:.83rem;color:var(--grey-dk);line-height:1.6"><?= nl2br(h($inv['terms_conditions'])) ?></div>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- ── PAYMENTS ───────────────────────────────────────────────────────── -->
<?php if ($inv['status'] !== 'Cancelled'): ?>
<div class="section-label">Payments</div>
<div class="table-wrap" style="max-width:860px;margin-bottom:16px">
  <table>
    <thead>
      <tr>
        <th>Date</th>
        <th>Method</th>
        <th>Reference</th>
        <th class="text-right">Amount</th>
        <th>Notes</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php if ($payments): ?>
        <?php foreach ($payments as $p): ?>
        <tr class="<?= $p['cancelled_at'] ? 'payment-cancelled' : '' ?>">
          <td style="white-space:nowrap"><?= date('d M Y', strtotime($p['payment_date'])) ?></td>
          <td><?= h($p['method']) ?></td>
          <td class="text-muted"><?= h($p['reference'] ?? '—') ?></td>
          <td class="text-right" style="font-weight:600"><?= fmt_money((float)$p['amount'], $inv['currency']) ?></td>
          <td class="text-muted" style="font-size:.78rem">
            <?= h($p['notes'] ?? '') ?>
            <?php if ($p['cancelled_at']): ?>
              <span style="color:var(--red);display:block">❌ Cancelled <?= date('d M Y', strtotime($p['cancelled_at'])) ?><br><?= h($p['cancellation_reason']) ?></span>
            <?php endif; ?>
          </td>
          <td>
            <?php if (!$p['cancelled_at']): ?>
              <button class="btn btn-danger btn-sm" onclick="cancelPayment(<?= $p['id'] ?>, <?= $id ?>)">Cancel</button>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      <?php else: ?>
        <tr><td colspan="6" style="text-align:center;padding:20px;color:var(--grey-mid);font-size:.83rem">No payments recorded yet.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<!-- Add payment form -->
<?php if (in_array($inv['status'], ['Draft','Sent','Partially Paid'])): ?>
<div class="table-wrap" style="max-width:860px;padding:20px 24px;">
  <div style="font-size:.8rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--grey-dk);margin-bottom:16px;">Record Payment</div>
  <form method="POST" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;">
    <input type="hidden" name="add_payment" value="1">
    <div class="form-group" style="gap:4px">
      <label style="font-size:.7rem">Date</label>
      <input type="date" name="pay_date" value="<?= date('Y-m-d') ?>" style="padding:7px 10px;border:1.5px solid var(--grey-lt);border-radius:6px;font-family:inherit;font-size:.83rem">
    </div>
    <div class="form-group" style="gap:4px">
      <label style="font-size:.7rem">Amount (<?= h($inv['currency']) ?>)</label>
      <input type="number" name="amount" step="0.01" min="0.01"
             placeholder="<?= $inv['balance_due'] > 0 ? number_format($inv['balance_due'],2) : '0.00' ?>"
             style="padding:7px 10px;border:1.5px solid var(--grey-lt);border-radius:6px;font-family:inherit;font-size:.83rem;width:130px">
    </div>
    <div class="form-group" style="gap:4px">
      <label style="font-size:.7rem">Method</label>
      <select name="method" style="padding:7px 10px;border:1.5px solid var(--grey-lt);border-radius:6px;font-family:inherit;font-size:.83rem">
        <?php foreach (INV_METHODS as $m): ?><option value="<?= h($m) ?>"><?= h($m) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="form-group" style="gap:4px">
      <label style="font-size:.7rem">Reference</label>
      <input type="text" name="reference" placeholder="Transfer ref, etc." style="padding:7px 10px;border:1.5px solid var(--grey-lt);border-radius:6px;font-family:inherit;font-size:.83rem;width:160px">
    </div>
    <div class="form-group" style="gap:4px">
      <label style="font-size:.7rem">Notes</label>
      <input type="text" name="pay_notes" style="padding:7px 10px;border:1.5px solid var(--grey-lt);border-radius:6px;font-family:inherit;font-size:.83rem;width:160px">
    </div>
    <button type="submit" class="btn btn-green">Record Payment</button>
  </form>
</div>
<?php endif; ?>
<?php endif; // not cancelled ?>

<!-- ── META ───────────────────────────────────────────────────────────── -->
<div style="font-size:.7rem;color:var(--grey-mid);max-width:860px;margin-top:16px">
  Invoice #<?= $inv['id'] ?> &nbsp;·&nbsp;
  Created <?= date('d M Y H:i', strtotime($inv['created_at'])) ?>
  <?= $inv['created_by_name'] ? ' by ' . h($inv['created_by_name']) : '' ?>
  &nbsp;·&nbsp; Updated <?= date('d M Y H:i', strtotime($inv['updated_at'])) ?>
</div>

<script>
async function markSent() {
  var ok = await seConfirm('Mark as Sent', 'Mark invoice <?= h($inv['invoice_number']) ?> as Sent?\n\nThis means the invoice has been sent to the customer.');
  if (!ok) return;
  var fd = new FormData();
  fd.append('action','mark_sent'); fd.append('invoice_id','<?= $id ?>');
  fetch('invoice_view.php', {method:'POST',body:fd}).then(function(r){return r.json();}).then(function(d){ if(d.ok) location.reload(); });
}
async function cancelInvoice() {
  var ok = await seConfirm('Cancel Invoice', 'Cancel invoice <?= h($inv['invoice_number']) ?>?\n\nThis will mark it as Cancelled. Existing payments are not affected.');
  if (!ok) return;
  var fd = new FormData();
  fd.append('action','cancel_invoice'); fd.append('invoice_id','<?= $id ?>');
  fetch('invoice_view.php', {method:'POST',body:fd}).then(function(r){return r.json();}).then(function(d){ if(d.ok) location.reload(); });
}
</script>

<?php include 'includes/footer.php'; ?>
