<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;

require_once __DIR__ . '/phpmailer/Exception.php';
require_once __DIR__ . '/phpmailer/PHPMailer.php';

// Per-user email signatures (defines get_user_signature_html, etc.)
if (is_file(__DIR__ . '/../../../includes/signature_helper.php')) {
    require_once __DIR__ . '/../../../includes/signature_helper.php';
}

/**
 * Tidy up HTML produced by the Quill editor so it renders compactly and
 * consistently across mail clients (Thunderbird, Gmail, etc.).
 *  - collapses runs of empty paragraphs (<p><br></p>, <p>&nbsp;</p>) into a single one
 *  - gives every <p> an explicit uniform margin so clients don't apply their
 *    own (large) default paragraph spacing
 */
if (!function_exists('normalize_email_html')) {
    function normalize_email_html(string $html): string {
        // Normalise empty-paragraph variants to a single marker
        $html = preg_replace('#<p>(\s|&nbsp;|<br\s*/?>)*</p>#i', '<p></p>', $html);
        // Collapse 2+ consecutive empty paragraphs into one
        $html = preg_replace('#(<p></p>\s*){2,}#i', '<p></p>', $html);
        // Empty paragraph = single blank line of fixed height
        $html = str_ireplace('<p></p>', '<p style="margin:0;line-height:1.4">&nbsp;</p>', $html);
        // Give content paragraphs (those without an inline style) a uniform margin
        $html = preg_replace('#<p(?![^>]*\bstyle=)#i', '<p style="margin:0 0 4px 0;line-height:1.4"', $html);
        return $html;
    }
}

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
        $body_html = normalize_email_html($body_html);
        $mail->isMail();
        $mail->CharSet    = 'UTF-8';
        $mail->setFrom($from_email, $from_name);
        $mail->addAddress($to);
        if ($reply_to) $mail->addReplyTo($reply_to);
        // BCC the sending account so it keeps a copy of outgoing hub mail
        if (filter_var($from_email, FILTER_VALIDATE_EMAIL)) {
            $mail->addBCC($from_email);
        }
        $mail->isHTML(true);
        $mail->Subject = $subject;

        // Append the logged-in user's HTML signature, if any.
        if (function_exists('get_user_signature_html')) {
            $sigUid = (int)($_SESSION['user_id'] ?? 0);
            $sig = $sigUid > 0 ? get_user_signature_html($sigUid) : '';
            if ($sig !== '') {
                $body_html .= '<br><br><hr style="border:none;border-top:1px solid #ccc;margin:12px 0;">' . $sig;
            }
        }

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
