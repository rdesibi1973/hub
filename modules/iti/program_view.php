<?php
/**
 * modules/iti/program_view.php
 * Preview interna del programma — read only
 */
require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_once __DIR__ . '/includes/iti_functions.php';

$id = (int)($_GET['id'] ?? 0);
if (!$id) { iti_flash_set('error','No program specified.'); iti_redirect('programs.php'); }

$program = iti_get_program($id);
if (!$program) { iti_flash_set('error','Program not found.'); iti_redirect('programs.php'); }

$days       = iti_get_program_days($id);
$prices     = iti_get_program_prices($id);
$inclusions = iti_get_program_inclusions($id);
$included   = array_filter($inclusions, fn($i) => $i['item_type'] === 'inclusion');
$excluded   = array_filter($inclusions, fn($i) => $i['item_type'] === 'exclusion');

$lang = $_GET['lang'] ?? $program['display_language'] ?? 'en';
if (!in_array($lang, ITI_LANGS)) $lang = 'en';

$curr = $_GET['curr'] ?? $program['display_currency'] ?? 'USD';
if (!in_array($curr, ITI_CURRENCIES)) $curr = 'USD';

// Consulente (owner del programma): foto + bio + contatti
$consultant     = iti_get_consultant($program['created_by'] ?? '');
$consultant_bio = $consultant ? iti_consultant_bio($consultant, $lang) : '';

// T&C
$tc = null;
if ($program['terms_id']) {
    $s = db()->prepare('SELECT * FROM iti_terms_conditions WHERE id=?');
    $s->execute([$program['terms_id']]);
    $tc = $s->fetch();
}

$page_title = 'Preview: ' . $program['title_en'];
include __DIR__ . '/../../includes/layout_header.php';
?>

<style>
.prev-wrap   { max-width:800px; margin:0 auto; }
.prev-hero   { background:url('https://www.savannahexplorers.com/img/leoni-safari.jpg') center/cover no-repeat;
               background-color:var(--red);
               color:var(--white); border-radius:12px;
               padding:40px 48px; margin-bottom:32px; position:relative; overflow:hidden; }
.prev-hero h1{ font-family:'Merriweather',serif; font-size:1.6rem; font-weight:700;
               margin:0 0 8px; line-height:1.3; }
.prev-hero .sub{ opacity:.75; font-size:.88rem; }
.prev-hero .meta{ display:flex; gap:16px; margin-top:18px; flex-wrap:wrap; }
.prev-hero .meta-item{ background:rgba(255,255,255,.12); border-radius:6px;
                       padding:6px 14px; font-size:.78rem; font-weight:600; }
.day-card   { background:var(--white); border-radius:12px; border:1px solid var(--grey-lt);
              margin-bottom:20px; overflow:hidden; }
.day-head   { background:var(--red); color:var(--white); padding:14px 24px;
              display:flex; align-items:baseline; gap:12px; }
.day-head .num { font-size:.72rem; font-weight:800; text-transform:uppercase;
                 letter-spacing:.15em; opacity:.8; }
.day-head .title{ font-family:'Merriweather',serif; font-size:1rem; font-weight:700; }
.day-body   { padding:20px 24px; }
.day-row    { display:flex; gap:16px; margin-bottom:16px; }
.day-row .label{ width:120px; font-size:.68rem; font-weight:800; text-transform:uppercase;
                 letter-spacing:.1em; color:var(--grey-mid); padding-top:2px; flex-shrink:0; }
.day-row .val  { flex:1; font-size:.85rem; line-height:1.6; }
.lodge-pill  { display:inline-block; background:var(--off-white); border-radius:20px;
               padding:4px 12px; font-size:.78rem; font-weight:600; color:var(--grey-dk); }
.meal-pills  { display:flex; gap:6px; flex-wrap:wrap; }
.meal-pill   { background:var(--off-white); border-radius:20px; padding:3px 10px;
               font-size:.75rem; font-weight:600; }
.act-pill    { display:inline-flex; align-items:center; gap:5px; background:var(--off-white);
               border-radius:20px; padding:4px 12px; font-size:.78rem; font-weight:600;
               margin:2px; }
.flight-pill { display:inline-flex; align-items:center; gap:6px; background:#eef4ff;
               color:#1a4fd6; border-radius:20px; padding:4px 14px;
               font-size:.78rem; font-weight:600; margin:2px; }
.narrative   { font-size:.85rem; line-height:1.7; color:var(--grey-dk);
               white-space:pre-wrap; }
.price-table { width:100%; border-collapse:collapse; font-size:.83rem; }
.price-table th{ padding:10px 14px; text-align:left; font-size:.68rem; font-weight:700;
                 text-transform:uppercase; letter-spacing:.1em; color:var(--grey-mid);
                 border-bottom:2px solid var(--grey-lt); }
.price-table td{ padding:12px 14px; border-bottom:1px solid var(--grey-lt); }
.price-table tr:last-child td{ border-bottom:none; }
.section-box { background:var(--white); border-radius:12px; border:1px solid var(--grey-lt);
               padding:24px; margin-bottom:20px; }
.inc-list    { list-style:none; margin:0; padding:0; }
.inc-list li { padding:6px 0; border-bottom:1px solid var(--off-white); font-size:.85rem;
               display:flex; align-items:baseline; gap:8px; }
.inc-list li:last-child{ border:none; }
.tc-richtext p { margin:0 0 6px 0; }
.tc-richtext ul, .tc-richtext ol { margin:6px 0 6px 0; padding-left:20px; }
.tc-richtext li { margin:0 0 3px 0; }
.tc-richtext a { color:var(--red); }
</style>

<main>
<?php iti_nav('Preview', [
    ['label' => ucfirst($program['program_type']).' programs','url'=>ITI_MODULE_URL.'/programs.php?type='.$program['program_type']],
    ['label' => h($program['title_en']),'url'=>ITI_MODULE_URL.'/program_edit.php?id='.$id],
]); ?>

<!-- Language / currency switcher -->
<div style="display:flex;align-items:center;gap:12px;margin-bottom:20px;flex-wrap:wrap;">
  <span style="font-size:.75rem;font-weight:700;color:var(--grey-mid);">Preview language:</span>
  <?php foreach (ITI_LANGS as $l): ?>
  <a href="?id=<?= $id ?>&lang=<?= $l ?>&curr=<?= $curr ?>"
     style="padding:4px 12px;border-radius:20px;font-size:.75rem;font-weight:700;text-decoration:none;
            background:<?= $lang===$l?'var(--red)':'var(--off-white)' ?>;
            color:<?= $lang===$l?'var(--white)':'var(--grey-dk)' ?>;"><?= strtoupper($l) ?></a>
  <?php endforeach; ?>
  <span style="font-size:.75rem;font-weight:700;color:var(--grey-mid);margin-left:12px;">Currency:</span>
  <?php foreach (ITI_CURRENCIES as $c): ?>
  <a href="?id=<?= $id ?>&lang=<?= $lang ?>&curr=<?= $c ?>"
     style="padding:4px 12px;border-radius:20px;font-size:.75rem;font-weight:700;text-decoration:none;
            background:<?= $curr===$c?'var(--red)':'var(--off-white)' ?>;
            color:<?= $curr===$c?'var(--white)':'var(--grey-dk)' ?>;"><?= $c ?></a>
  <?php endforeach; ?>
  <div style="margin-left:auto;display:flex;gap:8px;">
    <a href="program_edit.php?id=<?= $id ?>" class="btn btn-outline btn-sm">✏️ Edit</a>
    <a href="export_word.php?id=<?= $id ?>&lang=<?= $lang ?>&curr=<?= $curr ?>" class="btn btn-outline btn-sm">⬇ .docx</a>
  </div>
</div>

<div class="prev-wrap">

  <!-- Hero header -->
  <div class="prev-hero">
    <div style="margin-bottom:16px;">
      <span style="display:inline-block;background:#fff;border-radius:10px;padding:8px 12px;">
        <img src="<?= h(iti_setting('logo_url', 'https://hub.savannahexplorers.com/modules/iti/uploads/logo/logo_1781526818.png')) ?>"
             alt="<?= h(iti_setting('company_name', 'Savannah Explorers')) ?>"
             style="height:56px;width:auto;display:block;">
      </span>
    </div>
    </div>
    <h1><?= iti_h($program, 'title', $lang) ?></h1>
    <?php if (iti_field($program, 'subtitle', $lang)): ?>
    <div class="sub"><?= iti_h($program, 'subtitle', $lang) ?></div>
    <?php endif; ?>
    <div class="meta">
      <div class="meta-item">📅 <?= iti_duration_label((int)$program['duration_days'], $lang) ?></div>
      <div class="meta-item">👥 <?= $program['pax_adults'] ?> adult<?= $program['pax_adults']!=1?'s':'' ?><?= $program['pax_children']?' + '.$program['pax_children'].' child'.($program['pax_children']!=1?'ren':''):'' ?></div>
      <?php if ($program['flights_included']): ?><div class="meta-item">✈️ Flights included</div><?php endif; ?>
    </div>
  </div>

  <!-- Travel consultant -->
  <?php if ($consultant): ?>
  <div style="background:#fff;border:1px solid var(--grey-lt);border-radius:12px;padding:24px;margin-bottom:24px;display:flex;gap:20px;align-items:flex-start;">
    <?php if (!empty($consultant['photo_url'])): ?>
      <img src="<?= h($consultant['photo_url']) ?>" alt="<?= h($consultant['full_name'] ?? '') ?>" style="width:90px;height:90px;border-radius:50%;object-fit:cover;border:2px solid var(--grey-lt);flex-shrink:0;">
    <?php endif; ?>
    <div>
      <div style="font-size:.66rem;font-weight:800;text-transform:uppercase;letter-spacing:.12em;color:var(--red);margin-bottom:6px;"><?= h(iti_lbl_consultant($lang)) ?></div>
      <?php if (!empty($consultant['full_name'])): ?>
        <div style="font-family:'Merriweather',serif;font-size:1.1rem;font-weight:700;color:var(--grey-dk);margin-bottom:6px;"><?= h($consultant['full_name']) ?></div>
      <?php endif; ?>
      <?php
        $cparts = [];
        if (!empty($consultant['email']))    $cparts[] = h($consultant['email']);
        if (!empty($consultant['whatsapp'])) $cparts[] = 'WhatsApp: '.h($consultant['whatsapp']);
      ?>
      <?php if ($cparts): ?>
        <div style="font-size:.8rem;color:var(--grey-mid);margin-bottom:10px;"><?= implode(' &nbsp;·&nbsp; ', $cparts) ?></div>
      <?php endif; ?>
      <?php if ($consultant_bio !== ''): ?>
        <div style="font-size:.85rem;color:var(--grey-dk);line-height:1.6;"><?= $consultant_bio ?></div>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- Days -->
  <?php
    // Build date map for preview
    $prev_start = !empty($program['start_date']) ? new DateTime($program['start_date']) : null;
    $view_date_map = [];
    if ($prev_start) {
        $cur = clone $prev_start;
        foreach ($days as $_d) {
            $view_date_map[(int)$_d['id']] = clone $cur;
            $nights_this = max(1, (int)($_d['own_arrangement_nights'] ?? 0) ?: 1);
            $cur->modify("+{$nights_this} days");
        }
    }
  ?>
  <?php foreach ($days as $day): ?>
  <?php
    $acts     = iti_get_day_activities((int)$day['id']);
    $flights  = iti_get_day_flights((int)$day['id']);
    $title    = iti_field($day, 'day_title', $lang);
    $narr     = iti_field($day, 'narrative',  $lang);
    $day_is_oa = !empty($day['own_arrangement']);
    $oa_nights = (int)($day['own_arrangement_nights'] ?? 0);
    $day_date  = $view_date_map[(int)$day['id']] ?? null;
    $day_label = 'Day '.$day['day_number'];
    if ($day_is_oa && $oa_nights > 1) $day_label .= '–'.($day['day_number']+$oa_nights-1);
  ?>

  <?php if ($day_is_oa): ?>
  <!-- OA block -->
  <div class="day-card" style="border:2px solid #ffe082;">
    <div class="day-head" style="background:#f9a825;">
      <div class="num"><?= $day_label ?></div>
      <div class="title">Own Arrangement<?= $title ? ' — '.h($title) : '' ?></div>
      <?php if ($day_date): ?><div style="margin-left:auto;font-size:.75rem;opacity:.85;"><?= $day_date->format('d M Y') ?><?php if ($oa_nights>1): $end_oa=clone $day_date;$end_oa->modify('+'.($oa_nights-1).' days'); ?> – <?= $end_oa->format('d M Y') ?><?php endif; ?></div><?php endif; ?>
    </div>
    <div class="day-body">
      <div class="day-row">
        <div class="label">🏨 Accommodation</div>
        <div class="val">
          <?php
            $oa_lodge = '';
            if (!empty($day['end_lodge_name'])) $oa_lodge = $day['end_lodge_name'];
            elseif (!empty($day['end_lodge_custom']) && strcasecmp(trim($day['end_lodge_custom']), 'own arrangement') !== 0)
                $oa_lodge = $day['end_lodge_custom'];
          ?>
          <?php if ($oa_lodge): ?>
            <span class="lodge-pill" style="background:#fff8e1;color:#7A4F01;border:1px solid #ffe082;">
              🏨 <?= h($oa_lodge) ?> <span style="opacity:.7;">(Own Arrangement)</span>
            </span>
          <?php else: ?>
            <span class="lodge-pill" style="background:#fff8e1;color:#7A4F01;border:1px solid #ffe082;">
              🏨 Own Arrangement — <?= $oa_nights ?> night<?= $oa_nights!=1?'s':'' ?>
            </span>
          <?php endif; ?>
          <span style="font-size:.75rem;color:#7A4F01;margin-left:6px;"><?= $oa_nights ?> night<?= $oa_nights!=1?'s':'' ?></span>
        </div>
      </div>
      <?php if ($narr): ?>
      <div class="day-row">
        <div class="label">Notes</div>
        <div class="val narrative"><?= h($narr) ?></div>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <?php else: ?>
  <div class="day-card">
    <div class="day-head">
      <div class="num"><?= $day_label ?></div>
      <?php if ($title): ?><div class="title"><?= h($title) ?></div><?php endif; ?>
      <?php if ($day_date): ?><div style="margin-left:auto;font-size:.75rem;opacity:.75;"><?= $day_date->format('d M Y') ?></div><?php endif; ?>
    </div>
    <div class="day-body">

      <?php $start_name = iti_start_display_name($day); ?>
      <?php if ($start_name || $day['end_lodge_name']): ?>
      <div class="day-row">
        <div class="label"><?= $day['start_lodge_name'] ? '🏕️ Lodge' : '📍 Starting point' ?></div>
        <div class="val">
          <?php if ($start_name): ?>
            <span class="lodge-pill"><?= h($start_name) ?><?= ($day['start_lodge_name'] && $day['start_dest_name']) ? ' — '.h($day['start_dest_name']) : '' ?></span>
          <?php endif; ?>
          <?php if ($day['end_lodge_name'] && $day['end_lodge_name'] !== $start_name): ?>→ <span class="lodge-pill"><?= h($day['end_lodge_name']) ?></span><?php endif; ?>
        </div>
      </div>
      <?php endif; ?>

      <?php
        // Transfers row
        $day_transfers = iti_get_day_transfers((int)$day['id']);
      ?>
      <?php if ($day_transfers): ?>
      <div class="day-row">
        <div class="label">🚌 Transfer</div>
        <div class="val">
          <?php foreach ($day_transfers as $tr): ?>
          <div style="margin-bottom:4px;"><?= h($tr['description'] ?? '') ?></div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <?php
        // Overnight / accommodation row
        $overnight_name = '';
        if (!empty($day['end_lodge_name'])) {
            $overnight_name = $day['end_lodge_name'];
        } elseif (!empty($day['end_lodge_custom'])) {
            $overnight_name = $day['end_lodge_custom'];
        }
        $is_own = (strcasecmp(trim($overnight_name), 'own arrangement') === 0);
      ?>
      <?php if ($overnight_name): ?>
      <div class="day-row">
        <div class="label">🌙 Overnight</div>
        <div class="val">
          <?php if ($is_own): ?>
            <span class="lodge-pill" style="background:#fff8e1;color:#7A4F01;border:1px solid #ffe082;">🏨 Own Arrangement</span>
          <?php else: ?>
            <span class="lodge-pill"><?= h($overnight_name) ?></span>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>

      <?php if ($flights): ?>
      <div class="day-row">
        <div class="label">✈️ Flights</div>
        <div class="val">
          <?php foreach ($flights as $fl): ?>
          <?php
            $fl_route = !empty($fl['flight_label']) ? $fl['flight_label']
                      : (($fl['from_code'] ?: $fl['from_airport']) . ' → ' . ($fl['to_code'] ?: $fl['to_airport']));
            $fl_dep = !empty($fl['departure_time']) ? substr($fl['departure_time'],0,5) : '';
            $fl_arr = !empty($fl['arrival_time'])   ? substr($fl['arrival_time'],0,5)   : '';
            $fl_times = $fl_dep ? ($fl_dep . ($fl_arr ? ' → '.$fl_arr : '')) : '';
            $fl_op = $fl['operator'] ?? '';
          ?>
          <span class="flight-pill">
            <?= h($fl_route) ?>
            <?= $fl_times ? ' · <strong>'.h($fl_times).'</strong>' : '' ?>
            <?= $fl_op    ? ' · '.h($fl_op) : '' ?>
          </span>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <?php
      $meals = [];
      if ($day['meal_breakfast']) $meals[] = '🌅 B';
      if ($day['meal_lunch'])     $meals[] = '☀️ L';
      if ($day['meal_dinner'])    $meals[] = '🌙 D';
      ?>
      <?php if ($meals): ?>
      <div class="day-row">
        <div class="label">Included</div>
        <div class="val"><div class="meal-pills"><?php foreach ($meals as $m): ?><span class="meal-pill"><?= $m ?></span><?php endforeach; ?></div></div>
      </div>
      <?php endif; ?>

      <?php if ($acts): ?>
      <div class="day-row">
        <div class="label">Activities</div>
        <div class="val">
          <?php foreach ($acts as $a): ?>
          <span class="act-pill"><?= ITI_ACTIVITY_ICONS[$a['activity_type']] ?? '⭐' ?> <?= iti_h($a, 'name', $lang) ?></span>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <?php if ($narr): ?>
      <div class="day-row">
        <div class="label">Description</div>
        <div class="val narrative"><?= h($narr) ?></div>
      </div>
      <?php endif; ?>

    </div>
  </div>
  <?php endif; // end OA / normal branch ?>
  <?php endforeach; ?>

  <!-- Prices -->
  <?php if ($prices): ?>
  <div class="section-box">
    <div style="font-family:'Merriweather',serif;font-size:1rem;font-weight:700;margin-bottom:16px;">Prices</div>
    <table class="price-table">
      <thead>
        <tr>
          <th>Category</th>
          <th>Per person (<?= $curr ?>)</th>
          <th>Single Suppl.</th>
          <th>Child</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach (ITI_PRICE_CATEGORIES as $cat => $cat_label): ?>
      <?php if (!isset($prices[$cat])) continue; $p = $prices[$cat]; ?>
      <?php $col_pp = 'price_per_pax_' . strtolower($curr); $col_ss = 'single_suppl_' . strtolower($curr); $col_ch = 'child_price_' . strtolower($curr); ?>
      <tr>
        <td style="font-weight:700;"><?= h($cat_label) ?></td>
        <td style="font-size:1rem;font-weight:700;color:var(--red);"><?= iti_money((float)($p[$col_pp]??0),$curr) ?></td>
        <td><?= iti_money((float)($p[$col_ss]??0),$curr) ?></td>
        <td><?= iti_money((float)($p[$col_ch]??0),$curr) ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <!-- Included / Excluded -->
  <?php if ($included || $excluded): ?>
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px;">
    <?php foreach (['included' => $included, 'excluded' => $excluded] as $type => $items): ?>
    <?php if (!$items) continue; ?>
    <div class="section-box">
      <div style="font-family:'Merriweather',serif;font-size:.95rem;font-weight:700;margin-bottom:14px;">
        <?= $type==='included'?'✅ Included':'❌ Not included' ?>
      </div>
      <ul class="inc-list">
        <?php foreach ($items as $inc): ?>
        <li>
          <span style="color:<?= $type==='included'?'var(--green)':'var(--red)' ?>;"><?= $type==='included'?'✓':'✗' ?></span>
          <?= h($inc['display_text'] ?? '') ?>
        </li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- T&C excerpt -->
  <?php if ($tc): ?>
  <div class="section-box" style="background:var(--off-white);">
    <div style="font-family:'Merriweather',serif;font-size:.85rem;font-weight:700;margin-bottom:4px;">Terms &amp; Conditions — <?= h($tc['name']) ?></div>
    <?php if (!empty($tc['effective_date'])): ?>
    <div style="font-size:.75rem;color:var(--grey-mid);margin-bottom:8px;">Effective <?= date('d M Y',strtotime($tc['effective_date'])) ?></div>
    <?php endif; ?>
    <?php $tc_text = iti_field($tc, 'content', $lang); if ($tc_text !== ''): ?>
    <div class="tc-richtext" style="font-size:.78rem;line-height:1.55;color:var(--grey-dk);"><?= $tc_text ?></div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <!-- ── RECAP ──────────────────────────────────────────────────────── -->
  <?php
    // Build recap arrays from all days
    $recap_accommodation = [];
    $recap_flights       = [];
    $recap_transfers     = [];

    foreach ($days as $d) {
        $night      = '';
        $is_own_arr = !empty($d['own_arrangement'] ?? 0);
        $oa_nights  = (int)($d['own_arrangement_nights'] ?? 0);
        if (!empty($d['end_lodge_name'])) {
            $night = $d['end_lodge_name'];
        } elseif (!empty($d['end_lodge_custom']) && strcasecmp(trim($d['end_lodge_custom']), 'own arrangement') !== 0) {
            $night = $d['end_lodge_custom'];
        } elseif ($is_own_arr) {
            $night = ''; // pure OA, no lodge
        }
        if ($night || $is_own_arr) {
            $recap_accommodation[] = [
                'day'       => $d['day_number'],
                'name'      => $night,
                'own'       => $is_own_arr,
                'oa_nights' => $oa_nights,
            ];
        }
        foreach (iti_get_day_flights((int)$d['id']) as $fl) {
            $recap_flights[] = ['day' => $d['day_number'], 'fl' => $fl];
        }
        foreach (iti_get_day_transfers((int)$d['id']) as $tr) {
            $recap_transfers[] = ['day' => $d['day_number'], 'tr' => $tr];
        }
    }
  ?>

  <?php if ($recap_accommodation || $recap_flights || $recap_transfers): ?>
  <div class="section-box" style="margin-top:8px;">
    <div style="font-family:'Merriweather',serif;font-size:1rem;font-weight:700;margin-bottom:20px;">📋 Recap</div>

    <?php if ($recap_accommodation): ?>
    <div style="margin-bottom:20px;">
      <div style="font-size:.68rem;font-weight:800;text-transform:uppercase;letter-spacing:.12em;color:var(--grey-mid);margin-bottom:10px;">🌙 Accommodation</div>
      <table style="width:100%;border-collapse:collapse;font-size:.83rem;">
        <thead>
          <tr>
            <th style="text-align:left;padding:6px 12px;font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--grey-mid);border-bottom:1px solid var(--grey-lt);width:60px;">Night</th>
            <th style="text-align:left;padding:6px 12px;font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--grey-mid);border-bottom:1px solid var(--grey-lt);">Lodge / Hotel</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($recap_accommodation as $ra): ?>
        <tr>
          <td style="padding:7px 12px;border-bottom:1px solid var(--off-white);color:var(--grey-mid);font-size:.78rem;font-weight:700;">
            Night <?= $ra['day'] ?><?= ($ra['own'] && $ra['oa_nights'] > 1) ? '–'.($ra['day']+$ra['oa_nights']-1) : '' ?>
          </td>
          <td style="padding:7px 12px;border-bottom:1px solid var(--off-white);">
            <?php if ($ra['own'] && $ra['name']): ?>
              <span class="lodge-pill" style="background:#fff8e1;color:#7A4F01;border:1px solid #ffe082;">
                🏨 <?= h($ra['name']) ?> <span style="opacity:.7;">(Own Arrangement)</span>
              </span>
            <?php elseif ($ra['own']): ?>
              <span class="lodge-pill" style="background:#fff8e1;color:#7A4F01;border:1px solid #ffe082;">
                🏨 Own Arrangement
              </span>
            <?php else: ?>
              <span class="lodge-pill"><?= h($ra['name']) ?></span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>

    <?php if ($recap_flights): ?>
    <div style="margin-bottom:20px;">
      <div style="font-size:.68rem;font-weight:800;text-transform:uppercase;letter-spacing:.12em;color:var(--grey-mid);margin-bottom:10px;">✈️ Flights</div>
      <table style="width:100%;border-collapse:collapse;font-size:.83rem;">
        <thead>
          <tr>
            <th style="text-align:left;padding:6px 12px;font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--grey-mid);border-bottom:1px solid var(--grey-lt);width:60px;">Day</th>
            <th style="text-align:left;padding:6px 12px;font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--grey-mid);border-bottom:1px solid var(--grey-lt);">Route</th>
            <th style="text-align:left;padding:6px 12px;font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--grey-mid);border-bottom:1px solid var(--grey-lt);">Time</th>
            <th style="text-align:left;padding:6px 12px;font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--grey-mid);border-bottom:1px solid var(--grey-lt);">Operator</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($recap_flights as $rf): $fl = $rf['fl']; ?>
        <tr>
          <td style="padding:7px 12px;border-bottom:1px solid var(--off-white);color:var(--grey-mid);font-size:.78rem;font-weight:700;">Day <?= $rf['day'] ?></td>
          <?php
            $rf_route = !empty($fl['flight_label']) ? $fl['flight_label']
                      : (($fl['from_code'] ?: $fl['from_airport']) . ' → ' . ($fl['to_code'] ?: $fl['to_airport']));
            $rf_dep = !empty($fl['departure_time']) ? substr($fl['departure_time'],0,5) : '';
            $rf_arr = !empty($fl['arrival_time'])   ? substr($fl['arrival_time'],0,5)   : '';
          ?>
          <td style="padding:7px 12px;border-bottom:1px solid var(--off-white);">
            <span class="flight-pill"><?= h($rf_route) ?></span>
          </td>
          <td style="padding:7px 12px;border-bottom:1px solid var(--off-white);font-size:.8rem;color:var(--grey-dk);">
            <?= $rf_dep ? h($rf_dep).($rf_arr?' → '.h($rf_arr):'') : '—' ?>
          </td>
          <td style="padding:7px 12px;border-bottom:1px solid var(--off-white);font-size:.8rem;color:var(--grey-dk);">
            <?= $fl['operator'] ? h($fl['operator']) : '—' ?>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>

    <?php if ($recap_transfers): ?>
    <div>
      <div style="font-size:.68rem;font-weight:800;text-transform:uppercase;letter-spacing:.12em;color:var(--grey-mid);margin-bottom:10px;">🚐 Transfers</div>
      <table style="width:100%;border-collapse:collapse;font-size:.83rem;">
        <thead>
          <tr>
            <th style="text-align:left;padding:6px 12px;font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--grey-mid);border-bottom:1px solid var(--grey-lt);width:60px;">Day</th>
            <th style="text-align:left;padding:6px 12px;font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--grey-mid);border-bottom:1px solid var(--grey-lt);">Transfer</th>
            <th style="text-align:left;padding:6px 12px;font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--grey-mid);border-bottom:1px solid var(--grey-lt);">Notes</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($recap_transfers as $rt): $tr = $rt['tr']; ?>
        <tr>
          <td style="padding:7px 12px;border-bottom:1px solid var(--off-white);color:var(--grey-mid);font-size:.78rem;font-weight:700;">Day <?= $rt['day'] ?></td>
          <td style="padding:7px 12px;border-bottom:1px solid var(--off-white);">
            <?php
              $tr_label = !empty($tr['description']) ? $tr['description'] : '—';
            ?>
            <span class="lodge-pill"><?= h($tr_label) ?></span>
          </td>
          <td style="padding:7px 12px;border-bottom:1px solid var(--off-white);font-size:.8rem;color:var(--grey-mid);">—</td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>

  </div><!-- end recap -->
  <?php endif; ?>

</div><!-- end prev-wrap -->
</main>

<?php include __DIR__ . '/../../includes/layout_footer.php'; ?>
