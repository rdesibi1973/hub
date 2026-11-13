<?php
/**
 * booking_gate.php — the booking gate from the Hub pages (logic: includes/booking_gate_service.php).
 *   GET  ?action=status&request_id=N   panel state (JSON)
 *   POST action=run      request_id     "Bookings done — run check" / "Re-run check" (booking team, manager, seller, Roberto)
 *   POST action=nudge    request_id     the open panel reads one more document of its run (no wait for the cron)
 *   POST action=override request_id, reason   "Force unlock" — Roberto (rdesibi) only
 *   GET  (no action)                    runs, documents, tokens and cost per month (admins)
 * The panel itself is assets/booking_gate.js (request view, Booking follow-up filtered on a request).
 */
ob_start();
require_once 'config.php';
requireLogin();
require_once __DIR__ . '/dropbox_helper.php';
require_once __DIR__ . '/includes/booking_gate_service.php';
$db = db();
hub_db_timezone($db);
$cu = current_user();
$action = (string)($_POST['action'] ?? $_GET['action'] ?? '');

if ($action !== '') {
    $json = function (array $d, int $code = 200) {
        if (ob_get_length()) ob_clean();
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        exit;
    };
    $rid = (int)($_POST['request_id'] ?? $_GET['request_id'] ?? 0);
    $r = $rid ? bg_request($db, $rid) : null;
    if (!$r) $json(['ok' => false, 'error' => 'Request not found'], 404);
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !hash_equals(csrf_token(), (string)($_POST['csrf'] ?? ''))) {
        $json(['ok' => false, 'error' => 'Session expired — reload the page.'], 403);
    }
    session_write_close();   // a document read can take a minute: do not lock the user's other pages
    try {
        switch ($action) {
            case 'status':
                $json(bg_status_out($db, $rid, $cu, empty($_GET['fast'])));
            case 'run':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') $json(['ok' => false, 'error' => 'POST only'], 405);
                if (!bg_can_run($db, $cu, $r)) $json(['ok' => false, 'error' => 'Only the booking team, a manager, the seller of this booking or Roberto can run the check.'], 403);
                @set_time_limit(120);
                $run = bg_start_run($db, $rid, (int)$cu['id'] ?: null, 'manual');
                $json(['ok' => true, 'run_id' => (int)$run['id'], 'existing' => !empty($run['existing'])] + bg_status_out($db, $rid, $cu, false));
            case 'nudge':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') $json(['ok' => false, 'error' => 'POST only'], 405);
                $run = bg_latest_run($db, $rid);
                if ($run && in_array($run['state'], ['queued', 'running'], true)) {
                    @set_time_limit(200);
                    bg_process($db, 20, 1, (int)$run['id']);
                }
                $json(bg_status_out($db, $rid, $cu, false));
            case 'override':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') $json(['ok' => false, 'error' => 'POST only'], 405);
                $res = bg_override($db, $rid, $cu, (string)($_POST['reason'] ?? ''));
                if (!$res['ok']) $json($res, 403);
                $json(['ok' => true] + bg_status_out($db, $rid, $cu, false));
        }
    } catch (InvalidArgumentException $e) {
        $json(['ok' => false, 'error' => $e->getMessage()], 422);
    } catch (Throwable $e) {
        $json(['ok' => false, 'error' => $e->getMessage()], 500);
    }
    $json(['ok' => false, 'error' => 'Unknown action'], 400);
}

// ── Cost page (admins) ───────────────────────────────────────────────────────
if (!in_array($cu['role_name'] ?? '', ['admin', 'manager'], true)) { http_response_code(403); die('Admins only.'); }
$rep = bg_cost_report($db);
$pageTitle = 'Booking gate';
include 'includes/header.php';
$n = fn($v) => number_format((float)$v);
?>
<style>
.bg-info{background:#fff;border-left:4px solid #3A6EA5;border-radius:6px;padding:12px 16px;margin-bottom:16px;font-size:.88rem;line-height:1.6}
.bg-t{width:100%;border-collapse:collapse;font-size:.82rem;background:#fff;margin-bottom:18px}
.bg-t th,.bg-t td{padding:.45rem .55rem;border-bottom:1px solid #eee;text-align:left}
.bg-t th{background:#fafafa;border-bottom:2px solid #ddd}
.bg-t td.n{text-align:right;font-variant-numeric:tabular-nums}
</style>
<div class="page-header"><h2>🚦 Booking gate</h2></div>
<div class="bg-info">
  The AI booking check the booking team runs before a folder leaves <b>PROGRESS</b>. Mode:
  <b><?= bg_enforce() ? 'enforced — the status change is refused until the check passes' : 'shadow — runs and reports work, the status change only warns' ?></b>
  (<code>BOOKING_GATE_ENFORCE</code> in includes/config.php). Model: <code><?= h(bg_model()) ?></code>.
  Documents already read are not sent again (<?= $n($rep['docs_from_cache']) ?> read from the cache so far).
  The cost also counts in the Hub's monthly AI limit (ITI → Settings → AI).
</div>
<table class="bg-t">
  <tr><th>Month</th><th>Runs</th><th>Passed</th><th>Blocked</th><th>Unlocked</th><th>Documents</th><th>Tokens in</th><th>Tokens out</th><th>Cost USD</th></tr>
  <?php foreach ($rep['months'] as $m): ?>
  <tr><td><?= h($m['month']) ?></td><td class="n"><?= $n($m['runs']) ?></td><td class="n"><?= $n($m['passed']) ?></td><td class="n"><?= $n($m['blocked']) ?></td>
      <td class="n"><?= $n($m['overridden']) ?></td><td class="n"><?= $n($m['docs']) ?></td><td class="n"><?= $n($m['tokens_in']) ?></td>
      <td class="n"><?= $n($m['tokens_out']) ?></td><td class="n"><?= number_format((float)$m['cost'], 2) ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$rep['months']): ?><tr><td colspan="9" class="text-muted">No run yet.</td></tr><?php endif; ?>
</table>
<h3 style="font-size:1rem">Latest runs</h3>
<table class="bg-t">
  <tr><th>#</th><th>Booking</th><th>Started</th><th>By</th><th>State</th><th>Docs</th><th>Blocking</th><th>Warnings</th><th>Tokens</th><th>USD</th></tr>
  <?php foreach ($rep['recent'] as $x): ?>
  <tr><td><?= (int)$x['id'] ?></td><td><a href="request_view.php?id=<?= (int)$x['request_id'] ?>"><?= h($x['customer_name'] ?: '#' . $x['request_id']) ?></a></td>
      <td><?= h(date('d M H:i', strtotime($x['started_at']))) ?></td><td><?= h($x['trigger_src']) ?></td><td><?= h($x['state']) ?></td>
      <td class="n"><?= (int)$x['docs_total'] ?></td><td class="n"><?= (int)$x['blocking_count'] ?></td><td class="n"><?= (int)$x['warning_count'] ?></td>
      <td class="n"><?= $n($x['tokens_in'] + $x['tokens_out']) ?></td><td class="n"><?= number_format((float)$x['cost_usd'], 3) ?></td></tr>
  <?php endforeach; ?>
</table>
<?php include 'includes/footer.php'; ?>
