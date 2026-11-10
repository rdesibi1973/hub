<?php
/**
 * modules/iti/sample_cleanup.php — clean-up of the ITI samples (10 Nov 2026), admins only.
 * Every section shows what would change (dry run, before → after); "Apply" writes that section.
 * Logic: includes/iti_sample_cleanup.php. Order: 1 → 5 (5 needs the routes of 4).
 */
ob_start();
require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_once __DIR__ . '/includes/iti_functions.php';
require_once __DIR__ . '/includes/iti_sample_cleanup.php';

if (!is_admin()) { http_response_code(403); exit('Admins only.'); }
$db = db();
iti_ensure_doc_columns();   // safari_type
iti_transfers_schema($db);  // day-transfer link columns + route labels (DDL, before any transaction)
$cu = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sec = $_POST['section'] ?? '';
    try {
        switch ($sec) {
            case 'shared': $n = iti_sc_apply_rows($db, iti_sc_plan_shared($db)); $msg = $n . ' text(s) cleaned.'; break;
            case 'titles': $n = iti_sc_apply_titles($db, iti_sc_plan_titles($db)); $msg = $n . ' field(s) updated.'; break;
            case 'nyumbu': $id = iti_sc_apply_nyumbu($db, (string)($cu['username'] ?? 'admin')); $msg = 'Private sample NYUMBU SAFARI created: #' . $id . '.'; break;
            case 'routes': $n = iti_sc_apply_routes($db); $msg = $n . ' route(s) created / updated.'; break;
            case 'link':   $n = iti_sc_apply_link($db); $msg = $n . ' day(s) linked to the transfer list.'; break;
            default: throw new RuntimeException('Unknown section.');
        }
        error_log('ITI sample_cleanup ' . $sec . ' by ' . ($cu['username'] ?? '?') . ': ' . $msg);
        iti_flash_set('success', $msg);
    } catch (Throwable $e) {
        iti_flash_set('error', 'Not applied: ' . $e->getMessage());
    }
    iti_redirect('sample_cleanup.php#' . preg_replace('/[^a-z]/', '', $sec));
}

$shared = iti_sc_plan_shared($db);
$titles = iti_sc_plan_titles($db);
$nyumbu = iti_sc_plan_nyumbu($db);
$routes = iti_sc_plan_routes($db);
$link   = iti_sc_plan_link($db);
$routesTodo = count(array_filter($routes, fn($r) => $r['new'] || $r['changes']));

$page_title = 'Sample clean-up — Itinerary Builder';
$extra_css = iti_extra_css() . '
.sc-sec{background:#fff;border:1px solid var(--grey-lt);border-radius:8px;padding:16px 20px;margin-bottom:22px;}
.sc-sec h3{margin:0 0 4px;font-size:1rem;} .sc-sec .sub{color:var(--grey-mid);font-size:.8rem;margin-bottom:10px;}
.sc-t{width:100%;border-collapse:collapse;font-size:.76rem;} .sc-t th{background:#2c2c2a;color:#fff;text-align:left;padding:5px 8px;}
.sc-t td{border-bottom:1px solid #eee;padding:5px 8px;vertical-align:top;} .sc-del{background:#fde2e2;text-decoration:line-through;}
.sc-add{background:#dff3e4;} .sc-ask{color:#a35a00;font-weight:600;} .sc-ok{color:var(--green);font-weight:600;}';
include __DIR__ . '/../../includes/layout_header.php';

/** $before with the part missing from $after struck out (one removed span: common prefix + common suffix). */
function sc_diff(string $before, string $after): string {
    $lb = strlen($before); $la = strlen($after);
    $p = 0; while ($p < $la && $before[$p] === $after[$p]) $p++;
    $s = 0; while ($s < $la - $p && $before[$lb - 1 - $s] === $after[$la - 1 - $s]) $s++;
    return h(substr($before, 0, $p)) . '<span class="sc-del">' . h(substr($before, $p, $lb - $p - $s)) . '</span>' . h(substr($before, $lb - $s));
}
function sc_apply_btn(string $sec, string $label, bool $enabled = true): string {
    return '<form method="POST" style="margin-top:10px;" onsubmit="return confirm(\'Apply: ' . h($label) . '?\')"><input type="hidden" name="section" value="' . h($sec) . '">'
         . '<button class="btn btn-red btn-sm"' . ($enabled ? '' : ' disabled') . '>Apply: ' . h($label) . '</button></form>';
}
?>
<main>
<?php iti_nav('Samples'); ?>
<?php iti_flash_render(); ?>
<div class="page-header"><div><h2>Sample clean-up</h2><div class="sub">Dry run first: nothing changes until you press Apply in a section. Apply 4 before 5.</div></div></div>

<div class="sc-sec" id="shared">
  <h3>1 · "Shared arrival transfer" sentence — private samples</h3>
  <div class="sub">Removed from every sample that is not a group safari (the documents already hide it on private safaris). Group samples keep it.</div>
  <?php if (!$shared): ?><div class="sc-ok">Nothing to clean.</div><?php else: ?>
  <table class="sc-t"><tr><th>Sample</th><th>Where</th><th>Lang</th><th>Text (struck = removed)</th></tr>
  <?php foreach ($shared as $r): ?><tr><td><?= h($r['program']) ?></td><td><?= h($r['where']) ?></td><td><?= h($r['lang']) ?></td><td><?= sc_diff($r['before'], $r['after']) ?></td></tr><?php endforeach; ?>
  </table>
  <?= sc_apply_btn('shared', count($shared) . ' texts') ?>
  <?php endif; ?>
</div>

<div class="sc-sec" id="titles">
  <h3>2 · Group samples 199 / 200 / 201 — titles, subtitle, intro</h3>
  <div class="sub">No client names or dates in the titles; the intro says "group safari" where it said "private safari".</div>
  <?php if (!$titles): ?><div class="sc-ok">Nothing to change.</div><?php else: ?>
  <table class="sc-t"><tr><th>Sample</th><th>Field</th><th>Lang</th><th>Before</th><th>After</th></tr>
  <?php foreach ($titles as $r): ?><tr><td><?= h($r['program']) ?></td><td><?= h($r['where']) ?></td><td><?= h($r['lang']) ?></td><td class="sc-del"><?= h($r['before'] !== '' ? $r['before'] : '(empty)') ?></td><td class="sc-add"><?= h($r['after']) ?></td></tr><?php endforeach; ?>
  </table>
  <?= sc_apply_btn('titles', count($titles) . ' fields') ?>
  <?php endif; ?>
</div>

<div class="sc-sec" id="nyumbu">
  <h3>3 · New private sample NYUMBU SAFARI (copy of 199)</h3>
  <?php if (!empty($nyumbu['exists'])): ?><div class="sc-ok">Already there: <?= h($nyumbu['exists']) ?></div>
  <?php elseif (!empty($nyumbu['error'])): ?><div class="sc-ask"><?= h($nyumbu['error']) ?></div>
  <?php else: ?>
  <div class="sub">Same days, lodges and activities as <?= h($nyumbu['from']) ?>; 199 stays the group sample.</div>
  <table class="sc-t"><tr><th>Set</th><th>Value</th></tr>
  <?php foreach ($nyumbu['set'] as $k => $v): ?><tr><td><?= h($k) ?></td><td class="sc-add"><?= h($v) ?></td></tr><?php endforeach; ?></table>
  <table class="sc-t" style="margin-top:8px;"><tr><th>Text</th><th>Before (199)</th><th>After</th></tr>
  <?php foreach ($nyumbu['changes'] as $c): ?><tr><td><?= h($c['what']) ?></td><td class="sc-del"><?= h($c['before']) ?></td><td class="sc-add"><?= h($c['after']) ?></td></tr><?php endforeach; ?></table>
  <?= sc_apply_btn('nyumbu', 'create NYUMBU SAFARI') ?>
  <?php endif; ?>
</div>

<div class="sc-sec" id="routes">
  <h3>4 · Transfer catalogue — durations of the sample texts, via-points, labels</h3>
  <div class="sub">One route = one duration for every programme linked to it. <span class="sc-ask">Orange</span> = your choice; change it later in Transfers &amp; Flights.</div>
  <table class="sc-t"><tr><th>Route</th><th>Change</th><th>Printed before (it / en)</th><th>Printed after (it / en)</th></tr>
  <?php foreach ($routes as $r): ?>
  <tr>
    <td><?= $r['new'] ? '<b>NEW</b> ' : '#' . (int)$r['route_id'] . ' ' ?><?= h($r['name']) ?><?php if ($r['ask']): ?><div class="sc-ask">? <?= h($r['ask']) ?></div><?php endif; ?></td>
    <td><?php if (!$r['changes'] && !$r['new']): ?><span class="sc-ok">ok</span><?php endif; ?>
      <?php foreach ($r['changes'] as $k => $c): ?><div><?= h($k) ?>: <span class="sc-del"><?= h((string)($c['before'] ?? '—')) ?></span> → <span class="sc-add"><?= h((string)$c['after']) ?></span></div><?php endforeach; ?></td>
    <td><?= $r['label_before'] ? h($r['label_before']['it']) . '<br><span class="text-muted">' . h($r['label_before']['en']) . '</span>' : '—' ?></td>
    <td><?= h($r['label_after']['it']) ?><br><span class="text-muted"><?= h($r['label_after']['en']) ?></span></td>
  </tr>
  <?php endforeach; ?>
  </table>
  <?= $routesTodo ? sc_apply_btn('routes', $routesTodo . ' routes') : '<div class="sc-ok" style="margin-top:8px;">Catalogue up to date.</div>' ?>
</div>

<div class="sc-sec" id="link">
  <h3>5 · Sample day transfers → transfer list</h3>
  <div class="sub">The free texts are replaced by the catalogue route (printed in the language of each document). Flights written inside a transfer text become a flight of the day.</div>
  <?php if ($link['missing_routes']): ?><div class="sc-ask">Apply section 4 first — routes missing: <?= h(implode(', ', $link['missing_routes'])) ?></div><?php endif; ?>
  <?php if (!$link['days']): ?><div class="sc-ok">No free-text transfer left to link.</div><?php else: ?>
  <table class="sc-t"><tr><th>Sample</th><th>Day</th><th>Before (free text, Italian only)</th><th>After: it / en</th><th>Flight added</th></tr>
  <?php foreach ($link['days'] as $d): ?>
  <tr><td><?= h($d['program']) ?></td><td><?= (int)$d['day'] ?></td>
    <td class="sc-del"><?= implode('<br>', array_map('h', $d['before'])) ?></td>
    <td class="sc-add"><?= implode('<br>', array_map(fn($a) => h($a['it']) . ($a['en'] !== '' ? ' <span class="text-muted">/ ' . h($a['en']) . '</span>' : ''), $d['after'])) ?></td>
    <td><?= h(implode(', ', $d['flights'])) ?></td></tr>
  <?php endforeach; ?>
  </table>
  <?= sc_apply_btn('link', count($link['days']) . ' days', !$link['missing_routes']) ?>
  <?php endif; ?>
  <?php if ($link['unmatched']): ?>
  <div style="margin-top:12px;font-weight:600;font-size:.82rem;">Free texts that stay (not a road transfer, or no route): translate them or replace them in the editor</div>
  <table class="sc-t"><tr><th>Sample</th><th>Day</th><th>Text</th></tr>
  <?php foreach ($link['unmatched'] as $u): ?><tr><td><?= h($u['program']) ?></td><td><?= (int)$u['day'] ?></td><td><?= h($u['text']) ?></td></tr><?php endforeach; ?></table>
  <?php endif; ?>
</div>
</main>
<?php include __DIR__ . '/../../includes/layout_footer.php'; ?>
