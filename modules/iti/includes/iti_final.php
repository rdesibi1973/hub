<?php
/**
 * iti_final.php — ITI final programme built from a confirmed booking's Calc.
 * See ITI_FINAL_PROGRAMME_HANDOFF.md §5.
 *
 *   iti_final_plan()      Calc + sample → per-night plan: lodges / activities /
 *                         routes resolved through the Calc aliases, nights aligned
 *                         with the sample's days, blocking items and flags.
 *   iti_final_generate()  duplicate the sample into a PERSONAL programme with
 *                         stage='final' and overlay the Calc (dates, nights,
 *                         lodges, meals, activities, booking data, guests).
 *
 * Rule: never guess. A Calc text without an alias blocks the generation until it
 * is mapped (ITI → Aliases, or inline on program_final.php). Days of the sample
 * that match the Calc keep their Wetu text; added / changed days get master text
 * and needs_review = 1.
 *
 * Needs iti_functions.php and, for calc_read_request(), the leads helpers
 * (dropbox_helper.php, includes/booking_service.php, includes/calc_service.php).
 */
require_once __DIR__ . '/iti_functions.php';

/** "01_TouristTrophy(GoWorld-Roberto)_GranSafariTanzania_Calc.xlsx" → "GranSafariTanzania". */
function iti_final_code_from_calc(string $file): string {
    $base = preg_replace('/_calc\.xlsx$/i', '', basename($file));
    // Duma-GRP template "…_GRUPPI-Giove(Roberto)_Calc.xlsx": nothing after the last ")" → drop that "(…)" first.
    if (preg_match('/\)\s*_?\s*$/', $base) && substr_count($base, '(') >= 2) $base = preg_replace('/\([^()]*\)\s*_?\s*$/', '', $base);
    $p = strrpos($base, ')');
    if ($p !== false) return trim(substr($base, $p + 1), '_ ');
    $parts = explode('_', $base, 3);          // no "(agency)": NN_Name_Code
    return count($parts) === 3 ? $parts[2] : $base;
}

/**
 * Calc codes of the samples. A sample has one main code (iti_programs.hub_program_code: the Calc template its
 * prices come from) and any number of extra codes here (other Calcs built on the same programme, e.g. a Classic
 * version). One code → one sample. Lazy DDL, outside any transaction.
 */
function iti_sample_codes_schema(PDO $db) {
    static $done = false;
    if ($done) return;
    $done = true;
    $db->exec("CREATE TABLE IF NOT EXISTS iti_sample_codes (
                 code       VARCHAR(80) NOT NULL PRIMARY KEY,
                 sample_id  INT NOT NULL,
                 created_by VARCHAR(50) NULL,
                 created_at DATETIME NOT NULL,
                 KEY idx_sc_sample (sample_id)
               ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/** Extra Calc codes of a sample (the main one is hub_program_code). */
function iti_final_sample_extra_codes(PDO $db, int $sampleId): array {
    iti_sample_codes_schema($db);
    $st = $db->prepare('SELECT code FROM iti_sample_codes WHERE sample_id = ? ORDER BY code');
    $st->execute(array($sampleId));
    return $st->fetchAll(PDO::FETCH_COLUMN);
}

/** The sample with this Calc code, main or extra (prefer the main code, then the display language), or null. */
function iti_final_find_sample(PDO $db, string $code, string $lang) {
    if ($code === '') return null;
    iti_sample_codes_schema($db);
    $st = $db->prepare("SELECT id, program_type, title_en, title_it, display_language, duration_days FROM iti_programs
                         WHERE program_type = 'sample' AND status <> 'cancelled'
                           AND (hub_program_code = ? OR id IN (SELECT sample_id FROM iti_sample_codes WHERE code = ?))
                         ORDER BY (hub_program_code = ?) DESC, (display_language = ?) DESC, id DESC LIMIT 1");
    $st->execute(array($code, $code, $code, $lang));
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ? $r : null;
}

/**
 * Tag a sample with a Calc code. Since 16 Nov 2026 a sample never loses its own code: the new code becomes its
 * main code when it has none, else an extra code. A code already on ANOTHER sample is moved only with $move
 * (an admin, Hub page); without it → InvalidArgumentException naming that sample. $go false = only what would change.
 * Returns ['code', 'sample' => {id, title}, 'as' => 'main'|'extra', 'previous_code' => null (kept for callers:
 * no code is lost any more), 'removed_from' => [{id, title}], 'changed' => bool].
 */
function iti_final_tag_sample(PDO $db, string $code, int $sampleId, bool $go, bool $move = false, string $who = ''): array {
    $code = trim($code);
    if ($code === '' || mb_strlen($code) > 80) throw new InvalidArgumentException('code: the Calc code, 1–80 characters');
    iti_sample_codes_schema($db);
    $st = $db->prepare('SELECT id, program_type, status, title_it, title_en, hub_program_code FROM iti_programs WHERE id = ?');
    $st->execute(array($sampleId));
    $s = $st->fetch(PDO::FETCH_ASSOC);
    if (!$s || $s['program_type'] !== 'sample') throw new InvalidArgumentException('Program #' . $sampleId . ' is not a sample');
    if ($s['status'] === 'cancelled') throw new InvalidArgumentException('Sample #' . $sampleId . ' is cancelled');
    // Other samples holding the code (main or extra).
    $st = $db->prepare("SELECT id, title_it, title_en FROM iti_programs WHERE program_type = 'sample' AND id <> ?
                          AND (hub_program_code = ? OR id IN (SELECT sample_id FROM iti_sample_codes WHERE code = ?)) ORDER BY id");
    $st->execute(array($sampleId, $code, $code));
    $removed = array();
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $o) $removed[] = array('id' => (int)$o['id'], 'title' => $o['title_it'] ?: $o['title_en']);
    if ($removed && !$move) {
        throw new InvalidArgumentException('Calc code "' . $code . '" is already used by sample #' . $removed[0]['id'] . ' ' . $removed[0]['title']
            . ' — only an admin can move it to another sample.');
    }
    $main  = (string)$s['hub_program_code'];
    $extra = iti_final_sample_extra_codes($db, $sampleId);
    $as = ($main === '' || strcasecmp($main, $code) === 0) ? 'main' : 'extra';
    $has = strcasecmp($main, $code) === 0 || in_array(strtolower($code), array_map('strtolower', $extra), true);
    $changed = $removed || !$has;
    if ($go && $changed) {
        $db->beginTransaction();
        try {
            $db->prepare("UPDATE iti_programs SET hub_program_code = NULL WHERE program_type = 'sample' AND hub_program_code = ? AND id <> ?")
               ->execute(array($code, $sampleId));
            $db->prepare('DELETE FROM iti_sample_codes WHERE code = ? AND sample_id <> ?')->execute(array($code, $sampleId));
            if (!$has) {
                if ($as === 'main') $db->prepare('UPDATE iti_programs SET hub_program_code = ? WHERE id = ?')->execute(array($code, $sampleId));
                else $db->prepare('INSERT INTO iti_sample_codes (code, sample_id, created_by, created_at) VALUES (?, ?, ?, ?)')
                        ->execute(array($code, $sampleId, $who !== '' ? mb_substr($who, 0, 50) : null, date('Y-m-d H:i:s')));
            }
            $db->commit();
        } catch (Throwable $e) { $db->rollBack(); throw $e; }
    }
    return array('code' => $code, 'sample' => array('id' => (int)$s['id'], 'title' => $s['title_it'] ?: $s['title_en']), 'as' => $as,
                 'previous_code' => null, 'removed_from' => $removed, 'changed' => (bool)$changed);
}

/**
 * A Calc code has no sample and someone used sample $sampleId for one booking only (or asked to tag it): one memo on
 * Roberto's board per code, so an admin decides whether that sample is right for every Calc with this code.
 * Never throws.
 */
function iti_final_sample_code_memo(PDO $db, string $code, int $sampleId, ?int $requestId, string $who, string $how, bool $tagged = false): void {
    try {
        require_once __DIR__ . '/../../leads/includes/folder_link_service.php';   // fl_memo_upsert()
        $st = $db->prepare('SELECT title_it, title_en FROM iti_programs WHERE id = ?');
        $st->execute(array($sampleId));
        $s = $st->fetch(PDO::FETCH_ASSOC);
        $title = $s ? ($s['title_it'] ?: $s['title_en']) : '?';
        $e = function ($x) { return htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8'); };
        if ($tagged) {
            $html = '<p>' . $e($who) . ' ' . $e($how) . ' sample <b>#' . $sampleId . ' ' . $e($title) . '</b> with the Calc code <b>' . $e($code) . '</b>: '
                  . 'every “' . $e($code) . '” Calc of any booking now starts from it.</p><p>Check its days and texts in ITI. If it is wrong, '
                  . 'fix the sample, or (admin) move the code to the right sample from the ITI program page of a request.</p>';
            $memoTitle = 'ITI: new sample for Calc code "' . $code . '" to check';
        } else {
            $html = '<p>No ITI sample has the Calc code <b>' . $e($code) . '</b>. ' . $e($who) . ' ' . $e($how) . ' sample <b>#' . $sampleId . ' ' . $e($title) . '</b>'
                  . ($requestId ? ' for request #' . (int)$requestId : '') . '.</p>'
                  . '<p>If that sample is right for every “' . $e($code) . '” Calc, tag it (admin: ITI program page of the request → “Use this sample” with '
                  . '“for every Calc” ticked). If not, create a proper sample for “' . $e($code) . '”.</p>';
            $memoTitle = 'ITI: no sample for Calc code "' . $code . '"';
        }
        fl_memo_upsert($db, 'iti-sample-code:' . mb_strtolower($code), $memoTitle, $html, $requestId, 'normal', 'iti');
    } catch (Throwable $e) {
        error_log('iti_final_sample_code_memo: ' . $e->getMessage());
    }
}

/**
 * Months a sample title names: "LUG-SET" → [7,8,9], "DIC2026-MAR2027" → [12,1,2,3],
 * "ESTATE" → [6..9], "INVERNO" → [12,1,2,3]. Italian and English. [] = no season.
 */
function iti_final_title_months(string $title): array {
    $mon = array('gen' => 1, 'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'mag' => 5, 'may' => 5, 'giu' => 6, 'jun' => 6,
                 'lug' => 7, 'jul' => 7, 'ago' => 8, 'aug' => 8, 'set' => 9, 'sep' => 9, 'ott' => 10, 'oct' => 10,
                 'nov' => 11, 'dic' => 12, 'dec' => 12);
    $t = strtolower($title);
    $alt = implode('|', array_keys($mon));
    if (preg_match('/\b(' . $alt . ')[a-z]*\s*\d{0,4}\s*(?:-|–|\/)\s*(' . $alt . ')[a-z]*/', $t, $m)) {
        $out = array();
        for ($i = $mon[$m[1]], $k = 0; $k < 12; $i = $i % 12 + 1, $k++) { $out[] = $i; if ($i === $mon[$m[2]]) break; }
        return $out;
    }
    if (preg_match('/\b(estate|summer)\b/', $t)) return array(6, 7, 8, 9);
    if (preg_match('/\b(inverno|winter)\b/', $t)) return array(12, 1, 2, 3);
    return array();
}

/**
 * The sample to propose when none is tagged with the Calc code yet. Scores each
 * sample on:
 *  - its itinerary: the Calc nights aligned with the sample's days (same lodge,
 *    same destination or a lodge within ITI_FINAL_NEAR_KM — as iti_final_plan()),
 *    as a share of the longer of the two (0–10 points);
 *  - its name: the whole code in the title (+3), else each code word in it
 *    ("MigrazioneEstate" → migrazione, estate; +1.5 each);
 *  - the season: the Calc start month inside the months of the title (+2, outside −3);
 *  - the same number of days (+1, one off +0.5), the language (+0.5), already
 *    tagged with another code (−1).
 * $samples = rows with id, title_en, title_it, duration_days, display_language, hub_program_code;
 * $planDays = iti_final_plan($db, $calc, 0)['days'].
 * Returns ['id' => sample id or 0 (nothing close / a tie), 'why' => string].
 */
function iti_final_suggest_sample(PDO $db, array $samples, string $code, array $calc, array $planDays, string $lang): array {
    $norm  = function ($s) { return preg_replace('/[^a-z0-9]+/', '', strtolower((string)$s)); };
    $c     = $norm($code);
    $words = array();   // "MigrazioneEstate" / "Grande_Migrazione" → migrazione, estate, grande
    foreach (preg_split('/[^a-z0-9]+/', strtolower(preg_replace('/(?<=[a-z])(?=[A-Z])/', ' ', $code))) as $w) {
        if (strlen($w) >= 4) $words[] = $w;
    }
    $startMonth = !empty($calc['start_date']) ? (int)date('n', strtotime($calc['start_date'])) : 0;
    $nDays = count($calc['days']);
    $mapped = 0;
    foreach ($planDays as $d) if ($d['lodge_id']) $mapped++;

    $rank = array();
    foreach ($samples as $s) {
        $why = array(); $score = 0.0;
        // Itinerary.
        if ($mapped) {
            $m = iti_final_match_sample($db, $planDays, iti_final_sample_days($db, (int)$s['id']));
            $fit = $m['max'] ? $m['score'] / $m['max'] : 0;
            $score += 10 * $fit;
            if ($fit >= 0.3) $why[] = 'itinerary ' . round(100 * $fit) . '% alike';
        }
        // Name.
        $title = $s['title_it'] . ' ' . $s['title_en'];
        $tn = $norm($title);
        if ($c !== '' && strpos($tn, $c) !== false) { $score += 3; $why[] = 'title contains “' . $code . '”'; }
        else {
            $hit = array();
            foreach ($words as $w) { if (strpos($tn, substr($w, 0, max(5, strlen($w) - 2))) !== false) $hit[] = $w; }
            if ($hit) { $score += 1.5 * count($hit); $why[] = 'title has “' . implode('”, “', $hit) . '”'; }
        }
        // Season.
        $months = iti_final_title_months($title);
        if ($months && $startMonth) {
            if (in_array($startMonth, $months, true)) { $score += 2; $why[] = 'season fits'; }
            else $score -= 3;
        }
        $dd = abs((int)$s['duration_days'] - $nDays);
        if ($dd === 0) { $score += 1; $why[] = $nDays . ' days like the Calc'; }
        elseif ($dd === 1) $score += 0.5;
        if ($s['display_language'] === $lang) $score += 0.5;
        if (!empty($s['hub_program_code']) && $s['hub_program_code'] !== $code) $score -= 1;
        $rank[(int)$s['id']] = array($score, $why);
    }
    uasort($rank, function ($a, $b) { return $b[0] <=> $a[0]; });
    $ids = array_keys($rank);
    if (!$ids) return array('id' => 0, 'why' => '');
    $best = $rank[$ids[0]];
    $second = isset($ids[1]) ? $rank[$ids[1]][0] : -INF;
    // Something real must match (≥ 4 points: e.g. half the itinerary, or the code), and clearly ahead.
    if ($best[0] < 4 || $best[0] - $second < 0.5) return array('id' => 0, 'why' => '');
    return array('id' => $ids[0], 'why' => implode(', ', $best[1]));
}

/**
 * Content choice thresholds (tested on 12 real Calcs, 16 Nov 2026: the samples share many lodges, so scores are close).
 * The content decides alone when the best sample matches ≥ ITI_FINAL_PICK_MIN_FIT of the itinerary and is
 * ITI_FINAL_PICK_MARGIN points ahead of the next; otherwise the sample of the file's code wins when it is within
 * ITI_FINAL_PICK_CODE_GAP points of the best; otherwise nothing is chosen (the user picks, Roberto gets a memo).
 */
if (!defined('ITI_FINAL_PICK_MIN_FIT'))  define('ITI_FINAL_PICK_MIN_FIT', 0.7);
if (!defined('ITI_FINAL_PICK_MARGIN'))   define('ITI_FINAL_PICK_MARGIN', 5);
if (!defined('ITI_FINAL_PICK_CODE_GAP')) define('ITI_FINAL_PICK_CODE_GAP', 10);

/**
 * The sample of a Calc, chosen from the Calc's CONTENT (16 Nov 2026), not from its file name: a Calc saved as 02
 * from 01 keeps the name but may be another trip. Every active sample is scored on how many Calc nights it matches
 * (iti_final_match_sample(): same lodge, same destination or a lodge within ITI_FINAL_NEAR_KM), then:
 *   season of the title vs the Calc start month (fits +2 points, outside −10), private vs group (other type −15),
 *   the sample tagged with the file's code (+3: a tie-break only), the language (+0.5); points out of 100 = % alike.
 * Chosen when the best matches ≥ ITI_FINAL_PICK_MIN_FIT of the itinerary. When fewer than half the Calc nights have a
 * mapped lodge the content says little: the code's sample (if any) is used for now, 'reliable' false — the choice is
 * made again once the hotels are mapped.
 * $planDays = iti_final_plan($db, $calc, 0)['days']; $isGroup = a GRP departure.
 * Returns ['sample' => row (id, title_it, title_en, duration_days, display_language) | null, 'fit' => 0–100,
 *          'why' => text, 'how' => 'content'|'code'|'none', 'reliable' => bool, 'mapped' => n, 'nights' => n,
 *          'ranking' => [{id, title, fit, days, why}] (best 5)].
 */
function iti_final_pick_sample(PDO $db, array $calc, array $planDays, string $code, string $lang, bool $isGroup = false): array {
    $nights = 0; $mapped = 0;
    foreach ($planDays as $d) {
        if ($d['last'] && $d['hotel_text'] === '') continue;   // departure day
        $nights++;
        if ($d['lodge_id']) $mapped++;
    }
    $reliable = $nights > 0 && $mapped / $nights >= 0.5;
    $tagged = $code !== '' ? iti_final_find_sample($db, $code, $lang) : null;
    $startMonth = !empty($calc['start_date']) ? (int)date('n', strtotime($calc['start_date'])) : 0;
    $cols = iti_table_columns('iti_programs');
    $typeCol = in_array('safari_type', $cols, true) ? ', safari_type' : '';
    $samples = $db->query("SELECT id, title_it, title_en, duration_days, display_language, hub_program_code$typeCol FROM iti_programs
                            WHERE program_type = 'sample' AND status <> 'cancelled' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    // A climb: most nights in huts / campsites, or a route name in its code (Marangu, Machame…) — never a safari
    // sample, and back (a climb + safari with fewer mountain nights counts as a safari, unless its code names the route).
    $calcTrek = preg_match(ITI_TREK_RE, $code) === 1;
    if (!$calcTrek && $nights > 0) {
        $mtn = array();
        try {
            foreach ($db->query("SELECT id FROM iti_lodges WHERE lodge_type IN ('hut', 'campsite')")->fetchAll(PDO::FETCH_COLUMN) as $lid) $mtn[(int)$lid] = true;
        } catch (PDOException $e) {}
        $n = 0;
        foreach ($planDays as $d) if (!empty($d['lodge_id']) && isset($mtn[(int)$d['lodge_id']])) $n++;
        $calcTrek = $n * 2 > $nights;
    }
    $rank = array();
    foreach ($samples as $s) {
        $m = iti_final_match_sample($db, $planDays, iti_final_sample_days($db, (int)$s['id']));
        $fit = $m['max'] ? $m['score'] / $m['max'] : 0;
        $pts = 100 * $fit; $why = array(round(100 * $fit) . '% of the itinerary alike');
        $months = iti_final_title_months($s['title_it'] . ' ' . $s['title_en']);
        if ($months && $startMonth) {
            if (in_array($startMonth, $months, true)) { $pts += 2; $why[] = 'season fits'; }
            else { $pts -= 10; $why[] = 'other season'; }
        }
        if ($typeCol !== '' && in_array($s['safari_type'], array('group', 'private'), true) && ($s['safari_type'] === 'group') !== $isGroup) {
            $pts -= 15; $why[] = $s['safari_type'] === 'group' ? 'group sample' : 'private sample';
        }
        if ($typeCol !== '' && ($s['safari_type'] === 'trekking') !== $calcTrek) {
            $pts -= 25; $why[] = $calcTrek ? 'not a trekking sample' : 'trekking sample';
        }
        if ($tagged && (int)$tagged['id'] === (int)$s['id']) { $pts += 3; $why[] = 'Calc named “' . $code . '”'; }
        if ($s['display_language'] === $lang) $pts += 0.5;
        $rank[] = array('row' => $s, 'pts' => $pts, 'fit' => (int)round(100 * $fit), 'why' => implode(', ', $why));
    }
    usort($rank, function ($a, $b) { return $b['pts'] <=> $a['pts'] ?: $a['row']['id'] <=> $b['row']['id']; });
    $ranking = array();
    foreach (array_slice($rank, 0, 5) as $r) {
        $ranking[] = array('id' => (int)$r['row']['id'], 'title' => $r['row']['title_it'] ?: $r['row']['title_en'], 'fit' => $r['fit'],
                           'days' => (int)$r['row']['duration_days'], 'why' => $r['why']);
    }
    $out = array('sample' => null, 'fit' => 0, 'why' => '', 'how' => 'none', 'reliable' => $reliable,
                 'mapped' => $mapped, 'nights' => $nights, 'ranking' => $ranking);
    $pick = function ($row) {
        return array('id' => (int)$row['id'], 'program_type' => 'sample', 'title_it' => $row['title_it'], 'title_en' => $row['title_en'],
                     'duration_days' => $row['duration_days'], 'display_language' => $row['display_language']);
    };
    if (!$reliable) {
        if ($tagged) {
            $out['sample'] = $tagged; $out['how'] = 'code';
            $out['why'] = 'only ' . $mapped . ' of ' . $nights . ' Calc hotels mapped: Calc named “' . $code . '” for now';
        }
        return $out;
    }
    if (!$rank) return $out;
    $best = $rank[0];
    $tagRank = null;
    foreach ($rank as $r) if ($tagged && (int)$r['row']['id'] === (int)$tagged['id']) { $tagRank = $r; break; }
    $bestIsTagged = $tagRank && $tagRank === $best;
    $ahead = !isset($rank[1]) || $best['pts'] - $rank[1]['pts'] >= ITI_FINAL_PICK_MARGIN;
    if ($best['fit'] >= 100 * ITI_FINAL_PICK_MIN_FIT && ($ahead || $bestIsTagged)) {
        $out['sample'] = $pick($best['row']); $out['fit'] = $best['fit']; $out['why'] = $best['why']; $out['how'] = 'content';
    } elseif ($tagRank && $tagRank['pts'] >= $best['pts'] - ITI_FINAL_PICK_CODE_GAP) {
        $out['sample'] = $pick($tagRank['row']); $out['fit'] = $tagRank['fit']; $out['how'] = 'code';
        $out['why'] = $tagRank['why'] . ($bestIsTagged ? '' : ' — the content alone is not decisive (best #' . (int)$best['row']['id'] . ' ' . $best['fit'] . '%)');
    } elseif ($best['fit'] >= 100 * ITI_FINAL_PICK_MIN_FIT) {
        $out['sample'] = $pick($best['row']); $out['fit'] = $best['fit']; $out['how'] = 'content';
        $out['why'] = $best['why'] . ' — close to #' . (int)$rank[1]['row']['id'] . ' (' . $rank[1]['fit'] . '%)';
    }
    return $out;
}

/**
 * No sample matches a Calc well enough and someone used sample $sampleId for that booking: one memo per Calc code on
 * Roberto's board (probably a sample is missing). Never throws.
 */
function iti_final_nofit_memo(PDO $db, string $calcFile, int $sampleId, ?int $requestId, string $who, array $ranking): void {
    try {
        require_once __DIR__ . '/../../leads/includes/folder_link_service.php';   // fl_memo_upsert()
        $e = function ($x) { return htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8'); };
        $code = iti_final_code_from_calc($calcFile);
        $st = $db->prepare('SELECT title_it, title_en FROM iti_programs WHERE id = ?');
        $st->execute(array($sampleId));
        $s = $st->fetch(PDO::FETCH_ASSOC);
        $best = array();
        foreach (array_slice($ranking, 0, 3) as $r) $best[] = '#' . (int)$r['id'] . ' ' . $e($r['title']) . ' (' . (int)$r['fit'] . '%)';
        $html = '<p>No ITI sample matches the Calc <b>' . $e($calcFile) . '</b>' . ($requestId ? ' of request #' . (int)$requestId : '')
              . ' well enough (at least ' . (int)round(100 * ITI_FINAL_PICK_MIN_FIT) . '% of its nights). Closest: ' . implode(', ', $best) . '.</p>'
              . '<p>' . $e($who) . ' used sample <b>#' . $sampleId . ' ' . $e($s ? ($s['title_it'] ?: $s['title_en']) : '?') . '</b> for this booking. '
              . 'If trips like this come back, create a sample for them in ITI.</p>';
        fl_memo_upsert($db, 'iti-sample-nofit:' . mb_strtolower($code !== '' ? $code : $calcFile), 'ITI: no sample close to the Calc "' . $calcFile . '"',
                       $html, $requestId, 'normal', 'iti');
    } catch (Throwable $ex) {
        error_log('iti_final_nofit_memo: ' . $ex->getMessage());
    }
}

/** A lodge with its destination, coordinates and room categories (cached), or null. */
function iti_final_lodge_info(PDO $db, int $id) {
    static $cache = array();
    if (!isset($cache[$id])) {
        $st = $db->prepare('SELECT l.*, d.name_en AS dest_name FROM iti_lodges l
                             LEFT JOIN iti_destinations d ON d.id = l.destination_id WHERE l.id = ?');
        $st->execute(array($id));
        $cache[$id] = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    return $cache[$id];
}

/** [lat, lng] of a lodge row, null when unknown (0 / 0 = not set). */
function iti_final_geo($row) {
    $lat = (float)($row['latitude'] ?? 0); $lng = (float)($row['longitude'] ?? 0);
    return (abs($lat) > 0.0001 || abs($lng) > 0.0001) ? array($lat, $lng) : null;
}

/**
 * A sample's days in order: id, day_number, end_lodge_id, lodge_name, destination_id,
 * latitude, longitude, lodge_text, title, places. Samples imported from WeTu often have the lodge
 * only as text ("Mara Pure Migration (Jul - Oct)"): it is read through the lodge
 * aliases, else the closest lodge name, so the day still matches the Calc;
 * 'lodge_text' keeps that text (null when the lodge is linked). 'title' = the first
 * day title set; 'places' = iti_final_places() of the titles and destinations.
 */
function iti_final_sample_days(PDO $db, int $sampleId): array {
    $titles = '';
    foreach (ITI_LANGUAGES as $l) $titles .= ', d.day_title_' . $l;
    $st = $db->prepare('SELECT d.id, d.day_number, d.end_lodge_id, d.end_lodge_custom, l.name AS lodge_name, l.destination_id, l.latitude, l.longitude,
                               CONCAT_WS(\' \', dd.name_en, dd.name_it, ld.name_en, ld.name_it) AS dest_names' . $titles . '
                          FROM iti_program_days d LEFT JOIN iti_lodges l ON l.id = d.end_lodge_id
                          LEFT JOIN iti_destinations dd ON dd.id = d.destination_id
                          LEFT JOIN iti_destinations ld ON ld.id = l.destination_id
                         WHERE d.program_id = ? ORDER BY d.day_number');
    $st->execute(array($sampleId));
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $k => $r) {
        $title = ''; $text = (string)$r['dest_names'];
        foreach (ITI_LANGUAGES as $l) {
            $t = trim((string)$r['day_title_' . $l]);
            if ($title === '') $title = $t;
            $text .= ' ' . $t;
        }
        $rows[$k]['title'] = $title;
        $rows[$k]['places'] = iti_final_places($text);
        $rows[$k]['start_place'] = iti_final_first_place(iti_final_places($title));   // where the day leaves from (its title)
        $rows[$k]['lodge_text'] = null;
        $txt = trim((string)$r['end_lodge_custom']);
        if ($r['end_lodge_id'] || $txt === '') continue;
        $id = iti_final_lodge_from_text($txt);
        if ($id && ($li = iti_final_lodge_info($db, $id))) {
            $rows[$k]['end_lodge_id'] = $id;
            $rows[$k]['lodge_name'] = $li['name'];
            $rows[$k]['destination_id'] = $li['destination_id'];
            $rows[$k]['latitude'] = $li['latitude'];
            $rows[$k]['longitude'] = $li['longitude'];
            $rows[$k]['lodge_text'] = $txt;
        }
    }
    return $rows;
}

/** Lodge id for a free lodge text of a sample: its alias, else the closest name (without "(…)"), else 0. */
function iti_final_lodge_from_text(string $txt): int {
    static $names = null, $cache = array();
    if (isset($cache[$txt])) return $cache[$txt];
    list($base) = iti_alias_split_meal($txt);
    foreach (array($txt, $base) as $t) {
        $a = iti_alias_lookup('lodge', $t);
        if ($a && $a['lodge_id']) return $cache[$txt] = (int)$a['lodge_id'];
    }
    if ($names === null) {
        $names = array();
        foreach (iti_get_lodges() as $l) $names[(int)$l['id']] = $l['name'];
    }
    $id = iti_alias_suggest(trim(preg_replace('/\([^)]*\)/', ' ', $base)), $names);
    return $cache[$txt] = $id ? (int)$id : 0;
}

/**
 * Places a Calc label or a sample day names ("Karatu-Ark-ZNZ" → Karatu, Arusha, Zanzibar;
 * "Pista di Ndutu" → Serengeti): [area => true]. Unknown words ("Home") are left out.
 */
function iti_final_places(string $text): array {
    static $areas = array(
        'Kilimanjaro'   => '/\b(kili|kilimanjaro|jro|kia|moshi)\b/u',
        'Arusha'        => '/\b(arusha|ark)\b/u',
        'Tarangire'     => '/\btarangire\b/u',
        'Manyara'       => '/\b(manyara|mto wa mbu)\b/u',
        'Karatu'        => '/\bkaratu\b/u',
        'Ngorongoro'    => '/\b(ngorongoro|crater|cratere|cratère|krater|cráter)\b/u',
        'Serengeti'     => '/\b(serengeti|ndutu|seronera|kogatende)\b/u',
        'Lake Eyasi'    => '/\beyasi\b/u',
        'Lake Natron'   => '/\bnatron\b/u',
        'Zanzibar'      => '/\b(znz|zanzibar|zanzíbar|sansibar|stone ?town|nungwi|kendwa|kiwengwa|matemwe|pwani|paje|jambiani|michamvi|uroa)\b/u',
        'Dar es Salaam' => '/\bdar\b/u',
        'Nairobi'       => '/\b(nairobi|nbo)\b/u',
        'Amboseli'      => '/\bamboseli\b/u',
        'Masai Mara'    => '/\bma+sai mara\b/u',
    );
    $t = mb_strtolower($text, 'UTF-8');
    $out = array(); $at = array();
    foreach ($areas as $area => $re) if (preg_match($re, $t, $m, PREG_OFFSET_CAPTURE)) { $out[$area] = true; $at[$area] = $m[0][1]; }
    asort($at);   // in the order of the text: the first one is where the day starts (iti_final_first_place())
    return array_intersect_key(array_replace($at, $out), $out);
}

/** Where a day starts: the first place named in its label / title ("Serengeti Kogatende-Arusha" → Serengeti), '' none. */
function iti_final_first_place(array $places): string {
    return $places ? (string)array_key_first($places) : '';
}

/** Two place sets (iti_final_places()) that both name places and share none. */
function iti_final_places_clash(array $a, array $b): bool {
    return $a && $b && !array_intersect_key($a, $b);
}

/**
 * Align the plan's nights with a sample's days on the lodge sequence. Same lodge
 * = 2; same destination, or another lodge within ITI_FINAL_NEAR_KM (The Manor at
 * Ngorongoro ~ Marera in Karatu: two destinations, one area) = 1; the departure
 * day with the sample's last day = 2, unless the Calc label and the sample day name
 * different places ("Znz-Home" vs "Karatu-Arusha-Kilimanjaro": the day is added from the Calc).
 * Returns ['map' => [planIndex => sampleIndex], 'score' => matched points, 'max' => 2 × the longer list].
 */
function iti_final_match_sample(PDO $db, array $planDays, array $sampleDays): array {
    $ka = array(); $kb = array();
    foreach ($planDays as $d) {
        $li = $d['lodge_id'] ? iti_final_lodge_info($db, (int)$d['lodge_id']) : null;
        $ka[] = array('l' => $d['lodge_id'], 'd' => $d['dest_id'], 'end' => $d['last'] && $d['hotel_text'] === '',
                      'geo' => $li ? iti_final_geo($li) : null, 'p' => iti_final_places((string)$d['label']),
                      's' => iti_final_first_place(iti_final_places((string)$d['label'])));
    }
    $m = count($sampleDays);
    foreach ($sampleDays as $j => $s) {
        $kb[] = array('l' => $s['end_lodge_id'] ? (int)$s['end_lodge_id'] : null,
                      'd' => $s['destination_id'] ? (int)$s['destination_id'] : null,
                      'end' => !$s['end_lodge_id'] && $j === $m - 1,
                      'geo' => iti_final_geo($s), 'p' => $s['places'] ?? array(), 's' => $s['start_place'] ?? '');
    }
    $score = function ($x, $y) {
        // Departure days: they must leave from the same place ("Serengeti Kogatende-Arusha" never takes the
        // sample's "Karatu-Arusha-Kilimanjaro", although both name Arusha).
        if ($x['end'] && $y['end']) return iti_final_places_clash($x['p'], $y['p'])
                                        || ($x['s'] !== '' && $y['s'] !== '' && $x['s'] !== $y['s']) ? 0 : 2;
        if ($x['l'] && $x['l'] === $y['l']) return 2;
        if ($x['d'] && $x['d'] === $y['d']) return 1;
        if ($x['geo'] && $y['geo'] && iti_haversine($x['geo'][0], $x['geo'][1], $y['geo'][0], $y['geo'][1]) <= ITI_FINAL_NEAR_KM) return 1;
        return 0;
    };
    $map = iti_final_align($ka, $kb, $score);
    $total = 0;
    foreach ($map as $i => $j) $total += $score($ka[$i], $kb[$j]);
    return array('map' => $map, 'score' => $total, 'max' => 2 * max(count($ka), count($kb)));
}

/**
 * Title of a final programme: the sample's title without the internal language tag,
 * in title case, then the customer — "PUMBA SAFARI IN ITALIANO" + "Marco Ciaolo"
 * → "Pumba Safari – Marco Ciaolo". Empty sample title → ''.
 */
function iti_final_title(string $sampleTitle, string $customer): string {
    $t = trim(preg_replace('/\s+(in\s+(italiano|english|inglese|fran[cç]ais|espa[nñ]ol|deutsch))\s*$/iu', '', $sampleTitle));
    $t = iti_final_strip_season($t);
    if ($t === '') return '';
    if (mb_strtoupper($t, 'UTF-8') === $t) {      // all caps → title case, airport / season codes kept
        $t = mb_convert_case(mb_strtolower($t, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
        $t = preg_replace_callback('/\b(Znz|Jro|Ark|Sto|Dar)\b/u', function ($m) { return strtoupper($m[1]); }, $t);
        // Small words inside the title stay lower case ("Kiboko Safari e Zanzibar").
        $t = preg_replace_callback('/(?<=\s)(E|Ed|Da|Di|Del|In|And|Of|The)(?=\s)/u', function ($m) { return strtolower($m[1]); }, $t);
    }
    $customer = trim($customer);
    return mb_substr($customer !== '' ? $t . ' – ' . $customer : $t, 0, 255);
}

/**
 * A sample title without its season / rate tags, which are for the team, not the
 * client: "SAFARI GRANDE MIGRAZIONE LUG-SET" → "SAFARI GRANDE MIGRAZIONE",
 * "… INVERNO STO DIC2026-MAR2027", "GREAT MIGRATION SAFARI – WINTER, DEC 2026–MAR 2027"
 * → the name only. Month ranges, season words, "STO" and years go.
 */
function iti_final_strip_season(string $title): string {
    $mon = '(?:gen|jan|feb|f[eé]v|mar|apr|avr|abr|mag|may|mai|giu|jun|juin|lug|jul|juil|ago|aug|ao[uû]|set|sep|ott|oct|okt|nov|dic|dec|d[eé]c|dez)\p{L}*';
    $t = preg_replace('/\b' . $mon . '\.?\s*(?:\d{4})?\s*(?:-|–|\/)\s*' . $mon . '\.?\s*(?:\d{4})?/iu', ' ', $title);
    $t = preg_replace('/\b(?:inverno|estate|winter|summer|hiver|[eé]t[eé]|invierno|verano|sommer|sto)\b/iu', ' ', $t);
    $t = preg_replace('/\b\d{4}(?:\s*[-–\/]\s*\d{2,4})?\b/u', ' ', $t);
    $t = preg_replace('/\s*[,–\-:\/]+\s*(?=[,–\-:\/]|$)/u', '', trim(preg_replace('/\s+/u', ' ', $t)));
    return trim($t, " \t,–-:/");
}

/**
 * Two activity names of the same kind: a common word of 6+ letters ("camminata"),
 * generic words aside — game drives are not merged (a night drive is added to the
 * day's game drive, not swapped for it). $a, $b = lists of names (any language).
 */
function iti_final_same_kind(array $a, array $b): bool {
    static $generic = array('activity', 'attivita', 'attività', 'lodge', 'safari', 'fotosafari', 'national', 'nazionale',
                            'giornata', 'intera', 'notturno', 'notturna');
    $words = function (array $names) use ($generic) {
        $w = array();
        foreach ($names as $n) {
            foreach (preg_split('/[^\p{L}]+/u', mb_strtolower((string)$n)) as $x) {
                if (mb_strlen($x) >= 6 && !in_array($x, $generic, true)) $w[$x] = true;
            }
        }
        return $w;
    };
    return (bool)array_intersect_key($words($a), $words($b));
}

/** A plain game drive line with nothing else in it: "Fotosafari", "Game drive", "Safari photo", "Pirschfahrt". */
function iti_final_plain_drive(string $text): bool {
    return (bool)preg_match('/^\s*(foto ?safari|game ?drives?|safari ?photos?|safari fotogr[aá]fico|pirschfahrt(en)?)\s*\.?\s*$/iu', $text);
}

/**
 * Text of a day added from the Calc (not in the sample) that has an activity of its own place: the
 * description of the first Calc activity tied to the day's destination (e.g. "Ngorongoro Crater game
 * drive" on a Ngorongoro day: the descent into the crater), '' when none — the caller then uses the
 * destination's description. Activity descriptions are not printed elsewhere in the documents.
 */
function iti_final_activity_day_text(PDO $db, array $acts, $destId, string $lang): string {
    if (!$destId || !in_array($lang, ITI_LANGUAGES, true)) return '';
    $st = $db->prepare('SELECT description_' . $lang . ' FROM iti_activities WHERE id = ? AND destination_id = ?');
    foreach ($acts as $a) {
        if (empty($a['activity_id'])) continue;
        $st->execute(array((int)$a['activity_id'], (int)$destId));
        $t = trim((string)$st->fetchColumn());
        if ($t !== '' && !(function_exists('iti_is_placeholder_text') && iti_is_placeholder_text($t))) return $t;   // iti_terms.php
    }
    return '';
}

/** Who a program can be put under: active Hub users tied to a seller, [username => full name]. */
function iti_final_sellers(PDO $db): array {
    $st = $db->prepare('SELECT username, full_name FROM users WHERE agent_id IS NOT NULL AND is_active = 1 AND username <> ? ORDER BY full_name');
    $st->execute(array(defined('AGENT_API_USER') ? AGENT_API_USER : 'claude_agent'));
    $out = array();
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $u) $out[$u['username']] = trim((string)$u['full_name']) !== '' ? $u['full_name'] : $u['username'];
    return $out;
}

/** Username of the request's seller, '' when none is an active user. */
function iti_final_request_seller(PDO $db, int $requestId): string {
    $st = $db->prepare('SELECT u.username FROM requests r JOIN users u ON u.agent_id = r.agent_id
                         WHERE r.id = ? AND u.is_active = 1 AND u.username <> ? ORDER BY u.id LIMIT 1');
    $st->execute(array($requestId, defined('AGENT_API_USER') ? AGENT_API_USER : 'claude_agent'));
    return (string)$st->fetchColumn();
}

/** Owner of a generated program: the seller asked for, else the request's seller, else $fallback (who generates it). */
function iti_final_resolve_seller(PDO $db, int $requestId, string $username, string $fallback): string {
    if ($username === '') {
        $s = iti_final_request_seller($db, $requestId);
        return $s !== '' ? $s : $fallback;
    }
    $sellers = iti_final_sellers($db);
    if (!isset($sellers[$username])) throw new RuntimeException('Seller "' . $username . '" is not an active seller of the Hub.');
    return $username;
}

/**
 * The Calc's room text in the program's language: "1 single" → "1 camera singola", "2 DBL + 1 twin" → "2 camere matrimoniali + 1 …".
 * Only the room types of the Calc (SINGLE / DOUBLE / TWIN / TRIPLE / QUAD / FAMILY, also their short and Italian forms);
 * null when a part is not one of them (free text stays as the seller wrote it, the confirmation step says so).
 */
function iti_final_room_text(string $rooms, string $lang): ?string {
    $names = array(
        'it' => array('single' => array('camera singola', 'camere singole'), 'double' => array('camera matrimoniale', 'camere matrimoniali'),
                      'twin' => array('camera doppia con letti separati', 'camere doppie con letti separati'), 'triple' => array('camera tripla', 'camere triple'),
                      'quad' => array('camera quadrupla', 'camere quadruple'), 'family' => array('camera familiare', 'camere familiari')),
        'en' => array('single' => array('single room', 'single rooms'), 'double' => array('double room', 'double rooms'),
                      'twin' => array('twin room', 'twin rooms'), 'triple' => array('triple room', 'triple rooms'),
                      'quad' => array('quadruple room', 'quadruple rooms'), 'family' => array('family room', 'family rooms')),
        'fr' => array('single' => array('chambre individuelle', 'chambres individuelles'), 'double' => array('chambre double', 'chambres doubles'),
                      'twin' => array('chambre à lits jumeaux', 'chambres à lits jumeaux'), 'triple' => array('chambre triple', 'chambres triples'),
                      'quad' => array('chambre quadruple', 'chambres quadruples'), 'family' => array('chambre familiale', 'chambres familiales')),
        'es' => array('single' => array('habitación individual', 'habitaciones individuales'), 'double' => array('habitación doble', 'habitaciones dobles'),
                      'twin' => array('habitación con dos camas', 'habitaciones con dos camas'), 'triple' => array('habitación triple', 'habitaciones triples'),
                      'quad' => array('habitación cuádruple', 'habitaciones cuádruples'), 'family' => array('habitación familiar', 'habitaciones familiares')),
        'de' => array('single' => array('Einzelzimmer', 'Einzelzimmer'), 'double' => array('Doppelzimmer', 'Doppelzimmer'),
                      'twin' => array('Zweibettzimmer', 'Zweibettzimmer'), 'triple' => array('Dreibettzimmer', 'Dreibettzimmer'),
                      'quad' => array('Vierbettzimmer', 'Vierbettzimmer'), 'family' => array('Familienzimmer', 'Familienzimmer')),
    );
    $words = array('single' => 'single', 'singles' => 'single', 'sgl' => 'single', 'singola' => 'single', 'singole' => 'single',
                   'double' => 'double', 'doubles' => 'double', 'dbl' => 'double', 'matrimoniale' => 'double', 'matrimoniali' => 'double',
                   'twin' => 'twin', 'twins' => 'twin', 'twn' => 'twin',
                   'triple' => 'triple', 'triples' => 'triple', 'tpl' => 'triple', 'tripla' => 'triple',
                   'quad' => 'quad', 'quads' => 'quad', 'quadruple' => 'quad', 'quadrupla' => 'quad',
                   'family' => 'family', 'familiare' => 'family');
    $t = isset($names[$lang]) ? $names[$lang] : $names['en'];
    $out = array();
    foreach (preg_split('/\s*(?:\+|,|&|;|\band\b|\be\b)\s*/iu', trim($rooms)) as $part) {
        if (!preg_match('/^(?:(\d+)\s*[x×]?\s*)?(?:(?:camer[ae]|rooms?)\s+)?([a-z]+)(?:\s+rooms?)?(?:\s*[x×]\s*(\d+))?$/iu', $part, $m)) return null;
        $w = strtolower($m[2]);
        if (!isset($words[$w])) return null;
        $n = max((int)$m[1], (int)($m[3] ?? 0));
        $forms = $t[$words[$w]];
        $out[] = $n > 0 ? $n . ' ' . $forms[$n > 1 ? 1 : 0] : $forms[0];
    }
    return $out ? implode(' + ', $out) : null;
}

/**
 * A day added from the Calc, in the same lodge as the night before, is a day trip when its route alias starts
 * from where the lodge is (Karatu → Lake Eyasi, lodge in Karatu). Another route on such a day ("Arusha → Serengeti"
 * on the second night in the Serengeti) is only the label's alias: no transfer that day.
 */
function iti_final_is_day_trip(?array $route, int $dayDestination): bool {
    return $route !== null && $dayDestination > 0 && (int)$route['from_id'] === $dayDestination;
}

/**
 * The seller's "ADULTS/TEEN/CHD" text as numbers: "2 ADULTS + 2 CHILDREN" → adults 2, teen 0, child 2.
 * null when any part of the text is not understood (it is then only shown, never compared).
 */
function iti_final_pax_from_text(string $text): ?array {
    $out = array('adults' => 0, 'teen' => 0, 'child' => 0);
    $left = preg_replace_callback('/(\d+)\s*(adults?|adulti|adulto|adt|ad|teen(?:ager)?s?|ragazz[oi]|child(?:ren)?|chd|kids?|bambin[oi])\b/iu', function ($m) use (&$out) {
        $w = strtolower($m[2]);
        $k = preg_match('/^ad/', $w) ? 'adults' : (preg_match('/^(teen|ragazz)/', $w) ? 'teen' : 'child');
        $out[$k] += (int)$m[1];
        return ' ';
    }, trim($text));
    if ($out['adults'] + $out['teen'] + $out['child'] === 0) return null;
    return preg_replace('/\s*(?:\+|,|&|\/|\band\b|\be\b)\s*|\s+/iu', '', (string)$left) === '' ? $out : null;
}

/** Single rooms in the Calc's room text: "1 DOUBLE + 1 SINGLE" → 1, "2 singole" / "SGL x 2" → 2, "SINGLE" → 1. */
function iti_final_single_rooms(string $rooms): int {
    if (!preg_match_all('/(?:(\d+)\s*[x×]?\s*)?(?<![a-z])(?:singles?|singol[ae]|sgl)\b(?:\s*[x×]\s*(\d+))?/iu', $rooms, $m, PREG_SET_ORDER)) return 0;
    $n = 0;
    foreach ($m as $g) $n += max(1, (int)($g[1] ?? 0), (int)($g[2] ?? 0));
    return $n;
}

/** A Calc of a request not Booked yet: a program for the sales phase (quote), not after the sale. */
function iti_final_is_quote(array $calc): bool {
    $status = trim((string)($calc['request_status'] ?? ''));
    return $status !== '' && strcasecmp($status, 'Booked') !== 0;
}

/**
 * What the seller must have filled in the Calc before an itinerary program is built from it.
 * 'errors' stop the program (dates not left at the template's); 'open_errors' too (pax, room types), unless
 * $calc['group_open']: a group departure still filling up. 'warnings' are shown on the confirmation step:
 * the total (F10) is the seller's, and the Hub only compares it with the sum it expects.
 * Returns array('errors', 'open_errors', 'warnings', 'singles', 'expected_total').
 */
function iti_final_calc_review(array $calc): array {
    $pax = isset($calc['pax']) ? $calc['pax'] : array();
    $a = (int)($pax['adults'] ?? 0); $t = (int)($pax['teen'] ?? 0); $c = (int)($pax['child'] ?? 0);
    $isRecap = strcasecmp(trim((string)($calc['sheet'] ?? '')), 'RECAP') === 0;   // the whole group: no rooms or total of one client
    $rooms = trim((string)($calc['room_config'] ?? ''));
    $singles = iti_final_single_rooms($rooms);
    $open = !empty($calc['group_open']);   // group departure still filling up: participants and rooms not known yet
    $errors = array(); $warnings = array(); $openErrors = array();

    // Room types are needed on a quote too (Roberto, 23 Nov 2026), except a quote priced on several pax sheets
    // (one per group size: the rooms are not known and iti_final_generate() leaves them off).
    $multiPrice = count($calc['price_options'] ?? array()) > 1;
    if (!$open && $a + $t + $c <= 0) $openErrors[] = 'Enter the number of adults, teenagers and children (B2:B4).';
    if (!$open && $rooms === '' && !$isRecap) {
        if ($multiPrice) $warnings[] = 'No room types under ROOMS TYPE: the quote is priced on several pax sheets, so the program goes without them.';
        else             $openErrors[] = 'Enter the room types in the cell under ROOMS TYPE.';
    }
    // Rows of the day table with content but no date and no PARK/OVERNIGHT text (calc_read_file()), read with the rule
    // shared with booking_check and the booking gate (calc_skipped_kind(), 13 Nov 2026): only a lodge with a cost in J
    // (a real night with no date) stops the program; a trip-wide item (MEDIVAC, insurance…) is not a day and needs
    // nothing; a booking-team annotation (supplier + INVOICE / CHECKED marks) and other texts are warnings.
    foreach (($calc['skipped_rows'] ?? array()) as $sk) {
        $kind = isset($sk['kind']) ? $sk['kind'] : (function_exists('calc_skipped_kind') ? calc_skipped_kind($sk) : 'lost_day');
        if ($kind === 'lost_day') {
            $errors[] = 'Row ' . (int)$sk['row'] . ' of the Calc has ' . $sk['text'] . ' with an accommodation cost but no date and no PARK/OVERNIGHT text (column B): '
                      . 'it would not become a day of the program. Fill column B (or clear the row).';
        } elseif ($kind === 'annotation') {
            $warnings[] = 'Row ' . (int)$sk['row'] . ' of the Calc ("' . trim($sk['hotel'] . ' | ' . $sk['invoice'] . ' | ' . $sk['checked'], ' |')
                        . '") is a booking note, not a day: move INVOICE / CHECKED to the service row.';
        } elseif ($kind === 'note') {
            $warnings[] = 'Row ' . (int)$sk['row'] . ' of the Calc has ' . $sk['text'] . ' but no date and no cost: not a day of the program. Check it is not a night left without a date.';
        }   // trip_item: a cost of the whole trip, never a day
    }
    if (!$open && $rooms !== '' && iti_final_room_text($rooms, 'en') === null) {
        $warnings[] = 'Room types "' . $rooms . '" not recognised: they go on the program as written in the Calc, not translated. Check them in the program.';
    }
    $paxText = trim((string)($calc['pax_text'] ?? ''));
    $written = (!$open && !$isRecap && $paxText !== '') ? iti_final_pax_from_text($paxText) : null;
    if ($written !== null && ($written['adults'] !== $a || $written['teen'] !== $t || $written['child'] !== $c)) {
        $warnings[] = 'The Calc says "' . $paxText . '", but B2:B4 say ' . $a . ' adults / ' . $t . ' teenagers / ' . $c . ' children: the program uses B2:B4, correct whichever is wrong.';
    }
    $start = $calc['start_date'] ?? null;
    $noDate = !$start;
    foreach (($calc['days'] ?? array()) as $d) if (empty($d['date'])) $noDate = true;
    if ($noDate) {
        $errors[] = 'Enter the trip dates in the DATE column.';
    } elseif ($start < date('Y-m-d')) {
        $errors[] = 'The trip starts on ' . $start . ', in the past: dates left from the template? Enter the real dates; if they are not known yet, '
                  . 'enter likely ones: the next step asks whether they are exact or indicative.';
    }

    $expected = null;
    if (!$isRecap && !$open && count($calc['price_options'] ?? array()) <= 1) {
        $need = array(array($t, $calc['price_teen'] ?? null, 'teenager price'), array($c, $calc['price_child'] ?? null, 'child price'),
                      array($singles, $calc['single_supp'] ?? null, 'single supplement'));
        $complete = !empty($calc['price_to_customer']);
        foreach ($need as $n) {
            if ($n[0] > 0 && (empty($n[1]) || $n[1] <= 0)) { $complete = false; $warnings[] = 'No ' . $n[2] . ' in the Calc: it will not be on the program.'; }
        }
        if ($complete) {
            $expected = $a * (float)$calc['price_to_customer'] + $t * (float)($calc['price_teen'] ?? 0)
                      + $c * (float)($calc['price_child'] ?? 0) + $singles * (float)($calc['single_supp'] ?? 0);
        }
        $total = $calc['price_total'] ?? null;
        if ($total === null || $total <= 0) {
            $warnings[] = 'Tot price (F10) is empty: the program will have no total.';
        } elseif ($expected !== null && abs($total - $expected) > 1) {
            $warnings[] = 'Tot price (F10) is ' . round($total) . ' USD, but prices, discounts and supplements add up to ' . round($expected)
                        . ' USD. The total on the program is F10: correct it in the Calc unless the difference is intended.';
        }
    }
    return array('errors' => $errors, 'open_errors' => $openErrors, 'warnings' => $warnings, 'singles' => $singles, 'expected_total' => $expected);
}

/**
 * Price table of a final programme from the Calc — the price to the customer: per person
 * for each pax type the Calc has (adult F9, teenager "Teenager price", child "Child price", with their
 * discount in the label), the single supplement when the rooms have singles, then the total (F10, bold).
 * [] when the Calc has no price to customer.
 * Rows [{label, price, currency, bold?}] as iti_doc_data() reads price_table_json.
 */
function iti_final_price_table(array $calc, string $lang): array {
    $L = array(
        'it' => array('Adulto', 'Ragazzo (12–15 anni)', 'Bambino (sotto i 12 anni)', 'Totale pratica', array('adulto', 'adulti'), array('ragazzo', 'ragazzi'), array('bambino', 'bambini'), 'Supplemento camera singola', 'sconto'),
        'en' => array('Adult', 'Teenager (12–15)', 'Child (under 12)', 'Total booking', array('adult', 'adults'), array('teenager', 'teenagers'), array('child', 'children'), 'Single room supplement', 'discount'),
        'fr' => array('Adulte', 'Adolescent (12–15 ans)', 'Enfant (moins de 12 ans)', 'Prix total', array('adulte', 'adultes'), array('adolescent', 'adolescents'), array('enfant', 'enfants'), 'Supplément chambre individuelle', 'réduction'),
        'es' => array('Adulto', 'Adolescente (12–15 años)', 'Niño (menor de 12 años)', 'Precio total', array('adulto', 'adultos'), array('adolescente', 'adolescentes'), array('niño', 'niños'), 'Suplemento habitación individual', 'descuento'),
        'de' => array('Erwachsener', 'Jugendlicher (12–15 J.)', 'Kind (unter 12 J.)', 'Gesamtpreis', array('Erwachsener', 'Erwachsene'), array('Jugendlicher', 'Jugendliche'), array('Kind', 'Kinder'), 'Einzelzimmerzuschlag', 'Ermäßigung'),
    );
    $t = $L[$lang] ?? $L['en'];
    $open = !empty($calc['group_open']);   // group still filling up: the price list per person, no total, never "base N pax"
    if (!$open && !empty($calc['price_options']) && count($calc['price_options']) > 1) return iti_final_price_options_table($calc['price_options'], $lang);
    $adult = $calc['price_to_customer'] ?? null;
    if ($adult === null || $adult <= 0) return array();
    $pax = $calc['pax'] ?? array();
    $rows = array(); $who = array();
    $add = function ($n, $label, $price, $forms) use (&$rows, &$who) {
        if ($n <= 0 || $price === null || $price <= 0) return;
        $rows[] = array('label' => $label, 'price' => round((float)$price), 'currency' => 'USD');
        $who[]  = $n . ' ' . $forms[$n > 1 ? 1 : 0];
    };
    $disc = function ($label, $d) use ($t) { return ($d !== null && $d > 0) ? $label . ', ' . $t[8] . ' ' . round((float)$d) . ' USD' : $label; };
    $add($open ? 1 : (int)($pax['adults'] ?? 0), $t[0], $adult, $t[4]);
    $listed = function ($price) use ($adult) { return ($price !== null && $price < $adult) ? 1 : 0; };   // group price list: only a real reduction
    $add($open ? $listed($calc['price_teen'] ?? null) : (int)($pax['teen'] ?? 0), $disc($t[1], $calc['discount_teen'] ?? null), $calc['price_teen'] ?? null, $t[5]);
    $add($open ? $listed($calc['price_child'] ?? null) : (int)($pax['child'] ?? 0), $disc($t[2], $calc['discount_child'] ?? null), $calc['price_child'] ?? null, $t[6]);
    $singles = $open ? 1 : iti_final_single_rooms((string)($calc['room_config'] ?? ''));
    if ($singles > 0 && !empty($calc['single_supp']) && $calc['single_supp'] > 0) {
        $rows[] = array('label' => $t[7] . ($singles > 1 ? ' (× ' . $singles . ')' : ''), 'price' => round((float)$calc['single_supp']), 'currency' => 'USD');
    }
    if (!$open && !empty($calc['price_total']) && $calc['price_total'] > 0) {
        $rows[] = array('label' => $t[3] . ($who ? ' (' . implode(', ', $who) . ')' : ''), 'price' => round((float)$calc['price_total']),
                        'currency' => 'USD', 'bold' => true);
    }
    return $rows;
}

/**
 * Price table of a quote with one pax sheet per group size (calc_add_price_options()): one row per
 * sheet, "per person, based on N pax", smallest group first; no total (it differs per option).
 * Two sheets with the same pax count are told apart by the sheet name.
 */
function iti_final_price_options_table(array $opts, string $lang): array {
    $L = array('it' => 'Quota per persona, base %d pax', 'en' => 'Per person, based on %d pax', 'fr' => 'Prix par personne, base %d pax',
               'es' => 'Precio por persona, base %d pax', 'de' => 'Preis pro Person, Basis %d Pax');
    $fmt = $L[$lang] ?? $L['en'];
    $count = array();
    foreach ($opts as $o) $count[$o['pax_total']] = ($count[$o['pax_total']] ?? 0) + 1;
    $rows = array();
    foreach ($opts as $o) {
        if ($o['price_to_customer'] === null || $o['price_to_customer'] <= 0 || $o['pax_total'] <= 0) continue;
        $rows[] = array('label' => sprintf($fmt, $o['pax_total']) . ($count[$o['pax_total']] > 1 ? ' (' . $o['sheet'] . ')' : ''),
                        'price' => round((float)$o['price_to_customer']), 'currency' => 'USD');
    }
    return $rows;
}

/** Text of an added day spent in the same lodge as the night before: a game drive day, or a free day at the beach. */
function iti_final_stay_text(string $lang, bool $beach): string {
    $t = $beach
        ? array('it' => 'Giornata libera per relax al mare e attività facoltative.', 'en' => 'Free day to relax on the beach, with optional activities.',
                'fr' => 'Journée libre pour se détendre à la plage, avec activités facultatives.', 'es' => 'Día libre para relajarse en la playa, con actividades opcionales.',
                'de' => 'Freier Tag zum Entspannen am Strand, mit optionalen Aktivitäten.')
        : array('it' => 'Intera giornata di fotosafari.', 'en' => 'Full day game drive.', 'fr' => 'Journée complète de safari photo.',
                'es' => 'Día completo de safari fotográfico.', 'de' => 'Ganztägige Pirschfahrt.');
    return $t[$lang] ?? $t['en'];
}

/**
 * Client name for the program title: the first guest of the Calc with a name
 * ("Luca De Sanctis"), else the Hub customer name with its words split
 * ("LucaDeSanctis" → "Luca De Sanctis").
 */
function iti_final_customer_name(array $calc, string $customer): string {
    foreach ($calc['guests'] ?? array() as $g) {
        if (empty($g['tba']) && trim((string)$g['name']) !== '') return trim((string)$g['name']);
    }
    return trim(preg_replace('/(?<=\p{Ll})(?=\p{Lu})/u', ' ', $customer));
}

// Two lodges this close count as the same area when aligning the Calc with the sample.
if (!defined('ITI_FINAL_NEAR_KM')) define('ITI_FINAL_NEAR_KM', 30);

/** Meal flags [breakfast, lunch, dinner, all_inclusive] for a meal basis code. */
function iti_final_meals(?string $basis): ?array {
    $m = array('BB' => array(1, 0, 0, 0), 'HB' => array(1, 0, 1, 0), 'FB' => array(1, 1, 1, 0), 'AI' => array(1, 1, 1, 1));
    return ($basis !== null && isset($m[$basis])) ? $m[$basis] : null;
}

/*
 * Migration camps follow the herds: in the north (Mara River / Kogatende) from June
 * to October, at Ndutu from December to March; in April, May and November they are
 * closed while they move — by default; a camp's own open months (iti_lodges.season_months,
 * set on the lodge page) win: Untamed also opens in the central Serengeti (Apr–Jun, Nov).
 * A Calc text like "Pure Migration" or "Bush Camp" is mapped once to one of the camps;
 * the night's month then picks the camp that is open: the camp itself, else its twin.
 * Camps = active lodges of type Mobile Camp, or with a season twin (iti_lodges.
 * season_twin_id, set on the lodge page: the same camp in the other area, e.g.
 * Tanzania Bush Camps Ndutu Mobile ↔ Mara River). Area (for the default months):
 * "Ndutu" in the name or destination → south; Mara / Kogatende / North → north; else
 * the opposite of the twin's; else none (one camp that moves itself: Ubuntu, Untamed).
 * Without a twin set, two mobile camps whose names match without the area words
 * are twins ("Ndutu Pure Migration Camp" ~ "Mara Pure Migration Camp").
 */

/** Migration camps: lodge id => ['name', 'area' => 'north'|'south'|'', 'twin' => lodge id or 0]. */
function iti_final_migration_camps(): array {
    static $camps = null;
    if ($camps !== null) return $camps;
    iti_ensure_doc_columns();   // season_twin_id
    $lodges = array();
    foreach (iti_get_lodges() as $l) $lodges[(int)$l['id']] = $l;
    $twinOf = array();
    foreach ($lodges as $id => $l) {
        $t = (int)($l['season_twin_id'] ?? 0);
        if ($t && isset($lodges[$t])) { $twinOf[$id] = $t; $twinOf[$t] = $id; }
    }
    $areaOf = function ($l) {
        $txt = strtolower($l['name'] . ' ' . $l['dest_name_en']);
        if (strpos($txt, 'ndutu') !== false) return 'south';
        return preg_match('/\b(mara|kogatende|north(ern)?)\b/', strtolower($l['name'])) ? 'north' : '';
    };
    $key = function ($n) {
        $n = preg_replace('/\b(ndutu|mara|kogatende|north(ern)?|south(ern)?|serengeti)\b/', ' ', strtolower($n));
        return trim(preg_replace('/[^a-z0-9]+/', ' ', $n));
    };
    // Mobile camps without a twin set: pair them by name.
    $byKey = array();
    foreach ($lodges as $id => $l) {
        if ($l['lodge_type'] === 'mobile_camp' && !isset($twinOf[$id])) $byKey[$key($l['name'])][] = $id;
    }
    foreach ($byKey as $ids) if (count($ids) === 2) { $twinOf[$ids[0]] = $ids[1]; $twinOf[$ids[1]] = $ids[0]; }

    $camps = array();
    foreach ($lodges as $id => $l) {
        if ($l['lodge_type'] !== 'mobile_camp' && !isset($twinOf[$id])) continue;
        $area = $areaOf($l);
        if ($area === '' && isset($twinOf[$id])) {
            $ta = $areaOf($lodges[$twinOf[$id]]);
            $area = $ta === 'south' ? 'north' : ($ta === 'north' ? 'south' : '');
        }
        $camps[$id] = array('name' => $l['name'], 'area' => $area, 'twin' => $twinOf[$id] ?? 0,
                            'months' => iti_final_open_months($l['season_months'] ?? null, $area));
    }
    return $camps;
}

/**
 * Months a migration camp is open: iti_lodges.season_months ("12,1,2,3", set on the lodge
 * page — e.g. Untamed, which also opens in the central Serengeti April–June and November:
 * all year), else by area: north Jun–Oct, Ndutu Dec–Mar, a camp that moves itself all but
 * April, May and November.
 */
function iti_final_open_months($stored, string $area): array {
    $m = array_values(array_unique(array_filter(array_map('intval', explode(',', (string)$stored)),
                                                function ($x) { return $x >= 1 && $x <= 12; })));
    if ($m) return $m;
    if ($area === 'north') return array(6, 7, 8, 9, 10);
    if ($area === 'south') return array(12, 1, 2, 3);
    return array(1, 2, 3, 6, 7, 8, 9, 10, 12);
}

/**
 * The lodge to use for a night given the season: ['id' => lodge id, 'note' => ?string].
 * A migration camp closed that month is swapped for its season twin when the twin is
 * open; 'note' explains the swap, or warns when the camp is closed.
 */
function iti_final_season_lodge(int $lodgeId, ?string $ymd): array {
    $camps = iti_final_migration_camps();
    if (!isset($camps[$lodgeId]) || !$ymd) return array('id' => $lodgeId, 'note' => null);
    $c = $camps[$lodgeId];
    $m = (int)date('n', strtotime($ymd));
    $month = date('F', strtotime($ymd));
    if (in_array($m, $c['months'], true)) return array('id' => $lodgeId, 'note' => null);
    $t = $c['twin'] && isset($camps[$c['twin']]) ? $camps[$c['twin']] : null;
    if ($t && in_array($m, $t['months'], true)) {
        return array('id' => $c['twin'], 'note' => 'Season rule: in ' . $month . ' ' . $c['name'] . ' is closed → '
                     . $t['name'] . ' (the Calc text is mapped to ' . $c['name'] . ').');
    }
    return array('id' => $lodgeId, 'note' => $c['name'] . ' is closed in ' . $month
                 . ($t ? ', and so is its season twin ' . $t['name'] : '') . ' — check the lodge (open months: ITI → Lodges).');
}

/**
 * Lodge to preselect when mapping a Calc hotel text: iti_alias_suggest() over the
 * lodges, leaving out the migration camps closed in the night's month whose twin is
 * open ("Pure Migration" in July → Mara Pure Migration Camp, not the Ndutu one).
 * $names = [lodge id => name]. Returns the id or null.
 */
function iti_final_suggest_lodge(string $text, array $names, ?string $ymd) {
    if ($ymd) {
        $m = (int)date('n', strtotime($ymd));
        $camps = iti_final_migration_camps();
        foreach ($camps as $id => $c) {
            if (!in_array($m, $c['months'], true) && $c['twin'] && isset($camps[$c['twin']])
                && in_array($m, $camps[$c['twin']]['months'], true)) unset($names[$id]);
        }
    }
    return iti_alias_suggest($text, $names);
}

/**
 * Weighted LCS alignment of two key lists. Returns [calcIndex => sampleIndex] for
 * the matched pairs. $score($a, $b) > 0 allows a pair (higher = better match);
 * default: keys compared with ===.
 */
function iti_final_align(array $a, array $b, ?callable $score = null): array {
    if ($score === null) $score = function ($x, $y) { return $x === $y ? 1 : 0; };
    $n = count($a); $m = count($b);
    $L = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
    $S = array();
    for ($i = $n - 1; $i >= 0; $i--) {
        for ($j = $m - 1; $j >= 0; $j--) {
            $S[$i][$j] = $score($a[$i], $b[$j]);
            $L[$i][$j] = max($L[$i + 1][$j], $L[$i][$j + 1], $S[$i][$j] > 0 ? $L[$i + 1][$j + 1] + $S[$i][$j] : 0);
        }
    }
    $map = array();
    for ($i = 0, $j = 0; $i < $n && $j < $m; ) {
        if ($S[$i][$j] > 0 && $L[$i][$j] === $L[$i + 1][$j + 1] + $S[$i][$j]) { $map[$i] = $j; $i++; $j++; }
        elseif ($L[$i + 1][$j] >= $L[$i][$j + 1]) $i++;
        else $j++;
    }
    return $map;
}

/**
 * The plan: one entry per Calc day row, with its resolved lodge / activities /
 * route, the matching sample day (or null = new day) and flags.
 * $calc = calc_read_file() / calc_read_request() output. $sampleId 0 = no sample yet.
 * @return array{days:array, blocking:array, unmapped:array, flags:string[], sample_days:int}
 */
function iti_final_plan(PDO $db, array $calc, int $sampleId): array {
    iti_ensure_final_schema();
    $days = array(); $unmapped = array('lodge' => array(), 'activity' => array(), 'route' => array());
    $hints = array('lodge_meal' => array(), 'lodge_date' => array());   // unmapped hotel text => meal from its suffix / first night
    $n = count($calc['days']);
    $lodgeInfo = function ($id) use ($db) { return iti_final_lodge_info($db, (int)$id); };
    // The booking: a text without an alias for everyone may have the staff's mapping for THIS booking only
    // (pending the admin's approval, iti_sh_propose()); listed in 'local' and flagged on the day.
    $rid = (int)($calc['request_id'] ?? 0);
    $local = array('lodge' => array(), 'activity' => array(), 'route' => array());

    foreach ($calc['days'] as $i => $cd) {
        $last = ($i === $n - 1);
        $d = array('i' => $i, 'date' => $cd['date'], 'label' => $cd['label'], 'hotel_text' => $cd['hotel_text'],
                   'invoice' => $cd['invoice'], 'checked' => $cd['checked'], 'flight_cost' => $cd['flight_cost'],
                   'last' => $last, 'lodge_id' => null, 'lodge_name' => '', 'lodge_custom' => null, 'dest_id' => null,
                   'meal' => null, 'room_category' => null, 'lodge_state' => 'none', 'own_arrangement' => false, 'lodge_local' => false, 'acts' => array(), 'route' => null,
                   'sample_index' => null, 'sample_lodge' => null, 'flags' => array());

        // Lodge (every night; the departure day normally has none).
        if ($cd['hotel_text'] !== '') {
            // "Orangi River Luxury (FB)": the alias is the hotel without the meal suffix
            // (an older alias saved with the suffix still wins); the suffix sets the meal.
            list($base, $mealSfx) = iti_alias_split_meal($cd['hotel_text']);
            $a = iti_alias_lookup('lodge', $cd['hotel_text'], $rid);
            if ($a === null && $base !== $cd['hotel_text']) $a = iti_alias_lookup('lodge', $base, $rid);
            if ($a !== null && !empty($a['local'])) { $d['lodge_local'] = true; $local['lodge'][$a['alias']] = $a['alias']; }
            // Own arrangement (18 Nov 2026): a lodge alias without lodge_id, or a Calc text naming it
            // ("Riu Palace Nungwi - own arrangement"): the agency / clients book it — no voucher, no meals, no room.
            $d['own_arrangement'] = iti_oa_text_says($cd['hotel_text']) || ($a !== null && !$a['lodge_id']);
            if ($d['own_arrangement'] && ($a === null || !$a['lodge_id'])) {
                $d['lodge_state'] = 'own';
                $d['lodge_custom'] = iti_oa_hotel_name($base) ?: 'Own Arrangement';   // "Riu Palace Nungwi"
            } elseif ($a === null) {
                $d['lodge_state'] = 'unmapped';
                $unmapped['lodge'][iti_alias_norm($base)] = $base;
                if ($mealSfx) $hints['lodge_meal'][$base] = $mealSfx;
                if (!isset($hints['lodge_date'][$base])) $hints['lodge_date'][$base] = $cd['date'];   // first night: season of the suggestion
            } else {   // a lodge (an alias without lodge_id is an own arrangement, above)
                $sea = iti_final_season_lodge((int)$a['lodge_id'], $cd['date']);   // migration camps: north / Ndutu by month
                if ($sea['note']) $d['flags'][] = $sea['note'];
                $li = $lodgeInfo($sea['id']);
                $d['lodge_state'] = $li ? 'ok' : 'unmapped';
                if ($li) {
                    $d['lodge_id'] = (int)$li['id']; $d['lodge_name'] = $li['name'];
                    $d['dest_id'] = $li['destination_id'] ? (int)$li['destination_id'] : null;
                    // Room category after the hotel name ("Melia Zanzibar Junior Suite Sea View"): the lodge's listed
                    // one, else the words as typed, flagged. Not for a migration camp swapped by season (other hotel).
                    if ((int)$li['id'] === (int)$a['lodge_id']
                        && ($rc = iti_room_category_from_text($base, $li['name'], iti_room_categories_list($li['room_categories'] ?? ''))) !== null) {
                        $d['room_category'] = $rc['category'];
                        if (!$rc['listed']) $d['flags'][] = 'Room category "' . $rc['category'] . '" read from the Calc hotel text, not in the lodge\'s list: check it.';
                    }
                }
                $d['meal'] = $mealSfx ?: ($a['meal_basis'] ?: null);
            }
        } elseif (!$last) {
            $d['flags'][] = 'Night without hotel in the Calc (col K).';
        }

        // Activities: ACTIVITY DESC + FEES DESC, split on '+'.
        foreach (array_merge(iti_alias_parts($cd['activity_desc']), iti_alias_parts($cd['park_fees_desc'])) as $part) {
            // Picnic lunch cost line, never an activity: not asked, no alias needed.
            if (preg_match('/^lunch ?box(es)?$/', iti_alias_norm($part))) {
                $d['acts'][] = array('text' => $part, 'state' => 'nothing', 'activity_id' => null);
                continue;
            }
            $a = iti_alias_lookup('activity', $part, $rid);
            if ($a === null) {
                $unmapped['activity'][$part] = $part;
                $d['acts'][] = array('text' => $part, 'state' => 'unmapped', 'activity_id' => null, 'local' => false);
            } else {
                if (!empty($a['local'])) $local['activity'][$a['alias']] = $a['alias'];
                $d['acts'][] = array('text' => $part, 'state' => $a['activity_id'] ? 'ok' : 'nothing',
                                     'activity_id' => $a['activity_id'] ? (int)$a['activity_id'] : null, 'local' => !empty($a['local']));
            }
        }

        // Route (PARK/OVERNIGHT label) — required only for days the sample does not cover (below).
        if ($cd['label'] !== '') {
            $a = iti_alias_lookup('route', $cd['label'], $rid);
            if ($a !== null && !empty($a['local'])) $local['route'][$a['alias']] = $a['alias'];
            $d['route'] = array('text' => $cd['label'], 'state' => $a === null ? 'unmapped' : 'ok', 'local' => $a !== null && !empty($a['local']),
                                'transfer_route_id' => $a && $a['transfer_route_id'] ? (int)$a['transfer_route_id'] : null,
                                'flight_route_id' => $a && $a['flight_route_id'] ? (int)$a['flight_route_id'] : null);
        }
        $days[] = $d;
    }

    // Align with the sample's days on the lodge sequence (iti_final_match_sample()).
    // Same lodge = best match; same destination or a nearby lodge still keeps the
    // sample day's text, flagged for review.
    $sampleDays = array();
    if ($sampleId) {
        $sampleDays = iti_final_sample_days($db, $sampleId);
        foreach (iti_final_match_sample($db, $days, $sampleDays)['map'] as $i => $j) {
            $days[$i]['sample_index'] = $j;
            $s = $sampleDays[$j];
            if ($days[$i]['lodge_id'] && $s['end_lodge_id'] && (int)$s['end_lodge_id'] !== $days[$i]['lodge_id']) {
                $days[$i]['sample_lodge'] = (string)$s['lodge_name'];
                $days[$i]['flags'][] = 'Lodge changed from the sample (' . $s['lodge_name'] . ' → ' . $days[$i]['lodge_name']
                                     . '): the sample text is kept — check it does not name the old lodge.';
            }
            $cp = iti_final_places((string)$days[$i]['label']);
            if (iti_final_places_clash($cp, $s['places'])) {
                $days[$i]['flags'][] = 'Places differ: Calc “' . $days[$i]['label'] . '” (' . implode(', ', array_keys($cp)) . ') vs sample day '
                                     . (int)$s['day_number'] . ' “' . $s['title'] . '” (' . implode(', ', array_keys($s['places'])) . ') — check the day text.';
            }
        }
        // Departure day left out by iti_final_match_sample(): the sample's leaves from elsewhere.
        $lastS = $sampleDays ? $sampleDays[count($sampleDays) - 1] : null;
        $lastC = $days ? $days[count($days) - 1] : null;
        if ($lastS && $lastC && $lastC['sample_index'] === null && $lastC['hotel_text'] === '' && !$lastS['end_lodge_id']
            && (iti_final_places_clash(iti_final_places((string)$lastC['label']), $lastS['places'])
                || (($cs = iti_final_first_place(iti_final_places((string)$lastC['label']))) !== '' && ($lastS['start_place'] ?? '') !== '' && $cs !== $lastS['start_place']))) {
            $days[count($days) - 1]['flags'][] = 'Departure not taken from the sample: its last day “' . $lastS['title'] . '” leaves from '
                . implode(', ', array_keys($lastS['places'])) . ', the Calc “' . $lastC['label'] . '” from '
                . implode(', ', array_keys(iti_final_places((string)$lastC['label']))) . '.';
        }
    }

    $blocking = array();
    foreach ($unmapped['lodge'] as $t) $blocking[] = 'Hotel "' . $t . '" has no lodge alias.';
    foreach ($unmapped['activity'] as $t) $blocking[] = 'Activity "' . $t . '" has no activity alias.';
    foreach ($days as $i => $d) {
        if ($sampleId && $d['sample_index'] === null) {
            $days[$i]['flags'][] = 'Not in the sample: added from the Calc (text to review).';
            // Routes only once every hotel is mapped: until then the alignment with the
            // sample is not known and most days only look new.
            if ($d['route'] && $d['route']['state'] === 'unmapped' && !$unmapped['lodge']) {
                $unmapped['route'][iti_alias_norm($d['route']['text'])] = $d['route']['text'];
            }
        }
        if ($d['flight_cost'] !== null && $d['flight_cost'] > 0 && !($d['route'] && $d['route']['flight_route_id']) && ($d['sample_index'] === null)) {
            $days[$i]['flags'][] = 'The Calc has a flight cost this day — check the flight in the program.';
        }
    }
    foreach ($unmapped['route'] as $t) $blocking[] = 'Route "' . $t . '" (a day not in the sample) has no route alias.';

    $flags = array();
    foreach ($sampleDays as $s) {
        if ($s['lodge_text'] !== null) {
            $flags[] = 'Sample day ' . (int)$s['day_number'] . ' has the lodge only as text “' . $s['lodge_text'] . '” — read as '
                     . $s['lodge_name'] . '. Link the lodge in the sample to make it certain.';
        }
    }
    if ($unmapped['lodge'] && $sampleId) $flags[] = 'Map the hotels first: which days match the sample (and which routes are needed) depends on them.';
    if (!empty($calc['guests_tba'])) $flags[] = 'Some guest names are not known yet (TBA / waiting) — only the known names go into the program; add the others later.';
    if ($sampleId && count($sampleDays) !== count($days)) {
        $flags[] = 'The sample has ' . count($sampleDays) . ' days, the Calc ' . count($days) . ' — days are added / removed to match the Calc.';
    }
    if (!empty($calc['group_open'])) {
        $flags[] = 'Group not final: the program has no participants, no room types and no total, only the price list per person. Regenerate it when the group is final.';
    }
    if (!empty($calc['dates_indicative'])) {
        $flags[] ='Dates confirmed as indicative: the documents show the period with "indicative dates" and no date day by day.';
    }
    $nLocal = count($local['lodge']) + count($local['activity']) + count($local['route']);
    if ($nLocal) {
        $flags[] = $nLocal . ' Calc text' . ($nLocal === 1 ? ' is' : 's are') . ' mapped for this booking only, waiting for Roberto\'s approval: '
                 . 'if he corrects one, regenerate the program.';
    }
    return array('days' => $days, 'blocking' => $blocking, 'unmapped' => $unmapped, 'hints' => $hints, 'flags' => $flags,
                 'sample_days' => count($sampleDays), 'local' => $local);
}

/**
 * ref_number (= the programme files' name) of a final programme, as docs/AGENT_API.md asks:
 * the name of the programme Word with the Calc's NN_ ("01_LucaDeSanctis(LasciatiViaggiare-
 * Daniela)_SafariGrandeMigrazioneEstate"). In order: the ref of the final it replaces (same
 * Calc, unless it is the old folder-based form "01_07_27JUL_…"); the Word copy_program put
 * next to that Calc (iti_ps_ref_from_request); the Calc's own name without "_Calc"; the
 * folder-based iti_lead_ref_number().
 */
function iti_final_ref_number(PDO $db, int $requestId, array $calc) {
    $file = basename((string)($calc['calc_path'] ?? ($calc['file'] ?? '')));
    $st = $db->prepare("SELECT ref_number, source_calc_path FROM iti_programs WHERE lead_request_id = ? AND stage = 'final'
                         AND superseded_by IS NULL AND ref_number IS NOT NULL AND ref_number <> '' ORDER BY id DESC");
    $st->execute(array($requestId));
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) {
        if (basename((string)$p['source_calc_path']) === $file && !preg_match('/^\d{1,3}_\d{2}_\d{2}[A-Z]{3}_/', $p['ref_number'])) {
            return $p['ref_number'];
        }
    }
    try {
        require_once __DIR__ . '/iti_program_service.php';
        return iti_ps_ref_from_request($db, $requestId, $file)['ref_number'];
    } catch (Throwable $e) { /* no Word copied next to this Calc: from the Calc's name */ }
    if (preg_match('/^(\d{1,3}_.+)_calc\.xlsx$/i', $file, $m) && !preg_match('#[\\\\/:*?"<>|]#', $m[1])) {
        return mb_substr($m[1], 0, 120);
    }
    return iti_lead_ref_number($requestId, $file);
}

/**
 * Build the final programme. $calc must come from calc_read_request() (calc_path,
 * calc_rev). Throws RuntimeException when the plan still has blocking items.
 * $who = ['id' => users.id, 'username' => ..., 'seller' => username of the program's owner ('' = the request's seller)]:
 * the owner goes in created_by (the consultant), who presses the button in generated_by. Returns ['program_id', 'superseded' => int[], 'plan'].
 */
function iti_final_generate(PDO $db, int $requestId, array $calc, int $sampleId, string $lang, array $who): array {
    iti_ensure_final_schema();
    if (!in_array($lang, ITI_LANGUAGES, true)) $lang = 'it';
    $review = iti_final_calc_review($calc);
    if ($review['errors'] || $review['open_errors']) throw new RuntimeException('Complete the Calc first: ' . implode(' ', array_merge($review['errors'], $review['open_errors'])));
    $calc['request_id'] = $requestId;   // the staff's mappings for this booking (iti_alias_lookup())
    $plan = iti_final_plan($db, $calc, $sampleId);
    if (!$sampleId) throw new RuntimeException('Choose the sample program first.');
    if ($plan['blocking']) throw new RuntimeException('Map these Calc texts first: ' . implode(' ', $plan['blocking']));
    $sample = iti_get_program($sampleId);
    if (!$sample || $sample['program_type'] !== 'sample') throw new RuntimeException('Sample #' . $sampleId . ' not found.');

    $pax = $calc['pax'];
    // "Pumba Safari – Marco Ciaolo" in every language the sample has a title for.
    $st = $db->prepare('SELECT customer_name, group_folder FROM requests WHERE id = ?');
    $st->execute(array($requestId));
    $reqRow = $st->fetch(PDO::FETCH_ASSOC) ?: array();
    $customer = iti_final_customer_name($calc, (string)($reqRow['customer_name'] ?? ''));
    $titles = array();
    foreach (ITI_LANGUAGES as $l) {
        if (!array_key_exists('title_' . $l, $sample)) continue;
        $titles['title_' . $l] = (string)$sample['title_' . $l] !== '' ? iti_final_title((string)$sample['title_' . $l], $customer) : '';
    }
    if (empty($titles['title_en'])) $titles['title_en'] = iti_final_title((string)$sample['title_en'], $customer) ?: (string)$sample['title_en'];
    $owner = iti_final_resolve_seller($db, $requestId, (string)($who['seller'] ?? ''), (string)($who['username'] ?? 'system'));
    $groupOpen = !empty($calc['group_open']);
    // Private or group comes from the BOOKING (a group folder / a departure still filling up), not from the sample:
    // a private trip built on a group sample (Simba) printed "Guided group safari" (24 Nov 2026).
    // Beach stay / trekking samples keep their own type.
    $typeOver = array();
    if (in_array((string)($sample['safari_type'] ?? ''), array('', 'private', 'group'), true)) {
        $typeOver['safari_type'] = ($groupOpen || trim((string)($reqRow['group_folder'] ?? '')) !== '') ? 'group' : 'private';
    }
    $newId = iti_duplicate_program($sampleId, 'personal', $owner, $titles + $typeOver + array(
        'pax_open'         => $groupOpen ? 1 : 0,
        'lead_request_id'  => $requestId,
        'ref_number'       => iti_final_ref_number($db, $requestId, $calc),
        'price_table_json' => ($pt = iti_final_price_table($calc, $lang)) ? json_encode($pt, JSON_UNESCAPED_UNICODE) : null,
        'stage'            => 'final',
        'start_date'       => $calc['start_date'],
        'dates_indicative' => !empty($calc['dates_indicative']) ? 1 : 0,
        'duration_days'    => count($calc['days']),
        'pax_adults'       => max(1, (int)$pax['adults']),
        'pax_teens'        => (int)$pax['teen'],      // under 16 (column from iti_ensure_doc_columns())
        'pax_children'     => (int)$pax['child'],     // under 12
        'display_language' => $lang,
        'hub_program_code' => null,
        'source_calc_path' => $calc['calc_path'] ?? null,
        'source_calc_rev'  => $calc['calc_rev'] ?? null,
        'generated_at'     => date('Y-m-d H:i:s'),
        'generated_by'     => !empty($who['id']) ? (int)$who['id'] : null,
        'superseded_by'    => null,
        'superseded_at'    => null,
    ));

    iti_transfers_schema($db);   // DDL: before the transaction (transfer texts in every language below)
    try {
        $db->beginTransaction();
        $st = $db->prepare('SELECT * FROM iti_program_days WHERE program_id = ? ORDER BY day_number');
        $st->execute(array($newId));
        $copied = $st->fetchAll(PDO::FETCH_ASSOC);          // same order as the sample's days
        // Free the day numbers, then drop the sample days the Calc does not use.
        $db->prepare('UPDATE iti_program_days SET day_number = -day_number WHERE program_id = ?')->execute(array($newId));
        $used = array();
        foreach ($plan['days'] as $d) if ($d['sample_index'] !== null) $used[$d['sample_index']] = true;
        foreach ($copied as $j => $row) {
            if (isset($used[$j])) continue;
            foreach (array('iti_day_activities', 'iti_day_flights', 'iti_day_transfers') as $t) {
                $db->prepare('DELETE FROM ' . $t . ' WHERE program_day_id = ?')->execute(array((int)$row['id']));
            }
            $db->prepare('DELETE FROM iti_program_days WHERE id = ?')->execute(array((int)$row['id']));
        }

        $dayCols = iti_table_columns('iti_program_days');
        $destDesc = function ($destId) use ($db, $lang) {
            if (!$destId) return '';
            $st = $db->prepare('SELECT description_' . $lang . ' FROM iti_destinations WHERE id = ?');
            $st->execute(array($destId));
            return trim((string)$st->fetchColumn());
        };
        $isBeach = function ($destId) use ($db) {
            if (!$destId) return false;
            $st = $db->prepare('SELECT is_beach_stay FROM iti_destinations WHERE id = ?');
            $st->execute(array($destId));
            return (bool)$st->fetchColumn();
        };
        $routeName = function ($trId) use ($db) {
            $st = $db->prepare('SELECT fd.name_en AS f, td.name_en AS t, tr.duration_min, tr.distance_km, tr.from_destination AS from_id, tr.to_destination AS to_id FROM iti_transfer_routes tr
                                  JOIN iti_destinations fd ON fd.id = tr.from_destination
                                  JOIN iti_destinations td ON td.id = tr.to_destination WHERE tr.id = ?');
            $st->execute(array($trId));
            return $st->fetch(PDO::FETCH_ASSOC) ?: null;
        };
        $update = function (int $dayId, array $vals) use ($db, $dayCols) {
            $vals = array_intersect_key($vals, array_flip($dayCols));
            if (!$vals) return;
            $set = array();
            foreach (array_keys($vals) as $c) $set[] = '`' . $c . '` = ?';
            $db->prepare('UPDATE iti_program_days SET ' . implode(', ', $set) . ' WHERE id = ?')
               ->execute(array_merge(array_values($vals), array($dayId)));
        };

        $prevLodge = null; $prevCustom = null;
        $nDays = count($plan['days']);
        $multiPrice = count($calc['price_options'] ?? array()) > 1;
        foreach ($plan['days'] as $i => $d) {
            $isNew = $d['sample_index'] === null;
            $vals = array(
                'day_number'     => $i + 1,
                'start_lodge_id' => $prevLodge,
                'start_custom'   => $prevLodge ? null : $prevCustom,
                'end_lodge_id'   => $d['lodge_id'],
                'end_lodge_custom' => $d['lodge_id'] ? null : $d['lodge_custom'],
                // No room types on an open group or a quote with several pax sheets (rooms not known yet).
                // Own arrangement: no room either (we do not book it).
                'room_type'      => !$groupOpen && !$multiPrice && empty($d['own_arrangement']) && ($d['lodge_id'] || $d['lodge_custom']) && $calc['room_config'] !== '' ? mb_substr(iti_final_room_text($calc['room_config'], $lang) ?? $calc['room_config'], 0, 100) : null,
                // From the Calc hotel text (iti_final_plan); a sample day's category is never kept (other lodge / client).
                'room_category'  => $d['lodge_id'] && empty($d['own_arrangement']) ? ($d['room_category'] ?? null) : null,
                'own_arrangement' => empty($d['own_arrangement']) ? 0 : 1,   // column from iti_tr_schema() (iti_transfers_schema() above)
                'booked_status'  => $d['invoice'] !== '' ? mb_substr($d['invoice'], 0, 40) : null,
                'booked_by'      => $d['checked'] !== '' ? mb_substr($d['checked'], 0, 60) : null,
                'needs_review'   => ($isNew || $d['sample_lodge'] !== null) ? 1 : 0,
                'review_note'    => $isNew ? 'Added from the Calc (not in the sample): check title, text, transfer and meals.'
                                   : ($d['sample_lodge'] !== null ? mb_substr('Lodge changed from the sample (' . $d['sample_lodge'] . ' → '
                                      . $d['lodge_name'] . '): check the text does not name the old lodge.', 0, 255) : null),
            );
            $meals = iti_final_meals($d['meal']);
            if ($isNew && $meals === null) $meals = array(1, 1, 1, 0);   // default full board, flagged for review
            if ($meals !== null) {
                $vals['meal_breakfast']     = $i === 0 ? 0 : $meals[0];
                $vals['meal_lunch']         = $meals[1];
                $vals['meal_dinner']        = $i === $nDays - 1 ? 0 : $meals[2];
                $vals['meal_all_inclusive'] = $meals[3];
            }
            // Own-arrangement night: no lunch / dinner there, and no breakfast the morning after (that hotel's);
            // the morning's breakfast is the previous night's lodge (kept, unless that night was own too).
            $prevOwn = $i > 0 && !empty($plan['days'][$i - 1]['own_arrangement']);
            if (!empty($d['own_arrangement'])) {
                $vals['meal_lunch'] = 0; $vals['meal_dinner'] = 0; $vals['meal_all_inclusive'] = 0;
                if ($prevOwn) $vals['meal_breakfast'] = 0;
            } elseif ($prevOwn) {
                $vals['meal_breakfast'] = 0;
            }
            if ($isNew) {
                // Another night in the same lodge: no transfer, a full day there (the destination
                // text is already shown in the day's destination block), unless it is a day trip.
                $stay = $d['lodge_id'] && $d['lodge_id'] === $prevLodge;
                $trip = ($d['route'] && $d['route']['transfer_route_id']) ? ($routeName($d['route']['transfer_route_id']) ?: null) : null;
                // The second night under the same Calc label ("Serengeti-Northern" twice) only repeats the arrival day's route.
                $sameLabel = $i > 0 && iti_alias_norm((string)$plan['days'][$i - 1]['label']) === iti_alias_norm((string)$d['label']);
                $excursion = $stay && !$sameLabel && iti_final_is_day_trip($trip, (int)$d['dest_id']);
                if ($d['dest_id']) $vals['destination_id'] = $d['dest_id'];
                foreach (ITI_LANGUAGES as $l) { $vals['day_title_' . $l] = ''; $vals['narrative_' . $l] = ''; }
                $vals['day_title_' . $lang] = mb_substr($d['label'], 0, 200);
                // A day with an activity of its own place (the crater game drive): that activity's text, not the area's.
                $actText = iti_final_activity_day_text($db, $d['acts'], $excursion ? (int)$trip['to_id'] : $d['dest_id'], $lang);
                $vals['narrative_' . $lang] = $actText !== '' ? $actText
                                            : ($excursion ? ($destDesc((int)$trip['to_id']) ?: iti_final_stay_text($lang, false))
                                            : ($stay ? iti_final_stay_text($lang, $isBeach($d['dest_id'])) : $destDesc($d['dest_id'])));
                $vals['program_id'] = $newId;
                $vals = array_intersect_key($vals, array_flip($dayCols));
                $db->prepare('INSERT INTO iti_program_days (`' . implode('`,`', array_keys($vals)) . '`) VALUES ('
                             . implode(',', array_fill(0, count($vals), '?')) . ')')->execute(array_values($vals));
                $dayId = (int)$db->lastInsertId();
                // Transfer / flight of an added day, from its route alias (none on a stay day, except a day trip).
                if ($trip && (!$stay || $excursion)) {
                    $r = $trip;
                    $notes = iti_build_transfer_notes($r['f'], $r['t'], (int)$r['duration_min'], $r['distance_km'] !== null ? (int)$r['distance_km'] : 0);
                    $tr = array('description' => $notes[$lang] ?? $notes['en'], 'tr' => $notes);   // all languages at once
                    iti_transfers_replace($db, $dayId, array($tr));
                }
                if (!$stay && $d['route'] && $d['route']['flight_route_id']) {
                    // No flight cost in the Calc that day: the clients book it (own arrangement), flagged for review.
                    $oaFlight = !($d['flight_cost'] !== null && $d['flight_cost'] > 0);
                    $db->prepare('INSERT INTO iti_day_flights (program_day_id, flight_route_id, sort_order, own_arrangement) VALUES (?,?,1,?)')
                       ->execute(array($dayId, $d['route']['flight_route_id'], $oaFlight ? 1 : 0));
                    if ($oaFlight) {
                        $db->prepare('UPDATE iti_program_days SET review_note = ? WHERE id = ?')
                           ->execute(array('Added from the Calc (not in the sample): check title, text and meals. Flight set as own arrangement (no flight cost in the Calc).', $dayId));
                    }
                }
            } else {
                $dayId = (int)$copied[$d['sample_index']]['id'];
                $update($dayId, $vals);
            }

            // Calc activities not already on the day (same activity, or its name in a custom text).
            // A sample activity of the same kind (a common word of 6+ letters: "Camminata
            // naturalistica" ~ "Camminata nei dintorni del lodge") is replaced by the Calc one.
            $st = $db->prepare('SELECT a.id, a.activity_id, a.activity_custom, m.name_en, m.name_it FROM iti_day_activities a
                                  LEFT JOIN iti_activities m ON m.id = a.activity_id WHERE a.program_day_id = ?');
            $st->execute(array($dayId));
            $have = $st->fetchAll(PDO::FETCH_ASSOC);
            $sort = count($have);
            foreach ($d['acts'] as $a) {
                if (!$a['activity_id']) continue;
                $an = $db->prepare('SELECT name_en, name_it, activity_type, destination_id FROM iti_activities WHERE id = ?');
                $an->execute(array($a['activity_id']));
                $ar = $an->fetch(PDO::FETCH_ASSOC) ?: array();
                $names = array_filter(array_map('mb_strtolower', array((string)($ar['name_en'] ?? ''), (string)($ar['name_it'] ?? ''))));
                $names[] = $a['text'];
                // A game drive tied to a place (the crater game drive, from the Calc "crater" fee) takes the place of the
                // day's plain "Fotosafari" / "Game drive" line instead of being listed next to it.
                $placeDrive = ($ar['activity_type'] ?? '') === 'game_drive' && !empty($ar['destination_id']);
                $dup = false; $same = null;
                foreach ($have as $k => $h) {
                    if ((int)$h['activity_id'] === $a['activity_id']) { $dup = true; break; }
                    $txt = mb_strtolower((string)$h['activity_custom']);
                    foreach ($names as $nm) { if ($nm !== '' && $txt !== '' && strpos($txt, $nm) !== false) { $dup = true; break 2; } }
                    if ($same === null && !empty($h['id'])
                        && (iti_final_same_kind($names, array($h['activity_custom'], $h['name_en'], $h['name_it']))
                            || ($placeDrive && iti_final_plain_drive((string)($h['activity_id'] ? $h['name_en'] : $h['activity_custom']))))) $same = $k;
                }
                if ($dup) continue;
                if ($same !== null) {   // the sample's texts of the old activity go too
                    $clear = '';
                    foreach (iti_table_columns('iti_day_activities') as $c) if (strpos($c, 'custom_note_') === 0) $clear .= ', `' . $c . '` = NULL';
                    $db->prepare('UPDATE iti_day_activities SET activity_id = ?, activity_custom = NULL' . $clear . ' WHERE id = ?')
                       ->execute(array($a['activity_id'], (int)$have[$same]['id']));
                    $have[$same] = array('id' => null, 'activity_id' => $a['activity_id'], 'activity_custom' => null, 'name_en' => null, 'name_it' => null);
                    continue;
                }
                $db->prepare('INSERT INTO iti_day_activities (program_day_id, activity_id, sort_order) VALUES (?,?,?)')
                   ->execute(array($dayId, $a['activity_id'], ++$sort));
                $have[] = array('id' => null, 'activity_id' => $a['activity_id'], 'activity_custom' => null, 'name_en' => null, 'name_it' => null);
            }

            $prevLodge = $d['lodge_id'];
            $prevCustom = $d['lodge_id'] ? null : $d['lodge_custom'];
        }
        // Safety net: one programme day per Calc day row, never a day silently lost.
        $st = $db->prepare('SELECT COUNT(*) FROM iti_program_days WHERE program_id = ?');
        $st->execute(array($newId));
        $built = (int)$st->fetchColumn();
        if ($built !== count($calc['days'])) {
            throw new RuntimeException('The program got ' . $built . ' days but the Calc has ' . count($calc['days']) . ' day rows — nothing was saved.');
        }

        // Booking data + guests (no passport numbers).
        $db->prepare('INSERT INTO iti_program_booking (program_id, room_config, pax_adults, pax_teen, pax_child,
                        arrival_details, departure_details, extra_details) VALUES (?,?,?,?,?,?,?,?)
                      ON DUPLICATE KEY UPDATE room_config = VALUES(room_config), pax_adults = VALUES(pax_adults),
                        pax_teen = VALUES(pax_teen), pax_child = VALUES(pax_child), arrival_details = VALUES(arrival_details),
                        departure_details = VALUES(departure_details), extra_details = VALUES(extra_details)')
           ->execute(array($newId, $calc['room_config'] !== '' ? $calc['room_config'] : null, (int)$pax['adults'], (int)$pax['teen'],
                           (int)$pax['child'], $calc['arrival'] ?: null, $calc['departure'] ?: null, $calc['extra_details'] ?: null));
        $gi = 0;
        foreach ($calc['guests'] as $g) {
            if (!empty($g['tba'])) continue;      // "TBA" / "waiting" rows: name not known yet
            $db->prepare('INSERT INTO iti_program_guests (program_id, sort_order, full_name, title, dob, country) VALUES (?,?,?,?,?,?)')
               ->execute(array($newId, ++$gi, mb_substr($g['name'], 0, 160), $g['title'] !== '' ? mb_substr($g['title'], 0, 10) : null,
                               $g['dob'], $g['country'] !== '' ? mb_substr($g['country'], 0, 80) : null));
        }

        // One active final per Calc file of the request (a request can have two Calc
        // files, e.g. the same safari with other lodges): older ones are kept, marked superseded.
        $st = $db->prepare("SELECT id FROM iti_programs WHERE lead_request_id = ? AND stage = 'final' AND superseded_by IS NULL AND id <> ?
                              AND source_calc_path <=> ?");
        $st->execute(array($requestId, $newId, $calc['calc_path'] ?? null));
        $old = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        if ($old) {
            $db->prepare("UPDATE iti_programs SET superseded_by = ?, superseded_at = ? WHERE id IN (" . implode(',', $old) . ")")
               ->execute(array($newId, date('Y-m-d H:i:s')));
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        // Remove the half-built copy (days, children and booking cascade or are cleaned here).
        try {
            foreach (array('iti_day_activities', 'iti_day_flights', 'iti_day_transfers') as $t) {
                $db->prepare('DELETE c FROM ' . $t . ' c JOIN iti_program_days d ON d.id = c.program_day_id WHERE d.program_id = ?')->execute(array($newId));
            }
            $db->prepare('DELETE FROM iti_program_days WHERE program_id = ?')->execute(array($newId));
            $db->prepare('DELETE FROM iti_programs WHERE id = ?')->execute(array($newId));
        } catch (Throwable $e2) {
            error_log('iti_final_generate cleanup of #' . $newId . ': ' . $e2->getMessage());
        }
        throw $e;
    }
    require_once __DIR__ . '/iti_terms.php';
    iti_terms_apply($db, (int)$newId);   // T&C of the request's channel (direct 60 days / agency 45)
    require_once __DIR__ . '/../../leads/includes/timeline_service.php';
    timeline_log($requestId, 'program_update', 'Final program generated from the Calc (#' . $newId . ')',
                 array('body' => 'Calc: ' . (isset($calc['file']) ? $calc['file'] : '?') . ($old ? "\nReplaces program #" . implode(', #', (array)$old) : ''),
                       'refs' => array('program_id' => $newId, 'calc_file' => isset($calc['file']) ? $calc['file'] : null)));
    return array('program_id' => $newId, 'superseded' => $old, 'plan' => $plan);
}

/** Final programmes of a request, newest first (active = not superseded). */
function iti_final_list(PDO $db, int $requestId): array {
    iti_ensure_final_schema();
    $st = $db->prepare("SELECT id, title_en, title_it, start_date, generated_at, source_calc_path, source_calc_rev, superseded_by, superseded_at, status
                          FROM iti_programs WHERE lead_request_id = ? AND stage = 'final' ORDER BY id DESC");
    $st->execute(array($requestId));
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * The sample of a final program: chosen from the Calc content (iti_final_pick_sample()), or the one the user picked
 * for this booking only ($sampleReq). Shared by program_final.php and the assistant (iti_ai_chat_service.php).
 * Returns array('pick', 'auto' (sample row or null), 'sample' (row or null), 'one_off' (bool), 'code').
 */
function iti_final_sample_for(PDO $db, array $calc, string $lang, bool $isGroup, int $sampleReq): array {
    $code = iti_final_code_from_calc($calc['file']);
    $pick = iti_final_pick_sample($db, $calc, iti_final_plan($db, $calc, 0)['days'], $code, $lang, $isGroup);
    $auto = $pick ? $pick['sample'] : null;
    $sample = $auto; $oneOff = false;
    if ($sampleReq && (!$auto || (int)$auto['id'] !== $sampleReq)) {
        $st = $db->prepare("SELECT id, program_type, title_en, title_it, display_language, duration_days FROM iti_programs
                             WHERE id = ? AND program_type = 'sample' AND status <> 'cancelled'");
        $st->execute(array($sampleReq));
        if ($r = $st->fetch(PDO::FETCH_ASSOC)) { $sample = $r; $oneOff = true; }
    }
    return array('pick' => $pick, 'auto' => $auto, 'sample' => $sample, 'one_off' => $oneOff, 'code' => $code);
}

/** The "data confirmed" token of program_final.php: valid for this file, sheet and version of the Calc only. */
function iti_final_ok_token(array $calc): string {
    return substr(md5($calc['file'] . '|' . $calc['sheet'] . '|' . ($calc['calc_rev'] ?? '')), 0, 12);
}

/**
 * Maps a Calc text from program_final.php or the assistant. An admin saves the alias for every program; the staff
 * map it for this booking only, pending the admin's approval (iti_alias_propose(), 16 Nov 2026). Returns the message.
 * $targets: lodge_id, activity_id, transfer_route_id, flight_route_id (0 = none).
 */
function iti_final_map_text(string $type, string $text, array $targets, ?string $meal, int $requestId, bool $isAdmin, string $user): string {
    $text = iti_alias_norm($text);
    if ($text === '') throw new RuntimeException('Empty Calc text.');
    $t = array();
    foreach (array('lodge_id', 'activity_id', 'transfer_route_id', 'flight_route_id') as $k) $t[$k] = (int)($targets[$k] ?? 0);
    if ($isAdmin) {
        iti_alias_save($type, $text, $t, $meal, $user);
        return 'Alias "' . $text . '" saved for every program.';
    }
    iti_alias_propose($type, $text, $t, $meal, $requestId);
    return '"' . $text . '" mapped for this booking only. Roberto approves it before other programs use it.';
}

/** Areas next to each other (iti_final_places()): a lodge in one fits a Calc label naming the other. */
function iti_final_near_places(): array {
    return array('Karatu' => array('Ngorongoro', 'Manyara', 'Lake Eyasi'), 'Ngorongoro' => array('Karatu', 'Serengeti'),
                 'Manyara' => array('Karatu', 'Tarangire'), 'Tarangire' => array('Manyara'), 'Lake Eyasi' => array('Karatu'),
                 'Kilimanjaro' => array('Arusha'), 'Arusha' => array('Kilimanjaro'), 'Serengeti' => array('Ngorongoro'));
}

/**
 * Pre-check of a Calc before a program is built from it (a quote in the sales phase or a booked trip), and before
 * any AI is used: everything the seller must
 * fix, decide or check, in one list. No AI, no cost. Built on iti_final_calc_review() (errors stop the program)
 * and iti_final_plan() (texts to map), plus checks of their own: guests' ages against adults / teenagers /
 * children, arrival / departure details, how the guests move when the hotel changes area, flight costs without a
 * flight, and a lodge that is not where the Calc label says (a wrong mapping, 23 Nov 2026).
 * Items: array('level' => fix|ask|map|warn, 'text', 'day' => day number or null).
 *   fix  = to correct in the Calc in Dropbox (the assistant does not start while there is one);
 *   ask  = to decide on the page (exact / indicative dates);
 *   map  = Calc texts without a mapping (the assistant helps);
 *   warn = to check, does not stop anything.
 * Returns array('items', 'counts' => [level => n], 'ai_ok' => no fix item).
 */
function iti_final_precheck(array $calc, array $review, ?array $plan, bool $groupOpen = false): array {
    $items = array();
    $add = function (string $level, string $text, $day = null) use (&$items) { $items[] = array('level' => $level, 'text' => $text, 'day' => $day); };

    foreach ($review['errors'] as $e) $add('fix', $e);
    if (!$groupOpen) foreach ($review['open_errors'] as $e) $add('fix', $e . ' (A group departure still filling up? Tick it when you confirm the data below.)');
    $add('ask', 'Dates: exact or indicative? The Calc does not say — it is asked when you confirm the data.');

    // Guests' ages on the first day against B2:B4 (teenager 12–15, child under 12, as the price table says).
    // Only when every guest is known with a date of birth and their number is the paying pax (no free Tour Leader).
    $pax = $calc['pax'] ?? array();
    $a = (int)($pax['adults'] ?? 0); $t = (int)($pax['teen'] ?? 0); $c = (int)($pax['child'] ?? 0);
    $guests = $calc['guests'] ?? array();
    $start = $calc['start_date'] ?? null;
    if (!$groupOpen && $start && $guests && empty($calc['guests_tba']) && empty($pax['tl']) && count($guests) === $a + $t + $c) {
        $byAge = array('adults' => 0, 'teen' => 0, 'child' => 0); $allDob = true;
        foreach ($guests as $g) {
            if (empty($g['dob'])) { $allDob = false; break; }
            $age = (int)date_diff(date_create($g['dob']), date_create($start))->y;
            $byAge[$age < 12 ? 'child' : ($age < 16 ? 'teen' : 'adults')]++;
        }
        if ($allDob && ($byAge['adults'] !== $a || $byAge['teen'] !== $t || $byAge['child'] !== $c)) {
            $add('warn', 'By their dates of birth the guests are ' . $byAge['adults'] . ' adults / ' . $byAge['teen'] . ' teenagers (12–15) / '
                . $byAge['child'] . ' children (under 12) on the first day, but B2:B4 say ' . $a . ' / ' . $t . ' / ' . $c
                . ': check B2:B4 and the teenager / child prices.');
        }
    }
    // ARRIVAL / DEPARTURE DETAILS (the guests' own flights) are not checked: clients often buy them later, also after
    // the booking is confirmed (Roberto, 23 Nov 2026).
    foreach ($review['warnings'] as $w) $add('warn', $w);
    foreach (($calc['warnings'] ?? array()) as $w) {
        if (strpos($w, 'Several Calc files') === 0) continue;   // the page has a file picker
        $add('warn', $w);
    }

    if ($plan) {
        $labels = array('lodge' => 'Hotel', 'activity' => 'Activity', 'route' => 'Route');
        foreach (array('lodge', 'activity', 'route') as $type) {
            foreach ($plan['unmapped'][$type] as $text) $add('map', $labels[$type] . ' "' . $text . '" is not mapped.');
        }
        $near = iti_final_near_places();
        $days = $plan['days'];
        foreach ($days as $i => $d) {
            $n = $i + 1;
            $prev = $i > 0 ? $days[$i - 1] : null;
            $route = $d['route'];
            // The hotel moves to another area (or the trip ends): the Calc must say how the guests get there.
            $moves = $prev && $prev['dest_id'] && (($d['dest_id'] && $d['dest_id'] !== $prev['dest_id']) || $d['last']);
            if ($moves) {
                $where = $d['last'] ? 'the departure' : $d['lodge_name'];
                if (!$route) {
                    $add('warn', 'Day ' . $n . ': from ' . $prev['lodge_name'] . ' to ' . $where . ' but the Calc has no PARK/OVERNIGHT text — how do the guests get there?', $n);
                } elseif ($route['state'] === 'ok' && !$route['transfer_route_id'] && !$route['flight_route_id']) {
                    $add('warn', 'Day ' . $n . ': from ' . $prev['lodge_name'] . ' to ' . $where . ', but "' . $route['text'] . '" is mapped to no transfer and no flight — check the transfer.', $n);
                }
            }
            if ($d['flight_cost'] !== null && $d['flight_cost'] > 0 && !($route && $route['flight_route_id'])) {
                $add('warn', 'Day ' . $n . ': the Calc has a flight cost but "' . ($route ? $route['text'] : '—') . '" is not mapped to a flight — which flight is it?', $n);
            }
            // A lodge that is not where the label says: often a wrong mapping. Not on a day trip from the same lodge.
            if ($d['lodge_id'] && $d['label'] !== '' && !($prev && $prev['lodge_id'] === $d['lodge_id'])) {
                $li = iti_final_lodge_info(db(), (int)$d['lodge_id']);
                $lp = $li ? iti_final_places($li['name'] . ' ' . $li['dest_name']) : array();
                $cp = iti_final_places((string)$d['label']);
                $fits = false;
                foreach (array_keys($lp) as $p) {
                    if (isset($cp[$p])) $fits = true;
                    foreach ($near[$p] ?? array() as $q) if (isset($cp[$q])) $fits = true;
                }
                if ($lp && $cp && !$fits) {
                    $add('warn', 'Day ' . $n . ': "' . $d['hotel_text'] . '" is mapped to ' . $d['lodge_name'] . ' (' . implode(', ', array_keys($lp)) . '), but the Calc says "'
                        . $d['label'] . '" (' . implode(', ', array_keys($cp)) . ')' . (!empty($d['lodge_local']) ? ' — mapped for this booking only: is the mapping right?' : ' — check the hotel.'), $n);
                }
            }
        }
    }
    $counts = array('fix' => 0, 'ask' => 0, 'map' => 0, 'warn' => 0);
    foreach ($items as $it) $counts[$it['level']]++;
    return array('items' => $items, 'counts' => $counts, 'ai_ok' => $counts['fix'] === 0);
}
