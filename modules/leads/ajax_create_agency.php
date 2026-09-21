<?php
/**
 * ajax_create_agency.php
 *
 * Session-authenticated "quick add" for an agency, used by the inline
 * "➕ Add Agency" button on request_add.php. Unlike api_create_agency.php
 * (which needs the X-Api-Key server key), this trusts the logged-in Hub
 * session, so no secret is exposed to the browser.
 *
 * POST (form-urlencoded): nome, short_name (optional), type (savannah|promoservice|lamprati)
 * Returns JSON: { success, id, nome, short_name }  (on duplicate: success=false + the existing id)
 */
require_once 'config.php';
header('Content-Type: application/json');
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$nome  = trim($_POST['nome']       ?? '');
$short = trim($_POST['short_name'] ?? '');
$type  = $_POST['type']            ?? 'savannah';

if ($nome === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Agency name is required.']);
    exit;
}
if (!in_array($type, ['savannah', 'promoservice', 'lamprati'], true)) {
    $type = 'savannah';
}

// Short code: auto-generate from the name when blank, then apply the type suffix
// (stripping any existing one first so we never double it).
if ($short === '') {
    $short = preg_replace_callback('/(?:^|\s+|-)(\S)/', fn($m) => strtoupper($m[1]), strtolower($nome));
    $short = preg_replace('/[\s-]+/', '', $short);
}
$short = preg_replace('/-PS$|-LAM$/', '', $short);
if     ($type === 'promoservice') $short .= '-PS';
elseif ($type === 'lamprati')     $short .= '-LAM';

try {
    $db = db();

    // Duplicate name → hand back the existing row so the caller can select it.
    $chk = $db->prepare('SELECT id, nome, short_name FROM agencies WHERE LOWER(nome) = LOWER(?) LIMIT 1');
    $chk->execute([$nome]);
    if ($row = $chk->fetch(PDO::FETCH_ASSOC)) {
        http_response_code(409);
        echo json_encode([
            'success'    => false,
            'message'    => 'Agency "' . $nome . '" already exists — selected it.',
            'id'         => (int)$row['id'],
            'nome'       => $row['nome'],
            'short_name' => $row['short_name'] ?: $row['nome'],
        ]);
        exit;
    }

    $db->prepare('INSERT INTO agencies (nome, short_name, type, attiva) VALUES (?, ?, ?, 1)')
       ->execute([$nome, $short, $type]);
    $newId = (int)$db->lastInsertId();

    echo json_encode([
        'success'    => true,
        'id'         => $newId,
        'nome'       => $nome,
        'short_name' => $short,
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error while adding the agency.']);
}
