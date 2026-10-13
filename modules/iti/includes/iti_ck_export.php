<?php
/**
 * iti_ck_export.php — the ITI programme of a confirmed booking, in the shape SafariCheck
 * reads from a Wetu Word (safariagent core_model.WordData), for the CK runner:
 * modules/leads/ck_agent_api.php ?action=iti.
 *
 * Which programme: the personal programmes linked to the folder's Hub requests
 * (iti_programs.lead_request_id), not cancelled, with a start date; per request the
 * active final ones (built from the Calc, not superseded), else the newest personal one.
 * GRP folder: one per client request, with that client's sub-folder (requests.practice_code).
 *
 * Night of day N = its end lodge (the last day has no night). Meal basis of the night
 * from the day's flags: dinner + lunch = FB, dinner = HB, breakfast next morning = BB,
 * all inclusive = AI (iti_final_meals() writes them the same way from the Calc).
 */
require_once __DIR__ . '/iti_functions.php';

/** Programmes of a 001_Safari folder for SafariCheck, newest first. */
function iti_ck_programs(PDO $db, string $folder): array {
    iti_ensure_final_schema();
    $st = $db->prepare("SELECT id, practice_code, group_folder FROM requests
                         WHERE (practice_code = ? AND (group_folder IS NULL OR group_folder = '')) OR group_folder = ?");
    $st->execute([$folder, $folder]);
    $reqs = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $sub = trim((string)$r['practice_code']);
        $reqs[(int)$r['id']] = (string)$r['group_folder'] !== '' && $sub !== $folder ? $sub : '';
    }
    if (!$reqs) return [];

    $in = implode(',', array_fill(0, count($reqs), '?'));
    $st = $db->prepare("SELECT * FROM iti_programs WHERE lead_request_id IN ($in)
                          AND program_type = 'personal' AND status <> 'cancelled' ORDER BY id DESC");
    $st->execute(array_keys($reqs));
    $finals = []; $other = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) {
        if (empty($p['start_date']) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$p['start_date'])) continue;
        $rid = (int)$p['lead_request_id'];
        if (($p['stage'] ?? '') === 'final') {
            if (empty($p['superseded_by'])) $finals[$rid][] = $p;
        } elseif (!isset($other[$rid])) {
            $other[$rid] = $p;
        }
    }
    $out = [];
    foreach ($reqs as $rid => $sub) {
        foreach ($finals[$rid] ?? (isset($other[$rid]) ? [$other[$rid]] : []) as $p) {
            $out[] = iti_ck_program_data($db, $p, $rid, $sub);
        }
    }
    return $out;
}

/** Basis of the night after a day: its meal flags and the next morning's breakfast. */
function iti_ck_basis(array $d, ?array $next): ?string {
    if (!empty($d['meal_all_inclusive'])) return 'AI';
    if (!empty($d['meal_dinner'])) return !empty($d['meal_lunch']) ? 'FB' : 'HB';
    if ($next !== null && !empty($next['meal_breakfast'])) return 'BB';
    return null;
}

/** Localised text with fallback lang → en → it → any. */
function iti_ck_pick(array $row, string $prefix, string $lang): string {
    foreach (array_merge([$lang, 'en', 'it'], ITI_LANGUAGES) as $l) {
        $v = trim((string)($row[$prefix . $l] ?? ''));
        if ($v !== '') return $v;
    }
    return '';
}

/**
 * One programme as SafariCheck WordData: dates, pax, adult / teen price (USD rows of the
 * price table), stays, internal flights, excluded items, and the file names an ITI export
 * of it gets (so the runner knows a .docx in the folder is this programme, not a Wetu Word).
 */
function iti_ck_program_data(PDO $db, array $p, int $requestId, string $clientFolder): array {
    $id   = (int)$p['id'];
    $lang = in_array($p['display_language'] ?? '', ITI_LANGUAGES, true) ? $p['display_language'] : 'it';
    $days = iti_get_days($id);
    $n    = count($days);
    $date = function (array $d) use ($p) {
        return date('Y-m-d', strtotime($p['start_date'] . ' +' . ((int)$d['day_number'] - 1) . ' days'));
    };

    // Stays: consecutive nights in the same lodge with the same room type.
    $stays = [];
    for ($i = 0; $i < $n - 1; $i++) {
        $d = $days[$i];
        $lodge = trim((string)($d['end_lodge_name'] ?? '')) ?: trim((string)($d['end_lodge_custom'] ?? ''));
        if ($lodge === '') continue;
        $night = $date($d);
        $room  = trim((string)($d['room_type'] ?? ''));
        $basis = iti_ck_basis($d, $days[$i + 1]);
        $k = count($stays) - 1;
        if ($k >= 0 && $stays[$k]['lodge'] === $lodge && $stays[$k]['room'] === $room && $stays[$k]['end'] === $night) {
            $stays[$k]['end'] = date('Y-m-d', strtotime($night . ' +1 day'));
            $stays[$k]['nights']++;
            $stays[$k]['night_bases'][] = $basis;
            continue;
        }
        $stays[] = ['lodge' => $lodge, 'destination' => trim((string)($d['destination_name_en'] ?? '')),
                    'start' => $night, 'end' => date('Y-m-d', strtotime($night . ' +1 day')), 'nights' => 1,
                    'room' => $room, 'night_bases' => [$basis]];
    }
    foreach ($stays as &$s) {
        $s['basis'] = null;
        foreach ($s['night_bases'] as $b) if ($b !== null) { $s['basis'] = $b; break; }
        if ($s['room'] === '') $s['room'] = null;
    }
    unset($s);

    // Internal flights, dated by their day.
    $flights = [];
    $st = $db->prepare('SELECT f.*, r.from_airport, r.to_airport, r.operator FROM iti_day_flights f
                          LEFT JOIN iti_flight_routes r ON r.id = f.flight_route_id WHERE f.program_day_id = ? ORDER BY f.sort_order, f.id');
    foreach ($days as $d) {
        $st->execute([(int)$d['id']]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $f) {
            $from = trim((string)($f['from_airport'] ?? '')) ?: trim((string)($f['flight_custom'] ?? ''));
            $to   = trim((string)($f['to_airport'] ?? ''));
            if ($from === '' && $to === '') continue;
            $flights[] = ['date' => $date($d), 'airline' => trim((string)($f['airline_company'] ?? '')) ?: trim((string)($f['operator'] ?? '')),
                          'from' => $from, 'to' => $to];
        }
    }

    // Not included (an own-arrangement flight listed there is not a missing one).
    $excluded = [];
    $st = $db->prepare("SELECT pi.*, si.text_en AS s_en, si.text_it AS s_it, si.text_fr AS s_fr, si.text_es AS s_es, si.text_de AS s_de
                          FROM iti_program_inclusions pi LEFT JOIN iti_standard_inclusions si ON si.id = pi.standard_inclusion_id
                         WHERE pi.program_id = ? AND pi.item_type = 'exclusion' ORDER BY pi.sort_order, pi.id");
    $st->execute([$id]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $t = iti_ck_pick($r, 'text_', $lang) ?: iti_ck_pick($r, 's_', $lang);
        if ($t !== '') $excluded[] = $t;
    }

    // Prices: the USD rows of the client's price table; totals, children and supplements skipped.
    $adult = null; $teen = null;
    $rows = json_decode((string)($p['price_table_json'] ?? ''), true);
    foreach (is_array($rows) ? $rows : [] as $r) {
        if (!is_array($r) || !isset($r['price']) || !is_numeric($r['price']) || ($r['currency'] ?? 'USD') !== 'USD') continue;
        $l = mb_strtolower((string)($r['label'] ?? ''));
        if (!empty($r['bold']) || preg_match('/\b(total|totale|gesamt)/u', $l)) continue;
        if (preg_match('/teen|ragazz|adolesc|jugend/u', $l)) { if ($teen === null) $teen = (float)$r['price']; continue; }
        if (preg_match('/child|bambin|enfant|niñ|kind|chd|suppl|single|singol|sconto|discount|riduzion/u', $l)) continue;
        if ($adult === null) $adult = (float)$r['price'];
    }

    // File names of this programme's Word: saved by the API (<ref_number>.docx) or downloaded (<Title>_<LANG>.docx).
    $names = [];
    if (trim((string)($p['ref_number'] ?? '')) !== '') $names[] = trim((string)$p['ref_number']) . '.docx';
    foreach (ITI_LANGUAGES as $l) {
        $t = trim((string)($p['title_' . $l] ?? ''));
        if ($t !== '') $names[] = (trim(preg_replace('/[^A-Za-z0-9]+/', '_', $t), '_') ?: 'Program') . '_' . strtoupper($l) . '.docx';
    }

    $end = $n ? $date($days[$n - 1]) : $p['start_date'];
    return [
        'id' => $id, 'request_id' => $requestId, 'client_folder' => $clientFolder,
        'stage' => $p['stage'] ?? 'proposal', 'status' => $p['status'] ?? '',
        'title' => iti_ck_pick($p, 'title_', $lang), 'language' => strtoupper($lang),
        'ref_number' => trim((string)($p['ref_number'] ?? '')) ?: null,
        'source_calc' => !empty($p['source_calc_path']) ? basename((string)$p['source_calc_path']) : null,
        'generated_at' => $p['generated_at'] ?? null, 'updated_at' => $p['updated_at'] ?? null,
        'start' => $p['start_date'], 'end' => $end,
        'pax' => (int)($p['pax_adults'] ?? 0) + (int)($p['pax_teens'] ?? 0) + (int)($p['pax_children'] ?? 0),
        'price_adult' => $adult, 'price_teen' => $teen,
        'stays' => $stays, 'flights' => $flights, 'excluded' => $excluded,
        'doc_names' => array_values(array_unique($names)),
        'link' => ITI_MODULE_URL . '/program_edit.php?id=' . $id,
    ];
}
