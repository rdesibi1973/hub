<?php
/**
 * procedures.php — "📘 Procedures": the office procedures, always the current version from Dropbox
 * /Operations/OfficeProcedures (procedures_service.php). Every logged-in user, read-only: PDFs only, a click on the
 * title opens the PDF in a new tab. Admins also get a link to the Dropbox folder (Word sources are edited there, in orig/).
 * Meant to grow into the entry point of the Hub manual.
 */
ob_start();
require_once 'config.php';
requireLogin();
require_once __DIR__ . '/includes/procedures_service.php';
$pageTitle = 'Procedures';
$currentUser = current_user();
$admin = proc_is_admin();

$list = null;
$error = null;
try {
    $list = proc_list();
} catch (Throwable $e) {
    error_log('procedures: ' . $e->getMessage());
    $error = 'Dropbox is not reachable right now, try again in a minute.';
}
$fmtDate = function (string $iso) {
    $ts = strtotime($iso);
    return $ts ? date('d M Y', $ts) : '';
};
$fmtSize = function (int $b) { return $b >= 1048576 ? round($b / 1048576, 1) . ' MB' : max(1, round($b / 1024)) . ' KB'; };

include 'includes/header.php';
?>
<div class="page-header">
  <div>
    <h2>📘 Procedures</h2>
    <div class="sub">Office procedures — always the current version from Dropbox. Each document has the English text first and the Italian text after.</div>
  </div>
  <?php if ($admin): ?>
  <a class="btn btn-outline" href="https://www.dropbox.com/home<?= h(str_replace('%2F', '/', rawurlencode(PROC_DIR))) ?>" target="_blank" rel="noopener" style="font-size:.8rem">Open folder in Dropbox ↗</a>
  <?php endif; ?>
</div>

<?php if ($error): ?>
<div class="flash flash-error"><?= h($error) ?></div>
<?php else: ?>
<div class="table-wrap">
  <table>
    <thead><tr><th>Procedure</th><th>Last updated</th><th>Size</th></tr></thead>
    <tbody>
    <?php if (!$list['pdf']): ?>
      <tr><td colspan="3" style="color:#777">No procedure in the folder yet.</td></tr>
    <?php endif; ?>
    <?php foreach ($list['pdf'] as $e): ?>
      <tr>
        <td><b><a href="<?= h(proc_url($e['key'])) ?>" target="_blank" rel="noopener"><?= h(proc_title($e['name'])) ?></a></b></td>
        <td><?= h($fmtDate($e['modified'])) ?></td>
        <td><?= h($fmtSize($e['size'])) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php endif; ?>

<?php include 'includes/footer.php'; ?>
