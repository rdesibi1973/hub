<?php
/**
 * notifications.php
 *
 * notify_agent_new_request()
 *  Sends an email to the assigned agent when a request is created by a different user.
 *
 * @param  PDO    $db
 * @param  int    $assignedAgentId   Agent the request is assigned to
 * @param  int    $creatorUserId     user.id of who created (0 = unknown/system)
 * @param  int    $requestId         Newly inserted request ID
 * @param  string $customerName
 * @param  string $practiceCode      Dropbox folder name
 * @param  bool   $doNotify          Whether the "notify agent" checkbox was checked
 *
 * @return array  ['sent' => bool, 'error' => string|null]
 *                'error' is non-null when notify was requested but agent has no email.
 */
function notify_agent_new_request(
    PDO    $db,
    int    $assignedAgentId,
    int    $creatorUserId,
    int    $requestId,
    string $customerName,
    string $practiceCode,
    bool   $doNotify
): array {

    if (!$doNotify) return ['sent' => false, 'error' => null];

    // Resolve creator's agent_id so we can skip self-assignment silently
    $creatorAgentId = null;
    if ($creatorUserId > 0) {
        $s = $db->prepare('SELECT agent_id FROM users WHERE id = ? LIMIT 1');
        $s->execute([$creatorUserId]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        $creatorAgentId = $row ? (int)$row['agent_id'] : null;
    }

    // Assigning to yourself — no notification needed
    if ($creatorAgentId !== null && $creatorAgentId === $assignedAgentId) {
        return ['sent' => false, 'error' => null];
    }

    // Get agent name + email (email lives in users table, not agents)
    $s = $db->prepare(
        'SELECT a.name, u.email
         FROM agents a
         LEFT JOIN users u ON u.agent_id = a.id AND u.is_active = 1
         WHERE a.id = ?
         LIMIT 1'
    );
    $s->execute([$assignedAgentId]);
    $agent = $s->fetch(PDO::FETCH_ASSOC);

    // Notification was requested but agent has no email — surface as error
    if (!$agent || empty($agent['email'])) {
        $name = $agent['name'] ?? 'Unknown agent';
        error_log("[notify] SKIP — agent_id={$assignedAgentId} ({$name}) has no email. creator_user={$creatorUserId}");
        return [
            'sent'  => false,
            'error' => "Agent \"{$name}\" has no email address configured — notification not sent.",
        ];
    }

    $hubUrl = 'https://hub.savannahexplorers.com/modules/leads/request_view.php?id=' . $requestId;

    // Fetch full request details
    $s = $db->prepare(
        'SELECT source, destination, period, pax, email, whatsapp, initial_request
         FROM requests WHERE id = ? LIMIT 1'
    );
    $s->execute([$requestId]);
    $req = $s->fetch(PDO::FETCH_ASSOC) ?: [];

    $source      = $req['source']      ?? '';
    $destination = $req['destination'] ?? '';
    $period      = $req['period']      ?? '';
    $pax         = $req['pax']         ?? '';
    $custEmail   = $req['email']       ?? '';
    $whatsapp    = $req['whatsapp']    ?? '';
    $initReq     = $req['initial_request'] ?? '';

    // Extract specific fields from initial_request lines
    $formName   = '';
    $activities = '';
    $duration   = '';
    $accomLevel = '';
    $otherInfo  = '';
    $inOther    = false;
    foreach (explode("\n", $initReq) as $line) {
        $t = trim($line);
        if      (str_starts_with($t, 'Form: '))               { $formName   = substr($t, 6); }
        elseif  (str_starts_with($t, 'Activities: '))         { $activities = substr($t, 12); }
        elseif  (str_starts_with($t, 'Duration: '))           { $duration   = substr($t, 10); }
        elseif  (str_starts_with($t, 'Accommodation Level: ')){ $accomLevel = substr($t, 21); }
        elseif  ($t === 'Other Info/Requests:')                { $inOther = true; }
        elseif  ($inOther && $t !== '')                       { $otherInfo .= ($otherInfo ? "\n             " : '') . $t; }
    }

    // Build email — show only fields that have a value
    $div  = "────────────────────────────────\n";
    $subject = "New request assigned to you - {$customerName}";
    $body =
        "Hi {$agent['name']},\n\n"
      . "A new request has been created and assigned to you.\n\n"
      . $div
      . "Customer:    {$customerName}\n"
      . ($custEmail   ? "Email:       {$custEmail}\n"    : '')
      . ($whatsapp    ? "WhatsApp:    {$whatsapp}\n"     : '')
      . $div
      . "Request ID:  #{$requestId}\n"
      . ($source      ? "Source:      {$source}\n"       : '')
      . ($formName    ? "Form:        {$formName}\n"     : '')
      . ($destination ? "Destination: {$destination}\n"  : '')
      . ($period      ? "Period:      {$period}\n"       : '')
      . ($pax         ? "Pax:         {$pax}\n"          : '')
      . ($activities  ? "Activities:  {$activities}\n"   : '')
      . ($duration    ? "Duration:    {$duration}\n"     : '')
      . ($accomLevel  ? "Accom.:      {$accomLevel}\n"   : '')
      . ($otherInfo   ? "Notes:       {$otherInfo}\n"    : '')
      . $div
      . "View in Hub:\n{$hubUrl}\n\n"
      . "- Savannah Explorers Hub";

    $headers =
        "From: noreply@savannahexplorers.com\r\n"
      . "Content-Type: text/plain; charset=UTF-8\r\n";

    @mail($agent['email'], $subject, $body, $headers);
    error_log("[notify] mail() sent to: {$agent['email']} for request #{$requestId} ({$customerName})");
    return ['sent' => true, 'error' => null];
}

/**
 * format_duplicate_lead_block()
 *  Plain-text summary of a lead_staging row, shared by the duplicate-lead
 *  email and the CustomerInfo.txt append.
 */
function format_duplicate_lead_block(array $lead): string {
    return
        "Name:        {$lead['customer_name']}\n"
      . (!empty($lead['email'])       ? "Email:       {$lead['email']}\n"       : '')
      . (!empty($lead['phone'])       ? "Phone:       {$lead['phone']}\n"       : '')
      . (!empty($lead['source'])      ? "Source:      {$lead['source']}\n"      : '')
      . (!empty($lead['destination']) ? "Destination: {$lead['destination']}\n" : '')
      . (!empty($lead['period'])      ? "Period:      {$lead['period']}\n"      : '')
      . (!empty($lead['pax'])         ? "Pax:         {$lead['pax']}\n"         : '')
      . (!empty($lead['initial_request']) ? "\nMessage:\n{$lead['initial_request']}\n" : '');
}

/**
 * notify_agent_duplicate_lead()
 *  Sends an email to the agent who owns an existing request when a new
 *  incoming lead is confirmed as a duplicate of it and merged in.
 *
 * @param  PDO    $db
 * @param  int    $targetRequestId   Existing request the duplicate was merged into
 * @param  array  $lead              The lead_staging row (customer_name, email, phone,
 *                                    source, destination, period, pax, initial_request)
 * @param  bool   $doNotify          Whether the "notify owner" checkbox was checked
 *
 * @return array  ['sent' => bool, 'error' => string|null]
 *                'error' is non-null when notify was requested but the owner has no email.
 */
function notify_agent_duplicate_lead(
    PDO   $db,
    int   $targetRequestId,
    array $lead,
    bool  $doNotify
): array {

    if (!$doNotify) return ['sent' => false, 'error' => null];

    // Owning agent + customer name of the target request
    $s = $db->prepare(
        'SELECT r.customer_name, a.id AS agent_id, a.name AS agent_name, u.email
         FROM requests r
         LEFT JOIN agents a ON a.id = r.agent_id
         LEFT JOIN users  u ON u.agent_id = a.id AND u.is_active = 1
         WHERE r.id = ? LIMIT 1'
    );
    $s->execute([$targetRequestId]);
    $target = $s->fetch(PDO::FETCH_ASSOC);

    if (!$target || empty($target['agent_id'])) {
        return ['sent' => false, 'error' => null];
    }

    if (empty($target['email'])) {
        $name = $target['agent_name'] ?? 'Unknown agent';
        error_log("[notify] SKIP duplicate — agent_id={$target['agent_id']} ({$name}) has no email. request={$targetRequestId}");
        return [
            'sent'  => false,
            'error' => "Agent \"{$name}\" has no email address configured — duplicate notification not sent.",
        ];
    }

    $hubUrl = 'https://hub.savannahexplorers.com/modules/leads/request_view.php?id=' . $targetRequestId;

    $div  = "────────────────────────────────\n";
    $subject = "Duplicate lead merged into request #{$targetRequestId} - {$target['customer_name']}";
    $body =
        "Hi {$target['agent_name']},\n\n"
      . "A new incoming lead was confirmed as a duplicate of your request "
      . "#{$targetRequestId} ({$target['customer_name']}) and has been merged into it.\n\n"
      . $div
      . "New Lead Details:\n"
      . format_duplicate_lead_block($lead)
      . $div
      . "View request in Hub:\n{$hubUrl}\n\n"
      . "- Savannah Explorers Hub";

    $headers =
        "From: noreply@savannahexplorers.com\r\n"
      . "Content-Type: text/plain; charset=UTF-8\r\n";

    @mail($target['email'], $subject, $body, $headers);
    error_log("[notify] duplicate mail() sent to: {$target['email']} for request #{$targetRequestId} ({$lead['customer_name']})");
    return ['sent' => true, 'error' => null];
}
