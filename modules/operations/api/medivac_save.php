<?php
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/auth.php';
require_permission('operations');

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['error' => 'POST only']); exit;
}

$body = json_decode(file_get_contents('php://input'), true);
if (!$body) {
    echo json_encode(['error' => 'Invalid JSON']); exit;
}

$travelers    = $body['travelers']    ?? [];
$groupName    = trim($body['group_name']    ?? '');
$tourAgent    = trim($body['tour_agent']    ?? '');
$coverageStart = $body['coverage_start'] ?? null;
$coverageEnd   = $body['coverage_end']   ?? null;
$sourceFile    = trim($body['source_file'] ?? '');
$skipDups      = !empty($body['skip_dups']);
$forceDups     = !empty($body['force_dups']);

if (!$groupName || empty($travelers)) {
    echo json_encode(['error' => 'Group name and at least one traveler are required']); exit;
}

$userId = current_user()['id'] ?? null;

// ── Validate coverage dates ───────────────────────────────────────────────────
$startSql = ($coverageStart && preg_match('/^\d{4}-\d{2}-\d{2}$/', $coverageStart)) ? $coverageStart : null;
$endSql   = ($coverageEnd   && preg_match('/^\d{4}-\d{2}-\d{2}$/', $coverageEnd))   ? $coverageEnd   : null;

// ── Duplicate check ───────────────────────────────────────────────────────────
$dupCheck = $pdo->prepare(
    'SELECT id, group_name, coverage_start FROM medivac_travelers
     WHERE LOWER(full_name) = LOWER(?) AND dob = ?'
);

$dups = [];
foreach ($travelers as $t) {
    $name = trim($t['full_name'] ?? '');
    $dob  = $t['dob'] ?? null;
    if (!$name || !$dob) continue;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob)) $dob = null;
    if (!$dob) continue;
    $dupCheck->execute([$name, $dob]);
    $found = $dupCheck->fetch();
    if ($found) {
        $dups[] = [
            'full_name'    => $name,
            'dob'          => $dob,
            'existing_id'  => $found['id'],
            'existing_group' => $found['group_name'],
            'existing_start' => $found['coverage_start'],
        ];
    }
}

// If dups found and not yet confirmed, return them for client review
if ($dups && !$skipDups && !$forceDups) {
    echo json_encode(['dup_warning' => true, 'dups' => $dups]);
    exit;
}

// ── Save ──────────────────────────────────────────────────────────────────────
$groupRef = uniqid('mv_', true);

$stmt = $pdo->prepare(
    'INSERT INTO medivac_travelers
     (group_ref, group_name, tour_agent, coverage_start, coverage_end,
      full_name, title, dob, passport_number, country,
      insurance_name, policy_number, source_file, created_by)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
);

// Build set of dup name+dob to skip
$dupKeys = [];
if ($skipDups) {
    foreach ($dups as $d) $dupKeys[strtolower($d['full_name']).'|'.$d['dob']] = true;
}

$saved = 0; $skipped = 0;
try {
    $pdo->beginTransaction();
    foreach ($travelers as $t) {
        $name = trim($t['full_name'] ?? '');
        if (!$name) continue;

        $dob = $t['dob'] ?? null;
        if ($dob && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob)) $dob = null;

        // Skip dups if requested
        if ($skipDups && $dob && isset($dupKeys[strtolower($name).'|'.$dob])) {
            $skipped++;
            continue;
        }

        $title    = in_array($t['title'] ?? '', ['MR','MRS','MS','']) ? ($t['title'] ?? '') : '';
        $passport = trim($t['passport_number'] ?? '');
        $country  = trim($t['country']  ?? '');

        $stmt->execute([
            $groupRef, $groupName, $tourAgent ?: null,
            $startSql, $endSql,
            $name, $title, $dob ?: null,
            $passport ?: null, $country ?: null,
            null, null,   // insurance_name, policy_number — filled later
            $sourceFile ?: null, $userId
        ]);
        $saved++;
    }
    $pdo->commit();
} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['error' => $e->getMessage()]); exit;
}

echo json_encode([
    'ok'        => true,
    'saved'     => $saved,
    'skipped'   => $skipped,
    'group_ref' => $groupRef,
    'group_name'=> $groupName,
]);
