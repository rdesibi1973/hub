<?php
/**
 * iti_import_service.php — a programme received as text from a partner (Kenya DMC, …) → a Savannah
 * personal programme (Agent API iti_import_program; later the Hub page "Importa programma", same code).
 *
 * The text is parsed by Claude (Cowork now, the Anthropic API later), never here: the input is already
 * structured — days with titles / narratives in the source language and the client's, destination and
 * lodge names, meals, activities, transfers, included / excluded. This file matches the names with the
 * catalogue (or plans stubs to complete later), checks the texts and writes everything in one transaction.
 *
 * Also here: new destinations (iti_create_destination) and the supplier guard — a partner's name,
 * domain, e-mail or phone never reaches an agency or a client (iti_supplier_blocklist).
 * Writes are plans unless $go ("confirm": true in the API). Loaded by agent_iti_lib().
 */
require_once __DIR__ . '/iti_program_service.php';
require_once __DIR__ . '/iti_content_service.php';

const ITI_DEST_TYPES = ['park', 'reserve', 'lake', 'city', 'beach', 'airport', 'other'];

/** An error with an HTTP code and data for the API reply: a duplicate (400 + existing row), supplier names (422 + hits). */
class ItiImportException extends InvalidArgumentException {
    public $http = 400;
    public $data = [];
    public function __construct(string $msg, int $http = 400, array $data = []) { parent::__construct($msg); $this->http = $http; $this->data = $data; }
}

// ── Supplier guard ───────────────────────────────────────────────────────────

/** Blocklisted terms of every supplier [{supplier, term}], plus $supplier's own name when given. */
function iti_im_supplier_terms(PDO $db, string $supplier = ''): array {
    iti_ensure_country_schema();
    $out = $db->query('SELECT supplier, term FROM iti_supplier_blocklist ORDER BY supplier, term')->fetchAll(PDO::FETCH_ASSOC);
    if (($supplier = trim($supplier)) !== '') $out[] = ['supplier' => $supplier, 'term' => $supplier];
    return $out;
}

/**
 * $term in $text: a phone number (7+ digits) by its last 9 digits in any number of the text
 * ("+254 20 123 4567" also finds "020 1234567"); anything else as a whole word / string, any case
 * ("BTG" finds "BTG Ltd" and "info@btgltd.com" finds the domain, not "abtgx").
 */
function iti_im_term_in(string $text, string $term): bool {
    $term = trim($term);
    if ($term === '' || trim($text) === '') return false;
    $digits = preg_replace('/\D+/', '', $term);
    if (strlen($digits) >= 7 && preg_match('/^[\d\s+().\-\/]+$/', $term)) {
        $tail = substr($digits, -9);
        preg_match_all('/\+?\d[\d\s().\-\/]{5,}\d/', $text, $m);
        foreach ($m[0] as $num) if (strpos(preg_replace('/\D+/', '', $num), $tail) !== false) return true;
        return false;
    }
    return (bool)preg_match('/(?<![\p{L}\p{N}])' . preg_quote($term, '/') . '(?![\p{L}\p{N}])/iu', $text);
}

/** Supplier terms found in any string of $data (nested arrays) → [{field, term, supplier}]. */
function iti_im_supplier_hits($data, array $terms, string $path = ''): array {
    if (is_array($data)) {
        $hits = [];
        foreach ($data as $k => $v) $hits = array_merge($hits, iti_im_supplier_hits($v, $terms, $path === '' ? (string)$k : $path . '.' . $k));
        return $hits;
    }
    if (!is_string($data)) return [];
    $hits = [];
    foreach ($terms as $t) if (iti_im_term_in($data, (string)$t['term'])) $hits[] = ['field' => $path, 'term' => $t['term'], 'supplier' => $t['supplier']];
    return $hits;
}

/** Refuse (422) when hits were found. */
function iti_im_supplier_guard(array $hits): void {
    if (!$hits) return;
    $terms = array_values(array_unique(array_map(function ($h) { return $h['term']; }, $hits)));
    throw new ItiImportException('Supplier name in the texts (' . implode(', ', $terms) . ') — remove it: the partner is never named to agencies or clients',
                                 422, ['supplier_hits' => array_slice($hits, 0, 50)]);
}

/** A programme imported from a partner (source_supplier set): refuse $data (the fields of an edit) naming a supplier. */
function iti_im_guard_input(PDO $db, int $pid, $data): void {
    iti_ensure_country_schema();
    $p = $pid > 0 ? iti_get_program($pid) : false;
    if (!$p || trim((string)($p['source_supplier'] ?? '')) === '') return;
    iti_im_supplier_guard(iti_im_supplier_hits($data, iti_im_supplier_terms($db, (string)$p['source_supplier'])));
}

/** Same, on what the documents print (iti_doc_data(): texts, days, lists, prices) — before a file is made. */
function iti_im_guard_document(PDO $db, int $pid, string $lang): void {
    iti_ensure_country_schema();
    $p = $pid > 0 ? iti_get_program($pid) : false;
    if (!$p || trim((string)($p['source_supplier'] ?? '')) === '') return;
    $lang = in_array($lang, ITI_PS_LANGS, true) ? $lang : ($p['display_language'] ?: 'it');
    $D = iti_doc_data($pid, $lang);
    $days = [];
    foreach ($D['days'] as $d) { unset($d['lodge_photos'], $d['dest_photo'], $d['lodge_key'], $d['lodge_url']); $days[] = $d; }
    $print = ['title' => $D['title'], 'subtitle' => $D['subtitle'], 'intro' => $D['intro'], 'days' => $days, 'included' => $D['incl'],
              'excluded' => $D['excl'], 'prices' => $D['prices'], 'price_notes' => $D['price_notes']];
    iti_im_supplier_guard(iti_im_supplier_hits($print, iti_im_supplier_terms($db, (string)$p['source_supplier'])));
}

/** The blocklist by supplier: {"BTG": ["BTG", "Bridge The Gap", "btgltd.com"], …}. */
function iti_im_blocklist(PDO $db): array {
    $out = [];
    foreach (iti_im_supplier_terms($db) as $r) $out[$r['supplier']][] = $r['term'];
    return $out;
}

/** Add / remove terms of one supplier. Returns the plan (would_add / would_remove); with $go they are written. */
function iti_im_blocklist_set(PDO $db, string $supplier, array $add, array $remove, string $who, bool $go): array {
    iti_ensure_country_schema();
    $supplier = mb_substr(trim($supplier), 0, 80);
    if ($supplier === '') throw new InvalidArgumentException('supplier is required (short name, e.g. "BTG")');
    $clean = function ($a) { $o = []; foreach ($a as $t) { $t = mb_substr(trim((string)$t), 0, 160); if ($t !== '') $o[$t] = true; } return array_keys($o); };
    $add = $clean($add); $remove = $clean($remove);
    if (!$add && !$remove) throw new InvalidArgumentException('Send add[] and / or remove[] (names, domains, e-mails, phone numbers)');
    $st = $db->prepare('SELECT term FROM iti_supplier_blocklist WHERE supplier = ?');
    $st->execute([$supplier]);
    $have = $st->fetchAll(PDO::FETCH_COLUMN);
    $plan = ['supplier' => $supplier, 'would_add' => array_values(array_diff($add, $have)), 'would_remove' => array_values(array_intersect($remove, $have))];
    if (!$go) return $plan;
    $ins = $db->prepare('INSERT INTO iti_supplier_blocklist (supplier, term, created_by) VALUES (?, ?, ?)');
    foreach ($plan['would_add'] as $t) $ins->execute([$supplier, $t, $who]);
    $del = $db->prepare('DELETE FROM iti_supplier_blocklist WHERE supplier = ? AND term = ?');
    foreach ($plan['would_remove'] as $t) $del->execute([$supplier, $t]);
    return ['supplier' => $supplier, 'added' => $plan['would_add'], 'removed' => $plan['would_remove'], 'blocklist' => iti_im_blocklist($db)];
}

// ── Name matching ────────────────────────────────────────────────────────────

function iti_im_ascii(string $s): string {
    if (function_exists('transliterator_transliterate')) {
        $t = transliterator_transliterate('Any-Latin; Latin-ASCII', $s);
        if (is_string($t)) return $t;
    }
    $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
    return is_string($t) && $t !== '' ? $t : $s;
}

/** A name for matching: lower-case ASCII without the generic words (lodge: Camp / Lodge / Resort / Hotel / & / Spa; area: National Park / Reserve …). */
function iti_im_norm(string $s, string $kind): string {
    $s = preg_replace('/[^a-z0-9]+/', ' ', strtolower(iti_im_ascii(str_replace('&', ' ', $s))));
    $generic = $kind === 'lodge' ? 'camp|camps|lodge|lodges|resort|hotel|hotels|spa|and|the|tented'
                                 : 'national|park|reserve|game|conservancy|conservation|area|the|of|np|nr';
    return trim(preg_replace('/\s+/', ' ', preg_replace('/\b(' . $generic . ')\b/', ' ', $s)));
}

/** 100 same name, 95 same without the generic words, 85 one contained in the other (whole words), else similarity %. */
function iti_im_score(string $text, string $name, string $kind): int {
    if (trim($name) === '') return 0;
    if (strcasecmp(trim($text), trim($name)) === 0) return 100;
    $a = iti_im_norm($text, $kind); $b = iti_im_norm($name, $kind);
    if ($a === '' || $b === '') return 0;
    if ($a === $b) return 95;
    $short = strlen($a) <= strlen($b) ? $a : $b;
    $long  = $short === $a ? $b : $a;
    if (strlen($short) >= 4 && preg_match('/\b' . preg_quote($short, '/') . '\b/', $long)) return 85;
    $max = max(strlen($a), strlen($b));
    return $max > 255 ? 0 : (int)round(100 * (1 - levenshtein($a, $b) / $max));
}

/**
 * Catalogue rows a name can match: active, in the programme's countries (a Kenya import never picks a
 * Tanzania lodge); destinations without airports / airstrips (those are for flights and transfers).
 * → [{id, names[], label, country, destination_id?}]
 */
function iti_im_candidates(PDO $db, string $kind, array $countries): array {
    $out = [];
    if ($kind === 'destination') {
        foreach ($db->query('SELECT * FROM iti_destinations WHERE is_active = 1')->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (!in_array(iti_country_code($r['country'] ?? ''), $countries, true)) continue;
            if (in_array($r['region'], ['Airports', 'Airstrips'], true) || ($r['dest_type'] ?? '') === 'airport') continue;
            $out[] = ['id' => (int)$r['id'], 'names' => [$r['name_en'], $r['name_it'], $r['code']], 'label' => $r['name_en']];
        }
        return $out;
    }
    foreach ($db->query('SELECT l.id, l.name, l.destination_id, d.country FROM iti_lodges l JOIN iti_destinations d ON d.id = l.destination_id
                          WHERE l.is_active = 1')->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (!in_array(iti_country_code($r['country'] ?? ''), $countries, true)) continue;
        $out[] = ['id' => (int)$r['id'], 'names' => [$r['name']], 'label' => $r['name'], 'destination_id' => (int)$r['destination_id']];
    }
    return $out;
}

/**
 * Best catalogue row for $text: score ≥ 80 and not tied with another row → matched; otherwise no id
 * and the closest candidates (score ≥ 60) so the caller can pass the id.
 */
function iti_im_match(string $text, array $cands, string $kind): array {
    $scored = [];
    foreach ($cands as $c) {
        $s = 0;
        foreach ($c['names'] as $n) $s = max($s, iti_im_score($text, (string)$n, $kind));
        if ($s >= 60) $scored[] = ['id' => $c['id'], 'name' => $c['label'], 'score' => $s];
    }
    usort($scored, function ($x, $y) { return $y['score'] - $x['score']; });
    $top = $scored[0] ?? null;
    $tied = $top && isset($scored[1]) && $scored[1]['score'] === $top['score'];
    if ($top && $top['score'] >= 80 && !$tied) return ['id' => $top['id'], 'name' => $top['name'], 'score' => $top['score'], 'candidates' => []];
    return ['id' => null, 'name' => null, 'score' => $top['score'] ?? 0, 'candidates' => array_slice($scored, 0, 3)];
}

// ── New destinations ─────────────────────────────────────────────────────────

/** A free destination code: 3–4 letters from the name ("Amboseli National Park" → AMB, "Tsavo West" → TSW), else with a digit. */
function iti_im_dest_code(PDO $db, string $name, array $reserved = []): string {
    $used = [];
    foreach ($db->query('SELECT code FROM iti_destinations')->fetchAll(PDO::FETCH_COLUMN) as $c) $used[strtoupper((string)$c)] = true;
    foreach ($reserved as $c) $used[strtoupper($c)] = true;
    $plain = preg_replace('/\b(national|park|reserve|game|lake|the|of|airport|airstrip|beach|conservancy)\b/i', ' ', iti_im_ascii($name));
    $words = preg_split('/[^A-Za-z]+/', strtoupper($plain), -1, PREG_SPLIT_NO_EMPTY);
    if (!$words) $words = preg_split('/[^A-Za-z]+/', strtoupper(iti_im_ascii($name)), -1, PREG_SPLIT_NO_EMPTY) ?: ['DST'];
    $w0 = str_pad($words[0], 3, 'X');
    $tries = [substr($w0, 0, 3)];
    if (isset($words[1])) $tries[] = substr($w0, 0, 2) . $words[1][0];
    $tries[] = substr($w0, 0, 4);
    foreach ($tries as $t) if (strlen($t) >= 3 && !isset($used[$t])) return $t;
    for ($i = 2; $i < 100; $i++) if (!isset($used[substr($w0, 0, 3) . $i])) return substr($w0, 0, 3) . $i;
    throw new InvalidArgumentException('No free code for "' . $name . '" — pass fields.code');
}

/** An existing destination with one of these names (any language) or this code, or null. */
function iti_im_dest_existing(PDO $db, array $names, string $code = ''): ?array {
    $w = []; $a = [];
    foreach (array_unique(array_filter(array_map('trim', $names), 'strlen')) as $n) { $w[] = '(name_en = ? OR name_it = ?)'; array_push($a, $n, $n); }
    if ($code !== '') { $w[] = 'code = ?'; $a[] = $code; }
    if (!$w) return null;
    $st = $db->prepare('SELECT * FROM iti_destinations WHERE ' . implode(' OR ', $w) . ' LIMIT 1');
    $st->execute($a);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

/**
 * New destination. $f: name_en*, country* (TZ | KE | … or the name), name_<lang>, code (3–4 letters, unique;
 * made from the name when missing), type (park | reserve | lake | city | beach | airport | other), region
 * (default from the type: National Parks / Airports / the country), latitude, longitude, description_<lang>.
 * A name or code that exists → ItiImportException 400 with the existing row. $stub: made by an import,
 * flagged needs_completion. A language without a name stays empty (the documents never print another language).
 */
function iti_im_create_destination(PDO $db, array $f, bool $go, bool $stub = false, array $reservedCodes = []): array {
    iti_ensure_country_schema();
    iti_photos_schema();
    $allowed = ['code', 'country', 'region', 'latitude', 'longitude', 'type'];
    foreach (ITI_PS_LANGS as $l) { $allowed[] = 'name_' . $l; $allowed[] = 'description_' . $l; }
    $bad = array_values(array_diff(array_keys($f), $allowed));
    if ($bad) throw new InvalidArgumentException('Not settable: ' . implode(', ', $bad) . ' — allowed: ' . implode(', ', $allowed));
    $name = trim((string)($f['name_en'] ?? ''));
    if ($name === '') throw new InvalidArgumentException('fields.name_en is required');
    $cc = iti_country_code($f['country'] ?? '');
    if ($cc === '') throw new InvalidArgumentException('fields.country is required: ' . implode(' | ', array_keys(ITI_COUNTRIES)) . ' (code or name)');
    $type = trim((string)($f['type'] ?? '')) ?: 'other';
    if (!in_array($type, ITI_DEST_TYPES, true)) throw new InvalidArgumentException('type: ' . implode(' | ', ITI_DEST_TYPES));
    $code = strtoupper(trim((string)($f['code'] ?? '')));
    if ($code !== '' && !preg_match('/^[A-Z]{3,4}$/', $code)) throw new InvalidArgumentException('code: 3–4 letters (e.g. AMB)');

    $names = [$name];
    foreach (ITI_PS_LANGS as $l) $names[] = (string)($f['name_' . $l] ?? '');
    if ($dup = iti_im_dest_existing($db, $names, $code)) {
        throw new ItiImportException('Destination already exists: #' . $dup['id'] . ' ' . $dup['name_en'] . ' (' . $dup['code'] . ')', 400,
                                     ['existing' => iti_cs_destination_out($dup, true)]);
    }
    if ($code === '') $code = iti_im_dest_code($db, $name, $reservedCodes);

    $regions = ['park' => 'National Parks', 'reserve' => 'National Parks', 'airport' => 'Airports'];
    $row = ['code' => $code, 'region' => trim((string)($f['region'] ?? '')) ?: ($regions[$type] ?? ITI_COUNTRIES[$cc]),
            'country' => ITI_COUNTRIES[$cc], 'dest_type' => $type, 'needs_completion' => $stub ? 1 : 0,
            'is_beach_stay' => $type === 'beach' ? 1 : 0, 'is_active' => 1, 'sort_order' => 0];
    foreach (ITI_PS_LANGS as $l) {
        $row['name_' . $l] = $l === 'en' ? $name : trim((string)($f['name_' . $l] ?? ''));
        $row['description_' . $l] = trim((string)($f['description_' . $l] ?? ''));
    }
    foreach (['latitude' => 90, 'longitude' => 180] as $k => $lim) {
        $v = $f[$k] ?? null;
        if ($v === null || $v === '') { $row[$k] = null; continue; }
        if (!is_numeric($v) || abs((float)$v) > $lim) throw new InvalidArgumentException($k . ': not a valid coordinate');
        $row[$k] = round((float)$v, 6);
    }
    $row = array_intersect_key($row, array_flip(iti_table_columns_cs($db, 'iti_destinations')));
    $missing = [];
    foreach (ITI_PS_LANGS as $l) if (($row['name_' . $l] ?? '') === '') $missing[] = 'name_' . $l;
    if (!$go) return ['would_create' => $row, 'missing_names' => $missing];
    $db->prepare('INSERT INTO iti_destinations (`' . implode('`,`', array_keys($row)) . '`) VALUES (' . implode(',', array_fill(0, count($row), '?')) . ')')
       ->execute(array_values($row));
    $id = (int)$db->lastInsertId();
    return ['created' => iti_cs_destination_out(iti_cs_destination_row($db, $id), true), 'missing_names' => $missing];
}

// ── Import ───────────────────────────────────────────────────────────────────

/** Meal flags of an imported day from "meals": "B,L,D" / "(B,L,D)" / "BB" / "HB" / "FB" / "AI"; [] when not given. */
function iti_im_meals(array $d): array {
    $m = strtoupper(trim((string)($d['meals'] ?? '')));
    if ($m === '') return [];
    $f = ['meal_breakfast' => 0, 'meal_lunch' => 0, 'meal_dinner' => 0, 'meal_all_inclusive' => 0];
    if (preg_match('/ALL\s*INCLUSIVE/', $m)) $m = 'AI';
    preg_match_all('/[A-Z]+/', $m, $tok);
    foreach ($tok[0] as $t) {
        if ($t === 'B' || $t === 'BB') $f['meal_breakfast'] = 1;
        elseif ($t === 'L') $f['meal_lunch'] = 1;
        elseif ($t === 'D') $f['meal_dinner'] = 1;
        elseif ($t === 'HB') $f['meal_breakfast'] = $f['meal_dinner'] = 1;
        elseif ($t === 'FB') $f['meal_breakfast'] = $f['meal_lunch'] = $f['meal_dinner'] = 1;
        elseif ($t === 'AI') $f = ['meal_breakfast' => 1, 'meal_lunch' => 1, 'meal_dinner' => 1, 'meal_all_inclusive' => 1];
        elseif ($t !== 'RO' && $t !== 'NONE') throw new InvalidArgumentException('meals "' . $d['meals'] . '": use B, L, D, BB, HB, FB, AI (e.g. "B,L,D")');
    }
    return $f;
}

/** "B,L,D" from a day's meal columns; null when the day sets none (the table default applies: B,L,D). */
function iti_im_meals_text(array $cols): ?string {
    if (!array_key_exists('meal_breakfast', $cols) && !array_key_exists('meal_lunch', $cols) && !array_key_exists('meal_dinner', $cols)) return null;
    if (!empty($cols['meal_all_inclusive'])) return 'AI';
    $p = [];
    if (!empty($cols['meal_breakfast'])) $p[] = 'B';
    if (!empty($cols['meal_lunch'])) $p[] = 'L';
    if (!empty($cols['meal_dinner'])) $p[] = 'D';
    return $p ? implode(',', $p) : '—';
}

/** Likely English (a leftover in an Italian / French … field): 6+ words, 12 % or more of them English function words. */
function iti_im_looks_english(string $t): bool {
    $w = preg_split('/[^a-z\']+/', strtolower($t), -1, PREG_SPLIT_NO_EMPTY);
    if (count($w) < 6) return false;
    static $en = null;
    if ($en === null) $en = array_flip(['the', 'and', 'with', 'of', 'to', 'your', 'you', 'will', 'after', 'for', 'is', 'are', 'at', 'this',
                                        'from', 'we', 'our', 'where', 'which', 'then', 'into', 'on', 'overnight', 'breakfast', 'dinner', 'lunch']);
    $hits = 0;
    foreach ($w as $x) if (isset($en[$x])) $hits++;
    return $hits / count($w) >= 0.12;
}

/** Lodge type from the name (the stub is completed later on the Lodges page). */
function iti_im_lodge_type(string $name): string {
    if (preg_match('/\bmobile\b/i', $name)) return 'mobile_camp';
    if (preg_match('/\b(camps?|tented)\b/i', $name)) return 'tented_camp';
    if (preg_match('/\b(hotel|novotel|ibis|hilton|marriott|radisson|sheraton|inn|suites?)\b/i', $name)) return 'hotel';
    return 'lodge';
}

/**
 * ref_number of an imported programme: fields.ref_number; else the request folder's programme Word
 * (as iti_create_personal); with no programme Word there — a partner's programme has none —
 * NN_<folder>_<title> ("01_Kenya2027(BramardiViaggi-Roberto)_KenyaClassic"; title = fields.ref_suffix,
 * else the title_en / title before " – "). Several Words → ItiRefException (never guessed).
 */
function iti_im_ref(PDO $db, array $set, string $suffix, string $lang): array {
    if (isset($set['ref_number'])) return ['ref_number' => $set['ref_number'], 'source' => 'fields'];
    if (empty($set['lead_request_id'])) return ['ref_number' => null, 'source' => null];
    try { return iti_ps_ref_from_request($db, (int)$set['lead_request_id']); }
    catch (ItiRefException $e) {
        // Only "folder read, no programme Word in it" is built here; no folder / unreadable / several Words → the 409.
        if (!array_key_exists('word_candidates', $e->data) || $e->data['word_candidates']) throw $e;
    }
    $base = iti_lead_ref_number((int)$set['lead_request_id']);   // "01_Kenya2027(BramardiViaggi-Roberto)"
    if ($base === null) throw new ItiRefException('ref_number: request ' . $set['lead_request_id'] . ' has no folder — pass fields.ref_number');
    if ($suffix === '') {
        $t = trim((string)($set['title_en'] ?? '')) ?: trim((string)($set['title_' . $lang] ?? ''));
        $suffix = (string)preg_split('/\s+[–—-]\s+/u', $t)[0];
    }
    $suffix = implode('', array_map('ucfirst', preg_split('/[^A-Za-z0-9]+/', iti_im_ascii($suffix), -1, PREG_SPLIT_NO_EMPTY)));
    $ref = $suffix !== '' ? $base . '_' . $suffix : $base;
    if (mb_strlen($ref) > iti_ps_ref_max()) $ref = mb_substr($ref, 0, iti_ps_ref_max());
    return ['ref_number' => iti_ps_ref_check($ref), 'source' => 'folder + title'];
}

/**
 * Import a programme (see the file header and docs/AGENT_API.md iti_import_program). $in:
 *   lead_request_id?, country* ("KE", "TZ+KE"), display_language (default it), fields {title_<lang>*, subtitle_<lang>,
 *   intro_<lang>, start_date, pax_*, price_table_json, price_notes_<lang>, safari_type, ref_number, ref_suffix, terms_variant},
 *   source {supplier, file} (internal only), days[] (day items, plus destination / end_lodge as text, "meals": "B,L,D",
 *   destination_type / destination_country / destination_name_<lang> and end_lodge_type / end_lodge_category for new rows),
 *   included[] / excluded[] ({text_<lang>…} | "text" | {std_id}), standard? (default true: the country's standard items are
 *   added), create_missing? (stubs for unknown lodges / destinations), nights? (checked against the days).
 * Returns the plan (day table, matches, to_create, warnings, gaps); with $go the programme, in one transaction.
 */
function iti_im_import(PDO $db, array $in, string $who, bool $go): array {
    // DDL first: the confirm runs in one transaction (ALTER TABLE would commit it).
    iti_ps_schema();
    iti_ensure_final_schema();
    iti_photos_schema();
    iti_ensure_country_schema();

    $countries = iti_country_list($in['country'] ?? '');
    if (!$countries) throw new InvalidArgumentException('country is required: ' . implode(' | ', array_keys(ITI_COUNTRIES)) . ' (mixed: TZ+KE)');
    $country = implode('+', $countries);
    $fields = isset($in['fields']) && is_array($in['fields']) ? $in['fields'] : [];
    $lang = (string)($in['display_language'] ?? $fields['display_language'] ?? 'it');
    if (!in_array($lang, ITI_PS_LANGS, true)) throw new InvalidArgumentException('display_language: ' . implode('|', ITI_PS_LANGS));
    $days = isset($in['days']) && is_array($in['days']) ? array_values($in['days']) : [];
    if (!$days) throw new InvalidArgumentException('days[] is required');
    $source = isset($in['source']) && is_array($in['source']) ? $in['source'] : [];
    $supplier = mb_substr(trim((string)($source['supplier'] ?? '')), 0, 80);
    $file = mb_substr(trim((string)($source['file'] ?? '')), 0, 255);
    $createMissing = !empty($in['create_missing']);

    // 1. Supplier names: nowhere but in source (stored internally, never printed).
    $check = $in;
    unset($check['source'], $check['confirm'], $check['create_missing'], $check['standard']);
    iti_im_supplier_guard(iti_im_supplier_hits($check, iti_im_supplier_terms($db, $supplier)));

    // 2. Header, as iti_create_personal (blank programme).
    $tv = isset($fields['terms_variant']) ? strtolower(trim((string)$fields['terms_variant'])) : 'auto';
    if (!in_array($tv, ['direct', 'agency', 'auto'], true)) throw new InvalidArgumentException('terms_variant: direct | agency | auto');
    $suffix = trim((string)($fields['ref_suffix'] ?? ''));
    unset($fields['terms_variant'], $fields['ref_suffix']);
    $fields['display_language'] = $lang;
    $fields['country'] = $country;
    $set = iti_ps_header_values($fields);
    if (trim((string)($set['title_' . $lang] ?? '')) === '') throw new InvalidArgumentException('fields.title_' . $lang . ' is required');
    foreach (ITI_PS_LANGS as $l) if (!isset($set['title_' . $l]) || $set['title_' . $l] === '') $set['title_' . $l] = $set['title_' . $lang];   // NOT NULL columns
    $set['stage'] = 'proposal';
    $set['source_supplier'] = $supplier !== '' ? $supplier : null;
    $set['source_file'] = $file !== '' ? $file : null;
    if (!empty($in['lead_request_id'])) {
        $st = $db->prepare('SELECT id FROM requests WHERE id = ?');
        $st->execute([(int)$in['lead_request_id']]);
        if (!$st->fetchColumn()) throw new InvalidArgumentException('lead_request_id ' . (int)$in['lead_request_id'] . ' not found');
        $set['lead_request_id'] = (int)$in['lead_request_id'];
    }
    $ref = iti_im_ref($db, $set, $suffix, $lang);
    $set['ref_number'] = $ref['ref_number'];
    if (!isset($set['safari_type'])) $set['safari_type'] = iti_safari_type_auto($db, $set);

    // 3. Days: names → catalogue rows (or stubs / free text), meals, the rest as a day item.
    $destC = iti_im_candidates($db, 'destination', $countries);
    $lodgeC = iti_im_candidates($db, 'lodge', $countries);
    $matches = []; $newDest = []; $newLodge = []; $items = []; $table = []; $warnings = []; $errors = [];
    $note = function ($kind, $text, $m) use (&$matches) {
        $k = $kind . '|' . mb_strtolower($text);
        if (!isset($matches[$k])) $matches[$k] = ['kind' => $kind, 'text' => $text, 'matched_id' => $m['id'], 'matched_name' => $m['name'],
                                                  'score' => $m['score'], 'candidates' => $m['candidates']];
    };
    $nDays = count($days);
    foreach ($days as $i => $d) {
        $d = (array)$d; $n = $i + 1;
        // Destination
        $dest = null;
        if (!empty($d['destination_id'])) {
            $dest = ['state' => 'matched', 'id' => iti_pb_lookup($db, 'destination', (int)$d['destination_id'])];
        } elseif (($txt = trim((string)($d['destination'] ?? ''))) !== '') {
            $m = iti_im_match($txt, $destC, 'destination');
            $note('destination', $txt, $m);
            if ($m['id']) {
                $dest = ['state' => 'matched', 'id' => $m['id'], 'name' => $m['name']];
            } else {
                $key = 'd|' . (iti_im_norm($txt, 'destination') ?: mb_strtolower($txt));
                $cc = isset($d['destination_country']) ? iti_country_code($d['destination_country']) : (count($countries) === 1 ? $countries[0] : '');
                if ($cc === '' && $createMissing) $errors[] = 'day ' . $n . ': destination_country is required for the new destination "' . $txt . '" of a ' . $country . ' programme';
                if (!isset($newDest[$key])) {
                    $f = ['name_en' => $txt, 'country' => $cc ?: $countries[0], 'type' => (string)($d['destination_type'] ?? 'other')];
                    foreach (ITI_PS_LANGS as $l) if (trim((string)($d['destination_name_' . $l] ?? '')) !== '' && $l !== 'en') $f['name_' . $l] = trim((string)$d['destination_name_' . $l]);
                    $newDest[$key] = ['fields' => $f, 'days' => []];
                }
                $newDest[$key]['days'][] = $n;
                $dest = $createMissing ? ['state' => 'new', 'key' => $key, 'name' => $txt] : ['state' => 'text', 'name' => $txt];
            }
        }
        // Lodge (the night's)
        $lodge = null;
        if (!empty($d['end_lodge_id'])) {
            $lodge = ['state' => 'matched', 'id' => iti_pb_lookup($db, 'lodge', (int)$d['end_lodge_id'])];
        } elseif (($txt = trim((string)($d['end_lodge'] ?? ''))) !== '') {
            $m = iti_im_match($txt, $lodgeC, 'lodge');
            $note('lodge', $txt, $m);
            if ($m['id']) {
                $lodge = ['state' => 'matched', 'id' => $m['id'], 'name' => $m['name']];
            } else {
                $key = 'l|' . (iti_im_norm($txt, 'lodge') ?: mb_strtolower($txt));
                if (!isset($newLodge[$key])) {
                    $cat = (string)($d['end_lodge_category'] ?? 'mid');
                    $type = (string)($d['end_lodge_type'] ?? iti_im_lodge_type($txt));
                    if (!in_array($cat, ['budget', 'mid', 'luxury', 'ultra_luxury'], true)) $errors[] = 'day ' . $n . ': end_lodge_category budget|mid|luxury|ultra_luxury';
                    if (!in_array($type, ['lodge', 'tented_camp', 'hotel', 'mobile_camp', 'house'], true)) $errors[] = 'day ' . $n . ': end_lodge_type lodge|tented_camp|hotel|mobile_camp|house';
                    // The lodge's area = this day's destination (an existing one or the new one).
                    $newLodge[$key] = ['name' => $txt, 'category' => $cat, 'lodge_type' => $type, 'days' => [],
                                       'destination_id' => $dest && $dest['state'] === 'matched' ? $dest['id'] : null,
                                       'dest_key' => $dest && $dest['state'] === 'new' ? $dest['key'] : null,
                                       'destination' => $dest['name'] ?? null];
                    if ($createMissing && !$newLodge[$key]['destination_id'] && !$newLodge[$key]['dest_key']) {
                        $errors[] = 'day ' . $n . ': the new lodge "' . $txt . '" needs the day\'s destination (its area) — add destination, or end_lodge_id';
                    }
                }
                $newLodge[$key]['days'][] = $n;
                $lodge = $createMissing ? ['state' => 'new', 'key' => $key, 'name' => $txt] : ['state' => 'text', 'name' => $txt];
            }
        }
        // The rest → day item (texts, meals, activities, transfers, flights, room_type, *_custom).
        $raw = $d;
        foreach (['destination', 'destination_id', 'end_lodge', 'end_lodge_id', 'meals', 'destination_type', 'destination_country',
                  'end_lodge_type', 'end_lodge_category'] as $k) unset($raw[$k]);
        foreach (ITI_PS_LANGS as $l) unset($raw['destination_name_' . $l]);
        foreach (iti_im_meals($d) as $k => $v) if (!array_key_exists($k, $raw)) $raw[$k] = $v;
        try { $item = iti_pb_day_item($db, $raw); }
        catch (InvalidArgumentException $e) { throw new InvalidArgumentException('days[' . $i . '] (day ' . $n . '): ' . $e->getMessage()); }
        $items[] = ['item' => $item, 'dest' => $dest, 'lodge' => $lodge];

        $meals = iti_im_meals_text($item['cols']);
        $table[] = ['day' => $n, 'title' => trim((string)($d['day_title_' . $lang] ?? '')) ?: trim((string)($d['day_title_en'] ?? '')),
                    'destination' => $dest ? $dest + ['name' => null] : null, 'lodge' => $lodge ? $lodge + ['name' => null] : null,
                    'meals' => $meals ?? 'B,L,D (default — not given)'];

        // Checks (§5 of the handoff): place / lodge, meals, texts in the programme language.
        $last = $n === $nDays;
        if (!$last && !$dest && empty($item['cols']['destination_custom'])) $warnings[] = 'Day ' . $n . ': no destination';
        if (!$last && !$lodge && empty($item['cols']['end_lodge_custom'])) $warnings[] = 'Day ' . $n . ': no lodge for the night';
        if ($last && ($lodge || !empty($item['cols']['end_lodge_custom']))) $warnings[] = 'Day ' . $n . ' (last day) has a lodge — check: the programme ends that day';
        if ($meals === null) $warnings[] = 'Day ' . $n . ': meals not given — stored as B,L,D (send "meals")';
        elseif (!$last && empty($item['cols']['meal_dinner']) && empty($item['cols']['meal_all_inclusive'])) $warnings[] = 'Day ' . $n . ': no dinner on a night day (' . $meals . ') — check';
        elseif ($last && (!empty($item['cols']['meal_lunch']) || !empty($item['cols']['meal_dinner']))) $warnings[] = 'Day ' . $n . ' (last day): ' . $meals . ' — check (usually breakfast only)';
        if ($lang !== 'en') {
            foreach (['day_title', 'narrative'] as $k) {
                $t = trim((string)($d[$k . '_' . $lang] ?? ''));
                $en = trim((string)($d[$k . '_en'] ?? ''));
                if ($t === '' && $en !== '') $warnings[] = 'Day ' . $n . ': ' . $k . '_' . $lang . ' missing (only English given)';
                elseif ($t !== '' && ($t === $en || iti_im_looks_english($t))) $warnings[] = 'Day ' . $n . ': ' . $k . '_' . $lang . ' looks English — translate it';
            }
        }
    }
    if ($errors) throw new InvalidArgumentException(implode('; ', $errors));
    if (isset($in['nights']) && (int)$in['nights'] !== $nDays - 1) $warnings[] = 'nights = ' . (int)$in['nights'] . ' but ' . $nDays . ' days (' . ($nDays - 1) . ' nights) — check the days';
    if ($lang !== 'en') {
        foreach (['title', 'subtitle', 'intro'] as $k) {
            $t = trim((string)($fields[$k . '_' . $lang] ?? ''));
            if ($t !== '' && iti_im_looks_english($t)) $warnings[] = 'fields.' . $k . '_' . $lang . ' looks English — translate it';
        }
    }

    // New rows: a name that exists elsewhere (another country, inactive) would be refused on create.
    $toCreate = []; $codes = [];
    foreach ($newDest as $key => $nd) {
        $x = ['kind' => 'destination', 'name' => $nd['fields']['name_en'], 'country' => $nd['fields']['country'], 'type' => $nd['fields']['type'], 'days' => $nd['days']];
        if ($ex = iti_im_dest_existing($db, [$nd['fields']['name_en']])) $x['conflict'] = 'exists as #' . $ex['id'] . ' ' . $ex['name_en'] . ' (country ' . $ex['country'] . ', active ' . (int)$ex['is_active'] . ') — pass destination_id';
        elseif ($createMissing) { $codes[] = $newDest[$key]['code'] = iti_im_dest_code($db, $nd['fields']['name_en'], $codes); $x['code'] = $newDest[$key]['code']; }
        $x['will_be'] = $createMissing ? 'created (needs_completion: photo, description, coordinates)' : 'free text on the day (create_missing: true creates it)';
        $toCreate[] = $x;
    }
    foreach ($newLodge as $nl) {
        $x = ['kind' => 'lodge', 'name' => $nl['name'], 'destination' => $nl['destination'], 'lodge_type' => $nl['lodge_type'], 'category' => $nl['category'], 'days' => $nl['days']];
        $st = $db->prepare('SELECT id, name, is_active FROM iti_lodges WHERE name = ? OR name LIKE ? LIMIT 1');
        $st->execute([$nl['name'], '%' . $nl['name'] . '%']);
        if ($ex = $st->fetch(PDO::FETCH_ASSOC)) $x['conflict'] = 'exists as #' . $ex['id'] . ' ' . $ex['name'] . ' (other country or inactive) — pass end_lodge_id';
        $x['will_be'] = $createMissing ? 'created (needs_completion: photos, description, contacts)' : 'free text on the day (create_missing: true creates it)';
        $toCreate[] = $x;
    }
    $conflicts = array_values(array_filter($toCreate, function ($x) { return isset($x['conflict']); }));
    if ($conflicts && $createMissing) foreach ($conflicts as $c) $warnings[] = ucfirst($c['kind']) . ' "' . $c['name'] . '": ' . $c['conflict'];

    // 4. Included / not included: the lists given + the country's standard items (unless "standard": false).
    $std = (!array_key_exists('standard', $in) || !empty($in['standard'])) ? iti_pb_standard_inclusions($db, $country) : ['included' => [], 'excluded' => []];
    $incIn = [];
    foreach (['included', 'excluded'] as $k) {
        $list = isset($in[$k]) && is_array($in[$k]) ? array_values($in[$k]) : [];
        $have = [];
        foreach ($list as $x) $have[mb_strtolower(trim(is_array($x) ? (string)($x['text_' . $lang] ?? '') : (string)$x))] = true;
        foreach ($std[$k] as $s) if (!isset($have[mb_strtolower(trim((string)$s['text_' . $lang]))])) $list[] = ['std_id' => $s['std_id']];
        if (!$list) throw new InvalidArgumentException($k . '[] is required (no standard ' . $k . ' items for ' . $country . ')');
        $incIn[$k] = $list;
    }
    $incl = iti_pb_inclusion_rows($db, $incIn, $lang);
    $inclText = [];
    foreach ($incl as $type => $rows) foreach ($rows as $r) $inclText[$type][] = $r['text_' . $lang];
    if (!in_array('TZ', $countries, true)) {
        foreach ($inclText as $type => $texts) foreach ($texts as $t) {
            if (preg_match('/\bNIC\b|Tanzani|Tansani|Jambo Tanzania/iu', $t)) $warnings[] = 'Tanzania item on a ' . $country . ' programme (' . ($type === 'inclusion' ? 'included' : 'excluded') . '): "' . mb_substr($t, 0, 80) . '"';
        }
    }
    if (in_array('KE', $countries, true) && !preg_grep('/\beTA\b/u', $inclText['exclusion'] ?? [])) $warnings[] = 'Kenya programme without the eTA / health insurance in "not included"';
    if ($lang !== 'en') foreach ($inclText as $texts) foreach ($texts as $t) if (iti_im_looks_english($t)) $warnings[] = 'Included / not included looks English: "' . mb_substr($t, 0, 60) . '"';

    // 5. Photos and texts the documents will miss (matched rows; new rows miss everything).
    $photoGaps = []; $textGaps = [];
    $lodgeIds = []; $destIds = [];
    foreach ($items as $x) {
        if ($x['lodge'] && $x['lodge']['state'] === 'matched') $lodgeIds[$x['lodge']['id']] = true;
        if ($x['dest'] && $x['dest']['state'] === 'matched') $destIds[$x['dest']['id']] = true;
    }
    foreach (array_keys($lodgeIds) as $id) {
        $r = iti_cs_lodge_row($db, $id);
        if (!iti_photos_decode($r['photos'] ?? null)) $photoGaps[] = ['type' => 'lodge', 'id' => $id, 'name' => $r['name']];
        $t = trim((string)($r['description_' . $lang] ?? ''));
        if ($t === '' || iti_is_placeholder_text($t)) $textGaps[] = ['type' => 'lodge', 'id' => $id, 'name' => $r['name'], 'field' => 'description_' . $lang];
    }
    foreach (array_keys($destIds) as $id) {
        $r = iti_cs_destination_row($db, $id);
        if (!iti_dest_photos($r)) $photoGaps[] = ['type' => 'destination', 'id' => $id, 'name' => $r['name_en']];
        foreach (['name', 'description'] as $k) {
            $t = trim((string)($r[$k . '_' . $lang] ?? ''));
            if ($t === '' || iti_is_placeholder_text($t)) $textGaps[] = ['type' => 'destination', 'id' => $id, 'name' => $r['name_en'], 'field' => $k . '_' . $lang];
        }
    }
    if ($createMissing) foreach ($toCreate as $x) if (!isset($x['conflict'])) {
        $photoGaps[] = ['type' => $x['kind'], 'id' => null, 'name' => $x['name'], 'new' => true];
        $textGaps[] = ['type' => $x['kind'], 'id' => null, 'name' => $x['name'], 'new' => true, 'field' => 'description_' . $lang];
    }

    $plan = ['country' => $country, 'language' => $lang, 'title' => $set['title_' . $lang], 'ref_number' => $ref['ref_number'],
             'ref_source' => $ref['source'] !== null ? $ref['source'] . (isset($ref['file']) ? ': ' . $ref['file'] : '') : null,
             'safari_type' => $set['safari_type'],
             'terms_variant' => $tv !== 'auto' ? $tv : (isset($set['lead_request_id']) ? iti_terms_variant_for_request($db, $set['lead_request_id']) : null),
             'source' => ['supplier' => $set['source_supplier'], 'file' => $set['source_file']],
             'days' => $table, 'matches' => array_values($matches), 'to_create' => $toCreate,
             'included' => $inclText['inclusion'] ?? [], 'excluded' => $inclText['exclusion'] ?? [],
             'warnings' => $warnings, 'photo_gaps' => $photoGaps, 'text_gaps' => $textGaps];
    if (!$go) return $plan;
    if ($conflicts && $createMissing) throw new InvalidArgumentException('Names that already exist elsewhere — pass their ids (destination_id / end_lodge_id) on those days: '
                                                                          . implode('; ', array_map(function ($c) { return $c['name'] . ' ' . $c['conflict']; }, $conflicts)));

    // 6. Everything in one transaction: stubs, programme, days, lists.
    $db->beginTransaction();
    try {
        $destNew = []; $lodgeNew = [];
        if ($createMissing) {
            foreach ($newDest as $key => $nd) {
                $r = iti_im_create_destination($db, $nd['fields'] + ['code' => $nd['code']], true, true);
                $destNew[$key] = (int)$r['created']['id'];
            }
            foreach ($newLodge as $key => $nl) {
                $did = $nl['dest_key'] !== null ? $destNew[$nl['dest_key']] : (int)$nl['destination_id'];
                $r = iti_cs_create_lodge($db, ['name' => $nl['name'], 'destination_id' => $did, 'category' => $nl['category'], 'lodge_type' => $nl['lodge_type']], true);
                $lodgeNew[$key] = (int)$r['created']['id'];
                $db->prepare('UPDATE iti_lodges SET needs_completion = 1 WHERE id = ?')->execute([$lodgeNew[$key]]);
            }
        }
        $dayItems = [];
        foreach ($items as $x) {
            $it = $x['item'];
            if ($x['dest']) {
                if ($x['dest']['state'] === 'matched')  $it['cols']['destination_id'] = $x['dest']['id'];
                elseif ($x['dest']['state'] === 'new')  $it['cols']['destination_id'] = $destNew[$x['dest']['key']];
                else                                    $it['cols']['destination_custom'] = $x['dest']['name'];
            }
            if ($x['lodge']) {
                if ($x['lodge']['state'] === 'matched') $it['cols']['end_lodge_id'] = $x['lodge']['id'];
                elseif ($x['lodge']['state'] === 'new') $it['cols']['end_lodge_id'] = $lodgeNew[$x['lodge']['key']];
                else                                    $it['cols']['end_lodge_custom'] = $x['lodge']['name'];
            }
            $dayItems[] = $it;
        }
        $id = iti_ps_insert_blank($db, $set, $dayItems, $who);
        iti_pb_inclusions_write($db, $id, $incl);
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }

    if ($tv !== 'auto' || !empty($set['lead_request_id'])) iti_terms_apply($db, $id, $tv !== 'auto' ? $tv : null);
    if (!empty($set['lead_request_id'])) {
        require_once __DIR__ . '/../../leads/includes/timeline_service.php';
        timeline_log((int)$set['lead_request_id'], 'program_update', 'Personal program imported (#' . $id . ')',
                     ['body' => count($dayItems) . ' days, ' . $country . ($supplier !== '' ? ', from a partner programme' : ''), 'refs' => ['program_id' => $id]]);
    }
    $out = iti_ps_program_out($db, $id, $lang);
    return $plan + ['program_id' => $id, 'created' => ['destinations' => array_values($destNew), 'lodges' => array_values($lodgeNew)],
                    'links' => $out['links'], 'program' => $out];
}
