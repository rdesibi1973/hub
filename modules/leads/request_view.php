<?php
require_once 'config.php';
requireLogin();   // ← must run before any access checks

$id  = (int)($_GET['id'] ?? 0);
$db  = db();

// ── Inline status update ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['quick_status'])) {
    $newStatus    = trim($_POST['quick_status']);
    $isXhr        = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';
    $staffAgentId = isLeadsRestricted() ? getStaffAgentId() : 0;

    if (!array_key_exists($newStatus, STATUSES)) {
        if ($isXhr) { header('Content-Type: application/json');
                      echo json_encode(['ok'=>false,'message'=>'Invalid status']); exit; }
        flash('Invalid status.', 'error');
    } else {
        if (isLeadsRestricted()) {
            $db->prepare("UPDATE requests SET status=?, pipeline_column=IF(?='Booked',NULL,pipeline_column) WHERE id=? AND agent_id=?")
               ->execute([$newStatus, $newStatus, $id, $staffAgentId]);
        } else {
            $db->prepare("UPDATE requests SET status=?, pipeline_column=IF(?='Booked',NULL,pipeline_column) WHERE id=?")
               ->execute([$newStatus, $newStatus, $id]);
        }
        if ($isXhr) { header('Content-Type: application/json');
                      echo json_encode(['ok'=>true]); exit; }
        flash('Status updated to ' . $newStatus . '.');
    }
    if (!$isXhr) { header('Location: request_view.php?id=' . $id); exit; }
}
$stmt = db()->prepare("
    SELECT r.*, a.name AS agent_name
    FROM requests r
    LEFT JOIN agents a ON a.id = r.agent_id
    WHERE r.id = ?
");
$stmt->execute([$id]);
$r = $stmt->fetch();
if (!$r) { flash('Request not found.', 'error'); header('Location: requests.php'); exit; }

$cu = current_user();

// Fetch email from DB (not in session)
$cuEmail = '';
if (!empty($cu['id'])) {
    $cuEmail = (string)($db->prepare("SELECT email FROM users WHERE id = ? LIMIT 1")
                          ->execute([(int)$cu['id']])
                       ?: null);
    $stmt2 = $db->prepare("SELECT email FROM users WHERE id = ? LIMIT 1");
    $stmt2->execute([(int)$cu['id']]);
    $cuEmail = (string)($stmt2->fetchColumn() ?: '');
}

// ── Notes POST ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_note'])) {
    $action = $_POST['action_note'];
    $isAdmin = in_array($cu['role_name'] ?? '', ['admin','manager']);

    if ($action === 'add') {
        $note = trim($_POST['note_text'] ?? '');
        if ($note !== '') {
            $db->prepare("INSERT INTO request_notes (request_id, user_id, note) VALUES (?,?,?)")
               ->execute([$id, (int)$cu['id'], $note]);
        }
    } elseif ($action === 'edit') {
        $nid  = (int)($_POST['note_id']   ?? 0);
        $note = trim($_POST['note_text'] ?? '');
        if ($nid && $note !== '') {
            // Only author or admin/manager can edit
            $clause = $isAdmin ? "id=? AND request_id=?" : "id=? AND request_id=? AND user_id=?";
            $params = $isAdmin ? [$note, $nid, $id] : [$note, $nid, $id, (int)$cu['id']];
            $db->prepare("UPDATE request_notes SET note=? WHERE $clause")->execute($params);
        }
    } elseif ($action === 'delete') {
        $nid = (int)($_POST['note_id'] ?? 0);
        if ($nid) {
            $clause = $isAdmin ? "id=? AND request_id=?" : "id=? AND request_id=? AND user_id=?";
            $params = $isAdmin ? [$nid, $id] : [$nid, $id, (int)$cu['id']];
            $db->prepare("DELETE FROM request_notes WHERE $clause")->execute($params);
        }
    }
    header("Location: request_view.php?id=$id#notes"); exit;
}

// ── To-Do POST ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_todo'])) {
    $action = $_POST['action_todo'];
    if ($action === 'add') {
        $title    = trim($_POST['todo_title']   ?? '');
        $due      = trim($_POST['todo_due']     ?? '');
        $email_to = trim($_POST['todo_email']   ?? '');
        if ($title && $due) {
            $db->prepare(
                "INSERT INTO request_todos (request_id, user_id, title, due_at, email_to) VALUES (?,?,?,?,?)"
            )->execute([$id, (int)$cu['id'], $title, $due, $email_to ?: null]);
        }
    } elseif ($action === 'edit') {
        $tid      = (int)($_POST['todo_id']    ?? 0);
        $title    = trim($_POST['todo_title']  ?? '');
        $due      = trim($_POST['todo_due']    ?? '');
        $email_to = trim($_POST['todo_email']  ?? '');
        if ($tid && $title && $due) {
            $db->prepare(
                "UPDATE request_todos SET title=?, due_at=?, email_to=?, reminder_sent=0 WHERE id=? AND request_id=?"
            )->execute([$title, $due, $email_to ?: null, $tid, $id]);
        }
    } elseif ($action === 'done') {
        $tid = (int)($_POST['todo_id'] ?? 0);
        if ($tid) $db->prepare("UPDATE request_todos SET done=1-done WHERE id=? AND request_id=?")
                     ->execute([$tid, $id]);
    } elseif ($action === 'delete') {
        $tid = (int)($_POST['todo_id'] ?? 0);
        if ($tid) $db->prepare("DELETE FROM request_todos WHERE id=? AND request_id=?")
                     ->execute([$tid, $id]);
    }
    header("Location: request_view.php?id=$id#todos"); exit;
}

// ── Load notes & todos ──────────────────────────────────────────────────────
$notes = $db->prepare(
    "SELECT n.*, u.full_name AS author FROM request_notes n
     LEFT JOIN users u ON u.id = n.user_id
     WHERE n.request_id = ? ORDER BY n.created_at ASC"
);
$notes->execute([$id]);
$notes = $notes->fetchAll();

$todos = $db->prepare(
    "SELECT t.*, u.full_name AS author FROM request_todos t
     LEFT JOIN users u ON u.id = t.user_id
     WHERE t.request_id = ? ORDER BY t.done ASC, t.due_at ASC"
);
$todos->execute([$id]);
$todos = $todos->fetchAll();



// Check for existing invoices on this request
$invStmt = db()->prepare("SELECT id, invoice_number, status FROM invoices WHERE request_id = ? ORDER BY id LIMIT 1");
$invStmt->execute([$id]);
$existingInv  = $invStmt->fetch();
$invCountStmt = db()->prepare("SELECT COUNT(*) FROM invoices WHERE request_id = ?");
$invCountStmt->execute([$id]);
$invCount = (int)$invCountStmt->fetchColumn();

// Load quotes for this request
$qStmt = db()->prepare("SELECT id, quote_number, customer_name, total_price, status, created_at FROM quotes WHERE request_id = ? ORDER BY id DESC");
$qStmt->execute([$id]);
$linkedQuotes = $qStmt->fetchAll();

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
    <?php if (!isLeadsRestricted()): ?>
      <?php if ($invCount === 0): ?>
        <a href="../invoices/invoice_add.php?request_id=<?= $r['id'] ?>" class="btn btn-outline">🧾 Create Invoice</a>
      <?php elseif ($invCount === 1): ?>
        <a href="../invoices/invoice_view.php?id=<?= $existingInv['id'] ?>" class="btn btn-outline">🧾 <?= h($existingInv['invoice_number']) ?></a>
      <?php else: ?>
        <a href="../invoices/invoices.php?request_id=<?= $r['id'] ?>" class="btn btn-outline">🧾 <?= $invCount ?> Invoices</a>
      <?php endif; ?>
    <?php endif; ?>
    <?php if ($r['dropbox_url']): ?>
      <a href="<?= h($r['dropbox_url']) ?>" target="_blank" class="btn btn-outline">
        📁 Open Dropbox Folder
      </a>
    <?php endif; ?>
    <?php
      // Show Edit button if: admin/manager/accountant, OR staff viewing their own request
      $canEdit = !isLeadsRestricted()
              || in_array(current_user()['role_name'], ['accountant'], true)
              || ((int)$r['agent_id'] === getStaffAgentId());
    ?>
    <?php if ($canEdit): ?>
      <a href="request_edit.php?id=<?= $r['id'] ?>" class="btn btn-red">Edit</a>
    <?php endif; ?>
    <?php if (!isLeadsRestricted()): ?>
      <button type="button"
              onclick="deleteRequest(<?= $r['id'] ?>, <?= htmlspecialchars(json_encode($r['customer_name'])) ?>, <?= htmlspecialchars(json_encode($r['practice_code'] ?? '')) ?>, <?= htmlspecialchars(json_encode($r['status'] ?? '')) ?>, 'requests.php', <?= htmlspecialchars(json_encode($r['group_folder'] ?? '')) ?>)"
              class="btn btn-outline"
              style="color:#C0211B;border-color:#C0211B;">🗑 Delete</button>
    <?php endif; ?>
    <?php
      $quoteCount = count($linkedQuotes);
      if ($quoteCount === 0): ?>
        <a href="quote_new.php?request_id=<?= $r['id'] ?>" class="btn btn-outline">📋 New Quote</a>
      <?php elseif ($quoteCount === 1): ?>
        <a href="quote_view.php?id=<?= $linkedQuotes[0]['id'] ?>" class="btn btn-outline">📋 <?= h($linkedQuotes[0]['quote_number']) ?></a>
        <a href="quote_new.php?edit=<?= $linkedQuotes[0]['id'] ?>&request_id=<?= $r['id'] ?>" class="btn btn-outline">✏️ Edit Quote</a>
        <a href="quote_new.php?request_id=<?= $r['id'] ?>" class="btn btn-outline">+ Quote</a>
      <?php else: ?>
        <a href="quotes.php" class="btn btn-outline">📋 <?= $quoteCount ?> Quotes</a>
        <a href="quote_new.php?request_id=<?= $r['id'] ?>" class="btn btn-outline">+ Quote</a>
      <?php endif; ?>
    <a href="wetu.php?client_name=<?= urlencode($r['customer_name']) ?>&ref_number=<?= urlencode($r['practice_code'] ?? '') ?>"
       class="btn btn-outline" style="color:var(--wetu,#1E4D7B);border-color:var(--wetu,#1E4D7B);">🗺️ Wetu</a>
    <a href="request_add.php" class="btn btn-outline">+ New Request</a>
  </div>
</div>


<!-- MAIN DETAILS -->
<div class="table-wrap" style="max-width:860px;margin-bottom:20px">
  <div class="detail-grid">

    <?php if (!empty($r['group_folder'])): ?>
    <div class="detail-label">GRP Folder</div>
    <div class="detail-value" style="font-size:.82rem;">
      <?= h($r['group_folder']) ?>
    </div>
    <?php endif; ?>

    <div class="detail-label">Dropbox Folder</div>
    <div class="detail-value">
      <?php if (!empty($r['group_folder']) && $r['practice_code']): ?>
        <span style="color:var(--grey-mid);font-size:.78rem;"><?= h($r['group_folder']) ?>/</span><?= h($r['practice_code']) ?>
      <?php else: ?>
        <?= $r['practice_code'] ? h($r['practice_code']) : '<span class="text-muted">—</span>' ?>
      <?php endif; ?>
    </div>

    <div class="detail-label">Date Received</div>
    <div class="detail-value"><?= date('d M Y', strtotime($r['date_received'])) ?></div>

    <div class="detail-label">Source</div>
    <div class="detail-value"><?= h($r['source'] ?? '—') ?></div>

    <div class="detail-label">Agent</div>
    <div class="detail-value"><?= h($r['agent_name'] ?? '—') ?></div>

    <div class="detail-label">Email</div>
    <div class="detail-value">
      <?= $r['email'] ? '<a href="mailto:'.h($r['email']).'">'.h($r['email']).'</a>' : '<span class="text-muted">—</span>' ?>
    </div>

    <div class="detail-label">WhatsApp</div>
    <div class="detail-value">
      <?php if ($r['whatsapp']):
        $wa_num = preg_replace('/\D/', '', $r['whatsapp']);
        $wa_url = 'https://wa.me/' . $wa_num;
      ?>
        <a href="<?= h($wa_url) ?>" target="_blank" title="Open in WhatsApp Web"
           style="display:inline-flex;align-items:center;gap:6px;text-decoration:none;color:var(--black);">
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="16" height="16" fill="#25D366">
            <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z"/>
            <path d="M12 0C5.373 0 0 5.373 0 12c0 2.117.554 4.103 1.523 5.824L.057 23.882a.5.5 0 0 0 .61.61l6.058-1.466A11.945 11.945 0 0 0 12 24c6.627 0 12-5.373 12-12S18.627 0 12 0zm0 21.9a9.877 9.877 0 0 1-5.031-1.378l-.36-.214-3.733.903.919-3.628-.235-.374A9.857 9.857 0 0 1 2.1 12C2.1 6.533 6.533 2.1 12 2.1S21.9 6.533 21.9 12 17.467 21.9 12 21.9z"/>
          </svg>
          <?= h($r['whatsapp']) ?>
        </a>
      <?php else: ?>
        <span class="text-muted">—</span>
      <?php endif; ?>
    </div>

    <div class="detail-label">Destination / Type</div>
    <div class="detail-value"><?= $r['destination'] ? h($r['destination']) : '<span class="text-muted">—</span>' ?></div>

    <div class="detail-label">Period</div>
    <div class="detail-value"><?= $r['period'] ? h($r['period']) : '<span class="text-muted">—</span>' ?></div>

    <div class="detail-label">Pax</div>
    <div class="detail-value"><?= $r['pax'] ?: '<span class="text-muted">—</span>' ?></div>

    <div class="detail-label">Status</div>
    <div class="detail-value">
      <?php if ($canEdit): ?>
        <form method="POST" style="display:inline-flex;align-items:center;gap:8px;">
          <select name="quick_status" onchange="this.form.submit()"
                  style="font-size:.82rem;padding:3px 6px;border:1px solid var(--grey-lt);border-radius:5px;cursor:pointer;">
            <?php foreach (STATUSES as $s => $_): ?>
              <option value="<?= h($s) ?>" <?= $r['status']===$s?'selected':'' ?>><?= h($s) ?></option>
            <?php endforeach; ?>
          </select>
          <noscript><button type="submit" class="btn btn-outline" style="font-size:.78rem;padding:3px 8px;">Update</button></noscript>
        </form>
      <?php else: ?>
        <span class="badge <?= STATUSES[$r['status']] ?? '' ?>"><?= h($r['status']) ?></span>
      <?php endif; ?>
    </div>

    <div class="detail-label">Dropbox Folder Link</div>
    <div class="detail-value">
      <?php
        // For GRP requests build the URL from group_folder + practice_code
        if (!empty($r['group_folder']) && $r['practice_code']) {
            $dbxUrl = 'https://www.dropbox.com/home/001_Safari/'
                    . rawurlencode($r['group_folder']) . '/' . rawurlencode($r['practice_code']);
        } else {
            $dbxUrl = $r['dropbox_url'] ?? '';
        }
      ?>
      <?php if ($dbxUrl): ?>
        <a href="<?= h($dbxUrl) ?>" target="_blank">📁 Open Dropbox Folder</a>
      <?php else: ?>
        <span class="text-muted">— not set yet</span>
      <?php endif; ?>
    </div>

  </div>
</div>

<!-- FINANCIALS -->
<?php if (!isLeadsRestricted()): ?>
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
<?php endif; ?>

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

<!-- QUOTES -->
<?php if (!empty($linkedQuotes)): ?>
<div class="section-label">Quotes</div>
<div class="table-wrap" style="max-width:860px;margin-bottom:20px">
  <table style="width:100%;border-collapse:collapse;font-size:.85rem;">
    <thead>
      <tr style="background:#f9fafb;">
        <th style="padding:8px 14px;text-align:left;font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#6b7280;">#</th>
        <th style="padding:8px 14px;text-align:left;font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#6b7280;">Customer</th>
        <th style="padding:8px 14px;text-align:right;font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#6b7280;">Total Price</th>
        <th style="padding:8px 14px;text-align:left;font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#6b7280;">Status</th>
        <th style="padding:8px 14px;text-align:left;font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#6b7280;">Date</th>
        <th style="padding:8px 14px;"></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($linkedQuotes as $lq): ?>
      <tr style="border-bottom:1px solid #f3f4f6;">
        <td style="padding:8px 14px;font-weight:700;color:var(--green);font-family:monospace;"><?= h($lq['quote_number']) ?></td>
        <td style="padding:8px 14px;font-weight:600;"><a href="quote_view.php?id=<?= $lq['id'] ?>" style="color:var(--black);text-decoration:none;"><?= h($lq['customer_name']) ?></a></td>
        <td style="padding:8px 14px;text-align:right;font-family:monospace;font-weight:700;">$<?= number_format((float)$lq['total_price'], 0, '.', ',') ?></td>
        <td style="padding:8px 14px;">
          <?php if ($lq['status'] === 'final'): ?>
            <span style="background:#fee2e2;color:#C0211B;border-radius:4px;padding:2px 8px;font-size:.72rem;font-weight:700;">Final</span>
          <?php else: ?>
            <span style="background:#fef3c7;color:#92400e;border-radius:4px;padding:2px 8px;font-size:.72rem;font-weight:700;">Draft</span>
          <?php endif; ?>
        </td>
        <td style="padding:8px 14px;color:var(--grey-mid);font-size:.8rem;"><?= date('d M Y', strtotime($lq['created_at'])) ?></td>
        <td style="padding:8px 14px;display:flex;gap:8px;">
          <a href="quote_view.php?id=<?= $lq['id'] ?>" class="btn btn-outline" style="font-size:.75rem;padding:4px 10px;">View</a>
          <a href="quote_new.php?request_id=<?= $r['id'] ?>&edit=<?= $lq['id'] ?>" class="btn btn-outline" style="font-size:.75rem;padding:4px 10px;">✏️ Edit</a>
          <button onclick="deleteQuote(<?= $lq['id'] ?>, '<?= h($lq['quote_number']) ?>')"
                  class="btn" style="font-size:.75rem;padding:4px 10px;background:#fff;color:#dc2626;border:1px solid #fca5a5;">Delete</button>
          <a href="api_export_quote.php?id=<?= $lq['id'] ?>" class="btn btn-outline" style="font-size:.75rem;padding:4px 10px;">⬇ Excel</a>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<!-- META -->
<div style="font-size:.7rem;color:var(--grey-mid);max-width:860px;margin-top:8px">
  Record #<?= $r['id'] ?> &nbsp;·&nbsp;
  Created <?= date('d M Y H:i', strtotime($r['created_at'])) ?> &nbsp;·&nbsp;
  Updated <?= date('d M Y H:i', strtotime($r['updated_at'])) ?>
</div>

<script>
function noteEdit(id) {
  document.getElementById('note-text-' + id).style.display = 'none';
  document.getElementById('note-edit-' + id).style.display = 'block';
  document.querySelector('#note-edit-' + id + ' textarea').focus();
}
function noteCancel(id) {
  document.getElementById('note-edit-' + id).style.display = 'none';
  document.getElementById('note-text-' + id).style.display = '';
}
</script>
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
<!-- ════════════════════════════════════════════════════════════════════════ -->
<!-- NOTES -->
<!-- ════════════════════════════════════════════════════════════════════════ -->
<style>
.notes-thread { max-width:860px; margin-bottom:24px; }
.note-item {
  display:flex; gap:12px; padding:12px 0;
  border-bottom:1px solid var(--grey-lt);
}
.note-item:last-child { border-bottom:none; }
.note-avatar {
  width:32px; height:32px; border-radius:50%;
  background:var(--blue-lt); color:var(--blue);
  display:flex; align-items:center; justify-content:center;
  font-weight:700; font-size:.75rem; flex-shrink:0;
}
.note-body { flex:1; min-width:0; }
.note-meta { font-size:.72rem; color:var(--grey-mid); margin-bottom:4px; }
.note-meta strong { color:var(--grey-dk); }
.note-text { font-size:.84rem; line-height:1.6; white-space:pre-wrap; color:var(--grey-dk); }
.note-add-form { display:flex; gap:8px; align-items:flex-end; max-width:860px; margin-top:12px; }
.note-add-form textarea {
  flex:1; padding:9px 12px; border:1.5px solid var(--grey-lt); border-radius:8px;
  font-size:.84rem; font-family:inherit; resize:vertical; min-height:60px;
  transition:border-color .15s;
}
.note-add-form textarea:focus { outline:none; border-color:var(--blue); }
.note-actions { margin-left:8px; }
.note-btn-edit, .note-btn-del {
  background:none; border:none; cursor:pointer; font-size:.8rem;
  opacity:0; transition:opacity .15s; padding:1px 3px;
}
.note-item:hover .note-btn-edit,
.note-item:hover .note-btn-del { opacity:1; }
.note-edit-form textarea {
  width:100%; padding:8px 10px; border:1.5px solid var(--blue); border-radius:6px;
  font-size:.84rem; font-family:inherit; resize:vertical; box-sizing:border-box;
}

/* To-Dos */
.todos-list { max-width:860px; margin-bottom:16px; }
.todo-item {
  display:flex; align-items:center; gap:10px;
  padding:10px 14px; border-radius:8px; margin-bottom:6px;
  border:1.5px solid var(--grey-lt); background:#fff;
  transition:opacity .15s;
}
.todo-item.done-item { opacity:.5; background:#fafafa; }
.todo-item.overdue { border-color:#fca5a5; background:#fff5f5; }
.todo-check { width:18px; height:18px; cursor:pointer; flex-shrink:0; accent-color:var(--green); }
.todo-title { flex:1; font-size:.84rem; font-weight:600; color:var(--grey-dk); }
.todo-item.done-item .todo-title { text-decoration:line-through; color:var(--grey-mid); }
.todo-due {
  font-size:.72rem; font-weight:700; padding:2px 8px;
  border-radius:4px; background:var(--blue-lt); color:var(--blue); white-space:nowrap;
}
.todo-item.overdue .todo-due   { background:#fee2e2; color:#C0211B; }
.todo-item.done-item .todo-due { background:var(--grey-lt); color:var(--grey-mid); }
.todo-email { font-size:.7rem; color:var(--grey-mid); }
.todo-del { background:none; border:none; cursor:pointer; color:#ddd; font-size:1rem; padding:2px 4px; }
.todo-del:hover { color:var(--red); }
.todo-add-form {
  display:flex; gap:8px; flex-wrap:wrap; align-items:flex-end;
  max-width:860px; margin-top:12px;
  padding:14px; background:#f9f9f9; border-radius:8px; border:1.5px dashed var(--grey-lt);
}
.todo-add-form input, .todo-add-form select {
  padding:7px 10px; border:1.5px solid var(--grey-lt); border-radius:6px;
  font-size:.82rem; font-family:inherit; background:#fff;
}
.todo-add-form input:focus { outline:none; border-color:var(--blue); }
.todo-add-form .tf-title { flex:2; min-width:180px; }
.todo-add-form .tf-due   { width:190px; }
.todo-add-form .tf-email { flex:1; min-width:160px; }
</style>

<a id="notes"></a>
<div class="section-label" style="margin-top:28px">📝 Notes</div>
<div class="notes-thread">
  <?php if (empty($notes)): ?>
  <p style="color:var(--grey-mid);font-size:.83rem;padding:8px 0">No notes yet.</p>
  <?php else: ?>
  <?php
    $cuIsAdmin = in_array($cu['role_name'] ?? '', ['admin','manager']);
  ?>
  <?php foreach ($notes as $n):
    $initials = implode('', array_map(fn($w)=>strtoupper($w[0]),
                array_filter(explode(' ', $n['author'] ?? 'U'))));
    $initials = substr($initials, 0, 2);
    $canEdit  = $cuIsAdmin || ((int)$n['user_id'] === (int)$cu['id']);
  ?>
  <div class="note-item" id="note-<?= $n['id'] ?>">
    <div class="note-avatar"><?= h($initials) ?></div>
    <div class="note-body">
      <div class="note-meta">
        <strong><?= h($n['author'] ?? 'Unknown') ?></strong>
        &nbsp;·&nbsp; <?= date('d M Y H:i', strtotime($n['created_at'])) ?>
        <?php if ($canEdit): ?>
        <span class="note-actions">
          <button type="button" class="note-btn-edit" onclick="noteEdit(<?= $n['id'] ?>)"
                  title="Edit">✏️</button>
          <form method="post" style="display:inline"
                onsubmit="return confirm('Delete this note?')">
            <input type="hidden" name="action_note" value="delete">
            <input type="hidden" name="note_id"    value="<?= $n['id'] ?>">
            <button type="submit" class="note-btn-del" title="Delete">🗑</button>
          </form>
        </span>
        <?php endif; ?>
      </div>
      <!-- Read view -->
      <div class="note-text" id="note-text-<?= $n['id'] ?>"><?= h($n['note']) ?></div>
      <!-- Edit view (hidden) -->
      <?php if ($canEdit): ?>
      <form method="post" class="note-edit-form" id="note-edit-<?= $n['id'] ?>" style="display:none">
        <input type="hidden" name="action_note" value="edit">
        <input type="hidden" name="note_id"    value="<?= $n['id'] ?>">
        <textarea name="note_text" rows="3"><?= h($n['note']) ?></textarea>
        <div style="display:flex;gap:6px;margin-top:5px">
          <button type="submit" class="btn btn-outline btn-sm">Save</button>
          <button type="button" class="btn btn-sm" onclick="noteCancel(<?= $n['id'] ?>)"
                  style="background:#fff;border:1.5px solid var(--grey-lt);color:var(--grey-dk)">Cancel</button>
        </div>
      </form>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>
</div>
<form method="post" class="note-add-form">
  <textarea name="note_text" placeholder="Add a note…" rows="2"></textarea>
  <input type="hidden" name="action_note" value="add">
  <button type="submit" class="btn btn-outline" style="white-space:nowrap;padding:8px 16px">
    + Add note
  </button>
</form>

<!-- ════════════════════════════════════════════════════════════════════════ -->
<!-- TO-DOS -->
<!-- ════════════════════════════════════════════════════════════════════════ -->
<style>
.todo-edit-btn {
  background:none; border:none; cursor:pointer; font-size:.8rem;
  opacity:0; transition:opacity .15s; padding:2px 4px; color:var(--grey-mid);
}
.todo-item:hover .todo-edit-btn { opacity:1; }
.todo-item:hover .todo-del      { color:#bbb; }
.todo-inline-edit {
  display:none; flex-wrap:wrap; gap:6px; align-items:center;
  padding:10px 12px; background:#f9f9f9;
  border:1.5px solid var(--blue); border-radius:8px; margin-bottom:6px;
}
.todo-inline-edit input {
  padding:6px 9px; border:1.5px solid var(--grey-lt); border-radius:6px;
  font-size:.82rem; font-family:inherit; background:#fff;
}
.todo-inline-edit input:focus { outline:none; border-color:var(--blue); }
.todo-inline-edit .tf-title { flex:2; min-width:160px; }
.todo-inline-edit .tf-due   { width:185px; }
.todo-inline-edit .tf-email { flex:1; min-width:140px; }
</style>

<a id="todos"></a>
<div class="section-label" style="margin-top:32px">✅ To-Dos & Reminders</div>
<div class="todos-list">
  <?php if (empty($todos)): ?>
  <p style="color:var(--grey-mid);font-size:.83rem;padding:8px 0">No to-dos yet.</p>
  <?php else: ?>
  <?php foreach ($todos as $t):
    $overdue  = !$t['done'] && strtotime($t['due_at']) < time();
    $cls      = $t['done'] ? 'done-item' : ($overdue ? 'overdue' : '');
    $dueFmt   = date('Y-m-d\TH:i', strtotime($t['due_at']));
  ?>

  <!-- Inline edit form (hidden until ✏️ clicked) -->
  <form method="post" class="todo-inline-edit" id="todo-edit-<?= $t['id'] ?>">
    <input type="hidden" name="action_todo" value="edit">
    <input type="hidden" name="todo_id"    value="<?= $t['id'] ?>">
    <input class="tf-title" name="todo_title" value="<?= h($t['title']) ?>" placeholder="What to do…" required>
    <input class="tf-due" type="datetime-local" name="todo_due" value="<?= $dueFmt ?>" required>
    <input class="tf-email" type="text" name="todo_email"
           value="<?= h($t['email_to'] ?? '') ?>" placeholder="email1, email2, …">
    <button type="submit" class="btn btn-outline btn-sm">Save</button>
    <button type="button" class="btn btn-sm"
            onclick="todoCancel(<?= $t['id'] ?>)"
            style="background:#fff;border:1.5px solid var(--grey-lt);color:var(--grey-dk)">Cancel</button>
  </form>

  <!-- Normal row -->
  <div class="todo-item <?= $cls ?>" id="todo-row-<?= $t['id'] ?>">
    <!-- Toggle done -->
    <form method="post" style="display:contents">
      <input type="hidden" name="action_todo" value="done">
      <input type="hidden" name="todo_id" value="<?= $t['id'] ?>">
      <input type="checkbox" class="todo-check" onchange="this.form.submit()" <?= $t['done']?'checked':'' ?>>
    </form>
    <span class="todo-title"><?= h($t['title']) ?></span>
    <span class="todo-due">
      <?= $overdue ? '⚠ ' : '' ?><?= date('d M Y H:i', strtotime($t['due_at'])) ?>
    </span>
    <?php if ($t['email_to']): ?>
    <span class="todo-email">✉ <?= h($t['email_to']) ?></span>
    <?php endif; ?>
    <!-- Edit -->
    <button type="button" class="todo-edit-btn" title="Edit" onclick="todoEdit(<?= $t['id'] ?>)">✏️</button>
    <!-- Delete -->
    <form method="post" style="display:contents"
          onsubmit="return confirm('Delete this to-do?')">
      <input type="hidden" name="action_todo" value="delete">
      <input type="hidden" name="todo_id" value="<?= $t['id'] ?>">
      <button type="submit" class="todo-del" title="Delete">✕</button>
    </form>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>
</div>

<!-- Add To-Do form -->
<form method="post" class="todo-add-form">
  <input type="hidden" name="action_todo" value="add">
  <input class="tf-title" name="todo_title" placeholder="What to do…" required>
  <input class="tf-due" type="datetime-local" name="todo_due" required
         value="<?= date('Y-m-d\TH:i', strtotime('+1 day')) ?>">
  <input class="tf-email" type="text" name="todo_email"
         placeholder="email1, email2, …"
         value="<?= h($cuEmail) ?>"
         title="One or more addresses separated by commas">
  <button type="submit" class="btn btn-outline" style="white-space:nowrap;padding:7px 16px">
    + Add to-do
  </button>
</form>
<p style="font-size:.7rem;color:var(--grey-mid);margin-top:6px;max-width:860px">
  💡 A reminder email is sent to the address above when the deadline is reached (checked every 15 min).
  Leave blank to skip the email.
</p>

<script>
function todoEdit(id) {
  document.getElementById('todo-row-'  + id).style.display = 'none';
  const form = document.getElementById('todo-edit-' + id);
  form.style.display = 'flex';
  form.querySelector('.tf-title').focus();
}
function todoCancel(id) {
  document.getElementById('todo-edit-' + id).style.display = 'none';
  document.getElementById('todo-row-'  + id).style.display = 'flex';
}
</script>

<?php include 'includes/footer.php'; ?>
