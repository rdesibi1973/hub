<?php
/**
 * iti_mag.php — the programme in the photo "magazine" layout: cover photo,
 * route map, stays overview, day by day with destination photos and lodge
 * cards, prices, included / not included, contacts, terms.
 *
 * Same data as the Etnia layout (iti_doc_data() in iti_doc.php), so web, print
 * and PDF stay in step. Opened with ?layout=mag on program_doc.php / itinerary.php.
 * Printing (browser or headless Chrome) gives an A4 PDF: cover page, then sections.
 *
 * Keep PHP-7 style (no match / arrow functions / str_contains).
 */

/** Extra UI strings of this layout (on top of iti_doc_labels()). */
function iti_mag_labels(string $lang): array {
    $L = [
        'it' => ['overview' => 'Il viaggio', 'facts' => 'In breve', 'route' => 'Il percorso', 'stays' => 'Le sistemazioni',
                 'days_nav' => 'Giorni', 'prices_nav' => 'Quote', 'info_nav' => 'Info', 'see_day' => 'vedi giorno',
                 'website' => 'Sito web', 'start' => 'Arrivo', 'end' => 'Partenza', 'km' => 'km in linea d\'aria',
                 'destinations' => 'Destinazioni', 'lodges' => 'Sistemazioni', 'online' => 'Itinerario digitale',
                 'about_dest' => 'La destinazione', 'pdf' => 'Scarica PDF', 'private' => 'Safari privato con guida',
                 'adult' => 'adulto', 'adults' => 'adulti', 'child' => 'bambino', 'children' => 'bambini', 'dates' => 'Date',
                 'months' => ['gennaio', 'febbraio', 'marzo', 'aprile', 'maggio', 'giugno', 'luglio', 'agosto', 'settembre', 'ottobre', 'novembre', 'dicembre'],
                 'wdays' => ['dom', 'lun', 'mar', 'mer', 'gio', 'ven', 'sab']],
        'en' => ['overview' => 'Your journey', 'facts' => 'At a glance', 'route' => 'The route', 'stays' => 'Where you stay',
                 'days_nav' => 'Days', 'prices_nav' => 'Rates', 'info_nav' => 'Info', 'see_day' => 'see day',
                 'website' => 'Website', 'start' => 'Arrival', 'end' => 'Departure', 'km' => 'km as the crow flies',
                 'destinations' => 'Destinations', 'lodges' => 'Accommodation', 'online' => 'Digital itinerary',
                 'about_dest' => 'The destination', 'pdf' => 'Download PDF', 'private' => 'Private guided safari',
                 'adult' => 'adult', 'adults' => 'adults', 'child' => 'child', 'children' => 'children', 'dates' => 'Dates',
                 'months' => ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'],
                 'wdays' => ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']],
        'fr' => ['overview' => 'Votre voyage', 'facts' => 'En bref', 'route' => 'L\'itinéraire', 'stays' => 'Vos hébergements',
                 'days_nav' => 'Jours', 'prices_nav' => 'Tarifs', 'info_nav' => 'Infos', 'see_day' => 'voir jour',
                 'website' => 'Site web', 'start' => 'Arrivée', 'end' => 'Départ', 'km' => 'km à vol d\'oiseau',
                 'destinations' => 'Destinations', 'lodges' => 'Hébergements', 'online' => 'Itinéraire numérique',
                 'about_dest' => 'La destination', 'pdf' => 'Télécharger le PDF', 'private' => 'Safari privé avec guide',
                 'adult' => 'adulte', 'adults' => 'adultes', 'child' => 'enfant', 'children' => 'enfants', 'dates' => 'Dates',
                 'months' => ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'],
                 'wdays' => ['dim', 'lun', 'mar', 'mer', 'jeu', 'ven', 'sam']],
        'es' => ['overview' => 'Tu viaje', 'facts' => 'En breve', 'route' => 'El recorrido', 'stays' => 'Alojamientos',
                 'days_nav' => 'Días', 'prices_nav' => 'Precios', 'info_nav' => 'Info', 'see_day' => 'ver día',
                 'website' => 'Sitio web', 'start' => 'Llegada', 'end' => 'Salida', 'km' => 'km en línea recta',
                 'destinations' => 'Destinos', 'lodges' => 'Alojamientos', 'online' => 'Itinerario digital',
                 'about_dest' => 'El destino', 'pdf' => 'Descargar PDF', 'private' => 'Safari privado con guía',
                 'adult' => 'adulto', 'adults' => 'adultos', 'child' => 'niño', 'children' => 'niños', 'dates' => 'Fechas',
                 'months' => ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'],
                 'wdays' => ['dom', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb']],
        'de' => ['overview' => 'Ihre Reise', 'facts' => 'Auf einen Blick', 'route' => 'Die Route', 'stays' => 'Ihre Unterkünfte',
                 'days_nav' => 'Tage', 'prices_nav' => 'Preise', 'info_nav' => 'Infos', 'see_day' => 'siehe Tag',
                 'website' => 'Webseite', 'start' => 'Ankunft', 'end' => 'Abreise', 'km' => 'km Luftlinie',
                 'destinations' => 'Reiseziele', 'lodges' => 'Unterkünfte', 'online' => 'Digitale Reiseroute',
                 'about_dest' => 'Das Reiseziel', 'pdf' => 'PDF herunterladen', 'private' => 'Private Safari mit Guide',
                 'adult' => 'Erwachsener', 'adults' => 'Erwachsene', 'child' => 'Kind', 'children' => 'Kinder', 'dates' => 'Reisedaten',
                 'months' => ['Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'],
                 'wdays' => ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa']],
    ];
    return isset($L[$lang]) ? $L[$lang] : $L['en'];
}

/** Consecutive nights in the same lodge → one stay row (first day, nights, photo, …). */
function iti_mag_stays(array $days): array {
    $stays = []; $prevKey = ''; $prevN = -1;
    foreach ($days as $d) {
        if ($d['lodge'] === '') { $prevKey = ''; continue; }
        $key = $d['lodge_key'] !== '' ? $d['lodge_key'] : $d['lodge'];
        if ($stays && $key === $prevKey && $d['n'] === $prevN + 1) {
            $stays[count($stays) - 1]['nights']++;
        } else {
            $stays[] = ['day' => $d['n'], 'lodge' => $d['lodge'], 'dest' => $d['lodge_area'] ?? '', 'nights' => 1,
                        'meals' => $d['meals'], 'photo' => $d['lodge_photos'] ? $d['lodge_photos'][0] : ''];
        }
        $prevKey = $key; $prevN = $d['n'];
    }
    // A later stay in the same lodge reuses the photo found on the first one.
    $photo = [];
    foreach ($stays as $s) if ($s['photo'] !== '' && !isset($photo[$s['lodge']])) $photo[$s['lodge']] = $s['photo'];
    foreach ($stays as &$s) if ($s['photo'] === '' && isset($photo[$s['lodge']])) $s['photo'] = $photo[$s['lodge']];
    unset($s);
    return $stays;
}

/** "2027-03-12" → "12 marzo 2027" ($year) / "gio 12 marzo" ($wday). */
function iti_mag_date(string $ymd, array $M, bool $year = true, bool $wday = false): string {
    $t = strtotime($ymd);
    if (!$t) return '';
    return ($wday ? $M['wdays'][(int)date('w', $t)] . ' ' : '') . (int)date('j', $t) . ' ' . $M['months'][(int)date('n', $t) - 1] . ($year ? ' ' . date('Y', $t) : '');
}

/** Travel dates of the cover: "12 – 17 marzo 2027" (or across months / years). */
function iti_mag_date_range(array $D, array $M): string {
    $days = $D['days'];
    if (!$days || empty($days[0]['date'])) return '';
    $a = $days[0]['date']; $b = $days[count($days) - 1]['date'];
    if (substr($a, 0, 7) === substr($b, 0, 7)) return (int)substr($a, 8) . ' – ' . iti_mag_date($b, $M);
    if (substr($a, 0, 4) === substr($b, 0, 4)) return iti_mag_date($a, $M, false) . ' – ' . iti_mag_date($b, $M);
    return iti_mag_date($a, $M) . ' – ' . iti_mag_date($b, $M);
}

/** "2 adulti · 1 bambino" for personal programmes ('' for samples). */
function iti_mag_pax(array $D, array $M): string {
    if (!empty($D['pax_label'])) return (string)$D['pax_label'];
    $p = $D['program'];
    if (($p['program_type'] ?? '') !== 'personal') return '';
    $a = (int)($p['pax_adults'] ?? 0); $c = (int)($p['pax_children'] ?? 0);
    $out = [];
    if ($a) $out[] = $a . ' ' . ($a === 1 ? $M['adult'] : $M['adults']);
    if ($c) $out[] = $c . ' ' . ($c === 1 ? $M['child'] : $M['children']);
    return implode(' · ', $out);
}

/** Accommodation summary: ["Mdonya Old River Camp · 5 notti", …] in stay order (nights added up per lodge). */
function iti_mag_lodge_nights(array $stays, array $T): array {
    $n = [];
    foreach ($stays as $s) $n[$s['lodge']] = ($n[$s['lodge']] ?? 0) + $s['nights'];
    $out = [];
    foreach ($n as $lodge => $k) $out[] = $lodge . ' · ' . $k . ' ' . ($k === 1 ? $T['night1'] : $T['nights']);
    return $out;
}

/** Cover photo: the destination where most days are spent, else the first lodge photo. */
function iti_mag_cover(array $D): string {
    if (!empty($D['program']['cover_photo'])) return (string)$D['program']['cover_photo'];
    if (!empty($D['cover'])) return (string)$D['cover'];   // safari photo chosen by iti_doc_day_photos()
    $count = []; $photo = [];
    foreach ($D['days'] as $d) {
        if ($d['dest_photo'] === '') continue;
        $count[$d['dest']] = ($count[$d['dest']] ?? 0) + 1;
        $photo[$d['dest']] = $d['dest_photo'];
    }
    if ($count) { arsort($count); return $photo[key($count)]; }
    foreach ($D['days'] as $d) if ($d['lodge_photos']) return $d['lodge_photos'][0];
    return '';
}

/** Destinations in visiting order, without repeats ("Arusha · Tarangire · …"). */
function iti_mag_route_names(array $days): array {
    $out = [];
    foreach ($days as $d) {
        if ($d['dest'] !== '' && !in_array($d['dest'], $out, true)) $out[] = $d['dest'];
    }
    return $out;
}

/** Scoped CSS (class .mag). */
function iti_mag_css(): string {
    return '
.mag{--red:#B3241C;--ink:#231f1c;--mute:#6f675f;--line:#e6ddd0;--sand:#f6f0e6;--paper:#fffdf9;--ochre:#b5822f;
     font-variant-numeric:lining-nums;color:var(--ink);font-family:"Open Sans","Segoe UI",Roboto,Arial,sans-serif;font-size:15.5px;line-height:1.65;background:var(--paper);
     -webkit-print-color-adjust:exact;print-color-adjust:exact}
.mag *{box-sizing:border-box}
.mag img{max-width:100%;display:block}
.mag h1,.mag h2,.mag h3,.mag .mag-serif{font-family:"Cormorant Garamond",Georgia,serif;font-weight:600;letter-spacing:.005em}
.mag p{margin:0 0 .8em}
.mag a{color:var(--red)}
.mag-wrap{max-width:980px;margin:0 auto;padding:0 28px}
.mag-kicker{font-size:11.5px;letter-spacing:.3em;text-transform:uppercase;font-weight:700;color:var(--red)}

/* Cover */
.mag-cover{position:relative;min-height:88vh;display:flex;align-items:flex-end;color:#fff;background:#3b3128 center/cover no-repeat;overflow:hidden}
.mag-cover:before{content:"";position:absolute;inset:0;background:linear-gradient(180deg,rgba(0,0,0,.35) 0%,rgba(0,0,0,0) 30%,rgba(0,0,0,.15) 55%,rgba(0,0,0,.72) 100%)}
.mag-cover-logo{position:absolute;top:26px;left:28px;width:92px;height:92px;border-radius:50%;background:#fff;padding:3px;box-shadow:0 4px 18px rgba(0,0,0,.25)}
.mag-cover-in{position:relative;width:100%;max-width:980px;margin:0 auto;padding:0 28px 56px}
.mag-cover .mag-kicker{color:#fff;opacity:.9}
.mag-cover h1{font-size:68px;line-height:1;margin:.12em 0 .15em;text-shadow:0 2px 24px rgba(0,0,0,.35)}
.mag-cover-sub{font-size:19px;max-width:640px;margin:0 0 18px;opacity:.95}
.mag-cover-route{font-family:"Cormorant Garamond",Georgia,serif;font-size:22px;font-style:italic;margin:0 0 22px}
.mag-pills{display:flex;flex-wrap:wrap;gap:8px}
.mag-pill{font-size:12.5px;font-weight:600;letter-spacing:.04em;padding:6px 14px;border-radius:999px;background:rgba(255,255,255,.16);border:1px solid rgba(255,255,255,.45);backdrop-filter:blur(4px)}

/* Sticky section nav (screen only) */
.mag-nav{position:sticky;top:0;z-index:20;background:rgba(255,253,249,.94);backdrop-filter:blur(6px);border-bottom:1px solid var(--line)}
.mag-nav .mag-wrap{display:flex;gap:4px;align-items:center;overflow-x:auto;padding-top:8px;padding-bottom:8px;scrollbar-width:none}
.mag-nav a{flex:none;font-size:12.5px;font-weight:600;color:var(--ink);text-decoration:none;padding:5px 11px;border-radius:999px;white-space:nowrap}
.mag-nav a:hover{background:var(--sand)}
.mag-nav a.d{min-width:30px;text-align:center;padding:5px 8px;color:var(--mute)}
.mag-nav .sep{width:1px;height:18px;background:var(--line);margin:0 6px;flex:none}
.mag-nav .lbl{flex:none;font-size:10.5px;letter-spacing:.18em;text-transform:uppercase;font-weight:700;color:var(--mute);margin:0 2px 0 4px}

/* Sections */
.mag-sec{padding:64px 0 8px}
.mag-sec h2{font-size:42px;line-height:1.05;margin:.1em 0 .55em}
.mag-intro{display:grid;grid-template-columns:1.6fr 1fr;gap:44px;align-items:start}
.mag-intro-txt{font-size:16.5px}
.mag-intro-txt p:first-child:first-letter{font-family:"Cormorant Garamond",Georgia,serif;float:left;font-size:64px;line-height:.8;padding:6px 8px 0 0;color:var(--red)}
.mag-facts{background:var(--sand);border-radius:4px;padding:22px 24px}
.mag-facts dl{margin:10px 0 0;display:grid;grid-template-columns:auto 1fr;gap:10px 16px;font-size:14px}
.mag-facts dt{color:var(--mute)}
.mag-facts dd{margin:0;font-weight:600}

/* Map + legend */
.mag-route{display:grid;grid-template-columns:1.55fr 1fr;gap:28px;align-items:stretch}
.mag-map{height:440px;border-radius:4px;background:var(--sand);border:1px solid var(--line)}
.mag .leaflet-container img{max-width:none;display:inline}
.mag-legend{list-style:none;margin:0;padding:0;font-size:14px}
.mag-legend li{display:grid;grid-template-columns:34px 1fr;gap:10px;padding:9px 0;border-bottom:1px solid var(--line);align-items:center}
.mag-legend .n{width:28px;height:28px;border-radius:50%;background:var(--red);color:#fff;font-weight:700;font-size:13px;display:flex;align-items:center;justify-content:center}
.mag-legend .n.ap{background:var(--ink);font-size:14px}
.mag-legend small{display:block;color:var(--mute);font-size:12px}
.mag-pin{width:28px;height:28px;border-radius:50%;background:#B3241C;color:#fff;border:2px solid #fff;box-shadow:0 2px 6px rgba(0,0,0,.35);display:flex;align-items:center;justify-content:center;font:700 12px "Open Sans",Arial,sans-serif;white-space:nowrap}
.mag-pin.ap{background:#231f1c}
.mag-pin.wide{width:auto;min-width:28px;padding:0 8px;border-radius:14px}

/* Stays */
.mag-stays{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:18px}
.mag-stay{background:#fff;border:1px solid var(--line);border-radius:4px;overflow:hidden}
.mag-stay-img{display:block;width:100%;aspect-ratio:4/3;object-fit:cover;background:var(--sand)}
.mag-stay-b{padding:12px 14px 14px}
.mag-stay-day{font-size:11px;letter-spacing:.2em;text-transform:uppercase;color:var(--red);font-weight:700}
.mag-stay-name{font-family:"Cormorant Garamond",Georgia,serif;font-size:21px;font-weight:600;line-height:1.15;margin:3px 0 4px}
.mag-stay-meta{font-size:12.5px;color:var(--mute)}

/* Days */
.mag-day{padding:56px 0 8px;border-top:1px solid var(--line)}
.mag-day:first-of-type{border-top:0}
.mag-hero{position:relative;margin:0 0 26px;border-radius:4px;overflow:hidden;aspect-ratio:2/1;background:var(--sand)}
.mag-hero img{width:100%;height:100%;object-fit:cover;object-position:50% 30%}   /* animals / faces sit high in the frame */
.mag-hero figcaption{position:absolute;left:0;right:0;bottom:0;padding:44px 26px 18px;color:#fff;background:linear-gradient(180deg,rgba(0,0,0,0),rgba(0,0,0,.65))}
.mag-dayhead{display:grid;grid-template-columns:auto 1fr;gap:22px;align-items:end;margin:0 0 14px}
.mag-dayno{font-family:"Cormorant Garamond",Georgia,serif;font-size:84px;line-height:.78;color:var(--red);font-weight:600}
.mag-hero .mag-dayno{color:#fff}
.mag-dayno small{display:block;font-family:"Open Sans",Arial,sans-serif;font-size:11px;letter-spacing:.3em;text-transform:uppercase;font-weight:700;margin-bottom:8px}
.mag-day h3{font-size:36px;line-height:1.08;margin:0}
.mag-chips{display:flex;flex-wrap:wrap;gap:8px;margin:0 0 20px}
.mag-chip{font-size:12.5px;padding:5px 12px;border-radius:999px;background:var(--sand);color:var(--ink)}
.mag-chip b{font-weight:700;color:var(--red);margin-right:4px}
.mag-daybody{display:grid;grid-template-columns:1.6fr 1fr;gap:40px}
.mag-daybody.solo{grid-template-columns:1fr;max-width:720px}
.mag-aside{font-size:14px;color:#4a433d;border-left:2px solid var(--ochre);padding-left:18px}
.mag-aside .mag-kicker{color:var(--ochre);margin-bottom:6px}
.mag-aside h4{font-family:"Cormorant Garamond",Georgia,serif;font-size:22px;margin:0 0 6px;font-weight:600;color:var(--ink)}
.mag-acts{margin:4px 0 0;font-size:14px}
.mag-acts b{font-size:11px;letter-spacing:.2em;text-transform:uppercase;color:var(--mute);margin-right:8px}

/* Lodge card */
.mag-lodge{margin:26px 0 0;background:var(--sand);border-radius:4px;overflow:hidden;display:grid;grid-template-columns:1.1fr 1fr}
.mag-lodge-ph{display:grid;grid-template-columns:2fr 1fr;grid-template-rows:1fr 1fr;gap:3px;min-height:280px}
.mag-lodge-ph img{display:block;width:100%;height:100%;object-fit:cover;background:#d9cfbf;min-height:0}
.mag-lodge-ph img:first-child{grid-row:1/3}
.mag-lodge-ph.one{grid-template-columns:1fr;grid-template-rows:1fr}
.mag-lodge-ph.one img:first-child{grid-row:auto}
.mag-lodge-ph.two{grid-template-rows:1fr}
.mag-lodge-ph.two img:first-child{grid-row:auto}
.mag-lodge-b{padding:22px 26px}
.mag-lodge-b h4{font-family:"Cormorant Garamond",Georgia,serif;font-size:28px;line-height:1.1;margin:4px 0 10px;font-weight:600}
.mag-lodge-b p{font-size:14px}
.mag-lodge-meta{font-size:12.5px;color:var(--mute);margin-top:6px}
.mag-lodge.noph{grid-template-columns:1fr}
.mag-again{margin:22px 0 0;padding:12px 16px;background:var(--sand);border-radius:4px;font-size:14px}
.mag-again b{font-family:"Cormorant Garamond",Georgia,serif;font-size:19px;font-weight:600}

/* Prices, lists, contacts, terms */
.mag table{width:100%;border-collapse:collapse;font-size:14.5px}
.mag th{text-align:left;font-size:11px;letter-spacing:.18em;text-transform:uppercase;color:var(--mute);font-weight:700;padding:8px 12px;border-bottom:2px solid var(--ink)}
.mag td{padding:11px 12px;border-bottom:1px solid var(--line)}
.mag td.num{font-family:"Cormorant Garamond",Georgia,serif;font-size:22px;font-weight:600;text-align:right;white-space:nowrap}
.mag-prices{display:grid;grid-template-columns:1fr 1fr;gap:40px;align-items:start}
.mag-notes{font-size:14px;color:#4a433d}
.mag-lists{display:grid;grid-template-columns:1fr 1fr;gap:40px}
.mag-lists h3{font-size:28px;margin:0 0 10px}
.mag-lists ul{list-style:none;margin:0;padding:0;font-size:14.5px}
.mag-lists li{position:relative;padding:7px 0 7px 28px;border-bottom:1px solid var(--line)}
.mag-lists li:before{position:absolute;left:2px;top:7px;font-weight:700}
.mag-in li:before{content:"✓";color:#2f7a45}
.mag-out li:before{content:"✕";color:var(--red)}
.mag-terms{font-size:13.5px;color:#4a433d;columns:2;column-gap:40px}
.mag-terms h1,.mag-terms h2,.mag-terms h3{font-family:"Open Sans",Arial,sans-serif;font-size:14px;font-weight:700;margin:14px 0 6px;break-after:avoid}
.mag-terms ul{padding-left:1.1em}
.mag-terms details summary{cursor:pointer}
.mag-foot{margin:70px 0 0;padding:40px 0 50px;background:var(--ink);color:#e9e2d6;font-size:13.5px}
.mag-foot .mag-wrap{display:flex;gap:24px;align-items:center;flex-wrap:wrap}
.mag-foot img{width:64px;height:64px;border-radius:50%;background:#fff;padding:2px}
.mag-foot a{color:#fff}
.mag-foot .mag-serif{font-size:24px;color:#fff}

@media (max-width:760px){
  .mag-wrap{padding:0 16px}
  .mag-cover{min-height:78vh}.mag-cover-in{padding:0 16px 36px}.mag-cover-logo{left:16px;top:16px;width:70px;height:70px}
  .mag-cover h1{font-size:44px}.mag-sec h2{font-size:32px}.mag-day h3{font-size:28px}.mag-dayno{font-size:60px}
  .mag-intro,.mag-route,.mag-daybody,.mag-lodge,.mag-prices,.mag-lists{grid-template-columns:1fr;gap:24px}
  .mag-map{height:320px}.mag-hero{aspect-ratio:16/9}.mag-terms{columns:1}
}
@media print{
  @page{size:A4;margin:14mm 13mm}
  @page :first{margin:0}
  .mag{font-size:10.5pt;background:#fff}
  .mag-nav,.mag-noprint{display:none!important}
  .mag-wrap{max-width:none;padding:0}
  .mag-cover{height:297mm;min-height:0;break-after:page}
  .mag-cover-in{padding:0 16mm 22mm}.mag-cover-logo{top:14mm;left:16mm}
  .mag-sec{padding:10mm 0 2mm}
  #mag-overview,#mag-route,#mag-days,#mag-prices{break-before:page;padding-top:0}
  .leaflet-control-zoom{display:none}
  .mag-day{padding:9mm 0 2mm;break-inside:auto}
  .mag-hero{break-after:avoid}
  .mag-hero,.mag-lodge,.mag-stay,.mag-again,.mag-aside,tr,.mag-lists li{break-inside:avoid}
  .mag-dayhead,.mag-sec h2,.mag-sec h3{break-after:avoid}
  .mag-stays{break-before:avoid}
  .mag-map{height:110mm}
  .mag-lodge-ph{min-height:62mm}
  .mag-foot{margin-top:10mm;break-inside:avoid}
  .mag a{text-decoration:none}
}
';
}

/** The document body (HTML). $publicUrl: link to the digital itinerary, shown in the footer (PDF). */
function iti_mag_render(array $D, string $publicUrl = ''): string {
    $T = $D['T'];
    $M = iti_mag_labels($D['lang']);
    $stays = iti_mag_stays($D['days']);
    $cover = iti_mag_cover($D);
    $routeNames = iti_mag_route_names($D['days']);
    $map = isset($D['map']) ? $D['map'] : ['route' => [], 'markers' => [], 'legend' => []];
    $hasMap = count($map['markers']) >= 2;
    $nNights = 0; foreach ($stays as $s) $nNights += $s['nights'];
    $lodgeNames = []; foreach ($stays as $s) $lodgeNames[$s['lodge']] = true;
    $seenLodge = []; $seenDestPhoto = [];
    $paxLabel = iti_mag_pax($D, $M);
    $dateRange = iti_mag_date_range($D, $M);
    ob_start(); ?>
<div class="mag" lang="<?= h($D['lang']) ?>">

  <section class="mag-cover"<?= $cover !== '' ? ' style="background-image:url(\'' . h(iti_photo_variant($cover, 1600)) . '\')"' : '' ?>>
    <?php if ($D['logo']): ?><img class="mag-cover-logo" src="<?= h($D['logo']) ?>" alt="Savannah Explorers"><?php endif; ?>
    <div class="mag-cover-in">
      <div class="mag-kicker"><?= h($T['kicker']) ?></div>
      <h1><?= h($D['title']) ?></h1>
      <?php if ($D['subtitle'] !== ''): ?><p class="mag-cover-sub"><?= h($D['subtitle']) ?></p><?php endif; ?>
      <?php if ($routeNames && $D['subtitle'] === ''): ?><p class="mag-cover-route"><?= h(implode(' · ', $routeNames)) ?></p><?php endif; ?>
      <div class="mag-pills">
        <?php if ($dateRange !== ''): ?><span class="mag-pill"><?= h($dateRange) ?></span><?php endif; ?>
        <span class="mag-pill"><?= h($D['duration']) ?></span>
        <span class="mag-pill"><?= h($M['private']) ?></span>
        <?php if ($paxLabel !== ''): ?><span class="mag-pill"><?= h($paxLabel) ?></span><?php endif; ?>
      </div>
    </div>
  </section>

  <nav class="mag-nav"><div class="mag-wrap">
    <a href="#mag-overview"><?= h($M['overview']) ?></a>
    <?php if ($hasMap || $stays): ?><a href="#mag-route"><?= h($M['route']) ?></a><?php endif; ?>
    <span class="sep"></span>
    <span class="lbl"><?= h($M['days_nav']) ?></span>
    <?php foreach ($D['days'] as $d): ?><a class="d" href="#day-<?= (int)$d['n'] ?>" title="<?= h($T['day'] . ' ' . $d['n'] . ($d['title'] !== '' ? ' · ' . $d['title'] : '')) ?>"><?= (int)$d['n'] ?></a><?php endforeach; ?>
    <span class="sep"></span>
    <?php if ($D['prices']): ?><a href="#mag-prices"><?= h($M['prices_nav']) ?></a><?php endif; ?>
    <a href="#mag-info"><?= h($M['info_nav']) ?></a>
    <span style="flex:1"></span>
    <a href="#" onclick="magPrint(this);return false" style="background:var(--red);color:#fff">⤓ <?= h($M['pdf']) ?></a>
  </div></nav>

  <div class="mag-wrap">

    <section class="mag-sec" id="mag-overview">
      <div class="mag-kicker"><?= h($T['brief']) ?></div>
      <h2><?= h($M['overview']) ?></h2>
      <div class="mag-intro">
        <div class="mag-intro-txt"><?= iti_doc_paras($D['intro']) ?></div>
        <aside class="mag-facts">
          <div class="mag-kicker"><?= h($M['facts']) ?></div>
          <dl>
            <dt><?= h(ucfirst($T['days'])) ?></dt><dd><?= h($D['duration']) ?></dd>
            <?php if ($routeNames): ?><dt><?= h($M['destinations']) ?></dt><dd><?= h(implode(', ', $routeNames)) ?></dd><?php endif; ?>
            <?php if ($lodgeNames): ?><dt><?= h($M['lodges']) ?></dt><dd><?= implode('<br>', array_map('h', iti_mag_lodge_nights($stays, $T))) ?></dd><?php endif; ?>
            <?php if ($dateRange !== ''): ?><dt><?= h($M['dates']) ?></dt><dd><?= h($dateRange) ?></dd><?php endif; ?>
            <?php if ($paxLabel !== ''): ?><dt><?= h(ucfirst($T['pax'])) ?></dt><dd><?= h($paxLabel) ?></dd><?php endif; ?>
          </dl>
        </aside>
      </div>
    </section>

    <?php if ($hasMap || $stays): ?>
    <section class="mag-sec" id="mag-route">
      <div class="mag-kicker"><?= h($D['duration']) ?></div>
      <h2><?= h($M['route']) ?></h2>
      <?php if ($hasMap): ?>
      <div class="mag-route">
        <div class="mag-map" id="mag-map" data-map="<?= h(json_encode($map, JSON_UNESCAPED_UNICODE)) ?>"></div>
        <ol class="mag-legend">
          <?php foreach ($map['legend'] as $l): $ap = $l['role'] !== 'stop'; ?>
            <li><span class="n<?= $ap ? ' ap' : '' ?>"><?= $ap ? '✈' : (int)$l['num'] ?></span>
              <span><?= h($l['name']) ?>
                <small><?= $ap ? h($l['role'] === 'start' ? $M['start'] : $M['end']) . ($l['code'] ? ' · ' . h($l['code']) : '') : '' ?><?= $l['dist'] !== null ? ($ap ? ' · ' : '') . '≈ ' . number_format($l['dist'], 0, ',', '.') . ' ' . h($M['km']) : '' ?></small></span></li>
          <?php endforeach; ?>
        </ol>
      </div>
      <?php endif; ?>
      <?php if ($stays): ?>
        <h3 class="mag-serif" style="font-size:28px;margin:44px 0 16px"><?= h($M['stays']) ?></h3>
        <div class="mag-stays">
          <?php foreach ($stays as $s): ?>
            <a class="mag-stay" href="#day-<?= (int)$s['day'] ?>" style="text-decoration:none;color:inherit">
              <?php if ($s['photo'] !== ''): ?><img class="mag-stay-img" src="<?= h(iti_photo_variant($s['photo'], 480)) ?>" alt="<?= h($s['lodge']) ?>" loading="lazy" decoding="async"><?php else: ?><div class="mag-stay-img"></div><?php endif; ?>
              <div class="mag-stay-b">
                <div class="mag-stay-day"><?= h($T['day']) ?> <?= (int)$s['day'] ?><?= $s['nights'] > 1 ? '–' . ((int)$s['day'] + $s['nights'] - 1) : '' ?></div>
                <div class="mag-stay-name"><?= h($s['lodge']) ?></div>
                <div class="mag-stay-meta"><?= $s['dest'] !== '' ? h($s['dest']) . ' · ' : '' ?><?= (int)$s['nights'] ?> <?= h($s['nights'] === 1 ? $T['night1'] : $T['nights']) ?> · <?= h($s['meals']) ?></div>
              </div>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>
    <?php endif; ?>

    <section class="mag-sec" id="mag-days">
      <div class="mag-kicker"><?= h($T['program']) ?></div>
      <?php foreach ($D['days'] as $i => $d):
          $photo = ($d['dest_photo'] !== '' && empty($seenDestPhoto[$d['dest_photo']])) ? $d['dest_photo'] : '';
          if ($photo !== '') $seenDestPhoto[$photo] = true;
          $lk = $d['lodge_key'] !== '' ? $d['lodge_key'] : $d['lodge'];
          $prevSame = $i > 0 && $d['lodge'] !== '' && ($D['days'][$i - 1]['lodge_key'] !== '' ? $D['days'][$i - 1]['lodge_key'] : $D['days'][$i - 1]['lodge']) === $lk;
          $firstLodge = $d['lodge'] !== '' && !isset($seenLodge[$lk]);
          $aside = $d['dest_desc'] !== '';
      ?>
      <article class="mag-day" id="day-<?= (int)$d['n'] ?>">
        <?php if ($photo !== ''): ?>
          <figure class="mag-hero"><img src="<?= h(iti_photo_variant($photo, 1600)) ?>"<?= ($ss = iti_photo_srcset($photo)) !== '' ? ' srcset="' . h($ss) . '" sizes="(max-width: 760px) 100vw, 930px"' : '' ?> alt="<?= h($d['dest']) ?>" loading="lazy" decoding="async">
            <figcaption><div class="mag-dayhead"><div class="mag-dayno"><small><?= h($T['day']) ?><?= !empty($d['date']) ? ' · ' . h(iti_mag_date($d['date'], $M, false, true)) : '' ?></small><?= (int)$d['n'] ?></div><h3><?= h($d['title']) ?></h3></div></figcaption>
          </figure>
        <?php else: ?>
          <div class="mag-dayhead"><div class="mag-dayno"><small><?= h($T['day']) ?><?= !empty($d['date']) ? ' · ' . h(iti_mag_date($d['date'], $M, false, true)) : '' ?></small><?= (int)$d['n'] ?></div><h3><?= h($d['title']) ?></h3></div>
        <?php endif; ?>

        <div class="mag-chips">
          <?php foreach ($d['flights'] ?? [] as $fl): ?><span class="mag-chip"><b>✈</b><?= h($fl) ?></span><?php endforeach; ?>
          <?php foreach ($d['transfers'] as $tr): ?><span class="mag-chip"><b>⇢</b><?= h($tr) ?></span><?php endforeach; ?>
          <span class="mag-chip"><b><?= h($T['meals']) ?></b><?= h($d['meals']) ?></span>
          <?php if ($d['lodge'] !== ''): ?><span class="mag-chip"><b><?= h($T['overnight']) ?></b><?= h($d['lodge']) ?></span><?php endif; ?>
        </div>

        <div class="mag-daybody<?= $aside ? '' : ' solo' ?>">
          <div>
            <?= iti_doc_paras($d['narrative']) ?>
            <?php if ($d['activities']): ?><p class="mag-acts"><b><?= h($T['activities']) ?></b><?= h(implode(' · ', $d['activities'])) ?></p><?php endif; ?>
          </div>
          <?php if ($aside): ?>
            <aside class="mag-aside">
              <div class="mag-kicker"><?= h($M['about_dest']) ?></div>
              <h4><?= h($d['dest']) ?></h4>
              <?= iti_doc_paras($d['dest_desc']) ?>
            </aside>
          <?php endif; ?>
        </div>

        <?php if ($firstLodge): $seenLodge[$lk] = (int)$d['n']; $ph = array_slice($d['lodge_photos'], 0, 3); ?>
          <div class="mag-lodge<?= $ph ? '' : ' noph' ?>">
            <?php if ($ph): ?>
              <div class="mag-lodge-ph<?= count($ph) === 1 ? ' one' : (count($ph) === 2 ? ' two' : '') ?>">
                <?php foreach ($ph as $k => $u): ?><img src="<?= h(iti_photo_variant($u, $k === 0 ? 960 : 480)) ?>" alt="<?= h($d['lodge']) ?>" loading="lazy" decoding="async"><?php endforeach; ?>
              </div>
            <?php endif; ?>
            <div class="mag-lodge-b">
              <div class="mag-kicker"><?= h($T['overnight']) ?></div>
              <h4><?= h($d['lodge']) ?></h4>
              <?= iti_doc_paras($d['lodge_desc']) ?>
              <div class="mag-lodge-meta">
                <?= h($d['lodge_area'] ?? '') ?>
                <?php if ($d['lodge_url'] !== ''): ?><?= !empty($d['lodge_area']) ? ' · ' : '' ?><a href="<?= h($d['lodge_url']) ?>" target="_blank" rel="noopener"><?= h($M['website']) ?> ↗</a><?php endif; ?>
              </div>
            </div>
          </div>
        <?php elseif ($d['lodge'] !== '' && !$prevSame): ?>
          <div class="mag-again"><span class="mag-kicker"><?= h($T['overnight']) ?></span><br><b><?= h($d['lodge']) ?></b> — <a href="#day-<?= (int)$seenLodge[$lk] ?>"><?= h($M['see_day']) ?> <?= (int)$seenLodge[$lk] ?></a></div>
        <?php endif; ?>
      </article>
      <?php endforeach; ?>
    </section>

    <?php if ($D['prices']): ?>
    <section class="mag-sec" id="mag-prices">
      <div class="mag-kicker"><?= h($T['pp']) ?></div>
      <h2><?= h($T['prices']) ?></h2>
      <div class="mag-prices">
        <table>
          <tr><th><?= h($T['group']) ?></th><th style="text-align:right"><?= h($T['pp']) ?></th></tr>
          <?php foreach ($D['prices'] as $pr): ?>
            <tr><td><?= h($pr['label'] ?? '') ?></td><td class="num"><?= h(isset($pr['price']) ? number_format((float)$pr['price'], 0, ',', '.') . ' ' . ($pr['currency'] ?? 'USD') : '') ?></td></tr>
          <?php endforeach; ?>
        </table>
        <?php if ($D['price_notes'] !== ''): ?><div class="mag-notes"><?= iti_doc_paras($D['price_notes']) ?></div><?php endif; ?>
      </div>
    </section>
    <?php endif; ?>

    <?php if ($D['incl'] || $D['excl']): ?>
    <section class="mag-sec">
      <div class="mag-lists">
        <?php if ($D['incl']): ?><div class="mag-in"><h3><?= h($T['incl']) ?></h3><ul><?php foreach ($D['incl'] as $x): ?><li><?= h($x) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
        <?php if ($D['excl']): ?><div class="mag-out"><h3><?= h($T['excl']) ?></h3><ul><?php foreach ($D['excl'] as $x): ?><li><?= h($x) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
      </div>
    </section>
    <?php endif; ?>

    <section class="mag-sec" id="mag-info">
      <h2><?= h($T['contacts']) ?></h2>
      <table>
        <tr><th><?= h($T['ref']) ?></th><th><?= h($T['phone']) ?></th><th><?= h($T['email']) ?></th></tr>
        <?php foreach ($D['contacts'] as $c): ?>
          <tr><td><?= h($c['name']) ?></td><td><?= h($c['phone']) ?></td><td><?= $c['email'] !== '' ? '<a href="mailto:' . h($c['email']) . '">' . h($c['email']) . '</a>' : '' ?></td></tr>
        <?php endforeach; ?>
      </table>
      <?php if ($D['terms'] !== ''): ?>
        <h3 class="mag-serif" style="font-size:28px;margin:44px 0 14px"><?= h($T['terms']) ?></h3>
        <div class="mag-terms"><?= strpos($D['terms'], '<') !== false ? iti_sanitize_richtext($D['terms']) : iti_doc_paras($D['terms']) ?></div>
      <?php endif; ?>
    </section>
  </div>

  <footer class="mag-foot"><div class="mag-wrap">
    <?php if ($D['logo']): ?><img src="<?= h($D['logo']) ?>" alt=""><?php endif; ?>
    <div>
      <div class="mag-serif">Savannah Explorers</div>
      <?= h($D['contacts'][0]['phone'] ?? '') ?> · <a href="mailto:<?= h($D['contacts'][0]['email'] ?? '') ?>"><?= h($D['contacts'][0]['email'] ?? '') ?></a>
      <?php if ($publicUrl !== ''): ?><br><?= h($M['online']) ?>: <a href="<?= h($publicUrl) ?>"><?= h($publicUrl) ?></a><?php endif; ?>
    </div>
  </div></footer>
</div>
<?php
    return (string)ob_get_clean();
}

/** Leaflet map from the data-map JSON (iti_get_program_map()). Static fallback if Leaflet is missing. */
function iti_mag_map_js(): string {
    return <<<'JS'
// "Download PDF": photos load as you scroll, so load them all first, then print (max 15 s).
function magPrint(btn) {
  var imgs = Array.prototype.slice.call(document.querySelectorAll('.mag img'));
  imgs.forEach(function (i) { i.loading = 'eager'; });
  var label = btn ? btn.innerHTML : '';
  if (btn) btn.innerHTML = '…';
  var done = false, go = function () { if (done) return; done = true; if (btn) btn.innerHTML = label; window.print(); };
  Promise.all(imgs.map(function (i) {
    return i.complete && i.naturalWidth ? null : new Promise(function (r) { i.addEventListener('load', r); i.addEventListener('error', r); });
  })).then(go);
  setTimeout(go, 15000);
}
(function(){
  var el = document.getElementById('mag-map');
  if (!el || !window.L) return;
  var M = JSON.parse(el.getAttribute('data-map') || '{}');
  if (!M.markers || M.markers.length < 2) return;
  var map = L.map(el, {scrollWheelZoom:false, zoomControl:true, attributionControl:true});
  L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Topo_Map/MapServer/tile/{z}/{y}/{x}',
    {maxZoom:13, attribution:'Tiles &copy; Esri'}).addTo(map);
  var line = M.route.map(function(p){ return [p.lat, p.lng]; });
  L.polyline(line, {color:'#ffffff', weight:7, opacity:.8}).addTo(map);
  L.polyline(line, {color:'#B3241C', weight:3.5, opacity:.95, dashArray:'2 7', lineCap:'round'}).addTo(map);
  M.markers.forEach(function(m){
    var txt = m.label || '✈', wide = txt.length > 2, w = wide ? 14 + txt.length * 7 : 28;
    var cls = 'mag-pin' + (m.label ? '' : ' ap') + (wide ? ' wide' : '');
    L.marker([m.lat, m.lng], {icon:L.divIcon({className:'', html:'<div class="'+cls+'">'+txt+'</div>', iconSize:[w,28], iconAnchor:[w/2,14]})})
      .bindTooltip(m.name, {direction:'top', offset:[0,-12]}).addTo(map);
  });
  map.fitBounds(L.latLngBounds(line).pad(0.12));
  // Print: open collapsed parts and refit the map to the printed size.
  window.addEventListener('beforeprint', function(){
    document.querySelectorAll('.mag img[loading="lazy"]').forEach(function(i){ i.loading = 'eager'; });
    document.querySelectorAll('.mag details').forEach(function(d){ d.open = true; });
    map.invalidateSize(); map.fitBounds(L.latLngBounds(line).pad(0.12));
  });
})();
JS;
}

/** Full standalone HTML page (public link / internal preview). $bar: optional toolbar above the document. */
function iti_mag_page(array $D, string $bar = '', string $publicUrl = ''): void {
    header('Content-Type: text/html; charset=utf-8');
    ?><!DOCTYPE html>
<html lang="<?= h($D['lang']) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($D['title']) ?> — Savannah Explorers</title>
<meta name="robots" content="noindex">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,500;0,600;1,500&family=Open+Sans:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<style>
html,body{margin:0;background:#fffdf9}
.etn-bar{font-family:"Open Sans","Segoe UI",Arial,sans-serif;font-size:13px;display:flex;gap:6px;align-items:center;flex-wrap:wrap;
         margin:0;padding:8px 16px;background:#231f1c;color:#fff}
.etn-bar a{color:#e9e2d6;text-decoration:none;padding:4px 10px;border-radius:14px;font-weight:600}
.etn-bar a.on{background:#B3241C;color:#fff}
.etn-bar .sep{flex:1}
.etn-bar button{font:inherit}
@media print{.etn-bar{display:none}}
<?= iti_mag_css() ?>
</style>
</head>
<body>
<?= $bar ?>
<?= iti_mag_render($D, $publicUrl) ?>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script><?= iti_mag_map_js() ?></script>
</body>
</html>
<?php
}
