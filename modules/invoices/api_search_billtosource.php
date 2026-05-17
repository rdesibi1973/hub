<?php
/**
 * api_search_billtosource.php
 * Returns merged list of agencies and customers for the Bill To selector.
 * Optionally filtered by ?q= search term.
 *
 * Auth: X-Hub-Token header must match API_IMPORT_KEY in config.php
 *
 * GET ?q=optional_filter
 * Returns JSON array: [{id, name, source_type, address}]
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

try {
    if ($q !== '') {
        $like = '%' . $q . '%';
        $stmt = $pdo->prepare("
            SELECT id, nome AS name, 'agency' AS source_type, COALESCE(address,'') AS address
            FROM   agencies
            WHERE  attiva = 1 AND nome LIKE :q
            UNION ALL
            SELECT id, name, 'customer' AS source_type,
                   COALESCE(CONCAT_WS(', ', NULLIF(address,''), NULLIF(city,''), NULLIF(country,'')), '') AS address
            FROM   customers
            WHERE  active = 1 AND name LIKE :q2
            ORDER  BY name ASC
            LIMIT  30
        ");
        $stmt->execute([':q' => $like, ':q2' => $like]);
    } else {
        // Return all agencies (customers list would be too large unfiltered)
        $stmt = $pdo->query("
            SELECT id, nome AS name, 'agency' AS source_type, COALESCE(address,'') AS address
            FROM   agencies
            WHERE  attiva = 1
            ORDER  BY nome ASC
        ");
    }
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
