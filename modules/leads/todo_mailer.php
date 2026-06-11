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

// ── Timezone: align NOW() and entered times to Dar es Salaam (EAT) ──────────
// BlueHost server clock is in US time (~7h behind), so NOW() must be forced to
// the same zone in which to-do times are entered, otherwise reminders fire late.
date_default_timezone_set('Africa/Dar_es_Salaam');
$db->exec("SET time_zone = '+03:00'");

// ── Find all due, unsent, not-done to-dos that have an email address ───────
$stmt = $db->prepare("
    SELECT t.id, t.title, t.body_html, t.due_at, t.email_to, t.request_id,
           r.customer_name, r.destination, r.period,
           u.full_name AS creator_name, u.email AS creator_email
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

    $reqLine = htmlspecialchars($t['customer_name'], ENT_QUOTES, 'UTF-8');
    if ($t['destination']) $reqLine .= ' &mdash; ' . htmlspecialchars($t['destination'], ENT_QUOTES, 'UTF-8');
    if ($t['period'])      $reqLine .= ' (' . htmlspecialchars($t['period'], ENT_QUOTES, 'UTF-8') . ')';

    $msgHtml = trim($t['body_html'] ?? '');

    $body  = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#1a1a2e;max-width:620px">';
    $body .= '<p style="margin:0 0 4px;color:#777;font-size:12px">Reminder from Savannah Explorers Hub</p>';
    $body .= '<h2 style="margin:0 0 12px;font-size:17px;color:#C0211B">' . htmlspecialchars($t['title'], ENT_QUOTES, 'UTF-8') . '</h2>';
    if ($msgHtml !== '') {
        $body .= '<div style="border-left:3px solid #e0e0e8;padding-left:12px;margin:0 0 14px;line-height:1.5">' . $msgHtml . '</div>';
    }
    $body .= '<table style="font-size:13px;color:#333;border-collapse:collapse">';
    $body .= '<tr><td style="padding:2px 10px 2px 0;color:#888">Due</td><td style="padding:2px 0">' . $dueFormatted . '</td></tr>';
    $body .= '<tr><td style="padding:2px 10px 2px 0;color:#888">Request</td><td style="padding:2px 0">' . $reqLine . '</td></tr>';
    $body .= '</table>';
    $body .= '<p style="margin:16px 0"><a href="' . htmlspecialchars($viewUrl, ENT_QUOTES, 'UTF-8') . '" style="background:#C0211B;color:#fff;text-decoration:none;padding:8px 16px;border-radius:6px;font-size:13px">View request</a></p>';
    $body .= '<hr style="border:none;border-top:1px solid #e0e0e8;margin:16px 0">';
    $body .= '<p style="margin:0;color:#999;font-size:11px">Savannah Explorers Hub &mdash; automated reminder</p>';
    $body .= '</div>';

    // Send from the creating user's company address (all @savannahexplorers.com).
    // Fallback to noreply only if the user has no email on file.
    $fromEmail = filter_var($t['creator_email'] ?? '', FILTER_VALIDATE_EMAIL)
               ? $t['creator_email']
               : 'noreply@savannahexplorers.com';
    $fromName  = $t['creator_name'] ?: 'Savannah Explorers Hub';
    $fromHeader = sprintf('%s <%s>', $fromName, $fromEmail);

    $headers  = "From: " . $fromHeader . "\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "X-Mailer: SavannahHub-TodoMailer\r\n";

    // -f sets the envelope sender so BlueHost/SPF accepts the message.
    $ok = @mail($to, $subject, $body, $headers, '-f' . $fromEmail);

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
