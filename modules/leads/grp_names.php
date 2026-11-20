<?php
/**
 * grp_names.php — group folders of /001_Safari whose name is not normalised:
 * the programme (Duma / Simba / Nyumbu) must be written once, right after GRP{DDMM}
 * (…_GRP0209Nyumbu(Agency-Agent)_START…). Report only: each rename is done one by one
 * (BackOffice rename, scope group) after Roberto's ok. Same data as the Agent API grp_names_report.
 * Logic: includes/grp_program_service.php (bo_grp_names_report()).
 */
ob_start();
require_once 'config.php';
require_once 'includes/booking_service.php';
$pageTitle = 'GRP folder names';
$db = db();

$currentUser = current_user();
if (($currentUser['role_name'] ?? '') !== 'admin') {
    flash('Access denied.', 'error');
    header('Location: requests.php'); exit;
}

$rows = null; $err = '';
if (!empty($_GET['run'])) {
    @set_time_limit(180);
    try {
        require_once 'dropbox_helper.php';
        $rows = bo_grp_names_report($db, dropbox_get_access_token());
    } catch (Throwable $e) { $err = $e->getMessage(); }
}

include 'includes/header.php';
?>
<div class="page-header"><h2>🏷 GRP folder names — programme</h2></div>

<div class="table-wrap" style="max-width:1200px;margin-bottom:20px">
  <div style="padding:18px 22px">
    <p style="font-size:.85rem;color:var(--grey-dk);line-height:1.6;margin:0 0 10px">
      Group folders must say their programme right after the code: <code>…_GRP0209Nyumbu(Agency-Agent)_START…</code>
      (Duma, Simba or Nyumbu — DumaShort is a private safari). This lists every group folder at the top of
      <code>/001_Safari</code> whose name is not in that form, with the programme found in the name or in the group Calc
      (<code>…_NyumbuGRP_Calc.xlsx</code>) and the suggested name. Nothing is renamed automatically.
    </p>
    <a class="btn btn-red btn-sm" href="grp_names.php?run=1">Run the report</a>
  </div>
</div>

<?php if ($err !== ''): ?>
  <div class="table-wrap" style="max-width:1200px;padding:14px 22px;color:#C0211B">Error: <?= h($err) ?></div>
<?php elseif ($rows !== null): ?>
<div class="table-wrap" style="max-width:1200px">
  <div style="padding:10px 22px;font-size:.85rem"><b><?= count($rows) ?></b> group folder(s) to look at.</div>
  <?php if ($rows): ?>
  <table class="data-table" style="font-size:.78rem">
    <thead><tr><th>Folder</th><th>Programme</th><th>Issue</th><th>Suggested name</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $g): ?>
      <tr>
        <td style="font-family:monospace;word-break:break-all"><?= h($g['folder']) ?></td>
        <td><?= $g['program'] ? h($g['program']) . ' <span style="color:#888">(' . h($g['program_source']) . ')</span>' : '—' ?></td>
        <td><?= h($g['issue']) ?></td>
        <td style="font-family:monospace;word-break:break-all"><?= $g['suggested'] ? h($g['suggested']) : '—' ?></td>
        <td style="white-space:nowrap">
          <?php if ($g['suggested'] && $g['request_id']): ?>
            <form method="POST" action="backoffice.php" style="margin:0"
                  onsubmit="return confirm(<?= h(json_encode("Rename the group folder (all its members follow)?\n\n" . $g['folder'] . "\n→ " . $g['suggested'])) ?>);">
              <input type="hidden" name="action" value="rename">
              <input type="hidden" name="request_id" value="<?= (int)$g['request_id'] ?>">
              <input type="hidden" name="new_name" value="<?= h($g['suggested']) ?>">
              <input type="hidden" name="q" value="<?= h('GRP' . $g['code']) ?>">
              <input type="hidden" name="root" value="All">
              <button type="submit" class="btn btn-outline btn-sm">✏ Rename</button>
            </form>
          <?php elseif ($g['suggested']): ?>
            <span style="color:#888" title="No Hub request is filed in this group: rename it in Dropbox">not in the Hub</span>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php include 'includes/footer.php'; ?>
