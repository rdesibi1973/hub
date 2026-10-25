<?php
/**
 * iti_doc.php — the data of the program document (iti_doc_data(): days, lodges, prices,
 * included / not included, terms, contacts) in one language, plus the UI labels and small
 * helpers. Rendered by iti_mag.php (preview / public link), iti_mag_pdf.php, iti_mag_word.php
 * and iti_guide_pdf.php.
 *
 * Keep PHP-7 style (no match / arrow functions / str_contains).
 */
require_once __DIR__ . '/iti_photos.php';
require_once __DIR__ . '/iti_terms.php';   // T&C per channel (direct / agency), placeholder texts

/**
 * Cover kicker + facts pill of a programme document by safari type (iti_programs.safari_type):
 * private safari or group departure (shared jeep). The only place these strings live, so the
 * web page, PDF and Word stay in step. $country = the programme's country (iti_program_country():
 * "TZ", "KE", "TZ+KE") → "Safari privato in Kenya", "Safari privé en Tanzanie et au Kenya".
 * Returns ['kicker', 'safari_pill'].
 */
function iti_safari_labels(string $type, string $lang, string $country = 'TZ'): array {
    $L = [
        'private' => [
            'it' => ['Safari privato', 'Safari privato con guida'],
            'en' => ['Private safari', 'Private guided safari'],
            'fr' => ['Safari privé', 'Safari privé avec guide'],
            'es' => ['Safari privado', 'Safari privado con guía'],
            'de' => ['Private Safari', 'Private Safari mit Guide'],
        ],
        'group' => [
            'it' => ['Safari di gruppo', 'Safari di gruppo con guida'],
            'en' => ['Group safari', 'Guided group safari'],
            'fr' => ['Safari en groupe', 'Safari en groupe avec guide'],
            'es' => ['Safari en grupo', 'Safari en grupo con guía'],
            'de' => ['Gruppensafari', 'Gruppensafari mit Guide'],
        ],
    ];
    // "in <country>" per language, the preposition included (French: en Tanzanie, au Kenya, à Madagascar).
    $in = [
        'TZ' => ['it' => 'in Tanzania', 'en' => 'in Tanzania', 'fr' => 'en Tanzanie', 'es' => 'en Tanzania', 'de' => 'in Tansania'],
        'KE' => ['it' => 'in Kenya', 'en' => 'in Kenya', 'fr' => 'au Kenya', 'es' => 'en Kenia', 'de' => 'in Kenia'],
        'UG' => ['it' => 'in Uganda', 'en' => 'in Uganda', 'fr' => 'en Ouganda', 'es' => 'en Uganda', 'de' => 'in Uganda'],
        'RW' => ['it' => 'in Ruanda', 'en' => 'in Rwanda', 'fr' => 'au Rwanda', 'es' => 'en Ruanda', 'de' => 'in Ruanda'],
        'MG' => ['it' => 'in Madagascar', 'en' => 'in Madagascar', 'fr' => 'à Madagascar', 'es' => 'en Madagascar', 'de' => 'in Madagaskar'],
    ];
    $and = ['it' => ' e ', 'en' => ' and ', 'fr' => ' et ', 'es' => ' y ', 'de' => ' und '];
    $t = $L[$type] ?? $L['private'];
    $l = isset($t[$lang]) ? $lang : 'en';
    $places = [];
    foreach (preg_split('/[+,\s]+/', strtoupper($country), -1, PREG_SPLIT_NO_EMPTY) as $c) if (isset($in[$c])) $places[] = $in[$c][$l];
    if (!$places) $places[] = $in['TZ'][$l];
    if (count($places) > 1 && $l !== 'fr') {   // "in Tanzania e Kenya": one preposition, except French (en … et au …)
        $prep = strtok($places[0], ' ');
        foreach ($places as $i => $pl) if ($i > 0 && strpos($pl, $prep . ' ') === 0) $places[$i] = substr($pl, strlen($prep) + 1);
    }
    $last = array_pop($places);
    $where = $places ? implode(', ', $places) . $and[$l] . $last : $last;
    return ['kicker' => $t[$l][0] . ' ' . $where, 'safari_pill' => $t[$l][1]];
}

/** UI strings per language. */
function iti_doc_labels(string $lang): array {
    $L = [
        'it' => ['days' => 'giorni', 'day1' => 'giorno', 'nights' => 'notti', 'night1' => 'notte',
                 'brief' => 'Il safari in breve', 'day' => 'Giorno', 'route' => 'Percorso', 'overnight' => 'Pernottamento', 'board' => 'Trattamento',
                 'prices' => 'Quote per persona', 'group' => 'Gruppo', 'pp' => 'Per persona', 'pax' => 'partecipanti',
                 'incl' => 'La quota comprende', 'excl' => 'La quota non comprende', 'program' => 'Programma giorno per giorno',
                 'transfer' => 'Trasferimento', 'meals' => 'Pasti', 'about' => 'circa', 'contacts' => 'Contatti utili',
                 'ref' => 'Riferimento', 'phone' => 'Telefono', 'email' => 'E-mail', 'office' => 'ufficio', 'emergency' => 'Emergenze',
                 'terms' => 'Termini e condizioni', 'activities' => 'Attività', 'end' => 'Fine dei nostri servizi',
                 'B' => 'colazione', 'L' => 'pranzo', 'D' => 'cena', 'FB' => 'pensione completa', 'HB' => 'mezza pensione', 'AI' => 'all inclusive', 'none' => '—'],
        'en' => ['days' => 'days', 'day1' => 'day', 'nights' => 'nights', 'night1' => 'night',
                 'brief' => 'Safari at a glance', 'day' => 'Day', 'route' => 'Route', 'overnight' => 'Overnight', 'board' => 'Meal plan',
                 'prices' => 'Rates per person', 'group' => 'Group', 'pp' => 'Per person', 'pax' => 'people',
                 'incl' => 'Included', 'excl' => 'Not included', 'program' => 'Day-by-day itinerary',
                 'transfer' => 'Transfer', 'meals' => 'Meals', 'about' => 'approx.', 'contacts' => 'Useful contacts',
                 'ref' => 'Contact', 'phone' => 'Phone', 'email' => 'E-mail', 'office' => 'office', 'emergency' => 'Emergencies',
                 'terms' => 'Terms and conditions', 'activities' => 'Activities', 'end' => 'End of our services',
                 'B' => 'breakfast', 'L' => 'lunch', 'D' => 'dinner', 'FB' => 'full board', 'HB' => 'half board', 'AI' => 'all inclusive', 'none' => '—'],
        'fr' => ['days' => 'jours', 'day1' => 'jour', 'nights' => 'nuits', 'night1' => 'nuit',
                 'brief' => 'Le safari en bref', 'day' => 'Jour', 'route' => 'Itinéraire', 'overnight' => 'Hébergement', 'board' => 'Formule',
                 'prices' => 'Tarifs par personne', 'group' => 'Groupe', 'pp' => 'Par personne', 'pax' => 'participants',
                 'incl' => 'Le prix comprend', 'excl' => 'Le prix ne comprend pas', 'program' => 'Programme jour par jour',
                 'transfer' => 'Transfert', 'meals' => 'Repas', 'about' => 'environ', 'contacts' => 'Contacts utiles',
                 'ref' => 'Contact', 'phone' => 'Téléphone', 'email' => 'E-mail', 'office' => 'bureau', 'emergency' => 'Urgences',
                 'terms' => 'Conditions générales', 'activities' => 'Activités', 'end' => 'Fin de nos services',
                 'B' => 'petit-déjeuner', 'L' => 'déjeuner', 'D' => 'dîner', 'FB' => 'pension complète', 'HB' => 'demi-pension', 'AI' => 'tout compris', 'none' => '—'],
        'es' => ['days' => 'días', 'day1' => 'día', 'nights' => 'noches', 'night1' => 'noche',
                 'brief' => 'El safari en breve', 'day' => 'Día', 'route' => 'Recorrido', 'overnight' => 'Alojamiento', 'board' => 'Régimen',
                 'prices' => 'Precios por persona', 'group' => 'Grupo', 'pp' => 'Por persona', 'pax' => 'participantes',
                 'incl' => 'El precio incluye', 'excl' => 'El precio no incluye', 'program' => 'Programa día a día',
                 'transfer' => 'Traslado', 'meals' => 'Comidas', 'about' => 'aprox.', 'contacts' => 'Contactos útiles',
                 'ref' => 'Contacto', 'phone' => 'Teléfono', 'email' => 'E-mail', 'office' => 'oficina', 'emergency' => 'Emergencias',
                 'terms' => 'Términos y condiciones', 'activities' => 'Actividades', 'end' => 'Fin de nuestros servicios',
                 'B' => 'desayuno', 'L' => 'almuerzo', 'D' => 'cena', 'FB' => 'pensión completa', 'HB' => 'media pensión', 'AI' => 'todo incluido', 'none' => '—'],
        'de' => ['days' => 'Tage', 'day1' => 'Tag', 'nights' => 'Nächte', 'night1' => 'Nacht',
                 'brief' => 'Die Safari auf einen Blick', 'day' => 'Tag', 'route' => 'Route', 'overnight' => 'Übernachtung', 'board' => 'Verpflegung',
                 'prices' => 'Preise pro Person', 'group' => 'Gruppe', 'pp' => 'Pro Person', 'pax' => 'Teilnehmer',
                 'incl' => 'Im Preis enthalten', 'excl' => 'Nicht im Preis enthalten', 'program' => 'Reiseverlauf Tag für Tag',
                 'transfer' => 'Transfer', 'meals' => 'Mahlzeiten', 'about' => 'ca.', 'contacts' => 'Nützliche Kontakte',
                 'ref' => 'Kontakt', 'phone' => 'Telefon', 'email' => 'E-Mail', 'office' => 'Büro', 'emergency' => 'Notfälle',
                 'terms' => 'Geschäftsbedingungen', 'activities' => 'Aktivitäten', 'end' => 'Ende unserer Leistungen',
                 'B' => 'Frühstück', 'L' => 'Mittagessen', 'D' => 'Abendessen', 'FB' => 'Vollpension', 'HB' => 'Halbpension', 'AI' => 'All inclusive', 'none' => '—'],
    ];
    return isset($L[$lang]) ? $L[$lang] : $L['en'];
}

/** Localised field with fallback: lang → en → it → any language. */
function iti_doc_pick(array $row, string $field, string $lang): string {
    foreach (array_merge([$lang, 'en', 'it'], ITI_LANGUAGES) as $l) {
        $v = trim((string)($row[$field . '_' . $l] ?? ''));
        if ($v !== '' && !iti_is_placeholder_text($v)) return $v;   // the website blurb pasted as a description = empty
    }
    return '';
}

/**
 * Name / description of an activity, lodge or destination in $lang only — never another language
 * (15 Oct 2026: a French programme printed English lodge texts). Missing (or the website blurb) →
 * '' so the box is not printed, and the gap is noted in $missing (iti_doc_data()['missing_texts'],
 * iti_program missing_texts[] in the Agent API) so it can be filled before the file is made.
 */
function iti_doc_own(array $row, string $field, string $lang, string $kind, array &$missing, int $day): string {
    $v = trim((string)($row[$field . '_' . $lang] ?? ''));
    if ($v !== '' && !iti_is_placeholder_text($v)) return $v;
    $id = (int)($row['id'] ?? 0);
    $key = $kind . '|' . $id . '|' . $field . '_' . $lang;
    if (!isset($missing[$key])) {
        $label = $kind === 'lodge' ? (string)($row['name'] ?? '')
               : (trim((string)($row['name_en'] ?? '')) ?: trim((string)($row['name_it'] ?? '')) ?: (string)($row['code'] ?? ''));
        $missing[$key] = ['type' => $kind, 'id' => $id, 'name' => $label, 'field' => $field . '_' . $lang, 'days' => []];
    }
    if (!in_array($day, $missing[$key]['days'], true)) $missing[$key]['days'][] = $day;
    return '';
}

/** Meal flags of a day → short text ("pensione completa", "cena", …). */
function iti_doc_meals(array $d, array $T): string {
    if (!empty($d['meal_all_inclusive'])) return $T['AI'];
    $b = !empty($d['meal_breakfast']); $l = !empty($d['meal_lunch']); $n = !empty($d['meal_dinner']);
    if ($b && $l && $n) return $T['FB'];
    if ($b && !$l && $n) return $T['HB'];
    $p = [];
    if ($b) $p[] = $T['B'];
    if ($l) $p[] = $T['L'];
    if ($n) $p[] = $T['D'];
    return $p ? implode(', ', $p) : $T['none'];
}

/** "Arusha – Tarangire 2 ore di trasferimento." → "2 ore" (duration only), or ''. */
function iti_doc_duration(string $transfer): string {
    if (preg_match('/(\d+(?:[.,]\d+)?(?:\s*-\s*\d+(?:[.,]\d+)?)?\s*(?:ore|ora|minuti|hours?|hrs?|h|heures?|horas?|Stunden?))\b/iu', $transfer, $m)) {
        return trim($m[1]);
    }
    return '';
}

/** Destinations that give safari photos: active, with photos, not towns / airports / airstrips. */
function iti_doc_scenic_dests(PDO $db): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $towns = ['KAR', 'MWB', 'MKY', 'MSH', 'MCH', 'MRG', 'LEM', 'RNG', 'TVT', 'ISB', 'NAM', 'DOD', 'IRG', 'KSZ'];   // Arusha Town and Dar es Salaam have city / landscape photos
    $townWords = ['arusha', 'karatu', 'moshi', 'mto wa mbu', 'dar es salaam', 'dodoma', 'iringa', 'namanga'];
    $generic = '/\b(national park|conservation area|parco nazionale|parc national|parque nacional|nationalpark|area di conservazione|'
             . 'lake|lago|lac|see|mount|monte|mont|island|isola|île|isla|insel|gorge|gola|crater|cratere|cratère|krater|town|città|ville|ciudad|stadt)\b/u';
    $cache = [];
    foreach ($db->query('SELECT * FROM iti_destinations WHERE is_active = 1')->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $photos = iti_dest_photos($r);
        if (!$photos || in_array($r['region'], ['Airports', 'Airstrips', 'International'], true) || in_array($r['code'], $towns, true)) continue;
        $keys = [];
        foreach (ITI_LANGUAGES as $l) {
            $full = mb_strtolower(trim((string)($r['name_' . $l] ?? '')));
            if ($full === '') continue;
            $keys[$full] = true;
            $short = trim(preg_replace('/\s+/u', ' ', preg_replace($generic, ' ', $full)));
            // "Arusha National Park" → not just "arusha" (that is the town).
            // "Arusha" alone means the town (Arusha Town), never "Arusha National Park".
            $isTown = stripos((string)$r['name_en'], 'town') !== false;
            if (mb_strlen($short) >= 4 && ($isTown || !in_array($short, $townWords, true))) $keys[$short] = true;
        }
        $cache[(int)$r['id']] = ['photos' => $photos, 'keys' => array_keys($keys), 'park' => $r['region'] === 'National Parks'];
    }
    return $cache;
}

/**
 * Safari photos for the days and the cover. A day shows the place its drive goes to
 * (the destination named last in its title / transfers: "Arusha – Tarangire" → Tarangire),
 * else its destination, else its lodge's area; towns and airports never; no photo twice.
 * The cover is a photo of the park where most days are spent. Returns the cover URL.
 */
function iti_doc_day_photos(PDO $db, array &$days, array $p): string {
    $sc = iti_doc_scenic_dests($db);
    $cands = []; $count = [];
    foreach ($days as $i => $d) {
        $text = mb_strtolower($d['title'] . ' ' . implode(' ', $d['transfers']));
        $hits = [];
        foreach ($sc as $id => $s) {
            foreach ($s['keys'] as $k) {
                $pos = mb_strrpos($text, $k);
                if ($pos !== false && (!isset($hits[$id]) || $pos > $hits[$id])) $hits[$id] = $pos;
            }
        }
        arsort($hits);                                   // last named first
        $c = array_keys($hits);
        foreach ($d['_photo_ids'] as $id) if (isset($sc[$id]) && !in_array($id, $c, true)) $c[] = $id;
        $cands[$i] = $c;
        if ($c && $sc[$c[0]]['park']) $count[$c[0]] = ($count[$c[0]] ?? 0) + 1;
        unset($days[$i]['_photo_ids']);
    }
    $used = []; $cover = '';
    if ($count) {
        arsort($count);
        $cd = $sc[key($count)];
        $cover = $cd['photos'][0];
        if (count($cd['photos']) > 1) $used[$cover] = true;   // keep it for the cover when the park has more
    }
    foreach ($days as $i => $d) {
        foreach ($cands[$i] as $id) {
            foreach ($sc[$id]['photos'] as $u) {
                if (isset($used[$u])) continue;
                $days[$i]['dest_photo'] = $u; $used[$u] = true;
                continue 3;
            }
        }
    }
    return $cover;
}

/** A park, reserve or conservation area (Ngorongoro): its type, the "National Parks" region or its English name. */
function iti_doc_is_park(array $d): bool {
    if (in_array(strtolower(trim((string)($d['dest_type'] ?? ''))), ['park', 'reserve'], true)) return true;
    if (($d['region'] ?? '') === 'National Parks') return true;
    return (bool)preg_match('/national park|conservation area|game reserve|national reserve|conservancy/i', (string)($d['name_en'] ?? ''));
}

/** A free-text activity that is a game drive: "Fotosafari", "Game drive", "safari fotografico", "Pirschfahrt"… */
function iti_doc_is_drive_text(string $txt): bool {
    return (bool)preg_match('/\b(game[\s-]?drives?|foto[\s-]?safari|photo[\s-]?safari|safari[\s-]?(fotogr|photo)\w*|pirschfahrt\w*)\b/iu', $txt);
}

/**
 * Last safari day of a programme ($days = iti_get_days() rows): the last day that leaves from or
 * arrives in a park / reserve, or has a game drive / walking safari. Departure = where the night
 * before was spent (lodge area, else destination) and the day's start destination / start lodge's
 * area; arrival = the day's destination or its lodge's area. A free-text activity counts as a game
 * drive by its text (iti_doc_is_drive_text()). Null when there is none
 * (beach / trekking only). Serengeti → flight to Zanzibar = the flight day.
 */
function iti_doc_last_safari_day(PDO $db, array $days): ?int {
    if (!$days) return null;
    $lodgeIds = []; $dayIds = [];
    foreach ($days as $d) {
        $dayIds[] = (int)$d['id'];
        foreach (['start_lodge_id', 'end_lodge_id'] as $k) if (!empty($d[$k])) $lodgeIds[(int)$d[$k]] = true;
    }
    $lodgeDest = [];
    if ($lodgeIds) {
        foreach ($db->query('SELECT id, destination_id FROM iti_lodges WHERE id IN (' . implode(',', array_keys($lodgeIds)) . ')')->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (!empty($r['destination_id'])) $lodgeDest[(int)$r['id']] = (int)$r['destination_id'];
        }
    }
    $parks = [];
    foreach ($db->query('SELECT * FROM iti_destinations')->fetchAll(PDO::FETCH_ASSOC) as $r) if (iti_doc_is_park($r)) $parks[(int)$r['id']] = true;
    $drives = [];
    $st = $db->query("SELECT DISTINCT da.program_day_id FROM iti_day_activities da JOIN iti_activities a ON a.id = da.activity_id
                       WHERE a.activity_type IN ('game_drive', 'walking_safari') AND da.program_day_id IN (" . implode(',', $dayIds) . ')');
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $x) $drives[(int)$x] = true;
    // Free-text activities ("Fotosafari" / "Game drive" in the samples imported from WeTu): by their text.
    $st = $db->query('SELECT * FROM iti_day_activities WHERE activity_id IS NULL AND program_day_id IN (' . implode(',', $dayIds) . ')');
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $a) {
        $txt = (string)($a['activity_custom'] ?? '');
        foreach ($a as $k => $v) if (strpos($k, 'custom_note_') === 0) $txt .= ' ' . $v;
        if (iti_doc_is_drive_text($txt)) $drives[(int)$a['program_day_id']] = true;
    }

    $last = null; $prev = [];   // $prev: where the night before was spent
    foreach ($days as $d) {
        $from = $prev;   // + the start set on the day (often an airstrip: the lodge of the night before still counts)
        if (!empty($d['start_destination_id'])) $from[] = (int)$d['start_destination_id'];
        if (!empty($d['start_lodge_id']) && isset($lodgeDest[(int)$d['start_lodge_id']])) $from[] = $lodgeDest[(int)$d['start_lodge_id']];
        $to = [];
        if (!empty($d['destination_id'])) $to[] = (int)$d['destination_id'];
        if (!empty($d['end_lodge_id']) && isset($lodgeDest[(int)$d['end_lodge_id']])) $to[] = $lodgeDest[(int)$d['end_lodge_id']];
        $hit = isset($drives[(int)$d['id']]);
        foreach (array_merge($from, $to) as $x) if (isset($parks[$x])) $hit = true;
        if ($hit) $last = (int)$d['day_number'];
        $prev = [];
        if (!empty($d['end_lodge_id']) && isset($lodgeDest[(int)$d['end_lodge_id']])) $prev[] = $lodgeDest[(int)$d['end_lodge_id']];
        elseif (!empty($d['destination_id'])) $prev[] = (int)$d['destination_id'];
    }
    return $last;
}

/**
 * Tip note of the client documents (magazine page, PDF, Word — never the guide sheet): the text of
 * ITI Settings → Tip note in $lang, on the last safari day (iti_doc_last_safari_day()). Worked out
 * at render time, nothing stored in the days. Off for the programme (show_tip_note = 0), no text in
 * $lang or no safari day → ['day' => null, 'text' => ''].
 */
function iti_doc_tip_note(PDO $db, array $p, array $days, string $lang): array {
    $none = ['day' => null, 'text' => ''];
    if ((int)($p['show_tip_note'] ?? 1) !== 1) return $none;
    $text = trim(iti_setting('tip_note_' . $lang));
    if ($text === '') return $none;
    $day = iti_doc_last_safari_day($db, $days);
    return $day === null ? $none : ['day' => $day, 'text' => $text];
}

/**
 * Everything the document needs, in $lang:
 * program, days[] (title, transfer, duration, meals, narrative, destination, lodge, activities),
 * glance rows, included/excluded, prices (optional), terms HTML, contacts.
 */
function iti_doc_data(int $id, string $lang): ?array {
    $db = db();
    iti_ensure_doc_columns();   // room_type, pax_teens, is_beach_stay (also from the public page)
    $p = iti_get_program($id);
    if (!$p) return null;
    $T = iti_doc_labels($lang);   // + the cover kicker, once the destinations give the country (below)

    $days = iti_get_days($id);
    $lodgeIds = []; $destIds = [];
    foreach ($days as $d) {
        if (!empty($d['end_lodge_id']))   $lodgeIds[(int)$d['end_lodge_id']] = true;
        if (!empty($d['destination_id'])) $destIds[(int)$d['destination_id']] = true;
    }
    $lodges = []; $dests = [];
    if ($lodgeIds) {
        $in = implode(',', array_keys($lodgeIds));
        foreach ($db->query("SELECT * FROM iti_lodges WHERE id IN ($in)")->fetchAll() as $r) $lodges[(int)$r['id']] = $r;
        // The lodge's own area (its destination), shown on the lodge card / stays.
        foreach ($lodges as $r) if (!empty($r['destination_id'])) $destIds[(int)$r['destination_id']] = true;
    }
    if ($destIds) {
        $in = implode(',', array_keys($destIds));
        foreach ($db->query("SELECT * FROM iti_destinations WHERE id IN ($in)")->fetchAll() as $r) $dests[(int)$r['id']] = $r;
    }
    // "Safari privato in Kenya": the programme's country, else its destinations' (iti_functions.php).
    $country = iti_program_country($p, $dests);
    $T += iti_safari_labels(iti_safari_type($p), $lang, $country);

    // Internal flights of a day → "Dar es Salaam → Ruaha · Auric Air · 07:40–10:05".
    $fltSt = $db->prepare('SELECT f.*, r.from_airport, r.to_airport, r.operator FROM iti_day_flights f
                             LEFT JOIN iti_flight_routes r ON r.id = f.flight_route_id WHERE f.program_day_id = ? ORDER BY f.sort_order, f.id');
    $actSt = $db->prepare('SELECT da.*, a.name_en, a.name_it, a.name_fr, a.name_es, a.name_de
                             FROM iti_day_activities da LEFT JOIN iti_activities a ON a.id = da.activity_id
                            WHERE da.program_day_id = ? ORDER BY da.sort_order, da.id');

    $out = []; $seenLodge = []; $seenDest = []; $missing = [];
    foreach ($days as $d) {
        $dn = (int)$d['day_number'];
        $transfers = [];
        foreach (iti_get_day_transfers((int)$d['id']) as $t) {   // translation in $lang, else the original text
            $tx = trim((string)($t['description_' . $lang] ?? '')) ?: trim((string)$t['description']);
            if ($tx !== '') $transfers[] = $tx;
        }
        $flights = [];
        $fltSt->execute([(int)$d['id']]);
        foreach ($fltSt->fetchAll() as $f) {
            $route = $f['flight_route_id'] ? trim($f['from_airport'] . ' → ' . $f['to_airport']) : trim((string)$f['flight_custom']);
            if ($route === '') continue;
            $parts = [$route];
            $air = trim((string)($f['airline_company'] ?: ($f['operator'] ?? '')));
            if (!empty($f['flight_no'])) $air = trim($air . ' ' . $f['flight_no']);   // "Auric Air UI 403"
            if ($air !== '') $parts[] = $air;
            $t = trim(substr((string)$f['departure_time'], 0, 5) . ($f['arrival_time'] ? '–' . substr((string)$f['arrival_time'], 0, 5) : ''), '–');
            if ($t !== '') $parts[] = $t;
            $note = trim((string)($f['note_' . $lang] ?? ''));
            if ($note !== '') $parts[] = $note;
            $flights[] = implode(' · ', $parts);
        }
        $acts = [];
        $actSt->execute([(int)$d['id']]);
        foreach ($actSt->fetchAll() as $a) {
            if (!empty($a['activity_id'])) {
                // Catalogue activity: its name in $lang, else this day's note in $lang; never the English name.
                $name = iti_doc_own(['id' => (int)$a['activity_id']] + $a, 'name', $lang, 'activity', $missing, $dn);
                if ($name === '') $name = trim((string)($a['custom_note_' . $lang] ?? ''));
            } else {
                $name = trim((string)($a['custom_note_' . $lang] ?? '')) ?: trim((string)($a['activity_custom'] ?? ''));   // the programme's own text
            }
            if ($name !== '') $acts[] = $name;
        }

        $lodgeName = ''; $lodgeDesc = ''; $lodgePhotos = []; $lodgeUrl = ''; $lodgeKey = ''; $lodgeArea = '';
        if (!empty($d['end_lodge_id']) && isset($lodges[(int)$d['end_lodge_id']])) {
            $lr = $lodges[(int)$d['end_lodge_id']];
            $lodgeName = $lr['name'];
            $lodgeKey  = 'L' . $lr['id'];
            if (!empty($lr['destination_id']) && isset($dests[(int)$lr['destination_id']])) {
                $lodgeArea = iti_doc_own($dests[(int)$lr['destination_id']], 'name', $lang, 'destination', $missing, $dn);
            }
            $lodgePhotos = iti_photos_decode($lr['photos'] ?? null);
            $lodgeUrl  = trim((string)($lr['website'] ?? ''));
            if (empty($seenLodge[$lr['id']])) { $lodgeDesc = iti_doc_own($lr, 'description', $lang, 'lodge', $missing, $dn); $seenLodge[$lr['id']] = true; }
        } elseif (!empty($d['end_lodge_custom'])) {
            $lodgeName = $d['end_lodge_custom'];
            $lodgeKey  = 'C' . strtolower(trim($lodgeName));
        }
        $destName = ''; $destDesc = '';
        if (!empty($d['destination_id']) && isset($dests[(int)$d['destination_id']])) {
            $dr = $dests[(int)$d['destination_id']];
            $destName = iti_doc_own($dr, 'name', $lang, 'destination', $missing, $dn);
            if (empty($seenDest[$dr['id']])) {
                $destDesc = iti_doc_own($dr, 'description', $lang, 'destination', $missing, $dn);
                if ($destName === '') $destDesc = '';   // no box without its title in $lang
                $seenDest[$dr['id']] = true;
            }
        } elseif (!empty($d['destination_custom'])) {
            $destName = $d['destination_custom'];
        }
        // For the day photo (iti_doc_day_photos): the day's destination and the lodge's own destination.
        $photoIds = [];
        if (!empty($d['destination_id'])) $photoIds[] = (int)$d['destination_id'];
        if (isset($lr) && $lodgeKey === 'L' . $lr['id'] && !empty($lr['destination_id'])) $photoIds[] = (int)$lr['destination_id'];

        // Beach / relax stay: the lodge's area, else the day's destination (iti_mag_compact_days()).
        $beachDest = (isset($lr) && $lodgeKey === 'L' . $lr['id'] && !empty($lr['destination_id'])) ? (int)$lr['destination_id'] : (int)($d['destination_id'] ?? 0);
        $beach = $lodgeName !== '' && $beachDest && !empty($dests[$beachDest]['is_beach_stay']);

        $title = iti_doc_pick($d, 'day_title', $lang);
        if ($title === '') $title = $destName !== '' ? $destName : ($lodgeName !== '' ? $lodgeName : '');
        $date = null;   // real date when the programme has a start date (personal / final)
        if (!empty($p['start_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$p['start_date'])) {
            $date = date('Y-m-d', strtotime($p['start_date'] . ' +' . ((int)$d['day_number'] - 1) . ' days'));
        }
        $out[] = [
            'n'          => (int)$d['day_number'],
            'date'       => $date,
            'title'      => $title,
            'transfers'  => $transfers,
            'flights'    => $flights,
            'duration'   => $transfers ? iti_doc_duration($transfers[0]) : '',
            'meals'      => iti_doc_meals($d, $T),
            'narrative'  => iti_doc_pick($d, 'narrative', $lang),
            'dest'       => $destName,
            'dest_desc'  => $destDesc,
            'dest_photo' => '',            // set by iti_doc_day_photos() below
            '_photo_ids' => $photoIds,
            'lodge'      => $lodgeName,
            'lodge_key'  => $lodgeKey,
            'lodge_area' => $lodgeArea,
            'lodge_desc' => $lodgeDesc,
            'lodge_photos' => $lodgePhotos,
            'lodge_url'  => $lodgeUrl,
            'room'       => $lodgeName !== '' ? trim((string)($d['room_type'] ?? '')) : '',   // "1 Double + 1 Twin", free text
            'beach'      => $beach,
            'activities' => $acts,
            'tip_note'   => '',            // set below on the last safari day
        ];
    }

    $cover = iti_doc_day_photos($db, $out, $p);
    $tip = iti_doc_tip_note($db, $p, $days, $lang);
    foreach ($out as $i => $d) if ($d['n'] === $tip['day']) $out[$i]['tip_note'] = $tip['text'];

    // Included / not included: this programme's rows, text in $lang with fallbacks.
    $incl = []; $excl = [];
    $st = $db->prepare('SELECT pi.*, si.text_en AS s_en, si.text_it AS s_it, si.text_fr AS s_fr, si.text_es AS s_es, si.text_de AS s_de
                          FROM iti_program_inclusions pi LEFT JOIN iti_standard_inclusions si ON si.id = pi.standard_inclusion_id
                         WHERE pi.program_id = ? ORDER BY pi.sort_order, pi.id');
    $st->execute([$id]);
    foreach ($st->fetchAll() as $r) {
        $txt = iti_doc_pick($r, 'text', $lang);
        if ($txt === '') $txt = iti_doc_pick($r, 's', $lang);
        if ($txt === '') continue;
        if ($r['item_type'] === 'exclusion') $excl[] = $txt; else $incl[] = $txt;
    }

    // Terms: the programme's own version, else the linked request's (direct client 60 days /
    // agency 45 days), else the latest active general one — iti_terms.php.
    $terms = '';
    try {
        $tres = iti_terms_resolve($db, $p);
        if (!empty($tres['row'])) $terms = iti_doc_pick($tres['row'], 'content', $lang);
    } catch (PDOException $e) { $terms = ''; }

    // Prices: optional JSON [{"label":"2 partecipanti","price":1625}, …] + notes (filled from the Calc Excel).
    $prices = [];
    if (!empty($p['price_table_json'])) {
        $pj = json_decode($p['price_table_json'], true);
        if (is_array($pj)) $prices = $pj;
    }

    $n = count($out);
    return [
        'lang'     => $lang,
        'T'        => $T,
        'program'  => $p,
        'safari_type' => iti_safari_type($p),
        'country'  => $country,
        'title'    => iti_doc_pick($p, 'title', $lang),
        'subtitle' => iti_doc_pick($p, 'subtitle', $lang),
        'intro'    => iti_doc_pick($p, 'intro', $lang),
        'duration' => $n . ' ' . ($n === 1 ? $T['day1'] : $T['days']) . ' / ' . max(0, $n - 1) . ' ' . ($n - 1 === 1 ? $T['night1'] : $T['nights']),
        'days'     => $out,
        // Activity / lodge / destination texts not in $lang: left out of the document (iti_doc_own()).
        'missing_texts' => array_values($missing),
        'tip_note_day' => $tip['day'],   // day with the tip note, null = none (off, no text in $lang, no safari day)
        'incl'     => $incl,
        'excl'     => $excl,
        'prices'   => $prices,
        'price_notes' => trim((string)($p['price_notes_' . $lang] ?? ($p['price_notes'] ?? ''))),
        'terms'    => $terms,
        // Route map (airports + numbered overnight stops), used by the magazine layout.
        'map'      => iti_get_program_map($days),
        'cover'    => $cover,
        'logo'     => iti_setting('logo_url', 'https://hub.savannahexplorers.com/modules/iti/uploads/logo/logo_1781526818.png'),
        // Office and emergencies only (no personal contact of the consultant).
        'contacts' => [
            ['name' => 'Savannah Explorers Ltd — ' . $T['office'],
             'phone' => iti_setting('office_phone', '+255 768 900 199'), 'email' => iti_setting('office_email', 'info@savannahexplorers.com')],
            ['name' => $T['emergency'], 'phone' => iti_setting('emergency_phone', '+255 768 900 199 · +255 747 777 315'), 'email' => ''],
        ],
    ];
}

/**
 * A price-table row printed in bold: its own "bold" flag (Prices tab / Agent API), else a row
 * that is the total of the booking ("Totale pratica (2 adulti + 2 ragazze)", "Total booking", …).
 */
function iti_doc_price_bold(array $pr): bool {
    if (array_key_exists('bold', $pr)) return !empty($pr['bold']);
    return (bool)preg_match('/^\s*(totale\s+(pratica|viaggio|complessivo)|total\s+(booking|trip|price|du\s+voyage|del\s+viaje)|prix\s+total|precio\s+total|gesamt)/iu', (string)($pr['label'] ?? ''));
}

/** Plain text with blank-line paragraphs → <p>…</p>; runs of "- item" paragraphs → <ul>. */
function iti_doc_paras(string $txt): string {
    $out = ''; $list = [];
    $flush = function () use (&$out, &$list) {
        if ($list) { $out .= '<ul><li>' . implode('</li><li>', $list) . '</li></ul>'; $list = []; }
    };
    foreach (preg_split('/\n\s*\n/u', trim($txt)) as $para) {
        $para = trim($para);
        if ($para === '') continue;
        if (preg_match('/^[-•]\s+(.*)$/su', $para, $m)) { $list[] = nl2br(h(trim($m[1]))); continue; }
        $flush();
        $out .= '<p>' . nl2br(h($para)) . '</p>';
    }
    $flush();
    return $out;
}

/** Language switch links (keeps the other query parameters). */
function iti_doc_lang_bar(string $lang, array $keep, string $extra = '', ?array $langs = null): string {
    $out = '<div class="etn-bar">';
    foreach ($langs ?? ITI_LANGUAGES as $l) {   // $langs: only these buttons (a personal programme shows just its own language)
        $q = http_build_query(array_merge($keep, ['lang' => $l]));
        $out .= '<a href="?' . h($q) . '"' . ($l === $lang ? ' class="on"' : '') . '>' . strtoupper($l) . '</a>';
    }
    return $out . '<span class="sep"></span>' . $extra . '</div>';
}
