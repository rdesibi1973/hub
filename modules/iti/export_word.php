<?php
/**
 * modules/iti/export_word.php
 * Genera il programma come .docx brandizzato via PhpWord
 * Richiede: composer require phpoffice/phpword
 */
require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_once __DIR__ . '/includes/iti_functions.php';

$id = (int)($_GET['id'] ?? 0);
if (!$id) die('No program ID specified.');

$program = iti_get_program($id);
if (!$program) die('Program not found.');

// PhpWord via Composer
$vendor = __DIR__ . '/../../vendor/autoload.php';
if (!file_exists($vendor)) {
    die('<h2>PhpWord not installed</h2><p>Run <code>composer require phpoffice/phpword</code> in the hub root, then redeploy.</p>');
}
require_once $vendor;

$lang = $_GET['lang'] ?? $program['display_language'] ?? 'en';
if (!in_array($lang, ITI_LANGS)) $lang = 'en';
$curr = $_GET['curr'] ?? $program['display_currency'] ?? 'USD';
if (!in_array($curr, ITI_CURRENCIES)) $curr = 'USD';

$days       = iti_get_program_days($id);
$prices     = iti_get_program_prices($id);
$inclusions = iti_get_program_inclusions($id);
$included   = array_filter($inclusions, fn($i)=>$i['item_type']==='inclusion');
$excluded   = array_filter($inclusions, fn($i)=>$i['item_type']==='exclusion');
$req        = $program['request_id'] ? iti_get_request((int)$program['request_id']) : null;

// Consultant (programme owner) + bio for header block
$consultant     = iti_get_consultant($program['created_by'] ?? '');
$consultant_bio = $consultant ? iti_consultant_bio($consultant, $lang) : '';
// Local filesystem dir where profile photos live (URL → path mapping)
$PROFILE_DIR_EXPORT = __DIR__ . '/uploads/profiles';

// T&C
$tc = null;
if ($program['terms_id']) {
    $s = db()->prepare('SELECT * FROM iti_terms_conditions WHERE id=?');
    $s->execute([$program['terms_id']]);
    $tc = $s->fetch();
}

// ── Colori brand ────────────────────────────────────────────
$RED     = 'C0211B';
$BLACK   = '1A1A1A';
$GREY    = '999591';
$OFF_WHITE = 'F7F5F2';

// ── Crea documento ──────────────────────────────────────────
$phpWord = new \PhpOffice\PhpWord\PhpWord();
$phpWord->setDefaultFontName('Calibri');
$phpWord->setDefaultFontSize(11);

// Stili
$phpWord->addTitleStyle(1, ['name'=>'Merriweather','size'=>22,'bold'=>true,'color'=>$BLACK]);
$phpWord->addTitleStyle(2, ['name'=>'Calibri','size'=>14,'bold'=>true,'color'=>$RED]);
$phpWord->addTitleStyle(3, ['name'=>'Calibri','size'=>11,'bold'=>true,'color'=>$BLACK]);

$phpWord->addFontStyle('brand',    ['name'=>'Calibri','size'=>9,'color'=>$GREY,'caps'=>true]);
$phpWord->addFontStyle('hero',     ['name'=>'Merriweather','size'=>22,'bold'=>true,'color'=>$BLACK]);
$phpWord->addFontStyle('subtitle', ['name'=>'Calibri','size'=>13,'color'=>$BLACK]);
$phpWord->addFontStyle('meta',     ['name'=>'Calibri','size'=>10,'color'=>$GREY]);
$phpWord->addFontStyle('dayNum',   ['name'=>'Calibri','size'=>9,'bold'=>true,'color'=>$RED,'caps'=>true]);
$phpWord->addFontStyle('dayTitle', ['name'=>'Merriweather','size'=>12,'bold'=>true,'color'=>$BLACK]);
$phpWord->addFontStyle('label',    ['name'=>'Calibri','size'=>9,'bold'=>true,'color'=>$GREY,'caps'=>true]);
$phpWord->addFontStyle('normal',   ['name'=>'Calibri','size'=>11,'color'=>$BLACK]);
$phpWord->addFontStyle('price',    ['name'=>'Calibri','size'=>14,'bold'=>true,'color'=>$RED]);
$phpWord->addFontStyle('small',    ['name'=>'Calibri','size'=>9,'color'=>$GREY]);
$phpWord->addFontStyle('incl',     ['name'=>'Calibri','size'=>11,'color'=>'2E7D32']);
$phpWord->addFontStyle('excl',     ['name'=>'Calibri','size'=>11,'color'=>$RED]);
$phpWord->addFontStyle('tcFont',   ['name'=>'Calibri','size'=>9,'color'=>$GREY]);
$phpWord->addFontStyle('consName', ['name'=>'Merriweather','size'=>12,'bold'=>true,'color'=>$BLACK]);
$phpWord->addFontStyle('consLabel',['name'=>'Calibri','size'=>8,'bold'=>true,'color'=>$RED,'caps'=>true]);
$phpWord->addFontStyle('consMeta', ['name'=>'Calibri','size'=>9,'color'=>$GREY]);
$phpWord->addFontStyle('consBio',  ['name'=>'Calibri','size'=>9,'color'=>'7A7A7A']);

$phpWord->addParagraphStyle('spacer',  ['spaceBefore'=>80]);
$phpWord->addParagraphStyle('divider', ['spaceBefore'=>120,'borderBottomSize'=>4,'borderBottomColor'=>$OFF_WHITE]);

// ── Sezione principale ───────────────────────────────────────
$section = $phpWord->addSection([
    'marginTop'    => 900,
    'marginBottom' => 900,
    'marginLeft'   => 1000,
    'marginRight'  => 1000,
]);

// Header
$header = $section->addHeader();
$ht = $header->addTable(['borderSize'=>0,'cellMarginTop'=>80,'cellMarginBottom'=>80]);
$ht->addRow();
$c1 = $ht->addCell(4500);
$c1->addText(strtoupper(iti_setting('company_name','Savannah Explorers')), 'brand', ['align'=>'left']);
$c2 = $ht->addCell(4500);
$c2->addText(iti_field($program,'title',$lang), 'small', ['align'=>'right']);

// Footer
$footer = $section->addFooter();
$footer->addText(date('F Y') . ' · Confidential', 'small', ['align'=>'center']);

// ── COPERTINA: brand + titolo ────────────────────────────────
$section->addText(strtoupper(iti_setting('company_name','Savannah Explorers')), 'brand', 'spacer');
$section->addText(iti_field($program,'title',$lang), 'hero');
if (iti_field($program,'subtitle',$lang)) {
    $section->addText(iti_field($program,'subtitle',$lang), 'subtitle', ['spaceBefore'=>80]);
}

$meta = [];
$meta[] = iti_duration_label((int)$program['duration_days'],$lang);
if ($req && $req['client_name']) $meta[] = $req['client_name'];
$meta[] = $program['pax_adults'].'A'.($program['pax_children']?'+'.$program['pax_children'].'C':'');
if ($program['flights_included']) $meta[] = '✈ Flights included';
$section->addText(implode('  ·  ',$meta), 'meta', ['spaceBefore'=>120,'spaceAfter'=>300]);

// ── CONSULENTE (owner del programma): foto + bio + contatti ──
if ($consultant) {
    // Risolvi il file foto locale dall'URL pubblico salvato in photo_url
    $photo_local = null;
    if (!empty($consultant['photo_url'])) {
        $fname = basename(parse_url($consultant['photo_url'], PHP_URL_PATH));
        $cand  = $PROFILE_DIR_EXPORT . '/' . $fname;
        if ($fname !== '' && is_file($cand)) $photo_local = $cand;
    }

    $section->addText('', null, ['borderBottomSize'=>4,'borderBottomColor'=>$OFF_WHITE,'spaceAfter'=>120]);
    $section->addText(iti_lbl_consultant($lang), 'consLabel', ['spaceAfter'=>80]);

    $ctbl = $section->addTable(['borderSize'=>0,'cellMarginRight'=>160]);
    $ctbl->addRow();
    // Colonna foto (se presente)
    if ($photo_local) {
        $pcell = $ctbl->addCell(1500, ['valign'=>'top']);
        $pcell->addImage($photo_local, [
            'width'=>90,'height'=>90,'marginTop'=>0,'marginLeft'=>0,
        ]);
        $tcell = $ctbl->addCell(7500, ['valign'=>'top']);
    } else {
        $tcell = $ctbl->addCell(9000, ['valign'=>'top']);
    }

    if (!empty($consultant['full_name'])) {
        $tcell->addText($consultant['full_name'], 'consName', ['spaceAfter'=>40]);
    }
    $contacts = [];
    if (!empty($consultant['email']))    $contacts[] = $consultant['email'];
    if (!empty($consultant['whatsapp'])) $contacts[] = 'WhatsApp: ' . $consultant['whatsapp'];
    if ($contacts) $tcell->addText(implode('   ·   ', $contacts), 'consMeta', ['spaceAfter'=>80]);

    if ($consultant_bio !== '') {
        iti_richtext_to_phpword($tcell, $consultant_bio, 'consBio', ['spaceAfter'=>60]);
    }
}

$section->addPageBreak();

// ── GIORNI ──────────────────────────────────────────────────
foreach ($days as $day) {
    $acts    = iti_get_day_activities((int)$day['id']);
    $flights = iti_get_day_flights((int)$day['id']);
    $title   = iti_field($day,'day_title',$lang);
    $narr    = iti_field($day,'narrative',$lang);

    // Day header row
    $dt = $section->addTable(['borderSize'=>0]);
    $dt->addRow();
    $dNum = $dt->addCell(1000);
    $dNum->addText('DAY '.$day['day_number'], 'dayNum');
    $dTit = $dt->addCell(8000);
    if ($title) $dTit->addText($title, 'dayTitle');

    // Rule line
    $section->addText('', null, ['borderBottomSize'=>4,'borderBottomColor'=>$OFF_WHITE,'spaceAfter'=>80]);

    // Lodge / Starting point
    $start_name = iti_start_display_name($day);
    if ($start_name) {
        // Show label as LODGE only when starting point is actually a lodge
        $section->addText($day['start_lodge_name'] ? 'LODGE' : 'STARTING POINT', 'label');
        $txt = $start_name;
        // Append destination context only when starting from a lodge
        if ($day['start_lodge_name'] && $day['start_dest_name']) $txt .= '  —  '.$day['start_dest_name'];
        if ($day['end_lodge_name'] && $day['end_lodge_name'] !== $start_name) $txt .= '  →  '.$day['end_lodge_name'];
        $section->addText($txt, 'normal', ['spaceAfter'=>80]);
    }

    // Flights
    if ($flights) {
        $section->addText('FLIGHTS', 'label');
        foreach ($flights as $fl) {
            $ftxt = ($fl['from_code']?:$fl['from_airport']).' → '.($fl['to_code']?:$fl['to_airport']);
            if ($fl['departure_time']) $ftxt .= '  '.substr($fl['departure_time'],0,5);
            if ($fl['operator']) $ftxt .= '  ·  '.$fl['operator'];
            $section->addText($ftxt, 'normal');
        }
        $section->addText('', null, ['spaceAfter'=>60]);
    }

    // Activities
    if ($acts) {
        $section->addText('ACTIVITIES', 'label');
        $actLine = implode('  ·  ', array_map(fn($a)=>iti_field($a,'name',$lang), $acts));
        $section->addText($actLine, 'normal', ['spaceAfter'=>80]);
    }

    // Meals
    $meals = [];
    if ($day['meal_breakfast']) $meals[] = 'Breakfast';
    if ($day['meal_lunch'])     $meals[] = 'Lunch';
    if ($day['meal_dinner'])    $meals[] = 'Dinner';
    if ($meals) {
        $section->addText('MEALS', 'label');
        $section->addText(implode('  ·  ',$meals), 'normal', ['spaceAfter'=>80]);
    }

    // Narrative
    if ($narr) {
        foreach (explode("\n", $narr) as $para) {
            if (trim($para) !== '') {
                $section->addText(trim($para), 'normal', ['spaceAfter'=>80,'lineHeight'=>1.6]);
            }
        }
    }

    $section->addText('', null, ['spaceAfter'=>200]);
}

// ── PREZZI ──────────────────────────────────────────────────
if ($prices) {
    $section->addPageBreak();
    $section->addTitle('Prices', 2);
    $section->addText('', null, ['spaceAfter'=>80]);

    $c = strtolower($curr);
    $ptable = $section->addTable([
        'borderSize'=>4,'borderColor'=>$OFF_WHITE,
        'cellMarginTop'=>100,'cellMarginBottom'=>100,
        'cellMarginLeft'=>140,'cellMarginRight'=>140,
    ]);

    // Header row
    $ptable->addRow(null,['tblHeader'=>true]);
    foreach (['Category','Per person ('.$curr.')','Single suppl.','Child'] as $hh) {
        $cell = $ptable->addCell(2000,['bgColor'=>$BLACK]);
        $cell->addText($hh, ['name'=>'Calibri','size'=>9,'bold'=>true,'color'=>'FFFFFF','caps'=>true]);
    }

    foreach (ITI_PRICE_CATEGORIES as $cat => $cat_label) {
        if (!isset($prices[$cat])) continue;
        $p = $prices[$cat];
        $ptable->addRow();
        $ptable->addCell(2000)->addText($cat_label, ['name'=>'Calibri','size'=>11,'bold'=>true]);
        $ptable->addCell(2000)->addText(iti_money((float)($p["price_per_pax_{$c}"]??0),$curr), ['name'=>'Calibri','size'=>13,'bold'=>true,'color'=>$RED]);
        $ptable->addCell(2000)->addText(iti_money((float)($p["single_suppl_{$c}"]??0),$curr), 'normal');
        $ptable->addCell(2000)->addText(iti_money((float)($p["child_price_{$c}"]??0),$curr), 'normal');
    }

    if (!$program['flights_included']) {
        $section->addText('* Internal flights are not included in the prices above.', 'small', ['spaceBefore'=>100]);
    }
}

// ── INCLUSI / ESCLUSI ───────────────────────────────────────
if ($included || $excluded) {
    $section->addText('', null, ['spaceBefore'=>200]);
    $section->addTitle('Included / Excluded', 2);

    if ($included) {
        $section->addText('Included', 'dayTitle', ['spaceBefore'=>120]);
        foreach ($included as $inc) {
            $section->addListItem(
                iti_field($inc,'resolved',$lang)?:iti_field($inc,'text',$lang),
                0, 'incl'
            );
        }
    }
    if ($excluded) {
        $section->addText('Not included', 'dayTitle', ['spaceBefore'=>120]);
        foreach ($excluded as $inc) {
            $section->addListItem(
                iti_field($inc,'resolved',$lang)?:iti_field($inc,'text',$lang),
                0, 'excl'
            );
        }
    }
}

// ── T&C ─────────────────────────────────────────────────────
if ($tc) {
    $section->addPageBreak();
    $section->addTitle('Terms & Conditions', 2);
    $section->addText($tc['name'].' — Effective '.date('d F Y',strtotime($tc['effective_date'])), 'small', ['spaceAfter'=>120]);
    $tc_text = iti_field($tc,'content',$lang)?:iti_field($tc,'content','en');
    if ($tc_text) {
        iti_richtext_to_phpword($section, $tc_text, 'tcFont', ['spaceAfter'=>60,'lineHeight'=>1.5]);
    }
}

// ── Output file ─────────────────────────────────────────────
$slug    = preg_replace('/[^a-z0-9]+/','-',strtolower(iti_field($program,'title','en')));
$slug    = trim($slug,'-');
$date    = date('Y-m-d');
$fname   = "itinerary-{$slug}-{$id}-{$date}.docx";

header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
header('Content-Disposition: attachment;filename="'.$fname.'"');
header('Cache-Control: max-age=0');

$writer = \PhpOffice\PhpWord\IOFactory::createWriter($phpWord,'Word2007');
$writer->save('php://output');
exit;
