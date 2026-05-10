<?php
/**
 * Import: Farm of Dreams Lodge – 2026 Rates
 *
 * Run once from browser or CLI:
 *   php setup/import_lodge_farm_of_dreams_2026.php
 *
 * Safe to re-run: uses INSERT IGNORE / ON DUPLICATE KEY UPDATE.
 * Rack rates (non-STO) are not stored in lodge_prices (no matching meal plan);
 * they are listed in comments below for reference.
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

/** @var PDO $pdo */

// ── Helper ────────────────────────────────────────────────────────────────────

function out(string $msg): void
{
    $prefix = PHP_SAPI === 'cli' ? '' : '<br>';
    echo $prefix . $msg . "\n";
}

// ── Lodge definition ──────────────────────────────────────────────────────────

$lodge = [
    'name'              => 'Farm of Dreams Lodge',
    'location'          => 'Karatu, Arusha – Tanzania',
    'default_meal_plan' => 'FB',
    'active'            => 1,
    'notes'             => implode("\n", [
        'Rates 2026 per room, inclusive of VAT 18% and Bed night levy.',
        'Non-commissionable. STO rates only (Rack rates excluded from DB).',
        'Rack rates HIGH: Single $164 | Dbl $238 | Triple $333 | Fam4 $354 | Fam5 $406',
        'Rack rates LOW:  Single $135 | Dbl $203 | Triple $285 | Fam4 $310 | Fam5 $368',
        'Extra meals: Breakfast $15 | Lunch $25 | Lunch/Dinner $25',
        'Driver guide: 1 free lunchbox; additional driver/translator TSH 10,000 each.',
        'Children: 0-5 FOC | 5-12 sharing 50% adult | 13+ full adult.',
        'Children not sharing room: 75% adult rate.',
        'Check-in 14:00 | Check-out 10:00 | Late departure: $30 adult, $15 child.',
        'Cancellation: 7 days prior 12:00; no-show = 1 night charge.',
    ]),
];

// ── Seasons ───────────────────────────────────────────────────────────────────

$seasons = [
    [
        'name'       => 'High Season 2026',
        'sort_order' => 1,
        'periods'    => [
            ['year' => 2026, 'start' => '01-01', 'end' => '03-31'],
            ['year' => 2026, 'start' => '06-01', 'end' => '10-31'],
            ['year' => 2026, 'start' => '12-01', 'end' => '12-31'],
        ],
    ],
    [
        'name'       => 'Low Season 2026',
        'sort_order' => 2,
        'periods'    => [
            ['year' => 2026, 'start' => '04-01', 'end' => '05-31'],
            ['year' => 2026, 'start' => '11-01', 'end' => '11-30'],
        ],
    ],
];

// ── Room types ────────────────────────────────────────────────────────────────

$roomTypes = [
    ['name' => 'Single',                      'max_pax' => 1, 'sort_order' => 1],
    ['name' => 'Double / Twin',               'max_pax' => 2, 'sort_order' => 2],
    ['name' => 'Triple',                      'max_pax' => 3, 'sort_order' => 3],
    ['name' => 'Family Room *4 (2ADL+2KIDS)', 'max_pax' => 4, 'sort_order' => 4],
    ['name' => 'Family Room *5 (2ADL+3KIDS)', 'max_pax' => 5, 'sort_order' => 5],
];

// ── Prices (USD, per room) ─────────────────────────────────────────────────────
// Format: prices[season_name][room_name][meal_plan] = price

$prices = [
    'High Season 2026' => [
        'Single'                      => ['FB' => 135, 'HB' => 125],
        'Double / Twin'               => ['FB' => 195, 'HB' => 185],
        'Triple'                      => ['FB' => 273, 'HB' => 258],
        'Family Room *4 (2ADL+2KIDS)' => ['FB' => 290, 'HB' => 270],
        'Family Room *5 (2ADL+3KIDS)' => ['FB' => 333, 'HB' => 310],
    ],
    'Low Season 2026' => [
        'Single'                      => ['FB' => 115, 'HB' => 105],
        'Double / Twin'               => ['FB' => 165, 'HB' => 155],
        'Triple'                      => ['FB' => 225, 'HB' => 215],
        'Family Room *4 (2ADL+2KIDS)' => ['FB' => 248, 'HB' => 228],
        'Family Room *5 (2ADL+3KIDS)' => ['FB' => 294, 'HB' => 269],
    ],
];

// ── Holiday supplements ───────────────────────────────────────────────────────

$supplements = [
    [
        'name'         => 'Holiday Supplement (Xmas)',
        'start_mmdd'   => '12-24',
        'end_mmdd'     => '12-31',
        'year'         => 2026,
        'amount_adult' => 20,
        'amount_child' => 10,
        'mandatory'    => 1,
    ],
    [
        'name'         => 'Holiday Supplement (New Year)',
        'start_mmdd'   => '01-01',
        'end_mmdd'     => '01-01',
        'year'         => 2026,
        'amount_adult' => 20,
        'amount_child' => 10,
        'mandatory'    => 1,
    ],
];

// ═══════════════════════════════════════════════════════════════════════════════
// IMPORT
// ═══════════════════════════════════════════════════════════════════════════════

$pdo->beginTransaction();

try {

    // ── 1. Lodge ──────────────────────────────────────────────────────────────

    $stmt = $pdo->prepare("SELECT id FROM lodges WHERE name = ? LIMIT 1");
    $stmt->execute([$lodge['name']]);
    $lodgeId = $stmt->fetchColumn();

    if ($lodgeId) {
        $pdo->prepare("UPDATE lodges SET location=?, default_meal_plan=?, active=?, notes=? WHERE id=?")
            ->execute([$lodge['location'], $lodge['default_meal_plan'], $lodge['active'], $lodge['notes'], $lodgeId]);
        out("Lodge updated (id=$lodgeId): {$lodge['name']}");
    } else {
        $pdo->prepare("INSERT INTO lodges (name, location, default_meal_plan, active, notes) VALUES (?,?,?,?,?)")
            ->execute([$lodge['name'], $lodge['location'], $lodge['default_meal_plan'], $lodge['active'], $lodge['notes']]);
        $lodgeId = (int)$pdo->lastInsertId();
        out("Lodge inserted (id=$lodgeId): {$lodge['name']}");
    }

    // ── 2. Seasons & periods ──────────────────────────────────────────────────

    $seasonIds = [];   // season_name => season_id

    foreach ($seasons as $s) {
        $stmt = $pdo->prepare("SELECT id FROM lodge_seasons WHERE lodge_id=? AND name=? LIMIT 1");
        $stmt->execute([$lodgeId, $s['name']]);
        $sid = $stmt->fetchColumn();

        if ($sid) {
            $pdo->prepare("UPDATE lodge_seasons SET sort_order=? WHERE id=?")
                ->execute([$s['sort_order'], $sid]);
            out("  Season exists (id=$sid): {$s['name']}");
        } else {
            $pdo->prepare("INSERT INTO lodge_seasons (lodge_id, name, sort_order) VALUES (?,?,?)")
                ->execute([$lodgeId, $s['name'], $s['sort_order']]);
            $sid = (int)$pdo->lastInsertId();
            out("  Season inserted (id=$sid): {$s['name']}");
        }

        $seasonIds[$s['name']] = $sid;

        foreach ($s['periods'] as $p) {
            $check = $pdo->prepare(
                "SELECT id FROM lodge_season_periods WHERE season_id=? AND year=? AND start_mmdd=? AND end_mmdd=? LIMIT 1"
            );
            $check->execute([$sid, $p['year'], $p['start'], $p['end']]);
            if (!$check->fetchColumn()) {
                $pdo->prepare("INSERT INTO lodge_season_periods (season_id, year, start_mmdd, end_mmdd) VALUES (?,?,?,?)")
                    ->execute([$sid, $p['year'], $p['start'], $p['end']]);
                out("    Period inserted: {$p['start']} – {$p['end']} ({$p['year']})");
            } else {
                out("    Period exists: {$p['start']} – {$p['end']} ({$p['year']})");
            }
        }
    }

    // ── 3. Room types ─────────────────────────────────────────────────────────

    $roomTypeIds = [];   // room_name => rt_id

    foreach ($roomTypes as $rt) {
        $stmt = $pdo->prepare("SELECT id FROM lodge_room_types WHERE lodge_id=? AND name=? LIMIT 1");
        $stmt->execute([$lodgeId, $rt['name']]);
        $rtId = $stmt->fetchColumn();

        if ($rtId) {
            $pdo->prepare("UPDATE lodge_room_types SET max_pax=?, sort_order=? WHERE id=?")
                ->execute([$rt['max_pax'], $rt['sort_order'], $rtId]);
            out("  Room type exists (id=$rtId): {$rt['name']}");
        } else {
            $pdo->prepare("INSERT INTO lodge_room_types (lodge_id, name, max_pax, sort_order) VALUES (?,?,?,?)")
                ->execute([$lodgeId, $rt['name'], $rt['max_pax'], $rt['sort_order']]);
            $rtId = (int)$pdo->lastInsertId();
            out("  Room type inserted (id=$rtId): {$rt['name']}");
        }

        $roomTypeIds[$rt['name']] = $rtId;
    }

    // ── 4. Prices ─────────────────────────────────────────────────────────────

    foreach ($prices as $seasonName => $roomRows) {
        $sid = $seasonIds[$seasonName] ?? null;
        if (!$sid) { out("  WARNING: season not found: $seasonName"); continue; }

        foreach ($roomRows as $roomName => $mealPlans) {
            $rtId = $roomTypeIds[$roomName] ?? null;
            if (!$rtId) { out("  WARNING: room type not found: $roomName"); continue; }

            foreach ($mealPlans as $meal => $price) {
                $pdo->prepare("
                    INSERT INTO lodge_prices
                        (room_type_id, season_id, meal_plan, price_basis, pax_1)
                    VALUES (?, ?, ?, 'per_room', ?)
                    ON DUPLICATE KEY UPDATE
                        meal_plan=VALUES(meal_plan),
                        price_basis=VALUES(price_basis),
                        pax_1=VALUES(pax_1),
                        pax_2=NULL, pax_3=NULL, pax_4=NULL, pax_5=NULL
                ")->execute([$rtId, $sid, $meal, $price]);
                out("  Price set: $seasonName | $roomName | $meal = \$$price/room");
            }
        }
    }

    // ── 5. Supplements ────────────────────────────────────────────────────────

    foreach ($supplements as $sup) {
        $check = $pdo->prepare(
            "SELECT id FROM lodge_supplements WHERE lodge_id=? AND name=? AND start_mmdd=? AND end_mmdd=? AND year=? LIMIT 1"
        );
        $check->execute([$lodgeId, $sup['name'], $sup['start_mmdd'], $sup['end_mmdd'], $sup['year']]);

        if (!$check->fetchColumn()) {
            $pdo->prepare("
                INSERT INTO lodge_supplements (lodge_id, name, start_mmdd, end_mmdd, year, amount_adult, amount_child, mandatory)
                VALUES (?,?,?,?,?,?,?,?)
            ")->execute([
                $lodgeId, $sup['name'], $sup['start_mmdd'], $sup['end_mmdd'],
                $sup['year'], $sup['amount_adult'], $sup['amount_child'], $sup['mandatory'],
            ]);
            out("  Supplement inserted: {$sup['name']} ({$sup['start_mmdd']}–{$sup['end_mmdd']})");
        } else {
            out("  Supplement exists: {$sup['name']}");
        }
    }

    $pdo->commit();
    out("\nDONE – Farm of Dreams Lodge (id=$lodgeId) imported successfully.");

} catch (Throwable $e) {
    $pdo->rollBack();
    out("\nERROR – rollback. " . $e->getMessage());
    exit(1);
}
