<?php
/**
 * commissions.php — commissions of the commercial referrers (Promoservice, Lamprati, Elena …) per booking.
 * Logic: includes/commission_service.php (comm_report(), shared with the Agent API commissions_report).
 * Admin (Roberto): referrers (name, rate, penalties, period) and the backfill of the booking snapshots.
 */
require_once 'config.php';
require_once __DIR__ . '/includes/commission_service.php';
require_once __DIR__ . '/includes/folder_parser.php';
requireLogin();
if (!comm_can_view()) { header('Location: requests.php'); exit; }
$pageTitle = 'Commissions';
$db = db();
comm_schema($db);
$canEdit = comm_can_edit();
$errors = [];
$notice = '';
$backfillPreview = null;

// ── Admin POST: referrers, backfill ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canEdit) {
    $act = $_POST['action'] ?? '';
    if ($act === 'referrer_save') {
        $res = comm_referrer_save($db, (int)($_POST['id'] ?? 0), [
            'name' => $_POST['name'] ?? '', 'kind' => $_POST['kind'] ?? 'rep', 'default_rate' => $_POST['default_rate'] ?? 0,
            'on_penalties' => isset($_POST['on_penalties']) ? 1 : 0, 'period' => $_POST['period'] ?? 'month',
            'agency_type' => $_POST['agency_type'] ?? '', 'active' => isset($_POST['active']) ? 1 : 0]);
        if ($res['ok']) { flash('Referrer saved.'); header('Location: commissions.php?' . http_build_query(array_intersect_key($_GET, array_flip(['referrer_id', 'from', 'to', 'basis'])))); exit; }
        $errors[] = $res['error'];
    }
    if ($act === 'backfill_preview') $backfillPreview = comm_backfill($db, true);
    if ($act === 'backfill_confirm') {
        $r = comm_backfill($db, false);
        flash('Backfill done: ' . $r['agencies']['count'] . ' agencies, ' . $r['bookings']['count'] . ' bookings frozen.');
        header('Location: commissions.php'); exit;
    }
}

// ── Report ───────────────────────────────────────────────────────
$refs   = comm_referrers($db);
$refId  = (int)($_GET['referrer_id'] ?? 0);
$basis  = in_array($_GET['basis'] ?? '', COMM_BASIS, true) ? $_GET['basis'] : 'booked';
$from   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : date('Y') . '-01-01';
$to     = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '') ? $_GET['to'] : date('Y') . '-12-31';
$rep    = comm_report($db, ['referrer_id' => $refId, 'from' => $from, 'to' => $to, 'basis' => $basis]);
$fmt    = $_GET['format'] ?? '';
if ($fmt === 'csv' || $fmt === 'xlsx') {
    $name = 'commissions_' . ($refId && isset($refs[$refId]) ? preg_replace('/[^A-Za-z0-9]/', '', $refs[$refId]['name']) . '_' : '') . $from . '_' . $to . '.' . $fmt;
    if ($fmt === 'csv') { header('Content-Type: text/csv; charset=utf-8'); $body = comm_report_csv($rep); }
    else { header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'); $body = comm_report_xlsx($rep); }
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Cache-Control: no-cache, no-store');
    echo $body;
    exit;
}
$money = function ($v) { return number_format((float)$v, 2); };
$q = function (array $extra) use ($refId, $from, $to, $basis) {
    return 'commissions.php?' . http_build_query(array_merge(['referrer_id' => $refId ?: null, 'from' => $from, 'to' => $to, 'basis' => $basis], $extra));
};
include 'includes/header.php';
?>
<div class="page-header">
  <div>
    <h2>Commissions</h2>
    <div class="sub">Commercial referrers per booking — split frozen at confirmation (consultant → agency default). Base = invoices − credit notes<?= ' · ' ?>Promoservice: no commission on cancellation penalties.</div>
  </div>
</div>

<?php if ($errors): ?><div class="flash flash-error"><?= implode('<br>', array_map('h', $errors)) ?></div><?php endif; ?>

<form method="GET" style="display:flex;gap:10px;align-items:end;flex-wrap:wrap;margin-bottom:16px;font-size:.82rem;">
  <label>Referrer<br>
    <select name="referrer_id" style="padding:5px 8px;">
      <option value="">All</option>
      <?php foreach ($refs as $rf): ?><option value="<?= $rf['id'] ?>" <?= $refId === $rf['id'] ? 'selected' : '' ?>><?= h($rf['name']) ?></option><?php endforeach; ?>
    </select></label>
  <label>From<br><input type="date" name="from" value="<?= h($from) ?>" style="padding:4px 6px;"></label>
  <label>To<br><input type="date" name="to" value="<?= h($to) ?>" style="padding:4px 6px;"></label>
  <label>Date<br>
    <select name="basis" style="padding:5px 8px;">
      <?php foreach (['booked' => 'Booked (confirmation)', 'travel' => 'Travel (start)', 'paid' => 'Fully paid'] as $k => $l): ?>
        <option value="<?= $k ?>" <?= $basis === $k ? 'selected' : '' ?>><?= h($l) ?></option>
      <?php endforeach; ?>
    </select></label>
  <button type="submit" class="btn btn-red btn-sm">Show</button>
  <a class="btn btn-outline btn-sm" href="<?= h($q(['format' => 'xlsx'])) ?>">⬇ Excel</a>
  <a class="btn btn-outline btn-sm" href="<?= h($q(['format' => 'csv'])) ?>">⬇ CSV</a>
</form>

<?php if ($rep['totals']): ?>
<div class="table-wrap" style="max-width:860px;margin-bottom:18px;">
  <table>
    <thead><tr><th>Referrer</th><th>Cur</th><th style="text-align:right">Bookings</th><th style="text-align:right">Base</th><th style="text-align:right">Commission</th><th>By period</th></tr></thead>
    <tbody>
    <?php foreach ($rep['totals'] as $t): ?>
      <tr><td style="font-weight:600"><?= h($t['referrer']) ?></td><td><?= h($t['currency']) ?></td>
          <td style="text-align:right"><?= (int)$t['bookings'] ?></td><td style="text-align:right"><?= $money($t['base']) ?></td>
          <td style="text-align:right;font-weight:700"><?= $money($t['commission']) ?></td>
          <td style="font-size:.75rem;color:var(--grey-mid)"><?= h(implode(' · ', array_map(fn($p) => $p['period'] . ': ' . number_format($p['commission'], 2), $t['by_period']))) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php if ($rep['warnings']): ?>
<div class="flash flash-error" style="font-size:.8rem;">
  <?php foreach (array_slice($rep['warnings'], 0, 20) as $w): ?>
    <div>⚠ <a href="request_view.php?id=<?= (int)$w['request_id'] ?>"><?= h((string)$w['folder']) ?></a> — <?= h($w['warning']) ?></div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="table-wrap" style="margin-bottom:24px;">
  <table style="font-size:.78rem;">
    <thead><tr><th>Folder</th><th>Agency / contact</th><th><?= $basis === 'travel' ? 'Travel' : ($basis === 'paid' ? 'Paid' : 'Booked') ?></th><th>Referrer</th>
      <th>Cur</th><th style="text-align:right">Invoices</th><th style="text-align:right">Credit notes</th><th style="text-align:right">Penalty</th>
      <th style="text-align:right">Base</th><th style="text-align:right">Rate</th><th style="text-align:right">Commission</th><th></th></tr></thead>
    <tbody>
    <?php if (!$rep['rows']): ?>
      <tr><td colspan="12" style="color:var(--grey-mid);text-align:center;padding:16px;">No booking with a commission in this period.</td></tr>
    <?php endif; ?>
    <?php foreach ($rep['rows'] as $r): ?>
      <tr<?= $r['cancelled'] ? ' style="color:#888"' : '' ?>>
        <td><a href="request_view.php?id=<?= (int)$r['request_id'] ?>"><?= h((string)$r['folder']) ?></a></td>
        <td><?= h((string)$r['agency']) ?><?= $r['contact'] ? '<br><span style="color:var(--grey-mid)">' . h($r['contact']) . '</span>' : '' ?></td>
        <td style="white-space:nowrap"><?= h((string)($basis === 'travel' ? $r['travel_date'] : ($basis === 'paid' ? $r['paid_date'] : $r['booked_date']))) ?></td>
        <td><?= h($r['referrer']) ?></td>
        <td><?= h($r['currency']) ?></td>
        <td style="text-align:right" title="<?= h($r['invoices']) ?>"><?= $money($r['invoice_total']) ?></td>
        <td style="text-align:right"><?= $r['credit_notes'] ? '−' . $money($r['credit_notes']) : '' ?></td>
        <td style="text-align:right"><?= $r['penalty'] ? ($r['penalty_excluded'] ? '<span title="Excluded for this referrer">−' . $money($r['penalty']) . '</span>' : '<span style="color:var(--grey-mid)" title="Included">' . $money($r['penalty']) . '</span>') : '' ?></td>
        <td style="text-align:right"><?= $money($r['base']) ?></td>
        <td style="text-align:right"><?= h(rtrim(rtrim(number_format($r['rate'], 2, '.', ''), '0'), '.')) ?> %</td>
        <td style="text-align:right;font-weight:600"><?= $money($r['commission']) ?></td>
        <td style="font-size:.7rem;color:var(--grey-mid)"><?= $r['provisional'] ? '<span title="Not frozen yet: from ' . h($r['split_source']) . '">provisional</span> ' : '' ?><?= $r['cancelled'] ? 'cancelled ' : '' ?><?= h((string)$r['note']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div class="form-card" style="margin-bottom:24px;">
  <div class="form-section-title" style="margin-top:0">Referrers</div>
  <table style="font-size:.8rem;">
    <thead><tr><th>Name</th><th>Kind</th><th>Default %</th><th title="Commission also on cancellation penalties">On penalties</th><th>Period</th><th title="Agency type it stands for (folder suffix)">Agency type</th><th>Active</th><th></th></tr></thead>
    <tbody>
    <?php foreach (array_merge($refs, $canEdit ? [0 => ['id' => 0, 'name' => '', 'kind' => 'internal', 'default_rate' => 5, 'on_penalties' => 1, 'period' => 'month', 'agency_type' => null, 'active' => 1]] : []) as $rf): ?>
      <tr>
      <?php if ($canEdit): $fid = 'rf' . (int)$rf['id']; /* a <form> cannot wrap <td>s: inputs point to it with form="…" */ ?>
          <td><form method="POST" id="<?= $fid ?>" style="margin:0"><input type="hidden" name="action" value="referrer_save"><input type="hidden" name="id" value="<?= (int)$rf['id'] ?>">
              <input type="text" name="name" value="<?= h($rf['name']) ?>" placeholder="New referrer" style="width:150px;padding:3px 6px;"></form></td>
          <td><select name="kind" form="<?= $fid ?>"><option value="rep" <?= $rf['kind'] === 'rep' ? 'selected' : '' ?>>rep</option><option value="internal" <?= $rf['kind'] === 'internal' ? 'selected' : '' ?>>internal</option></select></td>
          <td><input type="number" name="default_rate" form="<?= $fid ?>" value="<?= h((string)(float)$rf['default_rate']) ?>" step="0.5" min="0" max="30" style="width:60px;padding:3px 4px;"></td>
          <td style="text-align:center"><input type="checkbox" name="on_penalties" form="<?= $fid ?>" <?= $rf['on_penalties'] ? 'checked' : '' ?>></td>
          <td><select name="period" form="<?= $fid ?>"><option value="month" <?= $rf['period'] === 'month' ? 'selected' : '' ?>>month</option><option value="semester" <?= $rf['period'] === 'semester' ? 'selected' : '' ?>>semester</option></select></td>
          <td><select name="agency_type" form="<?= $fid ?>"><option value="">—</option><?php foreach (['promoservice', 'lamprati'] as $t): ?><option value="<?= $t ?>" <?= $rf['agency_type'] === $t ? 'selected' : '' ?>><?= $t ?></option><?php endforeach; ?></select></td>
          <td style="text-align:center"><input type="checkbox" name="active" form="<?= $fid ?>" <?= $rf['active'] ? 'checked' : '' ?>></td>
          <td><button type="submit" form="<?= $fid ?>" class="btn btn-outline btn-sm"><?= $rf['id'] ? 'Save' : '+ Add' ?></button></td>
      <?php else: ?>
        <td><?= h($rf['name']) ?></td><td><?= h($rf['kind']) ?></td><td><?= h((string)(float)$rf['default_rate']) ?> %</td>
        <td><?= $rf['on_penalties'] ? 'yes' : 'no' ?></td><td><?= h($rf['period']) ?></td><td><?= h((string)$rf['agency_type']) ?></td><td><?= $rf['active'] ? '✓' : '✗' ?></td><td></td>
      <?php endif; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <div style="font-size:.75rem;color:var(--grey-mid);margin-top:6px;">New consultants of networks like LabTravel: “new” = no booking before <?= h(date('d M Y', strtotime(COMM_NEW_CONSULTANT_CUTOFF))) ?> (COMM_NEW_CONSULTANT_CUTOFF). Consultants and agency defaults are set on the Agencies page.</div>
</div>

<?php if ($canEdit): ?>
<div class="form-card" style="margin-bottom:24px;">
  <div class="form-section-title" style="margin-top:0">Backfill</div>
  <div style="font-size:.8rem;margin-bottom:8px;">Agencies without a default get one from their type (savannah → none); confirmed bookings without a frozen split get the agency default (LabTravel history = Promoservice 5 %).</div>
  <?php if ($backfillPreview): ?>
    <div style="font-size:.8rem;margin-bottom:8px;">
      <strong>Dry run:</strong> <?= (int)$backfillPreview['agencies']['count'] ?> agencies
      (<?= h(implode(', ', array_map(fn($k, $v) => "$k: $v", array_keys($backfillPreview['agencies']['by_label']), $backfillPreview['agencies']['by_label']))) ?>),
      <?= (int)$backfillPreview['bookings']['count'] ?> bookings
      (<?= h(implode(', ', array_map(fn($k, $v) => "$k: $v", array_keys($backfillPreview['bookings']['by_label']), $backfillPreview['bookings']['by_label']))) ?>).
    </div>
    <form method="POST" onsubmit="return confirm('Freeze the commission split of these bookings?')"><input type="hidden" name="action" value="backfill_confirm"><button class="btn btn-red btn-sm">Confirm backfill</button></form>
  <?php else: ?>
    <form method="POST"><input type="hidden" name="action" value="backfill_preview"><button class="btn btn-outline btn-sm">Dry run</button></form>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php include 'includes/footer.php'; ?>
