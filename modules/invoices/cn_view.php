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
            // Cancel any outstanding allocations first (frees target invoices).
            $outstanding = $db->prepare("SELECT id FROM credit_note_allocations WHERE credit_note_id=? AND cancelled_at IS NULL");
            $outstanding->execute([$cnId]);
            foreach ($outstanding->fetchAll(PDO::FETCH_COLUMN) as $aId) {
                cn_cancel_allocation($db, (int)$aId);
            }
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

    if ($action === 'search_invoices') {
        // Find candidate target invoices to apply the credit to.
        // Excludes the CN's own source invoice and cancelled invoices.
        $q   = trim($_POST['q'] ?? '');
        $cnRow = $db->prepare("SELECT invoice_id FROM credit_notes WHERE id=?");
        $cnRow->execute([$cnId]);
        $srcInvId = (int)$cnRow->fetchColumn();
        $like = "%$q%";
        $st = $db->prepare(
            "SELECT id, invoice_number, bill_to_name, currency, total, balance_due, status
             FROM invoices
             WHERE status != 'Cancelled' AND id <> ?
               AND (invoice_number LIKE ? OR bill_to_name LIKE ?)
             ORDER BY issue_date DESC, id DESC
             LIMIT 15"
        );
        $st->execute([$srcInvId, $like, $like]);
        echo json_encode(['ok'=>true, 'results'=>$st->fetchAll()]); exit;
    }

    if ($action === 'apply_credit') {
        try {
            $targetId = (int)($_POST['target_invoice_id'] ?? 0);
            $amountCn = (float)($_POST['amount_cn'] ?? 0);
            $fxRate   = (float)($_POST['fx_rate'] ?? 1);
            $allocDt  = $_POST['alloc_date'] ?? date('Y-m-d');
            $note     = trim($_POST['note'] ?? '');
            $uid      = (int)(current_user()['id'] ?? 0);
            $allocId  = cn_apply_to_invoice($db, $cnId, $targetId, $amountCn, $fxRate, $allocDt, $note ?: null, $uid);
            echo json_encode(['ok'=>true, 'alloc_id'=>$allocId]); exit;
        } catch (\Throwable $e) { echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); exit; }
    }

    if ($action === 'cancel_allocation') {
        try {
            $allocId = (int)($_POST['alloc_id'] ?? 0);
            cn_cancel_allocation($db, $allocId);
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

// Allocations (credit used to pay other invoices)
$allocs = $db->prepare(
    "SELECT a.*, i.invoice_number, i.currency AS invoice_currency, i.bill_to_name
     FROM credit_note_allocations a
     LEFT JOIN invoices i ON i.id = a.invoice_id
     WHERE a.credit_note_id=?
     ORDER BY a.cancelled_at IS NOT NULL, a.alloc_date, a.id"
);
$allocs->execute([$id]); $allocs = $allocs->fetchAll();

$remainingCredit = cn_remaining_credit($db, $id);

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
  A credit of <strong><?= fmt_money((float)$cn['total'], $cn['currency']) ?></strong>
  has been applied to invoice <strong><?= h($cn['invoice_number']) ?></strong>.
  Any remaining credit can be used to pay other invoices below.
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

<!-- ── CREDIT USAGE ───────────────────────────────────────────────────── -->
<?php if ($cn['status'] !== 'Cancelled'): ?>
<div class="section-label">Credit Usage</div>

<div style="display:flex;justify-content:flex-end;max-width:860px;margin-bottom:16px;">
  <div class="totals-box">
    <div class="totals-row"><span>Total Credit</span><span><?= fmt_money((float)$cn['total'], $cn['currency']) ?></span></div>
    <div class="totals-row"><span>Used</span><span><?= fmt_money((float)$cn['total'] - $remainingCredit, $cn['currency']) ?></span></div>
    <div class="totals-row total">
      <span>Remaining Credit</span>
      <span style="<?= $remainingCredit <= 0.001 ? 'color:var(--grey-mid)' : 'color:var(--green)' ?>">
        <?= fmt_money($remainingCredit, $cn['currency']) ?>
      </span>
    </div>
  </div>
</div>

<!-- Allocations list -->
<div class="table-wrap" style="max-width:860px;margin-bottom:16px">
  <table>
    <thead>
      <tr>
        <th>Date</th>
        <th>Applied to invoice</th>
        <th class="text-right">From credit (<?= h($cn['currency']) ?>)</th>
        <th class="text-right">Rate</th>
        <th class="text-right">Paid on invoice</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php if ($allocs): ?>
        <?php foreach ($allocs as $a): ?>
        <tr class="<?= $a['cancelled_at'] ? 'payment-cancelled' : '' ?>" style="<?= $a['cancelled_at'] ? 'opacity:.5' : '' ?>">
          <td style="white-space:nowrap"><?= date('d M Y', strtotime($a['alloc_date'])) ?></td>
          <td><a href="invoice_view.php?id=<?= (int)$a['invoice_id'] ?>" style="color:var(--blue)"><?= h($a['invoice_number']) ?></a>
              <span style="font-size:.75rem;color:var(--grey-mid)"><?= h($a['bill_to_name']) ?></span></td>
          <td class="text-right" style="font-weight:600"><?= fmt_money((float)$a['amount_cn'], $cn['currency']) ?></td>
          <td class="text-right"><?= rtrim(rtrim(number_format((float)$a['fx_rate'], 6, '.', ''), '0'), '.') ?></td>
          <td class="text-right"><?= fmt_money((float)$a['amount_invoice'], $a['invoice_currency']) ?></td>
          <td>
            <?php if (!$a['cancelled_at']): ?>
              <button class="btn btn-danger btn-sm" onclick="cancelAlloc(<?= (int)$a['id'] ?>)">Cancel</button>
            <?php else: ?>
              <span style="font-size:.75rem;color:var(--red)">Cancelled</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      <?php else: ?>
        <tr><td colspan="6" style="text-align:center;padding:20px;color:var(--grey-mid);font-size:.83rem">No credit used yet.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<!-- Apply credit form -->
<?php if ($remainingCredit > 0.001): ?>
<div class="table-wrap" style="max-width:860px;padding:20px 24px;">
  <div style="font-size:.8rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--grey-dk);margin-bottom:16px;">Apply Credit to an Invoice</div>

  <!-- Invoice picker -->
  <div style="position:relative;margin-bottom:14px;max-width:480px;">
    <label style="font-size:.7rem;font-weight:700;color:var(--grey-dk);display:block;margin-bottom:4px;">Target invoice</label>
    <input type="text" id="invSearch" autocomplete="off" placeholder="Search invoice number or name…"
           style="width:100%;padding:8px 12px;border:1.5px solid var(--grey-lt);border-radius:7px;font-family:inherit;font-size:.85rem;">
    <div id="invDrop" style="display:none;position:absolute;z-index:100;background:#fff;border:1.5px solid var(--grey-lt);border-radius:7px;box-shadow:0 4px 16px rgba(0,0,0,.12);max-height:240px;overflow-y:auto;width:100%;top:100%;left:0;"></div>
    <input type="hidden" id="targetInvoiceId" value="">
    <input type="hidden" id="targetCurrency" value="">
  </div>

  <div id="selectedInvBox" style="display:none;font-size:.82rem;color:var(--grey-dk);background:var(--off-white);padding:10px 14px;border-radius:6px;margin-bottom:14px;"></div>

  <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;">
    <div class="form-group" style="gap:4px">
      <label style="font-size:.7rem">Date</label>
      <input type="date" id="allocDate" value="<?= date('Y-m-d') ?>" style="padding:7px 10px;border:1.5px solid var(--grey-lt);border-radius:6px;font-family:inherit;font-size:.83rem">
    </div>
    <div class="form-group" style="gap:4px">
      <label style="font-size:.7rem">From credit (<?= h($cn['currency']) ?>)</label>
      <input type="number" id="amountCn" step="0.01" min="0.01" max="<?= number_format($remainingCredit,2,'.','') ?>"
             placeholder="<?= number_format($remainingCredit,2) ?>"
             style="padding:7px 10px;border:1.5px solid var(--grey-lt);border-radius:6px;font-family:inherit;font-size:.83rem;width:130px" oninput="calcApplied()">
    </div>
    <div class="form-group" style="gap:4px">
      <label style="font-size:.7rem">FX rate (<?= h($cn['currency']) ?>→<span id="fxTargetCur">?</span>)</label>
      <input type="number" id="fxRate" step="0.000001" min="0.000001" value="1"
             style="padding:7px 10px;border:1.5px solid var(--grey-lt);border-radius:6px;font-family:inherit;font-size:.83rem;width:110px" oninput="calcApplied()">
    </div>
    <div class="form-group" style="gap:4px">
      <label style="font-size:.7rem">Will pay (floored)</label>
      <input type="text" id="appliedPreview" readonly style="padding:7px 10px;border:1.5px solid var(--grey-lt);border-radius:6px;font-family:inherit;font-size:.83rem;width:120px;background:var(--off-white);color:var(--grey-dk)">
    </div>
    <div class="form-group" style="gap:4px;flex:1;min-width:160px">
      <label style="font-size:.7rem">Note (optional)</label>
      <input type="text" id="allocNote" style="padding:7px 10px;border:1.5px solid var(--grey-lt);border-radius:6px;font-family:inherit;font-size:.83rem;width:100%">
    </div>
    <button class="btn btn-green" type="button" onclick="applyCredit()">Apply Credit</button>
  </div>
  <div style="font-size:.75rem;color:var(--grey-mid);margin-top:10px;">
    The credit is deducted in <?= h($cn['currency']) ?>; the invoice receives <code>floor(amount × rate)</code> in its own currency, recorded as a payment.
  </div>
  <span id="applyMsg" style="font-size:.8rem;display:none;margin-top:8px;"></span>
</div>
<?php endif; ?>
<?php endif; // not cancelled ?>

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

// ── Credit usage: invoice picker ──────────────────────────────────────────
var CN_CURRENCY = <?= json_encode($cn['currency']) ?>;
var invSearch = document.getElementById('invSearch');
var invDrop   = document.getElementById('invDrop');
var searchTimer = null;

if (invSearch) {
  invSearch.addEventListener('input', function() {
    clearTimeout(searchTimer);
    var q = this.value.trim();
    if (q.length < 1) { invDrop.style.display='none'; return; }
    searchTimer = setTimeout(function(){ doInvSearch(q); }, 250);
  });
  document.addEventListener('click', function(e){
    if (!invDrop.contains(e.target) && e.target !== invSearch) invDrop.style.display='none';
  });
}

async function doInvSearch(q) {
  var fd = new FormData();
  fd.append('action','search_invoices'); fd.append('cn_id','<?= $id ?>'); fd.append('q', q);
  var r = await fetch('', {method:'POST', body:fd});
  var d = await r.json();
  if (!d.ok || !d.results.length) { invDrop.innerHTML=''; invDrop.style.display='none'; return; }
  invDrop.innerHTML = d.results.map(function(inv){
    var sym = inv.currency === 'EUR' ? '€' : '$';
    return '<div class="inv-pick" data-id="'+inv.id+'" data-num="'+escAttr(inv.invoice_number)+'"'
         + ' data-cur="'+inv.currency+'" data-name="'+escAttr(inv.bill_to_name)+'"'
         + ' data-bal="'+inv.balance_due+'" data-total="'+inv.total+'"'
         + ' style="padding:9px 14px;cursor:pointer;border-bottom:1px solid var(--grey-lt);font-size:.83rem;">'
         + '<strong>'+escHtml(inv.invoice_number)+'</strong> · '+escHtml(inv.bill_to_name)
         + '<div style="font-size:.74rem;color:var(--grey-mid)">'+inv.currency+' · total '+sym+(+inv.total).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2})
         + ' · balance '+sym+(+inv.balance_due).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2})+'</div>'
         + '</div>';
  }).join('');
  invDrop.style.display='block';
}

if (invDrop) {
  invDrop.addEventListener('mousedown', function(e){
    var item = e.target.closest('.inv-pick');
    if (!item) return;
    e.preventDefault();
    document.getElementById('targetInvoiceId').value = item.dataset.id;
    document.getElementById('targetCurrency').value  = item.dataset.cur;
    invSearch.value = item.dataset.num + ' — ' + item.dataset.name;
    invDrop.style.display='none';
    document.getElementById('fxTargetCur').textContent = item.dataset.cur;
    // If currencies match, lock rate to 1; otherwise leave for manual entry.
    var fx = document.getElementById('fxRate');
    if (item.dataset.cur === CN_CURRENCY) { fx.value = 1; }
    var sym = item.dataset.cur === 'EUR' ? '€' : '$';
    var box = document.getElementById('selectedInvBox');
    box.style.display = 'block';
    box.innerHTML = 'Applying to <strong>'+escHtml(item.dataset.num)+'</strong> ('+item.dataset.cur+'). '
                  + 'Current balance due: '+sym+(+item.dataset.bal).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2})+'.';
    calcApplied();
  });
}

function calcApplied() {
  var amt = parseFloat(document.getElementById('amountCn').value) || 0;
  var fx  = parseFloat(document.getElementById('fxRate').value) || 0;
  var cur = document.getElementById('targetCurrency').value || CN_CURRENCY;
  var sym = cur === 'EUR' ? '€' : '$';
  var applied = Math.floor(amt * fx);
  document.getElementById('appliedPreview').value = sym + applied.toLocaleString('en-US');
}

async function applyCredit() {
  var targetId = document.getElementById('targetInvoiceId').value;
  var amountCn = parseFloat(document.getElementById('amountCn').value) || 0;
  var fxRate   = parseFloat(document.getElementById('fxRate').value) || 0;
  var allocDt  = document.getElementById('allocDate').value;
  var note     = document.getElementById('allocNote').value.trim();
  var msg      = document.getElementById('applyMsg');

  if (!targetId)        { showApplyMsg('Select a target invoice.', false); return; }
  if (amountCn <= 0)    { showApplyMsg('Enter an amount to deduct from the credit.', false); return; }
  if (fxRate <= 0)      { showApplyMsg('Enter a valid FX rate.', false); return; }

  var fd = new FormData();
  fd.append('action','apply_credit');
  fd.append('cn_id','<?= $id ?>');
  fd.append('target_invoice_id', targetId);
  fd.append('amount_cn', amountCn);
  fd.append('fx_rate', fxRate);
  fd.append('alloc_date', allocDt);
  fd.append('note', note);
  showApplyMsg('Applying…', null);
  var r = await fetch('', {method:'POST', body:fd});
  var d = await r.json();
  if (d.ok) location.reload(); else showApplyMsg(d.error || 'Error', false);
}

function showApplyMsg(txt, ok) {
  var el = document.getElementById('applyMsg');
  el.style.display = 'inline'; el.textContent = txt;
  el.style.color = ok === false ? 'var(--red)' : (ok === null ? 'var(--grey-mid)' : 'var(--green)');
}

async function cancelAlloc(allocId) {
  if (!confirm('Cancel this credit usage?\n\nThe payment will be removed from the target invoice and the credit freed.')) return;
  var fd = new FormData();
  fd.append('action','cancel_allocation'); fd.append('cn_id','<?= $id ?>'); fd.append('alloc_id', allocId);
  var r = await fetch('', {method:'POST', body:fd});
  var d = await r.json();
  if (d.ok) location.reload(); else alert(d.error || 'Error');
}

function escHtml(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function escAttr(s){ return String(s).replace(/&/g,'&amp;').replace(/"/g,'&quot;'); }
</script>

<?php include 'includes/footer.php'; ?>
