<?php
require_once 'config.php';
$db  = db();
$id  = (int)($_GET['id'] ?? 0);

// ── Folder status helpers (defined here so AJAX handler can use them) ─────
const FOLDER_TAG_OPTIONS = [
    'PROGRESS'     => 'PROGRESS',
    'PROVISIONAL'  => 'PROVISIONAL',
    'DEPOSIT'      => 'DEPOSIT',
    'BALANCE'      => 'BALANCE',
    'BALANCE-CASH' => 'BALANCE-CASH',
    'FULLY PAID'   => 'PAID',
];
function folder_current_tag(string $name): string {
    // If the folder ends with _CK, look at the status tag that precedes it
    if (str_ends_with($name, '_CK')) $name = substr($name, 0, -3);
    foreach (['BALANCE-CASH','BALANCE','DEPOSIT','PROGRESS','PROVISIONAL','PAID','CK','CANCELLED','BOOKED'] as $tag) {
        if (str_ends_with($name, '_'.$tag)) return $tag;
    }
    return '';
}
function folder_strip_tag(string $name): string {
    foreach (['_BALANCE-CASH','_BALANCE','_DEPOSIT','_PROGRESS','_PROVISIONAL','_PAID','_CK','_CANCELLED','_BOOKED'] as $tag) {
        if (str_ends_with($name, $tag)) return substr($name, 0, -strlen($tag));
    }
    return $name;
}

// ── AJAX: cancel payment ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    ob_start();
    header('Content-Type: application/json');
    $action = $_POST['action'];

    if ($action === 'cancel_payment') {
        $pid    = (int)($_POST['payment_id'] ?? 0);
        $invId  = (int)($_POST['invoice_id'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');
        if (!$pid || !$reason) { ob_end_clean(); echo json_encode(['ok'=>false,'error'=>'Missing data']); exit; }
        try {
            $db->prepare("UPDATE invoice_payments SET cancelled_at=NOW(), cancellation_reason=? WHERE id=? AND invoice_id=?")
               ->execute([$reason, $pid, $invId]);
            recalculate_invoice($db, $invId);
            sync_request_value($db, $invId);
            ob_end_clean(); echo json_encode(['ok'=>true]);
        } catch (\Throwable $e) { ob_end_clean(); echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); }
        exit;
    }

    if ($action === 'mark_sent') {
        $invId = (int)($_POST['invoice_id'] ?? 0);
        $db->prepare("UPDATE invoices SET status='New', updated_at=NOW() WHERE id=?")->execute([$invId]);
        ob_end_clean(); echo json_encode(['ok'=>true]); exit;
    }

    if ($action === 'cancel_invoice') {
        $invId = (int)($_POST['invoice_id'] ?? 0);
        $db->prepare("UPDATE invoices SET status='Cancelled', updated_at=NOW() WHERE id=?")->execute([$invId]);
        sync_request_value($db, $invId);
        ob_end_clean(); echo json_encode(['ok'=>true]); exit;
    }

    if ($action === 'restore_invoice') {
        $invId = (int)($_POST['invoice_id'] ?? 0);
        $db->prepare("UPDATE invoices SET status='New', updated_at=NOW() WHERE id=? AND status='Cancelled'")->execute([$invId]);
        recalculate_invoice($db, $invId);
        sync_request_value($db, $invId);
        $finalStatus = $db->query("SELECT status FROM invoices WHERE id=$invId")->fetchColumn();
        $warning = $finalStatus !== 'New'
            ? "Invoice has recorded payments — status has been set to \"$finalStatus\" instead of New. To revert to New, cancel all payments first."
            : null;
        ob_end_clean(); echo json_encode(['ok'=>true, 'warning'=>$warning]); exit;
    }

    if ($action === 'set_status') {
        $invId   = (int)($_POST['invoice_id'] ?? 0);
        $nstatus = $_POST['new_status'] ?? '';
        if (!in_array($nstatus, ['New', 'Cancelled'])) {
            ob_end_clean(); echo json_encode(['ok'=>false,'error'=>'This status is set automatically by the payment system.']); exit;
        }
        $db->prepare("UPDATE invoices SET status=?, updated_at=NOW() WHERE id=?")->execute([$nstatus, $invId]);
        $warning = null;
        if ($nstatus === 'New') {
            recalculate_invoice($db, $invId);
            sync_request_value($db, $invId);
            $finalStatus = $db->query("SELECT status FROM invoices WHERE id=$invId")->fetchColumn();
            if ($finalStatus !== 'New') {
                $warning = "Invoice has recorded payments — status has been set to \"$finalStatus\" instead of New. To revert to New, cancel all payments first.";
            }
        }
        ob_end_clean(); echo json_encode(['ok'=>true, 'warning'=>$warning]); exit;
    }

    if ($action === 'update_folder') {
        $invId    = (int)($_POST['invoice_id'] ?? 0);
        $newLabel = trim($_POST['folder_status'] ?? '');
        $tagMap   = ['PROGRESS'=>'PROGRESS','PROVISIONAL'=>'PROVISIONAL','DEPOSIT'=>'DEPOSIT',
                     'BALANCE'=>'BALANCE','BALANCE-CASH'=>'BALANCE-CASH','FULLY PAID'=>'PAID'];
        if (!array_key_exists($newLabel, $tagMap)) {
            ob_end_clean(); echo json_encode(['ok'=>false,'error'=>'Invalid status selected.']); exit;
        }
        $dbTag = $tagMap[$newLabel];
        try {
            $reqRow = $db->prepare(
                "SELECT r.id, r.practice_code, r.dropbox_url, r.status
                 FROM invoices i JOIN requests r ON r.id = i.request_id WHERE i.id = ?"
            );
            $reqRow->execute([$invId]);
            $req = $reqRow->fetch();
            if (!$req || !$req['practice_code']) {
                ob_end_clean(); echo json_encode(['ok'=>false,'error'=>'No linked Dropbox folder found.']); exit;
            }

            $oldName  = $req['practice_code'];
            // Preserve _CK suffix: strip it before removing the status tag,
            // then re-append it after the new status tag (e.g. _DEPOSIT_CK → _BALANCE_CK)
            $hasCK    = str_ends_with($oldName, '_CK');
            $baseName = folder_strip_tag($hasCK ? substr($oldName, 0, -3) : $oldName);
            $newName  = $baseName . '_' . $dbTag . ($hasCK ? '_CK' : '');

            // ── Rename folder in Dropbox ───────────────────────────────────
            $dropboxPrefix = 'https://www.dropbox.com/home';
            $oldUrl        = $req['dropbox_url'] ?? '';
            $fromPath = $toPath = '';

            if (str_starts_with($oldUrl, $dropboxPrefix)) {
                $apiPath   = urldecode(substr($oldUrl, strlen($dropboxPrefix)));
                $lastSlash = strrpos($apiPath, '/');
                if ($lastSlash !== false) {
                    $parentPath = substr($apiPath, 0, $lastSlash);
                    $fromPath   = $apiPath;
                    $toPath     = $parentPath . '/' . $newName;
                }
            }
            if ($fromPath === '') {
                $parentPath = ($req['status'] === 'Booked') ? '/001_Safari' : '/2026';
                $fromPath   = $parentPath . '/' . $oldName;
                $toPath     = $parentPath . '/' . $newName;
            }

            require_once __DIR__ . '/../../modules/leads/dropbox_constants.php';
            require_once __DIR__ . '/../../modules/leads/dropbox_helper.php';
            $token = dropbox_get_access_token();
            if ($fromPath === '' || $toPath === '') {
                ob_end_clean();
                echo json_encode(['ok'=>false,'error'=>'Cannot build Dropbox path. dropbox_url in DB: ' . $oldUrl]);
                exit;
            }

            // Resolve the real Dropbox path by searching for the folder name,
            // because the stored dropbox_url may have wrong case or subfolder.
            $realFromPath = dropbox_find_folder($token, $oldName);
            if ($realFromPath !== null) {
                // Derive toPath from real parent
                $realParent = substr($realFromPath, 0, strrpos($realFromPath, '/'));
                $toPath     = $realParent . '/' . $newName;
                $fromPath   = $realFromPath;
            }

            try {
                dropbox_move_folder($token, $fromPath, $toPath);
            } catch (\Throwable $dbx) {
                // If folder not found, the stored dropbox_url parent is stale (e.g. folder was
                // confirmed into 001_Safari but DB still says /2026/).
                // Retry by swapping /2026/ ↔ /001_Safari/ before giving up.
                $retried = false;
                if (str_contains($dbx->getMessage(), 'not_found')) {
                    $altFrom = null;
                    if (str_starts_with($fromPath, '/2026/')) {
                        $altFrom = '/001_Safari/' . $oldName;
                        $altTo   = '/001_Safari/' . $newName;
                    } elseif (str_starts_with($fromPath, '/001_Safari/')) {
                        $altFrom = '/2026/' . $oldName;
                        $altTo   = '/2026/'  . $newName;
                    }
                    if ($altFrom !== null) {
                        try {
                            dropbox_move_folder($token, $altFrom, $altTo);
                            // Use the alternate paths for the URL rebuild below
                            $fromPath = $altFrom;
                            $toPath   = $altTo;
                            $retried  = true;
                        } catch (\Throwable $ignored) {}
                    }
                }
                if (!$retried) {
                    ob_end_clean();
                    echo json_encode(['ok'=>false,'error'=>$dbx->getMessage() . ' | from: ' . $fromPath . ' | to: ' . $toPath]);
                    exit;
                }
            }

            // ── Update DB ──────────────────────────────────────────────────
            // Rebuild dropbox_url from the path that actually succeeded ($toPath),
            // so stale /2026/ URLs get corrected to /001_Safari/ after a retry.
            $newUrl = '';
            if ($toPath !== '') {
                $newUrl = 'https://www.dropbox.com/home' . $toPath;
            } elseif ($oldUrl !== '') {
                $lastSlash = strrpos($oldUrl, '/');
                $newUrl    = substr($oldUrl, 0, $lastSlash + 1) . rawurlencode($newName);
            }

            // Derive payment_status from the new folder tag
            $psMap = [
                'DEPOSIT'      => 'Deposit',
                'BALANCE'      => 'Balance',
                'BALANCE-CASH' => 'Balance-Cash',
                'PAID'         => 'Paid',
                'PROGRESS'     => null,
                'PROVISIONAL'  => null,
            ];
            $newPs = array_key_exists($dbTag, $psMap) ? $psMap[$dbTag] : false;

            if ($newPs !== false) {
                $db->prepare("UPDATE requests SET practice_code=?, dropbox_url=?, payment_status=? WHERE id=?")
                   ->execute([$newName, $newUrl, $newPs, (int)$req['id']]);
            } else {
                $db->prepare("UPDATE requests SET practice_code=?, dropbox_url=? WHERE id=?")
                   ->execute([$newName, $newUrl, (int)$req['id']]);
            }

            ob_end_clean(); echo json_encode(['ok'=>true,'new_name'=>$newName,'new_tag'=>$dbTag,'new_url'=>$newUrl]); exit;
        } catch (\Throwable $e) {
            ob_end_clean(); echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); exit;
        }
    }

    ob_end_clean(); echo json_encode(['ok'=>false,'error'=>'Unknown action']); exit;
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
        sync_request_value($db, $id);
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

// ── Load linked request (for folder status) ───────────────────────────────
$linkedRequest = null;
$currentTag    = '';
if ($inv['request_id']) {
    $rs = $db->prepare("SELECT id, practice_code, dropbox_url, email, customer_name FROM requests WHERE id=?");
    $rs->execute([$inv['request_id']]);
    $linkedRequest = $rs->fetch();
    if ($linkedRequest && $linkedRequest['practice_code']) {
        $currentTag = folder_current_tag($linkedRequest['practice_code']);
    }
}

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
    <a href="invoice_edit.php?id=<?= $id ?>" class="btn btn-outline">Edit</a>
    <a href="#" class="btn btn-outline" onclick="openMailModal();return false;">✉ Mail</a>
    <a href="invoice_pdf.php?id=<?= $id ?>&download=1" class="btn btn-outline" target="_blank">🖨 PDF</a>
    <?php if ($inv['status'] === 'Cancelled' && isInvoiceAdmin()): ?>
      <button class="btn btn-outline" onclick="restoreInvoice()">↩ Restore</button>
    <?php endif; ?>
    <?php if ($inv['status'] !== 'Cancelled' && isInvoiceAdmin()): ?>
      <select id="statusSelect" onchange="setStatus(this.value)"
              style="padding:6px 10px;border:1.5px solid var(--grey-lt);border-radius:6px;font-size:.82rem;cursor:pointer;background:#fff;">
        <?php foreach (INV_STATUSES as $s => $cls): ?>
          <?php if (!in_array($s, ['New', 'Cancelled'])) continue; // Partially Paid and Fully Paid are set automatically ?>
          <option value="<?= h($s) ?>" <?= $inv['status'] === $s ? 'selected' : '' ?>><?= h($s) ?></option>
        <?php endforeach; ?>
      </select>
      <button class="btn btn-danger" onclick="cancelInvoice()">Cancel</button>
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
<?php if ($inv['status'] !== 'Cancelled'): ?>
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
  <?php if ($linkedRequest && $linkedRequest['practice_code']): ?>
  <div style="margin-top:20px;padding-top:16px;border-top:1px solid var(--grey-lt);">
    <div style="font-size:.8rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--grey-dk);margin-bottom:12px;">Dropbox Folder Status</div>
    <div style="font-size:.83rem;color:var(--grey-dk);margin-bottom:12px;">
      Current folder: <code id="folderName" style="background:var(--off-white);padding:2px 6px;border-radius:4px;font-size:.8rem"><?= h($linkedRequest['practice_code']) ?></code>
      &nbsp;<span id="folderTagBadge" style="background:var(--amber-lt);color:var(--amber);padding:2px 8px;border-radius:12px;font-size:.7rem;font-weight:700;display:<?= $currentTag ? 'inline' : 'none' ?>"><?= h($currentTag ?: '') ?></span>
    </div>
    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
      <label style="font-size:.7rem;font-weight:700;color:var(--grey-dk);white-space:nowrap">Update status to:</label>
      <select id="folderStatusSelect" style="padding:7px 10px;border:1.5px solid var(--grey-lt);border-radius:6px;font-family:inherit;font-size:.83rem">
        <option value="">— Select new status —</option>
        <?php foreach (FOLDER_TAG_OPTIONS as $label => $tag): ?>
          <option value="<?= h($label) ?>" <?= $currentTag === $tag ? 'selected' : '' ?>><?= h($label) ?></option>
        <?php endforeach; ?>
      </select>
      <button class="btn btn-outline" onclick="updateFolderStatus()" type="button">Update Folder</button>
      <span id="folderMsg" style="font-size:.8rem;display:none"></span>
    </div>
  </div>
  <?php endif; ?>
</div>

<script>
async function updateFolderStatus() {
  var sel = document.getElementById('folderStatusSelect');
  var newLabel = sel.value;
  if (!newLabel) { alert('Please select a status.'); return; }
  var msg = document.getElementById('folderMsg');
  msg.style.display = 'inline'; msg.style.color = 'var(--grey-mid)'; msg.textContent = 'Updating…';
  var fd = new FormData();
  fd.append('action','update_folder');
  fd.append('invoice_id','<?= $id ?>');
  fd.append('folder_status', newLabel);
  try {
    var r = await fetch('invoice_view.php?id=<?= $id ?>', {method:'POST', body:fd});
    var d = await r.json();
    if (d.ok) {
      document.getElementById('folderName').textContent = d.new_name;
      var badge = document.getElementById('folderTagBadge');
      badge.textContent = d.new_tag; badge.style.display = 'inline';
      msg.style.color = 'var(--green)'; msg.textContent = '✓ Folder renamed successfully';
      setTimeout(function(){ msg.style.display='none'; }, 3000);
    } else {
      msg.style.color = 'var(--red)'; msg.textContent = '✗ ' + d.error;
    }
  } catch(e) {
    msg.style.color = 'var(--red)'; msg.textContent = '✗ ' + e.message;
  }
}
</script>
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
async function setStatus(newStatus) {
  var messages = {
    'New':       'Reset this invoice to New?\n\nIf there are recorded payments, the status will be corrected automatically.',
    'Cancelled': 'Cancel this invoice?\n\nExisting payments will not be affected, but no new payments can be added.'
  };
  var msg = messages[newStatus] || ('Change status to "' + newStatus + '"?');
  var ok = await seConfirm('Change Status', msg);
  if (!ok) {
    document.getElementById('statusSelect').value = '<?= h($inv['status']) ?>';
    return;
  }
  var fd = new FormData();
  fd.append('action','set_status');
  fd.append('invoice_id','<?= $id ?>');
  fd.append('new_status', newStatus);
  var r = await fetch('', {method:'POST', body:fd});
  var d = await r.json();
  if (!d.ok) {
    alert(d.error);
    document.getElementById('statusSelect').value = '<?= h($inv['status']) ?>';
    return;
  }
  if (d.warning) alert('⚠️ ' + d.warning);
  location.reload();
}

async function restoreInvoice() {
  var ok = await seConfirm('Restore Invoice', 'Restore invoice <?= h($inv['invoice_number']) ?> to New status?');
  if (!ok) return;
  var fd = new FormData();
  fd.append('action','restore_invoice'); fd.append('invoice_id','<?= $id ?>');
  var r = await fetch('', {method:'POST', body:fd});
  var d = await r.json();
  if (d.warning) alert('⚠️ ' + d.warning);
  location.reload();
}

async function cancelInvoice() {
  var ok = await seConfirm('Cancel Invoice', 'Cancel invoice <?= h($inv['invoice_number']) ?>?\n\nThis will mark it as Cancelled. Existing payments are not affected.');
  if (!ok) return;
  var fd = new FormData();
  fd.append('action','cancel_invoice'); fd.append('invoice_id','<?= $id ?>');
  fetch('invoice_view.php', {method:'POST',body:fd}).then(function(r){return r.json();}).then(function(d){ if(d.ok) location.reload(); });
}
</script>

<?php
// ── Prepare mail modal defaults ───────────────────────────────────────────────
$mailTo      = ($linkedRequest['email'] ?? '') ?: '';
$mailSubject = h($inv['bill_to_name']) . ' trip with Savannah Explorers';
$mailBody    = "Greetings from Savannah Explorers.\r\nKindly find your attached receipt and note that your money was successfully received into our bank account,\r\nWe wish you all the best for your upcoming safari/trip,\r\nRegards with thanks,\r\nEsther\r\n--\r\nSavannah Explorers Ltd\r\nEngosheraton - P.O. Box 16726\r\nArusha - Tanzania\r\nOffice +255 768 900 199\r\nEmergency Mobile Tanzania: +255 768 900 199 and +255 747 777 315\r\nEmail: accountant@savannahexplorers.com\r\nWebsite: savannahexplorers.net";
?>

<!-- ── MAIL MODAL ─────────────────────────────────────────────────────────── -->
<div id="mailModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9998;align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:12px;padding:28px 32px;max-width:640px;width:94%;box-shadow:0 10px 40px rgba(0,0,0,.25);font-family:'Open Sans',sans-serif;max-height:92vh;overflow-y:auto;">

    <div style="font-family:'Merriweather',serif;font-size:1.05rem;font-weight:700;color:#A01A14;margin-bottom:20px;">
      ✉ Send Invoice by Email
    </div>

    <div style="display:flex;flex-direction:column;gap:14px;">

      <div>
        <label style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:#888;display:block;margin-bottom:4px;">To</label>
        <input id="mailTo" type="email" value="<?= h($mailTo) ?>"
               style="width:100%;padding:9px 12px;border:1.5px solid #E8E8E8;border-radius:7px;font-family:inherit;font-size:.85rem;outline:none;"
               onfocus="this.style.borderColor='#C0211B'" onblur="this.style.borderColor='#E8E8E8'">
      </div>

      <div>
        <label style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:#888;display:block;margin-bottom:4px;">CC <span style="font-weight:400;font-style:italic;">(optional)</span></label>
        <input id="mailCc" type="text" placeholder="e.g. agent@example.com"
               value="accountant@savannahexplorers.com"
               style="width:100%;padding:9px 12px;border:1.5px solid #E8E8E8;border-radius:7px;font-family:inherit;font-size:.85rem;outline:none;"
               onfocus="this.style.borderColor='#C0211B'" onblur="this.style.borderColor='#E8E8E8'">
      </div>

      <div>
        <label style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:#888;display:block;margin-bottom:4px;">Subject</label>
        <input id="mailSubject" type="text" value="<?= h($mailSubject) ?>"
               style="width:100%;padding:9px 12px;border:1.5px solid #E8E8E8;border-radius:7px;font-family:inherit;font-size:.85rem;outline:none;"
               onfocus="this.style.borderColor='#C0211B'" onblur="this.style.borderColor='#E8E8E8'">
      </div>

      <div>
        <label style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:#888;display:block;margin-bottom:4px;">Body</label>
        <textarea id="mailBody" rows="12"
                  style="width:100%;padding:9px 12px;border:1.5px solid #E8E8E8;border-radius:7px;font-family:inherit;font-size:.82rem;line-height:1.6;resize:vertical;outline:none;"
                  onfocus="this.style.borderColor='#C0211B'" onblur="this.style.borderColor='#E8E8E8'"><?= h($mailBody) ?></textarea>
      </div>

      <div style="font-size:.75rem;color:#888;padding:8px 12px;background:#F7F5F2;border-radius:6px;">
        📎 Invoice <strong><?= h($inv['invoice_number']) ?></strong> will be attached as a PDF.
      </div>

      <div id="mailStatus" style="display:none;font-size:.83rem;padding:9px 14px;border-radius:7px;"></div>

    </div>

    <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:22px;border-top:1px solid #E8E8E8;padding-top:18px;">
      <button type="button" onclick="closeMailModal()"
              style="padding:9px 22px;border-radius:7px;border:1.5px solid #E8E8E8;background:#fff;font-family:inherit;font-size:.83rem;font-weight:600;cursor:pointer;">
        Cancel
      </button>
      <button type="button" id="mailSendBtn" onclick="sendInvoiceMail()"
              style="padding:9px 22px;border-radius:7px;border:none;background:#C0211B;color:#fff;font-family:inherit;font-size:.83rem;font-weight:700;cursor:pointer;">
        Send Email
      </button>
    </div>

  </div>
</div>

<script>
function openMailModal() {
  document.getElementById('mailModal').style.display = 'flex';
  document.getElementById('mailStatus').style.display = 'none';
  document.getElementById('mailSendBtn').disabled = false;
  document.getElementById('mailSendBtn').textContent = 'Send Email';
}
function closeMailModal() {
  document.getElementById('mailModal').style.display = 'none';
}
document.getElementById('mailModal').addEventListener('click', function(e) {
  if (e.target === this) closeMailModal();
});

async function sendInvoiceMail() {
  var to      = document.getElementById('mailTo').value.trim();
  var cc      = document.getElementById('mailCc').value.trim();
  var subject = document.getElementById('mailSubject').value.trim();
  var body    = document.getElementById('mailBody').value.trim();
  var status  = document.getElementById('mailStatus');
  var btn     = document.getElementById('mailSendBtn');

  if (!to)      { showMailStatus('Please enter a To address.', false); return; }
  if (!subject) { showMailStatus('Please enter a Subject.', false); return; }
  if (!body)    { showMailStatus('Please enter the email body.', false); return; }

  btn.disabled = true;
  btn.textContent = 'Sending…';
  showMailStatus('Generating PDF and sending…', null);

  var fd = new FormData();
  fd.append('invoice_id', '<?= $id ?>');
  fd.append('to',      to);
  fd.append('cc',      cc);
  fd.append('subject', subject);
  fd.append('body',    body);

  try {
    var r = await fetch('api_send_invoice_email.php', { method: 'POST', body: fd });
    var d = await r.json();
    if (d.ok) {
      closeMailModal();
    } else {
      showMailStatus('✗ ' + (d.error || 'Unknown error'), false);
      btn.disabled = false;
      btn.textContent = 'Send Email';
    }
  } catch(e) {
    showMailStatus('✗ Network error: ' + e.message, false);
    btn.disabled = false;
    btn.textContent = 'Send Email';
  }
}

function showMailStatus(msg, ok) {
  var el = document.getElementById('mailStatus');
  el.textContent = msg;
  el.style.display = 'block';
  if (ok === true)  { el.style.background = '#D6EDD9'; el.style.color = '#1A6B3A'; }
  else if (ok === false) { el.style.background = '#FAE8E7'; el.style.color = '#A01A14'; }
  else              { el.style.background = '#F7F5F2'; el.style.color = '#888'; }
}
</script>

<?php include 'includes/footer.php'; ?>
