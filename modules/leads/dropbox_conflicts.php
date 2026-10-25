<?php
/**
 * dropbox_conflicts.php — Dropbox conflicted copies in /001_Safari (Calc open in Excel on two PCs): what the nightly
 * run (ck_cron.php) resolved by itself and what the owners still have to fix. Admin / manager: every owner,
 * "Analyse now" (never changes a file). Anyone else: only the bookings they own.
 * Same data as the Agent API dropbox_conflicts: dbxc_report() in includes/dbx_conflict_service.php.
 */
ob_start();
require_once 'config.php';
requireLogin();
if (!defined('AGENT_API_KEY') && is_file(__DIR__ . '/../../includes/config.php')) {
    require_once __DIR__ . '/../../includes/config.php';   // DBX_CONFLICTS_LIVE
}
require_once __DIR__ . '/includes/dbx_conflict_service.php';
$pageTitle = 'Conflicted copies';
$currentUser = current_user();
$db = db();
$isMgr = !(function_exists('isLeadsRestricted') && isLeadsRestricted()) && in_array($currentUser['role_name'] ?? '', ['admin', 'manager'], true);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'analyse') {
    if (!$isMgr) {
        flash('Only admin and managers can start the analysis.', 'error');
    } elseif (!hash_equals(csrf_token(), (string)($_POST['csrf'] ?? ''))) {
        flash('Session expired — reload the page and try again.', 'error');
    } else {
        @set_time_limit(150);
        try {
            $s = dbxc_run($db, ['live' => false, 'notify' => false, 'budget' => 90]);
            if (empty($s['ok'])) flash($s['msg'], 'error');
            else flash("Analysed {$s['checked']} of {$s['conflicts']} conflicted copies" . ($s['left'] ? ", {$s['left']} left: click again" : '')
                       . ($s['gone'] ? ", {$s['gone']} gone" : '') . '. Nothing was changed in Dropbox.');
        } catch (Throwable $e) {
            flash('Analysis failed: ' . $e->getMessage(), 'error');
        }
    }
    ob_end_clean();
    header('Location: dropbox_conflicts.php'); exit;
}

$owner = $isMgr ? ((int)($_GET['owner'] ?? 0) ?: null) : (int)$currentUser['id'];
$rep = dbxc_report($db, $owner);
$owners = [];
foreach (array_merge($rep['open'], $rep['closed']) as $r) if ($r['owner_user_id']) $owners[$r['owner_user_id']] = $r['owner'];
asort($owners);
$lastCron = dbxc_meta($db, 'last_cron');
$past = date('Y-m-d', strtotime('-' . DBXC_PAST_DAYS . ' days'));

$cellTable = function (array $r) {
    if (!$r['cells']) return;
    echo '<details class="dc-cells"><summary>' . (int)$r['n_cells'] . ' cell' . ((int)$r['n_cells'] === 1 ? '' : 's') . '</summary><table>'
       . '<tr><th>Cell</th>' . ($r['base_saved'] ? '<th>Before (' . h($r['base_saved']) . ')</th>' : '') . '<th>Original</th><th>Copy</th></tr>';
    foreach ($r['cells'] as $c) {
        echo '<tr' . ($c['both'] ? ' class="dc-both" title="changed in both files"' : '') . '><td>' . h($c['cell']) . '</td>'
           . ($r['base_saved'] ? '<td>' . h($c['base']) . '</td>' : '') . '<td>' . h($c['original']) . '</td><td>' . h($c['copy']) . '</td></tr>';
    }
    if ($r['n_cells'] > count($r['cells'])) echo '<tr><td colspan="4">… ' . ($r['n_cells'] - count($r['cells'])) . ' more</td></tr>';
    echo '</table></details>';
};

include 'includes/header.php';
?>
<style>
.dc-box{margin-bottom:20px}
.dc-box h3{font-size:1rem;margin:0 0 8px}
.dc-wrap{overflow-x:auto}
.dc-table{width:100%;border-collapse:collapse;font-size:.85rem;background:#fff}
.dc-table th,.dc-table td{padding:.45rem .55rem;border-bottom:1px solid #eee;text-align:left;vertical-align:top}
.dc-table th{background:#fafafa;border-bottom:2px solid #ddd;font-weight:600;text-transform:none;letter-spacing:normal}
.dc-file{word-break:break-all;min-width:180px;max-width:380px}
.dc-nowrap{white-space:nowrap}
.dc-tag{display:inline-block;border-radius:10px;padding:1px 8px;font-size:.72rem;font-weight:600;white-space:nowrap}
.dc-auto{background:#e3f4e5;color:#2e7d32}.dc-fix{background:#fde2e1;color:#C0211B}.dc-plan{background:#fff3cd;color:#7a5b00}.dc-grey{background:#eee;color:#555}
.dc-why{color:#555;font-size:.8rem;display:block;margin-top:2px}
.dc-cells summary{cursor:pointer;color:#3A6EA5;font-size:.8rem}
.dc-cells table{border-collapse:collapse;margin-top:4px;font-size:.78rem}
.dc-cells th,.dc-cells td{border:1px solid #e5e5e5;padding:2px 6px;white-space:nowrap}
.dc-cells tr.dc-both td{background:#fde2e1}
.dc-info{background:#fff;border-left:4px solid #3A6EA5;border-radius:6px;padding:12px 16px;margin-bottom:16px;font-size:.88rem;line-height:1.6}
.dc-info p{margin:0 0 6px}.dc-info p:last-child{margin:0}
.dc-bar{display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-bottom:16px;font-size:.85rem}
.dc-hint{color:#777;font-size:.82rem}
</style>

<div class="page-header"><h2>🔀 Conflicted copies in Dropbox</h2></div>

<div class="dc-info">
  <p>When a Calc stays open in Excel on one PC while someone else (or the Hub) saves it, Dropbox keeps both versions and adds a
  <b>conflicted copy</b> next to the original. Every night (01:00–07:00) the Hub compares each copy in <code>/001_Safari</code>
  (not the <code>old/</code> sub-folders nor the archive folders like <code>00_2025</code>) with the original and its Dropbox version history, cell by cell:</p>
  <p><span class="dc-tag dc-auto">resolved</span> when the copy adds nothing, or when the copy and the original changed <i>different</i>
  cells: the copy's cells are written into the original, checked, and the copy goes to the Dropbox trash (restorable from Deleted files).<br>
  <span class="dc-tag dc-fix">to fix</span> when the <i>same</i> cells changed in both, or the file cannot be merged safely: the owner of the
  booking gets a memo and an email with the cells to look at.</p>
  <p class="dc-hint">Mode: <b><?= $rep['live'] ? 'live' : 'analysis only' ?></b><?= $rep['live'] ? '' : ' — the nightly run only shows what it would do (nothing moved, written, memoed or mailed) until DBX_CONFLICTS_LIVE is set in the server config' ?>.
  <?php if ($rep['last_run']): $lr = $rep['last_run']; ?>
  Last run <?= h($lr['at']) ?> (<?= $lr['live'] ? 'live' : 'analysis' ?>): <?= (int)$lr['conflicts'] ?> copies, <?= (int)$lr['checked'] ?> checked<?= !empty($lr['left']) ? ', ' . (int)$lr['left'] . ' left' : '' ?><?= !empty($lr['errors']) ? ', ' . count($lr['errors']) . ' errors' : '' ?>.
  <?php else: ?>Not run yet.<?php endif; ?>
  <?php if ($isMgr && $lastCron): ?>Last cron call: <?= h($lastCron) ?>.<?php endif; ?></p>
</div>

<?php if ($isMgr): ?>
<div class="dc-bar">
  <form method="get">
    <label>Owner
      <select name="owner" onchange="this.form.submit()">
        <option value="">All</option>
        <?php foreach ($owners as $id => $name): ?><option value="<?= (int)$id ?>" <?= $owner === (int)$id ? 'selected' : '' ?>><?= h($name) ?></option><?php endforeach; ?>
      </select>
    </label>
  </form>
  <form method="post" onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').textContent='Analysing…'">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="analyse">
    <button type="submit" class="btn btn-secondary btn-sm">Analyse now</button>
    <span class="dc-hint">reads Dropbox only, about 1½ min at most; it changes nothing</span>
  </form>
</div>
<?php endif; ?>

<?php
$tables = [
    ['Open (' . count($rep['open']) . ')', $rep['open'], true],
    ['Resolved or gone in the last 30 days (' . count($rep['closed']) . ')', $rep['closed'], false],
];
foreach ($tables as [$title, $list, $isOpen]): ?>
<div class="table-wrap dc-box">
  <div style="padding:14px 18px">
  <h3><?= h($title) ?></h3>
  <?php if (!$list): ?><div class="dc-hint">none</div><?php else: ?>
  <div class="dc-wrap">
  <table class="dc-table">
    <thead><tr><th>Booking</th><th>Conflicted copy</th><th>Saved</th><th>Owner</th><th>Result</th><th>Cells</th><th><?= $isOpen ? 'First seen' : 'Closed' ?></th></tr></thead>
    <tbody>
    <?php foreach ($list as $r):
        if (!$isOpen) {
            $tag = $r['status'] === 'gone' ? ['dc-grey', 'fixed by hand'] : ['dc-auto', $r['action'] === 'merged' ? 'merged' : 'removed'];
        } elseif ($r['auto']) {
            $tag = ['dc-plan', $rep['live'] ? 'next run' : 'will be resolved'];
        } else {
            $tag = ($r['trip_end'] && $r['trip_end'] < $past) ? ['dc-grey', 'past trip'] : ['dc-fix', 'to fix'];
        } ?>
      <tr>
        <td class="dc-file"><?= $r['request_id'] ? '<a href="request_view.php?id=' . (int)$r['request_id'] . '">' . h($r['folder']) . '</a>' : h($r['folder']) ?></td>
        <td class="dc-file"><a href="<?= h($r['dropbox_url']) ?>" target="_blank" rel="noopener"><?= h($r['file']) ?></a></td>
        <td class="dc-nowrap"><?= h($r['saved_at'] ? substr($r['saved_at'], 0, 16) : '') ?></td>
        <td><?= h($r['owner'] ?? '—') ?></td>
        <td><span class="dc-tag <?= $tag[0] ?>"><?= h($tag[1]) ?></span>
            <span class="dc-why"><?= h($r['outcome_label']) ?><?= $r['reason'] ? ': ' . h($r['reason']) : '' ?><?= $r['error'] ? ' — ' . h($r['error']) : '' ?></span></td>
        <td><?php $cellTable($r); ?></td>
        <td><?= h(substr((string)($isOpen ? $r['first_seen_at'] : $r['resolved_at']), 0, 16)) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
  </div>
</div>
<?php endforeach; ?>

<?php include 'includes/footer.php'; ?>
