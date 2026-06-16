<?php
require_once 'config.php';
requireLogin();
$currentUser = current_user();
$isAdmin = ($currentUser['role_name'] ?? '') === 'admin';
if (!$isAdmin) { header('Location: requests.php'); exit; }

$db = db();

// ── AJAX actions (restore / purge) ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $delId = (int)($_POST['del_id'] ?? 0);
    if (!$delId) { echo json_encode(['error' => 'No del_id']); exit; }

    $r = $db->prepare("SELECT * FROM deleted_requests WHERE del_id = ?");
    $r->execute([$delId]);
    $arc = $r->fetch(PDO::FETCH_ASSOC);
    if (!$arc) { echo json_encode(['error' => 'Archive record not found']); exit; }

    // ---- RESTORE ----
    // The Dropbox folder was never moved (it stays in /<year>/), so restore is
    // purely a DB operation: reinsert the original row as-is and drop the archive.
    if ($_POST['action'] === 'restore') {
        $rowData = json_decode($arc['row_data'], true);
        if (!is_array($rowData)) { echo json_encode(['error' => 'Corrupt row_data']); exit; }

        try {
            $db->beginTransaction();
            $cols   = array_keys($rowData);
            $place  = implode(',', array_fill(0, count($cols), '?'));
            $colSql = '`' . implode('`,`', $cols) . '`';
            $vals   = array_map(fn($c) => $rowData[$c] ?? null, $cols);
            $db->prepare("INSERT INTO requests ($colSql) VALUES ($place)")->execute($vals);
            $db->prepare("DELETE FROM deleted_requests WHERE del_id = ?")->execute([$delId]);
            $db->commit();
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            echo json_encode(['error' => 'Restore failed: ' . $e->getMessage()]); exit;
        }
        echo json_encode(['ok' => true, 'restored_id' => $arc['orig_id']]);
        exit;
    }

    // ---- PURGE (hard delete archive row) ----
    if ($_POST['action'] === 'purge') {
        $db->prepare("DELETE FROM deleted_requests WHERE del_id = ?")->execute([$delId]);
        echo json_encode(['ok' => true, 'purged' => $delId]);
        exit;
    }

    echo json_encode(['error' => 'Unknown action']); exit;
}

$pageTitle = 'Deleted Requests (Archive)';
$archived = $db->query(
    "SELECT del_id, orig_id, practice_code, customer_name, deleted_by_name,
            deleted_at, dropbox_to_path
       FROM deleted_requests
   ORDER BY deleted_at DESC"
)->fetchAll(PDO::FETCH_ASSOC);

include 'includes/header.php';
?>
<div class="container" style="max-width:1100px;margin:0 auto;padding:1rem;">
  <h1>Deleted Requests (Archive)</h1>
  <p style="color:#666;">
    Soft-deleted requests. The Dropbox folder is left untouched in its
    <code>/&lt;year&gt;/</code> location. <strong>Restore</strong> reinstates the
    request exactly as it was. <strong>Purge</strong> removes the archive record
    permanently (the Dropbox folder is not affected).
  </p>

  <?php if (!$archived): ?>
    <p>No deleted requests.</p>
  <?php else: ?>
  <table class="table" style="width:100%;border-collapse:collapse;">
    <thead>
      <tr style="text-align:left;border-bottom:2px solid #ddd;">
        <th style="padding:.5rem;">Customer</th>
        <th style="padding:.5rem;">Practice code</th>
        <th style="padding:.5rem;">Deleted by</th>
        <th style="padding:.5rem;">Deleted at</th>
        <th style="padding:.5rem;">Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($archived as $a): ?>
      <tr style="border-bottom:1px solid #eee;" id="row-<?= (int)$a['del_id'] ?>">
        <td style="padding:.5rem;"><?= h($a['customer_name'] ?? '') ?></td>
        <td style="padding:.5rem;font-size:.85em;"><?= h($a['practice_code'] ?? '') ?></td>
        <td style="padding:.5rem;"><?= h($a['deleted_by_name'] ?? '') ?></td>
        <td style="padding:.5rem;"><?= h($a['deleted_at'] ?? '') ?></td>
        <td style="padding:.5rem;white-space:nowrap;">
          <button class="btn btn-sm" onclick="restoreReq(<?= (int)$a['del_id'] ?>, this)">Restore</button>
          <button class="btn btn-sm btn-danger" onclick="purgeReq(<?= (int)$a['del_id'] ?>, this)">Purge</button>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<script>
async function postAction(action, delId) {
  const fd = new FormData();
  fd.append('action', action);
  fd.append('del_id', delId);
  const res = await fetch('deleted_requests_list.php', { method: 'POST', body: fd });
  return res.json();
}
async function restoreReq(delId, btn) {
  if (!confirm('Restore this request? It will reappear in the requests list as it was. The Dropbox folder is not affected.')) return;
  btn.disabled = true;
  const j = await postAction('restore', delId);
  if (j.ok) { document.getElementById('row-' + delId).remove(); }
  else { alert('Error: ' + (j.error || 'unknown')); btn.disabled = false; }
}
async function purgeReq(delId, btn) {
  if (!confirm('Permanently remove this archive record? This cannot be undone. The Dropbox folder is not affected.')) return;
  btn.disabled = true;
  const j = await postAction('purge', delId);
  if (j.ok) { document.getElementById('row-' + delId).remove(); }
  else { alert('Error: ' + (j.error || 'unknown')); btn.disabled = false; }
}
</script>
<?php include 'includes/footer.php'; ?>
