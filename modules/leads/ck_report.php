<?php
/**
 * ck_report.php?id=N — the SafariCheck HTML report of one automatic check
 * (stored by ck_agent_api.php).
 *
 * The report itself (&raw=1) is served sandboxed: it is generated from booking
 * files, so it gets no access to the Hub session. This page frames it and
 * relays its "Correct / confirmed" ticks (postMessage) to ck_confirm.php, which
 * stores them in the booking's Dropbox folder.
 */
require_once 'config.php';
require_once 'includes/ck_lib.php';
requireLogin();
$db = db();
ck_ensure_schema($db);
$id = (int)($_GET['id'] ?? 0);

$st = $db->prepare("SELECT c.report_html, c.error, c.folder_name, c.created_at, c.trigger_src,
                           f.last_check_id, f.check_requested_at
                    FROM ck_checks c LEFT JOIN ck_folders f ON f.id = c.ck_folder_id
                    WHERE c.id = ?");
$st->execute([$id]);
$c = $st->fetch(PDO::FETCH_ASSOC);

if (!empty($_GET['raw'])) {
    // allow-top-navigation-to-custom-protocols: the report's 'Open folder' link (savannah://).
    header('Content-Security-Policy: sandbox allow-popups allow-modals allow-scripts allow-top-navigation-to-custom-protocols');
    header('X-Content-Type-Options: nosniff');
    if (!$c) { http_response_code(404); echo 'Report not found.'; exit; }
    if ($c['report_html']) { echo $c['report_html']; exit; }
    header('Content-Type: text/plain; charset=utf-8');
    echo "No report for {$c['folder_name']} ({$c['created_at']}).\n\n" . ($c['error'] ? 'Error: ' . $c['error'] : '');
    exit;
}
if (!$c) { http_response_code(404); echo 'Report not found.'; exit; }
header('X-Frame-Options: SAMEORIGIN');
// When this check ran (office time), and whether it is still the latest one.
$TRIGGERS = ['manual' => 'manual re-check', 'nightly' => 'nightly run', 'stage' => 'automatic (stage change)'];
$runAt    = date('D d M Y, H:i', strtotime($c['created_at']));
$running  = $c['check_requested_at'] && (strtotime(ck_now()) - strtotime($c['check_requested_at'])) <= 12 * 60;
$newer    = (int)$c['last_check_id'] > $id ? (int)$c['last_check_id'] : 0;
?><!doctype html>
<html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>SafariCheck — <?= htmlspecialchars((string)$c['folder_name'], ENT_QUOTES, 'UTF-8') ?></title>
<style>
html,body{margin:0;height:100%;background:#f6f3f2;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif}
body{display:flex;flex-direction:column}
.runbar{flex:0 0 auto;display:flex;gap:14px;flex-wrap:wrap;align-items:center;padding:7px 16px;font-size:13px;color:#3a2a26;background:#fff;border-bottom:1px solid #ecdede}
.runbar b{font-weight:700}
.runbar .warn{color:#9A6A00;font-weight:700}
.runbar a{color:#1a3a5c;font-weight:700}
iframe{border:0;width:100%;flex:1 1 auto;display:block}
</style>
</head><body>
<div class="runbar">
  <span>🕒 Check run <b><?= htmlspecialchars($runAt, ENT_QUOTES, 'UTF-8') ?></b> (Tanzania time)<?php
    if (!empty($c['trigger_src'])): ?> · <?= htmlspecialchars($TRIGGERS[$c['trigger_src']] ?? $c['trigger_src'], ENT_QUOTES, 'UTF-8') ?><?php endif; ?></span>
  <?php if ($running): ?>
    <span class="warn">⏳ A re-check is running — this report is from the previous run; the new one appears in the CK tracker in about 2 minutes.</span>
  <?php elseif ($newer): ?>
    <span class="warn">This is not the latest report — <a href="ck_report.php?id=<?= $newer ?>">open the latest</a>.</span>
  <?php endif; ?>
</div>
<iframe id="rep" src="ck_report.php?id=<?= $id ?>&amp;raw=1"></iframe>
<script>
(function () {
  var ID = <?= $id ?>, CSRF = <?= json_encode(csrf_token()) ?>;
  var frame = document.getElementById('rep');
  function reply(msg) { frame.contentWindow.postMessage(msg, '*'); }
  function call(opts) {
    return fetch('ck_confirm.php' + (opts ? '' : '?id=' + ID), opts || {credentials: 'same-origin'})
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.ok) throw new Error(d.msg || 'error');
        reply({type: 'sc-state', checks: d.checks});
      })
      .catch(function (e) { reply({type: 'sc-error', msg: e.message}); });
  }
  window.addEventListener('message', function (ev) {
    // Only the framed report (sandboxed: opaque origin) may talk to us.
    if (ev.source !== frame.contentWindow || !ev.data) return;
    if (ev.data.type === 'sc-ready') call();
    if (ev.data.type === 'sc-confirm') call({
      method: 'POST', credentials: 'same-origin',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({id: ID, csrf: CSRF, key: String(ev.data.key || ''),
                            fp: String(ev.data.fp || ''), title: String(ev.data.title || ''),
                            on: !!ev.data.on, note: String(ev.data.note || '')})
    });
  });
})();
</script>
</body></html>
