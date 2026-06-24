<?php
// Update driver/guide for all movements sharing the same client_name and old driver value.
ob_start();
date_default_timezone_set('Africa/Dar_es_Salaam');
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db.php';
start_session();
header('Content-Type: application/json');

function out($ok, $data = array()) {
    ob_clean();
    echo json_encode(array_merge(array('ok' => $ok), $data));
    exit;
}

if (!isset($_SESSION['user_id']) || !$_SESSION['user_id']) {
    out(false, array('error' => 'Not authenticated'));
}

$client_name = trim($_POST['client_name'] ?? '');
$old_driver  = trim($_POST['old_driver']  ?? '');
$new_driver  = trim($_POST['new_driver']  ?? '');

if ($client_name === '' || $old_driver === '' || $new_driver === '') {
    out(false, array('error' => 'Missing parameters'));
}
if ($old_driver === $new_driver) {
    out(true, array('updated' => 0));
}

$stmt = $pdo->prepare(
    "UPDATE movements SET driver = ? WHERE client_name = ? AND driver = ?"
);
$stmt->execute(array($new_driver, $client_name, $old_driver));
out(true, array('updated' => $stmt->rowCount()));
