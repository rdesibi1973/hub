<?php
/**
 * postponed.php — safaris postponed without new dates (see includes/postpone_lib.php).
 *
 * Each row: customer, agent, original dates, postponed on, deadline for the new
 * dates and days left (red when ≤ 30 or passed), payment status, and the
 * actions: Reschedule (opens it in BackOffice), open the request / folder.
 */
require_once 'config.php';
require_once 'includes/folder_parser.php';
require_once 'includes/postpone_lib.php';
$pageTitle = 'Postponed safaris';
$db = db();

$currentUser = current_user();
if (!in_array($currentUser['role_name'] ?? '', ['admin', 'manager'], true)) {
    flash('Access denied.', 'error');
    header('Location: requests.php'); exit;
}
pp_ensure_schema($db);

$rows = $db->query("SELECT r.id, r.customer_name, r.practice_code, r.payment_status, r.postponed_at,
                           r.postpone_until, r.postponed_from, r.pp_reminded, a.name AS agent_name
                    FROM requests r LEFT JOIN agents a ON a.id = r.agent_id
                    WHERE r.postpone_until IS NOT NULL
                    ORDER BY r.postpone_until")->fetchAll(PDO::FETCH_ASSOC);
$today = strtotime(date('Y-m-d'));

include 'includes/header.php';
?>

<div class="page-header" style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
  <div>
    <h2>⏸ Postponed safaris</h2>
    <div class="sub">Waiting for the client's new dates. Folders are in <code><?= h(PP_DIR) ?></code>;
      the agent and the admins are reminded 60 and 30 days before the deadline and when it passes.</div>
  </div>
  <a href="backoffice.php" class="btn btn-outline btn-sm">← BackOffice</a>
</div>

<?php if (!$rows): ?>
  <p style="color:var(--grey-mid);padding:20px">No postponed safaris. Postpone one from BackOffice (⏸ Postpone… on a confirmed booking).</p>
<?php else: ?>
<div class="table-wrap">
<table>
  <thead><tr>
    <th>Customer</th><th>Agent</th><th>Original dates</th><th>Postponed on</th>
    <th>New dates due by</th><th>Payment</th><th></th>
  </tr></thead>
  <tbody>
  <?php foreach ($rows as $r):
      $days = (int)floor((strtotime($r['postpone_until']) - $today) / 86400);
      $col  = $days < 0 ? '#a33' : ($days <= 30 ? '#a33' : ($days <= 60 ? '#B26A00' : '#1A6B3A'));
      $back = http_build_query(['q' => $r['customer_name'], 'root' => 'All', 'open' => 'rs' . (int)$r['id']]);
  ?>
    <tr>
      <td><a href="request_view.php?id=<?= (int)$r['id'] ?>" style="font-weight:700;text-decoration:none"><?= h($r['customer_name']) ?></a>
        <div style="font-size:.68rem;color:var(--grey-mid);font-family:monospace"><?= h($r['practice_code']) ?></div></td>
      <td><?= h($r['agent_name'] ?: '—') ?></td>
      <td><?= h(pp_dates_label((string)$r['postponed_from']) ?: '—') ?></td>
      <td><?= $r['postponed_at'] ? h(date('d M Y', strtotime($r['postponed_at']))) : '—' ?></td>
      <td><b><?= h(date('d M Y', strtotime($r['postpone_until']))) ?></b>
        <div style="font-size:.72rem;font-weight:700;color:<?= $col ?>">
          <?= $days < 0 ? 'passed ' . (-$days) . ' d ago' : $days . ' d left' ?></div>
        <?php if ($r['pp_reminded']): ?><div style="font-size:.64rem;color:var(--grey-mid)">reminder sent: <?= h($r['pp_reminded']) ?></div><?php endif; ?></td>
      <td><?= h($r['payment_status'] ?: '—') ?></td>
      <td style="white-space:nowrap">
        <a href="backoffice.php?<?= h($back) ?>" class="btn btn-sm" style="background:#1a3a5c;border-color:#1a3a5c;color:#fff">📅 Reschedule…</a>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>

<?php include 'includes/footer.php'; ?>
