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
require_once __DIR__ . '/includes/iti_ai_service.php';
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
$sheetReq = trim((string)($_REQUEST['sheet'] ?? ''));  // pax sheet chosen by the user ('' = the reader decides)
// Pax sheets whose prices go in the price table (quote for 10 / 11 / 12 pax): ticked by the user
// (ps_set=1), else '*' = every pax sheet unless the Calc is a group calc or has a CONF sheet.
$psSet    = !empty($_REQUEST['ps_set']);
$psReq    = array_values(array_filter(array_map('trim', (array)($_REQUEST['price_sheets'] ?? [])), 'strlen'));
// Sample chosen for this booking only (no sample tagged with the Calc code, or a different one wanted): it is
// not saved for other Calcs — only an admin tags a sample with a code (one code = one sample, for every booking).
$sampleReq = (int)($_REQUEST['sample_id'] ?? 0);
$self     = 'program_final.php?request_id=' . $rid . ($file !== '' ? '&file=' . rawurlencode($file) : '')
          . ($sheetReq !== '' ? '&sheet=' . rawurlencode($sheetReq) : '') . ($sampleReq ? '&sample_id=' . $sampleReq : '');
if ($psSet) {
    $self .= '&ps_set=1';
    foreach ($psReq as $ps) $self .= '&price_sheets%5B%5D=' . rawurlencode($ps);
}

$st = $db->prepare('SELECT id, customer_name, practice_code, status, group_folder FROM requests WHERE id = ?');
$st->execute([$rid]);
$req = $st->fetch();
if (!$req) { iti_flash_set('error', 'Hub request not found.'); iti_redirect('programs.php'); }

$lang = in_array($_REQUEST['lang'] ?? '', ITI_LANGUAGES, true) ? $_REQUEST['lang'] : 'it';

// ── Read the Calc (Dropbox) ─────────────────────────────────
$calc = null; $calcErr = '';
$calcPick = null;   // the Calc was found but needs a choice: ['file', 'candidates', 'sheets']
try {
    $isGrp = trim((string)($req['group_folder'] ?? '')) !== '';   // GRP departure: one price only by default
    $calc = calc_read_request($db, $rid, $file, $sheetReq, $psSet ? $psReq : ($isGrp ? [] : ['*']));
} catch (CalcSheetException $e) {
    $calcErr  = $e->getMessage();
    $calcPick = $e->data;
} catch (Throwable $e) {
    $calcErr = $e->getMessage();
}
if ($calc) $calc['request_id'] = $rid;   // the staff's mappings for this booking count as mapped (iti_alias_lookup())
$isAdmin = is_admin();                   // maps for every program; the staff map for this booking only (16 Nov 2026)
// The sample is chosen from the Calc CONTENT (its nights / lodges against each sample's days), not from the file
// name: a Calc saved as 02 from 01 keeps the name but may be another trip (iti_final_pick_sample(), 16 Nov 2026).
$sf = $calc ? iti_final_sample_for($db, $calc, $lang, trim((string)($req['group_folder'] ?? '')) !== '', $sampleReq) : null;
$code         = $sf ? $sf['code'] : '';
$pick         = $sf ? $sf['pick'] : null;
$sampleAuto   = $sf ? $sf['auto'] : null;
$sample       = $sf ? $sf['sample'] : null;
$sampleOneOff = $sf ? $sf['one_off'] : false;   // picked by the user for this booking only

// Step 1: several Calc files → the user picks one. Step 2: what was read is confirmed (pax, rooms, dates, prices).
// Then sample, mappings, generate. The confirmation holds for this file, sheet and version of the Calc only.
$groupOpen    = !empty($_REQUEST['group_open']);   // group departure still filling up: participants and rooms not required
if ($calc) $calc['group_open'] = $groupOpen;
$promo        = !empty($_REQUEST['promo']);        // Eleonora Ongaro discount, ITI_FINAL_PROMO_PP per person (iti_final_price_table())
if ($calc) $calc['promo_pp'] = $promo ? ITI_FINAL_PROMO_PP : 0;
$review       = $calc ? iti_final_calc_review($calc) : null;
$mustPickFile = $calc && count($calc['candidates'] ?? []) > 1 && $file === '';
$okToken      = $calc ? iti_final_ok_token($calc) : '';
$datesMode    = in_array($_REQUEST['dates'] ?? '', ['exact', 'indicative'], true) ? $_REQUEST['dates'] : '';   // asked with the confirmation
$sellers       = $calc ? iti_final_sellers($db) : [];         // the program goes under this seller; who generates it stays in generated_by
$sellerDefault = $calc ? iti_final_request_seller($db, $rid) : '';
$sellerReq     = trim((string)($_REQUEST['seller'] ?? ''));
$dataOk       = $calc && !$mustPickFile && !$review['errors'] && !$review['open_errors'] && ($_REQUEST['ok'] ?? '') === $okToken && $datesMode !== '' && isset($sellers[$sellerReq]);
$selfBase     = $self;
if ($dataOk) {
    $self .= '&ok=' . $okToken . '&dates=' . $datesMode . '&seller=' . rawurlencode($sellerReq) . ($groupOpen ? '&group_open=1' : '') . ($promo ? '&promo=1' : '');
    $calc['dates_indicative'] = $datesMode === 'indicative';
}

$aiMapKey = 'iti_ai_map_' . $rid;   // last AI proposals of this request, until the session ends

// ── POST ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_edit) {
    $action = $_POST['action'] ?? '';
    $back   = $self . '&lang=' . $lang;
    try {
        if ($action === 'set_sample') {
            $sid = (int)($_POST['sample_id'] ?? 0);
            if (!$sid) throw new RuntimeException('Choose a sample.');
            $back = preg_replace('/&sample_id=\d+/', '', $back);
            $st = $db->prepare("SELECT id FROM iti_programs WHERE id = ? AND program_type = 'sample' AND status <> 'cancelled'");
            $st->execute([$sid]);
            if (!$st->fetchColumn()) throw new RuntimeException('Sample #' . $sid . ' not found.');
            if (!$sampleAuto || (int)$sampleAuto['id'] !== $sid) $back .= '&sample_id=' . $sid;   // this booking only
            if (is_admin() && !empty($_POST['for_all']) && $code !== '') {
                // Admin: also tag the sample with the file's code (tie-break when the content is not decisive; prices of proposals).
                $t = iti_final_tag_sample($db, $code, $sid, true, true, $user);
                iti_flash_set('success', 'Sample #' . $sid . ' used, and tagged "' . $code . '"'
                    . ($t['as'] === 'extra' ? ' (extra code: it keeps its own)' : '')
                    . ($t['removed_from'] ? ' — removed from #' . implode(', #', array_column($t['removed_from'], 'id')) : '') . '.');
            } else {
                if (!$sampleAuto) iti_final_nofit_memo($db, $calc['file'], $sid, $rid, $user, $pick['ranking'] ?? []);
                iti_flash_set('success', 'Sample #' . $sid . ' used for this booking only'
                    . (!$sampleAuto ? ' — no sample is close enough to this Calc: Roberto gets a memo.' : '.'));
            }
        } elseif ($action === 'map') {
            $type = $_POST['type'] ?? '';
            $targets = [
                'lodge_id'          => (int)($_POST['lodge_id'] ?? 0),
                'activity_id'       => (int)($_POST['activity_id'] ?? 0),
                'transfer_route_id' => (int)($_POST['transfer_route_id'] ?? 0),
                'flight_route_id'   => (int)($_POST['flight_route_id'] ?? 0),
            ];
            $meal = ($_POST['meal_basis'] ?? '') !== '' ? $_POST['meal_basis'] : null;
            // An alias applies to every future Calc with this text: only an admin saves it; the staff map for this booking.
            iti_flash_set('success', iti_final_map_text($type, (string)($_POST['alias'] ?? ''), $targets, $meal, $rid, $isAdmin, $user));
        } elseif ($action === 'generate') {
            if (!$calc) throw new RuntimeException('The Calc could not be read: ' . $calcErr);
            if (!$dataOk) throw new RuntimeException('Confirm the data read from the Calc first.');
            if (!$sample) throw new RuntimeException('Choose the sample program first.');
            $res = iti_final_generate($db, $rid, $calc, (int)$sample['id'], $lang, ['id' => $_cu['id'] ?? null, 'username' => $user, 'seller' => $sellerReq]);
            $msg = 'Itinerary program #' . $res['program_id'] . ' generated'
                 . ($res['superseded'] ? ' — it replaces #' . implode(', #', $res['superseded']) : '') . '. Review the flagged days.';
            // The client document into the request folder: PDF for a direct client, Word for an agency.
            try {
                require_once __DIR__ . '/includes/iti_program_service.php';
                $sv = iti_ps_save_client_document($db, (int)$res['program_id']);
                iti_flash_set('success', $msg . ' ' . ($sv['format'] === 'docx' ? 'Word' : 'PDF') . ' saved in Dropbox: ' . $sv['path']
                    . ($sv['overwritten'] ? ' (replaced the previous file; Dropbox keeps the old version)' : '') . '.');
            } catch (Throwable $e) {
                iti_flash_set('error', $msg . ' But the document was NOT saved in Dropbox: ' . $e->getMessage());
            }
        } elseif ($action === 'ai_map_texts') {
            if (!iti_ai_can_see()) throw new RuntimeException('The AI buttons are not enabled for your user.');
            if (!$calc) throw new RuntimeException('The Calc could not be read: ' . $calcErr);
            @set_time_limit(280);
            $p = iti_final_plan($db, $calc, $sample ? (int)$sample['id'] : 0);
            $_SESSION[$aiMapKey] = iti_ai_propose_mappings($p['unmapped'], $p['hints'], $rid);
            iti_flash_set('success', 'AI proposals ready below (' . number_format(iti_ai_run_spent(), 2) . ' USD). Nothing is saved until you press Map on each line.');
        }
    } catch (Throwable $e) {
        iti_flash_set('error', $e->getMessage());
    }
    iti_redirect($back);
}

$plan   = $calc ? iti_final_plan($db, $calc, $sample ? (int)$sample['id'] : 0) : null;
// Pre-check (no AI): everything to fix, decide, map or check, before anyone builds the program or spends AI tokens.
$pre    = $calc ? iti_final_precheck($calc, $review, $plan, $groupOpen) : null;
$aiChat = $calc && iti_ai_can_see();   // the program assistant (program_chat.php), trial from 23 Nov 2026
$finals = iti_final_list($db, $rid);
$active = null;
foreach ($finals as $f) {   // the active final of THIS Calc file
    if (!$f['superseded_by'] && $calc && basename((string)$f['source_calc_path']) === $calc['file']) { $active = $f; break; }
}
$multiCalc = $calc && count($calc['candidates'] ?? []) > 1;
$sheetList = $calc ? ($calc['sheets'] ?? []) : ($calcPick['sheets'] ?? []);
// Pickers: one form per choice (changing the file drops the sheet: another workbook has other sheets).
$fileForm = function (array $candidates, string $current) use ($rid, $lang, $finals) {
    ob_start(); ?>
  <form method="GET" action="program_final.php" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:8px;">
    <input type="hidden" name="request_id" value="<?= $rid ?>">
    <input type="hidden" name="lang" value="<?= h($lang) ?>">
    <span style="font-size:.85rem;"><?= count($candidates) ?> Calc files in the folder — each one gets its own program:</span>
    <select name="file" onchange="this.form.submit()" style="max-width:520px;">
      <?php if ($current === ''): ?><option value="">— choose the Calc —</option><?php endif; ?>
      <?php foreach ($candidates as $cf):
        $has = false;
        foreach ($finals as $f) { if (!$f['superseded_by'] && basename((string)$f['source_calc_path']) === $cf) { $has = (int)$f['id']; break; } } ?>
        <option value="<?= h($cf) ?>" <?= $cf === $current ? 'selected' : '' ?>><?= h($cf) ?><?= $has ? ' — program #' . $has : '' ?></option>
      <?php endforeach; ?>
    </select>
    <noscript><button type="submit" class="btn btn-outline btn-sm">Open</button></noscript>
  </form>
<?php return ob_get_clean();
};
$sheetForm = function (string $fileName, array $sheets, string $current) use ($rid, $lang) {
    ob_start(); ?>
  <form method="GET" action="program_final.php" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:8px;">
    <input type="hidden" name="request_id" value="<?= $rid ?>">
    <input type="hidden" name="lang" value="<?= h($lang) ?>">
    <input type="hidden" name="file" value="<?= h($fileName) ?>">
    <span style="font-size:.85rem;">Sheet of the Calc (pax option) to build the program from:</span>
    <select name="sheet" onchange="this.form.submit()" style="min-width:220px;">
      <?php if ($current === ''): ?><option value="">— choose the sheet —</option><?php endif; ?>
      <?php foreach ($sheets as $s): ?>
        <option value="<?= h($s) ?>" <?= strcasecmp(trim($s), trim($current)) === 0 ? 'selected' : '' ?>><?= h(trim($s)) ?></option>
      <?php endforeach; ?>
    </select>
    <noscript><button type="submit" class="btn btn-outline btn-sm">Open</button></noscript>
  </form>
<?php return ob_get_clean();
};
if ($multiCalc) {   // the file picker replaces the reader's "several Calc files" warning
    $calc['warnings'] = array_values(array_filter($calc['warnings'], function ($w) { return strpos($w, 'Several Calc files') !== 0; }));
}
$calcChanged = $active && $calc && $active['source_calc_rev'] && $active['source_calc_rev'] !== $calc['calc_rev'];

$changeSample = $sample && $can_edit && !empty($_GET['change_sample']);   // "change": the picker again
// Picker: the closest samples to this Calc first (with their % alike), then the others by title.
$samples = []; $fitOf = []; $suggestedSample = 0;
if ($calc && (!$sample || $changeSample)) {
    foreach ($pick['ranking'] as $r) $fitOf[$r['id']] = $r['fit'];
    $all = $db->query("SELECT id, title_en, title_it, display_language, duration_days, hub_program_code FROM iti_programs
                        WHERE program_type = 'sample' AND status <> 'cancelled' ORDER BY title_en")->fetchAll();
    usort($all, function ($a, $b) use ($fitOf) {
        return ($fitOf[$b['id']] ?? -1) <=> ($fitOf[$a['id']] ?? -1);   // stable for the rest: title order kept
    });
    $samples = $all;
    $suggestedSample = $sample ? (int)$sample['id'] : (int)($pick['ranking'][0]['id'] ?? 0);
}
// Texts the staff mapped for this booking only, waiting for the admin's approval (can still be changed here).
$localRows = $plan && array_filter($plan['local']) ? iti_sh_proposals($db, ['status' => 'pending', 'request_id' => $rid]) : [];
// Pickers for inline mapping (only when something is unmapped or mapped for this booking only).
$opt = ['lodge' => [], 'activity' => [], 'transfer' => [], 'flight' => []];
$optName = ['lodge' => []];   // lodge names without the destination, for the preselected suggestion
if ($plan && ($plan['blocking'] || $localRows)) {
    foreach (iti_get_lodges() as $l) { $opt['lodge'][$l['id']] = $l['name'] . ' — ' . $l['dest_name_en']; $optName['lodge'][$l['id']] = $l['name']; }
    foreach (iti_get_activities() as $a) $opt['activity'][$a['id']] = $a['name_en'] . ($a['name_it'] && $a['name_it'] !== $a['name_en'] ? ' / ' . $a['name_it'] : '');
    foreach (iti_get_transfer_routes() as $r) $opt['transfer'][$r['id']] = $r['from_name'] . ' → ' . $r['to_name'];
    foreach (iti_get_flight_routes() as $r) $opt['flight'][$r['id']] = $r['from_airport'] . ' → ' . $r['to_airport'] . ($r['operator'] ? ' (' . $r['operator'] . ')' : '');
}
$fmtD = function ($ymd) { return $ymd ? date('D d M Y', strtotime($ymd)) : '—'; };
$badge = function (string $state): string {
    $map = ['ok' => ['#e8f5e9', '#2e7d32', 'mapped'], 'nothing' => ['#eee', '#555', 'not shown'],
            'own' => ['#eef3fb', '#2f5597', 'own arrangement'],   // booked by the agency / clients: no voucher, meals, room
            'unmapped' => ['#ffebee', '#c62828', 'NOT MAPPED'], 'none' => ['#eee', '#888', '—'],
            'local' => ['#fff8e1', '#8a6d00', 'this booking only']];
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
    <div class="sub"><?= strcasecmp(trim((string)$req['status']), 'Booked') === 0 ? 'Booked trip' : 'Quote (sales phase, ' . h($req['status']) . ')' ?> · from the request's Calc · Hub request #<?= $rid ?> · <?= h($req['practice_code']) ?></div>
  </div>
  <a href="../leads/request_view.php?id=<?= $rid ?>" class="btn btn-outline btn-sm">← Request</a>
</div>

<?php if ($calcPick && count($calcPick['sheets']) > 0): ?>
  <div class="form-card" style="border-left:4px solid #f0ad00;margin-bottom:18px;">
    <div class="form-section-title">Choose the Calc sheet</div>
    <div style="font-size:.85rem;margin-bottom:10px;">
      <strong><?= h($calcPick['file']) ?></strong> has more than one sheet — choose the one with the option the client is getting.
      <div style="font-size:.78rem;color:var(--grey-mid);margin-top:4px;"><?= h($calcErr) ?></div>
    </div>
    <?php if (count($calcPick['candidates'] ?? []) > 1) echo $fileForm($calcPick['candidates'], $calcPick['file']); ?>
    <?= $sheetForm($calcPick['file'], $calcPick['sheets'], $sheetReq) ?>
  </div>
<?php elseif ($calcErr): ?>
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
          <a href="program_doc.php?id=<?= (int)$f['id'] ?>" target="_blank" class="btn btn-outline btn-sm">👁 Preview</a>        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php endif; ?>

<?php if ($calc && $mustPickFile): ?>
<div class="form-card" style="border-left:4px solid #f0ad00;margin-bottom:18px;">
  <div class="form-section-title">Choose the Calc</div>
  <?= $fileForm($calc['candidates'], '') ?>
</div>
<?php elseif ($calc): ?>
<?php
  $preHead = ['fix' => ['Fix in the Calc first', 'var(--red)', '✖'], 'map' => ['To map', '#c62828', '•'],
              'ask' => ['To decide', '#2f5597', '?'], 'warn' => ['To check', '#8a6d00', '⚠']];
  $preBorder = $pre['counts']['fix'] ? 'var(--red)' : ($pre['counts']['map'] || $pre['counts']['warn'] ? '#f0ad00' : '#2e7d32');
?>
<div class="form-card" id="precheck" style="margin-bottom:18px;border-left:4px solid <?= $preBorder ?>;">
  <div class="form-section-title">Pre-check of the Calc
    <span style="font-weight:400;font-size:.8rem;color:var(--grey-mid);">
      <?= $pre['counts']['fix'] ? $pre['counts']['fix'] . ' to fix in the Calc' : 'nothing to fix in the Calc' ?>
      · <?= $pre['counts']['map'] ?> to map · <?= $pre['counts']['warn'] ?> to check
    </span>
  </div>
  <?php foreach ($preHead as $lvl => $hd):
    $its = array_values(array_filter($pre['items'], function ($it) use ($lvl) { return $it['level'] === $lvl; }));
    if (!$its) continue; ?>
    <div style="font-size:.78rem;font-weight:700;color:<?= $hd[1] ?>;margin:8px 0 2px;text-transform:uppercase;letter-spacing:.03em;"><?= h($hd[0]) ?></div>
    <?php foreach ($its as $it): ?>
      <div style="font-size:.85rem;line-height:1.5;color:<?= $lvl === 'fix' ? 'var(--red)' : 'inherit' ?>;<?= $lvl === 'fix' ? 'font-weight:600;' : '' ?>"><?= $hd[2] ?> <?= h($it['text']) ?></div>
    <?php endforeach; ?>
  <?php endforeach; ?>
  <?php if ($pre['counts']['fix']): ?>
    <div style="font-size:.82rem;margin-top:8px;">Correct the Calc in Dropbox, then <a href="<?= h($self . '&lang=' . $lang . ($groupOpen ? '&group_open=1' : '')) ?>">reload this page</a>.</div>
  <?php endif; ?>
</div>

<?php if ($aiChat): ?>
<div class="form-card" id="pchat" style="margin-bottom:18px;"
     data-endpoint="program_chat.php" data-csrf="<?= h(csrf_token()) ?>" data-request="<?= $rid ?>"
     data-file="<?= h($calc['file']) ?>" data-sheet="<?= h(trim((string)$calc['sheet'])) ?>" data-lang="<?= h($lang) ?>"
     data-sample="<?= $sampleOneOff ? (int)$sample['id'] : 0 ?>" data-group-open="<?= $groupOpen ? 1 : 0 ?>"
     data-ai-ok="<?= $pre['ai_ok'] ? 1 : 0 ?>">
  <div class="form-section-title">✨ Program assistant <span class="badge" style="background:#fff8e1;color:#8a6d00;">test</span></div>
  <div class="pchat-body" style="font-size:.85rem;color:var(--grey-mid);">Loading…</div>
</div>
<script src="assets/program_chat.js?v=1"></script>
<?php endif; ?>

<div class="form-card" style="margin-bottom:18px;">
  <div class="form-section-title">Calc — check what was read</div>
  <?php if ($multiCalc) echo $fileForm($calc['candidates'], $calc['file']); ?>
  <?php if (count($sheetList) > 1) echo $sheetForm($calc['file'], $sheetList, $calc['sheet']); ?>
  <?php
    $paxSheets = array_values(array_filter($sheetList, function ($s) { return stripos($s, 'PAX') !== false; }));
    $priceOpts = $calc['price_options'] ?? [];
    $ticked = [];
    foreach ($priceOpts as $o) $ticked[] = strtolower($o['sheet']);
    if (!$priceOpts && $psSet) foreach ($psReq as $ps) $ticked[] = strtolower($ps);
  ?>
  <?php if (count($paxSheets) > 1): ?>
  <form method="GET" action="program_final.php" style="margin:4px 0 10px;padding:8px 10px;background:#fafafa;border:1px solid var(--grey-lt);border-radius:6px;">
    <input type="hidden" name="request_id" value="<?= $rid ?>">
    <input type="hidden" name="lang" value="<?= h($lang) ?>">
    <input type="hidden" name="file" value="<?= h($calc['file']) ?>">
    <input type="hidden" name="sheet" value="<?= h(trim($calc['sheet'])) ?>">
    <input type="hidden" name="ps_set" value="1">
    <div style="font-size:.85rem;margin-bottom:4px;">Prices in the programme — one row per ticked sheet (quote for several group sizes):</div>
    <div style="display:flex;gap:14px;flex-wrap:wrap;align-items:center;">
      <?php foreach ($paxSheets as $s): $isMain = strcasecmp(trim($s), trim($calc['sheet'])) === 0; ?>
        <label style="font-size:.85rem;white-space:nowrap;">
          <input type="checkbox" name="price_sheets[]" value="<?= h(trim($s)) ?>" <?= $isMain || in_array(strtolower(trim($s)), $ticked, true) ? 'checked' : '' ?> <?= $isMain ? 'disabled title="The sheet the programme is built from"' : '' ?>>
          <?= h(trim($s)) ?>
        </label>
      <?php endforeach; ?>
      <button type="submit" class="btn btn-outline btn-sm">Update prices</button>
    </div>
    <?php if (count($priceOpts) > 1): ?>
      <div style="font-size:.8rem;margin-top:6px;line-height:1.6;">
        <?php foreach (iti_final_price_options_table($priceOpts, 'en') as $row): ?>
          <div><?= h($row['label']) ?>: <strong><?= number_format($row['price'], 0, ',', '.') ?> <?= h($row['currency']) ?></strong></div>
        <?php endforeach; ?>
        <?php foreach ($priceOpts as $o): if ($o['price_to_customer'] === null || $o['price_to_customer'] <= 0): ?>
          <div style="color:#8a6d00;">⚠ <?= h($o['sheet']) ?>: no price to customer in the sheet — left out.</div>
        <?php endif; endforeach; ?>
        <?php if (trim((string)($req['group_folder'] ?? '')) !== ''): ?>
          <div style="color:#c62828;">⚠ Group departure (GRP): never “base N pax” — the group may hold other travellers. Untick the other sheets.</div>
        <?php endif; ?>
      </div>
    <?php else: ?>
      <div style="font-size:.78rem;color:var(--grey-mid);margin-top:4px;">Only the sheet <?= h(trim($calc['sheet'])) ?>: price per person and total, as for a confirmed booking.</div>
    <?php endif; ?>
  </form>
  <?php endif; ?>
  <div style="font-size:.85rem;line-height:1.7;">
    <strong><?= h($calc['file']) ?></strong> · sheet <?= h($calc['sheet']) ?>
    <?php $missing = '<strong style="color:var(--red);">missing</strong>'; $priceRows = iti_final_price_table($calc, 'en'); /* this page is in English; the program uses its own language */ ?>
    <table style="margin:8px 0;border-collapse:collapse;">
      <tr><td style="padding:2px 16px 2px 0;color:var(--grey-mid);">Adults / teenagers / children</td>
          <td><?= $groupOpen ? 'not final' : '<strong>' . (int)$calc['pax']['adults'] . ' / ' . (int)$calc['pax']['teen'] . ' / ' . (int)$calc['pax']['child'] . '</strong>' ?></td></tr>
      <?php if (!$groupOpen && trim((string)($calc['pax_text'] ?? '')) !== ''): ?>
      <tr><td style="padding:2px 16px 2px 0;color:var(--grey-mid);">Written in the Calc</td><td><?= h($calc['pax_text']) ?></td></tr>
      <?php endif; ?>
      <tr><td style="padding:2px 16px 2px 0;color:var(--grey-mid);">Room types</td>
          <td><?= $groupOpen ? 'not final' : ($calc['room_config'] !== '' ? '<strong>' . h($calc['room_config']) . '</strong>' : $missing) ?></td></tr>
      <tr><td style="padding:2px 16px 2px 0;color:var(--grey-mid);">Dates</td>
          <td><strong><?= $fmtD($calc['start_date']) ?> → <?= $fmtD($calc['end_date']) ?></strong> · <?= (int)$calc['nights'] ?> nights</td></tr>
      <tr><td style="padding:2px 16px 2px 0;color:var(--grey-mid);vertical-align:top;">On the program</td>
          <td><?php if ($priceRows): foreach ($priceRows as $pr): ?><div><?= !empty($pr['bold']) ? '<strong>' : '' ?><?= h($pr['label']) ?><?= isset($pr['price']) ? ': ' . number_format($pr['price'], 0, ',', '.') . ' ' . h($pr['currency']) : '' ?><?= !empty($pr['bold']) ? '</strong>' : '' ?></div><?php endforeach; else: ?>no price<?php endif; ?></td></tr>
    </table>
    <?php $gKnown = array_filter($calc['guests'], function ($g) { return empty($g['tba']); });
          $gTba   = count($calc['guests']) - count($gKnown); ?>
    Guests: <?= h(implode(', ', array_map(function ($g) { return trim($g['title'] . ' ' . $g['name']); }, $gKnown))) ?>
    <?= $gTba || !$calc['guests'] ? '<em>' . ($gKnown ? '+ ' : '') . ($gTba ?: '') . ' TBA</em>' : '' ?><br>
    Arrival: <?= h($calc['arrival'] ?: '—') ?> · Departure: <?= h($calc['departure'] ?: '—') ?>
    <?php if ($pre['items']): ?><div style="font-size:.78rem;color:var(--grey-mid);margin-top:4px;">Problems and warnings of the Calc: in the <a href="#precheck">pre-check</a> above.</div><?php endif; ?>
    <?php if ($review['open_errors'] && $groupOpen): ?><div style="font-size:.78rem;color:var(--grey-mid);">Group not final: <?= h(implode(' ', $review['open_errors'])) ?> — not needed yet.</div><?php endif; ?>
  </div>
  <div style="margin-top:12px;">
    <?php if ($review['errors']): ?>
      <span style="font-size:.85rem;color:var(--red);">Complete the Calc in Dropbox, then reload this page.</span>
    <?php elseif (!$dataOk): ?>
      <form method="GET" action="program_final.php">
        <input type="hidden" name="request_id" value="<?= $rid ?>">
        <input type="hidden" name="lang" value="<?= h($lang) ?>">
        <input type="hidden" name="ok" value="<?= h($okToken) ?>">
        <?php if ($file !== ''): ?><input type="hidden" name="file" value="<?= h($file) ?>"><?php endif; ?>
        <?php if ($sheetReq !== ''): ?><input type="hidden" name="sheet" value="<?= h($sheetReq) ?>"><?php endif; ?>
        <?php if ($psSet): ?><input type="hidden" name="ps_set" value="1"><?php foreach ($psReq as $ps): ?><input type="hidden" name="price_sheets[]" value="<?= h($ps) ?>"><?php endforeach; endif; ?>
        <label style="font-size:.85rem;font-weight:600;display:block;">
          <input type="checkbox" name="group_open" value="1" <?= $review['open_errors'] ? 'required' : '' ?> <?= $groupOpen ? 'checked' : '' ?>> Group departure: participants and rooms not final yet
        </label>
        <div style="font-size:.78rem;color:var(--grey-mid);margin-bottom:10px;"><?= $review['open_errors'] ? 'Not a group? Complete the Calc in Dropbox, then reload this page. ' : '' ?>When ticked, the program has no participants, no room types and no total, only the price list per person. Regenerate it when the group is final.</div>
        <label style="font-size:.85rem;font-weight:600;display:block;margin-bottom:10px;">Seller (program owner)
          <select name="seller" required style="margin-left:8px;font-weight:400;">
            <?php if (!isset($sellers[$sellerDefault])): ?><option value="">— choose the seller —</option><?php endif; ?>
            <?php foreach ($sellers as $un => $nm): ?><option value="<?= h($un) ?>" <?= $un === $sellerDefault ? 'selected' : '' ?>><?= h($nm) ?></option><?php endforeach; ?>
          </select>
        </label>
        <div style="font-size:.85rem;font-weight:600;margin-bottom:4px;">Are these dates exact or indicative?</div>
        <label style="font-size:.85rem;display:block;"><input type="radio" name="dates" value="exact" required> Exact — the documents show the date of each day</label>
        <label style="font-size:.85rem;display:block;margin-bottom:10px;"><input type="radio" name="dates" value="indicative" required> Indicative — the documents show the period marked "indicative dates", with no date day by day</label>
        <label style="font-size:.85rem;font-weight:600;display:block;margin-bottom:10px;">
          <input type="checkbox" name="promo" value="1" <?= $promo ? 'checked' : '' ?>> <?= h(ITI_FINAL_PROMO_NAME) ?> discount: −<?= (int)ITI_FINAL_PROMO_PP ?> USD per person
          <span style="font-weight:400;color:var(--grey-mid);">— prices and total on the program lowered, with a line saying so</span>
        </label>
        <button type="submit" class="btn btn-red">✓ Data is correct — continue</button>
      </form>
    <?php else: ?>
      <span style="font-size:.85rem;color:#2e7d32;font-weight:600;">✓ Data confirmed · <?= $datesMode === 'indicative' ? 'indicative' : 'exact' ?> dates · seller <?= h($sellers[$sellerReq]) ?><?= $groupOpen ? ' · group not final' : '' ?><?= $promo ? ' · ' . h(ITI_FINAL_PROMO_NAME) . ' discount' : '' ?></span>
      <a href="<?= h($selfBase . '&lang=' . $lang) ?>" style="margin-left:8px;font-size:.85rem;">change</a>
    <?php endif; ?>
  </div>
</div>

<?php if ($dataOk): ?>
<div class="form-card" style="margin-bottom:18px;">
  <div class="form-section-title">Sample program <span style="font-weight:400;font-size:.8rem;color:var(--grey-mid)">chosen from the Calc content</span></div>
  <?php if ($sample && !$changeSample): ?>
    <div style="font-size:.85rem;">
      #<?= (int)$sample['id'] ?> <strong><?= h($sample['title_it'] ?: $sample['title_en']) ?></strong> (<?= (int)$sample['duration_days'] ?> days, <?= h($sample['display_language']) ?>)
      <?php if ($sampleOneOff): ?><span class="badge" style="background:#fff8e1;color:#8a6d00;margin-left:6px;">this booking only</span><?php endif; ?>
      <a href="program_edit.php?id=<?= (int)$sample['id'] ?>" style="margin-left:8px;">open</a>
      <?php if ($can_edit): ?><a href="<?= h($self) ?>&amp;lang=<?= h($lang) ?>&amp;change_sample=1" style="margin-left:8px;">change</a><?php endif; ?>
      <div style="font-size:.78rem;color:var(--grey-mid);margin-top:4px;">
        <?php if ($sampleOneOff): ?>
          Chosen by hand<?= $sampleAuto ? ' (from the content: #' . (int)$sampleAuto['id'] . ' ' . h($pick['why']) . ')' : ' — no sample is close enough to this Calc: Roberto gets a memo' ?>.
        <?php else: ?>
          <?= h($pick['why']) ?>.
        <?php endif; ?>
        <?php if ($pick && !$pick['reliable']): ?>
          <span style="color:#8a6d00;">Only <?= (int)$pick['mapped'] ?> of <?= (int)$pick['nights'] ?> Calc hotels are mapped: map them below and the sample is checked again.</span>
        <?php endif; ?>
        <?php $others = array_values(array_filter($pick['ranking'] ?? [], function ($r) use ($sample) { return $r['id'] !== (int)$sample['id']; })); ?>
        <?php if ($others): ?><br>Next closest: <?= implode(' · ', array_map(function ($r) { return '#' . $r['id'] . ' ' . h($r['title']) . ' ' . $r['fit'] . '%'; }, array_slice($others, 0, 3))) ?><?php endif; ?>
      </div>
    </div>
  <?php elseif ($can_edit): ?>
    <form method="POST" action="<?= h($self) ?>&amp;lang=<?= h($lang) ?>" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
      <input type="hidden" name="action" value="set_sample">
      <?php if ($sample): ?>
        <span style="font-size:.85rem;">Now #<?= (int)$sample['id'] ?> — choose another sample for this booking:</span>
      <?php elseif ($pick && !$pick['reliable']): ?>
        <span style="font-size:.85rem;">Only <?= (int)$pick['mapped'] ?> of <?= (int)$pick['nights'] ?> Calc hotels are mapped, so the content cannot choose yet — map them below, or choose a sample for this booking:</span>
      <?php else: ?>
        <span style="font-size:.85rem;">No sample is close enough to this Calc (at least <?= (int)round(100 * ITI_FINAL_PICK_MIN_FIT) ?>% of its nights) — choose one for this booking (Roberto gets a memo):</span>
      <?php endif; ?>
      <select name="sample_id" style="min-width:320px;">
        <option value="">— select —</option>
        <?php foreach ($samples as $s): $sug = (int)$s['id'] === $suggestedSample; $f = $fitOf[(int)$s['id']] ?? null; ?>
          <option value="<?= (int)$s['id'] ?>"<?= $sug ? ' selected' : '' ?>><?= $f !== null ? $f . '% · ' : '' ?>#<?= (int)$s['id'] ?> <?= h($s['title_it'] ?: $s['title_en']) ?> (<?= (int)$s['duration_days'] ?>d, <?= h($s['display_language']) ?>)</option>
        <?php endforeach; ?>
      </select>
      <?php if ($isAdmin && $code !== ''): ?>
        <label style="font-size:.82rem;display:flex;gap:4px;align-items:center;" title="The Calc file name only breaks ties when the content is not decisive, and gives the prices of proposals made from the sample.">
          <input type="checkbox" name="for_all" value="1"> also tag it “<?= h($code) ?>”
        </label>
      <?php endif; ?>
      <button type="submit" class="btn btn-red btn-sm">Use this sample</button>
      <?php if ($sample): ?><a href="<?= h($self) ?>&amp;lang=<?= h($lang) ?>" class="btn btn-outline btn-sm">Cancel</a><?php endif; ?>
    </form>
  <?php endif; ?>
</div>

<?php if ($plan): ?>
<?php $aiMap = $_SESSION[$aiMapKey] ?? [];
      iti_ai_panel('calc', ['post_to' => $self . '&lang=' . $lang,
                            'idle' => ['map_texts' => array_filter($plan['unmapped']) ? '' : 'Every Calc text is already mapped.']]); ?>
<?php
// One mapping line: $pre = preselected targets (lodge_id, meal_basis, activity_id, transfer_route_id, flight_route_id).
$mapRow = function (string $type, string $text, array $pre, string $extra = '') use ($self, $lang, $opt) {
    $lbl = ['lodge' => 'Hotel', 'activity' => 'Activity', 'route' => 'Route'][$type];
    ob_start(); ?>
    <form method="POST" action="<?= h($self) ?>&amp;lang=<?= h($lang) ?>" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;padding:6px 0;border-bottom:1px solid var(--grey-lt);">
      <input type="hidden" name="action" value="map">
      <input type="hidden" name="type" value="<?= $type ?>">
      <input type="hidden" name="alias" value="<?= h($text) ?>">
      <span style="min-width:90px;font-size:.75rem;color:var(--grey-mid);"><?= $lbl ?></span>
      <code style="min-width:220px;"><?= h($text) ?></code>
      <?php if ($type === 'lodge'): ?>
        <select name="lodge_id" style="max-width:340px;"><?= iti_options($opt['lodge'], $pre['lodge_id'] ?? null, '— nothing (own arrangement / not a lodge) —') ?></select>
        <select name="meal_basis"><?= iti_options(ITI_MEAL_BASIS, $pre['meal_basis'] ?? null, '— meal: lodge default —') ?></select>
      <?php elseif ($type === 'activity'): ?>
        <select name="activity_id" style="max-width:380px;"><?= iti_options($opt['activity'], $pre['activity_id'] ?? null, '— nothing (not an activity) —') ?></select>
      <?php else: ?>
        <select name="transfer_route_id" style="max-width:260px;"><?= iti_options($opt['transfer'], $pre['transfer_route_id'] ?? null, '— no transfer —') ?></select>
        <select name="flight_route_id" style="max-width:260px;"><?= iti_options($opt['flight'], $pre['flight_route_id'] ?? null, '— no flight —') ?></select>
      <?php endif; ?>
      <button type="submit" class="btn btn-outline btn-sm">💾 <?= $extra === '' ? 'Map' : 'Change' ?></button>
      <?= $extra ?>
    </form>
<?php return ob_get_clean();
};
?>
<?php if ($plan['blocking'] && $can_edit): ?>
<div class="form-card" style="margin-bottom:18px;border-left:4px solid var(--red);">
  <div class="form-section-title">To map before generating
    <?php if ($isAdmin): ?>(saved as aliases for every program — asked only once)
    <?php else: ?><span style="font-weight:400;font-size:.8rem;">— your mapping is used for <strong>this booking only</strong>; Roberto approves it before other programs use it</span><?php endif; ?>
  </div>
  <?php foreach (['lodge', 'activity', 'route'] as $type): ?>
    <?php foreach ($plan['unmapped'][$type] as $text): $ai = $aiMap[$type][$text] ?? null;
      $pre = $type === 'lodge'
           ? ['lodge_id' => ($ai['lodge_id'] ?? 0) ?: iti_final_suggest_lodge($text, $optName['lodge'], $plan['hints']['lodge_date'][$text] ?? null), 'meal_basis' => $plan['hints']['lodge_meal'][$text] ?? null]
           : ($type === 'activity' ? ['activity_id' => ($ai['activity_id'] ?? 0) ?: iti_alias_suggest($text, $opt['activity'])]
                                   : ['transfer_route_id' => ($ai['transfer_route_id'] ?? 0) ?: null, 'flight_route_id' => ($ai['flight_route_id'] ?? 0) ?: null]);
      echo $mapRow($type, $text, $pre);
      echo iti_ai_map_note($ai) !== '' ? '<div style="margin:-4px 0 4px 98px;">' . iti_ai_map_note($ai) . '</div>' : '';
    endforeach; ?>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($localRows && $can_edit): ?>
<div class="form-card" style="margin-bottom:18px;border-left:4px solid #f0ad00;">
  <div class="form-section-title">Mapped for this booking only — waiting for Roberto's approval
    <?php if ($isAdmin): ?><a href="aliases.php#proposals" style="font-weight:400;font-size:.8rem;margin-left:8px;">approve in Aliases →</a><?php endif; ?>
  </div>
  <div style="font-size:.8rem;color:var(--grey-mid);margin-bottom:6px;">Used for this program already. Other programs keep asking until Roberto approves it.<?= $isAdmin ? ' Pressing Change here saves it for every program.' : ' Wrong? Change it here.' ?></div>
  <?php foreach ($localRows as $lr):
    echo $mapRow($lr['type'], $lr['alias'], $lr, '<span style="font-size:.75rem;color:var(--grey-mid);">now: ' . h($lr['target']) . ' · ' . h($lr['proposed_by']) . ' ' . h(substr((string)$lr['proposed_at'], 0, 10)) . '</span>');
  endforeach; ?>
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
          <?php if ($d['route'] && $d['sample_index'] === null): ?><br><?= $badge($d['route']['state']) ?><?= !empty($d['route']['local']) ? ' ' . $badge('local') : '' ?><?php endif; ?></td>
        <td style="font-size:.8rem;">
          <?php if ($d['hotel_text'] !== ''): ?>
            <code><?= h($d['hotel_text']) ?></code> <?= $badge($d['lodge_state']) ?><?= !empty($d['lodge_local']) ? ' ' . $badge('local') : '' ?><?= !empty($d['own_arrangement']) && $d['lodge_state'] !== 'own' ? ' ' . $badge('own') : '' ?>
            <?php if ($d['lodge_state'] === 'own' && $d['lodge_custom']): ?><br>→ <?= h($d['lodge_custom']) ?> <span style="color:var(--grey-mid);">(no voucher, meals, room)</span><?php endif; ?>
            <?php if ($d['lodge_name']): ?><br>→ <strong><?= h($d['lodge_name']) ?></strong><?= $d['meal'] ? ' · ' . h($d['meal']) : '' ?><?= !empty($d['room_category']) ? ' · ' . h($d['room_category']) : '' ?><?php endif; ?>
          <?php else: ?><span style="color:var(--grey-mid);"><?= $d['last'] ? 'departure' : '—' ?></span><?php endif; ?>
        </td>
        <td style="font-size:.8rem;">
          <?php foreach ($d['acts'] as $a): ?><div><code><?= h($a['text']) ?></code> <?= $badge($a['state']) ?><?= !empty($a['local']) ? ' ' . $badge('local') : '' ?></div><?php endforeach; ?>
        </td>
        <td style="font-size:.8rem;"><?= $d['sample_index'] !== null ? 'Day ' . ($d['sample_index'] + 1) : ($sample ? '<span class="badge" style="background:#fff8e1;color:#8a6d00;">new</span>' : '—') ?></td>
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
            onclick="return confirm('<?= $active ? 'Generate a new version? The current one is kept, marked superseded. The program in the Dropbox folder (PDF for a direct client, Word for an agency) is replaced.' : 'Generate the program from the Calc? It is also saved in the Dropbox folder: PDF for a direct client, Word for an agency.' ?>')">
      <?= $active ? '↻ Regenerate from Calc' : '⚙ Generate program' ?></button>
    <?php if ($blocked): ?><span style="font-size:.8rem;color:var(--red);"><?= !$sample ? 'Choose the sample first.' : count($plan['blocking']) . ' text(s) to map above.' ?></span><?php endif; ?>
  </form>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php endif; ?>
<?php endif; ?>
</main>
<?php include __DIR__ . '/../../includes/layout_footer.php'; ?>
