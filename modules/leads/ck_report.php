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

$st = $db->prepare("SELECT report_html, error, folder_name, created_at FROM ck_checks WHERE id = ?");
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
?><!doctype html>
<html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>SafariCheck — <?= htmlspecialchars((string)$c['folder_name'], ENT_QUOTES, 'UTF-8') ?></title>
<style>html,body{margin:0;height:100%;background:#f6f3f2}iframe{border:0;width:100%;height:100%;display:block}</style>
</head><body>
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
