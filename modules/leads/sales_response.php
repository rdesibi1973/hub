<?php
/**
 * sales_response.php — how fast the sellers answer clients by email (admin only for now).
 * Same numbers as the weekly email and the Agent API sales_response_status: sr_report() in
 * includes/sales_response_service.php. Headers only: no mail body is ever shown. Page in English, emails in Italian.
 * Unanswered list: filter by seller (ua), tick the mails and send the seller a reminder (sr_send_reminder()).
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
$noLeave = !empty($_GET['exclude_leave']);   // off by default: sellers on leave must still answer
$uaId    = (int)($_GET['ua'] ?? 0) ?: $agentId;   // unanswered list: seller filter (default All, or the page's seller)
$selfUrl = function (array $set) use ($week, $agentId, $noLeave, $uaId) {
    $q = array_filter(['week' => $week, 'agent_id' => $agentId, 'exclude_leave' => $noLeave ? 1 : null, 'ua' => $uaId], function ($v) { return $v !== null; });
    return 'sales_response.php?' . http_build_query(array_filter(array_merge($q, $set), function ($v) { return $v !== null; }));
};

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'remind') {
    $to = (int)($_POST['agent_id'] ?? 0);
    if (!hash_equals(csrf_token(), (string)($_POST['csrf'] ?? ''))) {
        flash('Session expired — reload the page and try again.', 'error');
    } elseif (empty($_POST['keys']) || !is_array($_POST['keys'])) {
        flash('Tick at least one mail for the reminder.', 'error');
    } else {
        try {
            $res = sr_send_reminder($db, $to, array_map('strval', $_POST['keys']), (string)($currentUser['username'] ?: $currentUser['full_name']));
            if ($res['ok']) flash('Reminder sent to ' . $res['to'] . ' (' . $res['n'] . ' mail' . ($res['n'] === 1 ? '' : 's') . ').');
            else flash($res['error'], 'error');
        } catch (Throwable $e) {
            flash('Reminder not sent: ' . $e->getMessage(), 'error');
        }
    }
    ob_end_clean();
    header('Location: ' . $selfUrl(['ua' => $to ?: null]) . '#unanswered'); exit;
}

$rep     = sr_report($db, $week, $agentId, 4, $noLeave);
$all     = sr_sellers($db);
$ws      = $rep['week']['start'];
$cols    = sr_metric_cols('en');
$weekOpts = [];
for ($i = 0; $i < 13; $i++) $weekOpts[] = date('Y-m-d', strtotime(sr_week_start(date('Y-m-d')) . ' 12:00:00') - 7 * $i * 86400);
$scan = $rep['last_scan'];
$fh = function (?float $v) { return sr_fmt_h($v, true); };
$unanswered = array_values(array_filter($rep['unanswered'], function ($u) use ($uaId) { return !$uaId || $u['agent_id'] === $uaId; }));
$uaSeller = null;
foreach ($all as $s) if ($uaId && $s['agent_id'] === $uaId) $uaSeller = $s;
$reminded = sr_reminded($db);

include 'includes/header.php';
?>
<style>
.sr-box{margin-bottom:20px}
.sr-box h3{font-size:1rem;margin:0 0 8px}
.sr-wrap{overflow-x:auto}
.sr-table{width:100%;border-collapse:collapse;font-size:.85rem;background:#fff}
.sr-table th,.sr-table td{padding:.45rem .55rem;border-bottom:1px solid #eee;text-align:left;vertical-align:top}
.sr-table th{background:#fafafa;border-bottom:2px solid #ddd;font-weight:600;white-space:normal;letter-spacing:normal;text-transform:none;min-width:70px}
.sr-table td{white-space:nowrap}
.sr-table td.sr-subj{white-space:normal;min-width:180px;max-width:320px}
.sr-prev{color:var(--grey-mid,#888);font-size:.78rem;display:block}
.sr-tag{display:inline-block;border-radius:10px;padding:1px 8px;font-size:.72rem;font-weight:600}
a.sr-tag{text-decoration:none}
a.sr-tag:hover{outline:1px solid currentColor}
.sr-warn{background:#fff3cd;color:#7a5b00}.sr-bad{background:#fde2e1;color:#C0211B}.sr-ok{background:#e3f4e5;color:#2e7d32}
.sr-filters{display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-bottom:16px;font-size:.85rem}
.sr-head{display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-bottom:8px}
.sr-head h3{margin:0}
.sr-head form{font-size:.85rem}
.sr-actions{display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-top:10px;font-size:.85rem}
.sr-hint{color:#777;font-size:.82rem}
</style>

<div class="page-header"><h2>⏱ Sales Response — replies to clients</h2></div>

<form method="get" class="sr-filters">
  <label>Week
    <select name="week" onchange="this.form.submit()">
      <?php foreach ($weekOpts as $w): ?>
      <option value="<?= h($w) ?>" <?= $w === $ws ? 'selected' : '' ?>><?= h($w) ?> – <?= h(date('Y-m-d', strtotime($w . ' +6 days'))) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label>Seller
    <select name="agent_id" onchange="this.form.submit()">
      <option value="">All</option>
      <?php foreach ($all as $s): if (!$s['agent_id']) continue; ?>
      <option value="<?= (int)$s['agent_id'] ?>" <?= $agentId === $s['agent_id'] ? 'selected' : '' ?>><?= h($s['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label title="Leave out of the KPIs the client mails received on a seller's leave days (leave_entries)">
    <input type="checkbox" name="exclude_leave" value="1" <?= $noLeave ? 'checked' : '' ?> onchange="this.form.submit()"> Exclude leave days
  </label>
  <span>
    <?php if ($scan['status'] === 'never_run'): ?>
      <span class="sr-tag sr-warn">No scan yet</span>
    <?php elseif ($scan['status'] === 'ok'): ?>
      <span class="sr-tag sr-ok">Last scan <?= h($scan['last_good_scan']) ?></span>
    <?php else: ?>
      <span class="sr-tag sr-bad"><?= $scan['status'] === 'stale' ? 'Scan stopped' : 'Scan failed' ?></span>
      <?= h($scan['last_run']['error'] ?? ('last good scan ' . ($scan['last_good_scan'] ?? '–'))) ?>
    <?php endif; ?>
    <?php if (!$rep['live']): ?><span class="sr-tag sr-warn" title="Numbers still being checked; no daily alerts until SALES_RESPONSE_LIVE is set">STILL IN TEST PHASE</span><?php endif; ?>
  </span>
</form>

<div class="table-wrap sr-box">
  <div style="padding:14px 18px">
  <h3>Week <?= h($ws) ?> – <?= h($rep['week']['end']) ?> <span class="sr-prev" style="display:inline">(grey: the <?= (int)$rep['previous_weeks'] ?> weeks before, together)</span></h3>
  <div class="sr-wrap">
  <table class="sr-table">
    <thead><tr><th>Seller</th><?php foreach (array_keys($cols) as $c): ?><th><?= h($c) ?></th><?php endforeach; ?><th>Unanswered now</th><?php if ($noLeave): ?><th>Leave</th><?php endif; ?></tr></thead>
    <tbody>
    <?php foreach ($rep['sellers'] as $s): ?>
      <tr>
        <td><b><?= h($s['name']) ?></b><?php if ($s['mapping_error']): ?><br><span class="sr-tag sr-bad"><?= h($s['mapping_error']) ?></span><?php endif; ?></td>
        <?php foreach ($cols as $fn): ?>
        <td><?= h($fn($s['week'])) ?><?php if ($s['prev']): ?><span class="sr-prev"><?= h($fn($s['prev'])) ?></span><?php endif; ?></td>
        <?php endforeach; ?>
        <td><?php if ($s['open_unanswered']): ?><a class="sr-tag sr-bad" href="<?= h($selfUrl(['ua' => $s['agent_id']])) ?>#unanswered" title="Show <?= h($s['name']) ?>'s unanswered mails"><?= (int)$s['open_unanswered'] ?></a><?php else: ?>0<?php endif; ?></td>
        <?php if ($noLeave): ?><td><?= $s['week']['on_leave_excluded'] ? (int)$s['week']['on_leave_excluded'] . ' excluded' : '' ?></td><?php endif; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  </div>
</div>

<div class="table-wrap sr-box" id="unanswered">
  <div style="padding:14px 18px">
  <div class="sr-head">
    <h3>Client mails unanswered for more than <?= SR_THRESHOLD_H ?>h (<?= count($unanswered) ?>)</h3>
    <form method="get" action="sales_response.php#unanswered">
      <?php if ($week): ?><input type="hidden" name="week" value="<?= h($week) ?>"><?php endif; ?>
      <?php if ($agentId): ?><input type="hidden" name="agent_id" value="<?= (int)$agentId ?>"><?php endif; ?>
      <?php if ($noLeave): ?><input type="hidden" name="exclude_leave" value="1"><?php endif; ?>
      <label>Seller
        <select name="ua" onchange="this.form.submit()">
          <option value="">All</option>
          <?php foreach ($all as $s): if (!$s['agent_id'] || ($agentId && $s['agent_id'] !== $agentId)) continue; ?>
          <option value="<?= (int)$s['agent_id'] ?>" <?= $uaId === $s['agent_id'] ? 'selected' : '' ?>><?= h($s['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
    </form>
  </div>
  <?php if (!$unanswered): ?><div style="color:#777;font-size:.85rem">none</div><?php else: ?>
  <form method="post" action="sales_response.php" id="sr-remind">
  <input type="hidden" name="action" value="remind">
  <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
  <input type="hidden" name="agent_id" value="<?= (int)$uaId ?>">
  <div class="sr-wrap">
  <table class="sr-table">
    <thead><tr>
      <?php if ($uaSeller): ?><th style="min-width:0"><input type="checkbox" checked title="All / none" onclick="document.querySelectorAll('#sr-remind .sr-key').forEach(function (c) { c.checked = this.checked; }, this)"></th><?php endif; ?>
      <th>Seller</th><th>Request</th><th>Client</th><th>Subject</th><th>Last client mail</th><th>Waiting</th><th>Notes</th>
    </tr></thead>
    <tbody>
    <?php foreach ($unanswered as $u): $k = sr_unanswered_key($u); ?>
      <tr>
        <?php if ($uaSeller): ?><td><input type="checkbox" class="sr-key" name="keys[]" value="<?= h($k) ?>" checked></td><?php endif; ?>
        <td><?= h($u['seller']) ?></td>
        <td><a href="request_view.php?id=<?= (int)$u['request_id'] ?>"><?= h($u['folder'] ?: '#' . $u['request_id']) ?></a></td>
        <td><?= h($u['customer'] . ' <' . $u['client'] . '>') ?></td>
        <td class="sr-subj"><?= h($u['subject'] ?? '') ?></td>
        <td><?= h($u['last_client_at'] . ($u['client_msgs'] > 1 ? ' (' . $u['client_msgs'] . ' mails)' : '')) ?></td>
        <td><?= h($fh($u['hours_waiting'])) ?></td>
        <td><?= h(implode(', ', array_filter([$u['in_ferie'] ? 'on leave' : '', $u['ambiguous'] ? 'uncertain match' : '',
                                              isset($reminded[$k]) ? 'reminded ' . $reminded[$k] : '']))) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <div class="sr-actions">
    <?php if ($uaSeller): ?>
      <button type="submit" class="btn btn-primary"
              onclick="var n = document.querySelectorAll('#sr-remind .sr-key:checked').length; if (!n) { alert('Tick at least one mail.'); return false; } return confirm('Send a reminder to <?= h(addslashes($uaSeller['name'] . ' (' . $uaSeller['mailbox'] . ')')) ?> for ' + n + ' mail(s)?');">
        ✉ Send reminder to <?= h($uaSeller['name']) ?>
      </button>
      <span class="sr-hint">To <?= h($uaSeller['mailbox']) ?>, replies come back to <?= h(sr_cfg()['to']) ?>. Only the ticked mails are listed.</span>
    <?php else: ?>
      <span class="sr-hint">Choose a seller above (or click a number in “Unanswered now”) to send them a reminder.</span>
    <?php endif; ?>
  </div>
  </form>
  <?php endif; ?>
  </div>
</div>

<?php
$lists = [
    ['New leads this week: first reply', $rep['new_leads'],
     ['First client mail' => function ($l) { return $l['first_client_at']; },
      'First reply' => function ($l) { return $l['first_reply_at'] ?? 'no reply'; },
      'Time' => function ($l) use ($fh) { return $fh($l['hours']); },
      'From' => function ($l) { return (string)$l['answered_by']; }]],
    ['Clients silent for ' . SR_SILENT_DAYS . '+ days, no follow-up (for information)', $rep['silent'],
     ['Status' => function ($l) { return $l['status']; },
      'Our last mail' => function ($l) { return $l['last_sent_at']; },
      'Days' => function ($l) { return $l['days'] . ' d'; }]],
];
foreach ($lists as [$title, $list, $extra]): ?>
<div class="table-wrap sr-box">
  <div style="padding:14px 18px">
  <h3><?= h($title) ?></h3>
  <?php if (!$list): ?><div style="color:#777;font-size:.85rem">none</div><?php else: ?>
  <div class="sr-wrap">
  <table class="sr-table">
    <thead><tr><th>Seller</th><th>Request</th><?php foreach (array_keys($extra) as $c): ?><th><?= h($c) ?></th><?php endforeach; ?></tr></thead>
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
  </div>
  <?php endif; ?>
  </div>
</div>
<?php endforeach; ?>

<p style="font-size:.78rem;color:var(--grey-dk,#555);line-height:1.6">
  <b>Company mail only.</b> WhatsApp and phone are not visible; replies from personal Gmail addresses are not visible;
  info@ and safari@ are not read (their reply counts only if the seller is in copy); a few ambiguous matches may be wrong;
  leads from the web form have no first client mail, so their first reply cannot be measured.
  Threshold <?= SR_THRESHOLD_H ?> clock hours; evening / weekend / holiday = 18:00–08:00 EAT, Saturday, Sunday, Tanzanian public holidays.
  <?= $noLeave ? "Client mails received on a seller's leave days are left out of the KPIs." : "Leave days count like any other day (\"on leave\" is only a note); tick \"Exclude leave days\" to leave them out." ?>
</p>

<?php include 'includes/footer.php'; ?>
