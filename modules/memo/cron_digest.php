<?php
// modules/memo/cron_digest.php — morning digest of the Memo Board, by email.
// Called by cron-job.org Mon–Sat 07:20 EAT:
//   https://hub.savannahexplorers.com/modules/memo/cron_digest.php?token=XXX
//     &dry=1    → return the HTML instead of sending (nothing logged)
//     &force=1  → send even if today's digest already went out
//
// The Cowork recap ("Recap mattutino Memo + Calendar", 07:40) finds it in Gmail by the
// "[Memo Hub] YYYY-MM-DD" subject and reads the DIGEST-COUNTS line.
// Server-only constants in includes/config.php: MEMO_CRON_TOKEN, AGENT_MEMO_USER (board
// owner, rdesibi), MEMO_DIGEST_TO (recipient). Keep PHP-7 style, like the rest of memo/.

ob_start();
date_default_timezone_set('Africa/Dar_es_Salaam');   // EAT, same as Nairobi

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';     // $pdo

if (!defined('MEMO_CRON_TOKEN') || !is_string($_GET['token'] ?? null) || !hash_equals(MEMO_CRON_TOKEN, $_GET['token'])) {
    ob_end_clean();
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/../../includes/mail_helper.php';
require_once __DIR__ . '/memo_lib.php';
memo_schema($pdo);

$dry   = !empty($_GET['dry']);
$force = !empty($_GET['force']);
$today = date('Y-m-d');

$fail = function ($msg) {
    ob_end_clean();
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo $msg . "\n";
    exit;
};
if (!defined('MEMO_DIGEST_TO') || !filter_var(MEMO_DIGEST_TO, FILTER_VALIDATE_EMAIL)) $fail('MEMO_DIGEST_TO not configured');
$owner = memo_owner($pdo);
if (!$owner) $fail('Memo owner not found — define AGENT_MEMO_USER in includes/config.php');

// ── Already sent today? ─────────────────────────────────────────────────────
$pdo->exec("CREATE TABLE IF NOT EXISTS memo_digest_log (
    digest_date DATE NOT NULL PRIMARY KEY,
    sent_at     DATETIME NOT NULL,
    recipient   VARCHAR(255) NOT NULL,
    counts      VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
if (!$dry && !$force) {
    $st = $pdo->prepare("SELECT sent_at FROM memo_digest_log WHERE digest_date = ?");
    $st->execute(array($today));
    if ($sentAt = $st->fetchColumn()) {
        ob_end_clean();
        header('Content-Type: text/plain; charset=UTF-8');
        echo "$today — digest already sent at $sentAt (add &force=1 to resend)\n";
        exit;
    }
}

$d    = memo_digest_build($pdo, $owner['id'], $today);
$mail = memo_digest_render($d, $today);

if ($dry) {
    ob_end_clean();
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!-- Subject: ' . htmlspecialchars($mail['subject'], ENT_QUOTES, 'UTF-8') . " -->\n" . $mail['html'];
    exit;
}

$ok = send_hub_email(MEMO_DIGEST_TO, $mail['subject'], $mail['html'], 'Savannah Explorers Hub',
                     'noreply@savannahexplorers.com', '', $mail['text']);
ob_end_clean();
header('Content-Type: text/plain; charset=UTF-8');
if (!$ok) {
    http_response_code(500);
    echo "$today — digest NOT sent (mail() returned false)\n";
    exit;
}
$pdo->prepare("INSERT INTO memo_digest_log (digest_date, sent_at, recipient, counts) VALUES (?, ?, ?, ?)
               ON DUPLICATE KEY UPDATE sent_at = VALUES(sent_at), recipient = VALUES(recipient), counts = VALUES(counts)")
    ->execute(array($today, date('Y-m-d H:i:s'), MEMO_DIGEST_TO, $mail['counts']));
echo "$today — digest sent to " . MEMO_DIGEST_TO . ": " . $mail['subject'] . "\n";
exit;


// ═════════════════════════════════════════════════════════════════════════════

/**
 * The digest sections for the owner's board on $today. Each memo appears once:
 * overdue → today → next 7 days → in progress. Open memos without a due date
 * (consultation notes) and done / pending / archived / deleted memos are left out.
 */
function memo_digest_build(PDO $pdo, $ownerId, $today) {
    $rows = memo_rows($pdo, "m.user_id = ? AND m.deleted_at IS NULL AND m.status IN ('open','doing','waiting')
                             AND NOT (m.status = 'open' AND m.due_date IS NULL)", array((int)$ownerId));
    $in7 = date('Y-m-d', strtotime($today . ' +7 days'));
    $d = array('overdue' => array(), 'today' => array(), 'week' => array(), 'doing' => array());
    foreach ($rows as $m) {
        $due = $m['due_date'];
        if ($due !== null && $due < $today) {
            $m['days_overdue'] = (int)round((strtotime($today) - strtotime($due)) / 86400);
            $d['overdue'][] = $m;
        } elseif ($due === $today) {
            $d['today'][] = $m;
        } elseif ($due !== null && $due <= $in7) {
            $d['week'][] = $m;
        } elseif ($m['status'] === 'doing') {
            $d['doing'][] = $m;
        }
    }
    // Most overdue first; today: high priority first.
    usort($d['overdue'], function ($a, $b) { return $b['days_overdue'] - $a['days_overdue']; });
    usort($d['today'], function ($a, $b) {
        $pa = $a['priority'] === 'high' ? 0 : 1; $pb = $b['priority'] === 'high' ? 0 : 1;
        return $pa !== $pb ? $pa - $pb : $a['id'] - $b['id'];
    });

    // Routines: same service as the Agent API routine_status.
    $d['leads'] = 0; $d['sh_open'] = 0; $d['routines_due'] = array();
    foreach (memo_routines_status($pdo, $ownerId) as $r) {
        if ($r['key'] === 'leads')   $d['leads']   = (int)$r['count'];
        if ($r['key'] === 'afrasia') $d['sh_open'] = (int)$r['count'];
        if ($r['due']) $d['routines_due'][] = $r['title'];
    }
    return $d;
}

/** First non-empty line of a memo body (max 160 chars). */
function memo_digest_first_line($body) {
    foreach (preg_split('/\r?\n/', (string)$body) as $line) {
        $line = trim(preg_replace('/\s+/u', ' ', $line));
        if ($line !== '' && preg_match('/[\p{L}\p{N}]/u', $line)) {
            return mb_strlen($line) > 160 ? mb_substr($line, 0, 157) . '…' : $line;
        }
    }
    return '';
}

/** "SH-2026-0041, saldo € 1,250.00 — si chiude da solo al pagamento" or ''. */
function memo_digest_payment($m) {
    if (!$m['invoice_number']) return '';
    $cur = $m['invoice_currency'] === 'EUR' ? '€' : '$';
    $s = $m['invoice_number'] . ', saldo ' . $cur . ' ' . number_format((float)$m['invoice_balance'], 2);
    if ($m['afrasia']) $s .= ' (AfrAsia)';
    if ($m['auto_close_on_payment']) $s .= ' — si chiude da solo al pagamento';
    return $s;
}

/** Subject, HTML, plain text and the DIGEST-COUNTS line. */
function memo_digest_render(array $d, $today) {
    $hub   = 'https://hub.savannahexplorers.com/modules/';
    $board = $hub . 'memo/index.php';
    $e = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
    $dmy = function ($ymd) { return date('d/m', strtotime($ymd)); };

    $nToday = count($d['today']); $nOver = count($d['overdue']);
    $counts = 'sollecitare=' . $nToday . '; scaduti=' . $nOver . '; attesa7=' . count($d['week'])
            . '; incorso=' . count($d['doing']) . '; leads=' . $d['leads'] . '; sh_aperte=' . $d['sh_open'];
    $subject = '[Memo Hub] ' . $today . ' – ' . $nToday . ' da sollecitare, ' . $nOver . ' scaduti';

    $html = array(); $text = array();
    $section = function ($title, array $itemsHtml, array $itemsText) use (&$html, &$text, $e) {
        if (!$itemsHtml) return;
        $html[] = '<h3 style="font-size:15px;margin:18px 0 6px 0;">' . $e($title) . '</h3><ul style="margin:0;padding-left:20px;">'
                . implode('', array_map(function ($li) { return '<li style="margin:0 0 6px 0;">' . $li . '</li>'; }, $itemsHtml)) . '</ul>';
        $text[] = $title . "\n" . implode("\n", array_map(function ($t) { return '- ' . $t; }, $itemsText));
    };
    $link = function ($m) use ($board, $e) {
        return '<a href="' . $board . '" style="color:#C0211B;">' . $e($m['title']) . '</a>';
    };

    // 1. Overdue
    $h = array(); $t = array();
    foreach ($d['overdue'] as $m) {
        $extra = array_filter(array($m['waiting_on'] ? 'attesa: ' . $m['waiting_on'] : '', memo_digest_payment($m)));
        $steps = array();
        foreach ($m['next_steps'] as $s) $steps[] = $s['title'];
        $h[] = $link($m) . ' — <strong>' . $m['days_overdue'] . ' gg di ritardo</strong> (' . $dmy($m['due_date']) . ')'
             . ($extra ? '<br><span style="color:#555;">' . $e(implode(' · ', $extra)) . '</span>' : '')
             . ($steps ? '<br><span style="color:#777;">Poi: ' . $e(implode(' → ', $steps)) . '</span>' : '');
        $t[] = $m['title'] . ' — ' . $m['days_overdue'] . ' gg di ritardo (' . $dmy($m['due_date']) . ')'
             . ($extra ? ' · ' . implode(' · ', $extra) : '') . ($steps ? ' · Poi: ' . implode(' → ', $steps) : '');
    }
    $section('🔴 Scaduti', $h, $t);

    // 2. Today
    $h = array(); $t = array();
    foreach ($d['today'] as $m) {
        $hi = $m['priority'] === 'high';
        $line = memo_digest_first_line($m['body']);
        $extra = array_filter(array($m['waiting_on'] ? 'attesa: ' . $m['waiting_on'] : '', memo_digest_payment($m)));
        $h[] = ($hi ? '<strong>' : '') . $link($m) . ($hi ? '</strong>' : '')
             . ($extra ? ' — ' . $e(implode(' · ', $extra)) : '')
             . ($line !== '' ? '<br><span style="color:#555;">' . $e($line) . '</span>' : '');
        $t[] = ($hi ? '[!] ' : '') . $m['title'] . ($extra ? ' — ' . implode(' · ', $extra) : '') . ($line !== '' ? ' — ' . $line : '');
    }
    $section('⏰ Da sollecitare oggi', $h, $t);

    // 3. Next 7 days
    $h = array(); $t = array();
    foreach ($d['week'] as $m) {
        $extra = array_filter(array($m['waiting_on'] ? 'attesa: ' . $m['waiting_on'] : '', memo_digest_payment($m)));
        $h[] = $dmy($m['due_date']) . ' — ' . $link($m) . ($extra ? ' — ' . $e(implode(' · ', $extra)) : '');
        $t[] = $dmy($m['due_date']) . ' — ' . $m['title'] . ($extra ? ' — ' . implode(' · ', $extra) : '');
    }
    $section('⏳ In attesa nei prossimi 7 giorni', $h, $t);

    // 4. In progress
    $h = array(); $t = array();
    foreach ($d['doing'] as $m) {
        $folder = $m['folder'] && $m['request_id']
            ? ' — <a href="' . $hub . 'leads/request_view.php?id=' . (int)$m['request_id'] . '" style="color:#555;">' . $e($m['folder']) . '</a>' : '';
        $h[] = $link($m) . $folder;
        $t[] = $m['title'] . ($m['folder'] ? ' — ' . $m['folder'] : '');
    }
    $section('🔄 In corso', $h, $t);

    // 5. Routines
    $h = array(); $t = array();
    if ($d['leads'] > 0) { $h[] = '<a href="' . $hub . 'leads/staging.php" style="color:#C0211B;">Incoming Leads da assegnare</a>: <strong>' . $d['leads'] . '</strong>'; $t[] = 'Incoming Leads da assegnare: ' . $d['leads']; }
    if ($d['sh_open'] > 0) { $h[] = 'Fatture SH con saldo aperto (AfrAsia): <strong>' . $d['sh_open'] . '</strong>'; $t[] = 'Fatture SH con saldo aperto (AfrAsia): ' . $d['sh_open']; }
    if ($d['routines_due']) { $h[] = 'Routine da fare: ' . $e(implode(', ', $d['routines_due'])); $t[] = 'Routine da fare: ' . implode(', ', $d['routines_due']); }
    $section('🔁 Routine', $h, $t);

    if (!$d['overdue'] && !$d['today'] && !$d['week'] && !$d['doing']) {
        array_unshift($html, '<p>Nessun memo in scadenza.</p>');
        array_unshift($text, 'Nessun memo in scadenza.');
    }

    $htmlOut = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#222;line-height:1.4;">'
             . '<p style="margin:0 0 4px 0;"><strong>Memo Board — ' . $e(date('d/m/Y', strtotime($today))) . '</strong> · '
             . '<a href="' . $board . '" style="color:#C0211B;">apri la board</a></p>'
             . implode('', $html)
             . '<p style="color:#999;font-size:11px;margin-top:20px;">DIGEST-COUNTS: ' . $e($counts) . '</p></div>';
    $textOut = 'Memo Board — ' . date('d/m/Y', strtotime($today)) . "\n" . $board . "\n\n"
             . implode("\n\n", $text) . "\n\nDIGEST-COUNTS: " . $counts . "\n";
    return array('subject' => $subject, 'html' => $htmlOut, 'text' => $textOut, 'counts' => $counts);
}
