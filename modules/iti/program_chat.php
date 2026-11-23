<?php
/**
 * modules/iti/program_chat.php — the program assistant of program_final.php (logic: includes/iti_ai_chat_service.php,
 * panel: assets/program_chat.js). Only for the people who see the AI buttons (iti_ai_can_see()).
 *   GET  ?action=state&request_id=N&file=F          the person's open conversation on this Calc (or null)
 *   POST action=start   request_id, file, sheet, lang, group_open, sample_id   new conversation + the first answer
 *   POST action=send    chat_id, text                a message of the seller → the answer
 *   POST action=confirm chat_id, card_id             Confirm on a card (saves the mapping / sample; no AI call)
 *   POST action=refuse  chat_id, card_id             "Not right" on a card
 *   POST action=close   chat_id
 *   GET  ?transcript=ID                              the whole conversation (admins)
 */
ob_start();
require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_once __DIR__ . '/includes/iti_functions.php';
require_once __DIR__ . '/includes/iti_ai_chat_service.php';
require_once __DIR__ . '/../leads/dropbox_constants.php';   // DROPBOX_* keys (server-only file, like program_final.php)
require_once __DIR__ . '/../leads/dropbox_helper.php';
require_once __DIR__ . '/../leads/includes/booking_service.php';
require_once __DIR__ . '/../leads/includes/calc_service.php';

$db = db();
$cu = current_user();
$isAdmin = is_admin();

// ── Transcript (admins): where the assistant was needed ──
if (isset($_GET['transcript'])) {
    if (!$isAdmin) { http_response_code(403); exit('Admins only.'); }
    $chat = iti_chat_get($db, (int)$_GET['transcript']);
    if (!$chat) { http_response_code(404); exit('Conversation not found.'); }
    $page_title = 'Assistant conversation #' . $chat['id'];
    $extra_css = iti_extra_css();
    include __DIR__ . '/../../includes/layout_header.php';
    echo '<main>'; iti_nav('Settings');
    echo '<div class="page-header"><div><h2>Assistant conversation #' . $chat['id'] . '</h2><div class="sub">' . h($chat['username'] ?? '—') . ' · request #' . $chat['request_id']
       . ' · ' . h($chat['calc_file']) . ' · ' . $chat['turns'] . ' messages · ' . number_format($chat['cost_usd'], 3) . ' USD · ' . h($chat['created_at']) . '</div></div>'
       . '<a class="btn btn-outline btn-sm" href="program_final.php?request_id=' . $chat['request_id'] . '">Program from Calc →</a></div>';
    echo '<div class="form-card">';
    foreach ($chat['messages'] as $m) {
        foreach ($m['content'] as $b) {
            $t = $b['type'] ?? '';
            if ($t === 'text') {
                $txt = (string)$b['text'];
                if ($m['role'] === 'user' && strpos($txt, 'Status of the booking (JSON):') === 0) {
                    $cut = strpos($txt, "\n\n");
                    echo '<details style="margin:6px 0;"><summary style="font-size:.8rem;color:var(--grey-mid);">Status sent with the first message</summary><pre style="white-space:pre-wrap;font-size:.72rem;">'
                       . h(substr($txt, 0, $cut ?: strlen($txt))) . '</pre></details>';
                    $txt = $cut ? substr($txt, $cut + 2) : '';
                }
                if ($txt !== '') echo '<div style="margin:8px 0;padding:8px 10px;border-radius:6px;background:' . ($m['role'] === 'user' ? '#f3f6fb' : '#fff') . ';border:1px solid var(--grey-lt);">'
                                    . '<div style="font-size:.7rem;color:var(--grey-mid);">' . ($m['role'] === 'user' ? 'Seller / Hub' : 'Assistant') . '</div>' . nl2br(h($txt)) . '</div>';
            } elseif ($t === 'tool_use') {
                echo '<div style="font-size:.76rem;color:#2f5597;margin:2px 0 2px 12px;">🔧 ' . h($b['name']) . ' ' . h(json_encode($b['input'] ?? new stdClass(), JSON_UNESCAPED_UNICODE)) . '</div>';
            } elseif ($t === 'tool_result') {
                $c = is_string($b['content'] ?? null) ? $b['content'] : json_encode($b['content'] ?? '', JSON_UNESCAPED_UNICODE);
                echo '<div style="font-size:.72rem;color:' . (!empty($b['is_error']) ? 'var(--red)' : 'var(--grey-dk)') . ';margin:2px 0 2px 24px;white-space:pre-wrap;">↳ ' . h(mb_substr($c, 0, 600)) . (mb_strlen($c) > 600 ? ' …' : '') . '</div>';
            }
        }
    }
    echo '</div><div class="form-card" style="margin-top:12px;"><div class="form-section-title">Cards</div>';
    foreach ($chat['cards'] as $c) echo '<div style="font-size:.82rem;">' . h($c['status']) . ' — ' . h($c['label']) . '</div>';
    echo '</div></main>';
    include __DIR__ . '/../../includes/layout_footer.php';
    exit;
}

$json = function (array $d, int $code = 200) {
    if (ob_get_length()) ob_clean();
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
};
if (!iti_ai_can_see()) $json(['ok' => false, 'error' => 'The assistant is not enabled for your user.'], 403);
$action = (string)($_POST['action'] ?? $_GET['action'] ?? '');
$post = $_SERVER['REQUEST_METHOD'] === 'POST';
if ($post && !hash_equals(csrf_token(), (string)($_POST['csrf'] ?? ''))) $json(['ok' => false, 'error' => 'Session expired — reload the page.'], 403);
session_write_close();   // a message can take a minute: do not lock the user's other pages
$uid = isset($cu['id']) ? (int)$cu['id'] : null;

// The conversation of a POST: the person's own (an admin may continue anyone's).
$mine = function () use ($db, $uid, $isAdmin, $json) {
    $chat = iti_chat_get($db, (int)($_POST['chat_id'] ?? 0));
    if (!$chat || (!$isAdmin && $chat['user_id'] !== $uid)) $json(['ok' => false, 'error' => 'Conversation not found.'], 404);
    return $chat;
};

try {
    switch ($action) {
        case 'state':
            $chat = iti_chat_open_for($db, (int)($_GET['request_id'] ?? 0), $uid, (string)($_GET['file'] ?? ''));
            $json(['ok' => true, 'chat' => $chat ? iti_chat_out($chat) : null, 'limit' => iti_ai_settings()['chat']]);

        case 'start':
            if (!$post) $json(['ok' => false, 'error' => 'POST only'], 405);
            @set_time_limit(280);
            $rid = (int)($_POST['request_id'] ?? 0);
            $file = trim((string)($_POST['file'] ?? ''));
            if (!$rid || $file === '') $json(['ok' => false, 'error' => 'Request or Calc missing.'], 400);
            $lang = in_array($_POST['lang'] ?? '', ITI_LANGUAGES, true) ? $_POST['lang'] : 'it';
            $state = ['sample_id' => (int)($_POST['sample_id'] ?? 0), 'group_open' => !empty($_POST['group_open']), 'events' => []];
            $chat = iti_chat_create($db, $rid, $cu, $file, trim((string)($_POST['sheet'] ?? '')), $lang, $state);
            try {
                iti_chat_send($db, $chat, 'Help me build the itinerary program from this Calc: tell me what needs attention first.', $cu, $isAdmin);
            } catch (Throwable $e) {
                $json(['ok' => false, 'error' => $e->getMessage(), 'chat' => iti_chat_out($chat)]);
            }
            $json(['ok' => true, 'chat' => iti_chat_out($chat)]);

        case 'send':
            if (!$post) $json(['ok' => false, 'error' => 'POST only'], 405);
            @set_time_limit(280);
            $chat = $mine();
            try {
                iti_chat_send($db, $chat, (string)($_POST['text'] ?? ''), $cu, $isAdmin);
            } catch (Throwable $e) {
                $json(['ok' => false, 'error' => $e->getMessage(), 'chat' => iti_chat_out($chat)]);
            }
            $json(['ok' => true, 'chat' => iti_chat_out($chat)]);

        case 'confirm':
        case 'refuse':
            if (!$post) $json(['ok' => false, 'error' => 'POST only'], 405);
            $chat = $mine();
            $msg = iti_chat_answer_card($db, $chat, (string)($_POST['card_id'] ?? ''), $action === 'confirm', $cu, $isAdmin);
            $json(['ok' => true, 'message' => $msg, 'chat' => iti_chat_out($chat)]);

        case 'close':
            if (!$post) $json(['ok' => false, 'error' => 'POST only'], 405);
            $chat = $mine();
            $chat['status'] = 'closed';
            iti_chat_save($db, $chat);
            $json(['ok' => true, 'chat' => null]);
    }
    $json(['ok' => false, 'error' => 'Unknown action.'], 400);
} catch (Throwable $e) {
    $json(['ok' => false, 'error' => $e->getMessage()], 500);
}
