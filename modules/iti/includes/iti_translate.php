<?php
/**
 * iti_translate.php — fill a programme's missing texts in one language by
 * translating them with Claude from the programme's source language.
 *
 * Covers: programme title / subtitle / intro, day titles and narratives, activity
 * notes, included / not included, and the descriptions of the programme's lodges
 * and destinations. Only EMPTY target fields are written — edited translations are
 * never overwritten. Raw HTTP to the Messages API (the Anthropic PHP SDK needs a
 * newer PHP than the server's 8.0). Key: ANTHROPIC_API_KEY in includes/config.php.
 */

require_once __DIR__ . '/iti_texts.php';

const ITI_TR_MODEL = 'claude-opus-5';

/** One Messages API call: [id => text] (source lang) → [id => text] (target lang). */
function iti_tr_call(array $items, string $from, string $to): array {
    if (!defined('ANTHROPIC_API_KEY') || !ANTHROPIC_API_KEY) {
        throw new RuntimeException('ANTHROPIC_API_KEY is not configured in includes/config.php.');
    }
    $names = ['it' => 'Italian', 'en' => 'English', 'fr' => 'French', 'es' => 'Spanish', 'de' => 'German'];
    $list = [];
    foreach ($items as $id => $text) $list[] = ['id' => (string)$id, 'text' => (string)$text];

    $system = 'You translate safari itinerary texts for Savannah Explorers, a Tanzanian safari operator, '
            . 'from ' . $names[$from] . ' to ' . $names[$to] . '. Write natural, polished travel copy for tour operators '
            . 'and their clients. Keep proper names of parks, lodges, camps, places and airlines unchanged '
            . '(e.g. "Ngorongoro Marera Mountain View Lodge", "Tarangire National Park"). Keep numbers, times, prices '
            . 'and blank-line paragraph breaks exactly as in the source; keep a leading "- " list marker when present. '
            . 'Return every item with its id unchanged.';

    $body = [
        'model'      => ITI_TR_MODEL,
        'max_tokens' => 16000,
        'fallbacks'  => 'default',
        'system'     => $system,
        'output_config' => [
            'effort' => 'medium',
            'format' => [
                'type'   => 'json_schema',
                'schema' => [
                    'type' => 'object',
                    'properties' => [
                        'translations' => [
                            'type'  => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => ['id' => ['type' => 'string'], 'text' => ['type' => 'string']],
                                'required' => ['id', 'text'],
                                'additionalProperties' => false,
                            ],
                        ],
                    ],
                    'required' => ['translations'],
                    'additionalProperties' => false,
                ],
            ],
        ],
        'messages' => [[
            'role'    => 'user',
            'content' => "Translate each item's text.\n\n" . json_encode(['items' => $list], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        ]],
    ];

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 280,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: ' . ANTHROPIC_API_KEY,
            'anthropic-version: 2023-06-01',
            'anthropic-beta: server-side-fallback-2026-07-01',
        ],
        CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
    ]);
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);
    if ($raw === false) throw new RuntimeException('Translation request failed: ' . $cerr);

    $resp = json_decode((string)$raw, true);
    if ($code !== 200 || !is_array($resp)) {
        $msg = is_array($resp) && isset($resp['error']['message']) ? $resp['error']['message'] : substr((string)$raw, 0, 300);
        throw new RuntimeException('Translation API error (HTTP ' . $code . '): ' . $msg);
    }
    $stop = $resp['stop_reason'] ?? '';
    if ($stop === 'refusal')    throw new RuntimeException('The translation request was declined by the model.');
    if ($stop === 'max_tokens') throw new RuntimeException('Translation too long for one request — try again or shorten the texts.');

    $json = '';
    foreach ($resp['content'] ?? [] as $block) {
        if (($block['type'] ?? '') === 'text') { $json = $block['text']; break; }
    }
    $data = json_decode($json, true);
    if (!is_array($data) || !isset($data['translations']) || !is_array($data['translations'])) {
        throw new RuntimeException('Unexpected translation response.');
    }
    $out = [];
    foreach ($data['translations'] as $t) {
        if (isset($t['id'], $t['text']) && isset($items[$t['id']])) $out[$t['id']] = (string)$t['text'];
    }
    return $out;
}

/**
 * Translate everything missing in $to for programme $id, from its source language
 * (display_language, falling back to Italian). Returns ['filled' => n, 'from' => lang].
 */
function iti_translate_program(int $id, string $to): array {
    $db = db();
    $c = iti_texts_collect($db, $id, $to, false);
    if (!$c['items']) return ['filled' => 0, 'from' => $c['from']];

    // Batches of ~12k characters so each request stays well inside max_tokens.
    $batches = []; $cur = []; $size = 0;
    foreach ($c['items'] as $k => $it) {
        if ($cur && $size + mb_strlen($it['source']) > 12000) { $batches[] = $cur; $cur = []; $size = 0; }
        $cur[$k] = $it['source']; $size += mb_strlen($it['source']);
    }
    if ($cur) $batches[] = $cur;

    $filled = 0;
    foreach ($batches as $batch) {
        $r = iti_texts_save($db, $id, $to, iti_tr_call($batch, $c['from'], $to), false);
        $filled += $r['written'];
    }
    return ['filled' => $filled, 'from' => $c['from']];
}

/** How many texts of the programme are still missing in $to (for the "Translate" button). */
function iti_translate_missing(int $id, string $to): int {
    try { return count(iti_texts_collect(db(), $id, $to, false)['items']); }
    catch (Throwable $e) { return 0; }
}
