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
.prev-hero   { background:var(--red); color:var(--white); border-radius:12px;
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
    <div style="font-size:.7rem;font-weight:800;text-transform:uppercase;letter-spacing:.2em;opacity:.6;margin-bottom:8px;">Savannah Explorers</div>
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

  <!-- Days -->
  <?php foreach ($days as $day): ?>
  <?php
    $acts    = iti_get_day_activities((int)$day['id']);
    $flights = iti_get_day_flights((int)$day['id']);
    $title   = iti_field($day, 'day_title', $lang);
    $narr    = iti_field($day, 'narrative',  $lang);
  ?>
  <div class="day-card">
    <div class="day-head">
      <div class="num">Day <?= $day['day_number'] ?></div>
      <?php if ($title): ?><div class="title"><?= h($title) ?></div><?php endif; ?>
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
          <span class="flight-pill">
            <?= h($fl['from_code'] ?: $fl['from_airport']) ?> → <?= h($fl['to_code'] ?: $fl['to_airport']) ?>
            <?= $fl['departure_time'] ? ' '.h(substr($fl['departure_time'],0,5)) : '' ?>
            <?= $fl['operator'] ? ' · '.h($fl['operator']) : '' ?>
          </span>
          <?php endforeach; ?>
        </div>
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

      <?php
      $meals = [];
      if ($day['meal_breakfast']) $meals[] = '🌅 B';
      if ($day['meal_lunch'])     $meals[] = '☀️ L';
      if ($day['meal_dinner'])    $meals[] = '🌙 D';
      ?>
      <?php if ($meals): ?>
      <div class="day-row">
        <div class="label">Meals</div>
        <div class="val"><div class="meal-pills"><?php foreach ($meals as $m): ?><span class="meal-pill"><?= $m ?></span><?php endforeach; ?></div></div>
      </div>
      <?php endif; ?>

      <?php if ($narr): ?>
      <div class="day-row">
        <div class="label">Narrative</div>
        <div class="val narrative"><?= h($narr) ?></div>
      </div>
      <?php endif; ?>

    </div>
  </div>
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
    <div style="font-family:'Merriweather',serif;font-size:.85rem;font-weight:700;margin-bottom:8px;">Terms &amp; Conditions — <?= h($tc['version']) ?></div>
    <div style="font-size:.75rem;color:var(--grey-mid);">Effective <?= date('d M Y',strtotime($tc['effective_date'])) ?></div>
  </div>
  <?php endif; ?>

  <!-- ── RECAP ──────────────────────────────────────────────────────── -->
  <?php
    // Build recap arrays from all days
    $recap_accommodation = [];
    $recap_flights       = [];
    $recap_transfers     = [];

    foreach ($days as $d) {
        $night = '';
        $is_own_arr = false;
        if (!empty($d['end_lodge_name'])) {
            $night = $d['end_lodge_name'];
        } elseif (!empty($d['end_lodge_custom'])) {
            $night = $d['end_lodge_custom'];
            $is_own_arr = (strcasecmp(trim($night), 'own arrangement') === 0);
        }
        if ($night) {
            $recap_accommodation[] = ['day' => $d['day_number'], 'name' => $night, 'own' => $is_own_arr];
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
          <td style="padding:7px 12px;border-bottom:1px solid var(--off-white);color:var(--grey-mid);font-size:.78rem;font-weight:700;">Night <?= $ra['day'] ?></td>
          <td style="padding:7px 12px;border-bottom:1px solid var(--off-white);">
            <?php if ($ra['own']): ?>
              <span class="lodge-pill" style="background:#fff8e1;color:#7A4F01;border:1px solid #ffe082;">🏨 Own Arrangement</span>
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
          <td style="padding:7px 12px;border-bottom:1px solid var(--off-white);">
            <span class="flight-pill"><?= h($fl['from_code'] ?: $fl['from_airport']) ?> → <?= h($fl['to_code'] ?: $fl['to_airport']) ?></span>
          </td>
          <td style="padding:7px 12px;border-bottom:1px solid var(--off-white);font-size:.8rem;color:var(--grey-dk);">
            <?= $fl['departure_time'] ? h(substr($fl['departure_time'],0,5)) : '—' ?>
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
