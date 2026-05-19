<?php
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/auth.php';
require_permission('operations');

header('Content-Type: application/json');

$from   = $_GET['from']   ?? null;
$to     = $_GET['to']     ?? null;
$search = trim($_GET['q'] ?? '');

$where  = [];
$params = [];

if ($from && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    $where[] = 'coverage_start >= ?'; $params[] = $from;
}
if ($to && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    $where[] = 'coverage_start <= ?'; $params[] = $to;
}
if ($search) {
    $where[] = '(LOWER(full_name) LIKE ? OR LOWER(group_name) LIKE ?)';
    $params[] = '%'.strtolower($search).'%';
    $params[] = '%'.strtolower($search).'%';
}

$sql = 'SELECT * FROM medivac_travelers';
if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
$sql .= ' ORDER BY coverage_start DESC, group_ref, full_name';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Group by group_ref
$groups = [];
foreach ($rows as $r) {
    $ref = $r['group_ref'];
    if (!isset($groups[$ref])) {
        $groups[$ref] = [
            'group_ref'      => $ref,
            'group_name'     => $r['group_name'],
            'tour_agent'     => $r['tour_agent'],
            'coverage_start' => $r['coverage_start'],
            'coverage_end'   => $r['coverage_end'],
            'source_file'    => $r['source_file'],
            'created_at'     => $r['created_at'],
            'travelers'      => [],
        ];
    }
    $groups[$ref]['travelers'][] = [
        'id'             => $r['id'],
        'full_name'      => $r['full_name'],
        'title'          => $r['title'],
        'dob'            => $r['dob'],
        'passport_number'=> $r['passport_number'],
        'country'        => $r['country'],
        'insurance_name' => $r['insurance_name'],
        'policy_number'  => $r['policy_number'],
    ];
}

echo json_encode(['ok' => true, 'groups' => array_values($groups)]);
