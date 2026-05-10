<?php
require_once 'config.php';
header('Content-Type: application/json');

function fail(string $msg, int $code = 400): never {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

// Auth
requireLogin();
$isStaff      = isLeadsRestricted();
$staffAgentId = $isStaff ? getStaffAgentId() : 0;

// Parse JSON body
$raw = file_get_contents('php://input');
$body = json_decode($raw, true);
if (!$body) fail('Invalid JSON body.');

// CSRF
if (session_status() === PHP_SESSION_NONE) session_start();
$expectedCsrf = $_SESSION['csrf_token'] ?? '';
if (empty($body['csrf']) || !hash_equals($expectedCsrf, $body['csrf'])) fail('Invalid CSRF token.', 403);

// Validate required fields
$requestId    = (int)($body['request_id']   ?? 0);
$customerName = trim($body['customer_name'] ?? '');
if (!$requestId || !$customerName) fail('Missing required fields.');

$db = db();

// Verify request exists and access is permitted
$rStmt = $db->prepare("SELECT id, agent_id, customer_name FROM requests WHERE id = ?");
$rStmt->execute([$requestId]);
$req = $rStmt->fetch();
if (!$req) fail('Request not found.', 404);
if ($isStaff && (int)$req['agent_id'] !== $staffAgentId) fail('Access denied.', 403);

// Quote number — use provided override or auto-assign
$quoteNumOverride = trim($body['quote_number'] ?? '');
if ($quoteNumOverride !== '' && preg_match('/^\d+$/', $quoteNumOverride)) {
    $chk = $db->prepare("SELECT id FROM quotes WHERE quote_number = ?");
    $chk->execute([$quoteNumOverride]);
    if ($chk->fetch()) fail('Quote number "' . $quoteNumOverride . '" is already in use. Please choose another number.');
    $nextNum = str_pad($quoteNumOverride, 2, '0', STR_PAD_LEFT);
} else {
    $nextNum = $db->query("
        SELECT LPAD(COALESCE(MAX(CAST(quote_number AS UNSIGNED)), 0) + 1, 2, '0')
        FROM quotes
    ")->fetchColumn();
}

// Sanitise and extract fields
$agentName     = substr(trim($body['agent_name']   ?? ''), 0, 150);
$agencyName    = substr(trim($body['agency_name']  ?? ''), 0, 150);
$startDate     = preg_match('/^\d{4}-\d{2}-\d{2}$/', $body['start_date'] ?? '') ? $body['start_date'] : null;
$adults        = max(0, (int)($body['adults']   ?? 0));
$teens         = max(0, (int)($body['teens']    ?? 0));
$children      = max(0, (int)($body['children'] ?? 0));
$program       = substr(trim($body['program']   ?? ''), 0, 100);
$markupType    = in_array($body['markup_type'] ?? '', ['standard','to','custom']) ? $body['markup_type'] : 'standard';
$markupPct     = min(max(0, (float)($body['markup_pct'] ?? 25)), 100);
$bankComm      = max(0, (float)($body['bank_commission'] ?? 100));
$totalCosts    = max(0, (float)($body['total_costs'] ?? 0));
$totalPrice    = max(0, (float)($body['total_price'] ?? 0));
$days          = $body['days'] ?? [];

// Validate pax
if ($adults + $teens + $children < 1) fail('At least 1 participant required.');

// Get agent_id from request
$agentId = (int)$req['agent_id'];

// Current user (created_by)
$createdBy = (int)($_SESSION['user_id'] ?? 0);

$db->beginTransaction();
try {
    // Insert quote
    $ins = $db->prepare("
        INSERT INTO quotes
          (quote_number, request_id, agent_id, customer_name, agent_name, agency_name,
           start_date, adults, teens, children, program, markup_type, markup_pct,
           bank_commission, total_costs, total_price, status, created_by)
        VALUES
          (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'draft',?)
    ");
    $ins->execute([
        $nextNum, $requestId, $agentId, $customerName, $agentName, $agencyName,
        $startDate, $adults, $teens, $children, $program, $markupType, $markupPct,
        $bankComm, $totalCosts, $totalPrice, $createdBy,
    ]);
    $quoteId = (int)$db->lastInsertId();

    $validJeep = ['none','half','full','double','contribution'];
    $validPark = ['none','tarangire','manyara','serengeti1','serengeti2','crater','custom'];
    $validType = ['pax','fixed'];

    // Insert safari-level fixed items (Emergency, Medivac, etc.)
    $insSafariStmt = $db->prepare("
        INSERT INTO quote_safari_items (quote_id, activity_rate_id, description, item_type, amount)
        VALUES (?,?,?,?,?)
    ");
    foreach (($body['safari_items'] ?? []) as $si) {
        $iType = in_array($si['item_type'] ?? '', $validType) ? $si['item_type'] : 'pax';
        $insSafariStmt->execute([
            $quoteId,
            (int)($si['activity_rate_id'] ?? 0) ?: null,
            substr(trim($si['description'] ?? ''), 0, 500),
            $iType,
            max(0, (float)($si['amount'] ?? 0)),
        ]);
    }

    // Insert days
    $insDayStmt = $db->prepare("
        INSERT INTO quote_days
          (quote_id, day_number, route, attraction, lodge, lodge_id, room_type_id,
           lodge_custom, jeep, jeep_count, jeep_rate_custom, drinks, park, park_custom,
           flight, flight_route_id, flight_custom,
           transfer_rate_id, transfer_custom, day_total)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    ");
    $insItemStmt = $db->prepare("
        INSERT INTO quote_day_items (quote_day_id, description, item_type, amount)
        VALUES (?,?,?,?)
    ");
    $insRoomStmt = $db->prepare("
        INSERT INTO quote_day_rooms (quote_day_id, room_type_id, room_type_name, qty, unit_price, total_price)
        VALUES (?,?,?,?,?,?)
    ");

    foreach ($days as $dayNum => $d) {
        $jeep = in_array($d['jeep'] ?? '', $validJeep) ? $d['jeep'] : 'full';
        $park = in_array($d['park'] ?? '', $validPark)  ? $d['park'] : 'none';

        $lodgeId2        = (int)($d['lodge_id']        ?? 0) ?: null;
        $flightRouteId   = (int)($d['flight_route_id'] ?? 0) ?: null;
        $transferRateId  = (int)($d['transfer_rate_id']?? 0) ?: null;
        $jeepRateCustom  = isset($d['jeep_rate_custom']) && $d['jeep_rate_custom'] !== null
                           ? max(0, (float)$d['jeep_rate_custom']) : null;
        $jeepCount       = isset($d['jeep_count']) && $d['jeep_count'] !== null
                           ? max(1, (int)$d['jeep_count']) : null;

        $insDayStmt->execute([
            $quoteId,
            $dayNum + 1,
            substr(trim($d['route']      ?? $d['location'] ?? ''), 0, 200),
            substr(trim($d['attraction'] ?? ''), 0, 200),
            substr(trim($d['lodge']      ?? ''), 0, 200),
            $lodgeId2,
            null,  // room_type_id legacy — now stored in quote_day_rooms
            max(0, (float)($d['lodge_custom']   ?? 0)),
            $jeep,
            $jeepCount,
            $jeepRateCustom,
            (int)($d['drinks'] ?? 1),
            $park,
            max(0, (float)($d['park_custom']    ?? 0)),
            'none',  // legacy flight column
            $flightRouteId,
            max(0, (float)($d['flight_custom']  ?? 0)),
            $transferRateId,
            max(0, (float)($d['transfer_custom']?? 0)),
            max(0, (float)($d['day_total']      ?? 0)),
        ]);
        $dayId = (int)$db->lastInsertId();

        // Insert room rows
        foreach (($d['rooms'] ?? []) as $rm) {
            $insRoomStmt->execute([
                $dayId,
                (int)($rm['room_type_id'] ?? 0) ?: null,
                substr(trim($rm['room_type_name'] ?? ''), 0, 200),
                max(1, (int)($rm['qty'] ?? 1)),
                max(0, (float)($rm['unit_price']  ?? 0)),
                max(0, (float)($rm['total_price'] ?? 0)),
            ]);
        }

        foreach (($d['items'] ?? []) as $item) {
            $iType = in_array($item['item_type'] ?? '', $validType) ? $item['item_type'] : 'fixed';
            $insItemStmt->execute([
                $dayId,
                substr(trim($item['description'] ?? ''), 0, 500),
                $iType,
                max(0, (float)($item['amount'] ?? 0)),
            ]);
        }
    }

    $db->commit();
    echo json_encode(['ok' => true, 'id' => $quoteId, 'quote_number' => $nextNum]);

} catch (Throwable $e) {
    $db->rollBack();
    fail('Database error: ' . $e->getMessage(), 500);
}
