<?php
/**
 * iti_ai_service.php — the AI buttons of the ITI module and their spending limits.
 *
 * Every Claude call of the module goes through iti_ai_call(): it checks the limits
 * (ITI → Settings → AI), makes the call and records its real cost in iti_ai_usage.
 * A new button adds a row to iti_ai_actions() and calls iti_ai_call(); it never opens
 * its own connection, so it cannot go past the limits. Key: ANTHROPIC_API_KEY in
 * includes/config.php. Raw HTTP, as the server has no Composer packages.
 */

/** USD per million tokens: input, output, cache read, cache write (Anthropic price list, Oct 2026). */
const ITI_AI_PRICES = [
    'claude-opus-5-5'   => [4.00, 20.00, 0.20, 5.00],
    'claude-opus-5'     => [5.00, 25.00, 0.50, 6.25],
    'claude-sonnet-5-5' => [2.00, 10.00, 0.20, 2.50],
    'claude-haiku-5-5'  => [0.10, 0.50, 0.01, 0.125],
];
/** A model missing from the list is counted at this rate, so the limits err on the safe side. */
const ITI_AI_PRICE_UNKNOWN = [10.00, 50.00, 1.00, 12.50];

const ITI_AI_DEFAULT_MONTH = '30';   // USD, whole Hub, calendar month
const ITI_AI_DEFAULT_USER  = '10';   // USD, one person, calendar month
const ITI_AI_DEFAULT_CLICK = '2';    // USD, one press of a button

/**
 * The AI buttons. where: calc = program from Calc page, program = program editor.
 * state: live = works; planned = shown disabled until the tests decide how it should work.
 */
function iti_ai_actions(): array {
    return [
        'map_texts'   => ['label' => 'Propose mappings', 'where' => 'calc', 'state' => 'planned',
                          'help'  => 'Proposes the lodge, activity or route for each Calc text not mapped yet. You confirm each one.'],
        'pick_sample' => ['label' => 'Propose sample', 'where' => 'calc', 'state' => 'planned',
                          'help'  => 'Proposes the sample program closest to the Calc and says why.'],
        'draft_days'  => ['label' => 'Draft day texts', 'where' => 'program', 'state' => 'planned',
                          'help'  => 'Writes the texts of the days the sample does not cover, for you to review.'],
        'check_rules' => ['label' => 'Check client rules', 'where' => 'program', 'state' => 'planned',
                          'help'  => 'Checks prices, names and wording against the rules for client documents. Reports only, changes nothing.'],
    ];
}

function iti_ai_schema(PDO $db): void {
    static $done = false;
    if ($done) return;
    $done = true;
    if (iti_setting('ai_schema_version') === '1') return;
    $db->exec("CREATE TABLE IF NOT EXISTS iti_ai_usage (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        created_at DATETIME NOT NULL,
        user_id INT NULL,
        username VARCHAR(100) NULL,
        action VARCHAR(40) NOT NULL,
        program_id INT NULL,
        request_id INT NULL,
        model VARCHAR(60) NOT NULL,
        input_tokens INT UNSIGNED NOT NULL DEFAULT 0,
        output_tokens INT UNSIGNED NOT NULL DEFAULT 0,
        cache_read_tokens INT UNSIGNED NOT NULL DEFAULT 0,
        cache_write_tokens INT UNSIGNED NOT NULL DEFAULT 0,
        cost_usd DECIMAL(10,5) NOT NULL DEFAULT 0,
        status ENUM('ok','error','blocked') NOT NULL,
        note VARCHAR(255) NULL,
        KEY idx_created (created_at),
        KEY idx_user_created (user_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    iti_set_setting('ai_schema_version', '1');
}

/** Limits and switches, as saved in ITI → Settings → AI. */
function iti_ai_settings(): array {
    return [
        'enabled' => iti_setting('ai_enabled', '1') === '1',
        'month'   => (float)iti_setting('ai_limit_month', ITI_AI_DEFAULT_MONTH),
        'user'    => (float)iti_setting('ai_limit_user', ITI_AI_DEFAULT_USER),
        'click'   => (float)iti_setting('ai_limit_click', ITI_AI_DEFAULT_CLICK),
        'users'   => array_values(array_filter(array_map('intval', explode(',', iti_setting('ai_users'))))),
    ];
}

/** Admins and the people ticked in Settings see the AI buttons still being tested. */
function iti_ai_can_see(): bool {
    return is_admin() || in_array((int)(current_user()['id'] ?? 0), iti_ai_settings()['users'], true);
}

/** Cost in USD of one response, from its usage block. */
function iti_ai_usd(string $model, array $usage): float {
    $p = ITI_AI_PRICES[$model] ?? ITI_AI_PRICE_UNKNOWN;
    return ((int)($usage['input_tokens'] ?? 0) * $p[0]
          + (int)($usage['output_tokens'] ?? 0) * $p[1]
          + (int)($usage['cache_read_input_tokens'] ?? 0) * $p[2]
          + (int)($usage['cache_creation_input_tokens'] ?? 0) * $p[3]) / 1000000;
}

/** Rough cost before the call: about 3.5 bytes per token; the answer about 1.5 times the question, thinking included. */
function iti_ai_estimate(string $model, int $bodyBytes, int $maxTokens, ?int $outputTokens = null): float {
    $in = (int)ceil($bodyBytes / 3.5);
    return iti_ai_usd($model, ['input_tokens' => $in, 'output_tokens' => min($maxTokens, $outputTokens ?? (int)ceil($in * 1.5))]);
}

/** Spending added up during this page load, that is one press of a button. */
function iti_ai_run_spent(float $add = 0.0): float {
    static $total = 0.0;
    $total += $add;
    return $total;
}

/** Limits and what has been spent this calendar month. */
function iti_ai_status(PDO $db, ?int $uid): array {
    iti_ai_schema($db);
    $s = iti_ai_settings();
    $s['month_spent'] = (float)$db->query("SELECT COALESCE(SUM(cost_usd), 0) FROM iti_ai_usage WHERE created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')")->fetchColumn();
    $st = $db->prepare("SELECT COALESCE(SUM(cost_usd), 0) FROM iti_ai_usage WHERE user_id = ? AND created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')");
    $st->execute([$uid]);
    $s['user_spent'] = (float)$st->fetchColumn();
    return $s;
}

/** Why a call costing about $est USD cannot go ahead ('' = it can). $est = 0: only checks what is already spent. */
function iti_ai_limit_reason(array $st, float $est, float $runSpent = 0.0): string {
    if (empty($st['enabled'])) return 'AI is switched off (ITI → Settings → AI).';
    if ($st['month_spent'] + $est >= $st['month']) return 'AI limit reached: the monthly limit of the Hub (' . number_format($st['month'], 2) . ' USD) is used up. An admin can raise it in ITI → Settings → AI.';
    if ($st['user_spent'] + $est >= $st['user']) return 'AI limit reached: your monthly limit (' . number_format($st['user'], 2) . ' USD) is used up. An admin can raise it in ITI → Settings → AI.';
    if ($est > 0 && $runSpent + $est > $st['click']) return 'AI limit reached: this request would cost more than the limit for one click (' . number_format($st['click'], 2) . ' USD).';
    return '';
}

function iti_ai_record(PDO $db, string $action, string $model, array $usage, float $cost, string $status, array $ctx, string $note = ''): void {
    try {
        $cu = current_user();
        $db->prepare('INSERT INTO iti_ai_usage (created_at, user_id, username, action, program_id, request_id, model,
                             input_tokens, output_tokens, cache_read_tokens, cache_write_tokens, cost_usd, status, note)
                      VALUES (NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
           ->execute([$cu['id'] ?? null, $cu['username'] ?? null, $action, $ctx['program_id'] ?? null, $ctx['request_id'] ?? null, $model,
                      (int)($usage['input_tokens'] ?? 0), (int)($usage['output_tokens'] ?? 0),
                      (int)($usage['cache_read_input_tokens'] ?? 0), (int)($usage['cache_creation_input_tokens'] ?? 0),
                      $cost, $status, $note !== '' ? mb_substr($note, 0, 255) : null]);
    } catch (Throwable $e) {
        error_log('iti_ai_record: ' . $e->getMessage());   // the call is already paid for: keep its result
    }
}

/**
 * One Messages API call under the limits. $body: the request; $action: key of iti_ai_actions();
 * $ctx: program_id, request_id, est_output_tokens (when the answer is much shorter or longer than the question).
 * Returns the decoded response; a response with usage is recorded also when its stop_reason is bad, as it is paid for.
 */
function iti_ai_call(array $body, string $action, array $ctx = []): array {
    if (!defined('ANTHROPIC_API_KEY') || !ANTHROPIC_API_KEY) {
        throw new RuntimeException('ANTHROPIC_API_KEY is not configured in includes/config.php.');
    }
    $payload = json_encode($body, JSON_UNESCAPED_UNICODE);
    if ($payload === false) throw new RuntimeException('The AI request could not be encoded: ' . json_last_error_msg());

    $db    = db();
    $model = (string)$body['model'];
    $est   = iti_ai_estimate($model, strlen($payload), (int)($body['max_tokens'] ?? 16000), $ctx['est_output_tokens'] ?? null);
    $why   = iti_ai_limit_reason(iti_ai_status($db, current_user()['id'] ?? null), $est, iti_ai_run_spent());
    if ($why !== '') {
        iti_ai_record($db, $action, $model, [], 0.0, 'blocked', $ctx, $why);
        throw new RuntimeException($why);
    }

    $headers = ['Content-Type: application/json', 'x-api-key: ' . ANTHROPIC_API_KEY, 'anthropic-version: 2023-06-01'];
    if (isset($body['fallbacks'])) $headers[] = 'anthropic-beta: server-side-fallback-2026-07-01';
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 280,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_POSTFIELDS     => $payload,
    ]);
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);
    if ($raw === false) {
        iti_ai_record($db, $action, $model, [], 0.0, 'error', $ctx, $cerr);
        throw new RuntimeException('AI request failed: ' . $cerr);
    }

    $resp = json_decode((string)$raw, true);
    if ($code !== 200 || !is_array($resp)) {
        $msg = is_array($resp) && isset($resp['error']['message']) ? $resp['error']['message'] : substr((string)$raw, 0, 300);
        iti_ai_record($db, $action, $model, [], 0.0, 'error', $ctx, 'HTTP ' . $code . ': ' . $msg);
        throw new RuntimeException('AI API error (HTTP ' . $code . '): ' . $msg);
    }
    $served = isset(ITI_AI_PRICES[$resp['model'] ?? '']) ? $resp['model'] : $model;   // a fallback may answer with another model
    $usage  = is_array($resp['usage'] ?? null) ? $resp['usage'] : [];
    $cost   = iti_ai_usd($served, $usage);
    iti_ai_run_spent($cost);
    iti_ai_record($db, $action, $served, $usage, $cost, 'ok', $ctx);
    return $resp;
}

/** This month's spending per person and per button, and the latest calls (Settings → AI). */
function iti_ai_report(PDO $db): array {
    iti_ai_schema($db);
    $from = "created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')";
    return [
        'users'   => $db->query("SELECT user_id, COALESCE(username, '—') AS username, COUNT(*) AS calls, SUM(cost_usd) AS cost
                                   FROM iti_ai_usage WHERE $from AND status = 'ok' GROUP BY user_id, username ORDER BY cost DESC")->fetchAll(),
        'actions' => $db->query("SELECT action, COUNT(*) AS calls, SUM(cost_usd) AS cost
                                   FROM iti_ai_usage WHERE $from AND status = 'ok' GROUP BY action ORDER BY cost DESC")->fetchAll(),
        'recent'  => $db->query('SELECT created_at, username, action, model, input_tokens, output_tokens, cost_usd, status, note
                                   FROM iti_ai_usage ORDER BY id DESC LIMIT 20')->fetchAll(),
    ];
}

/** Spent / limit bar. The state is also written in words, so it does not rest on the colour alone. */
function iti_ai_meter(float $spent, float $limit): string {
    $pct   = $limit > 0 ? min(100, $spent / $limit * 100) : 100;
    $full  = $spent >= $limit;
    $near  = !$full && $pct >= 80;
    $color = $full ? 'var(--red)' : ($near ? 'var(--amber,#b87c00)' : 'var(--grey-dk)');
    $word  = $full ? ' · limit reached' : ($near ? ' · nearly used' : '');
    return '<div style="font-size:.8rem;color:var(--grey-dk);margin-bottom:4px;"><strong>' . number_format($spent, 2) . '</strong> of ' . number_format($limit, 2) . ' USD' . $word . '</div>'
         . '<div role="meter" aria-valuemin="0" aria-valuemax="' . h((string)$limit) . '" aria-valuenow="' . h((string)round($spent, 2)) . '" style="height:8px;background:var(--grey-lt);border-radius:4px;overflow:hidden;">'
         . '<div style="height:100%;width:' . round($pct, 1) . '%;background:' . $color . ';border-radius:4px;"></div></div>';
}

/** The card with the AI buttons of a page ('' for people who do not see them yet). */
function iti_ai_panel_html(string $where, array $st): string {
    $buttons = '';
    $planned = false;
    foreach (iti_ai_actions() as $a) {
        if ($a['where'] !== $where) continue;
        $planned = $planned || $a['state'] === 'planned';
        $buttons .= '<button type="button" class="btn btn-outline btn-sm" disabled style="opacity:.55;cursor:not-allowed;" title="' . h($a['help']) . '">✨ ' . h($a['label']) . '</button>';
    }
    if ($buttons === '') return '';
    $why = iti_ai_limit_reason($st, 0.0);
    return '<div class="form-card" style="margin-bottom:18px;">'
         . '<div class="form-section-title">AI assistant <span class="badge" style="background:#fff8e1;color:#8a6d00;">test</span></div>'
         . '<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">' . $buttons . '</div>'
         . ($planned ? '<div style="font-size:.78rem;color:var(--grey-mid);margin-top:8px;">Not active yet: these buttons start after the tests.</div>' : '')
         . ($why !== '' ? '<div style="font-size:.82rem;color:var(--red);margin-top:8px;">' . h($why) . '</div>' : '')
         . '<div style="font-size:.78rem;color:var(--grey-mid);margin-top:8px;">This month: you ' . number_format($st['user_spent'], 2) . ' of ' . number_format($st['user'], 2)
         . ' USD · Hub ' . number_format($st['month_spent'], 2) . ' of ' . number_format($st['month'], 2) . ' USD</div>'
         . '</div>';
}

/** Prints the AI card; a problem with the card never stops the page around it. */
function iti_ai_panel(string $where): void {
    try {
        if (iti_ai_can_see()) echo iti_ai_panel_html($where, iti_ai_status(db(), current_user()['id'] ?? null));
    } catch (Throwable $e) {
        error_log('iti_ai_panel: ' . $e->getMessage());
    }
}
