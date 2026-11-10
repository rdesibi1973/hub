<?php
/**
 * iti_sample_cleanup.php — one-off clean-up of the ITI samples (10 Nov 2026, Roberto), used by
 * sample_cleanup.php. Every section has a plan (dry run: what changes, before → after) and an apply.
 *
 *   shared    "shared arrival transfer" sentence out of every non-group sample (narratives, intro)
 *   titles    group samples 199 / 200 / 201: no client name / date in the titles, group wording in the intro
 *   nyumbu    new private sample NYUMBU SAFARI (copy of 199, private wording, Calc code Nyumbu)
 *   routes    transfer catalogue: durations of the sample texts, via-points, labels, missing routes
 *   link      sample day transfers: free text → catalogue route (+ flights), unmatched texts stay
 *
 * Needs iti_functions.php (iti_duplicate_program) and iti_transfer_service.php.
 */

const ITI_SC_GROUP_TITLES = [
    199 => ['en' => 'NYUMBU GROUP SAFARI', 'fr' => 'SAFARI NYUMBU EN GROUPE', 'es' => 'SAFARI NYUMBU EN GRUPO', 'de' => 'NYUMBU GRUPPENSAFARI'],
    200 => ['en' => 'SIMBA GROUP SAFARI',  'fr' => 'SAFARI SIMBA EN GROUPE',  'es' => 'SAFARI SIMBA EN GRUPO',  'de' => 'SIMBA GRUPPENSAFARI'],
    201 => ['en' => 'DUMA GROUP SAFARI',   'fr' => 'SAFARI DUMA EN GROUPE',   'es' => 'SAFARI DUMA EN GRUPO',   'de' => 'DUMA GRUPPENSAFARI'],
];
// Intro line "private safari" ↔ "group safari", per language (the samples' own wording).
const ITI_SC_INTRO_PRIVATE = ['it' => '- Safari privato con uso esclusivo della jeep.', 'en' => '- Private safari with exclusive use of the vehicle.', 'fr' => '- Safari privé avec usage exclusif du véhicule.'];
const ITI_SC_INTRO_GROUP   = ['it' => '- Safari di gruppo in Tanzania.', 'en' => '- Group safari in Tanzania.', 'fr' => '- Safari en groupe en Tanzanie.'];
const ITI_SC_PRIVATE_MODEL = 32;    // Simba: private wording (intro line, jeep inclusion) for the private Nyumbu
const ITI_SC_NYUMBU_GROUP  = 199;

/** Normalised transfer text: lower case, one kind of dash, no final dot, "transferimento" typo fixed. */
function iti_sc_norm(string $t): string {
    $t = mb_strtolower(trim($t));
    $t = str_replace(['–', '—', '’'], ['-', '-', "'"], $t);
    $t = (string)preg_replace('/\s*-\s*/u', ' - ', $t);
    $t = str_replace('transferimento', 'trasferimento', $t);
    $t = (string)preg_replace('/\s+/u', ' ', $t);
    return rtrim($t, ". ");
}

/**
 * Catalogue routes the samples need: [from, to] destination ids → duration (the sample texts win,
 * Roberto 10 Nov 2026), via, written labels, 'new' = to be created. 'ask' = a choice for Roberto.
 */
function iti_sc_route_specs(): array {
    $crater = ['it' => 'cratere del Ngorongoro', 'en' => 'Ngorongoro Crater', 'fr' => 'cratère du Ngorongoro', 'es' => 'cráter del Ngorongoro', 'de' => 'Ngorongoro-Krater'];
    $lab = function (string $from, int $min) use ($crater) {
        $o = [];
        foreach (ITI_TR_LANGS as $l) $o['label_' . $l] = $from . ' – ' . $crater[$l] . ', ' . iti_tr_duration_text($min, $l);
        return $o;
    };
    return [
        '30-17' => ['min' => 60],                                   // Kilimanjaro Airport – Arusha (catalogue 90)
        '17-15' => ['min' => 120],                                  // Arusha – Tarangire
        '52-30' => ['min' => 180, 'via' => 17],                     // Karatu – Arusha – Kilimanjaro (210)
        '52-14' => ['min' => 180, 'via' => 9],                      // Karatu – Ngorongoro – Serengeti (240)
        '14-9'  => [],                                              // Serengeti – Ngorongoro, game drive on the way (no time printed)
        '52-9'  => ['min' => 90] + $lab('Karatu', 90),              // Karatu – Ngorongoro Crater (catalogue 30, to the gate)
        '64-9'  => ['min' => 60, 'new' => true, 'ask' => '199 day 6 "Transfer al cratere circa 1 ora", the night before is at Ndutu'] + $lab('Ndutu', 60),
        '52-17' => ['min' => 120],                                  // Karatu – Arusha (150)
        '52-6'  => ['min' => 30, 'ask' => 'Karatu – Manyara: 30 min in 5 samples, 45 min in 17, 18, 33'],   // (90)
        '24-33' => ['min' => 60, 'new' => true,                     // Zanzibar (hotel) – Zanzibar airport
                    'label_it' => "Trasferimento all'aeroporto di Zanzibar, circa 1 ora", 'label_en' => 'Transfer to Zanzibar airport, about 1 hour',
                    'label_fr' => "Transfert à l'aéroport de Zanzibar, environ 1 heure", 'label_es' => 'Traslado al aeropuerto de Zanzíbar, aprox. 1 hora',
                    'label_de' => 'Transfer zum Flughafen Sansibar, ca. 1 Stunde'],
        '30-52' => ['min' => 180],                                  // Kilimanjaro Airport – Karatu (210)
        '15-52' => ['min' => 60],                                   // Tarangire – Karatu (90)
        '52-31' => ['min' => 180],                                  // Karatu – Arusha Airport
        '14-64' => [],                                              // Serengeti – Ndutu, game drive on the way
        '30-54' => ['min' => 105, 'new' => true, 'ask' => 'Kilimanjaro Airport – Machame: "1,5-2 ore" (set 1 h 45)'],
        '55-17' => ['min' => 180],                                  // Marangu – Arusha (90)
        '17-55' => ['min' => 180],                                  // Arusha – Marangu (90)
        '17-1'  => ['min' => 45, 'new' => true],                    // Arusha – Arusha National Park
        '52-18' => ['min' => 60],                                   // Karatu – Lake Eyasi (90)
        '9-14'  => ['min' => 90],                                   // Ngorongoro – Serengeti (270)
        '14-19' => ['min' => 300, 'new' => true],                   // Serengeti – Lake Natron
        '19-17' => ['min' => 300, 'new' => true],                   // Lake Natron – Arusha
        '53-30' => ['min' => 90],                                   // Moshi – Kilimanjaro Airport (#4): Mbogo (22) day 13 (Roberto, 10 Nov 2026)
        '6-17'  => ['min' => 120],                                  // Lake Manyara – Arusha
    ];
}

/** Free text (as in the samples) → legs [[from-to, game_drive]] + flights ["Zanzibar → Arusha"]. Unlisted texts stay free text. */
function iti_sc_text_map(): array {
    $m = [
        'Aeroporto del Kilimanjaro – Arusha 1 ora di trasferimento.'          => [['30-17']],
        'Arusha – Tarangire 2 ore di trasferimento.'                          => [['17-15']],
        'Karatu-Arusha-Kilimanjaro 3 ore circa di trasferimento.'             => [['52-30']],
        'Karatu – Ngorongoro – Serengeti 3 ore di trasferimento.'             => [['52-14']],
        'Karatu – Ngorongoro – Serengeti ingresso 3 ore di trasferimento.'    => [['52-14']],
        'Serengeti – Ngorongoro con fotosafari sulla strada'                  => [['14-9', 1]],
        'Karatu – cratere del Ngorongoro 1.5 ora di trasferimento.'           => [['52-9']],
        'Transfer al cratere del Ngorongoro circa 1 ora'                      => [['64-9']],
        'Karatu - Arusha 2 ore di trasferimento.'                             => [['52-17']],
        'Karatu-Arusha 2 ore circa di trasferimento.'                         => [['52-17']],
        'Karatu - Manyara 30 min circa di trasferimento.'                     => [['52-6']],
        'Karatu - Manyara 30 min. di trasferimento.'                          => [['52-6']],
        'Karatu - Manyara 30 minuti circa di trasferimento.'                  => [['52-6']],
        'Karatu - Manyara 45 min. di trasferimento.'                          => [['52-6']],
        'Transferimento all’aeroporto di Zanzibar 1 ora circa.'               => [['24-33']],
        'Kilimanjaro Airport – Karatu 3 ore di transferimento.'               => [['30-52']],
        'Tarangire – Karatu 1 ora di trasferimento.'                          => [['15-52']],
        'Karatu – aeroporto di Arusha 3 ore circa di trasferimento.'          => [['52-31']],
        'Serengeti – Ndutu con fotosafari sulla strada'                       => [['14-64', 1]],
        'Aeroporto del Kilimanjaro – Machame 1,5-2 ore di trasferimento'      => [['30-54']],
        'Marangu – Arusha circa 3 ore di trasferimento.'                      => [['55-17']],
        'Arusha – Marangu circa 3 ore di trasferimento.'                      => [['17-55']],
        'Arusha-Arusha NP 45 minuti di trasferimento.'                        => [['17-1']],
        'Karatu - lago Eyasi circa 1 ora di transfer'                         => [['52-18']],
        'Ngorongoro – Serengeti 1,5 ore di trasferimento.'                    => [['9-14']],
        'Dal Serengeti al lago Natron 5 ore circa di transfer.'               => [['14-19']],
        'Trasferimento Natron – Arusha 5 ore circa.'                          => [['19-17']],
        'Trasferimento all’aeroporto del Kilimanjaro 1,5 ore circa.'          => [['53-30']],   // Mbogo day 13: from Moshi
        'Manyara-Arusha 2 ore circa di trasferimento + volo Arusha-Zanzibar.' => [['6-17'], 'flights' => ['Arusha → Zanzibar']],
        'volo Zanzibar-Arusha + Arusha – Tarangire 2 ore di trasferimento.'   => [['17-15'], 'flights' => ['Zanzibar → Arusha'], 'flight_first' => true],
        'Zanzibar – Serengeti con volo interno'                               => ['flights' => ['Zanzibar → Serengeti']],
    ];
    $out = [];
    foreach ($m as $txt => $spec) $out[iti_sc_norm($txt)] = $spec;
    return $out;
}

/** Samples (not cancelled): id → row. */
function iti_sc_samples(PDO $db): array {
    $o = [];
    foreach ($db->query("SELECT * FROM iti_programs WHERE program_type = 'sample' AND status <> 'cancelled' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $r) $o[(int)$r['id']] = $r;
    return $o;
}

function iti_sc_title(array $p): string { return '#' . $p['id'] . ' ' . ($p['title_it'] ?: $p['title_en']); }

// ── shared ──────────────────────────────────────────────────────────────────

function iti_sc_plan_shared(PDO $db): array {
    $rows = [];
    $days = $db->prepare('SELECT * FROM iti_program_days WHERE program_id = ? ORDER BY day_number');
    foreach (iti_sc_samples($db) as $id => $p) {
        if (iti_safari_type($p) === 'group') continue;   // kept on group safaris (Roberto, 10 Nov 2026)
        foreach (ITI_TR_LANGS as $l) {
            $v = (string)($p['intro_' . $l] ?? '');
            if (iti_tr_has_shared($v)) $rows[] = ['program' => iti_sc_title($p), 'where' => 'intro', 'lang' => $l, 'table' => 'iti_programs', 'id' => $id, 'col' => 'intro_' . $l, 'before' => $v, 'after' => iti_tr_shared_strip($v)];
        }
        $days->execute([$id]);
        foreach ($days->fetchAll(PDO::FETCH_ASSOC) as $d) {
            foreach (ITI_TR_LANGS as $l) {
                $v = (string)($d['narrative_' . $l] ?? '');
                if (iti_tr_has_shared($v)) $rows[] = ['program' => iti_sc_title($p), 'where' => 'day ' . $d['day_number'], 'lang' => $l, 'table' => 'iti_program_days', 'id' => (int)$d['id'], 'col' => 'narrative_' . $l, 'before' => $v, 'after' => iti_tr_shared_strip($v)];
            }
        }
    }
    return $rows;
}

/** Write planned [table, id, col, after] rows (shared / titles), in one transaction. */
function iti_sc_apply_rows(PDO $db, array $rows): int {
    $db->beginTransaction();
    try {
        foreach ($rows as $r) {
            if (!preg_match('/^[a-z_]+$/', $r['table'] . $r['col'])) throw new RuntimeException('bad column');
            $db->prepare('UPDATE ' . $r['table'] . ' SET `' . $r['col'] . '` = ? WHERE id = ?')->execute([$r['after'], $r['id']]);
        }
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); throw $e; }
    return count($rows);
}

// ── titles (group samples) ──────────────────────────────────────────────────

function iti_sc_plan_titles(PDO $db): array {
    $rows = [];
    $samples = iti_sc_samples($db);
    foreach (ITI_SC_GROUP_TITLES as $id => $titles) {
        if (!isset($samples[$id])) continue;
        $p = $samples[$id];
        foreach ($titles as $l => $t) {
            if (trim((string)$p['title_' . $l]) !== $t) $rows[] = ['program' => iti_sc_title($p), 'where' => 'title', 'lang' => $l, 'table' => 'iti_programs', 'id' => $id, 'col' => 'title_' . $l, 'before' => (string)$p['title_' . $l], 'after' => $t];
        }
        // Subtitle (place names): every language as the Italian one when it lacks a place of it (199 en / fr without Ndutu).
        foreach (['en', 'fr'] as $l) {
            $it = trim((string)$p['subtitle_it']); $cur = trim((string)$p['subtitle_' . $l]);
            if ($it !== '' && $cur !== '' && $cur !== $it && substr_count($it, ' - ') > substr_count($cur, ' - ')) {
                $rows[] = ['program' => iti_sc_title($p), 'where' => 'subtitle', 'lang' => $l, 'table' => 'iti_programs', 'id' => $id, 'col' => 'subtitle_' . $l, 'before' => $cur, 'after' => $it];
            }
        }
        // Intro: "private safari" line on a group sample → the group line.
        foreach (ITI_SC_INTRO_PRIVATE as $l => $priv) {
            $v = (string)($p['intro_' . $l] ?? '');
            if ($v !== '' && strpos($v, $priv) !== false) $rows[] = ['program' => iti_sc_title($p), 'where' => 'intro', 'lang' => $l, 'table' => 'iti_programs', 'id' => $id, 'col' => 'intro_' . $l, 'before' => $priv, 'after' => ITI_SC_INTRO_GROUP[$l], 'full_after' => str_replace($priv, ITI_SC_INTRO_GROUP[$l], $v)];
        }
    }
    return $rows;
}

function iti_sc_apply_titles(PDO $db, array $rows): int {
    foreach ($rows as $i => $r) if (isset($r['full_after'])) $rows[$i]['after'] = $r['full_after'];
    return iti_sc_apply_rows($db, $rows);
}

// ── private Nyumbu ──────────────────────────────────────────────────────────

/** The existing private Nyumbu sample, if any. */
function iti_sc_nyumbu_private(PDO $db): ?array {
    foreach (iti_sc_samples($db) as $p) if (($p['hub_program_code'] ?? '') === 'Nyumbu' && iti_safari_type($p) === 'private') return $p;
    return null;
}

function iti_sc_plan_nyumbu(PDO $db): array {
    $samples = iti_sc_samples($db);
    if ($ex = iti_sc_nyumbu_private($db)) return ['exists' => iti_sc_title($ex)];
    if (!isset($samples[ITI_SC_NYUMBU_GROUP], $samples[ITI_SC_PRIVATE_MODEL])) return ['error' => 'sample 199 or 32 not found'];
    $src = $samples[ITI_SC_NYUMBU_GROUP];
    $plan = ['from' => iti_sc_title($src), 'set' => ['title (5 languages)' => 'NYUMBU SAFARI', 'safari_type' => 'private', 'Calc code' => 'Nyumbu'], 'changes' => []];
    foreach (ITI_SC_INTRO_GROUP as $l => $grp) {
        if (strpos((string)($src['intro_' . $l] ?? ''), $grp) !== false) $plan['changes'][] = ['what' => 'intro ' . $l, 'before' => $grp, 'after' => ITI_SC_INTRO_PRIVATE[$l]];
    }
    list($grpInc, $privInc) = iti_sc_nyumbu_inclusions($db);
    if ($grpInc && $privInc) $plan['changes'][] = ['what' => 'included: jeep', 'before' => $grpInc['text_it'], 'after' => $privInc['text_it'] . ' (+ en / fr… of Simba)'];
    elseif ($grpInc) $plan['changes'][] = ['what' => 'included: jeep', 'before' => $grpInc['text_it'], 'after' => '⚠ no jeep line found in Simba (32): left as is'];
    foreach (ITI_TR_LANGS as $l) if (iti_tr_has_shared((string)($src['intro_' . $l] ?? ''))) $plan['changes'][] = ['what' => 'intro ' . $l, 'before' => 'shared-transfer sentence', 'after' => 'removed'];
    return $plan;
}

/** [199's "jeep shared with the group" inclusion row, Simba's private jeep row] (either may be null). */
function iti_sc_nyumbu_inclusions(PDO $db, int $from = ITI_SC_NYUMBU_GROUP): array {
    $find = function (int $pid, string $re) use ($db) {
        $st = $db->prepare("SELECT * FROM iti_program_inclusions WHERE program_id = ? AND item_type = 'inclusion' ORDER BY sort_order, id");
        $st->execute([$pid]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) if (preg_match($re, (string)$r['text_it'])) return $r;
        return null;
    };
    return [$find($from, '/4x4.*condivis/iu'), $find(ITI_SC_PRIVATE_MODEL, '/4x4.*(uso esclusivo|esclusiv)/iu')];
}

function iti_sc_apply_nyumbu(PDO $db, string $who): int {
    if (iti_sc_nyumbu_private($db)) throw new RuntimeException('The private Nyumbu sample already exists.');
    $set = ['safari_type' => 'private', 'hub_program_code' => 'Nyumbu'];
    foreach (ITI_TR_LANGS as $l) $set['title_' . $l] = 'NYUMBU SAFARI';
    $set = array_intersect_key($set, array_flip(iti_table_columns('iti_programs')));
    $id = iti_duplicate_program(ITI_SC_NYUMBU_GROUP, 'sample', $who, $set);
    $p = iti_get_program($id);
    $db->beginTransaction();
    try {
        foreach (ITI_SC_INTRO_GROUP as $l => $grp) {
            $v = (string)($p['intro_' . $l] ?? '');
            $nv = iti_tr_shared_strip(str_replace($grp, ITI_SC_INTRO_PRIVATE[$l], $v));
            if ($nv !== $v) $db->prepare('UPDATE iti_programs SET `intro_' . $l . '` = ? WHERE id = ?')->execute([$nv, $id]);
        }
        list($grpInc, $privInc) = iti_sc_nyumbu_inclusions($db, $id);
        if ($grpInc && $privInc) {
            $cols = ['standard_inclusion_id' => $privInc['standard_inclusion_id']];
            foreach (ITI_TR_LANGS as $l) if (array_key_exists('text_' . $l, $privInc)) $cols['text_' . $l] = $privInc['text_' . $l];
            $db->prepare('UPDATE iti_program_inclusions SET `' . implode('` = ?, `', array_keys($cols)) . '` = ? WHERE id = ?')->execute(array_merge(array_values($cols), [(int)$grpInc['id']]));
        }
        $st = $db->prepare('SELECT id, narrative_it, narrative_en, narrative_fr, narrative_es, narrative_de FROM iti_program_days WHERE program_id = ?');
        $st->execute([$id]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $d) foreach (ITI_TR_LANGS as $l) {
            $v = (string)$d['narrative_' . $l];
            if (iti_tr_has_shared($v)) $db->prepare('UPDATE iti_program_days SET `narrative_' . $l . '` = ? WHERE id = ?')->execute([iti_tr_shared_strip($v), (int)$d['id']]);
        }
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); throw $e; }
    return $id;
}

// ── routes (catalogue) ──────────────────────────────────────────────────────

/** Route id for "from-to", or null. */
function iti_sc_route_id(PDO $db, string $key): ?int {
    list($f, $t) = array_map('intval', explode('-', $key));
    foreach (iti_tr_routes($db) as $id => $r) if ((int)$r['from_destination'] === $f && (int)$r['to_destination'] === $t && !empty($r['is_active'])) return $id;
    return null;
}

function iti_sc_plan_routes(PDO $db): array {
    $dests = iti_tr_dests($db);
    $rows = [];
    foreach (iti_sc_route_specs() as $key => $s) {
        list($f, $t) = array_map('intval', explode('-', $key));
        $rid = iti_sc_route_id($db, $key);
        $cur = $rid ? iti_tr_routes($db)[$rid] : null;
        $want = [];
        if (isset($s['min'])) $want['duration_min'] = $s['min'];
        if (isset($s['via'])) $want['via_destination'] = $s['via'];
        foreach (ITI_TR_LANGS as $l) if (isset($s['label_' . $l])) $want['label_' . $l] = $s['label_' . $l];
        $chg = [];
        foreach ($want as $k => $v) if (!$cur || (string)($cur[$k] ?? '') !== (string)$v) $chg[$k] = ['before' => $cur[$k] ?? null, 'after' => $v];
        $after = array_merge($cur ?: ['from_destination' => $f, 'to_destination' => $t, 'duration_min' => 0, 'via_destination' => null], $want);
        $rows[] = [
            'key' => $key, 'route_id' => $rid, 'new' => !$rid,
            'name' => ($dests[$f]['name_en'] ?? '#' . $f) . ' → ' . ($dests[$t]['name_en'] ?? '#' . $t),
            'changes' => $chg, 'ask' => $s['ask'] ?? null,
            'label_before' => $cur ? iti_tr_labels($db, $cur) : null, 'label_after' => iti_tr_labels($db, $after),
        ];
    }
    return $rows;
}

function iti_sc_apply_routes(PDO $db): int {
    $n = 0;
    $db->beginTransaction();
    try {
        foreach (iti_sc_plan_routes($db) as $r) {
            if (!$r['new'] && !$r['changes']) continue;
            $vals = array_map(function ($c) { return $c['after']; }, $r['changes']);
            if ($r['new']) {
                list($f, $t) = array_map('intval', explode('-', $r['key']));
                $row = array_merge(['from_destination' => $f, 'to_destination' => $t, 'duration_min' => 0, 'road_type' => 'mixed', 'is_active' => 1], $vals);
                $db->prepare('INSERT INTO iti_transfer_routes (`' . implode('`,`', array_keys($row)) . '`) VALUES (' . implode(',', array_fill(0, count($row), '?')) . ')')->execute(array_values($row));
            } else {
                $db->prepare('UPDATE iti_transfer_routes SET `' . implode('` = ?, `', array_keys($vals)) . '` = ? WHERE id = ?')->execute(array_merge(array_values($vals), [$r['route_id']]));
            }
            $n++;
        }
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); throw $e; }
    iti_tr_routes($db, true);
    return $n;
}

// ── link (sample day transfers) ─────────────────────────────────────────────

/** Per sample day with free-text transfers: before → after (labels it / en), flights added, unmatched texts. */
function iti_sc_plan_link(PDO $db): array {
    $map = iti_sc_text_map();
    $out = ['days' => [], 'unmatched' => [], 'missing_routes' => []];
    $days = $db->prepare('SELECT id, day_number FROM iti_program_days WHERE program_id = ? ORDER BY day_number');
    $trs  = $db->prepare('SELECT * FROM iti_day_transfers WHERE program_day_id = ? ORDER BY sort_order, id');
    foreach (iti_sc_samples($db) as $pid => $p) {
        $days->execute([$pid]);
        foreach ($days->fetchAll(PDO::FETCH_ASSOC) as $d) {
            $trs->execute([(int)$d['id']]);
            $rows = $trs->fetchAll(PDO::FETCH_ASSOC);
            $items = []; $flights = []; $changed = false; $before = [];
            foreach ($rows as $t) {
                $before[] = $t['description'];
                if (!empty($t['transfer_route_id'])) { $items[] = ['route' => (int)$t['transfer_route_id'], 'game_drive' => (int)($t['game_drive'] ?? 0), 'label' => $t['description']]; continue; }
                $spec = $map[iti_sc_norm((string)$t['description'])] ?? null;
                if ($spec === null) {
                    $out['unmatched'][] = ['program' => iti_sc_title($p), 'day' => (int)$d['day_number'], 'text' => $t['description']];
                    $items[] = ['text' => $t['description'], 'tr' => $t];
                    continue;
                }
                $changed = true;
                foreach ($spec['flights'] ?? [] as $f) $flights[] = ['custom' => $f, 'first' => !empty($spec['flight_first'])];
                foreach ($spec as $k => $leg) {
                    if (!is_int($k)) continue;
                    $rid = iti_sc_route_id($db, $leg[0]);
                    if (!$rid) $out['missing_routes'][$leg[0]] = true;
                    $items[] = ['route' => $rid, 'key' => $leg[0], 'game_drive' => !empty($leg[1]) ? 1 : 0];
                }
            }
            if (!$changed) continue;
            $after = [];
            foreach ($items as $it) {
                if (isset($it['text'])) { $after[] = ['it' => $it['text'], 'en' => '(free text, stays)']; continue; }
                $route = $it['route'] ? iti_tr_routes($db)[$it['route']] : null;
                if (!$route) { $after[] = ['it' => '⚠ route ' . $it['key'] . ' missing — apply "Transfer catalogue" first', 'en' => '']; continue; }
                $after[] = ['it' => iti_tr_label($db, $route, 'it', (bool)$it['game_drive'])['text'], 'en' => iti_tr_label($db, $route, 'en', (bool)$it['game_drive'])['text']];
            }
            $out['days'][] = ['program' => iti_sc_title($p), 'program_id' => $pid, 'day' => (int)$d['day_number'], 'day_id' => (int)$d['id'],
                              'before' => $before, 'after' => $after, 'flights' => array_column($flights, 'custom'), '_items' => $items, '_flights' => $flights];
        }
    }
    $out['missing_routes'] = array_keys($out['missing_routes']);
    return $out;
}

function iti_sc_apply_link(PDO $db): int {
    $plan = iti_sc_plan_link($db);
    if ($plan['missing_routes']) throw new RuntimeException('Apply "Transfer catalogue" first: routes missing ' . implode(', ', $plan['missing_routes']));
    $n = 0;
    $db->beginTransaction();
    try {
        foreach ($plan['days'] as $d) {
            $items = [];
            foreach ($d['_items'] as $it) {
                if (isset($it['text'])) {   // unmatched free text: keep it with its translations
                    $tr = [];
                    foreach (ITI_TR_LANGS as $l) if (trim((string)($it['tr']['description_' . $l] ?? '')) !== '') $tr[$l] = $it['tr']['description_' . $l];
                    $items[] = ['description' => $it['text'], 'tr' => $tr];
                } else {
                    $items[] = ['route' => $it['route'], 'game_drive' => $it['game_drive']];
                }
            }
            iti_transfers_replace($db, $d['day_id'], $items);
            if ($d['_flights']) {
                $st = $db->prepare('SELECT flight_custom, sort_order FROM iti_day_flights WHERE program_day_id = ?');
                $st->execute([$d['day_id']]);
                $have = $st->fetchAll(PDO::FETCH_ASSOC);
                $max = 0; $names = [];
                foreach ($have as $h) { $max = max($max, (int)$h['sort_order']); $names[] = (string)$h['flight_custom']; }
                foreach ($d['_flights'] as $f) {
                    if (in_array($f['custom'], $names, true)) continue;
                    // Same shape as an Agent API custom flight (iti_pb_day_write): no route, the text only (place names, any language).
                    $db->prepare('INSERT INTO iti_day_flights (program_day_id, flight_route_id, flight_custom, sort_order) VALUES (?, NULL, ?, ?)')->execute([$d['day_id'], $f['custom'], $f['first'] ? 0 : ++$max]);
                }
            }
            $n++;
        }
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); throw $e; }
    return $n;
}
