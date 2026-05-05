<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
echo "Step 1: PHP running OK\n";
echo "SAPI: " . php_sapi_name() . "\n";
define('HUBSPOT_TOKEN', 'pat-na1-a84e3308-ece4-49d2-8207-137c768befd5');
echo "Step 2: Token defined\n";

$ch = curl_init('https://api.hubapi.com/crm/v3/objects/contacts?limit=1');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . HUBSPOT_TOKEN],
]);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
echo "Step 3: HubSpot API = HTTP $code\n";

try {
    $db = new PDO('mysql:host=localhost;dbname=savannp5_savannah_leads;charset=utf8mb4',
        'savannp5_rdesibi', 'Savannah2026',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    echo "Step 4: DB connected OK\n";
    $count = $db->query("SELECT COUNT(*) FROM requests")->fetchColumn();
    echo "Step 5: requests table has $count rows\n";
} catch (PDOException $e) {
    echo "Step 4 FAILED: " . $e->getMessage() . "\n";
}
echo "Done.\n";
