<?php
/**
 * modules/iti/program_final.php?request_id=N
 * Final programme from a confirmed booking (ITI_FINAL_PROGRAMME_HANDOFF.md §5.2–5.3):
 * reads the request's Calc, shows night by night how each Calc text maps to the
 * ITI master data and to the sample's days, lets the user map unknown texts inline
 * (saved as aliases), then generates / regenerates the final programme.
 */
require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_once __DIR__ . '/includes/iti_functions.php';
require_once __DIR__ . '/includes/iti_final.php';
require_once __DIR__ . '/../leads/dropbox_constants.php';   // DROPBOX_* keys (server-only file, like iti_import_sto.php)
require_once __DIR__ . '/../leads/dropbox_helper.php';
require_once __DIR__ . '/../leads/includes/booking_service.php';
require_once __DIR__ . '/../leads/includes/calc_service.php';
iti_ensure_final_schema();

$db       = db();
$_cu      = current_user();
$can_edit = true;   // all staff since 7 Oct 2026 (was admin/manager)
$user     = $_cu['username'] ?? 'system';
$rid      = (int)($_REQUEST['request_id'] ?? 0);
$file     = trim((string)($_REQUEST['file'] ?? ''));   // one of several Calc files of the folder ('' = the highest number)
$self     = 'program_final.php?request_id=' . $rid . ($file !== '' ? '&file=' . rawurlencode($file) : '');

$st = $db->prepare('SELECT id, customer_name, practice_code, status FROM requests WHERE id = ?');
$st->execute([$rid]);
$req = $st->fetch();
if (!$req) { iti_flash_set('error', 'Hub request not found.'); iti_redirect('programs.php'); }

$lang = in_array($_REQUEST['lang'] ?? '', ITI_LANGUAGES, true) ? $_REQUEST['lang'] : 'it';

// ── Read the Calc (Dropbox) ─────────────────────────────────
$calc = null; $calcErr = '';
try {
    $calc = calc_read_request($db, $rid, $file, (string)($_REQUEST['sheet'] ?? ''));
} catch (Throwable $e) {
    $calcErr = $e->getMessage();
}
$code   = $calc ? iti_final_code_from_calc($calc['file']) : '';
$sample = $calc ? iti_final_find_sample($db, $code, $lang) : null;

// ── POST ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_edit) {
    $action = $_POST['action'] ?? '';
    $back   = $self . '&lang=' . $lang;
    try {
        if ($action === 'set_sample') {
            $sid = (int)($_POST['sample_id'] ?? 0);
            if (!$sid || $code === '') throw new RuntimeException('Choose a sample.');
            $db->prepare("UPDATE iti_programs SET hub_program_code = ? WHERE id = ? AND program_type = 'sample'")->execute([$code, $sid]);
            iti_flash_set('success', 'Sample #' . $sid . ' is now used for Calc code "' . $code . '".');
        } elseif ($action === 'map') {
            $type = $_POST['type'] ?? '';
            iti_alias_save($type, (string)($_POST['alias'] ?? ''), [
                'lodge_id'          => (int)($_POST['lodge_id'] ?? 0),
                'activity_id'       => (int)($_POST['activity_id'] ?? 0),
                'transfer_route_id' => (int)($_POST['transfer_route_id'] ?? 0),
                'flight_route_id'   => (int)($_POST['flight_route_id'] ?? 0),
            ], ($_POST['meal_basis'] ?? '') !== '' ? $_POST['meal_basis'] : null, $user);
            iti_flash_set('success', 'Alias "' . iti_alias_norm((string)$_POST['alias']) . '" saved.');
        } elseif ($action === 'generate') {
            if (!$calc) throw new RuntimeException('The Calc could not be read: ' . $calcErr);
            if (!$sample) throw new RuntimeException('Choose the sample program first.');
            $res = iti_final_generate($db, $rid, $calc, (int)$sample['id'], $lang, ['id' => $_cu['id'] ?? null, 'username' => $user]);
            iti_flash_set('success', 'Itinerary program #' . $res['program_id'] . ' generated'
                . ($res['superseded'] ? ' — it replaces #' . implode(', #', $res['superseded']) : '') . '. Review the flagged days.');
        }
    } catch (Throwable $e) {
        iti_flash_set('error', $e->getMessage());
    }
    iti_redirect($back);
}

$plan   = $calc ? iti_final_plan($db, $calc, $sample ? (int)$sample['id'] : 0) : null;
$finals = iti_final_list($db, $rid);
$active = null;
foreach ($finals as $f) {   // the active final of THIS Calc file
    if (!$f['superseded_by'] && $calc && basename((string)$f['source_calc_path']) === $calc['file']) { $active = $f; break; }
}
$multiCalc = $calc && count($calc['candidates'] ?? []) > 1;
if ($multiCalc) {   // the file picker replaces the reader's "several Calc files" warning
    $calc['warnings'] = array_values(array_filter($calc['warnings'], function ($w) { return strpos($w, 'Several Calc files') !== 0; }));
}
$calcChanged = $active && $calc && $active['source_calc_rev'] && $active['source_calc_rev'] !== $calc['calc_rev'];

$samples = []; $suggestedSample = 0;
if ($calc && !$sample) {
    $samples = $db->query("SELECT id, title_en, title_it, display_language, duration_days, hub_program_code FROM iti_programs
                            WHERE program_type = 'sample' AND status <> 'cancelled' ORDER BY title_en")->fetchAll();
    $suggestedSample = iti_final_suggest_sample($samples, $code, count($calc['days']), $lang);
}
// Pickers for inline mapping (only when something is unmapped).
$opt = ['lodge' => [], 'activity' => [], 'transfer' => [], 'flight' => []];
$optName = ['lodge' => []];   // lodge names without the destination, for the preselected suggestion
if ($plan && $plan['blocking']) {
    foreach (iti_get_lodges() as $l) { $opt['lodge'][$l['id']] = $l['name'] . ' — ' . $l['dest_name_en']; $optName['lodge'][$l['id']] = $l['name']; }
    foreach (iti_get_activities() as $a) $opt['activity'][$a['id']] = $a['name_en'] . ($a['name_it'] && $a['name_it'] !== $a['name_en'] ? ' / ' . $a['name_it'] : '');
    foreach (iti_get_transfer_routes() as $r) $opt['transfer'][$r['id']] = $r['from_name'] . ' → ' . $r['to_name'];
    foreach (iti_get_flight_routes() as $r) $opt['flight'][$r['id']] = $r['from_airport'] . ' → ' . $r['to_airport'] . ($r['operator'] ? ' (' . $r['operator'] . ')' : '');
}
$fmtD = function ($ymd) { return $ymd ? date('D d M Y', strtotime($ymd)) : '—'; };
$badge = function (string $state): string {
    $map = ['ok' => ['#e8f5e9', '#2e7d32', 'mapped'], 'nothing' => ['#eee', '#555', 'not shown'],
            'unmapped' => ['#ffebee', '#c62828', 'NOT MAPPED'], 'none' => ['#eee', '#888', '—']];
    $m = $map[$state] ?? $map['none'];
    return '<span class="badge" style="background:' . $m[0] . ';color:' . $m[1] . ';">' . $m[2] . '</span>';
};

$page_title = 'Itinerary program from Calc — ' . $req['customer_name'];
$extra_css = iti_extra_css();
include __DIR__ . '/../../includes/layout_header.php';
?>
<main>
<?php iti_nav('Programs'); ?>
<?php iti_flash_render(); ?>

<div class="page-header">
  <div>
    <h2>Itinerary program from Calc — <?= h($req['customer_name']) ?></h2>
    <div class="sub">From the booking's Calc · Hub request #<?= $rid ?> · <?= h($req['practice_code']) ?></div>
  </div>
  <a href="../leads/request_view.php?id=<?= $rid ?>" class="btn btn-outline btn-sm">← Request</a>
</div>

<?php if ($calcErr): ?>
  <div class="form-card" style="border-left:4px solid var(--red);margin-bottom:18px;">
    <strong>The Calc cannot be used yet.</strong><br><?= h($calcErr) ?>
  </div>
<?php endif; ?>

<?php if ($finals): ?>
<div class="form-card" style="margin-bottom:18px;">
  <div class="form-section-title">Programs built from the Calc of this request</div>
  <?php if ($calcChanged): ?>
    <div style="padding:8px 12px;margin-bottom:10px;border-radius:6px;background:#fff8e1;color:#8a6d00;font-size:.85rem;">
      ⚠ The Calc changed in Dropbox since the active version was generated — regenerate to include the changes.
    </div>
  <?php endif; ?>
  <div class="table-wrap"><table>
    <thead><tr><th>#</th><th>Calc</th><th>Generated</th><th>Start</th><th>State</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($finals as $f): ?>
      <tr style="<?= $f['superseded_by'] ? 'opacity:.55;' : '' ?>">
        <td>#<?= (int)$f['id'] ?></td>
        <td style="font-size:.75rem;"><?= h($f['source_calc_path'] ? basename($f['source_calc_path']) : '—') ?></td>
        <td style="font-size:.8rem;"><?= h($f['generated_at'] ?? '') ?></td>
        <td style="font-size:.8rem;"><?= $fmtD($f['start_date']) ?></td>
        <td><?= $f['superseded_by'] ? '<span class="badge">superseded by #' . (int)$f['superseded_by'] . '</span>' : '<span class="badge status-booked">active</span>' ?></td>
        <td style="white-space:nowrap;">
          <a href="program_edit.php?id=<?= (int)$f['id'] ?>" class="btn btn-outline btn-sm">✏️ Edit</a>
          <a href="program_doc.php?id=<?= (int)$f['id'] ?>" target="_blank" class="btn btn-outline btn-sm">👁 Preview</a>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php endif; ?>

<?php if ($calc): ?>
<div class="form-card" style="margin-bottom:18px;">
  <div class="form-section-title">Calc</div>
  <?php if ($multiCalc): ?>
  <form method="GET" action="program_final.php" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:8px;">
    <input type="hidden" name="request_id" value="<?= $rid ?>">
    <input type="hidden" name="lang" value="<?= h($lang) ?>">
    <span style="font-size:.85rem;"><?= count($calc['candidates']) ?> Calc files in the folder — each one gets its own program:</span>
    <select name="file" onchange="this.form.submit()" style="max-width:520px;">
      <?php foreach ($calc['candidates'] as $cf):
        $has = false;
        foreach ($finals as $f) { if (!$f['superseded_by'] && basename((string)$f['source_calc_path']) === $cf) { $has = (int)$f['id']; break; } } ?>
        <option value="<?= h($cf) ?>" <?= $cf === $calc['file'] ? 'selected' : '' ?>><?= h($cf) ?><?= $has ? ' — program #' . $has : '' ?></option>
      <?php endforeach; ?>
    </select>
    <noscript><button type="submit" class="btn btn-outline btn-sm">Open</button></noscript>
  </form>
  <?php endif; ?>
  <div style="font-size:.85rem;line-height:1.7;">
    <strong><?= h($calc['file']) ?></strong> · sheet <?= h($calc['sheet']) ?><br>
    <?= $fmtD($calc['start_date']) ?> → <?= $fmtD($calc['end_date']) ?> · <?= (int)$calc['nights'] ?> nights ·
    <?= (int)$calc['pax']['adults'] ?> adults<?= $calc['pax']['teen'] ? ', ' . (int)$calc['pax']['teen'] . ' teen' : '' ?><?= $calc['pax']['child'] ? ', ' . (int)$calc['pax']['child'] . ' children' : '' ?>
    · room: <?= h($calc['room_config'] ?: '—') ?><br>
    Guests: <?= $calc['guests_tba'] ? '<em>TBA</em>' : h(implode(', ', array_map(function ($g) { return trim($g['title'] . ' ' . $g['name']); }, $calc['guests']))) ?><br>
    Arrival: <?= h($calc['arrival'] ?: '—') ?> · Departure: <?= h($calc['departure'] ?: '—') ?>
    <?php foreach ($calc['warnings'] as $w): ?><div style="color:#8a6d00;">⚠ <?= h($w) ?></div><?php endforeach; ?>
  </div>
</div>

<div class="form-card" style="margin-bottom:18px;">
  <div class="form-section-title">Sample program <span style="font-weight:400;font-size:.8rem;color:var(--grey-mid)">Calc code “<?= h($code) ?>”</span></div>
  <?php if ($sample): ?>
    <div style="font-size:.85rem;">
      #<?= (int)$sample['id'] ?> <strong><?= h($sample['title_it'] ?: $sample['title_en']) ?></strong> (<?= (int)$sample['duration_days'] ?> days, <?= h($sample['display_language']) ?>)
      <a href="program_edit.php?id=<?= (int)$sample['id'] ?>" style="margin-left:8px;">open</a>
    </div>
  <?php elseif ($can_edit): ?>
    <form method="POST" action="<?= h($self) ?>&amp;lang=<?= h($lang) ?>" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
      <input type="hidden" name="action" value="set_sample">
      <span style="font-size:.85rem;">No sample is tagged “<?= h($code) ?>” yet — choose it once:</span>
      <select name="sample_id" style="min-width:320px;">
        <option value="">— select —</option>
        <?php foreach ($samples as $s): $sug = (int)$s['id'] === $suggestedSample; ?>
          <option value="<?= (int)$s['id'] ?>"<?= $sug ? ' selected' : '' ?>><?= $sug ? '★ ' : '' ?>#<?= (int)$s['id'] ?> <?= h($s['title_it'] ?: $s['title_en']) ?> (<?= (int)$s['duration_days'] ?>d, <?= h($s['display_language']) ?>)<?= $s['hub_program_code'] ? ' — now “' . h($s['hub_program_code']) . '”' : '' ?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="btn btn-red btn-sm">Use this sample</button>
      <?php if ($suggestedSample): ?><span style="font-size:.78rem;color:var(--grey-mid);">★ proposed: title contains “<?= h($code) ?>”<?= count($calc['days']) ? ', ' . count($calc['days']) . ' days like the Calc' : '' ?> — check it</span><?php endif; ?>
    </form>
  <?php endif; ?>
</div>

<?php if ($plan): ?>
<?php if ($plan['blocking'] && $can_edit): ?>
<div class="form-card" style="margin-bottom:18px;border-left:4px solid var(--red);">
  <div class="form-section-title">To map before generating (saved as aliases — asked only once)</div>
  <?php foreach (['lodge' => 'Hotel', 'activity' => 'Activity', 'route' => 'Route'] as $type => $lbl): ?>
    <?php foreach ($plan['unmapped'][$type] as $text): ?>
    <form method="POST" action="<?= h($self) ?>&amp;lang=<?= h($lang) ?>" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;padding:6px 0;border-bottom:1px solid var(--grey-lt);">
      <input type="hidden" name="action" value="map">
      <input type="hidden" name="type" value="<?= $type ?>">
      <input type="hidden" name="alias" value="<?= h($text) ?>">
      <span style="min-width:90px;font-size:.75rem;color:var(--grey-mid);"><?= $lbl ?></span>
      <code style="min-width:220px;"><?= h($text) ?></code>
      <?php if ($type === 'lodge'): ?>
        <select name="lodge_id" style="max-width:340px;"><?= iti_options($opt['lodge'], iti_alias_suggest($text, $optName['lodge']), '— nothing (own arrangement / not a lodge) —') ?></select>
        <select name="meal_basis"><?= iti_options(ITI_MEAL_BASIS, $plan['hints']['lodge_meal'][$text] ?? null, '— meal: lodge default —') ?></select>
      <?php elseif ($type === 'activity'): ?>
        <select name="activity_id" style="max-width:380px;"><?= iti_options($opt['activity'], iti_alias_suggest($text, $opt['activity']), '— nothing (not an activity) —') ?></select>
      <?php else: ?>
        <select name="transfer_route_id" style="max-width:260px;"><?= iti_options($opt['transfer'], null, '— no transfer —') ?></select>
        <select name="flight_route_id" style="max-width:260px;"><?= iti_options($opt['flight'], null, '— no flight —') ?></select>
      <?php endif; ?>
      <button type="submit" class="btn btn-outline btn-sm">💾 Map</button>
    </form>
    <?php endforeach; ?>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="form-card" style="margin-bottom:18px;">
  <div class="form-section-title">Night by night</div>
  <div class="table-wrap"><table>
    <thead><tr><th>Date</th><th>Calc label</th><th>Hotel → lodge</th><th>Activities</th><th>Sample day</th><th>Booked</th></tr></thead>
    <tbody>
    <?php foreach ($plan['days'] as $d): ?>
      <tr>
        <td style="white-space:nowrap;font-size:.8rem;"><?= $fmtD($d['date']) ?></td>
        <td style="font-size:.8rem;"><?= h($d['label']) ?>
          <?php if ($d['route'] && $d['sample_index'] === null): ?><br><?= $badge($d['route']['state']) ?><?php endif; ?></td>
        <td style="font-size:.8rem;">
          <?php if ($d['hotel_text'] !== ''): ?>
            <code><?= h($d['hotel_text']) ?></code> <?= $badge($d['lodge_state']) ?>
            <?php if ($d['lodge_name']): ?><br>→ <strong><?= h($d['lodge_name']) ?></strong><?= $d['meal'] ? ' · ' . h($d['meal']) : '' ?><?php endif; ?>
          <?php else: ?><span style="color:var(--grey-mid);"><?= $d['last'] ? 'departure' : '—' ?></span><?php endif; ?>
        </td>
        <td style="font-size:.8rem;">
          <?php foreach ($d['acts'] as $a): ?><div><code><?= h($a['text']) ?></code> <?= $badge($a['state']) ?></div><?php endforeach; ?>
        </td>
        <td style="font-size:.8rem;"><?= $d['sample_index'] !== null ? 'Giorno ' . ($d['sample_index'] + 1) : ($sample ? '<span class="badge" style="background:#fff8e1;color:#8a6d00;">new</span>' : '—') ?></td>
        <td style="font-size:.75rem;color:var(--grey-mid);"><?= h(trim($d['invoice'] . ' ' . $d['checked'])) ?></td>
      </tr>
      <?php if ($d['flags']): ?>
      <tr><td></td><td colspan="5" style="font-size:.75rem;color:#8a6d00;padding-top:0;"><?= h(implode(' ', $d['flags'])) ?></td></tr>
      <?php endif; ?>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php foreach ($plan['flags'] as $fl): ?><div style="font-size:.82rem;color:#8a6d00;margin-top:6px;">⚠ <?= h($fl) ?></div><?php endforeach; ?>

  <?php if ($can_edit): ?>
  <form method="POST" action="<?= h($self) ?>" style="display:flex;gap:10px;align-items:center;margin-top:16px;flex-wrap:wrap;">
    <input type="hidden" name="action" value="generate">
    <label style="font-size:.8rem;">Language
      <select name="lang"><?php foreach (ITI_LANGUAGES as $l): ?><option value="<?= $l ?>" <?= $l === $lang ? 'selected' : '' ?>><?= strtoupper($l) ?></option><?php endforeach; ?></select>
    </label>
    <?php $blocked = !$sample || $plan['blocking']; ?>
    <button type="submit" class="btn btn-red" <?= $blocked ? 'disabled title="Choose the sample and map every text first"' : '' ?>
            onclick="return confirm('<?= $active ? 'Generate a new version? The current one is kept, marked superseded.' : 'Generate the program from the Calc?' ?>')">
      <?= $active ? '↻ Regenerate from Calc' : '⚙ Generate program' ?></button>
    <?php if ($blocked): ?><span style="font-size:.8rem;color:var(--red);"><?= !$sample ? 'Choose the sample first.' : count($plan['blocking']) . ' text(s) to map above.' ?></span><?php endif; ?>
  </form>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php endif; ?>
</main>
<?php include __DIR__ . '/../../includes/layout_footer.php'; ?>
