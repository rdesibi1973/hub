<?php
/**
 * Pure-logic checks for the mail header parser and reply threading (mail_get / mail_send / mail_draft).
 * No IMAP, no server: php tools/tests/mailbox_headers_test.php   (exit code 1 on a failure)
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../../includes/mailbox_service.php';

$fail = 0;
$t = function (string $name, bool $ok) use (&$fail) { echo ($ok ? 'PASS ' : 'FAIL ') . $name . "\n"; if (!$ok) $fail++; };

// Outlook message with empty In-Reply-To / References lines (uid 206525, 21 Oct 2026).
$raw = "From: Raffaella <raffaella@etniaviaggi.it>\r\n"
     . "To: info@savannahexplorers.com\r\n"
     . "Message-ID: <AM9PR01MB1234@eurprd01.prod.outlook.com>\r\n"
     . "In-Reply-To:\r\n"
     . "Subject: I: Safari Amedeo x 3\r\n"
     . "References: \r\n"
     . "In-Reply-To-Ignored: x\r\n"
     . "Thread-Topic: Safari Amedeo x 3\r\n\r\n";
$t('empty In-Reply-To stays empty',  mbx_raw_header($raw, 'In-Reply-To') === '');
$t('empty References stays empty',   mbx_raw_header($raw, 'References') === '');
$t('Subject read',                   mbx_raw_header($raw, 'Subject') === 'I: Safari Amedeo x 3');
$t('missing header empty',           mbx_raw_header($raw, 'X-Nope') === '');
$t('no prefix match',                mbx_raw_header($raw, 'Thread') === '');

// Folded References (continuation lines), LF-only line ends.
$raw2 = "References: <a@x.com>\n <b@y.com>\n\t<c@z.com>\nIn-Reply-To: <c@z.com>\nSubject: Re: test\n";
$t('folded References unfolded',     mbx_raw_header($raw2, 'References') === '<a@x.com> <b@y.com> <c@z.com>');
$t('In-Reply-To read',               mbx_raw_header($raw2, 'in-reply-to') === '<c@z.com>');

// Message-ID tokens only.
$t('msgids filter junk',             mbx_msgids('In-Reply-To: <a@b> junk <c@d> <nope> <a@b>') === ['<a@b>', '<c@d>']);
$t('msgids empty',                   mbx_msgids('Subject: I: Safari Amedeo x 3') === []);

// Reply to uid: References = original References + Message-ID, junk dropped.
$orig = ['message_id' => '<m1@outlook.com>', 'references' => 'In-Reply-To:'];
$t('reply: junk refs dropped',       mbx_thread_headers($orig, []) === ['<m1@outlook.com>', '<m1@outlook.com>']);
$orig = ['message_id' => '<m2@outlook.com>', 'references' => '<r1@a.it> <r2@b.it>'];
$t('reply: refs + msgid',            mbx_thread_headers($orig, []) === ['<m2@outlook.com>', '<r1@a.it> <r2@b.it> <m2@outlook.com>']);
$orig = ['message_id' => '<m3@a.it>', 'references' => '<r1@a.it> <m3@a.it>'];
$t('reply: msgid not doubled',       mbx_thread_headers($orig, []) === ['<m3@a.it>', '<r1@a.it> <m3@a.it>']);
$t('reply: no msgid → no threading', mbx_thread_headers(['message_id' => '', 'references' => ''], []) === ['', '']);
$t('not a reply',                    mbx_thread_headers(null, []) === ['', '']);

// Explicit values win over reply_to_uid.
$orig = ['message_id' => '<m4@a.it>', 'references' => '<r1@a.it>'];
$t('explicit in_reply_to wins',      mbx_thread_headers($orig, ['in_reply_to' => 'x9@gmail.com']) === ['<x9@gmail.com>', '<r1@a.it> <x9@gmail.com>']);
$t('explicit both win',              mbx_thread_headers($orig, ['in_reply_to' => '<x9@gmail.com>', 'references' => ['<p@q.com>', '<x9@gmail.com>']])
                                         === ['<x9@gmail.com>', '<p@q.com> <x9@gmail.com>']);
$t('explicit references only',       mbx_thread_headers($orig, ['references' => '<p@q.com>']) === ['<m4@a.it>', '<p@q.com> <m4@a.it>']);
$bad = false;
try { mbx_thread_headers(null, ['in_reply_to' => '<x9@gmail.com>', 'references' => 'In-Reply-To: <a@b>']); } catch (InvalidArgumentException $e) { $bad = true; }
$t('explicit bad references refused', $bad);

echo $fail ? "$fail FAILED\n" : "ALL PASS\n";
exit($fail ? 1 : 0);
