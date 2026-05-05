<?php
// mail_test.php — upload to modules/leads/, run once, then DELETE
// Access: https://hub.savannahexplorers.com/modules/leads/mail_test.php

$to      = 'anderson.jr@savannahexplorers.com'; // ← change to your own email to test
$subject = 'Hub mail() test — ' . date('H:i:s');
$body    = "This is a test from hub.savannahexplorers.com\n\nTime: " . date('Y-m-d H:i:s');
$headers = "From: noreply@savannahexplorers.com\r\n"
         . "Content-Type: text/plain; charset=UTF-8\r\n";

$result = mail($to, $subject, $body, $headers);

echo '<pre>';
echo "mail() returned: " . ($result ? 'TRUE' : 'FALSE') . "\n\n";

// Check if the From domain has email hosting on BlueHost
echo "PHP mail configuration:\n";
$ini_keys = ['SMTP', 'smtp_port', 'sendmail_path', 'sendmail_from'];
foreach ($ini_keys as $k) {
    $v = ini_get($k);
    echo "  {$k} = " . ($v !== '' ? $v : '(empty)') . "\n";
}

echo "\nServer: " . ($_SERVER['SERVER_NAME'] ?? 'unknown') . "\n";
echo "PHP version: " . PHP_VERSION . "\n";

echo "\n--- RESULT ---\n";
if ($result) {
    echo "mail() accepted the message.\n";
    echo "Check inbox AND spam folder at: {$to}\n";
    echo "If not received in 5 min, the issue is SPF/spam filtering.\n";
} else {
    echo "mail() FAILED — BlueHost sendmail rejected the message.\n";
    echo "Likely cause: From address domain has no email account on this server.\n";
    echo "Try changing From to an address that EXISTS in cPanel Mail.\n";
}
echo '</pre>';
