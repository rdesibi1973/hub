<?php
require_once 'config.php';
require_once 'dropbox_helper.php';
require_once 'notifications.php';

$currentUser = current_user();
if (!in_array($currentUser['role_name'] ?? '', ['admin','manager'])) {
    flash('Access denied.', 'error');
    header('Location: requests.php'); exit;
}

$action    = $_POST['action']     ?? '';
$stagingId = (int)($_POST['staging_id'] ?? 0);
$db        = db();

// ─────────────────────────────────────────────────────────────────────────────
// HubSpot sync actions — do not need a staging_id, return JSON directly
// ─────────────────────────────────────────────────────────────────────────────

if ($action === 'hubspot_preview') {
    header('Content-Type: application/json');
    define('HS_INCLUDED', true);
    require_once __DIR__ . '/hubspot_sync.php';

    $days  = max(1, min(30, (int)($_POST['days'] ?? 1)));
    $since = (time() - $days * 86400) * 1000;

    try {
        $leads = hs_fetch_all($since);
    } catch (RuntimeException $e) {
        echo json_encode(['error' => $e->getMessage()]);
        exit;
    }

    // Get already-staged hubspot_ids so we can exclude them from preview
    $stagedIds = array_flip(
        $db->query("SELECT hubspot_id FROM lead_staging WHERE hubspot_id IS NOT NULL")
           ->fetchAll(PDO::FETCH_COLUMN)
    );

    $preview = [];
    foreach ($leads as $lead) {
        if (isset($stagedIds[$lead['hubspot_id']])) continue;
        $preview[] = [
            'hubspot_id'    => $lead['hubspot_id'],
            'source'        => $lead['source'],
            'customer_name' => $lead['customer_name'],
            'email'         => $lead['email'],
            'phone'         => $lead['phone'],
            'pax'           => $lead['pax'],
            'period'        => $lead['period'],
            'destination'   => $lead['destination'],
            'date'          => $lead['date_created_ms']
                                ? date('d M H:i', (int)($lead['date_created_ms'] / 1000))
                                : '—',
        ];
    }

    echo json_encode(['leads' => $preview, 'total' => count($preview)]);
    exit;
}

if ($action === 'hubspot_import') {
    header('Content-Type: application/json');
    define('HS_INCLUDED', true);
    require_once __DIR__ . '/hubspot_sync.php';

    $days        = max(1, min(30, (int)($_POST['days'] ?? 1)));
    $since       = (time() - $days * 86400) * 1000;
    $selectedIds = json_decode($_POST['selected'] ?? '[]', true);
    if (!is_array($selectedIds)) $selectedIds = [];
    $importAll   = empty($selectedIds);

    try {
        $leads = hs_fetch_all($since);
    } catch (RuntimeException $e) {
        echo json_encode(['error' => $e->getMessage()]);
        exit;
    }

    hs_ensure_table($db);

    $results = [];
    foreach ($leads as $lead) {
        if (!$importAll && !in_array($lead['hubspot_id'], $selectedIds, true)) continue;
        $result    = hs_insert_lead($db, $lead);
        $results[] = [
            'hubspot_id' => $lead['hubspot_id'],
            'name'       => $lead['customer_name'],
            'status'     => $result['status'],
            'message'    => $result['message'],
        ];
    }

    $staged  = count(array_filter($results, fn($r) => $r['status'] === 'staged'));
    $skipped = count(array_filter($results, fn($r) => $r['status'] === 'skipped'));
    echo json_encode(['results' => $results, 'staged' => $staged, 'skipped' => $skipped]);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────

if (!$stagingId) { flash('Invalid request.','error'); header('Location: staging.php'); exit; }

$lead = $db->prepare("SELECT * FROM lead_staging WHERE id = ? LIMIT 1");
$lead->execute([$stagingId]);
$lead = $lead->fetch();
if (!$lead) { flash('Lead not found in staging.','error'); header('Location: staging.php'); exit; }

// ─────────────────────────────────────────────────────────────────────────────
if ($action === 'approve') {

    $agentId    = (int)($_POST['agent_id']    ?? 0);
    $doNotify   = !empty($_POST['notify_agent']); // checkbox
    if (!$agentId) { flash('Please select an agent.','error'); header('Location: staging.php'); exit; }

    $dest = trim($_POST['destination'] ?? '') ?: $lead['destination'];

    // Get agent
    $agent = $db->prepare("SELECT * FROM agents WHERE id = ? LIMIT 1");
    $agent->execute([$agentId]);
    $agent = $agent->fetch();
    if (!$agent) { flash('Agent not found.','error'); header('Location: staging.php'); exit; }

    // Build folder name — consistent with api_create_request.php
    function toCamelCaseSt(string $name): string {
        $name = trim($name);
        if (strpos($name, ' ') === false && strpos($name, '-') === false) return $name;
        return implode('', array_map('ucfirst', array_map('mb_strtolower',
            preg_split('/[\s\-]+/', $name))));
    }
    $agentName  = str_replace(' ', '', $agent['name']); // e.g. "RobertoCapri"
    $folderName = toCamelCaseSt($lead['customer_name']) . "({$agentName}-Drct)";
    $folderPath = DROPBOX_BASE_PATH . '/' . $folderName;

    // Create Dropbox folder — block on conflict (folder already exists)
    try {
        $token = dropbox_get_access_token();
        dropbox_create_folder($token, $folderPath, true); // throwOnConflict = true

        // Subfolders
        foreach (['bookings','complain','flights','guestcomments','insurance',
                  'IntFlights','invoices','mails','old','passports','vouchers'] as $sub) {
            try { dropbox_create_folder($token, $folderPath . '/' . $sub); }
            catch (RuntimeException $e) { error_log("Subfolder $sub error: " . $e->getMessage()); }
        }

        // CustomerInfo.txt
        $initReq = $lead['initial_request'] ?? '';
        $txtContent =
            "REQUEST DETAILS:\r\n\r\n"
          . $initReq . "\r\n\r\n\r\n"
          . "WHATSAPP link\r\n"
          . "Add phone number with international code without + or spaces and use the following link to chat with customer on whatsapp web\r\n"
          . "https://web.whatsapp.com/send?phone=\r\n\r\n"
          . "CUSTOMERS FULL NAMES:\r\n\r\n\r\n\r\n"
          . "ARRIVAL/DEPARTURE DETAILS - FLIGHTS:\r\n\r\n\r\n\r\n\r\n\r\n"
          . "DIETARY RESTRICTIONS:\r\n\r\n\r\n\r\n"
          . "NOTES:\r\n\r\n";
        dropbox_upload_text($token, $folderPath . '/CustomerInfo.txt', $txtContent);

    } catch (RuntimeException $e) {
        $msg = $e->getMessage();
        if (strpos($msg, 'already exists') !== false) {
            flash("La cartella Dropbox esiste già: <strong>{$folderName}</strong><br>Controlla se è un duplicato prima di procedere.", 'error');
        } else {
            flash("Errore Dropbox: {$msg}", 'error');
        }
        header('Location: staging.php'); exit;
    }

    $dropboxWebUrl = 'https://www.dropbox.com/home' . $folderPath;

    $db->prepare("
        INSERT INTO requests
            (date_received, customer_name, email, source, agent_id,
             destination, period, pax, status,
             initial_request, notes, practice_code, dropbox_url, created_at)
        VALUES (?,?,?,?,?,?,?,?,'Inquiry',?,?,?,?,NOW())
    ")->execute([
        $lead['date_received'],
        $lead['customer_name'],
        $lead['email']          ?: null,
        $lead['source'],
        $agentId,
        $dest                   ?: null,
        $lead['period']         ?: null,
        $lead['pax'],
        $lead['initial_request']?: null,
        $lead['notes']          ?: null,
        $folderName,
        $dropboxWebUrl,
    ]);

    $newId = $db->lastInsertId();
    $db->prepare("DELETE FROM lead_staging WHERE id = ?")->execute([$stagingId]);

    // ── Agent notification ────────────────────────────────────────────────
    $notif = notify_agent_new_request(
        $db, $agentId, (int)($currentUser['id'] ?? 0),
        (int)$newId, $lead['customer_name'], $folderName, $doNotify
    );

    flash("Lead approvato — Request #{$newId} creata. Cartella Dropbox: <strong>{$folderName}</strong>"
        . ($notif['sent'] ? " — ✉ Notifica inviata all'agente." : ''));
    if ($notif['error']) flash('⚠ ' . htmlspecialchars($notif['error']), 'error');

    header("Location: request_view.php?id=$newId"); exit;

// ─────────────────────────────────────────────────────────────────────────────
} elseif ($action === 'merge') {

    $targetId = (int)($_POST['target_request_id'] ?? 0);
    if (!$targetId) { flash('Please enter a valid Request ID.','error'); header('Location: staging.php'); exit; }

    $target = $db->prepare("SELECT * FROM requests WHERE id = ? LIMIT 1");
    $target->execute([$targetId]);
    $target = $target->fetch();
    if (!$target) { flash("Request #$targetId not found.",'error'); header('Location: staging.php'); exit; }

    // Append this lead's initial request to the target's notes
    $append  = "\n\n--- Merged from HubSpot (ID: {$lead['hubspot_id']}) ---\n";
    $append .= $lead['initial_request'] ?? '';
    $newNotes = ($target['notes'] ?? '') . $append;

    $db->prepare("UPDATE requests SET notes = ? WHERE id = ?")->execute([$newNotes, $targetId]);
    $db->prepare("DELETE FROM lead_staging WHERE id = ?")->execute([$stagingId]);
    flash("Lead merged into Request #{$targetId}.");
    header("Location: request_view.php?id=$targetId"); exit;

// ─────────────────────────────────────────────────────────────────────────────
} elseif ($action === 'dismiss') {

    $db->prepare("DELETE FROM lead_staging WHERE id = ?")->execute([$stagingId]);
    flash('Lead dismissed.', 'success');
    header('Location: staging.php'); exit;

} else {
    flash('Unknown action.', 'error');
    header('Location: staging.php'); exit;
}
