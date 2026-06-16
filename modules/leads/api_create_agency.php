<?php
// api_create_agency.php
// Adds a new agency to the agencies table.
// Method: POST  |  Header: X-Api-Key: <API_KEY>
// Body JSON: { "nome": "...", "short_name": "...", "type": "savannah|promoservice|lamprati" }

require_once 'config.php';
header('Content-Type: application/json');

// ── Auth ──────────────────────────────────────────────────────────────────────
if (($_SERVER['HTTP_X_API_KEY'] ?? '') !== API_KEY) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// ── Input ─────────────────────────────────────────────────────────────────────
$body      = json_decode(file_get_contents('php://input'), true) ?? [];
$nome      = trim($body['nome']       ?? '');
$shortName = trim($body['short_name'] ?? '');
$address   = trim($body['address']    ?? '');
$email     = trim($body['email']      ?? '');
$type      = trim($body['type']       ?? 'savannah');

if ($nome === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Agency name required']);
    exit;
}

// Whitelist type and set default
if (!in_array($type, ['savannah', 'promoservice', 'lamprati'], true)) {
    $type = 'savannah';
}

// ── Short Name Generation Logic ──────────────────────────────────────────────
// If short_name is not entered, autogenerate it
if ($shortName === '') {
    // 1. Derive base short_name from nome (CamelCase conversion)
    $shortName = preg_replace_callback(
        '/(?:^|\s+|-)(\S)/',
        fn($m) => strtoupper($m[1]),
        strtolower($nome)
    );
    $shortName = preg_replace('/[\s-]+/', '', $shortName);

    // 2. Append suffix based on type
    if ($type === 'promoservice') {
        $shortName .= '-PS';
    } elseif ($type === 'lamprati') {
        $shortName .= '-LAM';
    }
    // Savannah adds nothing[cite: 1]
}

// ── DB work ───────────────────────────────────────────────────────────────────
try {
    $db = db();

    // Duplicate check
    $check = $db->prepare('SELECT id FROM agencies WHERE LOWER(nome) = LOWER(?)');
    $check->execute([$nome]);
    if ($check->fetch()) {
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'message' => "Agency \"{$nome}\" already exists.",
        ]);
        exit;
    }

    // Insert
    $stmt = $db->prepare(
        'INSERT INTO agencies (nome, short_name, type, address, email) VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([$nome, $shortName, $type, $address ?: null, $email ?: null]);
    $newId = (int) $db->lastInsertId();

    echo json_encode([
        'success'    => true,
        'id'         => $newId,
        'nome'       => $nome,
        'short_name' => $shortName,
        'type'       => $type,
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'DB error: ' . $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}