<?php
/**
 * normalize_capri.php  (one-off admin maintenance tool)
 *
 * Roberto Capri's bookings were created with the agent token "Capri" inside the
 * folder parentheses instead of the canonical "RobertoCapri". This tool finds
 * every request whose practice_code / group_folder contains "(Capri)" and:
 *   1. renames the matching folder on Dropbox  ( (Capri) -> (RobertoCapri) )
 *   2. updates practice_code / group_folder and dropbox_url in the database
 * keeping Dropbox and the DB in sync in a single operation.
 *
 * "EleonoraOngaro" is the AGENCY name and is never touched by this tool.
 * Folders that already contain "RobertoCapri" are skipped.
 *
 * SAFETY:
 *   - Dry-run by default. Add ?run=1 to actually perform the changes.
 *   - Admin login required.
 *   - Only the "(Capri)" substring is replaced, so customer surnames are safe.
 *
 * Place in /modules/leads/ ; open in browser:
 *   https://hub.savannahexplorers.com/modules/leads/normalize_capri.php        (preview)
 *   https://hub.savannahexplorers.com/modules/leads/normalize_capri.php?run=1  (execute)
 */

require_once 'config.php';
require_once 'dropbox_helper.php';
requireLogin();

$cu = current_user();
if (($cu['role_name'] ?? '') !== 'admin') {
    http_response_code(403);
    die('Admins only.');
}

@set_time_limit(0);
header('Content-Type: text/html; charset=utf-8');

$DO_RUN = (($_GET['run'] ?? '') === '1');
$db = db();

// ── Find candidate rows ──────────────────────────────────────────────────────
// Match "(Capri)" as a token inside parentheses, in practice_code or group_folder.
// Skip anything that already contains "RobertoCapri".
$stmt = $db->query(
    "SELECT id, customer_name, practice_code, group_folder, status, dropbox_url
     FROM requests
     WHERE (practice_code LIKE '%(Capri)%' OR group_folder LIKE '%(Capri)%')
       AND COALESCE(practice_code,'') NOT LIKE '%RobertoCapri%'
       AND COALESCE(group_folder,'')  NOT LIKE '%RobertoCapri%'
     ORDER BY status DESC, practice_code ASC"
);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Acquire a Dropbox token once (only if we may touch Dropbox).
$token = null;
$tokenError = null;
try {
    $token = dropbox_get_access_token();
} catch (Throwable $e) {
    $tokenError = $e->getMessage();
}

$results = [];

foreach ($rows as $r) {
    $id = (int)$r['id'];

    // Decide which column carries the folder name.
    $usingGroup = false;
    $oldName = (string)($r['practice_code'] ?? '');
    if (strpos($oldName, '(Capri)') === false) {
        // the match was on group_folder
        $oldName    = (string)($r['group_folder'] ?? '');
        $usingGroup = true;
    }

    $newName = str_replace('(Capri)', '(RobertoCapri)', $oldName);

    $res = [
        'id'          => $id,
        'customer'    => $r['customer_name'],
        'column'      => $usingGroup ? 'group_folder' : 'practice_code',
        'status'      => $r['status'],
        'old'         => $oldName,
        'new'         => $newName,
        'dbx_action'  => '',
        'db_action'   => '',
        'ok'          => true,
        'msg'         => '',
    ];

    if ($oldName === '' || $oldName === $newName) {
        $res['ok']  = false;
        $res['msg'] = 'Nothing to replace.';
        $results[] = $res;
        continue;
    }

    // ── Dropbox rename ────────────────────────────────────────────────────────
    // Locate the folder by its current name; rename in place (same directory).
    $dbxRenamed = false;
    if ($token !== null) {
        try {
            $fromPath = dropbox_find_folder($token, $oldName);
            if ($fromPath !== null) {
                // Replace only the last path segment, keep the parent directory.
                $slash    = strrpos($fromPath, '/');
                $parent   = $slash !== false ? substr($fromPath, 0, $slash + 1) : '/';
                $toPath   = $parent . $newName;

                if ($DO_RUN) {
                    dropbox_move_folder($token, $fromPath, $toPath);
                    $res['dbx_action'] = "renamed: {$fromPath} -> {$toPath}";
                } else {
                    $res['dbx_action'] = "would rename: {$fromPath} -> {$toPath}";
                }
                $dbxRenamed = true;
            } else {
                $res['dbx_action'] = 'folder not found on Dropbox (DB-only update)';
            }
        } catch (Throwable $e) {
            $res['ok']  = false;
            $res['msg'] = 'Dropbox error: ' . $e->getMessage();
            $res['dbx_action'] = 'FAILED';
            $results[] = $res;
            continue; // do NOT update the DB if the Dropbox rename failed
        }
    } else {
        $res['dbx_action'] = 'no Dropbox token (' . $tokenError . ') — skipped';
    }

    // ── DB update ─────────────────────────────────────────────────────────────
    // Rebuild dropbox_url by swapping the encoded folder name in the existing URL.
    $newUrl = (string)($r['dropbox_url'] ?? '');
    if ($newUrl !== '') {
        $newUrl = str_replace(rawurlencode($oldName), rawurlencode($newName), $newUrl);
        $newUrl = str_replace($oldName, $newName, $newUrl); // unencoded variant too
    }

    $col = $usingGroup ? 'group_folder' : 'practice_code';
    if ($DO_RUN) {
        if ($newUrl !== '') {
            $u = $db->prepare("UPDATE requests SET $col = ?, dropbox_url = ? WHERE id = ?");
            $u->execute([$newName, $newUrl, $id]);
        } else {
            $u = $db->prepare("UPDATE requests SET $col = ? WHERE id = ?");
            $u->execute([$newName, $id]);
        }
        $res['db_action'] = "updated $col" . ($newUrl !== '' ? ' + dropbox_url' : '');
    } else {
        $res['db_action'] = "would update $col" . ($newUrl !== '' ? ' + dropbox_url' : '');
    }

    $results[] = $res;
}

// ── Render ────────────────────────────────────────────────────────────────────
$total   = count($results);
$okCount = 0; foreach ($results as $x) if ($x['ok']) $okCount++;
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8">
<title>Normalize Capri → RobertoCapri</title>
<style>
  body{font-family:system-ui,Arial,sans-serif;margin:24px;color:#1a1a2e;font-size:14px}
  h1{font-size:20px;margin:0 0 4px}
  .mode{display:inline-block;padding:4px 10px;border-radius:6px;font-weight:600;font-size:12px}
  .dry{background:#fff3cd;color:#7a5b00}
  .live{background:#d1f0d8;color:#1b6b3a}
  table{border-collapse:collapse;width:100%;margin-top:16px;font-size:12.5px}
  th,td{border:1px solid #e0e0e8;padding:6px 8px;text-align:left;vertical-align:top}
  th{background:#f4f4f8}
  tr.err td{background:#fdeaea}
  .old{color:#a33}.new{color:#1b6b3a;font-weight:600}
  code{font-family:ui-monospace,Menlo,monospace}
  .bar{margin:14px 0;padding:10px 14px;background:#f4f4f8;border-radius:8px}
  a.btn{display:inline-block;margin-left:12px;padding:6px 14px;border-radius:6px;
        background:#C0211B;color:#fff;text-decoration:none;font-weight:600}
</style></head><body>
<h1>Normalize <code>(Capri)</code> → <code>(RobertoCapri)</code></h1>
<div class="bar">
  Mode:
  <?php if ($DO_RUN): ?>
    <span class="mode live">LIVE — changes applied</span>
  <?php else: ?>
    <span class="mode dry">DRY-RUN — preview only</span>
    <a class="btn" href="?run=1" onclick="return confirm('Apply changes to Dropbox AND the database?');">Execute now</a>
  <?php endif; ?>
  &nbsp;|&nbsp; <?= $okCount ?>/<?= $total ?> rows OK
  <?php if ($tokenError): ?>
    &nbsp;|&nbsp; <strong style="color:#a33">Dropbox token error: <?= h($tokenError) ?></strong>
  <?php endif; ?>
</div>

<?php if ($total === 0): ?>
  <p>No rows to normalize. Everything already uses <code>RobertoCapri</code>.</p>
<?php else: ?>
<table>
  <tr><th>ID</th><th>Customer</th><th>Status</th><th>Column</th>
      <th>Old → New</th><th>Dropbox</th><th>DB</th><th>Note</th></tr>
  <?php foreach ($results as $x): ?>
  <tr class="<?= $x['ok'] ? '' : 'err' ?>">
    <td><?= (int)$x['id'] ?></td>
    <td><?= h($x['customer']) ?></td>
    <td><?= h($x['status']) ?></td>
    <td><?= h($x['column']) ?></td>
    <td><code class="old"><?= h($x['old']) ?></code><br><code class="new"><?= h($x['new']) ?></code></td>
    <td><?= h($x['dbx_action']) ?></td>
    <td><?= h($x['db_action']) ?></td>
    <td><?= h($x['msg']) ?></td>
  </tr>
  <?php endforeach; ?>
</table>
<?php endif; ?>
</body></html>
