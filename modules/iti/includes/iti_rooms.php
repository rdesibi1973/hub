<?php
/**
 * iti_rooms.php — room category of a night ("Junior Suite Garden View"), next to the room
 * configuration (room_type, "1 Double + 1 Twin"). Beach hotels and some lodges (Meliá…)
 * sell several categories: the lodge keeps its list (iti_lodges.room_categories, one per
 * line), the day its choice (iti_program_days.room_category, free text, suggested from the
 * list or read from the Calc hotel text). Documents show "Category (configuration)".
 *
 * Self-contained (no iti_functions.php): loaded by iti_functions.php and by
 * iti_content_service.php (Agent API).
 */

const ITI_ROOM_CATEGORY_MAX = 100;   // column length of a day's category and of one list line
const ITI_ROOM_CATEGORIES_MAX = 30;  // lines kept per lodge

/** Adds the two columns once (MySQL: no ADD COLUMN IF NOT EXISTS). Never inside a transaction. */
function iti_rooms_schema(PDO $db): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $add = array(
        array('iti_program_days', 'room_category', 'VARCHAR(100) NULL DEFAULT NULL'),
        array('iti_lodges', 'room_categories', 'TEXT NULL DEFAULT NULL'),
    );
    foreach ($add as $a) {
        try {
            if ($db->query('SHOW COLUMNS FROM `' . $a[0] . '` LIKE ' . $db->quote($a[1]))->fetch()) continue;
            $db->exec('ALTER TABLE `' . $a[0] . '` ADD COLUMN `' . $a[1] . '` ' . $a[2]);
        } catch (PDOException $e) {
            error_log('iti_rooms_schema ' . $a[0] . '.' . $a[1] . ': ' . $e->getMessage());
        }
    }
}

/** A lodge's categories from the stored text, a textarea or an API array: trimmed, unique, in order. */
function iti_room_categories_list($v): array {
    $lines = is_array($v) ? $v : preg_split('/\r\n|\r|\n/', (string)$v);
    $out = array(); $seen = array();
    foreach ($lines as $l) {
        $l = trim(preg_replace('/\s+/u', ' ', (string)$l));
        if ($l === '') continue;
        $l = mb_substr($l, 0, ITI_ROOM_CATEGORY_MAX);
        $k = mb_strtolower($l, 'UTF-8');
        if (isset($seen[$k])) continue;
        $seen[$k] = true;
        $out[] = $l;
        if (count($out) >= ITI_ROOM_CATEGORIES_MAX) break;
    }
    return $out;
}

/** The list as stored (one per line), null when empty. */
function iti_room_categories_text($v): ?string {
    $l = iti_room_categories_list($v);
    return $l ? implode("\n", $l) : null;
}

/** What the documents print after the lodge: "Junior Suite (1 camera matrimoniale)", or either part alone. */
function iti_room_label(?string $category, ?string $config): string {
    $c = trim((string)$category); $r = trim((string)$config);
    if ($c === '') return $r;
    if ($r === '' || mb_stripos($c, $r) !== false) return $c;
    return $c . ' (' . $r . ')';
}

/** Lower case, accents folded, words only ("Meliá Zanzibar - Garden View" → "melia zanzibar garden view"). */
function iti_room_norm(string $s): string {
    $s = mb_strtolower($s, 'UTF-8');
    $s = strtr($s, array('á' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
                         'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o',
                         'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ñ' => 'n', 'ç' => 'c', '’' => "'"));
    $s = preg_replace("/'s\b/u", '', $s);
    return trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s));
}

/**
 * Room category in a Calc hotel text (meal suffix already removed), for the lodge it was mapped to.
 * 1. A category of the lodge's list named in the text → that one (listed).
 * 2. Else the words left once the lodge name and generic words are taken out, when they name a room
 *    ("Plantation Room", "Garden View Villa") → matched to the list when close, else kept as typed (not listed:
 *    the day is flagged for review).
 * Returns ['category' => string, 'listed' => bool] or null.
 */
function iti_room_category_from_text(string $text, string $lodgeName, array $categories): ?array {
    if (trim(iti_room_norm($text)) === '') return null;
    static $generic = array('the', 'hotel', 'resort', 'lodge', 'camp', 'tented', 'safari', 'spa', 'and', 'by', 'at', 'boutique',
                            'collection', 'retreat', 'zanzibar', 'own', 'arrangement');
    static $roomWords = array('room', 'rooms', 'suite', 'suites', 'villa', 'view', 'bungalow', 'bungalows', 'chalet', 'cottage', 'tent',
                              'deluxe', 'superior', 'standard', 'junior', 'premium', 'executive', 'apartment', 'studio', 'penthouse',
                              'banda', 'camera', 'vista', 'family', 'honeymoon', 'beachfront', 'oceanfront', 'seafront', 'classic');
    $lodgeWords = array_flip(explode(' ', iti_room_norm($lodgeName)));
    $keep = array(); $isRoom = false;
    foreach (preg_split('/\s+/u', trim($text)) as $tok) {
        $n = iti_room_norm($tok);
        if ($n === '') continue;                                   // "-", "(", "/"
        if (isset($lodgeWords[$n])) continue;
        if (!$keep && in_array($n, $generic, true)) continue;      // generic words count only once a room word may follow
        if (in_array($n, $roomWords, true)) $isRoom = true;
        $keep[] = trim($tok, " \t-–—()/,:;");
    }
    $keep = array_values(array_filter($keep, 'strlen'));
    if (!$isRoom || !$keep) {
        // No room word left ("Riu Palace Ocean Front"): only a listed category named in the text counts.
        $t = ' ' . iti_room_norm($text) . ' ';
        $hits = array();
        foreach ($categories as $c) { $n = iti_room_norm($c); if ($n !== '' && strpos($t, ' ' . $n . ' ') !== false) $hits[] = $c; }
        return count($hits) === 1 ? array('category' => $hits[0], 'listed' => true) : null;
    }
    $cat = mb_substr(implode(' ', $keep), 0, ITI_ROOM_CATEGORY_MAX);
    if ($cat === mb_strtolower($cat, 'UTF-8') || $cat === mb_strtoupper($cat, 'UTF-8')) $cat = mb_convert_case($cat, MB_CASE_TITLE, 'UTF-8');   // "GARDEN VIEW" → "Garden View"

    // The same as a listed one, or close to exactly one ("Garden View" ~ "Garden View Room"): every word of the
    // shorter in the longer, at least two, and no room word of the text left over ("Junior Suite Ocean View" is
    // not "Junior Suite"). Several close ones = ambiguous: kept as typed.
    $w = array_values(array_unique(explode(' ', iti_room_norm($cat))));
    $close = array();
    foreach ($categories as $c) {
        $cw = array_values(array_unique(explode(' ', iti_room_norm($c))));
        if (iti_room_norm($c) === implode(' ', $w)) return array('category' => $c, 'listed' => true);
        $common = count(array_intersect($w, $cw));
        $left = array_diff($w, $cw);
        if ($common >= 2 && $common >= min(count($w), count($cw)) && !array_intersect($left, $roomWords)) $close[] = $c;
    }
    if (count($close) === 1) return array('category' => $close[0], 'listed' => true);
    return array('category' => $cat, 'listed' => false);
}
