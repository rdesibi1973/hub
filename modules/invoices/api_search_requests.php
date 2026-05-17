<?php
/**
 * api_search_requests.php
 * Search requests by customer_name or practice_code.
 * Called by the PDF invoice importer artifact.
 *
 * Auth: X-Hub-Token header (or api_key in query string) must match
 *       API_IMPORT_KEY defined in includes/config.php
 *
 * GET ?q=search_term
 * Returns JSON array of matching requests.
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Max-Age: 86400');
header('Access-Control-Allow-Headers: Content-Type, X-Hub-Token, Authorization');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';

// Auth
$token = $_GET['api_key'] ?? ($_SERVER['HTTP_X_HUB_TOKEN'] ?? '');
$validKey = defined('API_IMPORT_KEY') ? API_IMPORT_KEY : '';
if (!$validKey || $token !== $validKey) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$q = trim($_GET['q'] ?? '');
if (strlen($q) < 2) {
    echo json_encode([]);
    exit;
}

try {
    $like = '%' . $q . '%';
    $stmt = $pdo->prepare("
        SELECT r.id,
               r.practice_code,
               r.customer_name,
               r.date_received,
               r.status,
               r.source,
               r.destination,
               r.pax,
               a.name AS agent_name
        FROM   requests r
        LEFT JOIN agents a ON r.agent_id = a.id
        WHERE  r.customer_name LIKE :q1
            OR r.practice_code LIKE :q2
        ORDER BY r.date_received DESC
        LIMIT 25
    ");
    $stmt->execute([':q1' => $like, ':q2' => $like]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
