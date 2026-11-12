<?php
/**
 * drafts_cleanup.php — BackOffice → Draft mails in mails/ (admin only). Cowork's draft copies (.eml with "X-Unsent: 1")
 * out of the booking folders' mails/ into old/: "Dry run" lists them (nothing moved), "Move drafts" moves them.
 * Same code as the Agent API drafts_cleanup: drafts_cleanup() in includes/drafts_service.php. Batches: the result gives
 * the next offset (Dropbox rate limits, PHP time) — run again from there.
 */
ob_start();
require_once 'config.php';
requireLogin();
require_once __DIR__ . '/includes/drafts_service.php';
$pageTitle = 'Draft mails in mails/';
$currentUser = current_user();
$db = db();
if (($currentUser['role_name'] ?? '') !== 'admin') { http_response_code(403); die('Admins only.'); }

$in = ['scope' => (string)($_POST['scope'] ?? $_GET['scope'] ?? 'all'), 'request_id' => (int)($_POST['request_id'] ?? 0),
       'offset' => max(0, (int)($_POST['offset'] ?? 0)), 'limit_folders' => max(0, (int)($_POST['limit_folders'] ?? 0)), 'budget' => 100];
if (!in_array($in['scope'], ['safari', 'requests', 'all'], true)) $in['scope'] = 'all';
$res = null; $err = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals(csrf_token(), (string)($_POST['csrf'] ?? ''))) {
        $err = 'Session expired — reload the page and try again.';
    } else {
        $in['confirm'] = ($_POST['action'] ?? '') === 'move';
        @set_time_limit(150);
        try {
            $res = drafts_cleanup($db, $in);
            drafts_audit($db, $currentUser, $in, array_diff_key($res, ['results' => 1]) + ['affected' => array_column($res['results'], 'folder')]);
        } catch (Throwable $e) {
            $err = $e->getMessage();
        }
    }
}
include 'includes/header.php';
?>
<style>
.dr-info{background:#fff;border-left:4px solid #3A6EA5;border-radius:6px;padding:12px 16px;margin-bottom:16px;font-size:.88rem;line-height:1.6}
.dr-form{display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;margin-bottom:16px;font-size:.85rem}
.dr-form label{display:block;font-size:.75rem;font-weight:700;margin-bottom:3px}
.dr-form select,.dr-form input{padding:6px 9px;border:1.5px solid #ddd;border-radius:6px;font-family:inherit}
.dr-sum{background:#fff;border-radius:8px;padding:12px 16px;margin-bottom:14px;font-size:.88rem}
.dr-table{width:100%;border-collapse:collapse;font-size:.82rem;background:#fff}
.dr-table th,.dr-table td{padding:.45rem .55rem;border-bottom:1px solid #eee;text-align:left;vertical-align:top}
.dr-table th{background:#fafafa;border-bottom:2px solid #ddd}
.dr-file{word-break:break-all}
.dr-err{background:#fde2e1;color:#A01A14;border-radius:6px;padding:10px 14px;margin-bottom:14px}
</style>
<div class="page-header"><h2>✉ Draft mails in mails/</h2></div>
<div class="dr-info">
  Cowork keeps a copy of each draft mail as <code>.eml</code> with the header <code>X-Unsent: 1</code>. The booking folders'
  <code>mails/</code> must hold only real mails (received, sent, supplier proofs), so drafts move to the folder's <code>old/</code>.
  A file is a draft only if it is <code>.eml</code> <b>and</b> its header says <code>X-Unsent: 1</code> — never by its name; other files are
  never touched, nothing is deleted. A name already in <code>old/</code> gets “(2)”, “(3)”…<br>
  <b>Dry run</b> first, check the totals and a sample, then <b>Move drafts</b>. Large scopes run in batches: start again from the next offset shown.
</div>
<?php if ($err): ?><div class="dr-err"><?= h($err) ?></div><?php endif; ?>
<form method="POST" class="dr-form">
  <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
  <div><label for="dr-scope">Folders</label>
    <select name="scope" id="dr-scope">
      <?php foreach (['all' => 'All (001_Safari + inquiry years)', 'safari' => '001_Safari only', 'requests' => 'Inquiry years only (/' . (date('Y') - 1) . ', /' . date('Y') . ', /' . (date('Y') + 1) . ')'] as $k => $lbl): ?>
      <option value="<?= $k ?>"<?= $in['scope'] === $k ? ' selected' : '' ?>><?= h($lbl) ?></option>
      <?php endforeach; ?>
    </select></div>
  <div><label for="dr-req">or one request #</label><input type="number" name="request_id" id="dr-req" min="0" value="<?= $in['request_id'] ?: '' ?>" style="width:110px"></div>
  <div><label for="dr-off">From offset</label><input type="number" name="offset" id="dr-off" min="0" value="<?= (int)($res['next_offset'] ?? $in['offset']) ?>" style="width:90px"></div>
  <div><label for="dr-lim">Folders per run (0 = until time runs out)</label><input type="number" name="limit_folders" id="dr-lim" min="0" value="<?= $in['limit_folders'] ?>" style="width:90px"></div>
  <button type="submit" name="action" value="dry" class="btn btn-outline btn-sm">Dry run</button>
  <button type="submit" name="action" value="move" class="btn btn-red btn-sm"
          onclick="return confirm('Move the draft .eml files found from mails/ to old/ in these folders?')">Move drafts</button>
</form>
<?php if ($res): ?>
<div class="dr-sum">
  <b><?= $res['dry_run'] ? 'Dry run — nothing moved.' : 'Moved.' ?></b>
  Folders <?= (int)$res['offset'] ?>–<?= (int)$res['offset'] + (int)$res['folders_scanned'] ?> of <?= (int)$res['folders_total'] ?> scanned
  (<?= (int)$res['folders_with_mails'] ?> with mails/): <b><?= (int)$res['drafts_found'] ?></b> draft(s) in <?= (int)$res['folders_affected'] ?> folder(s)<?= $res['dry_run'] ? '' : ', ' . (int)$res['moved'] . ' moved' ?>.
  <?php if ($res['next_offset'] !== null): ?><br>Not finished: run again from offset <b><?= (int)$res['next_offset'] ?></b> (already filled in above).<?php else: ?><br>Scope done.<?php endif; ?>
  <?php foreach ($res['errors'] as $e): ?><br><span style="color:#A01A14">⚠ <?= h($e['folder'] . ': ' . $e['error']) ?></span><?php endforeach; ?>
</div>
<?php if ($res['results']): ?>
<div class="card"><div class="table-wrap"><table class="dr-table">
  <tr><th>Folder</th><th>Drafts<?= $res['dry_run'] ? ' (would move to old/)' : ' → old/' ?></th><th>Real mails kept in mails/</th></tr>
  <?php foreach ($res['results'] as $r): ?>
  <tr>
    <td class="dr-file"><?= h($r['folder']) ?><?php if ($r['request_id']): ?><br><a href="request_view.php?id=<?= (int)$r['request_id'] ?>" target="_blank">#<?= (int)$r['request_id'] ?></a><?php endif; ?></td>
    <td class="dr-file"><?php if (!empty($r['moves'])): foreach ($r['moves'] as $m): ?><?= h($m['from'] . ' → ' . $m['to'] . ($m['result'] !== 'moved' ? ' (' . $m['result'] . ')' : '')) ?><br><?php endforeach;
          else: foreach ($r['drafts'] as $d): ?><?= h($d) ?><br><?php endforeach; endif; ?>
        <?php foreach ($r['errors'] ?? [] as $e): ?><span style="color:#A01A14">⚠ <?= h($e['file'] . ': ' . $e['error']) ?></span><br><?php endforeach; ?></td>
    <td class="dr-file"><?= h(implode(', ', $r['kept'])) ?: '—' ?></td>
  </tr>
  <?php endforeach; ?>
</table></div></div>
<?php endif; ?>
<?php endif; ?>
<?php include 'includes/footer.php'; ?>
