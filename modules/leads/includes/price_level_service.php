<?php
/**
 * price_level_service.php — the price level of an agency (Roberto, 10 Nov 2026): the markup on cost
 * of the Calc templates every quote to that agency uses.
 *
 *   RACK    25 % (Calc D1)  direct clients
 *   STO     18 % (Calc D2)  standard agencies / tour operators (default)
 *   STOSPEC 14 %            partner agencies with volume (GRP files 16 %)
 *   custom  agencies.custom_markup, never below 12 % (staff-commission threshold; below 14 % only
 *           case by case, e.g. confirmed groups)
 *
 * agencies.price_level / custom_markup: added lazily (price_level_schema()). Used by agencies.php,
 * request_view.php, the Agent API (get_rates, list_agencies / update_agency, iti_create_personal) and
 * iti_program_service.php (price table of a new programme).
 */

const PRICE_LEVELS = ['rack' => 0.25, 'sto' => 0.18, 'stospec' => 0.14, 'custom' => null];
const PRICE_LEVEL_MIN_MARKUP = 0.12;
const PRICE_LEVEL_GRP_STOSPEC = 0.16;   // STOSPEC on group (GRP) Calc files

/** agencies.price_level (default sto) and custom_markup, added once. DDL: call outside transactions. */
function price_level_schema(PDO $db): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $have = [];
        foreach ($db->query('SHOW COLUMNS FROM agencies')->fetchAll(PDO::FETCH_ASSOC) as $c) $have[$c['Field']] = true;
        if (!isset($have['price_level'])) $db->exec("ALTER TABLE agencies ADD COLUMN price_level VARCHAR(10) NOT NULL DEFAULT 'sto'");
        if (!isset($have['custom_markup'])) $db->exec('ALTER TABLE agencies ADD COLUMN custom_markup DECIMAL(5,3) NULL DEFAULT NULL');
    } catch (PDOException $e) {
        error_log('price_level_schema: ' . $e->getMessage());
    }
}

/** Validate a level / custom markup (fraction: 0.15 = 15 %; "15" or "15%" also read as 15 %) → [level, markup|null]. */
function price_level_check($level, $markup = null): array {
    $level = strtolower(trim((string)$level));
    if (!array_key_exists($level, PRICE_LEVELS)) throw new InvalidArgumentException('price_level: rack | sto | stospec | custom');
    if ($level !== 'custom') return [$level, null];
    $m = is_string($markup) ? (float)str_replace([',', '%'], ['.', ''], $markup) : (float)$markup;
    if ($m >= 1) $m = $m / 100;
    if ($m <= 0) throw new InvalidArgumentException('custom_markup is required with price_level custom (e.g. 0.15 = 15 %)');
    if ($m < PRICE_LEVEL_MIN_MARKUP) throw new InvalidArgumentException('custom_markup ' . round($m * 100, 1) . ' % is below the 12 % minimum (staff-commission threshold)');
    return ['custom', round($m, 3)];
}

/** Markup fraction of a level (custom → its markup; GRP files: STOSPEC 16 %). */
function price_level_markup(string $level, ?float $custom = null, bool $grp = false): float {
    if ($level === 'custom') return (float)$custom;
    if ($level === 'stospec' && $grp) return PRICE_LEVEL_GRP_STOSPEC;
    return (float)(PRICE_LEVELS[$level] ?? PRICE_LEVELS['sto']);
}

/** "STO (18 %)", "Custom (15 %)". */
function price_level_label(string $level, ?float $custom = null): string {
    $names = ['rack' => 'RACK', 'sto' => 'STO', 'stospec' => 'STOSPEC', 'custom' => 'Custom'];
    return ($names[$level] ?? strtoupper($level)) . ' (' . rtrim(rtrim(number_format(price_level_markup($level, $custom) * 100, 1, '.', ''), '0'), '.') . ' %)';
}

/** An agency's level: ['level', 'markup', 'label']. */
function price_level_of_agency(PDO $db, int $agencyId): array {
    price_level_schema($db);
    $st = $db->prepare('SELECT price_level, custom_markup FROM agencies WHERE id = ?');
    $st->execute([$agencyId]);
    $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $lvl = array_key_exists((string)($r['price_level'] ?? ''), PRICE_LEVELS) ? $r['price_level'] : 'sto';
    $m = $lvl === 'custom' && $r['custom_markup'] !== null ? (float)$r['custom_markup'] : null;
    if ($lvl === 'custom' && $m === null) $lvl = 'sto';
    return ['level' => $lvl, 'markup' => $m, 'label' => price_level_label($lvl, $m)];
}

/**
 * Level of a Hub request: direct client (channel direct, or "-Drct)" in its folder) → rack; otherwise its
 * agency's level; no agency → sto. Returns ['level', 'markup', 'label', 'source' (direct|agency|default), 'agency_id', 'agency'].
 */
function price_level_for_request(PDO $db, int $requestId): array {
    price_level_schema($db);
    $st = $db->prepare('SELECT r.channel, r.practice_code, r.group_folder, r.agency_id, a.nome FROM requests r LEFT JOIN agencies a ON a.id = r.agency_id WHERE r.id = ?');
    $st->execute([$requestId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) throw new InvalidArgumentException('Request ' . $requestId . ' not found');
    $base = ['agency_id' => $r['agency_id'] ? (int)$r['agency_id'] : null, 'agency' => $r['nome'] ?? null];
    $folder = (string)$r['practice_code'] . ' ' . (string)$r['group_folder'];
    if (($r['channel'] ?? '') === 'direct' || preg_match('/-Drct\)/i', $folder)) {
        return ['level' => 'rack', 'markup' => null, 'label' => price_level_label('rack'), 'source' => 'direct'] + $base;
    }
    if (!empty($r['agency_id'])) return price_level_of_agency($db, (int)$r['agency_id']) + ['source' => 'agency'] + $base;
    return ['level' => 'sto', 'markup' => null, 'label' => price_level_label('sto'), 'source' => 'default'] + $base;
}

/** Round up to the next multiple of 5 USD (as the Calc prices). */
function price_level_roundup5(float $v): int {
    return (int)(ceil(round($v, 6) / 5) * 5);
}

/**
 * Price per person of one Calc pax sheet (calc_program_prices() row) at a level: rack = H9, sto = H10
 * (the prices typed in the Calc), stospec / custom = cost × (1 + markup) ÷ pax, rounded up to 5 USD.
 * Null when the sheet lacks what is needed.
 */
function price_level_sheet_price(array $sheet, string $level, ?float $custom = null, bool $grp = false): ?int {
    if ($level === 'rack') return $sheet['rack'] !== null ? (int)round($sheet['rack']) : null;
    if ($level === 'sto')  return $sheet['sto']  !== null ? (int)round($sheet['sto'])  : null;
    if (empty($sheet['cost']) || empty($sheet['pax_total'])) return null;
    return price_level_roundup5($sheet['cost'] * (1 + price_level_markup($level, $custom, $grp)) / $sheet['pax_total']);
}
