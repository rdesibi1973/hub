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
    $p = iti_get_program($id);
    if (!$p) throw new RuntimeException('Program not found.');
    $from = in_array($p['display_language'] ?? '', ITI_LANGUAGES, true) ? $p['display_language'] : 'it';
    if ($from === $to) return ['filled' => 0, 'from' => $from];

    // Imported samples carry the source title in title_en too (NOT NULL column): treat it as missing.
    if ($to === 'en' && trim((string)$p['title_en']) !== '' && $p['title_en'] === ($p['title_' . $from] ?? null)) {
        $db->prepare("UPDATE iti_programs SET title_en = '' WHERE id = ?")->execute([$id]);
        $p['title_en'] = '';
    }

    $items = [];   // key => source text
    $targets = []; // key => [table, id column value, column]
    $want = function (string $key, ?string $src, $tgt, string $table, int $rowId, string $col) use (&$items, &$targets) {
        if (trim((string)$src) === '' || trim((string)$tgt) !== '') return;
        $items[$key] = (string)$src;
        $targets[$key] = [$table, $rowId, $col];
    };

    foreach (['title', 'subtitle', 'intro'] as $f) {
        if (array_key_exists($f . '_' . $from, $p)) $want('p_' . $f, $p[$f . '_' . $from], $p[$f . '_' . $to] ?? '', 'iti_programs', $id, $f . '_' . $to);
    }
    $days = iti_get_days($id);
    $lodgeIds = []; $destIds = [];
    foreach ($days as $d) {
        $did = (int)$d['id'];
        $want('d' . $did . '_t', $d['day_title_' . $from] ?? '', $d['day_title_' . $to] ?? '', 'iti_program_days', $did, 'day_title_' . $to);
        $want('d' . $did . '_n', $d['narrative_' . $from] ?? '', $d['narrative_' . $to] ?? '', 'iti_program_days', $did, 'narrative_' . $to);
        if (!empty($d['end_lodge_id']))   $lodgeIds[(int)$d['end_lodge_id']] = true;
        if (!empty($d['destination_id'])) $destIds[(int)$d['destination_id']] = true;
        try {
            $st = $db->prepare('SELECT * FROM iti_day_activities WHERE program_day_id = ?');
            $st->execute([$did]);
            foreach ($st->fetchAll() as $a) {
                $srcTxt = trim((string)($a['custom_note_' . $from] ?? '')) ?: trim((string)($a['activity_custom'] ?? ''));
                if (empty($a['activity_id']) && array_key_exists('custom_note_' . $to, $a)) {
                    $want('a' . (int)$a['id'], $srcTxt, $a['custom_note_' . $to], 'iti_day_activities', (int)$a['id'], 'custom_note_' . $to);
                }
            }
        } catch (PDOException $e) { /* no custom_note columns */ }
    }
    $st = $db->prepare('SELECT * FROM iti_program_inclusions WHERE program_id = ?');
    $st->execute([$id]);
    foreach ($st->fetchAll() as $r) {
        $want('i' . (int)$r['id'], $r['text_' . $from] ?? '', $r['text_' . $to] ?? '', 'iti_program_inclusions', (int)$r['id'], 'text_' . $to);
    }
    foreach (['iti_lodges' => $lodgeIds, 'iti_destinations' => $destIds] as $table => $ids) {
        if (!$ids) continue;
        foreach ($db->query('SELECT * FROM ' . $table . ' WHERE id IN (' . implode(',', array_map('intval', array_keys($ids))) . ')')->fetchAll() as $r) {
            $want($table . (int)$r['id'], $r['description_' . $from] ?? '', $r['description_' . $to] ?? '', $table, (int)$r['id'], 'description_' . $to);
        }
    }
    if (!$items) return ['filled' => 0, 'from' => $from];

    // Batches of ~12k characters so each request stays well inside max_tokens.
    $batches = []; $cur = []; $size = 0;
    foreach ($items as $k => $txt) {
        if ($cur && $size + mb_strlen($txt) > 12000) { $batches[] = $cur; $cur = []; $size = 0; }
        $cur[$k] = $txt; $size += mb_strlen($txt);
    }
    if ($cur) $batches[] = $cur;

    $filled = 0;
    foreach ($batches as $batch) {
        foreach (iti_tr_call($batch, $from, $to) as $k => $txt) {
            list($table, $rowId, $col) = $targets[$k];
            if (!preg_match('/^[a-z_]+$/', $table) || !preg_match('/^[a-z_]+$/', $col)) continue;
            // Write only if still empty (never overwrite an edited translation).
            $db->prepare("UPDATE {$table} SET {$col} = ? WHERE id = ? AND ({$col} IS NULL OR {$col} = '')")->execute([$txt, $rowId]);
            $filled++;
        }
    }
    return ['filled' => $filled, 'from' => $from];
}

/** How many texts of the programme are still missing in $to (for the "Translate" button). */
function iti_translate_missing(int $id, string $to): int {
    $p = iti_get_program($id);
    if (!$p) return 0;
    $from = in_array($p['display_language'] ?? '', ITI_LANGUAGES, true) ? $p['display_language'] : 'it';
    if ($from === $to) return 0;
    $n = 0;
    foreach (['title', 'subtitle', 'intro'] as $f) {
        $tgt = trim((string)($p[$f . '_' . $to] ?? ''));
        if ($f === 'title' && $to === 'en' && $tgt === trim((string)($p['title_' . $from] ?? ''))) $tgt = '';
        if (trim((string)($p[$f . '_' . $from] ?? '')) !== '' && $tgt === '') $n++;
    }
    foreach (iti_get_days($id) as $d) {
        foreach (['day_title', 'narrative'] as $f) {
            if (trim((string)($d[$f . '_' . $from] ?? '')) !== '' && trim((string)($d[$f . '_' . $to] ?? '')) === '') $n++;
        }
    }
    return $n;
}
