<?php
/**
 * iti_texts.php — the translatable texts of an ITI programme, independent of
 * iti_functions.php (so the Agent API, which runs in the leads context, can use it).
 *
 *   iti_texts_collect($db, $id, $to, $all)  → source texts and where each translation goes
 *   iti_texts_save($db, $id, $to, $texts, $overwrite) → write translations
 *
 * Keys: p_title / p_subtitle / p_intro, d<dayId>_t (title) / d<dayId>_n (narrative),
 * a<activityRowId>, t<transferRowId>, i<inclusionId>, iti_lodges<id>, iti_destinations<id> (descriptions).
 * Used by iti_translate.php (Claude button) and the Agent API (iti_texts / iti_save_texts).
 *
 * Day transfers: `description` is the text in the programme's language, `description_<lang>`
 * the translations (columns added lazily by iti_transfers_schema()).
 */

const ITI_TEXT_LANGS = ['en', 'it', 'fr', 'es', 'de'];

/** Translation columns of iti_day_transfers that exist (description_<lang>); $refresh after an ALTER. */
function iti_transfers_tr_cols(PDO $db, bool $refresh = false): array {
    static $cols = null;
    if ($cols === null || $refresh) {
        $cols = [];
        try {
            foreach ($db->query("SHOW COLUMNS FROM iti_day_transfers LIKE 'description\\_%'")->fetchAll(PDO::FETCH_ASSOC) as $c) {
                if (in_array(substr($c['Field'], 12), ITI_TEXT_LANGS, true)) $cols[] = $c['Field'];
            }
        } catch (PDOException $e) { $cols = []; }
    }
    return $cols;
}

/**
 * Add description_<lang> to iti_day_transfers once (MySQL: no ADD COLUMN IF NOT EXISTS).
 * DDL commits implicitly: call it outside transactions.
 */
function iti_transfers_schema(PDO $db): void {
    static $done = false;
    if ($done) return;
    $done = true;
    if (count(iti_transfers_tr_cols($db)) === count(ITI_TEXT_LANGS)) return;
    foreach (ITI_TEXT_LANGS as $l) {
        try { $db->exec('ALTER TABLE iti_day_transfers ADD COLUMN description_' . $l . ' VARCHAR(500) NULL'); } catch (PDOException $e) {}
    }
    iti_transfers_tr_cols($db, true);
}

/**
 * Replace the transfers of a day. $items: "text" or ['description' => text, 'tr' => [lang => translation]].
 * A transfer whose text is unchanged keeps its translations (the editor and the API rewrite the whole list).
 */
function iti_transfers_replace(PDO $db, int $dayId, array $items): void {
    $cols = iti_transfers_tr_cols($db);
    $keep = [];
    if ($cols) {
        $st = $db->prepare('SELECT * FROM iti_day_transfers WHERE program_day_id = ? ORDER BY sort_order, id');
        $st->execute([$dayId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $k = trim((string)$r['description']);
            if (!isset($keep[$k])) $keep[$k] = $r;
        }
    }
    $db->prepare('DELETE FROM iti_day_transfers WHERE program_day_id = ?')->execute([$dayId]);
    $n = 0;
    foreach ($items as $it) {
        $desc = trim(is_array($it) ? (string)($it['description'] ?? '') : (string)$it);
        if ($desc === '') continue;
        $row = ['program_day_id' => $dayId, 'description' => $desc, 'sort_order' => ++$n];
        foreach ($cols as $c) {
            $l = substr($c, 12);
            $v = is_array($it) && isset($it['tr'][$l]) ? trim((string)$it['tr'][$l]) : '';
            if ($v === '' && isset($keep[$desc])) $v = trim((string)$keep[$desc][$c]);
            if ($v !== '') $row[$c] = $v;
        }
        $db->prepare('INSERT INTO iti_day_transfers (`' . implode('`,`', array_keys($row)) . '`) VALUES ('
                     . implode(',', array_fill(0, count($row), '?')) . ')')->execute(array_values($row));
    }
}

/** Source language of a programme (display_language, else Italian). */
function iti_texts_source(array $p): string {
    return in_array($p['display_language'] ?? '', ITI_TEXT_LANGS, true) ? $p['display_language'] : 'it';
}

/**
 * Texts of programme $id to translate into $to.
 * $all = false → only those whose target is empty; true → every text (with the current target).
 * Returns ['from', 'items' => [key => ['source', 'target']], 'targets' => [key => [table, id, column]]].
 */
function iti_texts_collect(PDO $db, int $id, string $to, bool $all = false): array {
    if (!in_array($to, ITI_TEXT_LANGS, true)) throw new InvalidArgumentException('Unknown language "' . $to . '".');
    $st = $db->prepare('SELECT * FROM iti_programs WHERE id = ?');
    $st->execute([$id]);
    $p = $st->fetch(PDO::FETCH_ASSOC);
    if (!$p) throw new InvalidArgumentException('Program ' . $id . ' not found.');
    $from = iti_texts_source($p);
    $out = ['from' => $from, 'items' => [], 'targets' => []];
    if ($from === $to) return $out;
    iti_transfers_schema($db);

    $add = function (string $key, $src, $tgt, string $table, int $rowId, string $col) use (&$out, $all) {
        $src = trim((string)$src); $tgt = trim((string)$tgt);
        if ($src === '' || (!$all && $tgt !== '')) return;
        $out['items'][$key]   = ['source' => $src, 'target' => $tgt];
        $out['targets'][$key] = [$table, $rowId, $col];
    };

    foreach (['title', 'subtitle', 'intro'] as $f) {
        if (!array_key_exists($f . '_' . $from, $p) || !array_key_exists($f . '_' . $to, $p)) continue;
        $tgt = $p[$f . '_' . $to];
        // Imported samples carry the source title in title_en too (NOT NULL column).
        if ($f === 'title' && $to === 'en' && trim((string)$tgt) === trim((string)$p['title_' . $from])) $tgt = '';
        $add('p_' . $f, $p[$f . '_' . $from], $tgt, 'iti_programs', $id, $f . '_' . $to);
    }

    $days = $db->prepare('SELECT * FROM iti_program_days WHERE program_id = ? ORDER BY day_number');
    $days->execute([$id]);
    $lodgeIds = []; $destIds = [];
    $acts = $db->prepare('SELECT * FROM iti_day_activities WHERE program_day_id = ? ORDER BY sort_order, id');
    $trs  = $db->prepare('SELECT * FROM iti_day_transfers WHERE program_day_id = ? ORDER BY sort_order, id');
    foreach ($days->fetchAll(PDO::FETCH_ASSOC) as $d) {
        $did = (int)$d['id'];
        $add('d' . $did . '_t', $d['day_title_' . $from] ?? '', $d['day_title_' . $to] ?? '', 'iti_program_days', $did, 'day_title_' . $to);
        $add('d' . $did . '_n', $d['narrative_' . $from] ?? '', $d['narrative_' . $to] ?? '', 'iti_program_days', $did, 'narrative_' . $to);
        if (!empty($d['end_lodge_id']))   $lodgeIds[(int)$d['end_lodge_id']] = true;
        if (!empty($d['destination_id'])) $destIds[(int)$d['destination_id']] = true;
        $acts->execute([$did]);
        foreach ($acts->fetchAll(PDO::FETCH_ASSOC) as $a) {
            if (!empty($a['activity_id']) || !array_key_exists('custom_note_' . $to, $a)) continue;
            $src = trim((string)($a['custom_note_' . $from] ?? '')) ?: trim((string)($a['activity_custom'] ?? ''));
            $add('a' . (int)$a['id'], $src, $a['custom_note_' . $to], 'iti_day_activities', (int)$a['id'], 'custom_note_' . $to);
        }
        $trs->execute([$did]);
        foreach ($trs->fetchAll(PDO::FETCH_ASSOC) as $t) {
            if (!array_key_exists('description_' . $to, $t)) continue;
            $add('t' . (int)$t['id'], $t['description'], $t['description_' . $to], 'iti_day_transfers', (int)$t['id'], 'description_' . $to);
        }
    }

    $inc = $db->prepare('SELECT * FROM iti_program_inclusions WHERE program_id = ? ORDER BY sort_order, id');
    $inc->execute([$id]);
    foreach ($inc->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $add('i' . (int)$r['id'], $r['text_' . $from] ?? '', $r['text_' . $to] ?? '', 'iti_program_inclusions', (int)$r['id'], 'text_' . $to);
    }

    foreach (['iti_lodges' => $lodgeIds, 'iti_destinations' => $destIds] as $table => $ids) {
        if (!$ids) continue;
        $rows = $db->query('SELECT * FROM ' . $table . ' WHERE id IN (' . implode(',', array_map('intval', array_keys($ids))) . ')');
        foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $add($table . (int)$r['id'], $r['description_' . $from] ?? '', $r['description_' . $to] ?? '', $table, (int)$r['id'], 'description_' . $to);
        }
    }
    return $out;
}

/**
 * Write translations [key => text] into language $to. Unknown keys are ignored.
 * $overwrite = false → only empty targets are written (edited translations kept).
 * Returns ['written' => n, 'skipped' => [keys], 'unknown' => [keys]].
 */
function iti_texts_save(PDO $db, int $id, string $to, array $texts, bool $overwrite = false): array {
    $c = iti_texts_collect($db, $id, $to, true);
    $res = ['written' => 0, 'skipped' => [], 'unknown' => []];
    foreach ($texts as $key => $txt) {
        $key = (string)$key; $txt = trim((string)$txt);
        if (!isset($c['targets'][$key])) { $res['unknown'][] = $key; continue; }
        if ($txt === '') { $res['skipped'][] = $key; continue; }
        list($table, $rowId, $col) = $c['targets'][$key];
        if (!preg_match('/^[a-z_]+$/', $table) || !preg_match('/^[a-z_]+$/', $col)) { $res['unknown'][] = $key; continue; }
        $cur = $c['items'][$key]['target'];
        if (!$overwrite && $cur !== '') { $res['skipped'][] = $key; continue; }
        $db->prepare("UPDATE {$table} SET {$col} = ? WHERE id = ?")->execute([$txt, $rowId]);
        $res['written']++;
    }
    return $res;
}
