<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;

require_once __DIR__ . '/phpmailer/Exception.php';
require_once __DIR__ . '/phpmailer/PHPMailer.php';

/**
 * Substitute {{variables}} in a template string using request data.
 */
function substitute_vars(string $text, array $req, array $dates, string $agent_name, string $agent_email): string {
    static $months = [
        'JAN'=>'Jan','FEB'=>'Feb','MAR'=>'Mar','APR'=>'Apr','MAY'=>'May','JUN'=>'Jun',
        'JUL'=>'Jul','AUG'=>'Aug','SEP'=>'Sep','OCT'=>'Oct','NOV'=>'Nov','DEC'=>'Dec'
    ];
    $start_fmt = $dates['start_date'] ? date('d M Y', strtotime($dates['start_date'])) : '';
    $end_fmt   = $dates['end_date']   ? date('d M Y', strtotime($dates['end_date']))   : '';

    $vars = [
        '{{customer_name}}' => $req['customer_name'] ?? '',
        '{{destination}}'   => $req['destination']   ?? '',
        '{{period}}'        => $req['period']         ?? '',
        '{{pax}}'           => $req['pax']            ?? '',
        '{{start_date}}'    => $start_fmt,
        '{{end_date}}'      => $end_fmt,
        '{{agent_name}}'    => $agent_name,
        '{{agent_email}}'   => $agent_email,
    ];
    return str_replace(array_keys($vars), array_values($vars), $text);
}

/**
 * Send an HTML email via PHPMailer (PHP mail() transport).
 * $attachments = [['tmp_path'=>..., 'name'=>...], ...]
 */
function send_hub_email(
    string $to,
    string $subject,
    string $body_html,
    string $from_name,
    string $from_email,
    string $reply_to = '',
    array  $attachments = []
): bool {
    $mail = new PHPMailer(true);
    try {
        $mail->isMail();
        $mail->CharSet    = 'UTF-8';
        $mail->setFrom($from_email, $from_name);
        $mail->addAddress($to);
        if ($reply_to) $mail->addReplyTo($reply_to);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $body_html;
        $mail->AltBody = strip_tags($body_html);

        foreach ($attachments as $att) {
            if (!empty($att['tmp_path']) && file_exists($att['tmp_path'])) {
                $mail->addAttachment($att['tmp_path'], $att['name']);
            }
        }
        $mail->send();
        return true;
    } catch (MailException $e) {
        error_log('PHPMailer error: ' . $mail->ErrorInfo);
        return false;
    }
}

/**
 * Log a sent email as a request_note.
 */
function log_email_note(PDO $pdo, int $request_id, ?int $user_id, string $subject, string $body, array $attachment_names = []): void {
    $note = $body;
    if ($attachment_names) {
        $note .= "\n\n<p style='font-size:.8rem;color:#888'><strong>Attachments:</strong> " . e(implode(', ', $attachment_names)) . '</p>';
    }
    $stmt = $pdo->prepare(
        "INSERT INTO request_notes (request_id, created_by, note_type, subject, body)
         VALUES (?, ?, 'email_sent', ?, ?)"
    );
    $stmt->execute([$request_id, $user_id, $subject, $note]);
}
