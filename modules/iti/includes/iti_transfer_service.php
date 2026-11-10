<?php
/**
 * iti_transfer_service.php — road transfers of the ITI programmes from the catalogue
 * (iti_transfer_routes), in every language. Independent of iti_functions.php (loaded by
 * iti_texts.php, so the Agent API has it too).
 *
 * A day transfer (iti_day_transfers row) is either
 *   - linked: transfer_route_id → the route; it prints the route's label in the document
 *     language (label_<lang> when written, else generated: short place names + via-point +
 *     duration, "Karatu – Ngorongoro – Serengeti, circa 3 ore"); game_drive = 1 adds "with a game
 *     drive on the way" and leaves the time out. `description` keeps the label in the programme
 *     language (for the editor and old code);
 *   - free text: `description` in the programme language, `description_<lang>` translations.
 *
 * Also: the "shared arrival transfer" sentence, never printed on a private safari (10 Nov 2026).
 */

const ITI_TR_LANGS = ['en', 'it', 'fr', 'es', 'de'];

/**
 * Columns added lazily (MySQL: no ADD COLUMN IF NOT EXISTS): iti_transfer_routes.via_destination +
 * label_<lang>, iti_day_transfers.transfer_route_id + game_drive (+ description_<lang>). DDL commits:
 * call it outside transactions.
 */
function iti_tr_schema(PDO $db): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $want = [
        'iti_transfer_routes' => ['via_destination' => 'INT UNSIGNED NULL DEFAULT NULL'],
        'iti_day_transfers'   => ['transfer_route_id' => 'INT UNSIGNED NULL DEFAULT NULL', 'game_drive' => 'TINYINT(1) NOT NULL DEFAULT 0'],
    ];
    foreach (ITI_TR_LANGS as $l) $want['iti_transfer_routes']['label_' . $l] = 'VARCHAR(255) NULL DEFAULT NULL';
    foreach ($want as $table => $cols) {
        try {
            $have = [];
            foreach ($db->query('SHOW COLUMNS FROM ' . $table)->fetchAll(PDO::FETCH_ASSOC) as $c) $have[$c['Field']] = true;
        } catch (PDOException $e) { continue; }
        foreach ($cols as $c => $def) {
            if (isset($have[$c])) continue;
            try { $db->exec("ALTER TABLE $table ADD COLUMN `$c` $def"); }
            catch (PDOException $e) { error_log('iti_tr_schema ' . $table . '.' . $c . ': ' . $e->getMessage()); }
        }
    }
    if (function_exists('iti_transfers_schema')) iti_transfers_schema($db);   // description_<lang> (iti_texts.php)
}

/** Destinations by id (names in every language, region, code), cached. */
function iti_tr_dests(PDO $db): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = [];
    foreach ($db->query('SELECT * FROM iti_destinations')->fetchAll(PDO::FETCH_ASSOC) as $r) $cache[(int)$r['id']] = $r;
    return $cache;
}

/** Route rows by id (all, active or not), cached; $refresh after a write. */
function iti_tr_routes(PDO $db, bool $refresh = false): array {
    static $cache = null;
    if ($cache !== null && !$refresh) return $cache;
    iti_tr_schema($db);
    $cache = [];
    foreach ($db->query('SELECT * FROM iti_transfer_routes')->fetchAll(PDO::FETCH_ASSOC) as $r) $cache[(int)$r['id']] = $r;
    return $cache;
}

/**
 * Short place name for a transfer line: "Parco Nazionale del Serengeti" → "Serengeti",
 * "Kilimanjaro International Airport" → "Kilimanjaro Airport", "Arusha Città" → "Arusha".
 */
function iti_tr_short_name(string $name, string $lang): string {
    $n = trim($name);
    if ($n === '') return '';
    $rules = [
        'en' => ['/\s+(National Park|Conservation Area|Game Reserve|Town)$/u', '/\s+International(?=\s+Airport)/u'],
        'it' => ["/^(Parco Nazionale|Area di Conservazione|Riserva)\s+(del|della|dello|dei|di|d')\s*/u", '/\s+Città$/u', '/\s+Internazionale/u'],
        'fr' => ["/^(Parc National|Zone de Conservation|Réserve)\s+(du|de la|des|de|d')\s*/u", '/\s+Ville$/u', '/\s+International/u'],
        'es' => ['/^(Parque Nacional|Área de Conservación|Reserva)\s+(del|de la|de los|de)\s*/u', '/\s+Ciudad$/u', '/\s+Internacional/u'],
        'de' => ['/-?(Nationalpark|Schutzgebiet|Wildreservat)$/u', '/\s+Stadt$/u', '/\bInternationaler\b/u'],
    ];
    foreach ($rules[$lang] ?? [] as $re) $n = trim((string)preg_replace('/\s{2,}/u', ' ', (string)preg_replace($re, '', $n)));
    if ($lang === 'de') {
        $n = (string)preg_replace('/^Lake-/u', 'Lake ', $n);                         // "Lake-Manyara" → "Lake Manyara" ("Eyasi-See" stays)
        $n = (string)preg_replace('/^(.+?)\s+Flughafen$/u', 'Flughafen $1', $n);     // "Kilimandscharo Flughafen" → "Flughafen Kilimandscharo"
    }
    return $n !== '' ? $n : trim($name);
}

/** "circa 1 ora e mezza" / "about 1.5 hours" / "environ 1 h 30" / "aprox. 1 hora y media" / "ca. 1,5 Stunden"; '' for 0. */
function iti_tr_duration_text(int $min, string $lang): string {
    if ($min <= 0) return '';
    $h = intdiv($min, 60); $m = $min % 60;
    switch ($lang) {
        case 'it':
            if ($h === 0) return 'circa ' . $m . ' minuti';
            $hs = $h === 1 ? '1 ora' : $h . ' ore';
            return 'circa ' . $hs . ($m === 0 ? '' : ($m === 30 ? ' e mezza' : ' e ' . $m . ' minuti'));
        case 'fr':
            if ($h === 0) return 'environ ' . $m . ' minutes';
            return 'environ ' . ($m === 0 ? ($h === 1 ? '1 heure' : $h . ' heures') : $h . ' h ' . sprintf('%02d', $m));
        case 'es':
            if ($h === 0) return 'aprox. ' . $m . ' minutos';
            $hs = $h === 1 ? '1 hora' : $h . ' horas';
            return 'aprox. ' . ($m === 0 ? $hs : ($m === 30 ? $hs . ' y media' : $h . ' h ' . $m . ' min'));
        case 'de':
            if ($h === 0) return 'ca. ' . $m . ' Minuten';
            return 'ca. ' . ($m === 0 ? ($h === 1 ? '1 Stunde' : $h . ' Stunden') : ($m === 30 ? $h . ',5 Stunden' : $h . ' Std. ' . $m . ' Min.'));
        default:
            if ($h === 0) return 'about ' . $m . ' minutes';
            return 'about ' . ($m === 0 ? ($h === 1 ? '1 hour' : $h . ' hours') : ($m === 30 ? $h . '.5 hours' : $h . ' h ' . $m . ' min'));
    }
}

/** ", with a game drive on the way" in each language. */
function iti_tr_game_drive_text(string $lang): string {
    $t = ['it' => 'con fotosafari lungo il percorso', 'en' => 'with a game drive on the way', 'fr' => 'avec safari photo en chemin',
          'es' => 'con safari fotográfico por el camino', 'de' => 'mit Pirschfahrt unterwegs'];
    return $t[$lang] ?? $t['en'];
}

/**
 * Label of route $route (row or id) in $lang → ['text', 'duration' (time phrase, '' with a game drive),
 * 'missing' => [destination ids without a name in $lang]]. label_<lang> wins when written; otherwise
 * the short place names (from – via – to) and the duration. Never another language: a place without
 * its name in $lang gives text '' and its id in 'missing'.
 */
function iti_tr_label(PDO $db, $route, string $lang, bool $gameDrive = false): array {
    if (!is_array($route)) $route = iti_tr_routes($db)[(int)$route] ?? null;
    $out = ['text' => '', 'duration' => '', 'missing' => []];
    if (!$route) return $out;
    $dur = $gameDrive ? '' : iti_tr_duration_text((int)($route['duration_min'] ?? 0), $lang);
    $out['duration'] = $dur;
    $gd = $gameDrive ? iti_tr_game_drive_text($lang) : '';
    $own = trim((string)($route['label_' . $lang] ?? ''));
    if ($own !== '') {
        $out['text'] = $own . ($gd !== '' && mb_stripos($own, $gd) === false ? ', ' . $gd : '');
        return $out;
    }
    $dests = iti_tr_dests($db);
    $ids = array_values(array_filter([(int)$route['from_destination'], (int)($route['via_destination'] ?? 0), (int)$route['to_destination']]));
    $full = []; $short = [];
    foreach ($ids as $id) {
        $n = trim((string)($dests[$id]['name_' . $lang] ?? ''));
        if ($n === '') { $out['missing'][] = $id; continue; }
        $full[] = $n; $short[] = iti_tr_short_name($n, $lang);
    }
    if ($out['missing']) return $out;
    // "Arusha – Arusha" (town → national park): the full names where two short ones are the same.
    for ($i = 1; $i < count($short); $i++) {
        if (mb_strtolower($short[$i]) === mb_strtolower($short[$i - 1])) { $short[$i - 1] = $full[$i - 1]; $short[$i] = $full[$i]; }
    }
    $tail = $gd !== '' ? $gd : $dur;
    $out['text'] = implode(' – ', $short) . ($tail !== '' ? ', ' . $tail : '');
    return $out;
}

/** The label in every language (generated or written): [lang => text]. */
function iti_tr_labels(PDO $db, $route, bool $gameDrive = false): array {
    $o = [];
    foreach (ITI_TR_LANGS as $l) $o[$l] = iti_tr_label($db, $route, $l, $gameDrive)['text'];
    return $o;
}

/**
 * Text of one day transfer row in $lang → ['text', 'duration', 'missing' => [['type', 'id', 'name', 'field']…]].
 * Linked → the route label; free text → description_<lang>, else `description` only when $lang is the
 * programme language ($srcLang); a free text not translated into $lang prints nothing and is reported.
 */
function iti_tr_row_text(PDO $db, array $t, string $lang, string $srcLang): array {
    $rid = (int)($t['transfer_route_id'] ?? 0);
    if ($rid && isset(iti_tr_routes($db)[$rid])) {
        $L = iti_tr_label($db, $rid, $lang, !empty($t['game_drive']));
        $miss = [];
        foreach ($L['missing'] as $did) {
            $d = iti_tr_dests($db)[$did] ?? [];
            $miss[] = ['type' => 'destination', 'id' => $did, 'name' => (string)($d['name_en'] ?? ''), 'field' => 'name_' . $lang];
        }
        return ['text' => $L['text'], 'duration' => $L['duration'], 'missing' => $miss];
    }
    $desc = trim((string)($t['description'] ?? ''));
    $tx = trim((string)($t['description_' . $lang] ?? ''));
    if ($tx === '' && $lang === $srcLang) $tx = $desc;
    if ($tx === '' && $desc !== '') {
        return ['text' => '', 'duration' => '', 'missing' => [['type' => 'transfer', 'id' => (int)($t['id'] ?? 0), 'name' => $desc, 'field' => 'description_' . $lang]]];
    }
    return ['text' => $tx, 'duration' => '', 'missing' => []];
}

/** Active routes for a picker / the API: id, from, via, to, duration, labels [lang => text]. */
function iti_tr_routes_list(PDO $db): array {
    $dests = iti_tr_dests($db);
    $out = [];
    foreach (iti_tr_routes($db) as $id => $r) {
        if (empty($r['is_active'])) continue;
        $out[] = [
            'id' => $id,
            'from_destination' => (int)$r['from_destination'], 'from_name' => (string)($dests[(int)$r['from_destination']]['name_en'] ?? ''),
            'via_destination' => !empty($r['via_destination']) ? (int)$r['via_destination'] : null,
            'via_name' => !empty($r['via_destination']) ? (string)($dests[(int)$r['via_destination']]['name_en'] ?? '') : null,
            'to_destination' => (int)$r['to_destination'], 'to_name' => (string)($dests[(int)$r['to_destination']]['name_en'] ?? ''),
            'duration_min' => (int)$r['duration_min'], 'distance_km' => $r['distance_km'] !== null ? (int)$r['distance_km'] : null,
            'road_type' => $r['road_type'],
            'labels' => iti_tr_labels($db, $r),
            'own_labels' => array_filter(array_combine(ITI_TR_LANGS, array_map(function ($l) use ($r) { return trim((string)($r['label_' . $l] ?? '')); }, ITI_TR_LANGS)), 'strlen'),
        ];
    }
    usort($out, function ($a, $b) { return strcmp($a['from_name'] . $a['to_name'], $b['from_name'] . $b['to_name']); });
    return $out;
}

/** Both ends in a park / reserve: a game drive on the way makes sense (editor options). */
function iti_tr_is_park_route(PDO $db, array $r): bool {
    $d = iti_tr_dests($db);
    $park = function ($id) use ($d) {
        $x = $d[(int)$id] ?? null;
        return $x && (($x['region'] ?? '') === 'National Parks' || in_array($x['code'] ?? '', ['NDT', 'SNPC', 'SNPN', 'OLD'], true));
    };
    return $park($r['from_destination']) && $park($r['to_destination']);
}

// ── "Shared arrival transfer" sentence ─────────────────────────────────────

/**
 * Remove the "(the arrival transfer may be shared with other Savannah Explorers travellers)" sentence,
 * any language, with the space before it. Only a parenthesis that names Savannah Explorers and speaks
 * of sharing is touched.
 */
function iti_tr_shared_strip(string $text): string {
    if ($text === '' || stripos($text, 'Savannah') === false) return $text;
    return (string)preg_replace('/\s*\((?=[^()]*Savannah\s+Explorers)[^()]*\b(condivis\w*|shared|partag\w*|compartid\w*|geteilt\w*|gemeinsam\w*)\b[^()]*\)/iu', '', $text);
}

/** True when $text carries the sentence. */
function iti_tr_has_shared(string $text): bool {
    return $text !== '' && iti_tr_shared_strip($text) !== $text;
}
