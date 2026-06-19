<?php
/**
 * Substitute {{variables}} in a template string using request data.
 */
if (is_file(__DIR__ . '/signature_helper.php')) {
    require_once __DIR__ . '/signature_helper.php';
}
function substitute_vars(string $text, array $req, array $dates, string $agent_name, string $agent_email): string {
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
 * Tidy up HTML produced by the Quill editor so it renders compactly and
 * consistently across mail clients.
 */
if (!function_exists('normalize_email_html')) {
    function normalize_email_html(string $html): string {
        $html = preg_replace('#<p>(\s|&nbsp;|<br\s*/?>)*</p>#i', '<p></p>', $html);
        $html = preg_replace('#(<p></p>\s*){2,}#i', '<p></p>', $html);
        $html = str_ireplace('<p></p>', '<p style="margin:0;line-height:1.4">&nbsp;</p>', $html);
        $html = preg_replace('#<p(?![^>]*\bstyle=)#i', '<p style="margin:0 0 4px 0;line-height:1.4"', $html);
        return $html;
    }
}

/**
 * Send an HTML email via PHP mail().
 */
function send_hub_email(
    string $to,
    string $subject,
    string $body_html,
    string $from_name,
    string $from_email,
    string $reply_to = ''
): bool {
    $body_html = normalize_email_html($body_html);

    // Append the logged-in user's HTML signature, if any.
    if (function_exists('get_user_signature_html')) {
        $sigUid = (int)($_SESSION['user_id'] ?? 0);
        $sig = $sigUid > 0 ? get_user_signature_html($sigUid) : '';
        if ($sig !== '') {
            $body_html .= '<br><br><hr style="border:none;border-top:1px solid #ccc;margin:12px 0;">' . $sig;
        }
    }

    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: =?UTF-8?B?" . base64_encode($from_name) . "?= <{$from_email}>\r\n";
    if ($reply_to) {
        $headers .= "Reply-To: {$reply_to}\r\n";
    }
    // BCC the sending account so it keeps a copy of outgoing hub mail
    if (filter_var($from_email, FILTER_VALIDATE_EMAIL)) {
        $headers .= "Bcc: {$from_email}\r\n";
    }
    $headers .= "X-Mailer: SavannahHub/1.0\r\n";

    return mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body_html, $headers);
}

/**
 * Log a sent email as a request_note.
 */
function log_email_note(PDO $pdo, int $request_id, ?int $user_id, string $subject, string $body): void {
    $stmt = $pdo->prepare(
        "INSERT INTO request_notes (request_id, created_by, note_type, subject, body)
         VALUES (?, ?, 'email_sent', ?, ?)"
    );
    $stmt->execute([$request_id, $user_id, $subject, $body]);
}
