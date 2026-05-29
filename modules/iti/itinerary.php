<?php
/**
 * modules/iti/itinerary.php
 * Pagina pubblica cliente — accesso via ?token=UUID
 * NO autenticazione richiesta
 */
require_once __DIR__ . '/../../includes/config.php';   // solo config/db, non auth
require_once __DIR__ . '/includes/iti_functions.php';

$token = trim($_GET['token'] ?? '');
if ($token === '') {
    http_response_code(404);
    die('<h1>Not found</h1><p>This itinerary link is not valid.</p>');
}

// Cerca il programma per token
$db  = db();
$stmt = $db->prepare('SELECT * FROM iti_programs WHERE public_token=? AND is_published=1');
$stmt->execute([$token]);
$program = $stmt->fetch();

if (!$program) {
    http_response_code(404);
    die('<h1>Itinerary not found</h1><p>This link may have expired or been deactivated.</p>');
}

$id = (int)$program['id'];

// Lingua: preferenza URL → programma → EN
$lang = $_GET['lang'] ?? $program['display_language'] ?? 'en';
if (!in_array($lang, ITI_LANGS)) $lang = 'en';

$curr = $_GET['curr'] ?? $program['display_currency'] ?? 'USD';
if (!in_array($curr, ITI_CURRENCIES)) $curr = 'USD';

// Carica dati
$days       = iti_get_program_days($id);
$prices     = iti_get_program_prices($id);
$inclusions = iti_get_program_inclusions($id);
$included   = array_filter($inclusions, fn($i) => $i['item_type'] === 'inclusion');
$excluded   = array_filter($inclusions, fn($i) => $i['item_type'] === 'exclusion');

// T&C
$tc = null;
if ($program['terms_id']) {
    $s = $db->prepare('SELECT * FROM iti_terms_conditions WHERE id=?');
    $s->execute([$program['terms_id']]);
    $tc = $s->fetch();
}

// Ottieni la richiesta per il nome cliente
$req = $program['request_id'] ? iti_get_request((int)$program['request_id']) : null;

$page_title = iti_field($program, 'title', $lang) . ' — Savannah Explorers';
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= h($page_title) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700&family=Merriweather:wght@400;700&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing:border-box; margin:0; padding:0; }
:root {
  --red:    #C0211B;
  --black:  #1A1A1A;
  --white:  #FFFFFF;
  --off-wh: #F7F5F2;
  --grey-lt:#E5E2DE;
  --grey-md:#999591;
  --grey-dk:#4A4743;
  --green:  #2E7D32;
  --amber:  #B45309;
}
body { font-family:'Open Sans',sans-serif; background:var(--off-wh); color:var(--black);
       font-size:15px; line-height:1.6; }
a { color:var(--red); }

/* ── Top bar ── */
.topbar { background:var(--black); color:var(--white); padding:12px 0; }
.topbar-inner { max-width:860px; margin:0 auto; padding:0 24px;
                display:flex; align-items:center; justify-content:space-between; }
.brand { font-family:'Merriweather',serif; font-size:.85rem; font-weight:700;
         letter-spacing:.04em; color:var(--white); text-decoration:none; }
.lang-sw { display:flex; gap:6px; }
.lang-sw a { color:rgba(255,255,255,.5); font-size:.72rem; font-weight:700;
             text-decoration:none; padding:3px 8px; border-radius:4px; }
.lang-sw a.active { color:var(--white); background:rgba(255,255,255,.15); }

/* ── Hero ── */
.hero { background:var(--red); color:var(--white); padding:56px 24px 48px; }
.hero-inner { max-width:860px; margin:0 auto; }
.hero-label { font-size:.68rem; font-weight:800; text-transform:uppercase;
              letter-spacing:.2em; opacity:.7; margin-bottom:10px; }
.hero-title { font-family:'Merriweather',serif; font-size:2rem; font-weight:700;
              line-height:1.3; margin-bottom:10px; }
.hero-sub   { font-size:.95rem; opacity:.85; margin-bottom:20px; }
.hero-meta  { display:flex; gap:12px; flex-wrap:wrap; }
.hero-chip  { background:rgba(255,255,255,.15); border-radius:20px;
              padding:5px 14px; font-size:.78rem; font-weight:700; }

/* ── Main ── */
.page-main { max-width:860px; margin:0 auto; padding:40px 24px 80px; }

/* ── Day card ── */
.day-card   { background:var(--white); border-radius:12px;
              box-shadow:0 1px 4px rgba(0,0,0,.06); margin-bottom:24px; overflow:hidden; }
.day-head   { display:flex; align-items:center; gap:14px; padding:16px 24px;
              border-bottom:2px solid var(--off-wh); }
.day-badge  { background:var(--red); color:var(--white); border-radius:8px;
              padding:6px 12px; font-size:.72rem; font-weight:800;
              text-transform:uppercase; letter-spacing:.1em; }
.day-title  { font-family:'Merriweather',serif; font-size:1rem; font-weight:700; }
.day-body   { padding:20px 24px; }
.info-row   { display:flex; gap:12px; margin-bottom:14px; }
.info-label { width:110px; font-size:.68rem; font-weight:800; text-transform:uppercase;
              letter-spacing:.1em; color:var(--grey-md); padding-top:3px; flex-shrink:0; }
.info-val   { flex:1; font-size:.85rem; line-height:1.6; }
.pill       { display:inline-block; background:var(--off-wh); border-radius:20px;
              padding:4px 12px; font-size:.78rem; font-weight:600; margin:2px; }
.pill-fl    { background:#EFF6FF; color:#1D4ED8; }
.pill-green { background:#F0FDF4; color:#15803D; }
.narrative  { font-size:.87rem; line-height:1.75; color:var(--grey-dk); }

/* ── Section ── */
.section-card { background:var(--white); border-radius:12px;
                box-shadow:0 1px 4px rgba(0,0,0,.06); padding:28px 32px; margin-bottom:24px; }
.section-head { font-family:'Merriweather',serif; font-size:1.1rem; font-weight:700;
                margin-bottom:18px; padding-bottom:12px; border-bottom:2px solid var(--off-wh); }

/* ── Prices ── */
.price-table { width:100%; border-collapse:collapse; }
.price-table th { padding:10px 14px; text-align:left; font-size:.68rem; font-weight:800;
                  text-transform:uppercase; letter-spacing:.1em; color:var(--grey-md);
                  border-bottom:2px solid var(--grey-lt); }
.price-table td { padding:14px; border-bottom:1px solid var(--off-wh); font-size:.88rem; }
.price-table tr:last-child td { border:none; }
.price-main { font-size:1.2rem; font-weight:800; color:var(--red); }

/* ── Inclusions ── */
.inc-grid { display:grid; grid-template-columns:1fr 1fr; gap:20px; }
@media(max-width:600px){ .inc-grid{ grid-template-columns:1fr; } }
.inc-list { list-style:none; }
.inc-list li { display:flex; align-items:baseline; gap:8px; padding:7px 0;
               border-bottom:1px solid var(--off-wh); font-size:.85rem; }
.inc-list li:last-child { border:none; }
.inc-list .icon { flex-shrink:0; }

/* ── T&C ── */
.tc-box { background:var(--off-wh); border-radius:10px; padding:18px 22px;
          font-size:.78rem; color:var(--grey-dk); line-height:1.7; }

/* ── CTA footer ── */
.cta-bar { background:var(--red); color:var(--white); text-align:center;
           padding:40px 24px; }
.cta-bar h2 { font-family:'Merriweather',serif; font-size:1.4rem; margin-bottom:10px; }
.cta-bar p  { opacity:.85; margin-bottom:18px; }
.cta-btn { display:inline-block; background:var(--white); color:var(--red);
           font-weight:800; font-size:.88rem; border-radius:8px;
           padding:12px 28px; text-decoration:none; }

/* ── Footer ── */
.site-footer { background:var(--black); color:rgba(255,255,255,.45);
               text-align:center; padding:24px; font-size:.75rem; }

@media print {
  .topbar, .lang-sw, .cta-bar, .site-footer { display:none; }
  .hero { -webkit-print-color-adjust:exact; print-color-adjust:exact; }
}
@media(max-width:620px){
  .hero-title { font-size:1.4rem; }
  .section-card { padding:20px; }
  .page-main { padding:24px 16px 60px; }
  .inc-grid { grid-template-columns:1fr; }
}
</style>
</head>
<body>

<!-- Top bar -->
<div class="topbar">
  <div class="topbar-inner">
    <span class="brand">Savannah Explorers</span>
    <div class="lang-sw">
      <?php foreach (ITI_LANGS as $l): ?>
      <a href="?token=<?= h($token) ?>&lang=<?= $l ?>&curr=<?= $curr ?>"
         class="<?= $lang===$l?'active':'' ?>"><?= strtoupper($l) ?></a>
      <?php endforeach; ?>
      &nbsp;
      <?php foreach (ITI_CURRENCIES as $c): ?>
      <a href="?token=<?= h($token) ?>&lang=<?= $lang ?>&curr=<?= $c ?>"
         class="<?= $curr===$c?'active':'' ?>"><?= $c ?></a>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<!-- Hero -->
<div class="hero">
  <div class="hero-inner">
    <?php if ($req && $req['client_name']): ?>
    <div class="hero-label">Your personalised itinerary · <?= h($req['client_name']) ?></div>
    <?php else: ?>
    <div class="hero-label">Safari Itinerary · Savannah Explorers</div>
    <?php endif; ?>
    <h1 class="hero-title"><?= iti_h($program,'title',$lang) ?></h1>
    <?php if (iti_field($program,'subtitle',$lang)): ?>
    <p class="hero-sub"><?= iti_h($program,'subtitle',$lang) ?></p>
    <?php endif; ?>
    <div class="hero-meta">
      <div class="hero-chip">📅 <?= iti_duration_label((int)$program['duration_days'],$lang) ?></div>
      <div class="hero-chip">👥 <?= $program['pax_adults'] ?> adult<?= $program['pax_adults']!=1?'s':'' ?><?= $program['pax_children']?' + '.$program['pax_children'].' child'.($program['pax_children']!=1?'ren':''):'' ?></div>
      <?php if ($program['flights_included']): ?>
      <div class="hero-chip">✈️ <?= $lang==='it'?'Voli inclusi':($lang==='de'?'Flüge inklusive':($lang==='fr'?'Vols inclus':($lang==='es'?'Vuelos incluidos':'Flights included'))) ?></div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Main content -->
<main class="page-main">

  <!-- Days -->
  <?php foreach ($days as $day): ?>
  <?php
    $acts    = iti_get_day_activities((int)$day['id']);
    $flights = iti_get_day_flights((int)$day['id']);
    $title   = iti_field($day,'day_title',$lang);
    $narr    = iti_field($day,'narrative',$lang);
    $meals   = [];
    if ($day['meal_breakfast']) $meals[] = '🌅';
    if ($day['meal_lunch'])     $meals[] = '☀️';
    if ($day['meal_dinner'])    $meals[] = '🌙';
  ?>
  <div class="day-card">
    <div class="day-head">
      <div class="day-badge">Day <?= $day['day_number'] ?></div>
      <?php if ($title): ?><div class="day-title"><?= h($title) ?></div><?php endif; ?>
      <?php if ($meals): ?><div style="margin-left:auto;font-size:.9rem;"><?= implode('&thinsp;',$meals) ?></div><?php endif; ?>
    </div>
    <div class="day-body">

      <?php $start_name = iti_start_display_name($day); ?>
      <?php if ($start_name || $day['end_lodge_name']): ?>
      <div class="info-row">
        <div class="info-label">🏕️ <?= $lang==='it'?'Lodge':($lang==='de'?'Unterkunft':($lang==='fr'?'Lodge':($lang==='es'?'Lodge':'Lodge'))) ?></div>
        <div class="info-val">
          <?php if ($start_name): ?>
          <span class="pill pill-green"><?= h($start_name) ?><?= ($day['start_lodge_name'] && $day['start_dest_name']) ? ' · '.h($day['start_dest_name']) : '' ?></span>
          <?php endif; ?>
          <?php if ($day['end_lodge_name'] && $day['end_lodge_name'] !== $start_name): ?>
          → <span class="pill"><?= h($day['end_lodge_name']) ?></span>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>

      <?php if ($flights): ?>
      <div class="info-row">
        <div class="info-label">✈️ Flight<?= count($flights)>1?'s':'' ?></div>
        <div class="info-val">
          <?php foreach ($flights as $fl): ?>
          <span class="pill pill-fl">
            <?= h($fl['from_code']?:$fl['from_airport']) ?> → <?= h($fl['to_code']?:$fl['to_airport']) ?>
            <?= $fl['departure_time'] ? ' '.h(substr($fl['departure_time'],0,5)) : '' ?>
            <?= $fl['operator'] ? ' · '.h($fl['operator']) : '' ?>
          </span>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <?php if ($acts): ?>
      <div class="info-row">
        <div class="info-label">Activities</div>
        <div class="info-val">
          <?php foreach ($acts as $a): ?>
          <span class="pill"><?= ITI_ACTIVITY_ICONS[$a['activity_type']]??'⭐' ?> <?= iti_h($a,'name',$lang) ?></span>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <?php if ($narr): ?>
      <div class="info-row" style="margin-top:14px;">
        <div class="info-label" style="padding-top:0;"></div>
        <div class="info-val narrative"><?= nl2br(h($narr)) ?></div>
      </div>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>

  <!-- Prices -->
  <?php if ($prices): ?>
  <div class="section-card">
    <div class="section-head">💰 <?= $lang==='it'?'Prezzi':($lang==='de'?'Preise':($lang==='fr'?'Tarifs':($lang==='es'?'Precios':'Prices'))) ?></div>
    <table class="price-table">
      <thead>
        <tr>
          <th><?= $lang==='it'?'Categoria':($lang==='de'?'Kategorie':($lang==='fr'?'Catégorie':($lang==='es'?'Categoría':'Category'))) ?></th>
          <th><?= $lang==='it'?'Per persona':($lang==='de'?'Pro Person':($lang==='fr'?'Par personne':($lang==='es'?'Por persona':'Per person'))) ?> (<?= $curr ?>)</th>
          <th><?= $lang==='it'?'Suppl. singola':($lang==='de'?'EZ-Zuschlag':($lang==='fr'?'Suppl. single':($lang==='es'?'Suppl. single':'Single supplement'))) ?></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach (ITI_PRICE_CATEGORIES as $cat => $cat_label): ?>
      <?php if (!isset($prices[$cat])) continue; $p = $prices[$cat]; ?>
      <?php $c = strtolower($curr); ?>
      <tr>
        <td style="font-weight:700;"><?= h($cat_label) ?></td>
        <td><span class="price-main"><?= iti_money((float)($p["price_per_pax_{$c}"]??0),$curr) ?></span></td>
        <td><?= iti_money((float)($p["single_suppl_{$c}"]??0),$curr) ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php if (!$program['flights_included']): ?>
    <p style="font-size:.75rem;color:var(--amber);margin-top:12px;">✈️ <?= $lang==='it'?'Voli interni non inclusi nel prezzo — preventivo separato su richiesta.':'Internal flights are not included in the prices above — available on request.' ?></p>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <!-- Included / Excluded -->
  <?php if ($included || $excluded): ?>
  <div class="section-card">
    <div class="section-head">
      <?= $lang==='it'?'Cosa è incluso':($lang==='de'?'Inkl./Exkl.':($lang==='fr'?'Inclus / Exclus':($lang==='es'?'Incluido / Excluido':'Included / Excluded'))) ?>
    </div>
    <div class="inc-grid">
      <?php if ($included): ?>
      <div>
        <div style="font-weight:700;font-size:.83rem;margin-bottom:10px;color:var(--green);">✅ <?= $lang==='it'?'Incluso':($lang==='de'?'Inklusive':($lang==='fr'?'Inclus':($lang==='es'?'Incluido':'Included'))) ?></div>
        <ul class="inc-list">
          <?php foreach ($included as $inc): ?>
          <li><span class="icon" style="color:var(--green);">✓</span><?= h(iti_field($inc,'resolved',$lang)?:iti_field($inc,'text',$lang)) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
      <?php endif; ?>
      <?php if ($excluded): ?>
      <div>
        <div style="font-weight:700;font-size:.83rem;margin-bottom:10px;color:var(--red);">❌ <?= $lang==='it'?'Non incluso':($lang==='de'?'Exklusive':($lang==='fr'?'Exclus':($lang==='es'?'No incluido':'Not included'))) ?></div>
        <ul class="inc-list">
          <?php foreach ($excluded as $inc): ?>
          <li><span class="icon" style="color:var(--red);">✗</span><?= h(iti_field($inc,'resolved',$lang)?:iti_field($inc,'text',$lang)) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- T&C -->
  <?php if ($tc): ?>
  <div class="section-card">
    <div class="section-head">📄 Terms &amp; Conditions</div>
    <div class="tc-box">
      <div style="font-weight:700;margin-bottom:6px;"><?= h($tc['version']) ?> — Effective <?= date('d F Y',strtotime($tc['effective_date'])) ?></div>
      <?php $tc_text = iti_field($tc,'text',$lang) ?: iti_field($tc,'text','en'); ?>
      <?php if ($tc_text): ?><div style="margin-top:10px;"><?= nl2br(h($tc_text)) ?></div><?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

</main>

<!-- CTA -->
<div class="cta-bar">
  <h2><?= $lang==='it'?'Pronto a partire?':($lang==='de'?'Bereit für ein Abenteuer?':($lang==='fr'?'Prêt à partir ?':($lang==='es'?'¿Listo para partir?':'Ready for your adventure?'))) ?></h2>
  <p><?= $lang==='it'?'Contattaci per confermare il tuo safari.':'Contact us to confirm your safari booking.' ?></p>
  <a href="mailto:info@savannahexplorers.com" class="cta-btn">info@savannahexplorers.com</a>
</div>

<div class="site-footer">
  &copy; <?= date('Y') ?> Savannah Explorers — All rights reserved
</div>

</body>
</html>
