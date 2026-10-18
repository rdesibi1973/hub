<?php
/**
 * procedures.php — "📘 Procedures": the office procedures, always the current version from Dropbox
 * /Operations/OfficeProcedures (procedures_service.php). Every logged-in user, read-only; admins also see the
 * Word sources (orig/) and a link to the Dropbox folder. Meant to grow into the entry point of the Hub manual.
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
    $list = proc_list($admin);
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
    <thead><tr><th>Procedure</th><th>Last updated</th><th>Size</th><th></th></tr></thead>
    <tbody>
    <?php if (!$list['pdf']): ?>
      <tr><td colspan="4" style="color:#777">No procedure in the folder yet.</td></tr>
    <?php endif; ?>
    <?php foreach ($list['pdf'] as $e): ?>
      <tr>
        <td><b><a href="<?= h(proc_url($e['key'])) ?>" target="_blank" rel="noopener"><?= h(proc_title($e['name'])) ?></a></b></td>
        <td><?= h($fmtDate($e['modified'])) ?></td>
        <td><?= h($fmtSize($e['size'])) ?></td>
        <td style="white-space:nowrap;text-align:right">
          <a class="btn btn-sm" href="<?= h(proc_url($e['key'])) ?>" target="_blank" rel="noopener">Open</a>
          <a class="btn btn-sm btn-outline" href="<?= h(proc_url($e['key'], true)) ?>">Download</a>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php if ($admin): ?>
<div class="table-wrap" style="margin-top:20px">
  <div style="padding:12px 16px 4px;font-weight:600">Word sources <span style="font-weight:400;color:#777;font-size:.85rem">(admin only — edit in Dropbox orig/, then export the PDF over the old one)</span></div>
  <table>
    <thead><tr><th>Word source</th><th>Last updated</th><th>Size</th><th></th></tr></thead>
    <tbody>
    <?php if (!$list['src']): ?>
      <tr><td colspan="4" style="color:#777">No Word file in orig/.</td></tr>
    <?php endif; ?>
    <?php foreach ($list['src'] as $e): ?>
      <tr>
        <td><?= h($e['name']) ?></td>
        <td><?= h($fmtDate($e['modified'])) ?></td>
        <td><?= h($fmtSize($e['size'])) ?></td>
        <td style="text-align:right"><a class="btn btn-sm btn-outline" href="<?= h(proc_url($e['key'], true)) ?>">Download</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
<?php endif; ?>

<?php include 'includes/footer.php'; ?>
