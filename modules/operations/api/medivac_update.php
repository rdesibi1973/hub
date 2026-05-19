<?php
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/auth.php';
require_permission('operations');

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['error' => 'POST only']); exit;
}

$body = json_decode(file_get_contents('php://input'), true);
$id   = (int)($body['id'] ?? 0);
if (!$id) { echo json_encode(['error' => 'Missing id']); exit; }

$full_name       = trim($body['full_name']       ?? '');
$title           = in_array($body['title'] ?? '', ['MR','MRS','MS','']) ? ($body['title'] ?? '') : '';
$dob             = $body['dob'] ?? null;
$passport_number = trim($body['passport_number'] ?? '');
$country         = trim($body['country']         ?? '');
$insurance_name  = trim($body['insurance_name']  ?? '');
$policy_number   = trim($body['policy_number']   ?? '');

if (!$dob || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob)) $dob = null;
if (!$full_name) { echo json_encode(['error' => 'Name is required']); exit; }

try {
    $pdo->prepare(
        'UPDATE medivac_travelers
         SET full_name=?, title=?, dob=?, passport_number=?, country=?,
             insurance_name=?, policy_number=?, updated_at=NOW()
         WHERE id=?'
    )->execute([$full_name, $title, $dob, $passport_number ?: null, $country ?: null,
                $insurance_name ?: null, $policy_number ?: null, $id]);

    echo json_encode(['ok' => true]);
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
