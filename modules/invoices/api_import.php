<?php
/**
 * api_import.php
 * Creates an invoice (+ line items + optional payment) from the PDF importer artifact.
 *
 * Auth: X-Hub-Token header must match API_IMPORT_KEY in includes/config.php
 *
 * POST JSON body:
 * {
 *   invoice_number_mode : "original" | "generate"  (default: "original")
 *   invoice_number      : string   (used when mode=original)
 *   request_id          : int|null
 *   bill_to_name        : string
 *   bill_to_address     : string|null
 *   bill_to_source_type : "agency"|"customer"|"manual"|null
 *   bill_to_source_id   : int|null
 *   issuer              : string
 *   currency            : "USD"|"EUR"
 *   issue_date          : "YYYY-MM-DD"
 *   due_date            : "YYYY-MM-DD"|null
 *   terms               : string
 *   notes               : string|null
 *   terms_conditions    : string|null
 *   items               : [{description, quantity, unit_price, line_total}]
 *   payment_amount      : float|null   (if > 0, inserts an invoice_payments record)
 *   payment_date        : "YYYY-MM-DD"|null
 *   payment_method      : "Bank Transfer"|"Credit Card"|"Cash"|"Other"
 *   payment_reference   : string|null
 * }
 *
 * Returns: {success: true, invoice_id: int, invoice_number: string}
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Max-Age: 86400');
header('Access-Control-Allow-Headers: Content-Type, X-Hub-Token, Authorization');
header('Access-Control-Allow-Methods: POST, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/config.php';   // inv_import()

// Auth
$token = $_GET['api_key'] ?? ($_SERVER['HTTP_X_HUB_TOKEN'] ?? '');
$validKey = defined('API_IMPORT_KEY') ? API_IMPORT_KEY : '';
if (!$validKey || $token !== $validKey) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
if (!$body) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON body']);
    exit;
}

// ── Create (includes/invoice_service.php, shared with the Agent API) ─────────
try {
    $res = inv_import($pdo, $body);
    echo json_encode([
        'success'        => true,
        'invoice_id'     => $res['invoice_id'],
        'invoice_number' => $res['invoice_number'],
    ]);
} catch (InvalidArgumentException $e) {
    http_response_code(422);
    echo json_encode(['error' => $e->getMessage()]);
} catch (DomainException $e) {
    http_response_code(409);
    echo json_encode(['error' => $e->getMessage()]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
