<?php
/**
 * Deposit / Balance payment notes read from a booking's "*_Calc.xlsx".
 *
 * The Calc sheet has rows like (label | amount | note):
 *     E11 "Deposit 1"   F11 3720   G11 "PAID BY CC 18 NOV-SARUNI"
 *     E14 "Balance"     F14 5958   G14 "on the way 14 SEPT - RDS"
 * Handled variants:
 *   - split payments (Deposit 1/2/3, Balance1, "Balance 1 ") — the un-numbered
 *     total row is dropped when it has no note of its own;
 *   - label in another column (D/A) — amount = next numeric cell, note = the cell
 *     right after the amount (cells further right hold unrelated data such as
 *     "350 single supplement");
 *   - "Deposit %" rows (a percentage, not a payment) are skipped;
 *   - Italian labels (Acconto / Saldo);
 *   - several sheets = alternative quotes: the one named *CONF* wins, else the
 *     one matching the customer name, else "ambiguous";
 *   - GROUP calcs (RECAP sheet): the customer's RECAP row (Deposit / PAID ON /
 *     Balance / PAID ON), else the customer's own sheet.
 * The Calc lives in the booking folder — for a GRP member, in the group's main
 * folder (one level up from the member's sub-folder).
 *
 * Results are cached in payment_excel_cache and refreshed when the Excel's
 * Dropbox revision changes (checked at most every CP_RECHECK_SECONDS).
 */
require_once __DIR__ . '/safari_check.php';   // sc_xlsx_cells / sc_xlsx_sheets / sc_cell
require_once __DIR__ . '/folder_parser.php';  // folder_rel_path / folder_is_booking_leaf

const CP_RECHECK_SECONDS = 600;
const CP_MAX_ROW = 40;
const CP_COLS = ['A','B','C','D','E','F','G','H','I','J','K','L'];
const CP_LABEL_COLS = 7;   // payment labels are looked for in A–G only

/** Files in a Dropbox folder with their revision: [['name'=>..,'rev'=>..], …]. */
function cp_list_files(string $token, string $path): array {
    $out = []; $cursor = null;
    do {
        $ch = curl_init('https://api.dropboxapi.com/2/files/list_folder' . ($cursor ? '/continue' : ''));
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => json_encode($cursor ? ['cursor' => $cursor] : ['path' => $path, 'recursive' => false]),
            CURLOPT_TIMEOUT        => 20,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code === 409) return [];   // folder not found
        if ($code !== 200) throw new RuntimeException("Dropbox list_folder failed (HTTP $code)");
        $data = json_decode($body, true);
        foreach ($data['entries'] ?? [] as $e) {
            if (($e['.tag'] ?? '') === 'file') $out[] = ['name' => $e['name'], 'rev' => $e['rev'] ?? ''];
        }
        $cursor = !empty($data['has_more']) ? ($data['cursor'] ?? null) : null;
    } while ($cursor);
    return $out;
}

/**
 * Pick the Calc workbook among a folder's files (highest leading number wins).
 * A Dropbox "conflicted copy" is used only when it is the sole Calc.
 */
function cp_pick_calc(array $files): ?array {
    $calcs = array_values(array_filter($files, fn($f) =>
        preg_match('/_calc[^\\/]*\.xlsx$/i', $f['name']) && stripos($f['name'], '~$') !== 0));
    $c = array_values(array_filter($calcs, fn($f) => stripos($f['name'], 'conflicted copy') === false)) ?: $calcs;
    if (!$c) return null;
    usort($c, fn($a, $b) => strnatcasecmp($b['name'], $a['name']));
    return $c[0];
}

/** Lower-case name tokens (≥3 letters) of a customer, splitting CamelCase. */
function cp_name_tokens(string $name): array {
    $name = preg_replace('/(?<=[a-z])(?=[A-Z])/', ' ', $name);
    $name = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) ?: $name;
    $toks = preg_split('/[^a-z]+/', strtolower($name), -1, PREG_SPLIT_NO_EMPTY);
    return array_values(array_unique(array_filter($toks, fn($t) => strlen($t) >= 3)));
}

/**
 * How many of the tokens appear in $text (case-insensitive). A token of 5+
 * letters also matches a word one typo away ("Caciolli" ~ "Cacioli").
 */
function cp_name_score(array $tokens, string $text): int {
    $ascii = strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text) ?: $text);
    $flat  = preg_replace('/[^a-z]+/', '', $ascii);
    $words = preg_split('/[^a-z]+/', $ascii, -1, PREG_SPLIT_NO_EMPTY);
    $n = 0;
    foreach ($tokens as $tok) {
        if ($tok === '') continue;
        if (strpos($flat, $tok) !== false) { $n++; continue; }
        if (strlen($tok) >= 5) {
            foreach ($words as $w) { if (levenshtein($tok, $w) <= 1) { $n++; break; } }
        }
    }
    return $n;
}

function cp_is_num(string $v): bool { return $v !== '' && is_numeric($v); }

/** Deposit/Balance kind of a label cell, or null if not a payment label. */
function cp_label_kind(string $v): ?string {
    $v = trim($v);
    // "Deposit %" is the percentage, not a payment ("Deposit + 4% CC" is one).
    if ($v === '' || preg_match('/^\w+\s*%/', $v)) return null;
    if (preg_match('/^(deposit|acconto)\b|^deposit\d/i', $v)) return 'Dep';
    if (preg_match('/^(balance|saldo)\b|^balance\d/i', $v)) return 'Bal';
    return null;
}

/**
 * Payment lines found in one sheet's cells map. Labels are read only in A–G:
 * columns further right hold side calculations ("Deposit + 4% CC" in I).
 * With no Deposit/Balance rows at all, the note beside "Tot price" is used.
 */
function cp_sheet_lines(array $cells): array {
    $lines = [];
    $tot   = null;
    for ($row = 1; $row <= CP_MAX_ROW; $row++) {
        foreach (array_slice(CP_COLS, 0, CP_LABEL_COLS) as $ci => $col) {
            $label = sc_cell($cells, $col, $row);
            $kind  = cp_label_kind($label);
            if (!$kind && $tot === null && preg_match('/^tot\s+price$/i', $label)) {
                $amt  = sc_cell($cells, CP_COLS[$ci + 1], $row);
                $note = sc_cell($cells, CP_COLS[$ci + 2], $row);
                if ($note !== '' && !cp_is_num($note)) {
                    $tot = ['kind' => 'Tot', 'label' => 'Tot price',
                            'amount' => cp_is_num($amt) ? (float)$amt : null, 'note' => $note];
                }
            }
            if (!$kind) continue;
            // Amount: first numeric cell within the next 3 columns.
            $amount = null; $amtIdx = null;
            for ($k = $ci + 1; $k <= min($ci + 3, count(CP_COLS) - 1); $k++) {
                $v = sc_cell($cells, CP_COLS[$k], $row);
                if (cp_is_num($v)) { $amount = (float)$v; $amtIdx = $k; break; }
                if ($v !== '') break;   // text before any number → no amount
            }
            // Note: the cell right after the amount (or right after the label).
            $noteIdx = ($amtIdx ?? $ci) + 1;
            $note = $noteIdx < count(CP_COLS) ? sc_cell($cells, CP_COLS[$noteIdx], $row) : '';
            if (cp_is_num($note)) $note = '';
            if ($amount === null && $note === '') continue;
            $lines[] = [
                'kind'     => $kind,
                'label'    => preg_replace('/\s+/', ' ', trim($label)),
                'numbered' => (bool)preg_match('/\d/', $label),
                'amount'   => $amount,
                'note'     => $note,
            ];
            break;   // one payment label per row
        }
    }
    // Split payments: drop the un-numbered total row when it carries no note.
    foreach (['Dep', 'Bal'] as $kind) {
        $hasNumbered = false;
        foreach ($lines as $l) if ($l['kind'] === $kind && $l['numbered']) $hasNumbered = true;
        if ($hasNumbered) {
            $lines = array_values(array_filter($lines, fn($l) =>
                $l['kind'] !== $kind || $l['numbered'] || $l['note'] !== ''));
        }
    }
    if (!$lines && $tot) return [$tot];
    return array_map(function ($l) { unset($l['numbered']); return $l; }, $lines);
}

/** The customer's row in a GROUP RECAP sheet → payment lines, or null. */
function cp_recap_lines(array $cells, array $tokens): ?array {
    // Header row: a "Cust name" cell, with Deposit / Balance headers on the same row.
    for ($hr = 1; $hr <= CP_MAX_ROW; $hr++) {
        if (stripos(sc_cell($cells, 'A', $hr), 'cust') !== 0) continue;
        $cols = [];
        foreach (CP_COLS as $i => $col) {
            $k = cp_label_kind(sc_cell($cells, $col, $hr));
            if ($k && !isset($cols[$k])) $cols[$k] = $i;
        }
        if (!$cols) return null;
        $best = null; $bestScore = 0; $tie = false;
        // Customer rows are the contiguous block under the header (free-text notes
        // further down may mention names too).
        for ($r = $hr + 1; $r <= $hr + 40; $r++) {
            $name = sc_cell($cells, 'A', $r);
            if ($name === '') break;
            $s = cp_name_score($tokens, $name);
            if ($s > $bestScore) { $best = $r; $bestScore = $s; $tie = false; }
            elseif ($s === $bestScore && $s > 0) { $tie = true; }
        }
        if (!$best || $tie) return null;
        $lines = [];
        foreach ($cols as $kind => $i) {
            $amt  = sc_cell($cells, CP_COLS[$i], $best);
            $note = $i + 1 < count(CP_COLS) ? sc_cell($cells, CP_COLS[$i + 1], $best) : '';
            if (cp_is_num($note)) $note = '';
            if (!cp_is_num($amt) && $note === '') continue;
            $lines[] = ['kind' => $kind, 'label' => $kind === 'Dep' ? 'Deposit' : 'Balance',
                        'amount' => cp_is_num($amt) ? (float)$amt : null, 'note' => $note];
        }
        return $lines ?: null;
    }
    return null;
}

/**
 * Parse a local Calc workbook for one customer.
 * @return array ['status'=>'ok'|'ambiguous'|'none', 'sheet'=>string, 'lines'=>[], 'sheets'=>[]]
 */
function cp_parse_calc(string $path, string $customer, bool $isGrp): array {
    $tokens = cp_name_tokens($customer);
    $sheets = sc_xlsx_sheets($path);

    $recap = null;
    foreach ($sheets as $s) if (stripos($s, 'recap') !== false) { $recap = $s; break; }
    if ($recap !== null) {
        $lines = cp_recap_lines(sc_xlsx_cells($path, $recap), $tokens);
        if ($lines) return ['status' => 'ok', 'sheet' => trim($recap), 'lines' => $lines];
    }

    // Sheets that carry payment rows.
    $cands = [];
    foreach ($sheets as $s) {
        if ($s === $recap) continue;
        $lines = cp_sheet_lines(sc_xlsx_cells($path, $s));
        if ($lines) $cands[$s] = $lines;
    }
    if (!$cands) return ['status' => 'none', 'sheet' => '', 'lines' => []];
    if (count($cands) === 1) {
        $s = array_key_first($cands);
        return ['status' => 'ok', 'sheet' => trim($s), 'lines' => $cands[$s]];
    }

    $pick = function (array $names) use ($cands) {
        return count($names) === 1 ? ['status' => 'ok', 'sheet' => trim($names[0]), 'lines' => $cands[$names[0]]] : null;
    };
    $conf = array_values(array_filter(array_keys($cands), fn($s) => preg_match('/conf/i', $s)));
    // Group calc: the customer's own sheet first; private: the confirmed quote first.
    $byName = [];
    $scores = [];
    foreach (array_keys($cands) as $s) $scores[$s] = cp_name_score($tokens, $s);
    $max = $scores ? max($scores) : 0;
    if ($max > 0) $byName = array_values(array_keys(array_filter($scores, fn($v) => $v === $max)));

    $order = $isGrp ? [$byName, $conf] : [$conf, $byName];
    foreach ($order as $names) { if ($r = $pick($names)) return $r; }
    // Several CONF sheets: narrow by name.
    if (count($conf) > 1 && $byName) {
        $both = array_values(array_intersect($conf, $byName));
        if ($r = $pick($both)) return $r;
    }
    return ['status' => 'ambiguous', 'sheet' => '', 'lines' => [], 'sheets' => array_map('trim', array_keys($cands))];
}

/** Create the cache table on first use. */
function cp_ensure_table(PDO $db): void {
    static $done = false;
    if ($done) return;
    $db->exec("CREATE TABLE IF NOT EXISTS payment_excel_cache (
        request_id  INT UNSIGNED NOT NULL PRIMARY KEY,
        file_path   VARCHAR(1000) NULL,
        file_rev    VARCHAR(64)  NULL,
        result_json MEDIUMTEXT   NULL,
        checked_at  DATETIME     NOT NULL
    ) DEFAULT CHARSET=utf8mb4");
    $done = true;
}

/**
 * Payment notes for one request row (needs customer_name, practice_code,
 * group_folder, dropbox_url). Cached; $force skips the recheck interval.
 */
function cp_payments_for_request(PDO $db, array $r, bool $force = false): array {
    cp_ensure_table($db);
    $id = (int)$r['id'];
    $st = $db->prepare("SELECT * FROM payment_excel_cache WHERE request_id=?");
    $st->execute([$id]);
    $cache = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    $cached = $cache ? json_decode($cache['result_json'] ?? '', true) : null;
    if (!$force && $cached && strtotime($cache['checked_at']) > time() - CP_RECHECK_SECONDS) {
        return $cached;
    }

    $save = function (array $res, ?string $file, ?string $rev) use ($db, $id) {
        $db->prepare("REPLACE INTO payment_excel_cache (request_id, file_path, file_rev, result_json, checked_at)
                      VALUES (?, ?, ?, ?, NOW())")
           ->execute([$id, $file, $rev, json_encode($res, JSON_UNESCAPED_UNICODE)]);
        return $res;
    };

    $rel = folder_rel_path($r);
    if ($rel === '') return $save(['status' => 'nofolder', 'lines' => []], null, null);

    require_once __DIR__ . '/../dropbox_helper.php';
    $token = dropbox_get_access_token();

    // The booking folder; for a GRP member the Calc is one level up (group folder).
    $dirs = ['/' . $rel];
    $segs = explode('/', $rel);
    if (count($segs) > 2 && folder_is_booking_leaf($segs[count($segs) - 2])) {
        $dirs[] = '/' . implode('/', array_slice($segs, 0, -1));
    }
    $calc = null; $dir = null;
    foreach ($dirs as $d) {
        $calc = cp_pick_calc(cp_list_files($token, $d));
        if ($calc) { $dir = $d; break; }
    }
    if (!$calc) return $save(['status' => 'nocalc', 'lines' => []], null, null);

    $file = $dir . '/' . $calc['name'];
    // Same file & revision → only refresh the check time.
    if ($cached && $cache['file_path'] === $file && $cache['file_rev'] === $calc['rev']) {
        $db->prepare("UPDATE payment_excel_cache SET checked_at=NOW() WHERE request_id=?")->execute([$id]);
        return $cached;
    }

    $bytes = dropbox_download_text($token, $file);
    if ($bytes === null || $bytes === '') return $save(['status' => 'nocalc', 'lines' => []], null, null);
    $tmp = tempnam(sys_get_temp_dir(), 'paycalc_');
    file_put_contents($tmp, $bytes);
    try {
        $res = cp_parse_calc($tmp, (string)$r['customer_name'], trim($r['group_folder'] ?? '') !== '');
    } finally {
        @unlink($tmp);
    }
    $res['file'] = $calc['name'];
    return $save($res, $file, $calc['rev']);
}
