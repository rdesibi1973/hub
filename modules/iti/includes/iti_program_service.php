<?php
/**
 * iti_program_service.php — personal programmes for the Agent API (Cowork):
 * create from a sample (proposal), generate the final programme from the
 * booking's Calc, edit header / days, map unknown Calc texts, publish and
 * return the client link (magazine layout).
 *
 * Writes are plans unless $go ("confirm": true in the API). Only personal
 * programmes are changed here; samples stay on the Hub pages.
 * Needs iti_functions.php (+ iti_final.php and the leads Dropbox / Calc
 * services for the Calc actions) — see agent_iti_lib() in agent_api.php.
 */
require_once __DIR__ . '/iti_functions.php';
require_once __DIR__ . '/iti_doc.php';
require_once __DIR__ . '/iti_program_build.php';   // days, inclusions, master data, save as sample

const ITI_PS_LANGS = ['en', 'it', 'fr', 'es', 'de'];

/** ref_number cannot be derived from the request folder: carries the candidates for the API reply (409). */
class ItiRefException extends InvalidArgumentException {
    public $data = [];
    public function __construct(string $msg, array $data = []) { parent::__construct($msg); $this->data = $data; }
}

/** Max length of iti_programs.ref_number (read once from the column, 60 if unknown). */
function iti_ps_ref_max(): int {
    static $max = null;
    if ($max === null) {
        $max = 60;
        try {
            $c = db()->query("SHOW COLUMNS FROM iti_programs LIKE 'ref_number'")->fetch(PDO::FETCH_ASSOC);
            if ($c && preg_match('/\((\d+)\)/', (string)$c['Type'], $m)) $max = (int)$m[1];
        } catch (Throwable $e) {}
    }
    return $max;
}

/**
 * Validate a ref_number (it is also the name of the programme files in the booking folder):
 * trimmed, '' → null, no \ / : * ? " < > |, at most the column length.
 */
function iti_ps_ref_check($v): ?string {
    if ($v === null) return null;
    $v = trim((string)$v);
    if ($v === '') return null;
    if (preg_match('#[\\\\/:*?"<>|]#', $v)) throw new InvalidArgumentException('ref_number: \\ / : * ? " < > | are not allowed (it becomes a file name)');
    if (mb_strlen($v) > iti_ps_ref_max()) throw new InvalidArgumentException('ref_number: max ' . iti_ps_ref_max() . ' characters (' . mb_strlen($v) . ' given)');
    return $v;
}

/**
 * ref_number of a client programme = the name of the programme Word copy_program put in the
 * request folder, without extension ("01_MarcoCiaolo(OceanoPoint-Roberto)_PumbaSafari").
 * Exactly one copied Word → its name; with $calcFile → the Word of that Calc (same NN_ and
 * programme). None / several → ItiRefException listing the candidates: never guessed.
 * Returns ['ref_number', 'source' => 'word'|'calc_file', 'file'].
 */
function iti_ps_ref_from_request(PDO $db, int $rid, string $calcFile = ''): array {
    require_once __DIR__ . '/../../leads/includes/booking_service.php';   // bs_copied_programs, req_folder_path
    require_once __DIR__ . '/../../leads/dropbox_helper.php';
    $st = $db->prepare('SELECT id, practice_code, group_folder, dropbox_url FROM requests WHERE id = ?');
    $st->execute([$rid]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    $folder = $r ? trim((string)$r['practice_code']) : '';
    $hint = 'Pass fields.ref_number, or calc_file (the Calc of the programme: same NN_ and programme as its Word).';
    if ($folder === '') throw new ItiRefException('ref_number: request ' . $rid . ' has no folder — ' . $hint, ['request_id' => $rid, 'hint' => $hint]);
    $dir = req_folder_path($r);
    try { $files = dropbox_list_files(dropbox_get_access_token(), $dir); }
    catch (Throwable $e) { throw new ItiRefException('ref_number: cannot read ' . $dir . ' (' . $e->getMessage() . ') — ' . $hint, ['request_id' => $rid, 'folder' => $dir, 'hint' => $hint]); }

    $progs = bs_copied_programs($files, $folder);
    $words = []; $calcs = [];
    foreach ($progs as $pr) if ($pr['word_present']) $words[] = $pr['word'];
    foreach ($files as $fn) if (preg_match('/_calc\.xlsx$/i', $fn)) $calcs[] = $fn;
    $data = ['request_id' => $rid, 'folder' => $dir, 'word_candidates' => $words, 'calc_files' => $calcs, 'hint' => $hint];

    $calcFile = basename(trim($calcFile));
    if ($calcFile !== '') {
        $pick = null;
        foreach ($progs as $pr) if ($pr['calc'] !== null && strcasecmp($pr['calc'], $calcFile) === 0) $pick = $pr;
        if (!$pick || $pick['word'] === null) throw new ItiRefException('ref_number: calc_file "' . $calcFile . '" is not a programme Calc copied by copy_program in this folder', $data);
        $file = $pick['word']; $source = 'calc_file';
    } elseif (count($words) === 1) {
        $file = $words[0]; $source = 'word';
    } else {
        throw new ItiRefException('ref_number: ' . (count($words) ? count($words) . ' programme Words' : 'no programme Word')
                                  . ' copied by copy_program in ' . $dir . ' — ' . $hint, $data);
    }
    $ref = preg_replace('/\.docx?$/i', '', $file);
    try { $ref = iti_ps_ref_check($ref); }
    catch (InvalidArgumentException $e) { throw new ItiRefException($e->getMessage() . ' — derived from ' . $file . '. ' . $hint, $data); }
    return ['ref_number' => $ref, 'source' => $source, 'file' => $file];
}

/**
 * File name of a programme document saved by the API: <ref_number>.docx / .pdf, guide sheet
 * <ref_number>_Guida.pdf. Null when the programme has no ref_number.
 */
function iti_ps_document_name(array $p, string $format): ?string {
    $ref = trim((string)($p['ref_number'] ?? ''));
    if ($ref === '') return null;
    $format = strtolower(trim($format));
    if ($format === 'guide') return $ref . '_Guida.pdf';
    return $ref . '.' . ($format === 'docx' || $format === 'word' ? 'docx' : 'pdf');
}

/** Public / internal links of a programme ($lang = display language). */
function iti_ps_links(array $p, string $lang = ''): array {
    $lang = $lang !== '' ? $lang : (string)($p['display_language'] ?? 'it');
    $base = ITI_MODULE_URL;
    $out = [
        'preview' => $base . '/program_doc.php?id=' . (int)$p['id'] . '&lang=' . $lang,
        'edit'    => $base . '/program_edit.php?id=' . (int)$p['id'],
        'word'    => $base . '/export_mag_word.php?id=' . (int)$p['id'] . '&lang=' . $lang,
        'pdf'     => $base . '/export_mag_word.php?id=' . (int)$p['id'] . '&lang=' . $lang . '&format=pdf',
        'guide'   => ($p['program_type'] ?? '') === 'personal' ? $base . '/export_mag_word.php?id=' . (int)$p['id'] . '&lang=' . $lang . '&format=guide' : null,
        'public'  => null,
    ];
    if (($pub = iti_public_url($p)) !== '') $out['public'] = $pub . '&lang=' . $lang;   // personal: always (token on first use)
    return $out;
}

/** Compact programme for the API: header, links and the days as the client sees them. */
function iti_ps_program_out(PDO $db, int $id, string $lang = ''): array {
    $p = iti_get_program($id);
    if (!$p) throw new InvalidArgumentException('Program ' . $id . ' not found');
    $lang = in_array($lang, ITI_PS_LANGS, true) ? $lang : (in_array($p['display_language'] ?? '', ITI_PS_LANGS, true) ? $p['display_language'] : 'it');
    $D = iti_doc_data($id, $lang);
    $days = [];
    foreach ($D['days'] as $d) {
        $days[] = ['day' => $d['n'], 'date' => $d['date'] ?? null, 'title' => $d['title'], 'destination' => $d['dest'], 'lodge' => $d['lodge'], 'room' => $d['room'],
                   'meals' => $d['meals'], 'flights' => $d['flights'] ?? [], 'transfers' => $d['transfers'], 'activities' => $d['activities'],
                   'narrative' => $d['narrative'], 'lodge_photos' => count($d['lodge_photos']), 'dest_photo' => $d['dest_photo'] !== ''];
    }
    return [
        'id' => (int)$p['id'], 'type' => $p['program_type'], 'stage' => $p['stage'] ?? null, 'status' => $p['status'],
        'ref_number' => isset($p['ref_number']) && $p['ref_number'] !== '' ? $p['ref_number'] : null,
        'sample_id' => $p['sample_program_id'] ? (int)$p['sample_program_id'] : null,
        'lead_request_id' => isset($p['lead_request_id']) && $p['lead_request_id'] ? (int)$p['lead_request_id'] : null,
        'title' => $D['title'], 'subtitle' => $D['subtitle'], 'intro' => $D['intro'], 'language' => $lang,
        'currency' => $p['display_currency'] ?? null, 'start_date' => $p['start_date'] ?? null,
        'pax_adults' => (int)($p['pax_adults'] ?? 0), 'pax_teens' => (int)($p['pax_teens'] ?? 0), 'pax_children' => (int)($p['pax_children'] ?? 0),
        'duration' => $D['duration'], 'published' => iti_program_is_public($p),   // personal: always, until cancelled
        'prices' => $D['prices'], 'price_notes' => $D['price_notes'],
        'included' => $D['incl'], 'excluded' => $D['excl'], 'terms' => iti_ps_terms_out($db, $p), 'days' => $days,
        'structure' => iti_pb_days_raw($db, $id),   // the days as stored (ids), same shape iti_set_days takes
        'links' => iti_ps_links($p, $lang),
    ];
}

/** Samples Cowork can start from: id, Calc code, titles, days, route. */
function iti_ps_samples(PDO $db, string $q = ''): array {
    $sql = "SELECT id, hub_program_code, title_it, title_en, subtitle_it, subtitle_en, display_language, duration_days
              FROM iti_programs WHERE program_type = 'sample' AND status <> 'cancelled'";
    $args = [];
    if ($q !== '') { $sql .= ' AND (title_it LIKE ? OR title_en LIKE ? OR hub_program_code LIKE ?)'; $l = "%$q%"; array_push($args, $l, $l, $l); }
    $st = $db->prepare($sql . ' ORDER BY title_it, title_en');
    $st->execute($args);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[] = ['id' => (int)$r['id'], 'code' => $r['hub_program_code'], 'title' => $r['title_it'] ?: $r['title_en'],
                  'route' => $r['subtitle_it'] ?: $r['subtitle_en'], 'language' => $r['display_language'], 'days' => (int)$r['duration_days']];
    }
    return $out;
}

/** Price table + notes of the programme document and the room type of each night (read by iti_doc_data()), added once if missing. */
function iti_ps_schema(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        iti_add_column('iti_programs', 'price_table_json', 'TEXT NULL DEFAULT NULL');
        foreach (ITI_PS_LANGS as $l) iti_add_column('iti_programs', 'price_notes_' . $l, 'TEXT NULL DEFAULT NULL');
        iti_ensure_doc_columns();   // pax_teens, room_type
    } catch (PDOException $e) {
        error_log('iti_ps_schema: ' . $e->getMessage());
    }
}

/** Header fields an agent may set, limited to the columns the live table really has. */
function iti_ps_header_fields(): array {
    iti_ps_schema();
    $f = ['ref_number', 'display_language', 'display_currency', 'start_date', 'pax_adults', 'pax_teens', 'pax_children', 'price_table_json', 'price_notes'];
    foreach (ITI_PS_LANGS as $l) foreach (['title', 'subtitle', 'intro', 'price_notes'] as $k) $f[] = $k . '_' . $l;
    return array_values(array_intersect($f, iti_table_columns('iti_programs')));
}

/** Normalise / validate header values; returns [column => value]. */
function iti_ps_header_values(array $fields): array {
    unset($fields['terms_variant']);   // handled by iti_ps_update_program()
    $allowed = iti_ps_header_fields();
    $bad = array_values(array_diff(array_keys($fields), $allowed));
    if ($bad) throw new InvalidArgumentException('Not editable: ' . implode(', ', $bad) . ' — allowed: ' . implode(', ', $allowed));
    $out = [];
    foreach ($fields as $k => $v) {
        if ($k === 'display_language' && !in_array($v, ITI_PS_LANGS, true)) throw new InvalidArgumentException('display_language: en|it|fr|es|de');
        if ($k === 'display_currency' && !in_array($v, ['USD', 'EUR'], true)) throw new InvalidArgumentException('display_currency: USD|EUR');
        if ($k === 'start_date') {
            if ($v !== null && $v !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$v)) throw new InvalidArgumentException('start_date: YYYY-MM-DD');
            $v = $v === '' ? null : $v;
        }
        if ($k === 'ref_number') { $out[$k] = iti_ps_ref_check($v); continue; }
        if ($k === 'pax_adults' || $k === 'pax_teens' || $k === 'pax_children') $v = max(0, min(60, (int)$v));
        if ($k === 'price_table_json') {
            // [{"label": "2 partecipanti", "price": 2905, "currency": "USD"}, …]
            if (is_string($v)) $v = json_decode($v, true);
            if ($v !== null && !is_array($v)) throw new InvalidArgumentException('price_table_json: list of {label, price, currency}');
            $v = $v ? json_encode(array_values($v), JSON_UNESCAPED_UNICODE) : null;
        }
        if (is_string($v)) $v = trim($v);
        $out[$k] = $v;
    }
    return $out;
}

/** A personal programme row, or an error (samples are not changed through the API). */
function iti_ps_personal(int $id): array {
    $p = iti_get_program($id);
    if (!$p) throw new InvalidArgumentException('Program ' . $id . ' not found');
    if ($p['program_type'] !== 'personal') throw new InvalidArgumentException('Program ' . $id . ' is a sample — duplicate it with iti_create_personal first');
    return $p;
}

/**
 * Proposal for a client: a copy of a sample, or a blank programme (no sample_id) for trips with
 * no matching sample. $in: sample_id?, lead_request_id?, calc_file?, fields? {ref_number, title_<lang>, subtitle_<lang>,
 * intro_<lang>, start_date, pax_adults, pax_teens (under 16), pax_children (under 12), display_language, display_currency,
 * price_table_json, price_notes_<lang>}, days? [day items, see iti_program_build.php] (replace
 * the sample's days). Blank programme: title_<display_language> and days[] are required.
 */
function iti_ps_create_personal(PDO $db, array $in, string $who, bool $go): array {
    iti_ps_schema();          // before anything caches the column list
    iti_ensure_final_schema();
    $sid = (int)($in['sample_id'] ?? 0);
    $s = $sid ? iti_get_program($sid) : null;
    if ($sid && (!$s || $s['program_type'] !== 'sample')) throw new InvalidArgumentException('sample_id: not a sample program (see iti_samples)');
    $tv = isset($in['fields']['terms_variant']) ? strtolower(trim((string)$in['fields']['terms_variant'])) : 'auto';
    if (!in_array($tv, ['direct', 'agency', 'auto'], true)) throw new InvalidArgumentException('terms_variant: direct | agency | auto');
    $set = iti_ps_header_values(isset($in['fields']) && is_array($in['fields']) ? $in['fields'] : []);
    $set['stage'] = 'proposal';
    if (!empty($in['lead_request_id'])) {
        $st = $db->prepare('SELECT id, customer_name FROM requests WHERE id = ?');
        $st->execute([(int)$in['lead_request_id']]);
        $req = $st->fetch(PDO::FETCH_ASSOC);
        if (!$req) throw new InvalidArgumentException('lead_request_id ' . (int)$in['lead_request_id'] . ' not found');
        $set['lead_request_id'] = (int)$req['id'];
    }
    // Ref. number = the programme Word copy_program put in the request folder (fields.ref_number wins),
    // never the sample's. Not derivable → ItiRefException (409 with the candidates).
    $ref = ['ref_number' => $set['ref_number'] ?? null, 'source' => isset($set['ref_number']) ? 'fields' : null];
    if ($ref['ref_number'] === null && isset($set['lead_request_id'])) {
        $ref = iti_ps_ref_from_request($db, $set['lead_request_id'], (string)($in['calc_file'] ?? ''));
    }
    $set['ref_number'] = $ref['ref_number'];
    $days = isset($in['days']) && is_array($in['days']) ? array_values($in['days']) : null;
    $items = [];
    foreach ((array)$days as $i => $d) {   // validate now, so a dry run reports the errors
        try { $items[] = iti_pb_day_item($db, (array)$d); }
        catch (InvalidArgumentException $e) { throw new InvalidArgumentException('days[' . $i . '] (day ' . ($i + 1) . '): ' . $e->getMessage()); }
    }
    if ($s) {
        $lang = $set['display_language'] ?? $s['display_language'];
        // A copy keeps the sample's title unless one is given ("(copy)" is for the Hub button only).
        foreach (ITI_PS_LANGS as $l) if (!array_key_exists('title_' . $l, $set)) $set['title_' . $l] = $s['title_' . $l];
        $plan = ['sample' => ['id' => $sid, 'title' => iti_doc_pick($s, 'title', $lang), 'days' => (int)$s['duration_days']], 'set' => $set];
    } else {
        $lang = $set['display_language'] ?? 'it';
        $set['display_language'] = $lang;
        if (trim((string)($set['title_' . $lang] ?? '')) === '') throw new InvalidArgumentException('Blank program: fields.title_' . $lang . ' is required');
        if (!$items) throw new InvalidArgumentException('Blank program: days[] is required');
        foreach (ITI_PS_LANGS as $l) if (!isset($set['title_' . $l]) || $set['title_' . $l] === '') $set['title_' . $l] = $set['title_' . $lang];   // NOT NULL columns
        $plan = ['sample' => null, 'set' => $set];
    }
    if ($days !== null) $plan['days'] = count($items);
    $plan['ref_number'] = $ref['ref_number'];
    $plan['ref_source'] = $ref['source'] !== null ? $ref['source'] . (isset($ref['file']) ? ': ' . $ref['file'] : '') : null;
    // T&C of the linked request's channel: direct client (Drct) 60 days, agency 45 days.
    $plan['terms_variant'] = $tv !== 'auto' ? $tv : (isset($set['lead_request_id']) ? iti_terms_variant_for_request($db, $set['lead_request_id']) : null);
    if (!$go) return $plan;

    try {
        if ($s) {                                            // iti_duplicate_program / iti_pb_set_days: own transactions
            $id = iti_duplicate_program($sid, 'personal', $who, $set);
            if ($days !== null) iti_pb_set_days($db, $id, $days, true);
        } else {
            $db->beginTransaction();
            $row = $set + ['program_type' => 'personal', 'status' => 'draft', 'created_by' => $who, 'duration_days' => count($items),
                           'is_published' => 0, 'brand' => 'savannah_explorers'];
            $row = array_intersect_key($row, array_flip(iti_table_columns('iti_programs')));
            $db->prepare('INSERT INTO iti_programs (`' . implode('`,`', array_keys($row)) . '`) VALUES (' . implode(',', array_fill(0, count($row), '?')) . ')')
               ->execute(array_values($row));
            $id = (int)$db->lastInsertId();
            foreach ($items as $i => $it) {
                $db->prepare('INSERT INTO iti_program_days (program_id, day_number) VALUES (?, ?)')->execute([$id, $i + 1]);
                iti_pb_day_write($db, (int)$db->lastInsertId(), $it);
            }
            iti_pb_renumber($db, $id);
            $db->commit();
        }
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    if ($tv !== 'auto' || !empty($set['lead_request_id'])) iti_terms_apply($db, $id, $tv !== 'auto' ? $tv : null);
    if (!empty($set['lead_request_id'])) {
        require_once __DIR__ . '/../../leads/includes/timeline_service.php';
        timeline_log((int)$set['lead_request_id'], 'program_update', 'Personal program created (#' . $id . ')',
                     ['body' => $s ? 'From sample #' . $sid . ': ' . iti_doc_pick($s, 'title', $lang) : 'Built day by day (' . count($items) . ' days)',
                      'refs' => ['program_id' => $id]]);
    }
    $out = iti_ps_program_out($db, $id, $lang);
    // program_id + links at the top too: callers looked for them there and found null.
    return $plan + ['program_id' => $id, 'links' => $out['links'], 'terms' => $out['terms'], 'program' => $out];
}

/**
 * Change header fields of a personal programme; returns the diff. Besides the columns,
 * fields.terms_variant = direct | agency | auto (auto = from the linked request) picks the
 * standard T&C version (not with a dedicated T&C override).
 */
function iti_ps_update_program(PDO $db, int $id, array $fields, bool $go): array {
    $p = iti_ps_personal($id);
    $variant = null;
    if (array_key_exists('terms_variant', $fields)) {
        $variant = strtolower(trim((string)$fields['terms_variant']));
        if (!in_array($variant, ['direct', 'agency', 'auto'], true)) throw new InvalidArgumentException('terms_variant: direct | agency | auto');
        unset($fields['terms_variant']);
    }
    $vals = iti_ps_header_values($fields);
    $changes = [];
    foreach ($vals as $k => $v) {
        $cur = $p[$k] ?? null;
        if ((string)$cur !== (string)$v) $changes[$k] = ['from' => $cur, 'to' => $v];
    }
    if ($variant !== null) {
        if (iti_terms_has_override($db, $id)) throw new InvalidArgumentException('Program ' . $id . ' has its own dedicated T&C (Hub editor → T&C): remove it there first');
        $want = $variant === 'auto' ? iti_terms_variant_for_request($db, (int)($p['lead_request_id'] ?? 0)) : $variant;
        if ($want === null) throw new InvalidArgumentException('terms_variant auto: the program has no linked request with a folder — use direct or agency');
        $tr = iti_terms_row_for_variant($db, $want);
        if (!$tr) throw new InvalidArgumentException('No active standard T&C named for "' . $want . '" (Hub → ITI Settings → T&C)');
        if ((int)$p['terms_id'] !== (int)$tr['id']) {
            $from = iti_terms_resolve($db, $p);
            $changes['terms_id'] = ['from' => $p['terms_id'] !== null ? (int)$p['terms_id'] : null, 'to' => (int)$tr['id'],
                                    'variant_from' => $from['variant'], 'variant_to' => $want, 'name' => $tr['name']];
        }
    }
    if ($go && $changes) {
        $sets = []; $args = [];
        foreach ($changes as $k => $c) { $sets[] = '`' . $k . '` = ?'; $args[] = $c['to']; }
        $args[] = $id;
        $db->prepare('UPDATE iti_programs SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($args);
    }
    return ['id' => $id, 'changes' => $changes];
}

/** Which T&C a programme prints: variant (direct | agency | custom), version id / name, and why (program | request | default). */
function iti_ps_terms_out(PDO $db, array $p): array {
    $r = iti_terms_resolve($db, $p);
    return ['variant' => $r['variant'], 'terms_id' => $r['row'] ? (int)$r['row']['id'] : null,
            'name' => $r['row']['name'] ?? null, 'source' => $r['source'],
            'request_variant' => iti_terms_variant_for_request($db, (int)($p['lead_request_id'] ?? 0))];
}

/**
 * Change one day of a personal programme: any day item key (see iti_program_build.php) —
 * texts, lodge, destination, meals, transfer route, and the transfers / activities / flights
 * lists (each one given replaces the day's list). Returns the column diff and the lists replaced.
 */
function iti_ps_update_day(PDO $db, int $pid, int $dayNo, array $fields, bool $go): array {
    iti_ps_personal($pid);
    $st = $db->prepare('SELECT * FROM iti_program_days WHERE program_id = ? AND day_number = ?');
    $st->execute([$pid, $dayNo]);
    $day = $st->fetch(PDO::FETCH_ASSOC);
    if (!$day) throw new InvalidArgumentException('Day ' . $dayNo . ' not found in program ' . $pid);
    $item = iti_pb_day_item($db, $fields);
    $changes = [];
    foreach ($item['cols'] as $k => $v) {
        if ((string)($day[$k] ?? '') !== (string)$v) $changes[$k] = ['from' => $day[$k] ?? null, 'to' => $v];
    }
    $item['cols'] = array_intersect_key($item['cols'], $changes);
    $lists = [];
    foreach (['transfers', 'activities', 'flights'] as $k) if ($item[$k] !== null) $lists[$k] = count($item[$k]);
    if ($go && ($item['cols'] || $lists)) {
        $db->beginTransaction();
        try { iti_pb_day_write($db, (int)$day['id'], $item); $db->commit(); }
        catch (Throwable $e) { $db->rollBack(); throw $e; }
    }
    return ['program_id' => $pid, 'day' => $dayNo, 'changes' => $changes, 'lists_replaced' => $lists];
}

/** Publish (token + public link) or unpublish a personal programme. */
function iti_ps_publish(PDO $db, int $id, bool $publish, bool $go): array {
    $p = iti_ps_personal($id);
    if ($go) {
        if ($publish) $db->prepare("UPDATE iti_programs SET is_published = 1, public_token = COALESCE(public_token, UUID()), published_at = NOW(),
                                     status = IF(status = 'draft', 'sent', status) WHERE id = ?")->execute([$id]);
        else          $db->prepare('UPDATE iti_programs SET is_published = 0 WHERE id = ?')->execute([$id]);
        $p = iti_get_program($id);
        if (!empty($p['lead_request_id'])) {
            require_once __DIR__ . '/../../leads/includes/timeline_service.php';
            timeline_log((int)$p['lead_request_id'], 'program_update', ($publish ? 'Program published' : 'Program unpublished') . ' (#' . $id . ')',
                         ['refs' => ['program_id' => $id]]);
        }
    }
    return ['id' => $id, 'published' => $go ? (bool)$p['is_published'] : $publish, 'links' => iti_ps_links($p)];
}

/** Calc of a Hub request → plan (sample, nights, what is not mapped yet). */
function iti_ps_calc_plan(PDO $db, int $rid, array $in): array {
    iti_ensure_final_schema();
    $calc = calc_read_request($db, $rid, (string)($in['file'] ?? ''), (string)($in['sheet'] ?? ''));
    $lang = in_array($in['lang'] ?? '', ITI_PS_LANGS, true) ? $in['lang'] : 'it';
    $code = iti_final_code_from_calc($calc['file']);
    $sample = !empty($in['sample_id']) ? iti_get_program((int)$in['sample_id']) : iti_final_find_sample($db, $code, $lang);
    if ($sample && $sample['program_type'] !== 'sample') throw new InvalidArgumentException('sample_id is not a sample');
    $plan = iti_final_plan($db, $calc, $sample ? (int)$sample['id'] : 0);
    $nights = [];
    foreach ($plan['days'] as $d) {
        $nights[] = ['date' => $d['date'] ?? null, 'label' => $d['label'] ?? '', 'hotel' => $d['hotel_text'] ?? '',
                     'lodge' => $d['lodge_name'] ?? ($d['lodge_custom'] ?? null), 'lodge_state' => $d['lodge_state'] ?? null,
                     'meal' => $d['meal'] ?? null,
                     'activities' => array_map(function ($a) { return $a['text'] . ' [' . $a['state'] . ']'; }, $d['acts'] ?? []),
                     'from_sample_day' => $d['sample_index'] !== null ? $d['sample_index'] + 1 : null, 'flags' => $d['flags'] ?? []];
    }
    return ['calc' => ['file' => $calc['file'], 'files' => $calc['candidates'] ?? [], 'rev' => $calc['calc_rev'] ?? null, 'pax' => $calc['pax'] ?? null],
            'code' => $code, 'sample' => $sample ? ['id' => (int)$sample['id'], 'title' => iti_doc_pick($sample, 'title', $lang)] : null,
            'nights' => $nights, 'unmapped' => $plan['unmapped'], 'blocking' => $plan['blocking'], 'flags' => $plan['flags'],
            'existing_finals' => array_map(function ($f) { return ['id' => (int)$f['id'], 'calc_file' => $f['source_calc_path'] ? basename($f['source_calc_path']) : null,
                                                                   'superseded_by' => $f['superseded_by'] ? (int)$f['superseded_by'] : null]; }, iti_final_list($db, $rid)),
            '_calc' => $calc, '_sample_id' => $sample ? (int)$sample['id'] : 0, '_lang' => $lang];
}

/** Generate the final programme (replaces an older final built from the same Calc file). */
function iti_ps_calc_generate(PDO $db, int $rid, array $plan, array $who): array {
    if (!$plan['_sample_id']) throw new InvalidArgumentException('No sample for Calc code "' . $plan['code'] . '" — pass sample_id');
    if ($plan['blocking']) throw new InvalidArgumentException('Map these Calc texts first (iti_save_alias): ' . implode(' ', $plan['blocking']));
    $res = iti_final_generate($db, $rid, $plan['_calc'], $plan['_sample_id'], $plan['_lang'], $who);
    return ['program_id' => (int)$res['program_id'], 'superseded' => $res['superseded'],
            'program' => iti_ps_program_out($db, (int)$res['program_id'], $plan['_lang'])];
}

/** Map a Calc text to master data (lodge / activity / transfer / flight route). */
function iti_ps_save_alias(array $in, string $who): array {
    $type = (string)($in['type'] ?? '');
    if (!in_array($type, ['lodge', 'activity', 'route'], true)) throw new InvalidArgumentException('type: lodge | activity | route');
    $text = trim((string)($in['text'] ?? ''));
    if ($text === '') throw new InvalidArgumentException('text is required (the Calc text, as in unmapped)');
    $ids = ['lodge_id' => (int)($in['lodge_id'] ?? 0), 'activity_id' => (int)($in['activity_id'] ?? 0),
            'transfer_route_id' => (int)($in['transfer_route_id'] ?? 0), 'flight_route_id' => (int)($in['flight_route_id'] ?? 0)];
    $meal = isset($in['meal_basis']) && $in['meal_basis'] !== '' ? strtoupper((string)$in['meal_basis']) : null;
    if ($meal !== null && !in_array($meal, ['BB', 'HB', 'FB', 'AI'], true)) throw new InvalidArgumentException('meal_basis: BB|HB|FB|AI');
    iti_alias_save($type, $text, $ids, $meal, $who);
    return ['alias' => iti_alias_norm($text), 'type' => $type, 'ids' => array_filter($ids), 'meal_basis' => $meal];
}
