<?php
/**
 * ajax_save_bill_to.php
 * Saves a new customer (type: individual/company) or agency (type: agency)
 * and returns the new record id for selection in invoice_add.php
 */
require_once 'config.php';
header('Content-Type: application/json');

$body = json_decode(file_get_contents('php://input'), true) ?? [];
$name    = trim($body['name']    ?? '');
$type    = trim($body['type']    ?? 'individual');
$address = trim($body['address'] ?? '');
$city    = trim($body['city']    ?? '');
$country = trim($body['country'] ?? '');
$email   = trim($body['email']   ?? '');

if ($name === '') {
    echo json_encode(['success' => false, 'message' => 'Name is required.']);
    exit;
}

$db = db();

if ($type === 'agency') {
    // Save into agencies table
    $db->prepare(
        "INSERT INTO agencies (nome, attiva, created_at) VALUES (?, 1, NOW())"
    )->execute([$name]);
    $id = (int)$db->lastInsertId();
    echo json_encode(['success' => true, 'id' => $id, 'source_type' => 'agency']);
} else {
    // Save into customers table
    $db->prepare(
        "INSERT INTO customers (name, type, address, city, country, email, active, created_at)
         VALUES (?, ?, ?, ?, ?, ?, 1, NOW())"
    )->execute([$name, $type, $address ?: null, $city ?: null, $country ?: null, $email ?: null]);
    $id = (int)$db->lastInsertId();
    echo json_encode(['success' => true, 'id' => $id, 'source_type' => 'customer']);
}
