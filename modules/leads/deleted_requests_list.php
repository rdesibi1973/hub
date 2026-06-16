<?php
require_once 'config.php';
require_once 'dropbox_helper.php';
requireLogin();
$currentUser = current_user();
$isAdmin = ($currentUser['role_name'] ?? '') === 'admin';
if (!$isAdmin) { header('Location: requests.php'); exit; }

$db = db();

// Status tags (longest first) for restoring the folder name.
$STATUS_TAGS = [
    '_BALANCE-CASH', '_BALANCE_CASH', '_BALANCE',
    '_DEPOSIT', '_PAID', '_PROGRESS', '_PROVISIONAL', '_CANCELLED',
];
function swap_status_tag(string $name, array $tags, string $newTag): string {
    foreach ($tags as $tag) {
        $pos = stripos($name, $tag);
        if ($pos !== false) {
            return substr($name, 0, $pos) . $newTag . substr($name, $pos + strlen($tag));
        }
    }
    return $name . $newTag;
}

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
    if ($_POST['action'] === 'restore') {
        $rowData = json_decode($arc['row_data'], true);
        if (!is_array($rowData)) { echo json_encode(['error' => 'Corrupt row_data']); exit; }

        // Move folder back to 001_Safari with _PROGRESS status.
        $fromPath = $arc['dropbox_to_path'];   // currently in 00_CANCELED
        $leaf     = basename($fromPath);
        $newLeaf  = swap_status_tag($leaf, $STATUS_TAGS, '_PROGRESS');
        $toPath   = '/001_Safari/' . $newLeaf;

        try {
            $token = dropbox_get_access_token();
            dropbox_move_folder($token, $fromPath, $toPath);
        } catch (RuntimeException $e) {
            echo json_encode(['error' => 'Dropbox move failed: ' . $e->getMessage()]); exit;
        }

        // Reinsert into requests (force status Progress, preserve original id if free).
        try {
            $db->beginTransaction();
            $cols = array_keys($rowData);
            $rowData['status'] = 'Progress';
            // keep practice_code aligned with the restored folder name
            if (!empty($rowData['group_folder'])) {
                $rowData['group_folder'] = $newLeaf;
            } else {
                $rowData['practice_code'] = $newLeaf;
            }
            $place = implode(',', array_fill(0, count($cols), '?'));
            $colSql = '`' . implode('`,`', $cols) . '`';
            $vals = array_map(fn($c) => $rowData[$c] ?? null, $cols);
            $db->prepare("INSERT INTO requests ($colSql) VALUES ($place)")->execute($vals);
            $db->prepare("DELETE FROM deleted_requests WHERE del_id = ?")->execute([$delId]);
            $db->commit();
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            try { dropbox_move_folder($token, $toPath, $fromPath); } catch (Exception $e2) {}
            echo json_encode(['error' => 'Restore DB failed: ' . $e->getMessage()
                            . ' (folder moved back)']); exit;
        }
        echo json_encode(['ok' => true, 'restored_to' => $toPath]);
        exit;
    }

    // ---- PURGE (hard delete archive row; Dropbox folder stays in 00_CANCELED) ----
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
    Soft-deleted requests. Their Dropbox folder has been renamed
    <code>_CANCELLED</code> and moved to <code>001_Safari/00_CANCELED/00_&lt;year&gt;</code>.
    <strong>Restore</strong> reinstates the request (status PROGRESS) and moves the folder
    back to <code>001_Safari</code>. <strong>Purge</strong> removes the archive record
    permanently (the Dropbox folder remains in 00_CANCELED).
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
        <th style="padding:.5rem;">Current folder</th>
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
        <td style="padding:.5rem;font-size:.8em;color:#888;"><?= h($a['dropbox_to_path'] ?? '') ?></td>
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
  if (!confirm('Restore this request? It will reappear with status PROGRESS and the folder will move back to 001_Safari.')) return;
  btn.disabled = true;
  const j = await postAction('restore', delId);
  if (j.ok) { document.getElementById('row-' + delId).remove(); }
  else { alert('Error: ' + (j.error || 'unknown')); btn.disabled = false; }
}
async function purgeReq(delId, btn) {
  if (!confirm('Permanently remove this archive record? This cannot be undone. The Dropbox folder will stay in 00_CANCELED.')) return;
  btn.disabled = true;
  const j = await postAction('purge', delId);
  if (j.ok) { document.getElementById('row-' + delId).remove(); }
  else { alert('Error: ' + (j.error || 'unknown')); btn.disabled = false; }
}
</script>
<?php include 'includes/footer.php'; ?>
