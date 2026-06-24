<?php
// modules/memo/cron_reminders.php
// Called by external cron service (cron-job.org / freecronjobs), e.g. every 15 min:
//   https://hub.savannahexplorers.com/modules/memo/cron_reminders.php?token=XXX
//
// Token is NOT hardcoded here: define MEMO_CRON_TOKEN in includes/config.php (outside the repo).

date_default_timezone_set('Africa/Dar_es_Salaam');

require_once __DIR__ . '/../../includes/config.php';  // must define MEMO_CRON_TOKEN
require_once __DIR__ . '/../../includes/db.php';       // provides db()

if (!defined('MEMO_CRON_TOKEN')) {
    http_response_code(500);
    die('Cron token not configured');
}
if (($_GET['token'] ?? '') !== MEMO_CRON_TOKEN) {
    http_response_code(403);
    die('Forbidden');
}

header('Content-Type: text/plain');

// PHPMailer (same setup as the rest of the Hub)
require_once __DIR__ . '/../../includes/mail_helper.php'; // adjust if your mail bootstrap differs

$pdo = db();
$now = date('Y-m-d H:i:s');

// Pull due reminders: only OPEN, not deleted, due now or earlier.
// For one-shot (recur_rule='none') we additionally require reminder_sent = 0.
$sql =
    "SELECT m.id, m.user_id, m.title, m.body, m.type, m.due_date, " .
    "m.reminder_at, m.recur_rule, u.email AS user_email, u.full_name AS user_name " .
    "FROM memos m " .
    "JOIN users u ON u.id = m.user_id " .
    "WHERE m.deleted_at IS NULL " .
    "AND m.status = 'open' " .
    "AND m.reminder_at IS NOT NULL " .
    "AND m.reminder_at <= ? " .
    "AND ( m.recur_rule <> 'none' OR m.reminder_sent = 0 )";
$stmt = $pdo->prepare($sql);
$stmt->execute(array($now));
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$sent = 0;
$skipped = 0;

foreach ($rows as $r) {
    $email = trim($r['user_email']);
    if ($email === '') { $skipped++; continue; }

    $name  = $r['user_name'] !== '' ? $r['user_name'] : 'there';
    $title = $r['title'];
    $bodyTxt = $r['body'] !== null ? $r['body'] : '';

    $subject = 'Memo reminder: ' . $title;

    $html  = '<p>Hi ' . htmlspecialchars($name) . ',</p>';
    $html .= '<p>This is a reminder for your memo:</p>';
    $html .= '<p style="font-size:16px;"><strong>' . htmlspecialchars($title) . '</strong></p>';
    if ($bodyTxt !== '') {
        $html .= '<div style="white-space:pre-wrap;border-left:3px solid #C0211B;padding-left:10px;color:#444;">'
               . htmlspecialchars($bodyTxt) . '</div>';
    }
    if (!empty($r['due_date'])) {
        $html .= '<p style="color:#777;">Due: ' . htmlspecialchars($r['due_date']) . '</p>';
    }
    $html .= '<p style="color:#999;font-size:12px;">Savannah Explorers Hub — Memo Board</p>';

    // send_mail() is assumed available from mail_helper.php (PHPMailer isMail(), noreply@ sender).
    // Adjust the call if your helper signature differs.
    $okMail = send_hub_email($email, $subject, $html, 'Savannah Explorers Hub', 'noreply@savannahexplorers.com');

    if ($okMail) {
        $sent++;
        if ($r['recur_rule'] === 'none') {
            // one-shot: mark done so it never fires again
            $u = $pdo->prepare("UPDATE memos SET reminder_sent = 1, updated_at = ? WHERE id = ?");
            $u->execute(array($now, $r['id']));
        } else {
            // recurring: advance reminder_at to next slot, keep reminder_sent = 0
            $next = advance_reminder($r['reminder_at'], $r['recur_rule']);
            $u = $pdo->prepare("UPDATE memos SET reminder_at = ?, reminder_sent = 0, updated_at = ? WHERE id = ?");
            $u->execute(array($next, $now, $r['id']));
        }
    } else {
        $skipped++;
    }
}

echo $now . " EAT — memo reminders: sent=$sent skipped=$skipped total_due=" . count($rows) . "\n";


// Advance a recurring reminder to the next occurrence.
// Catches up if the cron missed several slots (loops until in the future).
function advance_reminder($current, $rule) {
    $ts = strtotime($current);
    if ($ts === false) { $ts = time(); }
    $nowTs = time();

    $step = '+1 day';
    if ($rule === 'weekly')  { $step = '+1 week'; }
    if ($rule === 'monthly') { $step = '+1 month'; }

    // advance at least once, then keep advancing until in the future
    do {
        $ts = strtotime($step, $ts);
    } while ($ts !== false && $ts <= $nowTs);

    return date('Y-m-d H:i:s', $ts);
}
