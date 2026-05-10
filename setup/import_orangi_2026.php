<?php
/**
 * The Orangi Collection — Lodge rates import
 * Contract: STO 2026-27  (Pure African Safaris T Ltd)
 *
 * Lodges:
 *   1. Arusha Explorers Lodge          (HB)
 *   2. Ngorongoro Marera Mountain View Lodge (FB)
 *   3. Serengeti Kifaru Tented Lodge   (FB)
 *   4. Serengeti Orangi River Luxury Lodge   (AI)
 *
 * Seasons:
 *   LOW   16 Mar 2026 – 30 Jun 2026
 *   HIGH   1 Jul 2026 – 15 Mar 2027
 *
 * All prices per room total (price_basis = per_room).
 * Token: import-orangi-2026   |   Dry run: add ?dry
 */

require_once dirname(__DIR__) . '/modules/leads/config.php';

$token = $_GET['token'] ?? '';
if ($token !== 'import-orangi-2026') { http_response_code(403); die('Forbidden.'); }

$db  = db();
$dry = isset($_GET['dry']);

echo '<!doctype html><meta charset="utf-8"><pre style="font-family:monospace;font-size:.85rem;line-height:1.7">';
echo "The Orangi Collection 2026-27 — Import" . ($dry ? " [DRY RUN]" : "") . "\n";
echo "Contract: STO — Pure African Safaris (T) Ltd\n\n";

// ── Lodge definitions ─────────────────────────────────────────────────────────
//
// seasons[]:
//   name, periods[], prices{}
//   periods: [year, start_mmdd, end_mmdd]  (year = int or null for any year)
//   prices:  room_type_name => [meal_plan => amount]
//
// The price is stored in pax_N where N = max_pax of the room type.

$lodgesDef = [

    // ── 1. Arusha Explorers Lodge ─────────────────────────────────────────────
    [
        'name'      => 'Arusha Explorers Lodge',
        'location'  => 'Arusha',
        'meal_plan' => 'HB',
        'notes'     => 'The Orangi Collection · STO 2026-27',
        'room_types' => [
            ['name' => 'Single',      'max_pax' => 1, 'sort_order' => 1],
            ['name' => 'Double/Twin', 'max_pax' => 2, 'sort_order' => 2],
            ['name' => 'Triple',      'max_pax' => 3, 'sort_order' => 3],
            ['name' => 'Family',      'max_pax' => 4, 'sort_order' => 4],
        ],
        'seasons' => [
            [
                'name'       => 'Low Season',
                'sort_order' => 1,
                'periods'    => [
                    [2026, '03-16', '06-30'],
                ],
                'prices' => [
                    'Single'      => ['HB' => 120],
                    'Double/Twin' => ['HB' => 200],
                    'Triple'      => ['HB' => 270],
                    'Family'      => ['HB' => 320],
                ],
            ],
            [
                'name'       => 'High Season',
                'sort_order' => 2,
                'periods'    => [
                    [2026, '07-01', '12-31'],
                    [2027, '01-01', '03-15'],
                ],
                'prices' => [
                    'Single'      => ['HB' => 120],
                    'Double/Twin' => ['HB' => 200],
                    'Triple'      => ['HB' => 270],
                    'Family'      => ['HB' => 320],
                ],
            ],
        ],
    ],

    // ── 2. Ngorongoro Marera Mountain View Lodge ──────────────────────────────
    [
        'name'      => 'Ngorongoro Marera Mountain View Lodge',
        'location'  => 'Ngorongoro',
        'meal_plan' => 'FB',
        'notes'     => 'The Orangi Collection · STO 2026-27',
        'room_types' => [
            ['name' => 'Single',      'max_pax' => 1, 'sort_order' => 1],
            ['name' => 'Double/Twin', 'max_pax' => 2, 'sort_order' => 2],
            ['name' => 'Triple',      'max_pax' => 3, 'sort_order' => 3],
            ['name' => 'Family',      'max_pax' => 4, 'sort_order' => 4],
        ],
        'seasons' => [
            [
                'name'       => 'Low Season',
                'sort_order' => 1,
                'periods'    => [
                    [2026, '03-16', '06-30'],
                ],
                'prices' => [
                    'Single'      => ['FB' => 171],
                    'Double/Twin' => ['FB' => 275],
                    'Triple'      => ['FB' => 363],
                    'Family'      => ['FB' => 462],
                ],
            ],
            [
                'name'       => 'High Season',
                'sort_order' => 2,
                'periods'    => [
                    [2026, '07-01', '12-31'],
                    [2027, '01-01', '03-15'],
                ],
                'prices' => [
                    'Single'      => ['FB' => 182],
                    'Double/Twin' => ['FB' => 292],
                    'Triple'      => ['FB' => 385],
                    'Family'      => ['FB' => 495],
                ],
            ],
        ],
    ],

    // ── 3. Serengeti Kifaru Tented Lodge ──────────────────────────────────────
    [
        'name'      => 'Serengeti Kifaru Tented Lodge',
        'location'  => 'Serengeti',
        'meal_plan' => 'FB',
        'notes'     => 'The Orangi Collection · STO 2026-27',
        'room_types' => [
            ['name' => 'Single',      'max_pax' => 1, 'sort_order' => 1],
            ['name' => 'Double/Twin', 'max_pax' => 2, 'sort_order' => 2],
            ['name' => 'Triple',      'max_pax' => 3, 'sort_order' => 3],
            ['name' => 'Family',      'max_pax' => 4, 'sort_order' => 4],
        ],
        'seasons' => [
            [
                'name'       => 'Low Season',
                'sort_order' => 1,
                'periods'    => [
                    [2026, '03-16', '06-30'],
                ],
                'prices' => [
                    'Single'      => ['FB' => 225],
                    'Double/Twin' => ['FB' => 352],
                    'Triple'      => ['FB' => 495],
                    'Family'      => ['FB' => 660],
                ],
            ],
            [
                'name'       => 'High Season',
                'sort_order' => 2,
                'periods'    => [
                    [2026, '07-01', '12-31'],
                    [2027, '01-01', '03-15'],
                ],
                'prices' => [
                    'Single'      => ['FB' => 250],
                    'Double/Twin' => ['FB' => 400],
                    'Triple'      => ['FB' => 570],
                    'Family'      => ['FB' => 740],
                ],
            ],
        ],
    ],

    // ── 4. Serengeti Orangi River Luxury Lodge ────────────────────────────────
    [
        'name'      => 'Serengeti Orangi River Luxury Lodge',
        'location'  => 'Serengeti',
        'meal_plan' => 'AI',
        'notes'     => 'The Orangi Collection · STO 2026-27 · All Inclusive',
        'room_types' => [
            ['name' => 'Single',      'max_pax' => 1, 'sort_order' => 1],
            ['name' => 'Double/Twin', 'max_pax' => 2, 'sort_order' => 2],
            ['name' => 'Triple',      'max_pax' => 3, 'sort_order' => 3],
            ['name' => 'Quadruple',   'max_pax' => 4, 'sort_order' => 4],
        ],
        'seasons' => [
            [
                'name'       => 'Low Season',
                'sort_order' => 1,
                'periods'    => [
                    [2026, '03-16', '06-30'],
                ],
                'prices' => [
                    'Single'      => ['AI' =>  350],
                    'Double/Twin' => ['AI' =>  600],
                    'Triple'      => ['AI' =>  825],
                    'Quadruple'   => ['AI' => 1000],
                ],
            ],
            [
                'name'       => 'High Season',
                'sort_order' => 2,
                'periods'    => [
                    [2026, '07-01', '12-31'],
                    [2027, '01-01', '03-15'],
                ],
                'prices' => [
                    'Single'      => ['AI' =>  430],
                    'Double/Twin' => ['AI' =>  750],
                    'Triple'      => ['AI' => 1050],
                    'Quadruple'   => ['AI' => 1300],
                ],
            ],
        ],
    ],

];

// ── Helpers ───────────────────────────────────────────────────────────────────
$totalLodges = 0;
$totalRows   = 0;

foreach ($lodgesDef as $ld) {
    echo "━━━ {$ld['name']} ({$ld['meal_plan']}) ━━━\n";

    // Check existing lodge
    $existing = $db->prepare("SELECT id FROM lodges WHERE name = ?");
    $existing->execute([$ld['name']]);
    $existingRow = $existing->fetch();

    if ($existingRow) {
        $lodgeId = (int)$existingRow['id'];
        echo "  ⚠ Lodge already exists (id=$lodgeId) — replacing seasons & prices.\n";
        if (!$dry) {
            // Delete prices → periods → seasons → room types (in dependency order)
            $sids = $db->prepare("SELECT id FROM lodge_seasons WHERE lodge_id = ?");
            $sids->execute([$lodgeId]);
            foreach ($sids->fetchAll() as $s) {
                $db->prepare("DELETE FROM lodge_prices WHERE season_id = ?")->execute([$s['id']]);
                $db->prepare("DELETE FROM lodge_season_periods WHERE season_id = ?")->execute([$s['id']]);
            }
            $db->prepare("DELETE FROM lodge_seasons WHERE lodge_id = ?")->execute([$lodgeId]);
            $db->prepare("DELETE FROM lodge_room_types WHERE lodge_id = ?")->execute([$lodgeId]);
            $db->prepare("UPDATE lodges SET location=?, default_meal_plan=?, notes=?, active=1 WHERE id=?")
               ->execute([$ld['location'], $ld['meal_plan'], $ld['notes'] ?? '', $lodgeId]);
        }
    } else {
        echo "  + New lodge\n";
        if (!$dry) {
            $db->prepare("INSERT INTO lodges (name, location, default_meal_plan, notes, active) VALUES (?,?,?,?,1)")
               ->execute([$ld['name'], $ld['location'], $ld['meal_plan'], $ld['notes'] ?? '']);
            $lodgeId = (int)$db->lastInsertId();
        } else {
            $lodgeId = 0; // placeholder for dry run
        }
    }

    // Room types
    $rtMap = []; // room_type_name => id
    foreach ($ld['room_types'] as $rt) {
        printf("  Room type: %-18s  max_pax=%d\n", $rt['name'], $rt['max_pax']);
        if (!$dry) {
            $db->prepare("INSERT INTO lodge_room_types (lodge_id, name, max_pax, sort_order) VALUES (?,?,?,?)")
               ->execute([$lodgeId, $rt['name'], $rt['max_pax'], $rt['sort_order']]);
            $rtMap[$rt['name']] = ['id' => (int)$db->lastInsertId(), 'max_pax' => $rt['max_pax']];
        } else {
            $rtMap[$rt['name']] = ['id' => 0, 'max_pax' => $rt['max_pax']];
        }
    }

    // Seasons, periods, prices
    foreach ($ld['seasons'] as $season) {
        echo "\n  Season: {$season['name']}\n";
        if (!$dry) {
            $db->prepare("INSERT INTO lodge_seasons (lodge_id, name, sort_order) VALUES (?,?,?)")
               ->execute([$lodgeId, $season['name'], $season['sort_order']]);
            $seasonId = (int)$db->lastInsertId();
        } else {
            $seasonId = 0;
        }

        // Periods
        foreach ($season['periods'] as [$year, $start, $end]) {
            printf("    Period: year=%s  %s → %s\n", $year ?? 'any', $start, $end);
            if (!$dry) {
                $db->prepare("INSERT INTO lodge_season_periods (season_id, year, start_mmdd, end_mmdd) VALUES (?,?,?,?)")
                   ->execute([$seasonId, $year, $start, $end]);
            }
        }

        // Prices (one row per room type × meal plan)
        foreach ($season['prices'] as $rtName => $mealPrices) {
            $rtInfo = $rtMap[$rtName] ?? null;
            if (!$rtInfo) { echo "    ✗ Room type '$rtName' not found — skipped.\n"; continue; }
            $paxCol = 'pax_' . min($rtInfo['max_pax'], 5);

            foreach ($mealPrices as $mealPlan => $amount) {
                printf("    Price: %-18s  %s  %s = $%d\n", $rtName, $mealPlan, $paxCol, $amount);
                if (!$dry) {
                    $db->prepare("
                        INSERT INTO lodge_prices (room_type_id, season_id, meal_plan, price_basis, $paxCol)
                        VALUES (?,?,?,'per_person',?)
                        ON DUPLICATE KEY UPDATE $paxCol = VALUES($paxCol)
                    ")->execute([$rtInfo['id'], $seasonId, $mealPlan, $amount]);
                }
                $totalRows++;
            }
        }
    }

    $totalLodges++;
    echo "\n";
}

echo "─────────────────────────────────────────────────\n";
echo ($dry ? "Would import" : "Imported") . " $totalLodges lodges, $totalRows price rows.\n\n";
if ($dry) {
    echo "Run without ?dry to execute.\n";
} else {
    echo "✓ Done. You can delete this file from the server.\n";
}
echo '</pre>';
