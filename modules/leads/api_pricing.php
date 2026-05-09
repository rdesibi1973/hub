<?php
require_once 'config.php';
header('Content-Type: application/json');
requireLogin();

$db = db();

// ── Lodges ─────────────────────────────────────────────────────────────────────
$lodges = $db->query("SELECT id, name, location, default_meal_plan FROM lodges WHERE active=1 ORDER BY name")
             ->fetchAll(PDO::FETCH_ASSOC);

if (empty($lodges)) {
    echo json_encode(['ok' => true, 'lodges' => [], 'parks' => [], 'routes' => []]);
    exit;
}

$lodgeIds = array_map('intval', array_column($lodges, 'id'));
$in       = implode(',', array_fill(0, count($lodgeIds), '?'));

// Room types (bulk)
$stmt = $db->prepare("SELECT id, lodge_id, name, max_pax, sort_order FROM lodge_room_types WHERE lodge_id IN ($in) ORDER BY lodge_id, sort_order, name");
$stmt->execute($lodgeIds);
$allRoomTypes = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $rt) {
    $rt['id']       = (int)$rt['id'];
    $rt['lodge_id'] = (int)$rt['lodge_id'];
    $rt['max_pax']  = (int)$rt['max_pax'];
    $allRoomTypes[$rt['lodge_id']][] = $rt;
}

// Seasons (bulk)
$stmt = $db->prepare("SELECT id, lodge_id, name, sort_order FROM lodge_seasons WHERE lodge_id IN ($in) ORDER BY lodge_id, sort_order, id");
$stmt->execute($lodgeIds);
$allSeasons = [];
$allSeasonIds = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $s) {
    $s['id']       = (int)$s['id'];
    $s['lodge_id'] = (int)$s['lodge_id'];
    $allSeasons[$s['lodge_id']][] = $s;
    $allSeasonIds[] = $s['id'];
}

// Periods (bulk)
$allPeriods = [];
if ($allSeasonIds) {
    $in2  = implode(',', array_fill(0, count($allSeasonIds), '?'));
    $stmt = $db->prepare("SELECT id, season_id, year, start_mmdd, end_mmdd FROM lodge_season_periods WHERE season_id IN ($in2) ORDER BY season_id, year IS NULL DESC, start_mmdd");
    $stmt->execute($allSeasonIds);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $p['year'] = $p['year'] !== null ? (int)$p['year'] : null;
        $allPeriods[(int)$p['season_id']][] = $p;
    }
}

// Prices (bulk)
$allPrices = [];
$allRtIds  = [];
foreach ($allRoomTypes as $rows) {
    foreach ($rows as $rt) $allRtIds[] = $rt['id'];
}
if ($allRtIds && $allSeasonIds) {
    $in3  = implode(',', array_fill(0, count($allRtIds),    '?'));
    $in4  = implode(',', array_fill(0, count($allSeasonIds), '?'));
    $stmt = $db->prepare("SELECT room_type_id, season_id, meal_plan, price_basis, pax_1, pax_2, pax_3, pax_4, pax_5 FROM lodge_prices WHERE room_type_id IN ($in3) AND season_id IN ($in4)");
    $stmt->execute(array_merge($allRtIds, $allSeasonIds));
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
        // Cast numeric values
        for ($i = 1; $i <= 5; $i++) {
            $p["pax_$i"] = $p["pax_$i"] !== null ? (float)$p["pax_$i"] : null;
        }
        $allPrices[(int)$p['room_type_id']][(int)$p['season_id']] = $p;
    }
}

// Supplements (bulk)
$allSupplements = [];
$stmt = $db->prepare("SELECT lodge_id, name, start_mmdd, end_mmdd, year, amount_adult, amount_child, mandatory FROM lodge_supplements WHERE lodge_id IN ($in) ORDER BY lodge_id, start_mmdd");
$stmt->execute($lodgeIds);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $s) {
    $s['lodge_id']     = (int)$s['lodge_id'];
    $s['year']         = $s['year'] !== null ? (int)$s['year'] : null;
    $s['amount_adult'] = (float)$s['amount_adult'];
    $s['amount_child'] = (float)$s['amount_child'];
    $s['mandatory']    = (bool)$s['mandatory'];
    $allSupplements[$s['lodge_id']][] = $s;
}

// ── Assemble ───────────────────────────────────────────────────────────────────
foreach ($lodges as &$lodge) {
    $lid     = (int)$lodge['id'];
    $seasons = $allSeasons[$lid] ?? [];

    // Attach periods to seasons
    foreach ($seasons as &$s) {
        $s['periods'] = $allPeriods[$s['id']] ?? [];
    }
    unset($s);

    // Attach seasons+prices to room types
    $rts = $allRoomTypes[$lid] ?? [];
    foreach ($rts as &$rt) {
        $rtId      = $rt['id'];
        $rt['seasons'] = [];
        foreach ($seasons as $s) {
            $rt['seasons'][] = [
                'id'      => $s['id'],
                'name'    => $s['name'],
                'periods' => $s['periods'],
                'prices'  => $allPrices[$rtId][$s['id']] ?? null,
            ];
        }
    }
    unset($rt);

    $lodge['id']           = $lid;
    $lodge['room_types']   = $rts;
    $lodge['supplements']  = $allSupplements[$lid] ?? [];
}
unset($lodge);

// ── Parks ──────────────────────────────────────────────────────────────────────
$parks = $db->query("SELECT id, name, code FROM parks WHERE active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$parkIds = array_map('intval', array_column($parks, 'id'));
$parkFees = [];
if ($parkIds) {
    $in5  = implode(',', array_fill(0, count($parkIds), '?'));
    $stmt = $db->prepare("SELECT * FROM park_season_fees WHERE park_id IN ($in5) ORDER BY park_id, year IS NULL DESC, start_mmdd");
    $stmt->execute($parkIds);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $f) {
        $f['park_id']        = (int)$f['park_id'];
        $f['year']           = $f['year'] !== null ? (int)$f['year'] : null;
        $f['fee_nonresident']= (float)$f['fee_nonresident'];
        $f['fee_resident']   = (float)$f['fee_resident'];
        $f['fee_citizen']    = (float)$f['fee_citizen'];
        $f['fixed_fee']      = (float)$f['fixed_fee'];
        $parkFees[(int)$f['park_id']][] = $f;
    }
}
foreach ($parks as &$park) {
    $park['id']   = (int)$park['id'];
    $park['fees'] = $parkFees[$park['id']] ?? [];
}
unset($park);

// ── Flight routes ──────────────────────────────────────────────────────────────
$routes = $db->query("SELECT id, name, origin, destination FROM flight_routes WHERE active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$routeIds = array_map('intval', array_column($routes, 'id'));
$routePrices = [];
if ($routeIds) {
    $in6  = implode(',', array_fill(0, count($routeIds), '?'));
    $stmt = $db->prepare("SELECT * FROM flight_prices WHERE route_id IN ($in6) ORDER BY route_id, year IS NULL DESC, start_mmdd");
    $stmt->execute($routeIds);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $fp) {
        $fp['route_id']         = (int)$fp['route_id'];
        $fp['year']             = $fp['year'] !== null ? (int)$fp['year'] : null;
        $fp['price_per_person'] = (float)$fp['price_per_person'];
        $routePrices[(int)$fp['route_id']][] = $fp;
    }
}
foreach ($routes as &$route) {
    $route['id']     = (int)$route['id'];
    $route['prices'] = $routePrices[$route['id']] ?? [];
}
unset($route);

echo json_encode(['ok' => true, 'lodges' => $lodges, 'parks' => $parks, 'routes' => $routes]);
