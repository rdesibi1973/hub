<?php
/**
 * As Salaam Air 2026 — Flight rates import
 * Contract: 01 March 2026 – 28 February 2027
 *
 * Seasons:
 *   LOW      Mar–May  2026-03-01 → 2026-05-31
 *   HIGH     Jun–Dec  2026-06-01 → 2026-12-31
 *   SHOULDER Jan–Feb  2027-01-01 → 2027-02-28
 *
 * Rates are TOTAL (fare + $10 taxes) per pax.
 */

require_once dirname(__DIR__) . '/modules/leads/config.php';

$token = $_GET['token'] ?? '';
if ($token !== 'import-assalaam-2026') { http_response_code(403); die('Forbidden.'); }

$db  = db();
$dry = isset($_GET['dry']);

echo '<!doctype html><meta charset="utf-8"><pre style="font-family:monospace;font-size:.85rem;line-height:1.6">';
echo "As Salaam Air 2026 — Import" . ($dry ? " [DRY RUN]" : "") . "\n";
echo "Contract: 01 Mar 2026 – 28 Feb 2027\n\n";

// ── Seasons ───────────────────────────────────────────────────────────────────
// [label, valid_from, valid_to]
$seasons = [
    ['LOW',      '2026-03-01', '2026-05-31'],
    ['HIGH',     '2026-06-01', '2026-12-31'],
    ['SHOULDER', '2027-01-01', '2027-02-28'],
];

// ── Routes ────────────────────────────────────────────────────────────────────
// [route_name, origin, destination, rate_low, rate_shoulder, rate_high]
// All rates = fare + $10 taxes (TOTAL per pax)
$routes = [
    ['Zanzibar → Pemba',         'Zanzibar',      'Pemba',           85,  85,  85],
    ['Zanzibar → Dar es Salaam', 'Zanzibar',      'Dar es Salaam',   85,  85,  85],
    ['Zanzibar → Arusha',        'Zanzibar',      'Arusha',         110, 110, 110],
    ['Zanzibar → Kilimanjaro',   'Zanzibar',      'Kilimanjaro',    130, 130, 130],
    ['Zanzibar → Seronera',      'Zanzibar',      'Seronera',       180, 200, 220],
    ['Pemba → Zanzibar',         'Pemba',         'Zanzibar',        85,  85,  85],
    ['Pemba → Dar es Salaam',    'Pemba',         'Dar es Salaam',  130, 130, 130],
    ['Pemba → Kilimanjaro',      'Pemba',         'Kilimanjaro',    230, 230, 230],
    ['Arusha → Zanzibar',        'Arusha',        'Zanzibar',       190, 190, 190],
    ['Arusha → Pemba',           'Arusha',        'Pemba',          240, 250, 250],
    ['Arusha → Seronera',        'Arusha',        'Seronera',       160, 170, 170],
    ['Kilimanjaro → Zanzibar',   'Kilimanjaro',   'Zanzibar',       210, 210, 210],
    ['Seronera → Arusha',        'Seronera',      'Arusha',         190, 190, 190],
    ['Seronera → Zanzibar',      'Seronera',      'Zanzibar',       370, 370, 370],
    ['Seronera → Pemba',         'Seronera',      'Pemba',          440, 440, 440],
    ['Seronera → Dar es Salaam', 'Seronera',      'Dar es Salaam',  440, 440, 440],
    ['Dar es Salaam → Zanzibar', 'Dar es Salaam', 'Zanzibar',        85,  85,  85],
];

$airline = 'As Salaam Air';

// ── Delete existing 2026 rows for this airline ────────────────────────────────
$existing = $db->prepare("SELECT COUNT(*) FROM flight_routes WHERE airline = ? AND valid_from >= '2026-03-01'");
$existing->execute([$airline]);
$nExisting = (int)$existing->fetchColumn();

if ($nExisting > 0) {
    echo "⚠ Found $nExisting existing rows for $airline from 2026-03-01 onwards.\n";
    if (!$dry) {
        $db->prepare("DELETE FROM flight_routes WHERE airline = ? AND valid_from >= '2026-03-01'")->execute([$airline]);
        echo "  → Deleted.\n\n";
    } else {
        echo "  → Would be deleted (dry run).\n\n";
    }
}

// ── Insert ────────────────────────────────────────────────────────────────────
$stmt = $db->prepare("
    INSERT INTO flight_routes (route_name, origin, destination, airline, valid_from, valid_to, rate_pax, active)
    VALUES (?,?,?,?,?,?,?,1)
");

$count = 0;
$rateMap = [0 => 'LOW', 1 => 'SHOULDER', 2 => 'HIGH'];

foreach ($routes as [$name, $origin, $dest, $rateLow, $rateShoulder, $rateHigh]) {
    $ratesBySeason = ['LOW' => $rateLow, 'SHOULDER' => $rateShoulder, 'HIGH' => $rateHigh];
    foreach ($seasons as [$label, $from, $to]) {
        $rate = $ratesBySeason[$label];
        $rowName = $name . ' (' . $label . ')';
        printf("  %-45s  %-10s  $%d\n", $rowName, $from . ' → ' . $to, $rate);
        if (!$dry) {
            $stmt->execute([$rowName, $origin, $dest, $airline, $from, $to, $rate]);
        }
        $count++;
    }
    echo "\n";
}

echo "─────────────────────────────────────────────────\n";
echo ($dry ? "Would insert" : "Inserted") . " $count rows ($count = " . count($routes) . " routes × 3 seasons).\n\n";
if ($dry) {
    echo "Run without ?dry to execute.\n";
} else {
    echo "✓ Done. You can delete this file from the server.\n";
}
echo '</pre>';
