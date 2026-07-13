<?php
// mail_test.php — upload to modules/leads/, run once, then DELETE
// Access: https://hub.savannahexplorers.com/modules/leads/mail_test.php?to=sara@example.com
//
// Diagnoses assignment-notification delivery:
//   1. Sends a test to ?to= (default below) using From: noreply@ — same as the
//      real notification — and reports what mail() actually returned.
//   2. Sends a second copy using a real mailbox as From, to compare deliverability.
//   3. Prints the PHP mail configuration.

$to = filter_input(INPUT_GET, 'to', FILTER_VALIDATE_EMAIL)
    ?: 'anderson.jr@savannahexplorers.com';   // ← or pass ?to=sara@...

$stamp   = date('Y-m-d H:i:s');
$subject = 'Hub mail() test — ' . date('H:i:s');
$body    = "This is a test from hub.savannahexplorers.com\n\nTime: {$stamp}";

$fromNoreply = "From: noreply@savannahexplorers.com\r\n"
             . "Content-Type: text/plain; charset=UTF-8\r\n";
// A mailbox that actually EXISTS in cPanel Mail — more likely to be accepted.
$fromReal    = "From: info@savannahexplorers.com\r\n"
             . "Content-Type: text/plain; charset=UTF-8\r\n";

$r1 = mail($to, $subject . ' [noreply]', $body, $fromNoreply);
$r2 = mail($to, $subject . ' [real from]', $body, $fromReal);

echo '<pre>';
echo "Recipient: {$to}\n\n";
echo "mail() with From: noreply@   returned: " . ($r1 ? 'TRUE' : 'FALSE') . "\n";
echo "mail() with From: info@       returned: " . ($r2 ? 'TRUE' : 'FALSE') . "\n\n";

echo "PHP mail configuration:\n";
foreach (['SMTP', 'smtp_port', 'sendmail_path', 'sendmail_from'] as $k) {
    $v = ini_get($k);
    echo "  {$k} = " . ($v !== '' ? $v : '(empty)') . "\n";
}
echo "\nServer: " . ($_SERVER['SERVER_NAME'] ?? 'unknown') . "\n";
echo "PHP version: " . PHP_VERSION . "\n";

echo "\n--- HOW TO READ THIS ---\n";
echo "• Both TRUE  → the server ACCEPTED the messages. If Sara/Rick still see\n";
echo "  nothing, it is spam filtering / SPF-DKIM. Check their SPAM folder and\n";
echo "  whether the 'noreply' copy is missing while the 'info' copy arrives —\n";
echo "  that would confirm the From address is the problem.\n";
echo "• noreply FALSE, info TRUE → switch the notification From to a real mailbox.\n";
echo "• Both FALSE → BlueHost sendmail is rejecting outright (server-side issue).\n";
echo "\nDELETE this file after testing.\n";
echo '</pre>';
