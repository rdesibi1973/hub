<?php
/**
 * ck_tracker.php — CK tracker: confirmed bookings from booking to _CK.
 *
 * Replaces the Java BackOffice "Groups & CK → Missing CK" (MissingCK.bat): every
 * top-level folder in /001_Safari, with how long it has been in its stage, how
 * long it has been waiting for its CK since booking finished, and an urgency
 * band by days to arrival (suppliers start penalties ~90 days out):
 *   red < 60 days · amber 60–90 · grey > 90.
 * Anyone with Leads access can set / remove the _CK marker; the Hub records who.
 */
require_once 'config.php';
require_once 'dropbox_helper.php';
require_once 'includes/folder_parser.php';
require_once 'includes/ck_lib.php';
requireLogin();
$pageTitle = 'CK tracker';
$db = db();
$cu = current_user();
$uid = (int)($cu['id'] ?? 0) ?: null;
ck_ensure_schema($db);

$VIEWS = [
    'missing' => 'Missing CK',
    'booking' => 'In booking',
    'done'    => 'CK done',
    'all'     => 'All',
];
$view      = isset($VIEWS[$_GET['view'] ?? '']) ? $_GET['view'] : 'missing';
$showPast  = !empty($_GET['past']);    // include trips that already started
$showOther = !empty($_GET['other']);   // include Kenya/Uganda/… (left out by MissingCK.bat)

// ── Action: set / remove _CK ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['ck_on', 'ck_off'], true)) {
    try {
        $token = dropbox_get_access_token();
        $res   = ck_set_marker($db, $token, (int)($_POST['ck_id'] ?? 0), $_POST['action'] === 'ck_on', $uid);
        flash($res['msg'], $res['ok'] ? 'info' : 'error');
    } catch (Throwable $e) {
        flash('Error — nothing was changed: ' . $e->getMessage(), 'error');
    }
    parse_str((string)($_POST['return_qs'] ?? ''), $rq);
    $rq = array_intersect_key($rq, array_flip(['view', 'past', 'other', 'agent']));
    header('Location: ck_tracker.php' . ($rq ? '?' . http_build_query($rq) : '') . '#ck' . (int)($_POST['ck_id'] ?? 0));
    exit;
}

// ── Scan Dropbox (records any change since the last scan) ─────────────────────
$scanError = '';
try {
    ck_scan($db, dropbox_get_access_token());
} catch (Throwable $e) {
    $scanError = 'Could not read 001_Safari from Dropbox — showing the last known state. ' . $e->getMessage();
}

// ── Data ──────────────────────────────────────────────────────────────────────
$folders = $db->query("SELECT f.*, u.full_name AS ck_by_name
                       FROM ck_folders f LEFT JOIN users u ON u.id = f.ck_by
                       WHERE f.gone = 0")->fetchAll(PDO::FETCH_ASSOC);

// Requests per folder: a private safari by practice_code, a GRP by group_folder.
$byPc = []; $byGrp = [];
$rq = $db->query("SELECT r.id, r.customer_name, r.practice_code, r.group_folder, r.pax, a.name AS agent_name
                  FROM requests r LEFT JOIN agents a ON a.id = r.agent_id
                  WHERE (r.group_folder IS NOT NULL AND r.group_folder <> '')
                     OR r.dropbox_url LIKE '%001_Safari%'");
foreach ($rq->fetchAll(PDO::FETCH_ASSOC) as $r) {
    if (trim($r['group_folder'] ?? '') !== '') $byGrp[mb_strtolower(trim($r['group_folder']))][] = $r;
    elseif (trim($r['practice_code'] ?? '') !== '') $byPc[mb_strtolower(trim($r['practice_code']))][] = $r;
}
$byStem = [];
foreach ($byPc as $k => $list) $byStem[dropbox_folder_stem($k)] = array_merge($byStem[dropbox_folder_stem($k)] ?? [], $list);

$today = new DateTimeImmutable(ck_now('Y-m-d'));
$daysFrom = function (?string $dt) use ($today): ?int {
    if (!$dt) return null;
    return (int)$today->diff(new DateTimeImmutable(substr($dt, 0, 10)))->format('%r%a');
};

$rows = [];
foreach ($folders as $f) {
    $key  = mb_strtolower($f['folder_name']);
    $reqs = $byGrp[$key] ?? $byPc[$key] ?? $byStem[dropbox_folder_stem($f['folder_name'])] ?? [];
    // Sales person: the request's agent when all matched requests agree, else the folder's (…) tag.
    $reqAgents = array_values(array_unique(array_filter(array_map(fn($q) => (string)($q['agent_name'] ?? ''), $reqs))));
    $agent = count($reqAgents) === 1 ? $reqAgents[0] : ck_agent_from_name($f['folder_name']);

    $toArrival = $daysFrom($f['start_date']);           // + = days ahead, − = already started
    $band = null;
    if ($toArrival !== null && $toArrival >= 0) $band = $toArrival < 60 ? 'red' : ($toArrival <= 90 ? 'amber' : 'grey');

    $rows[] = $f + [
        'reqs'       => $reqs,
        'agent'      => $agent,
        'is_grp'     => stripos($f['folder_name'], 'GRP') !== false || isset($byGrp[$key]),
        'other'      => ck_is_other_destination($f['folder_name']),
        'to_arrival' => $toArrival,
        'band'       => $band,
        'in_stage'   => $f['stage_since'] ? -$daysFrom($f['stage_since']) : null,
        'waiting'    => $f['booking_done_at'] ? -$daysFrom($f['booking_done_at']) : null,
        'missing'    => in_array($f['stage'], CK_DONE_STAGES, true) && !(int)$f['has_ck'],
    ];
}

// Agents present (for the filter). Sellers default to their own name.
$agents = array_values(array_unique(array_filter(array_column($rows, 'agent'))));
natcasesort($agents);
$myAgent = '';
if (isLeadsRestricted()) {
    $st = $db->prepare("SELECT a.name FROM users u JOIN agents a ON a.id = u.agent_id WHERE u.id = ?");
    $st->execute([$uid]);
    $myAgent = (string)($st->fetchColumn() ?: '');
}
$agentF = array_key_exists('agent', $_GET) ? trim($_GET['agent']) : $myAgent;

// Base filter (past / other destinations / agent), then the view.
$base = array_filter($rows, function ($r) use ($showPast, $showOther, $agentF) {
    if (!$showPast && $r['to_arrival'] !== null && $r['to_arrival'] < 0) return false;
    if (!$showOther && $r['other']) return false;
    if ($agentF !== '' && strcasecmp($r['agent'], $agentF) !== 0) return false;
    return true;
});
$counts = ['red' => 0, 'amber' => 0, 'grey' => 0, 'booking' => 0, 'done' => 0];
foreach ($base as $r) {
    if ($r['missing'] && $r['band']) $counts[$r['band']]++;
    if (in_array($r['stage'], CK_BOOKING_STAGES, true)) $counts['booking']++;
    if ((int)$r['has_ck']) $counts['done']++;
}
$list = array_filter($base, fn($r) => match ($view) {
    'missing' => $r['missing'],
    'booking' => in_array($r['stage'], CK_BOOKING_STAGES, true),
    'done'    => (bool)(int)$r['has_ck'],
    default   => true,
});
usort($list, fn($a, $b) => [$a['start_date'] ?? '9999-12-31', $a['folder_name']] <=> [$b['start_date'] ?? '9999-12-31', $b['folder_name']]);

// History for the listed folders.
$events = [];
if ($list) {
    $ids = array_map(fn($r) => (int)$r['id'], $list);
    $in  = implode(',', array_fill(0, count($ids), '?'));
    $st  = $db->prepare("SELECT e.*, u.full_name AS user_name FROM ck_events e LEFT JOIN users u ON u.id = e.user_id
                         WHERE e.ck_folder_id IN ($in) ORDER BY e.created_at DESC, e.id DESC");
    $st->execute($ids);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $e) $events[(int)$e['ck_folder_id']][] = $e;
}

$STAGE_STYLE = [
    'Balance-Cash' => ['#1a3a5c', '#EAF1F8'], 'Balance' => ['#1a3a5c', '#EAF1F8'],
    'Deposit'      => ['#8a6d3b', '#fcf3e3'], 'Paid'    => ['#1A6B3A', '#E6F4EA'],
    'Progress'     => ['#6B7280', '#F3F4F6'], 'Confirmed' => ['#6B7280', '#F3F4F6'],
    'Provisional'  => ['#B26A00', '#FFF4E0'], 'Cancelled' => ['#a33', '#f7dede'],
];
$qsKeep = array_filter(['view' => $view, 'past' => $showPast ? '1' : '', 'other' => $showOther ? '1' : ''],
                       fn($v) => $v !== '');
// An explicit (even empty = "All") agent choice is kept; otherwise sellers fall back to their own.
if (array_key_exists('agent', $_GET)) $qsKeep['agent'] = $agentF;
$link = fn(array $over) => 'ck_tracker.php?' . http_build_query(array_filter(array_merge($qsKeep, $over), fn($v) => $v !== null));
$fmtD = fn(?string $d) => $d ? date('d M y', strtotime($d)) : '';
$evLabel = function (array $e): string {
    return match ($e['event']) {
        'first_seen' => 'Tracking started',
        'stage'      => 'Stage ' . ($e['from_value'] ?: '—') . ' → ' . ($e['to_value'] ?: '—'),
        'ck_set'     => '✅ CK set',
        'ck_removed' => '↩ CK removed',
        'renamed'    => 'Renamed → ' . $e['to_value'],
        'gone'       => 'Left 001_Safari',
        'back'       => 'Back in 001_Safari',
        default      => $e['event'],
    };
};

$extra_css = '
.ck-tiles{display:flex;gap:10px;flex-wrap:wrap;margin:6px 0 16px}
.ck-tile{flex:1;min-width:150px;background:#fff;border:1px solid var(--grey-lt);border-radius:10px;padding:10px 14px;text-decoration:none;color:inherit;border-left-width:5px}
.ck-tile b{display:block;font-size:1.5rem;line-height:1.2}
.ck-tile span{font-size:.72rem;color:var(--grey-mid)}
.ck-tile.red{border-left-color:#C0211B}.ck-tile.red b{color:#C0211B}
.ck-tile.amber{border-left-color:#E87722}.ck-tile.amber b{color:#E87722}
.ck-tile.grey{border-left-color:#9CA3AF}
.ck-tile.blue{border-left-color:#1a3a5c}
.ck-tile.green{border-left-color:#1A6B3A}.ck-tile.green b{color:#1A6B3A}
.ck-bar{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:12px}
.ck-tabs a{font-size:.8rem;font-weight:600;text-decoration:none;color:var(--grey-mid);padding:6px 12px;border-radius:16px;border:1px solid var(--grey-lt);background:#fff}
.ck-tabs a.on{color:#fff;background:#C0211B;border-color:#C0211B}
.ck-bar label{font-size:.78rem;color:var(--grey-dk);display:flex;gap:4px;align-items:center}
.ck-table{width:100%;border-collapse:collapse;background:#fff;border-radius:10px;overflow:hidden}
.ck-table th{text-align:left;font-size:.66rem;text-transform:uppercase;letter-spacing:.05em;color:var(--grey-mid);padding:8px 10px;border-bottom:1px solid var(--grey-lt)}
.ck-table td{padding:8px 10px;border-bottom:1px solid var(--grey-lt);font-size:.8rem;vertical-align:top}
.ck-table tr.b-red td:first-child{box-shadow:inset 4px 0 #C0211B}
.ck-table tr.b-amber td:first-child{box-shadow:inset 4px 0 #E87722}
.ck-arr b{display:block;white-space:nowrap}
.ck-arr small{font-size:.7rem;font-weight:700}
.ck-arr .red{color:#C0211B}.ck-arr .amber{color:#E87722}.ck-arr .grey{color:var(--grey-mid)}
.ck-cust{font-weight:700}
.ck-folder{font-family:monospace;font-size:.7rem;color:var(--grey-mid);word-break:break-all;margin-top:2px}
.ck-tag{font-size:.66rem;font-weight:700;border-radius:6px;padding:2px 7px;white-space:nowrap;display:inline-block}
.ck-grp{font-size:.62rem;color:#8a6d3b;background:#fcf3e3;border-radius:6px;padding:1px 5px;margin-left:4px}
.ck-sub{font-size:.7rem;color:var(--grey-mid);margin-top:3px}
.ck-wait{font-weight:700;white-space:nowrap}
.ck-wait.late{color:#C0211B}
.ck-actions a{font-size:.7rem;text-decoration:none;margin-right:8px;white-space:nowrap}
.ck-actions form{display:inline}
.ck-btn{font-size:.72rem;font-weight:700;border-radius:6px;padding:4px 10px;cursor:pointer;border:1px solid #1A6B3A;background:#1A6B3A;color:#fff;white-space:nowrap}
.ck-btn.off{background:#fff;color:#a33;border-color:#e4b9b9;font-weight:600}
.ck-hist summary{font-size:.7rem;color:var(--grey-mid);cursor:pointer;margin-top:4px}
.ck-hist ul{list-style:none;margin:4px 0 0;padding:0;font-size:.7rem;color:var(--grey-dk)}
.ck-hist li{padding:1px 0}
.ck-hist li small{color:var(--grey-mid)}
@media (max-width:760px){.ck-table thead{display:none}.ck-table td{display:block;border:0;padding:4px 10px}.ck-table tr{display:block;border-bottom:1px solid var(--grey-lt);padding:6px 0}}
';
include 'includes/header.php';
?>

<div class="page-header" style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
  <h2>✅ CK tracker <small style="font-size:.75rem;font-weight:400;color:var(--grey-mid)">— confirmed bookings in 001_Safari, from booking to CK</small></h2>
</div>

<?php if ($scanError): ?>
  <div style="background:#fbeaea;border-left:4px solid #a33;color:#a33;padding:10px 14px;border-radius:8px;margin-bottom:12px;font-size:.82rem"><?= h($scanError) ?></div>
<?php endif; ?>

<div class="ck-tiles">
  <a class="ck-tile red"   href="<?= h($link(['view' => 'missing'])) ?>"><b><?= $counts['red'] ?></b><span>No CK · arrival &lt; 60 days</span></a>
  <a class="ck-tile amber" href="<?= h($link(['view' => 'missing'])) ?>"><b><?= $counts['amber'] ?></b><span>No CK · 60–90 days (penalties)</span></a>
  <a class="ck-tile grey"  href="<?= h($link(['view' => 'missing'])) ?>"><b><?= $counts['grey'] ?></b><span>No CK · more than 90 days</span></a>
  <a class="ck-tile blue"  href="<?= h($link(['view' => 'booking'])) ?>"><b><?= $counts['booking'] ?></b><span>Still in booking (PROGRESS)</span></a>
  <a class="ck-tile green" href="<?= h($link(['view' => 'done'])) ?>"><b><?= $counts['done'] ?></b><span>CK done</span></a>
</div>

<div class="ck-bar">
  <div class="ck-tabs" style="display:flex;gap:6px;flex-wrap:wrap">
    <?php foreach ($VIEWS as $k => $label): ?>
      <a href="<?= h($link(['view' => $k])) ?>" class="<?= $view === $k ? 'on' : '' ?>"><?= h($label) ?></a>
    <?php endforeach; ?>
  </div>
  <form method="get" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-left:auto">
    <input type="hidden" name="view" value="<?= h($view) ?>">
    <label>Sales
      <select name="agent" onchange="this.form.submit()">
        <option value="">All</option>
        <?php foreach ($agents as $a): ?>
          <option value="<?= h($a) ?>" <?= strcasecmp($a, $agentF) === 0 ? 'selected' : '' ?>><?= h($a) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label title="Trips that already started"><input type="checkbox" name="past" value="1" <?= $showPast ? 'checked' : '' ?> onchange="this.form.submit()"> Started trips</label>
    <label title="Kenya, Uganda, Namibia, South Africa, Madagascar — left out of the old Missing CK list"><input type="checkbox" name="other" value="1" <?= $showOther ? 'checked' : '' ?> onchange="this.form.submit()"> Other destinations</label>
  </form>
</div>

<?php if (!$list): ?>
  <p style="color:var(--grey-mid);padding:20px">Nothing to show<?= $view === 'missing' ? ' — every booked folder has its CK 🎉' : '' ?>.</p>
<?php else: ?>
<table class="ck-table">
  <thead><tr>
    <th>Arrival</th><th>Booking</th><th>Sales</th><th>Stage</th>
    <th><?= $view === 'done' ? 'CK' : 'Waiting for CK' ?></th><th></th>
  </tr></thead>
  <tbody>
  <?php foreach ($list as $r):
      $id  = (int)$r['id'];
      $rel = ltrim(CK_BASE, '/') . '/' . $r['folder_name'];
      $st  = $STAGE_STYLE[$r['stage']] ?? ['#6B7280', '#F3F4F6'];
  ?>
    <tr id="ck<?= $id ?>" class="<?= $r['missing'] && $r['band'] ? 'b-' . $r['band'] : '' ?>">
      <td class="ck-arr">
        <b><?= h($fmtD($r['start_date'])) ?: '—' ?></b>
        <?php if ($r['to_arrival'] !== null): ?>
          <small class="<?= h($r['band'] ?? 'grey') ?>"><?= $r['to_arrival'] >= 0 ? 'in ' . $r['to_arrival'] . ' d' : 'started' ?></small>
        <?php endif; ?>
      </td>
      <td>
        <span class="ck-cust"><?= h(ck_customer_label($r['folder_name'])) ?></span><?php if ($r['is_grp']): ?><span class="ck-grp">GRP</span><?php endif; ?>
        <div class="ck-folder"><?= h($r['folder_name']) ?></div>
        <div class="ck-actions" style="margin-top:3px">
          <?php foreach ($r['reqs'] as $q): ?>
            <a href="request_view.php?id=<?= (int)$q['id'] ?>" target="_blank">🔗 <?= h($q['customer_name']) ?><?= (int)$q['pax'] ? ' · ' . (int)$q['pax'] . ' pax' : '' ?></a>
          <?php endforeach; ?>
          <a href="savannah://open?path=<?= h(implode('/', array_map('rawurlencode', explode('/', $rel)))) ?>" title="Open in Windows Explorer">📂 Open</a>
          <a href="#" data-copy="<?= h('%DROPBOX_HOME%\\' . str_replace('/', '\\', $rel)) ?>" onclick="copyPath(this);return false" title="Copy Windows path">📋 Copy path</a>
        </div>
      </td>
      <td><?= h($r['agent']) ?: '<span style="color:var(--grey-mid)">—</span>' ?></td>
      <td>
        <?php if ($r['stage']): ?>
          <span class="ck-tag" style="color:<?= $st[0] ?>;background:<?= $st[1] ?>"><?= h($r['stage']) ?></span>
        <?php else: ?><span class="ck-tag" style="color:#a33;background:#f7dede" title="No status tag in the folder name">no tag</span><?php endif; ?>
        <div class="ck-sub">
          <?= $r['in_stage'] !== null ? 'for ' . $r['in_stage'] . ' d' : '<span title="Already in this stage when tracking started">before tracking</span>' ?>
        </div>
      </td>
      <td>
        <?php if ((int)$r['has_ck']): ?>
          <span style="color:#1A6B3A;font-weight:700">✅ CK</span>
          <div class="ck-sub"><?= $r['ck_at'] ? h($fmtD($r['ck_at'])) . ($r['ck_by_name'] ? ' · ' . h($r['ck_by_name']) : ' · in Dropbox') : 'before tracking' ?></div>
        <?php elseif ($r['missing']): ?>
          <?php if ($r['waiting'] !== null): ?>
            <span class="ck-wait <?= $r['waiting'] > 7 ? 'late' : '' ?>"><?= $r['waiting'] ?> d</span>
            <div class="ck-sub">booking done <?= h($fmtD($r['booking_done_at'])) ?></div>
          <?php else: ?>
            <span class="ck-sub" title="Booking was already done when tracking started">booking done before tracking</span>
          <?php endif; ?>
        <?php else: ?>
          <span class="ck-sub">—</span>
        <?php endif; ?>
      </td>
      <td class="ck-actions" style="white-space:nowrap">
        <form method="post" onsubmit="return confirm(<?= h(json_encode(((int)$r['has_ck'] ? 'Remove the CK from ' : 'Set CK on ') . $r['folder_name'] . '?\n\nThe Dropbox folder will be renamed.')) ?>)">
          <input type="hidden" name="action" value="<?= (int)$r['has_ck'] ? 'ck_off' : 'ck_on' ?>">
          <input type="hidden" name="ck_id" value="<?= $id ?>">
          <input type="hidden" name="return_qs" value="<?= h(http_build_query($qsKeep)) ?>">
          <?php if ((int)$r['has_ck']): ?>
            <button class="ck-btn off" type="submit">↩ Remove CK</button>
          <?php else: ?>
            <button class="ck-btn" type="submit">✅ Set CK</button>
          <?php endif; ?>
        </form>
        <?php if (!empty($events[$id])): ?>
          <details class="ck-hist">
            <summary>History (<?= count($events[$id]) ?>)</summary>
            <ul>
              <?php foreach ($events[$id] as $e): ?>
                <li><small><?= h(date('d M y H:i', strtotime($e['created_at']))) ?></small> — <?= h($evLabel($e)) ?><?php if ($e['user_name']): ?> <small>· <?= h($e['user_name']) ?></small><?php endif; ?></li>
              <?php endforeach; ?>
            </ul>
          </details>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<p style="font-size:.72rem;color:var(--grey-mid);margin-top:10px">
  Tracking started on the first visit to this page: stages and CKs that already existed show “before tracking”.
  Changes made in Dropbox are picked up at the next visit (or by the scheduled scan).
</p>
<?php endif; ?>

<script>
function copyPath(el) {
  var t = el.getAttribute('data-copy');
  if (navigator.clipboard && window.isSecureContext) {
    navigator.clipboard.writeText(t).then(function () { flashCopied(el); }, function () { fallbackCopy(t, el); });
  } else { fallbackCopy(t, el); }
}
function fallbackCopy(t, el) {
  var ta = document.createElement('textarea'); ta.value = t; document.body.appendChild(ta);
  ta.select(); try { document.execCommand('copy'); flashCopied(el); } catch (e) {} document.body.removeChild(ta);
}
function flashCopied(el) { var o = el.textContent; el.textContent = '✓ Copied'; setTimeout(function(){ el.textContent = o; }, 1200); }
</script>

<?php include 'includes/footer.php'; ?>
