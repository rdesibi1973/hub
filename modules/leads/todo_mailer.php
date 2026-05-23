<?php
/**
 * todo_mailer.php
 *
 * Called by cron-job.org every 15 minutes.
 * Finds due, unsent, incomplete to-dos and sends reminder emails.
 *
 * URL: https://hub.savannahexplorers.com/modules/leads/todo_mailer.php?token=<TOKEN>
 *
 * Token is defined below — add it to your cron-job.org job URL.
 */

define('MAILER_TOKEN', 'todo-cron-9f4a2e7b1c3d');   // change if needed

if (($_GET['token'] ?? '') !== MAILER_TOKEN) {
    http_response_code(403);
    echo "Forbidden\n"; exit;
}

require_once 'config.php';
$db = db();

// ── Find all due, unsent, not-done to-dos that have an email address ───────
$stmt = $db->prepare("
    SELECT t.id, t.title, t.due_at, t.email_to, t.request_id,
           r.customer_name, r.destination, r.period,
           u.full_name AS creator_name
    FROM request_todos t
    JOIN requests r ON r.id = t.request_id
    LEFT JOIN users u ON u.id = t.user_id
    WHERE t.done = 0
      AND t.reminder_sent = 0
      AND t.email_to IS NOT NULL
      AND t.email_to != ''
      AND t.due_at <= NOW()
");
$stmt->execute();
$due = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($due)) {
    echo "No reminders to send.\n"; exit;
}

$sent = 0; $failed = 0;
$baseUrl = defined('BASE_URL') ? BASE_URL : 'https://hub.savannahexplorers.com';

foreach ($due as $t) {
    $recipients = array_filter(array_map('trim', explode(',', $t['email_to'])));
    if (empty($recipients)) { continue; }
    $to = implode(', ', $recipients);
    $subject = "⏰ Reminder: " . $t['title'];

    $dueFormatted = date('d M Y H:i', strtotime($t['due_at']));
    $viewUrl      = $baseUrl . '/modules/leads/request_view.php?id=' . $t['request_id'] . '#todos';

    $body  = "Reminder from Savannah Explorers Hub\r\n";
    $body .= str_repeat("-", 50) . "\r\n\r\n";
    $body .= "TO-DO: " . $t['title'] . "\r\n";
    $body .= "Due:   " . $dueFormatted . "\r\n\r\n";
    $body .= "Request: " . $t['customer_name'];
    if ($t['destination']) $body .= " — " . $t['destination'];
    if ($t['period'])      $body .= " (" . $t['period'] . ")";
    $body .= "\r\n\r\n";
    $body .= "View request: " . $viewUrl . "\r\n\r\n";
    $body .= str_repeat("-", 50) . "\r\n";
    $body .= "Savannah Explorers Hub — automated reminder\r\n";

    $headers  = "From: noreply@savannahexplorers.com\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $headers .= "X-Mailer: SavannahHub-TodoMailer\r\n";

    $ok = @mail($to, $subject, $body, $headers);

    if ($ok) {
        $db->prepare("UPDATE request_todos SET reminder_sent=1 WHERE id=?")
           ->execute([$t['id']]);
        $sent++;
        error_log("[todo_mailer] Sent reminder #{$t['id']} to {$to}: {$t['title']}");
    } else {
        $failed++;
        error_log("[todo_mailer] FAILED reminder #{$t['id']} to {$to}: {$t['title']}");
    }
}

echo "Done. Sent: $sent, Failed: $failed\n";
