<?php
/**
 * Substitute {{variables}} in a template string using request data.
 */
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
    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: =?UTF-8?B?" . base64_encode($from_name) . "?= <{$from_email}>\r\n";
    if ($reply_to) {
        $headers .= "Reply-To: {$reply_to}\r\n";
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
