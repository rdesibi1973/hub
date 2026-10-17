<?php
/**
 * sales_response.php — how fast the sellers answer clients by email (admin only for now).
 * Same numbers as the weekly email and the Agent API sales_response_status: sr_report() in
 * includes/sales_response_service.php. Headers only: no mail body is ever shown.
 */
ob_start();
require_once 'config.php';
requireLogin();
if (!defined('AGENT_API_KEY') && is_file(__DIR__ . '/../../includes/config.php')) {
    require_once __DIR__ . '/../../includes/config.php';   // SALES_RESPONSE_* constants, BASE_URL
}
require_once __DIR__ . '/includes/sales_response_service.php';
$pageTitle = 'Sales Response';
$currentUser = current_user();
if (($currentUser['role_name'] ?? '') !== 'admin') {
    flash('Access denied.', 'error');
    header('Location: requests.php'); exit;
}
$db = db();

$week    = isset($_GET['week']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['week']) ? $_GET['week'] : null;
$agentId = (int)($_GET['agent_id'] ?? 0) ?: null;
$rep     = sr_report($db, $week, $agentId);
$all     = sr_sellers($db);
$ws      = $rep['week']['start'];
$cols    = sr_metric_cols();
$weekOpts = [];
for ($i = 0; $i < 13; $i++) $weekOpts[] = date('Y-m-d', strtotime(sr_week_start(date('Y-m-d')) . ' 12:00:00') - 7 * $i * 86400);
$scan = $rep['last_scan'];

include 'includes/header.php';
?>
<style>
.sr-table{width:100%;border-collapse:collapse;font-size:.85rem;background:#fff}
.sr-table th,.sr-table td{padding:.45rem .55rem;border-bottom:1px solid #eee;text-align:left;vertical-align:top}
.sr-table th{background:#fafafa;border-bottom:2px solid #ddd;font-weight:600}
.sr-prev{color:var(--grey-mid,#888);font-size:.78rem;display:block}
.sr-box{max-width:1200px;margin-bottom:20px}
.sr-box h3{font-size:1rem;margin:0 0 8px}
.sr-tag{display:inline-block;border-radius:10px;padding:1px 8px;font-size:.72rem;font-weight:600}
.sr-warn{background:#fff3cd;color:#7a5b00}.sr-bad{background:#fde2e1;color:#C0211B}.sr-ok{background:#e3f4e5;color:#2e7d32}
.sr-filters{display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-bottom:16px;font-size:.85rem}
.sr-wrap{overflow-x:auto}
</style>

<div class="page-header"><h2>⏱ Sales Response — risposte ai clienti</h2></div>

<form method="get" class="sr-filters">
  <label>Settimana
    <select name="week" onchange="this.form.submit()">
      <?php foreach ($weekOpts as $w): ?>
      <option value="<?= h($w) ?>" <?= $w === $ws ? 'selected' : '' ?>><?= h($w) ?> – <?= h(date('Y-m-d', strtotime($w . ' +6 days'))) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label>Venditore
    <select name="agent_id" onchange="this.form.submit()">
      <option value="">Tutti</option>
      <?php foreach ($all as $s): if (!$s['agent_id']) continue; ?>
      <option value="<?= (int)$s['agent_id'] ?>" <?= $agentId === $s['agent_id'] ? 'selected' : '' ?>><?= h($s['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <span>
    <?php if ($scan['status'] === 'never_run'): ?>
      <span class="sr-tag sr-warn">Nessuna scansione ancora</span>
    <?php elseif ($scan['status'] === 'ok'): ?>
      <span class="sr-tag sr-ok">Ultima scansione <?= h($scan['last_good_scan']) ?></span>
    <?php else: ?>
      <span class="sr-tag sr-bad"><?= $scan['status'] === 'stale' ? 'Scansione ferma' : 'Scansione fallita' ?></span>
      <?= h($scan['last_run']['error'] ?? ('ultima riuscita ' . ($scan['last_good_scan'] ?? '–'))) ?>
    <?php endif; ?>
    <?php if (!$rep['live']): ?><span class="sr-tag sr-warn">TEST</span><?php endif; ?>
  </span>
</form>

<div class="table-wrap sr-box sr-wrap">
  <div style="padding:14px 18px">
  <h3>Settimana <?= h($ws) ?> – <?= h($rep['week']['end']) ?> <span class="sr-prev" style="display:inline">(sotto: media delle <?= (int)$rep['previous_weeks'] ?> settimane precedenti)</span></h3>
  <table class="sr-table">
    <thead><tr><th>Venditore</th><?php foreach (array_keys($cols) as $c): ?><th><?= h($c) ?></th><?php endforeach; ?><th>Senza risposta ora</th><th>Ferie</th></tr></thead>
    <tbody>
    <?php foreach ($rep['sellers'] as $s): ?>
      <tr>
        <td><b><?= h($s['name']) ?></b><?php if ($s['mapping_error']): ?><br><span class="sr-tag sr-bad"><?= h($s['mapping_error']) ?></span><?php endif; ?></td>
        <?php foreach ($cols as $fn): ?>
        <td><?= h($fn($s['week'])) ?><?php if ($s['prev']): ?><span class="sr-prev"><?= h($fn($s['prev'])) ?></span><?php endif; ?></td>
        <?php endforeach; ?>
        <td><?= $s['open_unanswered'] ? '<span class="sr-tag sr-bad">' . (int)$s['open_unanswered'] . '</span>' : '0' ?></td>
        <td><?= $s['week']['on_leave_excluded'] ? (int)$s['week']['on_leave_excluded'] . ' escluse' : '' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>

<?php
$lists = [
    ['Mail senza risposta da più di ' . SR_THRESHOLD_H . 'h (' . count($rep['unanswered']) . ')', $rep['unanswered'],
     ['Cliente' => function ($u) { return $u['customer'] . ' <' . $u['client'] . '>'; },
      'Ultima mail cliente' => function ($u) { return $u['last_client_at'] . ($u['client_msgs'] > 1 ? ' (' . $u['client_msgs'] . ' mail)' : ''); },
      'In attesa da' => function ($u) { return sr_fmt_h($u['hours_waiting']); },
      'Note' => function ($u) { return trim(($u['in_ferie'] ? 'in ferie ' : '') . ($u['ambiguous'] ? 'abbinamento incerto' : '')); }]],
    ['Nuovi lead della settimana: prima risposta', $rep['new_leads'],
     ['Prima mail cliente' => function ($l) { return $l['first_client_at']; },
      'Prima risposta' => function ($l) { return $l['first_reply_at'] ?? 'nessuna risposta'; },
      'Tempo' => function ($l) { return sr_fmt_h($l['hours']); },
      'Da' => function ($l) { return (string)$l['answered_by']; }]],
    ['Clienti silenziosi da ' . SR_SILENT_DAYS . '+ giorni, nessun follow-up (informativo)', $rep['silent'],
     ['Stato' => function ($l) { return $l['status']; },
      'Nostra ultima mail' => function ($l) { return $l['last_sent_at']; },
      'Da' => function ($l) { return $l['days'] . ' g'; }]],
];
foreach ($lists as [$title, $list, $extra]): ?>
<div class="table-wrap sr-box sr-wrap">
  <div style="padding:14px 18px">
  <h3><?= h($title) ?></h3>
  <?php if (!$list): ?><div style="color:#777;font-size:.85rem">nessuno</div><?php else: ?>
  <table class="sr-table">
    <thead><tr><th>Venditore</th><th>Richiesta</th><?php foreach (array_keys($extra) as $c): ?><th><?= h($c) ?></th><?php endforeach; ?></tr></thead>
    <tbody>
    <?php foreach ($list as $u): ?>
      <tr>
        <td><?= h($u['seller']) ?></td>
        <td><a href="request_view.php?id=<?= (int)$u['request_id'] ?>"><?= h($u['folder'] ?: '#' . $u['request_id']) ?></a></td>
        <?php foreach ($extra as $fn): ?><td><?= h($fn($u)) ?></td><?php endforeach; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
  </div>
</div>
<?php endforeach; ?>

<p style="font-size:.78rem;color:var(--grey-dk,#555);max-width:1200px;line-height:1.6">
  <b><?= h(SR_FOOTER) ?></b> <?= h(implode(' ', SR_LIMITS)) ?>
  Soglia <?= SR_THRESHOLD_H ?> ore di calendario; sera/weekend/festivi = 18:00–08:00 EAT, sabato, domenica, festività tanzaniane.
  Le mail ricevute nei giorni di ferie del venditore sono escluse dai KPI.
</p>

<?php include 'includes/footer.php'; ?>
