<?php
/**
 * iti_final.php — ITI final programme built from a confirmed booking's Calc.
 * See ITI_FINAL_PROGRAMME_HANDOFF.md §5.
 *
 *   iti_final_plan()      Calc + sample → per-night plan: lodges / activities /
 *                         routes resolved through the Calc aliases, nights aligned
 *                         with the sample's days, blocking items and flags.
 *   iti_final_generate()  duplicate the sample into a PERSONAL programme with
 *                         stage='final' and overlay the Calc (dates, nights,
 *                         lodges, meals, activities, booking data, guests).
 *
 * Rule: never guess. A Calc text without an alias blocks the generation until it
 * is mapped (ITI → Aliases, or inline on program_final.php). Days of the sample
 * that match the Calc keep their Wetu text; added / changed days get master text
 * and needs_review = 1.
 *
 * Needs iti_functions.php and, for calc_read_request(), the leads helpers
 * (dropbox_helper.php, includes/booking_service.php, includes/calc_service.php).
 */
require_once __DIR__ . '/iti_functions.php';

/** "01_TouristTrophy(GoWorld-Roberto)_GranSafariTanzania_Calc.xlsx" → "GranSafariTanzania". */
function iti_final_code_from_calc(string $file): string {
    $base = preg_replace('/_calc\.xlsx$/i', '', basename($file));
    $p = strrpos($base, ')');
    if ($p !== false) return trim(substr($base, $p + 1), '_ ');
    $parts = explode('_', $base, 3);          // no "(agency)": NN_Name_Code
    return count($parts) === 3 ? $parts[2] : $base;
}

/** The sample tagged with this Calc code (prefer the display language), or null. */
function iti_final_find_sample(PDO $db, string $code, string $lang) {
    if ($code === '') return null;
    $st = $db->prepare("SELECT id, program_type, title_en, title_it, display_language, duration_days FROM iti_programs
                         WHERE program_type = 'sample' AND status <> 'cancelled' AND hub_program_code = ?
                         ORDER BY (display_language = ?) DESC, id DESC LIMIT 1");
    $st->execute(array($code, $lang));
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ? $r : null;
}

/**
 * Months a sample title names: "LUG-SET" → [7,8,9], "DIC2026-MAR2027" → [12,1,2,3],
 * "ESTATE" → [6..9], "INVERNO" → [12,1,2,3]. Italian and English. [] = no season.
 */
function iti_final_title_months(string $title): array {
    $mon = array('gen' => 1, 'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'mag' => 5, 'may' => 5, 'giu' => 6, 'jun' => 6,
                 'lug' => 7, 'jul' => 7, 'ago' => 8, 'aug' => 8, 'set' => 9, 'sep' => 9, 'ott' => 10, 'oct' => 10,
                 'nov' => 11, 'dic' => 12, 'dec' => 12);
    $t = strtolower($title);
    $alt = implode('|', array_keys($mon));
    if (preg_match('/\b(' . $alt . ')[a-z]*\s*\d{0,4}\s*(?:-|–|\/)\s*(' . $alt . ')[a-z]*/', $t, $m)) {
        $out = array();
        for ($i = $mon[$m[1]], $k = 0; $k < 12; $i = $i % 12 + 1, $k++) { $out[] = $i; if ($i === $mon[$m[2]]) break; }
        return $out;
    }
    if (preg_match('/\b(estate|summer)\b/', $t)) return array(6, 7, 8, 9);
    if (preg_match('/\b(inverno|winter)\b/', $t)) return array(12, 1, 2, 3);
    return array();
}

/**
 * The sample to propose when none is tagged with the Calc code yet. Scores each
 * sample on:
 *  - its itinerary: the Calc nights aligned with the sample's days (same lodge,
 *    same destination or a lodge within ITI_FINAL_NEAR_KM — as iti_final_plan()),
 *    as a share of the longer of the two (0–10 points);
 *  - its name: the whole code in the title (+3), else each code word in it
 *    ("MigrazioneEstate" → migrazione, estate; +1.5 each);
 *  - the season: the Calc start month inside the months of the title (+2, outside −3);
 *  - the same number of days (+1, one off +0.5), the language (+0.5), already
 *    tagged with another code (−1).
 * $samples = rows with id, title_en, title_it, duration_days, display_language, hub_program_code;
 * $planDays = iti_final_plan($db, $calc, 0)['days'].
 * Returns ['id' => sample id or 0 (nothing close / a tie), 'why' => string].
 */
function iti_final_suggest_sample(PDO $db, array $samples, string $code, array $calc, array $planDays, string $lang): array {
    $norm  = function ($s) { return preg_replace('/[^a-z0-9]+/', '', strtolower((string)$s)); };
    $c     = $norm($code);
    $words = array();   // "MigrazioneEstate" / "Grande_Migrazione" → migrazione, estate, grande
    foreach (preg_split('/[^a-z0-9]+/', strtolower(preg_replace('/(?<=[a-z])(?=[A-Z])/', ' ', $code))) as $w) {
        if (strlen($w) >= 4) $words[] = $w;
    }
    $startMonth = !empty($calc['start_date']) ? (int)date('n', strtotime($calc['start_date'])) : 0;
    $nDays = count($calc['days']);
    $mapped = 0;
    foreach ($planDays as $d) if ($d['lodge_id']) $mapped++;

    $rank = array();
    foreach ($samples as $s) {
        $why = array(); $score = 0.0;
        // Itinerary.
        if ($mapped) {
            $m = iti_final_match_sample($db, $planDays, iti_final_sample_days($db, (int)$s['id']));
            $fit = $m['max'] ? $m['score'] / $m['max'] : 0;
            $score += 10 * $fit;
            if ($fit >= 0.3) $why[] = 'itinerary ' . round(100 * $fit) . '% alike';
        }
        // Name.
        $title = $s['title_it'] . ' ' . $s['title_en'];
        $tn = $norm($title);
        if ($c !== '' && strpos($tn, $c) !== false) { $score += 3; $why[] = 'title contains “' . $code . '”'; }
        else {
            $hit = array();
            foreach ($words as $w) { if (strpos($tn, substr($w, 0, max(5, strlen($w) - 2))) !== false) $hit[] = $w; }
            if ($hit) { $score += 1.5 * count($hit); $why[] = 'title has “' . implode('”, “', $hit) . '”'; }
        }
        // Season.
        $months = iti_final_title_months($title);
        if ($months && $startMonth) {
            if (in_array($startMonth, $months, true)) { $score += 2; $why[] = 'season fits'; }
            else $score -= 3;
        }
        $dd = abs((int)$s['duration_days'] - $nDays);
        if ($dd === 0) { $score += 1; $why[] = $nDays . ' days like the Calc'; }
        elseif ($dd === 1) $score += 0.5;
        if ($s['display_language'] === $lang) $score += 0.5;
        if (!empty($s['hub_program_code']) && $s['hub_program_code'] !== $code) $score -= 1;
        $rank[(int)$s['id']] = array($score, $why);
    }
    uasort($rank, function ($a, $b) { return $b[0] <=> $a[0]; });
    $ids = array_keys($rank);
    if (!$ids) return array('id' => 0, 'why' => '');
    $best = $rank[$ids[0]];
    $second = isset($ids[1]) ? $rank[$ids[1]][0] : -INF;
    // Something real must match (≥ 4 points: e.g. half the itinerary, or the code), and clearly ahead.
    if ($best[0] < 4 || $best[0] - $second < 0.5) return array('id' => 0, 'why' => '');
    return array('id' => $ids[0], 'why' => implode(', ', $best[1]));
}

/** A lodge with its destination and coordinates (cached), or null. */
function iti_final_lodge_info(PDO $db, int $id) {
    static $cache = array();
    if (!isset($cache[$id])) {
        $st = $db->prepare('SELECT l.id, l.name, l.destination_id, l.latitude, l.longitude, d.name_en AS dest_name FROM iti_lodges l
                             LEFT JOIN iti_destinations d ON d.id = l.destination_id WHERE l.id = ?');
        $st->execute(array($id));
        $cache[$id] = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    return $cache[$id];
}

/** [lat, lng] of a lodge row, null when unknown (0 / 0 = not set). */
function iti_final_geo($row) {
    $lat = (float)($row['latitude'] ?? 0); $lng = (float)($row['longitude'] ?? 0);
    return (abs($lat) > 0.0001 || abs($lng) > 0.0001) ? array($lat, $lng) : null;
}

/**
 * A sample's days in order: id, day_number, end_lodge_id, lodge_name, destination_id,
 * latitude, longitude, lodge_text, title, places. Samples imported from WeTu often have the lodge
 * only as text ("Mara Pure Migration (Jul - Oct)"): it is read through the lodge
 * aliases, else the closest lodge name, so the day still matches the Calc;
 * 'lodge_text' keeps that text (null when the lodge is linked). 'title' = the first
 * day title set; 'places' = iti_final_places() of the titles and destinations.
 */
function iti_final_sample_days(PDO $db, int $sampleId): array {
    $titles = '';
    foreach (ITI_LANGUAGES as $l) $titles .= ', d.day_title_' . $l;
    $st = $db->prepare('SELECT d.id, d.day_number, d.end_lodge_id, d.end_lodge_custom, l.name AS lodge_name, l.destination_id, l.latitude, l.longitude,
                               CONCAT_WS(\' \', dd.name_en, dd.name_it, ld.name_en, ld.name_it) AS dest_names' . $titles . '
                          FROM iti_program_days d LEFT JOIN iti_lodges l ON l.id = d.end_lodge_id
                          LEFT JOIN iti_destinations dd ON dd.id = d.destination_id
                          LEFT JOIN iti_destinations ld ON ld.id = l.destination_id
                         WHERE d.program_id = ? ORDER BY d.day_number');
    $st->execute(array($sampleId));
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $k => $r) {
        $title = ''; $text = (string)$r['dest_names'];
        foreach (ITI_LANGUAGES as $l) {
            $t = trim((string)$r['day_title_' . $l]);
            if ($title === '') $title = $t;
            $text .= ' ' . $t;
        }
        $rows[$k]['title'] = $title;
        $rows[$k]['places'] = iti_final_places($text);
        $rows[$k]['lodge_text'] = null;
        $txt = trim((string)$r['end_lodge_custom']);
        if ($r['end_lodge_id'] || $txt === '') continue;
        $id = iti_final_lodge_from_text($txt);
        if ($id && ($li = iti_final_lodge_info($db, $id))) {
            $rows[$k]['end_lodge_id'] = $id;
            $rows[$k]['lodge_name'] = $li['name'];
            $rows[$k]['destination_id'] = $li['destination_id'];
            $rows[$k]['latitude'] = $li['latitude'];
            $rows[$k]['longitude'] = $li['longitude'];
            $rows[$k]['lodge_text'] = $txt;
        }
    }
    return $rows;
}

/** Lodge id for a free lodge text of a sample: its alias, else the closest name (without "(…)"), else 0. */
function iti_final_lodge_from_text(string $txt): int {
    static $names = null, $cache = array();
    if (isset($cache[$txt])) return $cache[$txt];
    list($base) = iti_alias_split_meal($txt);
    foreach (array($txt, $base) as $t) {
        $a = iti_alias_lookup('lodge', $t);
        if ($a && $a['lodge_id']) return $cache[$txt] = (int)$a['lodge_id'];
    }
    if ($names === null) {
        $names = array();
        foreach (iti_get_lodges() as $l) $names[(int)$l['id']] = $l['name'];
    }
    $id = iti_alias_suggest(trim(preg_replace('/\([^)]*\)/', ' ', $base)), $names);
    return $cache[$txt] = $id ? (int)$id : 0;
}

/**
 * Places a Calc label or a sample day names ("Karatu-Ark-ZNZ" → Karatu, Arusha, Zanzibar;
 * "Pista di Ndutu" → Serengeti): [area => true]. Unknown words ("Home") are left out.
 */
function iti_final_places(string $text): array {
    static $areas = array(
        'Kilimanjaro'   => '/\b(kili|kilimanjaro|jro|kia|moshi)\b/u',
        'Arusha'        => '/\b(arusha|ark)\b/u',
        'Tarangire'     => '/\btarangire\b/u',
        'Manyara'       => '/\b(manyara|mto wa mbu)\b/u',
        'Karatu'        => '/\bkaratu\b/u',
        'Ngorongoro'    => '/\b(ngorongoro|crater|cratere|cratère|krater|cráter)\b/u',
        'Serengeti'     => '/\b(serengeti|ndutu|seronera|kogatende)\b/u',
        'Lake Eyasi'    => '/\beyasi\b/u',
        'Lake Natron'   => '/\bnatron\b/u',
        'Zanzibar'      => '/\b(znz|zanzibar|zanzíbar|sansibar|stone ?town|nungwi|kendwa|kiwengwa|matemwe|pwani|paje|jambiani|michamvi|uroa)\b/u',
        'Dar es Salaam' => '/\bdar\b/u',
        'Nairobi'       => '/\b(nairobi|nbo)\b/u',
        'Amboseli'      => '/\bamboseli\b/u',
        'Masai Mara'    => '/\bma+sai mara\b/u',
    );
    $t = mb_strtolower($text, 'UTF-8');
    $out = array();
    foreach ($areas as $area => $re) if (preg_match($re, $t)) $out[$area] = true;
    return $out;
}

/** Two place sets (iti_final_places()) that both name places and share none. */
function iti_final_places_clash(array $a, array $b): bool {
    return $a && $b && !array_intersect_key($a, $b);
}

/**
 * Align the plan's nights with a sample's days on the lodge sequence. Same lodge
 * = 2; same destination, or another lodge within ITI_FINAL_NEAR_KM (The Manor at
 * Ngorongoro ~ Marera in Karatu: two destinations, one area) = 1; the departure
 * day with the sample's last day = 2, unless the Calc label and the sample day name
 * different places ("Znz-Home" vs "Karatu-Arusha-Kilimanjaro": the day is added from the Calc).
 * Returns ['map' => [planIndex => sampleIndex], 'score' => matched points, 'max' => 2 × the longer list].
 */
function iti_final_match_sample(PDO $db, array $planDays, array $sampleDays): array {
    $ka = array(); $kb = array();
    foreach ($planDays as $d) {
        $li = $d['lodge_id'] ? iti_final_lodge_info($db, (int)$d['lodge_id']) : null;
        $ka[] = array('l' => $d['lodge_id'], 'd' => $d['dest_id'], 'end' => $d['last'] && $d['hotel_text'] === '',
                      'geo' => $li ? iti_final_geo($li) : null, 'p' => iti_final_places((string)$d['label']));
    }
    $m = count($sampleDays);
    foreach ($sampleDays as $j => $s) {
        $kb[] = array('l' => $s['end_lodge_id'] ? (int)$s['end_lodge_id'] : null,
                      'd' => $s['destination_id'] ? (int)$s['destination_id'] : null,
                      'end' => !$s['end_lodge_id'] && $j === $m - 1,
                      'geo' => iti_final_geo($s), 'p' => $s['places'] ?? array());
    }
    $score = function ($x, $y) {
        if ($x['end'] && $y['end']) return iti_final_places_clash($x['p'], $y['p']) ? 0 : 2;
        if ($x['l'] && $x['l'] === $y['l']) return 2;
        if ($x['d'] && $x['d'] === $y['d']) return 1;
        if ($x['geo'] && $y['geo'] && iti_haversine($x['geo'][0], $x['geo'][1], $y['geo'][0], $y['geo'][1]) <= ITI_FINAL_NEAR_KM) return 1;
        return 0;
    };
    $map = iti_final_align($ka, $kb, $score);
    $total = 0;
    foreach ($map as $i => $j) $total += $score($ka[$i], $kb[$j]);
    return array('map' => $map, 'score' => $total, 'max' => 2 * max(count($ka), count($kb)));
}

/**
 * Title of a final programme: the sample's title without the internal language tag,
 * in title case, then the customer — "PUMBA SAFARI IN ITALIANO" + "Marco Ciaolo"
 * → "Pumba Safari – Marco Ciaolo". Empty sample title → ''.
 */
function iti_final_title(string $sampleTitle, string $customer): string {
    $t = trim(preg_replace('/\s+(in\s+(italiano|english|inglese|fran[cç]ais|espa[nñ]ol|deutsch))\s*$/iu', '', $sampleTitle));
    $t = iti_final_strip_season($t);
    if ($t === '') return '';
    if (mb_strtoupper($t, 'UTF-8') === $t) {      // all caps → title case, airport / season codes kept
        $t = mb_convert_case(mb_strtolower($t, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
        $t = preg_replace_callback('/\b(Znz|Jro|Ark|Sto|Dar)\b/u', function ($m) { return strtoupper($m[1]); }, $t);
        // Small words inside the title stay lower case ("Kiboko Safari e Zanzibar").
        $t = preg_replace_callback('/(?<=\s)(E|Ed|Da|Di|Del|In|And|Of|The)(?=\s)/u', function ($m) { return strtolower($m[1]); }, $t);
    }
    $customer = trim($customer);
    return mb_substr($customer !== '' ? $t . ' – ' . $customer : $t, 0, 255);
}

/**
 * A sample title without its season / rate tags, which are for the team, not the
 * client: "SAFARI GRANDE MIGRAZIONE LUG-SET" → "SAFARI GRANDE MIGRAZIONE",
 * "… INVERNO STO DIC2026-MAR2027", "GREAT MIGRATION SAFARI – WINTER, DEC 2026–MAR 2027"
 * → the name only. Month ranges, season words, "STO" and years go.
 */
function iti_final_strip_season(string $title): string {
    $mon = '(?:gen|jan|feb|f[eé]v|mar|apr|avr|abr|mag|may|mai|giu|jun|juin|lug|jul|juil|ago|aug|ao[uû]|set|sep|ott|oct|okt|nov|dic|dec|d[eé]c|dez)\p{L}*';
    $t = preg_replace('/\b' . $mon . '\.?\s*(?:\d{4})?\s*(?:-|–|\/)\s*' . $mon . '\.?\s*(?:\d{4})?/iu', ' ', $title);
    $t = preg_replace('/\b(?:inverno|estate|winter|summer|hiver|[eé]t[eé]|invierno|verano|sommer|sto)\b/iu', ' ', $t);
    $t = preg_replace('/\b\d{4}(?:\s*[-–\/]\s*\d{2,4})?\b/u', ' ', $t);
    $t = preg_replace('/\s*[,–\-:\/]+\s*(?=[,–\-:\/]|$)/u', '', trim(preg_replace('/\s+/u', ' ', $t)));
    return trim($t, " \t,–-:/");
}

/**
 * Two activity names of the same kind: a common word of 6+ letters ("camminata"),
 * generic words aside — game drives are not merged (a night drive is added to the
 * day's game drive, not swapped for it). $a, $b = lists of names (any language).
 */
function iti_final_same_kind(array $a, array $b): bool {
    static $generic = array('activity', 'attivita', 'attività', 'lodge', 'safari', 'fotosafari', 'national', 'nazionale',
                            'giornata', 'intera', 'notturno', 'notturna');
    $words = function (array $names) use ($generic) {
        $w = array();
        foreach ($names as $n) {
            foreach (preg_split('/[^\p{L}]+/u', mb_strtolower((string)$n)) as $x) {
                if (mb_strlen($x) >= 6 && !in_array($x, $generic, true)) $w[$x] = true;
            }
        }
        return $w;
    };
    return (bool)array_intersect_key($words($a), $words($b));
}

/**
 * Price table of a final programme from the Calc — the price to the customer: per person
 * for each pax type the Calc has (adult F9, teenager "Teenager price", child "Child price"),
 * then the total (F10, bold). [] when the Calc has no price to customer.
 * Rows [{label, price, currency, bold?}] as iti_doc_data() reads price_table_json.
 */
function iti_final_price_table(array $calc, string $lang): array {
    $L = array(
        'it' => array('Adulto', 'Ragazzo (12–15 anni)', 'Bambino (sotto i 12 anni)', 'Totale pratica', array('adulto', 'adulti'), array('ragazzo', 'ragazzi'), array('bambino', 'bambini')),
        'en' => array('Adult', 'Teenager (12–15)', 'Child (under 12)', 'Total booking', array('adult', 'adults'), array('teenager', 'teenagers'), array('child', 'children')),
        'fr' => array('Adulte', 'Adolescent (12–15 ans)', 'Enfant (moins de 12 ans)', 'Prix total', array('adulte', 'adultes'), array('adolescent', 'adolescents'), array('enfant', 'enfants')),
        'es' => array('Adulto', 'Adolescente (12–15 años)', 'Niño (menor de 12 años)', 'Precio total', array('adulto', 'adultos'), array('adolescente', 'adolescentes'), array('niño', 'niños')),
        'de' => array('Erwachsener', 'Jugendlicher (12–15 J.)', 'Kind (unter 12 J.)', 'Gesamtpreis', array('Erwachsener', 'Erwachsene'), array('Jugendlicher', 'Jugendliche'), array('Kind', 'Kinder')),
    );
    $t = $L[$lang] ?? $L['en'];
    $adult = $calc['price_to_customer'] ?? null;
    if ($adult === null || $adult <= 0) return array();
    $pax = $calc['pax'] ?? array();
    $rows = array(); $who = array();
    $add = function ($n, $label, $price, $forms) use (&$rows, &$who) {
        if ($n <= 0 || $price === null || $price <= 0) return;
        $rows[] = array('label' => $label, 'price' => round((float)$price), 'currency' => 'USD');
        $who[]  = $n . ' ' . $forms[$n > 1 ? 1 : 0];
    };
    $add((int)($pax['adults'] ?? 0), $t[0], $adult, $t[4]);
    $add((int)($pax['teen'] ?? 0), $t[1], $calc['price_teen'] ?? null, $t[5]);
    $add((int)($pax['child'] ?? 0), $t[2], $calc['price_child'] ?? null, $t[6]);
    if (!empty($calc['price_total']) && $calc['price_total'] > 0) {
        $rows[] = array('label' => $t[3] . ($who ? ' (' . implode(', ', $who) . ')' : ''), 'price' => round((float)$calc['price_total']),
                        'currency' => 'USD', 'bold' => true);
    }
    return $rows;
}

/** Text of an added day spent in the same lodge as the night before: a game drive day, or a free day at the beach. */
function iti_final_stay_text(string $lang, bool $beach): string {
    $t = $beach
        ? array('it' => 'Giornata libera per relax al mare e attività facoltative.', 'en' => 'Free day to relax on the beach, with optional activities.',
                'fr' => 'Journée libre pour se détendre à la plage, avec activités facultatives.', 'es' => 'Día libre para relajarse en la playa, con actividades opcionales.',
                'de' => 'Freier Tag zum Entspannen am Strand, mit optionalen Aktivitäten.')
        : array('it' => 'Intera giornata di fotosafari.', 'en' => 'Full day game drive.', 'fr' => 'Journée complète de safari photo.',
                'es' => 'Día completo de safari fotográfico.', 'de' => 'Ganztägige Pirschfahrt.');
    return $t[$lang] ?? $t['en'];
}

/**
 * Client name for the program title: the first guest of the Calc with a name
 * ("Luca De Sanctis"), else the Hub customer name with its words split
 * ("LucaDeSanctis" → "Luca De Sanctis").
 */
function iti_final_customer_name(array $calc, string $customer): string {
    foreach ($calc['guests'] ?? array() as $g) {
        if (empty($g['tba']) && trim((string)$g['name']) !== '') return trim((string)$g['name']);
    }
    return trim(preg_replace('/(?<=\p{Ll})(?=\p{Lu})/u', ' ', $customer));
}

// Two lodges this close count as the same area when aligning the Calc with the sample.
if (!defined('ITI_FINAL_NEAR_KM')) define('ITI_FINAL_NEAR_KM', 30);

/** Meal flags [breakfast, lunch, dinner, all_inclusive] for a meal basis code. */
function iti_final_meals(?string $basis): ?array {
    $m = array('BB' => array(1, 0, 0, 0), 'HB' => array(1, 0, 1, 0), 'FB' => array(1, 1, 1, 0), 'AI' => array(1, 1, 1, 1));
    return ($basis !== null && isset($m[$basis])) ? $m[$basis] : null;
}

/*
 * Migration camps follow the herds: in the north (Mara River / Kogatende) from June
 * to October, at Ndutu from December to March; in April, May and November they are
 * closed while they move — by default; a camp's own open months (iti_lodges.season_months,
 * set on the lodge page) win: Untamed also opens in the central Serengeti (Apr–Jun, Nov).
 * A Calc text like "Pure Migration" or "Bush Camp" is mapped once to one of the camps;
 * the night's month then picks the camp that is open: the camp itself, else its twin.
 * Camps = active lodges of type Mobile Camp, or with a season twin (iti_lodges.
 * season_twin_id, set on the lodge page: the same camp in the other area, e.g.
 * Tanzania Bush Camps Ndutu Mobile ↔ Mara River). Area (for the default months):
 * "Ndutu" in the name or destination → south; Mara / Kogatende / North → north; else
 * the opposite of the twin's; else none (one camp that moves itself: Ubuntu, Untamed).
 * Without a twin set, two mobile camps whose names match without the area words
 * are twins ("Ndutu Pure Migration Camp" ~ "Mara Pure Migration Camp").
 */

/** Migration camps: lodge id => ['name', 'area' => 'north'|'south'|'', 'twin' => lodge id or 0]. */
function iti_final_migration_camps(): array {
    static $camps = null;
    if ($camps !== null) return $camps;
    iti_ensure_doc_columns();   // season_twin_id
    $lodges = array();
    foreach (iti_get_lodges() as $l) $lodges[(int)$l['id']] = $l;
    $twinOf = array();
    foreach ($lodges as $id => $l) {
        $t = (int)($l['season_twin_id'] ?? 0);
        if ($t && isset($lodges[$t])) { $twinOf[$id] = $t; $twinOf[$t] = $id; }
    }
    $areaOf = function ($l) {
        $txt = strtolower($l['name'] . ' ' . $l['dest_name_en']);
        if (strpos($txt, 'ndutu') !== false) return 'south';
        return preg_match('/\b(mara|kogatende|north(ern)?)\b/', strtolower($l['name'])) ? 'north' : '';
    };
    $key = function ($n) {
        $n = preg_replace('/\b(ndutu|mara|kogatende|north(ern)?|south(ern)?|serengeti)\b/', ' ', strtolower($n));
        return trim(preg_replace('/[^a-z0-9]+/', ' ', $n));
    };
    // Mobile camps without a twin set: pair them by name.
    $byKey = array();
    foreach ($lodges as $id => $l) {
        if ($l['lodge_type'] === 'mobile_camp' && !isset($twinOf[$id])) $byKey[$key($l['name'])][] = $id;
    }
    foreach ($byKey as $ids) if (count($ids) === 2) { $twinOf[$ids[0]] = $ids[1]; $twinOf[$ids[1]] = $ids[0]; }

    $camps = array();
    foreach ($lodges as $id => $l) {
        if ($l['lodge_type'] !== 'mobile_camp' && !isset($twinOf[$id])) continue;
        $area = $areaOf($l);
        if ($area === '' && isset($twinOf[$id])) {
            $ta = $areaOf($lodges[$twinOf[$id]]);
            $area = $ta === 'south' ? 'north' : ($ta === 'north' ? 'south' : '');
        }
        $camps[$id] = array('name' => $l['name'], 'area' => $area, 'twin' => $twinOf[$id] ?? 0,
                            'months' => iti_final_open_months($l['season_months'] ?? null, $area));
    }
    return $camps;
}

/**
 * Months a migration camp is open: iti_lodges.season_months ("12,1,2,3", set on the lodge
 * page — e.g. Untamed, which also opens in the central Serengeti April–June and November:
 * all year), else by area: north Jun–Oct, Ndutu Dec–Mar, a camp that moves itself all but
 * April, May and November.
 */
function iti_final_open_months($stored, string $area): array {
    $m = array_values(array_unique(array_filter(array_map('intval', explode(',', (string)$stored)),
                                                function ($x) { return $x >= 1 && $x <= 12; })));
    if ($m) return $m;
    if ($area === 'north') return array(6, 7, 8, 9, 10);
    if ($area === 'south') return array(12, 1, 2, 3);
    return array(1, 2, 3, 6, 7, 8, 9, 10, 12);
}

/**
 * The lodge to use for a night given the season: ['id' => lodge id, 'note' => ?string].
 * A migration camp closed that month is swapped for its season twin when the twin is
 * open; 'note' explains the swap, or warns when the camp is closed.
 */
function iti_final_season_lodge(int $lodgeId, ?string $ymd): array {
    $camps = iti_final_migration_camps();
    if (!isset($camps[$lodgeId]) || !$ymd) return array('id' => $lodgeId, 'note' => null);
    $c = $camps[$lodgeId];
    $m = (int)date('n', strtotime($ymd));
    $month = date('F', strtotime($ymd));
    if (in_array($m, $c['months'], true)) return array('id' => $lodgeId, 'note' => null);
    $t = $c['twin'] && isset($camps[$c['twin']]) ? $camps[$c['twin']] : null;
    if ($t && in_array($m, $t['months'], true)) {
        return array('id' => $c['twin'], 'note' => 'Season rule: in ' . $month . ' ' . $c['name'] . ' is closed → '
                     . $t['name'] . ' (the Calc text is mapped to ' . $c['name'] . ').');
    }
    return array('id' => $lodgeId, 'note' => $c['name'] . ' is closed in ' . $month
                 . ($t ? ', and so is its season twin ' . $t['name'] : '') . ' — check the lodge (open months: ITI → Lodges).');
}

/**
 * Lodge to preselect when mapping a Calc hotel text: iti_alias_suggest() over the
 * lodges, leaving out the migration camps closed in the night's month whose twin is
 * open ("Pure Migration" in July → Mara Pure Migration Camp, not the Ndutu one).
 * $names = [lodge id => name]. Returns the id or null.
 */
function iti_final_suggest_lodge(string $text, array $names, ?string $ymd) {
    if ($ymd) {
        $m = (int)date('n', strtotime($ymd));
        $camps = iti_final_migration_camps();
        foreach ($camps as $id => $c) {
            if (!in_array($m, $c['months'], true) && $c['twin'] && isset($camps[$c['twin']])
                && in_array($m, $camps[$c['twin']]['months'], true)) unset($names[$id]);
        }
    }
    return iti_alias_suggest($text, $names);
}

/**
 * Weighted LCS alignment of two key lists. Returns [calcIndex => sampleIndex] for
 * the matched pairs. $score($a, $b) > 0 allows a pair (higher = better match);
 * default: keys compared with ===.
 */
function iti_final_align(array $a, array $b, ?callable $score = null): array {
    if ($score === null) $score = function ($x, $y) { return $x === $y ? 1 : 0; };
    $n = count($a); $m = count($b);
    $L = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
    $S = array();
    for ($i = $n - 1; $i >= 0; $i--) {
        for ($j = $m - 1; $j >= 0; $j--) {
            $S[$i][$j] = $score($a[$i], $b[$j]);
            $L[$i][$j] = max($L[$i + 1][$j], $L[$i][$j + 1], $S[$i][$j] > 0 ? $L[$i + 1][$j + 1] + $S[$i][$j] : 0);
        }
    }
    $map = array();
    for ($i = 0, $j = 0; $i < $n && $j < $m; ) {
        if ($S[$i][$j] > 0 && $L[$i][$j] === $L[$i + 1][$j + 1] + $S[$i][$j]) { $map[$i] = $j; $i++; $j++; }
        elseif ($L[$i + 1][$j] >= $L[$i][$j + 1]) $i++;
        else $j++;
    }
    return $map;
}

/**
 * The plan: one entry per Calc day row, with its resolved lodge / activities /
 * route, the matching sample day (or null = new day) and flags.
 * $calc = calc_read_file() / calc_read_request() output. $sampleId 0 = no sample yet.
 * @return array{days:array, blocking:array, unmapped:array, flags:string[], sample_days:int}
 */
function iti_final_plan(PDO $db, array $calc, int $sampleId): array {
    iti_ensure_final_schema();
    $days = array(); $unmapped = array('lodge' => array(), 'activity' => array(), 'route' => array());
    $hints = array('lodge_meal' => array(), 'lodge_date' => array());   // unmapped hotel text => meal from its suffix / first night
    $n = count($calc['days']);
    $lodgeInfo = function ($id) use ($db) { return iti_final_lodge_info($db, (int)$id); };

    foreach ($calc['days'] as $i => $cd) {
        $last = ($i === $n - 1);
        $d = array('i' => $i, 'date' => $cd['date'], 'label' => $cd['label'], 'hotel_text' => $cd['hotel_text'],
                   'invoice' => $cd['invoice'], 'checked' => $cd['checked'], 'flight_cost' => $cd['flight_cost'],
                   'last' => $last, 'lodge_id' => null, 'lodge_name' => '', 'lodge_custom' => null, 'dest_id' => null,
                   'meal' => null, 'lodge_state' => 'none', 'acts' => array(), 'route' => null,
                   'sample_index' => null, 'sample_lodge' => null, 'flags' => array());

        // Lodge (every night; the departure day normally has none).
        if ($cd['hotel_text'] !== '') {
            // "Orangi River Luxury (FB)": the alias is the hotel without the meal suffix
            // (an older alias saved with the suffix still wins); the suffix sets the meal.
            list($base, $mealSfx) = iti_alias_split_meal($cd['hotel_text']);
            $a = iti_alias_lookup('lodge', $cd['hotel_text']);
            if ($a === null && $base !== $cd['hotel_text']) $a = iti_alias_lookup('lodge', $base);
            if ($a === null) {
                $d['lodge_state'] = 'unmapped';
                $unmapped['lodge'][iti_alias_norm($base)] = $base;
                if ($mealSfx) $hints['lodge_meal'][$base] = $mealSfx;
                if (!isset($hints['lodge_date'][$base])) $hints['lodge_date'][$base] = $cd['date'];   // first night: season of the suggestion
            } elseif (!$a['lodge_id']) {
                $d['lodge_state'] = 'nothing';
                $d['lodge_custom'] = $cd['hotel_text'];        // e.g. "Riu Palace Nungwi - own arrangement"
            } else {
                $sea = iti_final_season_lodge((int)$a['lodge_id'], $cd['date']);   // migration camps: north / Ndutu by month
                if ($sea['note']) $d['flags'][] = $sea['note'];
                $li = $lodgeInfo($sea['id']);
                $d['lodge_state'] = $li ? 'ok' : 'unmapped';
                if ($li) {
                    $d['lodge_id'] = (int)$li['id']; $d['lodge_name'] = $li['name'];
                    $d['dest_id'] = $li['destination_id'] ? (int)$li['destination_id'] : null;
                }
                $d['meal'] = $mealSfx ?: ($a['meal_basis'] ?: null);
            }
        } elseif (!$last) {
            $d['flags'][] = 'Night without hotel in the Calc (col K).';
        }

        // Activities: ACTIVITY DESC + FEES DESC, split on '+'.
        foreach (array_merge(iti_alias_parts($cd['activity_desc']), iti_alias_parts($cd['park_fees_desc'])) as $part) {
            // Picnic lunch cost line, never an activity: not asked, no alias needed.
            if (preg_match('/^lunch ?box(es)?$/', iti_alias_norm($part))) {
                $d['acts'][] = array('text' => $part, 'state' => 'nothing', 'activity_id' => null);
                continue;
            }
            $a = iti_alias_lookup('activity', $part);
            if ($a === null) {
                $unmapped['activity'][$part] = $part;
                $d['acts'][] = array('text' => $part, 'state' => 'unmapped', 'activity_id' => null);
            } else {
                $d['acts'][] = array('text' => $part, 'state' => $a['activity_id'] ? 'ok' : 'nothing',
                                     'activity_id' => $a['activity_id'] ? (int)$a['activity_id'] : null);
            }
        }

        // Route (PARK/OVERNIGHT label) — required only for days the sample does not cover (below).
        if ($cd['label'] !== '') {
            $a = iti_alias_lookup('route', $cd['label']);
            $d['route'] = array('text' => $cd['label'], 'state' => $a === null ? 'unmapped' : 'ok',
                                'transfer_route_id' => $a && $a['transfer_route_id'] ? (int)$a['transfer_route_id'] : null,
                                'flight_route_id' => $a && $a['flight_route_id'] ? (int)$a['flight_route_id'] : null);
        }
        $days[] = $d;
    }

    // Align with the sample's days on the lodge sequence (iti_final_match_sample()).
    // Same lodge = best match; same destination or a nearby lodge still keeps the
    // sample day's text, flagged for review.
    $sampleDays = array();
    if ($sampleId) {
        $sampleDays = iti_final_sample_days($db, $sampleId);
        foreach (iti_final_match_sample($db, $days, $sampleDays)['map'] as $i => $j) {
            $days[$i]['sample_index'] = $j;
            $s = $sampleDays[$j];
            if ($days[$i]['lodge_id'] && $s['end_lodge_id'] && (int)$s['end_lodge_id'] !== $days[$i]['lodge_id']) {
                $days[$i]['sample_lodge'] = (string)$s['lodge_name'];
                $days[$i]['flags'][] = 'Lodge changed from the sample (' . $s['lodge_name'] . ' → ' . $days[$i]['lodge_name']
                                     . '): the sample text is kept — check it does not name the old lodge.';
            }
            $cp = iti_final_places((string)$days[$i]['label']);
            if (iti_final_places_clash($cp, $s['places'])) {
                $days[$i]['flags'][] = 'Places differ: Calc “' . $days[$i]['label'] . '” (' . implode(', ', array_keys($cp)) . ') vs sample day '
                                     . (int)$s['day_number'] . ' “' . $s['title'] . '” (' . implode(', ', array_keys($s['places'])) . ') — check the day text.';
            }
        }
        // Departure day left out by iti_final_match_sample(): the sample's leaves from elsewhere.
        $lastS = $sampleDays ? $sampleDays[count($sampleDays) - 1] : null;
        $lastC = $days ? $days[count($days) - 1] : null;
        if ($lastS && $lastC && $lastC['sample_index'] === null && $lastC['hotel_text'] === '' && !$lastS['end_lodge_id']
            && iti_final_places_clash(iti_final_places((string)$lastC['label']), $lastS['places'])) {
            $days[count($days) - 1]['flags'][] = 'Departure not taken from the sample: its last day “' . $lastS['title'] . '” leaves from '
                . implode(', ', array_keys($lastS['places'])) . ', the Calc “' . $lastC['label'] . '” from '
                . implode(', ', array_keys(iti_final_places((string)$lastC['label']))) . '.';
        }
    }

    $blocking = array();
    foreach ($unmapped['lodge'] as $t) $blocking[] = 'Hotel "' . $t . '" has no lodge alias.';
    foreach ($unmapped['activity'] as $t) $blocking[] = 'Activity "' . $t . '" has no activity alias.';
    foreach ($days as $i => $d) {
        if ($sampleId && $d['sample_index'] === null) {
            $days[$i]['flags'][] = 'Not in the sample: added from the Calc (text to review).';
            // Routes only once every hotel is mapped: until then the alignment with the
            // sample is not known and most days only look new.
            if ($d['route'] && $d['route']['state'] === 'unmapped' && !$unmapped['lodge']) {
                $unmapped['route'][iti_alias_norm($d['route']['text'])] = $d['route']['text'];
            }
        }
        if ($d['flight_cost'] !== null && $d['flight_cost'] > 0 && !($d['route'] && $d['route']['flight_route_id']) && ($d['sample_index'] === null)) {
            $days[$i]['flags'][] = 'The Calc has a flight cost this day — check the flight in the program.';
        }
    }
    foreach ($unmapped['route'] as $t) $blocking[] = 'Route "' . $t . '" (a day not in the sample) has no route alias.';

    $flags = array();
    foreach ($sampleDays as $s) {
        if ($s['lodge_text'] !== null) {
            $flags[] = 'Sample day ' . (int)$s['day_number'] . ' has the lodge only as text “' . $s['lodge_text'] . '” — read as '
                     . $s['lodge_name'] . '. Link the lodge in the sample to make it certain.';
        }
    }
    if ($unmapped['lodge'] && $sampleId) $flags[] = 'Map the hotels first: which days match the sample (and which routes are needed) depends on them.';
    if (!empty($calc['guests_tba'])) $flags[] = 'Some guest names are not known yet (TBA / waiting) — only the known names go into the program; add the others later.';
    if ($sampleId && count($sampleDays) !== count($days)) {
        $flags[] = 'The sample has ' . count($sampleDays) . ' days, the Calc ' . count($days) . ' — days are added / removed to match the Calc.';
    }
    return array('days' => $days, 'blocking' => $blocking, 'unmapped' => $unmapped, 'hints' => $hints, 'flags' => $flags,
                 'sample_days' => count($sampleDays));
}

/**
 * ref_number (= the programme files' name) of a final programme, as docs/AGENT_API.md asks:
 * the name of the programme Word with the Calc's NN_ ("01_LucaDeSanctis(LasciatiViaggiare-
 * Daniela)_SafariGrandeMigrazioneEstate"). In order: the ref of the final it replaces (same
 * Calc, unless it is the old folder-based form "01_07_27JUL_…"); the Word copy_program put
 * next to that Calc (iti_ps_ref_from_request); the Calc's own name without "_Calc"; the
 * folder-based iti_lead_ref_number().
 */
function iti_final_ref_number(PDO $db, int $requestId, array $calc) {
    $file = basename((string)($calc['calc_path'] ?? ($calc['file'] ?? '')));
    $st = $db->prepare("SELECT ref_number, source_calc_path FROM iti_programs WHERE lead_request_id = ? AND stage = 'final'
                         AND superseded_by IS NULL AND ref_number IS NOT NULL AND ref_number <> '' ORDER BY id DESC");
    $st->execute(array($requestId));
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) {
        if (basename((string)$p['source_calc_path']) === $file && !preg_match('/^\d{1,3}_\d{2}_\d{2}[A-Z]{3}_/', $p['ref_number'])) {
            return $p['ref_number'];
        }
    }
    try {
        require_once __DIR__ . '/iti_program_service.php';
        return iti_ps_ref_from_request($db, $requestId, $file)['ref_number'];
    } catch (Throwable $e) { /* no Word copied next to this Calc: from the Calc's name */ }
    if (preg_match('/^(\d{1,3}_.+)_calc\.xlsx$/i', $file, $m) && !preg_match('#[\\\\/:*?"<>|]#', $m[1])) {
        return mb_substr($m[1], 0, 120);
    }
    return iti_lead_ref_number($requestId, $file);
}

/**
 * Build the final programme. $calc must come from calc_read_request() (calc_path,
 * calc_rev). Throws RuntimeException when the plan still has blocking items.
 * $who = ['id' => users.id, 'username' => ...]. Returns ['program_id', 'superseded' => int[], 'plan'].
 */
function iti_final_generate(PDO $db, int $requestId, array $calc, int $sampleId, string $lang, array $who): array {
    iti_ensure_final_schema();
    if (!in_array($lang, ITI_LANGUAGES, true)) $lang = 'it';
    $plan = iti_final_plan($db, $calc, $sampleId);
    if (!$sampleId) throw new RuntimeException('Choose the sample program first.');
    if ($plan['blocking']) throw new RuntimeException('Map these Calc texts first: ' . implode(' ', $plan['blocking']));
    $sample = iti_get_program($sampleId);
    if (!$sample || $sample['program_type'] !== 'sample') throw new RuntimeException('Sample #' . $sampleId . ' not found.');

    $pax = $calc['pax'];
    // "Pumba Safari – Marco Ciaolo" in every language the sample has a title for.
    $st = $db->prepare('SELECT customer_name FROM requests WHERE id = ?');
    $st->execute(array($requestId));
    $customer = iti_final_customer_name($calc, (string)$st->fetchColumn());
    $titles = array();
    foreach (ITI_LANGUAGES as $l) {
        if (!array_key_exists('title_' . $l, $sample)) continue;
        $titles['title_' . $l] = (string)$sample['title_' . $l] !== '' ? iti_final_title((string)$sample['title_' . $l], $customer) : '';
    }
    if (empty($titles['title_en'])) $titles['title_en'] = iti_final_title((string)$sample['title_en'], $customer) ?: (string)$sample['title_en'];
    $newId = iti_duplicate_program($sampleId, 'personal', (string)($who['username'] ?? 'system'), $titles + array(
        'lead_request_id'  => $requestId,
        'ref_number'       => iti_final_ref_number($db, $requestId, $calc),
        'price_table_json' => ($pt = iti_final_price_table($calc, $lang)) ? json_encode($pt, JSON_UNESCAPED_UNICODE) : null,
        'stage'            => 'final',
        'start_date'       => $calc['start_date'],
        'duration_days'    => count($calc['days']),
        'pax_adults'       => max(1, (int)$pax['adults']),
        'pax_teens'        => (int)$pax['teen'],      // under 16 (column from iti_ensure_doc_columns())
        'pax_children'     => (int)$pax['child'],     // under 12
        'display_language' => $lang,
        'hub_program_code' => null,
        'source_calc_path' => $calc['calc_path'] ?? null,
        'source_calc_rev'  => $calc['calc_rev'] ?? null,
        'generated_at'     => date('Y-m-d H:i:s'),
        'generated_by'     => !empty($who['id']) ? (int)$who['id'] : null,
        'superseded_by'    => null,
        'superseded_at'    => null,
    ));

    iti_transfers_schema($db);   // DDL: before the transaction (transfer texts in every language below)
    try {
        $db->beginTransaction();
        $st = $db->prepare('SELECT * FROM iti_program_days WHERE program_id = ? ORDER BY day_number');
        $st->execute(array($newId));
        $copied = $st->fetchAll(PDO::FETCH_ASSOC);          // same order as the sample's days
        // Free the day numbers, then drop the sample days the Calc does not use.
        $db->prepare('UPDATE iti_program_days SET day_number = -day_number WHERE program_id = ?')->execute(array($newId));
        $used = array();
        foreach ($plan['days'] as $d) if ($d['sample_index'] !== null) $used[$d['sample_index']] = true;
        foreach ($copied as $j => $row) {
            if (isset($used[$j])) continue;
            foreach (array('iti_day_activities', 'iti_day_flights', 'iti_day_transfers') as $t) {
                $db->prepare('DELETE FROM ' . $t . ' WHERE program_day_id = ?')->execute(array((int)$row['id']));
            }
            $db->prepare('DELETE FROM iti_program_days WHERE id = ?')->execute(array((int)$row['id']));
        }

        $dayCols = iti_table_columns('iti_program_days');
        $destDesc = function ($destId) use ($db, $lang) {
            if (!$destId) return '';
            $st = $db->prepare('SELECT description_' . $lang . ' FROM iti_destinations WHERE id = ?');
            $st->execute(array($destId));
            return trim((string)$st->fetchColumn());
        };
        $isBeach = function ($destId) use ($db) {
            if (!$destId) return false;
            $st = $db->prepare('SELECT is_beach_stay FROM iti_destinations WHERE id = ?');
            $st->execute(array($destId));
            return (bool)$st->fetchColumn();
        };
        $routeName = function ($trId) use ($db) {
            $st = $db->prepare('SELECT fd.name_en AS f, td.name_en AS t, tr.duration_min, tr.distance_km FROM iti_transfer_routes tr
                                  JOIN iti_destinations fd ON fd.id = tr.from_destination
                                  JOIN iti_destinations td ON td.id = tr.to_destination WHERE tr.id = ?');
            $st->execute(array($trId));
            return $st->fetch(PDO::FETCH_ASSOC) ?: null;
        };
        $update = function (int $dayId, array $vals) use ($db, $dayCols) {
            $vals = array_intersect_key($vals, array_flip($dayCols));
            if (!$vals) return;
            $set = array();
            foreach (array_keys($vals) as $c) $set[] = '`' . $c . '` = ?';
            $db->prepare('UPDATE iti_program_days SET ' . implode(', ', $set) . ' WHERE id = ?')
               ->execute(array_merge(array_values($vals), array($dayId)));
        };

        $prevLodge = null; $prevCustom = null;
        $nDays = count($plan['days']);
        foreach ($plan['days'] as $i => $d) {
            $isNew = $d['sample_index'] === null;
            $vals = array(
                'day_number'     => $i + 1,
                'start_lodge_id' => $prevLodge,
                'start_custom'   => $prevLodge ? null : $prevCustom,
                'end_lodge_id'   => $d['lodge_id'],
                'end_lodge_custom' => $d['lodge_id'] ? null : $d['lodge_custom'],
                'room_type'      => ($d['lodge_id'] || $d['lodge_custom']) && $calc['room_config'] !== '' ? mb_substr($calc['room_config'], 0, 100) : null,
                'booked_status'  => $d['invoice'] !== '' ? mb_substr($d['invoice'], 0, 40) : null,
                'booked_by'      => $d['checked'] !== '' ? mb_substr($d['checked'], 0, 60) : null,
                'needs_review'   => ($isNew || $d['sample_lodge'] !== null) ? 1 : 0,
                'review_note'    => $isNew ? 'Added from the Calc (not in the sample): check title, text, transfer and meals.'
                                   : ($d['sample_lodge'] !== null ? mb_substr('Lodge changed from the sample (' . $d['sample_lodge'] . ' → '
                                      . $d['lodge_name'] . '): check the text does not name the old lodge.', 0, 255) : null),
            );
            $meals = iti_final_meals($d['meal']);
            if ($isNew && $meals === null) $meals = array(1, 1, 1, 0);   // default full board, flagged for review
            if ($meals !== null) {
                $vals['meal_breakfast']     = $i === 0 ? 0 : $meals[0];
                $vals['meal_lunch']         = $meals[1];
                $vals['meal_dinner']        = $i === $nDays - 1 ? 0 : $meals[2];
                $vals['meal_all_inclusive'] = $meals[3];
            }
            if ($isNew) {
                // Another night in the same lodge: no transfer, a full day there (the destination
                // text is already shown in the day's destination block).
                $stay = $d['lodge_id'] && $d['lodge_id'] === $prevLodge;
                if ($d['dest_id']) $vals['destination_id'] = $d['dest_id'];
                foreach (ITI_LANGUAGES as $l) { $vals['day_title_' . $l] = ''; $vals['narrative_' . $l] = ''; }
                $vals['day_title_' . $lang] = mb_substr($d['label'], 0, 200);
                $vals['narrative_' . $lang] = $stay ? iti_final_stay_text($lang, $isBeach($d['dest_id'])) : $destDesc($d['dest_id']);
                $vals['program_id'] = $newId;
                $vals = array_intersect_key($vals, array_flip($dayCols));
                $db->prepare('INSERT INTO iti_program_days (`' . implode('`,`', array_keys($vals)) . '`) VALUES ('
                             . implode(',', array_fill(0, count($vals), '?')) . ')')->execute(array_values($vals));
                $dayId = (int)$db->lastInsertId();
                // Transfer / flight of an added day, from its route alias (none on a stay day).
                if (!$stay && $d['route'] && $d['route']['transfer_route_id'] && ($r = $routeName($d['route']['transfer_route_id']))) {
                    $notes = iti_build_transfer_notes($r['f'], $r['t'], (int)$r['duration_min'], $r['distance_km'] !== null ? (int)$r['distance_km'] : 0);
                    $tr = array('description' => $notes[$lang] ?? $notes['en'], 'tr' => $notes);   // all languages at once
                    iti_transfers_replace($db, $dayId, array($tr));
                }
                if (!$stay && $d['route'] && $d['route']['flight_route_id']) {
                    $db->prepare('INSERT INTO iti_day_flights (program_day_id, flight_route_id, sort_order) VALUES (?,?,1)')
                       ->execute(array($dayId, $d['route']['flight_route_id']));
                }
            } else {
                $dayId = (int)$copied[$d['sample_index']]['id'];
                $update($dayId, $vals);
            }

            // Calc activities not already on the day (same activity, or its name in a custom text).
            // A sample activity of the same kind (a common word of 6+ letters: "Camminata
            // naturalistica" ~ "Camminata nei dintorni del lodge") is replaced by the Calc one.
            $st = $db->prepare('SELECT a.id, a.activity_id, a.activity_custom, m.name_en, m.name_it FROM iti_day_activities a
                                  LEFT JOIN iti_activities m ON m.id = a.activity_id WHERE a.program_day_id = ?');
            $st->execute(array($dayId));
            $have = $st->fetchAll(PDO::FETCH_ASSOC);
            $sort = count($have);
            foreach ($d['acts'] as $a) {
                if (!$a['activity_id']) continue;
                $an = $db->prepare('SELECT name_en, name_it FROM iti_activities WHERE id = ?');
                $an->execute(array($a['activity_id']));
                $names = array_filter(array_map('mb_strtolower', array_values($an->fetch(PDO::FETCH_ASSOC) ?: array())));
                $names[] = $a['text'];
                $dup = false; $same = null;
                foreach ($have as $k => $h) {
                    if ((int)$h['activity_id'] === $a['activity_id']) { $dup = true; break; }
                    $txt = mb_strtolower((string)$h['activity_custom']);
                    foreach ($names as $nm) { if ($nm !== '' && $txt !== '' && strpos($txt, $nm) !== false) { $dup = true; break 2; } }
                    if ($same === null && !empty($h['id'])
                        && iti_final_same_kind($names, array($h['activity_custom'], $h['name_en'], $h['name_it']))) $same = $k;
                }
                if ($dup) continue;
                if ($same !== null) {   // the sample's texts of the old activity go too
                    $clear = '';
                    foreach (iti_table_columns('iti_day_activities') as $c) if (strpos($c, 'custom_note_') === 0) $clear .= ', `' . $c . '` = NULL';
                    $db->prepare('UPDATE iti_day_activities SET activity_id = ?, activity_custom = NULL' . $clear . ' WHERE id = ?')
                       ->execute(array($a['activity_id'], (int)$have[$same]['id']));
                    $have[$same] = array('id' => null, 'activity_id' => $a['activity_id'], 'activity_custom' => null, 'name_en' => null, 'name_it' => null);
                    continue;
                }
                $db->prepare('INSERT INTO iti_day_activities (program_day_id, activity_id, sort_order) VALUES (?,?,?)')
                   ->execute(array($dayId, $a['activity_id'], ++$sort));
                $have[] = array('id' => null, 'activity_id' => $a['activity_id'], 'activity_custom' => null, 'name_en' => null, 'name_it' => null);
            }

            $prevLodge = $d['lodge_id'];
            $prevCustom = $d['lodge_id'] ? null : $d['lodge_custom'];
        }

        // Booking data + guests (no passport numbers).
        $db->prepare('INSERT INTO iti_program_booking (program_id, room_config, pax_adults, pax_teen, pax_child,
                        arrival_details, departure_details, extra_details) VALUES (?,?,?,?,?,?,?,?)
                      ON DUPLICATE KEY UPDATE room_config = VALUES(room_config), pax_adults = VALUES(pax_adults),
                        pax_teen = VALUES(pax_teen), pax_child = VALUES(pax_child), arrival_details = VALUES(arrival_details),
                        departure_details = VALUES(departure_details), extra_details = VALUES(extra_details)')
           ->execute(array($newId, $calc['room_config'] !== '' ? $calc['room_config'] : null, (int)$pax['adults'], (int)$pax['teen'],
                           (int)$pax['child'], $calc['arrival'] ?: null, $calc['departure'] ?: null, $calc['extra_details'] ?: null));
        $gi = 0;
        foreach ($calc['guests'] as $g) {
            if (!empty($g['tba'])) continue;      // "TBA" / "waiting" rows: name not known yet
            $db->prepare('INSERT INTO iti_program_guests (program_id, sort_order, full_name, title, dob, country) VALUES (?,?,?,?,?,?)')
               ->execute(array($newId, ++$gi, mb_substr($g['name'], 0, 160), $g['title'] !== '' ? mb_substr($g['title'], 0, 10) : null,
                               $g['dob'], $g['country'] !== '' ? mb_substr($g['country'], 0, 80) : null));
        }

        // One active final per Calc file of the request (a request can have two Calc
        // files, e.g. the same safari with other lodges): older ones are kept, marked superseded.
        $st = $db->prepare("SELECT id FROM iti_programs WHERE lead_request_id = ? AND stage = 'final' AND superseded_by IS NULL AND id <> ?
                              AND source_calc_path <=> ?");
        $st->execute(array($requestId, $newId, $calc['calc_path'] ?? null));
        $old = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        if ($old) {
            $db->prepare("UPDATE iti_programs SET superseded_by = ?, superseded_at = ? WHERE id IN (" . implode(',', $old) . ")")
               ->execute(array($newId, date('Y-m-d H:i:s')));
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        // Remove the half-built copy (days, children and booking cascade or are cleaned here).
        try {
            foreach (array('iti_day_activities', 'iti_day_flights', 'iti_day_transfers') as $t) {
                $db->prepare('DELETE c FROM ' . $t . ' c JOIN iti_program_days d ON d.id = c.program_day_id WHERE d.program_id = ?')->execute(array($newId));
            }
            $db->prepare('DELETE FROM iti_program_days WHERE program_id = ?')->execute(array($newId));
            $db->prepare('DELETE FROM iti_programs WHERE id = ?')->execute(array($newId));
        } catch (Throwable $e2) {
            error_log('iti_final_generate cleanup of #' . $newId . ': ' . $e2->getMessage());
        }
        throw $e;
    }
    require_once __DIR__ . '/iti_terms.php';
    iti_terms_apply($db, (int)$newId);   // T&C of the request's channel (direct 60 days / agency 45)
    require_once __DIR__ . '/../../leads/includes/timeline_service.php';
    timeline_log($requestId, 'program_update', 'Final program generated from the Calc (#' . $newId . ')',
                 array('body' => 'Calc: ' . (isset($calc['file']) ? $calc['file'] : '?') . ($old ? "\nReplaces program #" . implode(', #', (array)$old) : ''),
                       'refs' => array('program_id' => $newId, 'calc_file' => isset($calc['file']) ? $calc['file'] : null)));
    return array('program_id' => $newId, 'superseded' => $old, 'plan' => $plan);
}

/** Final programmes of a request, newest first (active = not superseded). */
function iti_final_list(PDO $db, int $requestId): array {
    iti_ensure_final_schema();
    $st = $db->prepare("SELECT id, title_en, title_it, start_date, generated_at, source_calc_path, source_calc_rev, superseded_by, superseded_at, status
                          FROM iti_programs WHERE lead_request_id = ? AND stage = 'final' ORDER BY id DESC");
    $st->execute(array($requestId));
    return $st->fetchAll(PDO::FETCH_ASSOC);
}
