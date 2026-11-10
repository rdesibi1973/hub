<?php
/**
 * iti_price_service.php — price table of a new personal programme at a price level (Roberto, 10 Nov 2026).
 *
 * A sample with a Calc code (hub_program_code) → the per-person prices of that level for 2–6 pax from the
 * season's Calc template (calc_program_prices()), and price notes in the 5 languages: single supplement,
 * teen / child reductions, the seasonal supplement, "rates valid for travel until …". Samples themselves
 * never carry prices ("il sample è il programma, il prezzo va messo caso per caso"); the seller can still
 * overwrite the table. Used by iti_ps_create_personal() (Agent API iti_create_personal) and programs.php
 * (Hub "Create from sample" for a request).
 *
 * Settings (ITI → Settings, iti_settings): price_valid_until (YYYY-MM-DD, default 2027-12-31),
 * price_season_supplement (USD per person per night, default 35).
 */

require_once __DIR__ . '/iti_final.php';   // iti_final_price_options_table(): "Per person, based on N pax" rows
require_once __DIR__ . '/../../leads/includes/price_level_service.php';
require_once __DIR__ . '/../../leads/includes/calc_service.php';

const ITI_PRICE_VALID_DEFAULT = '2027-12-31';
const ITI_PRICE_SEASON_DEFAULT = 35;

function iti_price_setting(string $key, string $default): string {
    if (function_exists('iti_setting')) { $v = trim(iti_setting($key, $default)); return $v !== '' ? $v : $default; }
    return $default;
}

/** "31 dicembre 2027" / "31 December 2027" / "31 décembre 2027" / "31 de diciembre de 2027" / "31. Dezember 2027". */
function iti_price_date(string $ymd, string $lang): string {
    $t = strtotime($ymd);
    if (!$t) return $ymd;
    $m = (int)date('n', $t); $d = (int)date('j', $t); $y = date('Y', $t);
    $M = ['it' => ['gennaio', 'febbraio', 'marzo', 'aprile', 'maggio', 'giugno', 'luglio', 'agosto', 'settembre', 'ottobre', 'novembre', 'dicembre'],
          'en' => ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'],
          'fr' => ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'],
          'es' => ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'],
          'de' => ['Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember']];
    $n = ($M[$lang] ?? $M['en'])[$m - 1];
    switch ($lang) {
        case 'es': return $d . ' de ' . $n . ' de ' . $y;
        case 'de': return $d . '. ' . $n . ' ' . $y;
        default:   return $d . ' ' . $n . ' ' . $y;
    }
}

/** One amount, or "X–Y" when the pax sheets differ; '' when none. */
function iti_price_span(array $vals): string {
    $v = array_values(array_unique(array_map('intval', array_filter($vals, function ($x) { return $x !== null && $x > 0; }))));
    if (!$v) return '';
    sort($v);
    return count($v) === 1 ? (string)$v[0] : $v[0] . '–' . end($v);
}

/**
 * Rows + notes of a level from calc_program_prices() output. $lang = the programme language (row labels).
 * Returns ['rows' => [{label, price, currency}], 'notes' => [lang => text], 'prices' => [pax => price]].
 */
function iti_price_build(array $calc, string $level, ?float $markup, string $lang): array {
    $grp = !empty($calc['grp']);
    $sheets = array_values(array_filter($calc['sheets'] ?? [], function ($s) { return ($s['pax'] ?? 0) >= 2 && ($s['pax'] ?? 0) <= 6; }));
    usort($sheets, function ($a, $b) { return $a['pax'] <=> $b['pax']; });
    $opts = []; $prices = [];
    foreach ($sheets as $s) {
        $p = price_level_sheet_price($s, $level, $markup, $grp);
        if ($p === null || $p <= 0) continue;
        if (isset($prices[$s['pax']])) continue;   // two sheets for the same pax: the first
        $prices[$s['pax']] = $p;
        $opts[] = ['pax_total' => (int)$s['pax'], 'price_to_customer' => $p, 'sheet' => $s['sheet']];
    }
    $rows = $opts ? iti_final_price_options_table($opts, $lang) : [];
    $single = iti_price_span(array_column($sheets, 'single_suppl'));
    $teen   = iti_price_span(array_column($sheets, 'teen_discount'));
    $child  = iti_price_span(array_column($sheets, 'child_discount'));
    $season = (int)iti_price_setting('price_season_supplement', (string)ITI_PRICE_SEASON_DEFAULT);
    $valid  = iti_price_setting('price_valid_until', ITI_PRICE_VALID_DEFAULT);
    $net    = $level !== 'rack';
    $T = [
        'it' => ['Supplemento camera singola: %s USD a persona.', 'Riduzione ragazzo (12–15 anni): %s USD.', 'Riduzione bambino (sotto i 12 anni): %s USD.',
                 'Supplemento alta stagione: %d USD a persona a notte dal 1 al 25 agosto e il 24, 25, 26 e 31 dicembre.',
                 ($net ? 'Tariffe nette' : 'Tariffe') . ' valide per viaggi fino al %s.'],
        'en' => ['Single room supplement: %s USD per person.', 'Teenager reduction (12–15): %s USD.', 'Child reduction (under 12): %s USD.',
                 'High-season supplement: %d USD per person per night from 1 to 25 August and on 24, 25, 26 and 31 December.',
                 ($net ? 'Net rates' : 'Rates') . ' valid for travel until %s.'],
        'fr' => ['Supplément chambre individuelle : %s USD par personne.', 'Réduction adolescent (12–15 ans) : %s USD.', 'Réduction enfant (moins de 12 ans) : %s USD.',
                 'Supplément haute saison : %d USD par personne et par nuit du 1er au 25 août et les 24, 25, 26 et 31 décembre.',
                 ($net ? 'Tarifs nets' : 'Tarifs') . ' valables pour les voyages jusqu\'au %s.'],
        'es' => ['Suplemento habitación individual: %s USD por persona.', 'Reducción adolescente (12–15 años): %s USD.', 'Reducción niño (menor de 12 años): %s USD.',
                 'Suplemento temporada alta: %d USD por persona y noche del 1 al 25 de agosto y los días 24, 25, 26 y 31 de diciembre.',
                 ($net ? 'Tarifas netas' : 'Tarifas') . ' válidas para viajes hasta el %s.'],
        'de' => ['Einzelzimmerzuschlag: %s USD pro Person.', 'Ermäßigung Jugendliche (12–15 J.): %s USD.', 'Ermäßigung Kinder (unter 12 J.): %s USD.',
                 'Hochsaisonzuschlag: %d USD pro Person und Nacht vom 1. bis 25. August und am 24., 25., 26. und 31. Dezember.',
                 ($net ? 'Nettopreise' : 'Preise') . ' gültig für Reisen bis %s.'],
    ];
    $notes = [];
    foreach ($T as $l => $t) {
        $lines = [];
        if ($single !== '') $lines[] = sprintf($t[0], $single);
        if ($teen !== '')   $lines[] = sprintf($t[1], $teen);
        if ($child !== '')  $lines[] = sprintf($t[2], $child);
        if ($season > 0)    $lines[] = sprintf($t[3], $season);
        $lines[] = sprintf($t[4], iti_price_date($valid, $l));
        $notes[$l] = implode("\n", $lines);
    }
    return ['rows' => $rows, 'notes' => $notes, 'prices' => $prices];
}

/**
 * Price plan of a new programme: $level given (rack | sto | stospec | custom, with $markup), else the
 * linked request's level (direct client → rack), else none. Null = no price table (no level, no Calc code).
 * Returns ['level', 'markup', 'label', 'source', 'code', 'template', 'rows', 'notes', 'prices'] or ['error'].
 */
function iti_price_plan(PDO $db, array $sample, ?string $level, $markup, ?int $requestId, string $lang): ?array {
    $code = trim((string)($sample['hub_program_code'] ?? ''));
    if ($level === 'none') return null;
    if ($level !== null && $level !== '') {
        list($lvl, $mk) = price_level_check($level, $markup);
        $src = 'given';
    } elseif ($requestId) {
        $r = price_level_for_request($db, $requestId);
        $lvl = $r['level']; $mk = $r['markup']; $src = $r['source'] === 'agency' ? 'agency ' . $r['agency'] : $r['source'];
    } else {
        return null;
    }
    $base = ['level' => $lvl, 'markup' => $mk, 'label' => price_level_label($lvl, $mk), 'source' => $src, 'code' => $code ?: null];
    if ($code === '') return $base + ['error' => 'The sample has no Calc code (iti_tag_sample): no price table — add the prices by hand.'];
    try {
        require_once __DIR__ . '/../../leads/dropbox_constants.php';
        require_once __DIR__ . '/../../leads/dropbox_helper.php';
        require_once __DIR__ . '/../../leads/includes/booking_service.php';   // bs_std_programs(): code → template
        $calc = calc_program_prices(dropbox_get_access_token(), $code);
    } catch (Throwable $e) {
        return $base + ['error' => 'Calc template of "' . $code . '" not readable: ' . $e->getMessage()];
    }
    $b = iti_price_build($calc, $lvl, $mk, $lang);
    if (!$b['rows']) return $base + ['template' => $calc['template'], 'error' => 'No ' . $base['label'] . ' price in the Calc template ' . $calc['template']];
    $checks = array_values(array_filter($calc['sheets'], function ($s) { return $s['sto_check'] === false; }));
    return $base + ['template' => $calc['template'], 'rows' => $b['rows'], 'notes' => $b['notes'], 'prices' => $b['prices'],
                    'warning' => $checks ? 'STO typed in the Calc differs from cost × (1 + D2) on ' . implode(', ', array_column($checks, 'sheet')) . ': check the template' : null];
}

/** Header values (price_table_json, price_notes_<lang>) of a plan, for the columns the table has. */
function iti_price_set(array $plan): array {
    if (empty($plan['rows'])) return [];
    $set = ['price_table_json' => json_encode(array_values($plan['rows']), JSON_UNESCAPED_UNICODE)];
    foreach ($plan['notes'] as $l => $t) $set['price_notes_' . $l] = $t;
    return $set;
}
