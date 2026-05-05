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

    // Notification was requested but agent has no email → surface as error
    if (!$agent || empty($agent['email'])) {
        $name = $agent['name'] ?? 'Unknown agent';
        error_log("[notify] SKIP — agent_id={$assignedAgentId} ({$name}) has no email. creator_user={$creatorUserId}");
        return [
            'sent'  => false,
            'error' => "Agent \"{$name}\" has no email address configured — notification not sent.",
        ];
    }

    $hubUrl  = 'https://hub.savannahexplorers.com/modules/leads/request_view.php?id=' . $requestId;

    // Fetch full request details for the email body
    $s = $db->prepare(
        'SELECT source, destination, period, pax, initial_request
         FROM requests WHERE id = ? LIMIT 1'
    );
    $s->execute([$requestId]);
    $req = $s->fetch(PDO::FETCH_ASSOC) ?: [];

    $source      = $req['source']          ?? '—';
    $destination = $req['destination']     ?? '—';
    $period      = $req['period']          ?? '—';
    $pax         = $req['pax']             ?? '—';
    $initReq     = $req['initial_request'] ?? '';

    $subject = "New request assigned to you – {$customerName}";
    $body    =
        "Hi {$agent['name']},\n\n"
      . "A new request has been created and assigned to you.\n\n"
      . "────────────────────────────────\n"
      . "Customer:    {$customerName}\n"
      . "Folder:      {$practiceCode}\n"
      . "Request ID:  #{$requestId}\n"
      . "Source:      {$source}\n"
      . "Type:        {$destination}\n"
      . "Period:      {$period}\n"
      . "Pax:         {$pax}\n"
      . "────────────────────────────────\n"
      . ($initReq ? "\nInitial Request:\n{$initReq}\n\n" : '')
      . "View in Hub:\n{$hubUrl}\n\n"
      . "— Savannah Explorers Hub";

    $headers =
        "From: noreply@savannahexplorers.com\r\n"
      . "Content-Type: text/plain; charset=UTF-8\r\n";

    @mail($agent['email'], $subject, $body, $headers);
    error_log("[notify] mail() sent to: {$agent['email']} for request #{$requestId} ({$customerName})");
    return ['sent' => true, 'error' => null];
}
