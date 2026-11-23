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
    bool   $doNotify,
    string $note = ''
): array {

    if (!$doNotify) return ['sent' => false, 'error' => null, 'to' => null, 'reason' => 'explicit_false'];

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
        return ['sent' => false, 'error' => null, 'to' => null,
                'reason' => $assignedAgentId === NOTIFY_OWNER_AGENT_ID ? 'agent_is_roberto' : 'self_assigned'];
    }

    $agent = notify_agent_contact($db, $assignedAgentId);

    // Notification was requested but agent has no email — surface as error
    if (!$agent || empty($agent['email'])) {
        $name = $agent['name'] ?? 'Unknown agent';
        error_log("[notify] SKIP — agent_id={$assignedAgentId} ({$name}) has no email. creator_user={$creatorUserId}");
        return [
            'sent'   => false,
            'error'  => "Agent \"{$name}\" has no email address configured — notification not sent.",
            'to'     => null,
            'reason' => 'no_email',
        ];
    }

    $mail = notify_new_request_mail($db, $requestId, $agent['name'], $customerName, $note);
    $ok = send_notification_mail($agent['email'], $mail['subject'], $mail['body'],
        "new request #{$requestId} ({$customerName})");
    $err = $ok ? null : "Email server rejected the notification for {$agent['email']} (mail() returned false).";
    return [
        'sent'   => $ok,
        'error'  => $err,
        'to'     => $agent['email'],
        'reason' => $ok ? null : 'mail_error: ' . $err,
    ];
}

/** Roberto De Sibi's agent id: never notified of requests assigned to him (Roberto, 23 Nov 2026). */
if (!defined('NOTIFY_OWNER_AGENT_ID')) define('NOTIFY_OWNER_AGENT_ID', 14);

/** Default of notify_agent when the caller omits it: the seller is told, except Roberto (or no agent). */
function notify_default_for_agent(int $agentId): bool {
    return $agentId > 0 && $agentId !== NOTIFY_OWNER_AGENT_ID;
}

/** Agent name + e-mail (the e-mail lives in users, not agents). null = agent not found. */
function notify_agent_contact(PDO $db, int $agentId): ?array {
    if ($agentId <= 0) return null;
    $s = $db->prepare(
        'SELECT a.id, a.name, u.email
         FROM agents a
         LEFT JOIN users u ON u.agent_id = a.id AND u.is_active = 1
         WHERE a.id = ?
         LIMIT 1'
    );
    $s->execute([$agentId]);
    $row = $s->fetch(PDO::FETCH_ASSOC);
    return $row ? ['id' => (int)$row['id'], 'name' => (string)$row['name'], 'email' => (string)($row['email'] ?? '')] : null;
}

/**
 * Whether a "new request assigned" mail will go out, before sending (Agent API dry runs).
 * $explicit: the caller's notify_agent (null = omitted → notify_default_for_agent()).
 * → ['will_notify' => bool, 'to' => email|null, 'agent' => {id, name}|null,
 *    'reason' => null | 'no_agent' | 'agent_is_roberto' | 'explicit_false' | 'no_email']
 */
function notify_plan(PDO $db, int $agentId, ?bool $explicit): array {
    $c   = notify_agent_contact($db, $agentId);
    $out = ['will_notify' => false, 'to' => ($c && $c['email'] !== '') ? $c['email'] : null,
            'agent' => $c ? ['id' => $c['id'], 'name' => $c['name']] : null, 'reason' => null];
    if (!$c)                                   $out['reason'] = 'no_agent';
    elseif ($agentId === NOTIFY_OWNER_AGENT_ID) $out['reason'] = 'agent_is_roberto';
    elseif ($explicit === false)               $out['reason'] = 'explicit_false';
    elseif ($c['email'] === '')                $out['reason'] = 'no_email';
    else                                       $out['will_notify'] = true;
    return $out;
}

/** Subject + body of the "new request assigned" mail (sent by notify_agent_new_request(), previewed by the API). */
function notify_new_request_mail(PDO $db, int $requestId, string $agentName, string $customerName, string $note = ''): array {
    $intro = "A new request has been created and assigned to you.\n\n";
    $note  = trim($note);
    if ($note !== '') $intro .= $note . "\n\n";
    return [
        'subject' => "New request assigned to you - {$customerName}",
        'body'    => build_assignment_email_body($db, $requestId, $agentName, $customerName, $intro),
    ];
}

/**
 * notify_agent_reassigned()
 *  Sends an email to an agent when an existing request is reassigned to them
 *  (agent_id changed on edit). Silently skips when the editor reassigns the
 *  request to their own agent identity.
 *
 * @param  PDO    $db
 * @param  int    $newAgentId    Agent the request is now assigned to
 * @param  int    $editorUserId  users.id of whoever made the change (0 = unknown)
 * @param  int    $requestId
 * @param  string $customerName
 *
 * @return array  ['sent' => bool, 'error' => string|null]
 *                'error' is non-null when the agent has no email or mail() failed.
 */
function notify_agent_reassigned(
    PDO    $db,
    int    $newAgentId,
    int    $editorUserId,
    int    $requestId,
    string $customerName
): array {
    if ($newAgentId <= 0) return ['sent' => false, 'error' => null];

    // Reassigning to your own agent identity — no notification needed
    if ($editorUserId > 0) {
        $s = $db->prepare('SELECT agent_id FROM users WHERE id = ? LIMIT 1');
        $s->execute([$editorUserId]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        if ($row && (int)$row['agent_id'] === $newAgentId) {
            return ['sent' => false, 'error' => null, 'to' => null,
                    'reason' => $newAgentId === NOTIFY_OWNER_AGENT_ID ? 'agent_is_roberto' : 'self_assigned'];
        }
    }

    $agent = notify_agent_contact($db, $newAgentId);

    if (!$agent || empty($agent['email'])) {
        $name = $agent['name'] ?? 'Unknown agent';
        error_log("[notify] SKIP reassign — agent_id={$newAgentId} ({$name}) has no email. editor_user={$editorUserId}");
        return [
            'sent'   => false,
            'error'  => "Agent \"{$name}\" has no email address configured — reassignment notification not sent.",
            'to'     => null,
            'reason' => 'no_email',
        ];
    }

    $intro   = "An existing request has been reassigned to you.\n\n";
    $subject = "Request reassigned to you - {$customerName}";
    $body    = build_assignment_email_body($db, $requestId, $agent['name'], $customerName, $intro);

    $ok = send_notification_mail($agent['email'], $subject, $body,
        "reassigned request #{$requestId} ({$customerName})");
    $err = $ok ? null : "Email server rejected the notification for {$agent['email']} (mail() returned false).";
    return [
        'sent'   => $ok,
        'error'  => $err,
        'to'     => $agent['email'],
        'reason' => $ok ? null : 'mail_error: ' . $err,
    ];
}

/**
 * build_assignment_email_body()
 *  Shared plain-text body for the new-request and reassignment notifications.
 */
function build_assignment_email_body(
    PDO    $db,
    int    $requestId,
    string $agentName,
    string $customerName,
    string $intro
): string {
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
    return
        "Hi {$agentName},\n\n"
      . $intro
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
}

/**
 * send_notification_mail()
 *  Sends a plain-text notification via mail() and logs the ACTUAL result, so a
 *  delivery failure is visible in the PHP error log ("[notify] mail() FAILED")
 *  instead of being swallowed by @-suppression and an ignored return value.
 *
 * @return bool  What mail() returned (false = server rejected the message).
 */
function send_notification_mail(
    string $to,
    string $subject,
    string $body,
    string $context
): bool {
    $headers =
        "From: info@savannahexplorers.com\r\n"
      . "Content-Type: text/plain; charset=UTF-8\r\n";

    $ok = mail($to, $subject, $body, $headers);
    error_log("[notify] mail() " . ($ok ? 'OK' : 'FAILED') . " to: {$to} — {$context}");
    return $ok;
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
 *  incoming lead is confirmed as a duplicate of it — either merged into it
 *  (Merge action) or dismissed without merging (Dismiss action, FYI only).
 *
 * @param  PDO    $db
 * @param  int    $targetRequestId   Existing request the duplicate matches
 * @param  array  $lead              The lead_staging row (customer_name, email, phone,
 *                                    source, destination, period, pax, initial_request)
 * @param  bool   $doNotify          Whether the "notify owner" checkbox was checked
 * @param  bool   $merged            true = lead was merged into the request (default);
 *                                    false = lead was dismissed, notification is FYI only
 *
 * @return array  ['sent' => bool, 'error' => string|null]
 *                'error' is non-null when notify was requested but the owner has no email.
 */
function notify_agent_duplicate_lead(
    PDO   $db,
    int   $targetRequestId,
    array $lead,
    bool  $doNotify,
    bool  $merged = true
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
    $subject = $merged
        ? "Duplicate lead merged into request #{$targetRequestId} - {$target['customer_name']}"
        : "Possible duplicate lead for request #{$targetRequestId} - {$target['customer_name']}";
    $intro = $merged
        ? "A new incoming lead was confirmed as a duplicate of your request "
        . "#{$targetRequestId} ({$target['customer_name']}) and has been merged into it.\n\n"
        : "A new incoming lead was identified as a possible duplicate of your request "
        . "#{$targetRequestId} ({$target['customer_name']}) and was dismissed from staging "
        . "(not merged) — for your awareness only.\n\n";
    $body =
        "Hi {$target['agent_name']},\n\n"
      . $intro
      . $div
      . "New Lead Details:\n"
      . format_duplicate_lead_block($lead)
      . $div
      . "View request in Hub:\n{$hubUrl}\n\n"
      . "- Savannah Explorers Hub";

    $ok = send_notification_mail($target['email'], $subject, $body,
        "duplicate lead for request #{$targetRequestId} ({$lead['customer_name']})");
    return [
        'sent'  => $ok,
        'error' => $ok ? null
            : "Email server rejected the duplicate notification for {$target['email']} (mail() returned false).",
    ];
}
