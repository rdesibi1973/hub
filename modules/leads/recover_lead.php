<?php
/**
 * recover_lead.php — ONE-OFF recovery for a HubSpot lead whose Dropbox folder
 * was created but the DB record + CustomerInfo.txt never got written (network
 * drop during "Approve" at the Serengeti).
 *
 * It bypasses lead_staging / lead_processed entirely: it pulls the contact
 * straight from HubSpot by email, (re)writes CustomerInfo.txt into the existing
 * folder, and inserts the requests row only if it is missing.
 *
 * Idempotent & safe to re-run. DELETE THIS FILE after use.
 *
 *   cd <hub>/modules/leads
 *   php recover_lead.php                # dry-run: shows what was found from HubSpot
 *   php recover_lead.php --commit       # actually writes CustomerInfo.txt + DB row
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/dropbox_helper.php';
if (!defined('HS_INCLUDED')) define('HS_INCLUDED', true);
require_once __DIR__ . '/hubspot_sync.php';

// ── What we are recovering ───────────────────────────────────────────────────
$EMAIL       = 'polloni.marco@hotmail.com';
$AGENT_NAME  = 'Anderson';                       // direct channel → "(Anderson-Drct)"
$FOLDER_NAME = 'MarcoPolloni(Anderson-Drct)';    // exact existing Dropbox folder / practice_code
$CUST_NAME   = 'Marco Polloni';                  // display name for the hub record

$COMMIT = in_array('--commit', $argv, true);
$db     = db();

echo "=== recover_lead.php " . ($COMMIT ? '[COMMIT]' : '[DRY-RUN]') . " ===\n";
echo "Email:  $EMAIL\nFolder: $FOLDER_NAME\n\n";

// ── 1. Fetch the contact from HubSpot by email ───────────────────────────────
$props = [
    'firstname','lastname','email','phone','mobilephone',
    'createdate','recent_conversion_event_name','recent_conversion_date',
    'contact_name','whatsapp_phone_number',
    'ibotdestinations','pax','ibotperiod','activities','duration','accommodation_level',
    'nome_cognome','numero_whatsapp','ibotdestinazioni','ibotperiodo',
    'attivita','durata','livello_sistemazione',
    'numero_di_persone','periodo','message','destinazioni','destinations',
    'number_of_people','period','other_info_and_requests','surname','name',
];
$search = [
    'filterGroups' => [[ 'filters' => [[
        'propertyName' => 'email', 'operator' => 'EQ', 'value' => $EMAIL,
    ]] ]],
    'properties' => $props,
    'limit'      => 1,
];
$data     = hs_curl('https://api.hubapi.com/crm/v3/objects/contacts/search', $search);
$contacts = $data['results'] ?? [];
if (!$contacts) {
    fwrite(STDERR, "ERROR: no HubSpot contact found for $EMAIL\n");
    exit(1);
}
$lead = hs_contact_to_lead($contacts[0]);
if (!$lead) {
    fwrite(STDERR, "ERROR: could not normalise HubSpot contact (name/email empty?)\n");
    exit(1);
}

$hsName         = $lead['customer_name'];
$email          = $lead['email'];
$whatsapp       = $lead['phone'];
$initialRequest = $lead['initial_request'];
$pax            = $lead['pax'];
$destination    = $lead['destination'];
$period         = $lead['period'];
$source         = $lead['source'];

echo "HubSpot contact id : {$contacts[0]['id']}\n";
echo "Name (HubSpot)     : $hsName\n";
echo "Email              : $email\n";
echo "WhatsApp/Phone     : $whatsapp\n";
echo "Pax / Dest / Period: " . ($pax ?? '—') . " / " . ($destination ?: '—') . " / " . ($period ?: '—') . "\n";
echo "----- initial_request -----\n$initialRequest\n---------------------------\n\n";

// ── 2. Resolve agent id ──────────────────────────────────────────────────────
$agStmt = $db->prepare("SELECT id, name FROM agents WHERE REPLACE(name,' ','') = ? OR name = ? LIMIT 1");
$agStmt->execute([str_replace(' ', '', $AGENT_NAME), $AGENT_NAME]);
$agent = $agStmt->fetch();
if (!$agent) {
    fwrite(STDERR, "ERROR: agent '$AGENT_NAME' not found in agents table.\n");
    exit(1);
}
$agentId = (int)$agent['id'];
echo "Agent: {$agent['name']} (id=$agentId)\n";

// ── 3. Does the request already exist? ───────────────────────────────────────
$exists = $db->prepare("SELECT id FROM requests WHERE practice_code = ? LIMIT 1");
$exists->execute([$FOLDER_NAME]);
$existingId = $exists->fetchColumn();
echo "Existing hub record: " . ($existingId ? "#$existingId" : "none") . "\n\n";

if (!$COMMIT) {
    echo "DRY-RUN — nothing written. Re-run with --commit to apply.\n";
    exit(0);
}

// ── 4. (Re)create Dropbox folder + subfolders + CustomerInfo.txt ─────────────
$dropboxPath   = DROPBOX_BASE_PATH . '/' . $FOLDER_NAME;
$dropboxWebUrl = 'https://www.dropbox.com/home' . $dropboxPath;

$token = dropbox_get_access_token();
dropbox_create_folder($token, $dropboxPath);           // ignores conflict if it exists
foreach (['bookings','complain','flights','guestcomments','insurance',
          'IntFlights','invoices','mails','old','passports','vouchers'] as $sub) {
    try { dropbox_create_folder($token, $dropboxPath . '/' . $sub); }
    catch (RuntimeException $e) { echo "  subfolder $sub: {$e->getMessage()}\n"; }
}

$waDigits = preg_replace('/\D/', '', $whatsapp);
$txt =
    "CUSTOMER:\r\n\r\n"
  . "Name:        " . $hsName . "\r\n"
  . "Email:       " . $email . "\r\n"
  . "WhatsApp:    " . $whatsapp . "\r\n\r\n\r\n"
  . "REQUEST DETAILS:\r\n\r\n"
  . $initialRequest . "\r\n\r\n\r\n"
  . "WHATSAPP link\r\n"
  . "Add phone number with international code without + or spaces and use the following link to chat with customer on whatsapp web\r\n"
  . "https://web.whatsapp.com/send?phone=" . $waDigits . "\r\n\r\n"
  . "CUSTOMERS FULL NAMES:\r\n\r\n\r\n\r\n"
  . "ARRIVAL/DEPARTURE DETAILS - FLIGHTS:\r\n\r\n\r\n\r\n\r\n\r\n"
  . "DIETARY RESTRICTIONS:\r\n\r\n\r\n\r\n"
  . "NOTES:\r\n\r\n";
dropbox_upload_text($token, $dropboxPath . '/CustomerInfo.txt', $txt);
echo "Dropbox: folder ensured + CustomerInfo.txt written.\n";

// ── 5. Insert the hub record (only if missing) ───────────────────────────────
if ($existingId) {
    echo "Hub record already present (#$existingId) — not inserting a duplicate.\n";
} else {
    $db->prepare(
        'INSERT INTO requests
            (date_received, customer_name, email, whatsapp, source, agent_id,
             destination, period, initial_request, status, pax, practice_code, dropbox_url, created_at)
         VALUES (CURDATE(), ?, ?, ?, ?, ?, ?, ?, ?, "Inquiry", ?, ?, ?, NOW())'
    )->execute([
        $CUST_NAME, $email ?: null, $whatsapp ?: null, $source, $agentId,
        $destination ?: null, $period ?: null, $initialRequest ?: null,
        $pax, $FOLDER_NAME, $dropboxWebUrl,
    ]);
    $newId = (int)$db->lastInsertId();
    echo "Hub record created: #$newId\n";
}

echo "\nDONE. Verify in the hub, then DELETE this file (recover_lead.php).\n";
