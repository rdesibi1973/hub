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
header('Access-Control-Allow-Headers: Content-Type, X-Hub-Token');
header('Access-Control-Allow-Methods: POST, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/config.php';   // generate_invoice_number, recalculate_invoice, sync_request_value

// Auth
$token = $_SERVER['HTTP_X_HUB_TOKEN'] ?? '';
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

// ── Validate required fields ──────────────────────────────────────────────────
$errors = [];
$billToName = trim($body['bill_to_name'] ?? '');
$issuer     = $body['issuer'] ?? INV_ISSUERS[0];
$currency   = $body['currency'] ?? 'USD';
$issueDate  = $body['issue_date'] ?? '';

if (!$billToName)                       $errors[] = 'bill_to_name is required.';
if (!in_array($issuer, INV_ISSUERS))    $errors[] = 'Invalid issuer.';
if (!in_array($currency, INV_CURRENCIES)) $errors[] = 'Invalid currency.';
if (!$issueDate)                        $errors[] = 'issue_date is required.';

$items = $body['items'] ?? [];
if (empty($items))                      $errors[] = 'At least one item is required.';

if ($errors) {
    http_response_code(422);
    echo json_encode(['error' => implode(' ', $errors)]);
    exit;
}

// ── Build invoice number ──────────────────────────────────────────────────────
$numberMode = $body['invoice_number_mode'] ?? 'original';
if ($numberMode === 'generate') {
    $invNum = generate_invoice_number($pdo, $issuer);
} else {
    $invNum = trim($body['invoice_number'] ?? '');
    if (!$invNum) $invNum = generate_invoice_number($pdo, $issuer);
}

// ── Insert ────────────────────────────────────────────────────────────────────
try {
    $pdo->beginTransaction();

    $reqId      = (int)($body['request_id']       ?? 0) ?: null;
    $customerId = (int)($body['bill_to_source_id'] ?? 0) ?: null;
    // If bill_to is an agency, customer_id stays null (agencies are not in customers table)
    if (($body['bill_to_source_type'] ?? '') === 'agency') {
        $customerId = null;
    }

    $pdo->prepare("
        INSERT INTO invoices
            (invoice_number, request_id, customer_id, bill_to_name, bill_to_address,
             issuer, currency, issue_date, due_date, terms, notes, terms_conditions,
             status, created_by)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,'New',NULL)
    ")->execute([
        $invNum,
        $reqId,
        $customerId,
        $billToName,
        $body['bill_to_address'] ?: null,
        $issuer,
        $currency,
        $issueDate,
        $body['due_date'] ?: null,
        $body['terms'] ?? 'Due on Receipt',
        $body['notes'] ?: INV_DEFAULT_NOTES,
        $body['terms_conditions'] ?: INV_DEFAULT_TC,
    ]);

    $invId = (int)$pdo->lastInsertId();

    // ── Line items ────────────────────────────────────────────────────────────
    $sort = 0;
    $itemStmt = $pdo->prepare("
        INSERT INTO invoice_items (invoice_id, sort_order, description, quantity, unit_price, line_total)
        VALUES (?,?,?,?,?,?)
    ");
    foreach ($items as $item) {
        $desc  = trim($item['description'] ?? '');
        if (!$desc) continue;
        $qty   = round((float)($item['quantity']   ?? 1), 2);
        $price = round((float)($item['unit_price'] ?? 0), 2);
        $total = round((float)($item['line_total'] ?? $qty * $price), 2);
        $itemStmt->execute([$invId, $sort++, $desc, $qty, $price, $total]);
    }

    // ── Optional payment ──────────────────────────────────────────────────────
    $payAmount = round((float)($body['payment_amount'] ?? 0), 2);
    if ($payAmount > 0) {
        $payDate   = $body['payment_date']      ?? $issueDate;
        $payMethod = $body['payment_method']    ?? 'Bank Transfer';
        $payRef    = $body['payment_reference'] ?? null;
        if (!in_array($payMethod, ['Bank Transfer','Credit Card','Cash','Other'])) {
            $payMethod = 'Bank Transfer';
        }
        $pdo->prepare("
            INSERT INTO invoice_payments (invoice_id, payment_date, amount, method, reference, notes)
            VALUES (?,?,?,?,?,?)
        ")->execute([
            $invId,
            $payDate,
            $payAmount,
            $payMethod,
            $payRef,
            'Imported from Zoho / PDF',
        ]);
    }

    // ── Recalculate totals & sync request ─────────────────────────────────────
    recalculate_invoice($pdo, $invId);
    if ($reqId) sync_request_value($pdo, $invId);

    $pdo->commit();

    echo json_encode([
        'success'        => true,
        'invoice_id'     => $invId,
        'invoice_number' => $invNum,
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
