<?php
/**
 * dbx_conflict_service.php — Dropbox "conflicted copies" in /001_Safari: mostly the *_Calc.xlsx kept open in Excel on
 * one PC while another PC (same Dropbox account) or the Hub saves it. Shared by the nightly run (conflicts_cron.php),
 * the page dropbox_conflicts.php and the Agent API dropbox_conflicts / dropbox_conflicts_run.
 *
 * Each conflicted copy C ("X (Savannah Explorers's conflicted copy 2026-10-22[ 1]).xlsx", "X (Copia in conflitto di …
 * 2026-10-16).xlsx") is compared with its original O (same name without the bracket), sub-folders old/ left out:
 *   identical    same content (hash) or same cells as O                                   → C to the Dropbox trash
 *   old_version  same as an earlier revision of O (Dropbox version history)                → trash
 *   already_in   every cell C changed (vs its base A, a revision of O) already has that value in O → trash
 *   merged       C and O changed different cells: C's cells are written into O (.xlsx only; the rewritten file is
 *                read back and must differ from O in those cells only, before and after the rev-checked upload) → trash
 *   conflict     the same cell changed differently in both, sheets added / removed, too many changes (rows moved),
 *                not an .xlsx, or the rewrite would change other cells                     → the owner fixes it by hand
 *   no_original / not_excel / no_history / error                                         → the owner
 * Cells are compared as written (formula text, not its result), on every sheet. A = the revision of O saved before C
 * that is closest to C. The owner is the booking's CK owner (ck_owners_resolve(): the seller in the folder tag).
 *
 * Until DBX_CONFLICTS_LIVE is true (server config) the nightly run only analyses: nothing is moved, written, memoed or
 * mailed, and the page shows what it would do. Live: one memo per booking on the owner's Memo Board (rewritten each
 * night, closed when the copies are gone) and one mail per owner per night with the new ones. Trips ended more than
 * DBXC_PAST_DAYS days ago are still resolved when safe, but nobody is asked to fix them.
 *
 * Requires config.php, dropbox_helper.php, includes/calc_service.php (download / rev-checked upload, PhpSpreadsheet),
 * includes/ck_lib.php, ../memo/memo_lib.php and includes/mail_helper.php (loaded here).
 */

require_once __DIR__ . '/../dropbox_helper.php';
require_once __DIR__ . '/calc_service.php';
require_once __DIR__ . '/ck_lib.php';
require_once __DIR__ . '/timeline_service.php';
require_once __DIR__ . '/../../memo/memo_lib.php';
require_once __DIR__ . '/../../../includes/mail_helper.php';

const DBXC_MAX_REVS   = 8;     // earlier revisions of O read to find C's base
const DBXC_MAX_MERGE  = 40;    // more changed cells than this = rows inserted / moved: not merged
const DBXC_PAST_DAYS  = 7;     // trips ended longer ago: no memo / mail
const DBXC_NIGHT_FROM = 1;     // nightly window, EAT hours [from, to)
const DBXC_NIGHT_TO   = 7;
const DBXC_PAGE_URL   = 'https://hub.savannahexplorers.com/modules/leads/dropbox_conflicts.php';

/** outcome => [label, resolved automatically] */
const DBXC_OUTCOMES = [
    'identical'   => ['Same as the original', true],
    'old_version' => ['Older version of the original', true],
    'already_in'  => ['Its changes are already in the original', true],
    'merged'      => ['Changes merged into the original', true],
    'conflict'    => ['Needs a person: both files changed', false],
    'no_original' => ['Original file not found', false],
    'not_excel'   => ['Not an Excel file: compare by hand', false],
    'no_history'  => ['No earlier version to compare with', false],
    'error'       => ['Error', false],
];

/** Why a conflict could not be merged. */
const DBXC_REASONS = [
    'same_cells' => 'the same cells were changed in both files',
    'structure'  => 'sheets were added, removed or renamed',
    'too_many'   => 'too many cells changed (rows inserted or moved?)',
    'not_xlsx'   => 'only .xlsx files are merged automatically',
    'rewrite'    => 'the Hub cannot rewrite this file without changing other cells',
];

function dbxc_live(): bool {
    return defined('DBX_CONFLICTS_LIVE') && DBX_CONFLICTS_LIVE;
}

function dbxc_schema(PDO $db): void {
    static $done = false;
    if ($done) return;
    $db->exec("CREATE TABLE IF NOT EXISTS dbx_conflicts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        path_hash CHAR(40) NOT NULL,
        path VARCHAR(1000) NOT NULL,
        original_path VARCHAR(1000) NOT NULL,
        folder VARCHAR(255) NOT NULL,
        request_id INT NULL,
        owner_user_id INT NULL,
        trip_end DATE NULL,
        c_rev VARCHAR(64) NOT NULL,
        o_rev VARCHAR(64) NULL,
        c_saved_at DATETIME NULL,
        outcome VARCHAR(20) NOT NULL,
        status VARCHAR(10) NOT NULL DEFAULT 'open',
        action VARCHAR(10) NULL,
        detail MEDIUMTEXT NULL,
        first_seen_at DATETIME NOT NULL,
        last_seen_at DATETIME NOT NULL,
        checked_at DATETIME NOT NULL,
        resolved_at DATETIME NULL,
        mailed_at DATETIME NULL,
        UNIQUE KEY uq_dbxc_path (path_hash),
        KEY idx_dbxc_status (status, owner_user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS dbx_conflict_meta (
        k VARCHAR(40) PRIMARY KEY,
        v TEXT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

function dbxc_meta(PDO $db, string $k): ?string {
    $st = $db->prepare("SELECT v FROM dbx_conflict_meta WHERE k = ?");
    $st->execute([$k]);
    $v = $st->fetchColumn();
    return $v === false ? null : (string)$v;
}

function dbxc_meta_set(PDO $db, string $k, string $v): void {
    $db->prepare("INSERT INTO dbx_conflict_meta (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)")->execute([$k, $v]);
}

// ── Names ────────────────────────────────────────────────────────────────────

/**
 * A conflicted copy's name → ['original' => name without the bracket, 'date' => Y-m-d], or null. Both Dropbox
 * languages, with or without the PC / account name and the " 1" Dropbox adds to a second copy of the same day.
 */
function dbxc_parse_name(string $name): ?array {
    $re = '/^(.+?) \((?:[^()]*\'s conflicted copy|conflicted copy|Copia in conflitto di [^()]*|Copia in conflitto) (\d{4}-\d{2}-\d{2})(?: \d+)?\)(\.[A-Za-z0-9]{1,6})?$/u';
    if (!preg_match($re, $name, $m)) return null;
    return ['original' => $m[1] . ($m[3] ?? ''), 'date' => $m[2]];
}

/** True when a path below CK_BASE goes through an old/ sub-folder (copies already archived by hand). */
function dbxc_in_old(string $lowerPath): bool {
    $parts = explode('/', trim(substr($lowerPath, strlen(CK_BASE)), '/'));
    array_pop($parts);   // the file name
    return in_array('old', $parts, true);
}

// ── Dropbox ──────────────────────────────────────────────────────────────────

/** POST a Dropbox RPC endpoint → [http code, decoded body]. */
function dbxc_api(string $token, string $endpoint, array $payload): array {
    $ch = curl_init('https://api.dropboxapi.com/2/' . $endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode($payload),
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, json_decode((string)$body, true) ?: ['raw' => (string)$body]];
}

/** Dropbox file entry → the fields used here. */
function dbxc_entry(array $e): array {
    return [
        'path'   => (string)$e['path_display'],
        'lower'  => (string)$e['path_lower'],
        'name'   => (string)$e['name'],
        'rev'    => (string)($e['rev'] ?? ''),
        'hash'   => (string)($e['content_hash'] ?? ''),
        'saved'  => isset($e['client_modified']) ? strtotime($e['client_modified']) : null,   // when the PC saved it
        'server' => isset($e['server_modified']) ? strtotime($e['server_modified']) : null,
    ];
}

/** Every file below CK_BASE outside old/ sub-folders: [path_lower => dbxc_entry()]. */
function dbxc_list(string $token): array {
    $out = [];
    [$code, $data] = dbxc_api($token, 'files/list_folder', ['path' => CK_BASE, 'recursive' => true, 'limit' => 2000]);
    while (true) {
        if ($code !== 200) throw new RuntimeException("Dropbox list_folder failed (HTTP $code): " . json_encode($data));
        foreach ($data['entries'] ?? [] as $e) {
            if (($e['.tag'] ?? '') !== 'file' || dbxc_in_old((string)$e['path_lower'])) continue;
            $out[(string)$e['path_lower']] = dbxc_entry($e);
        }
        if (empty($data['has_more'])) break;
        [$code, $data] = dbxc_api($token, 'files/list_folder/continue', ['cursor' => $data['cursor']]);
    }
    return $out;
}

/** Revisions of a file, newest first (dbxc_entry() shape). */
function dbxc_revisions(string $token, string $path, int $limit = 30): array {
    [$code, $data] = dbxc_api($token, 'files/list_revisions', ['path' => $path, 'mode' => 'path', 'limit' => $limit]);
    if ($code !== 200) throw new RuntimeException("Dropbox list_revisions failed (HTTP $code): " . json_encode($data));
    return array_map('dbxc_entry', $data['entries'] ?? []);
}

/** Move a file to the Dropbox trash, only if it is still at $rev (restorable from Deleted files / version history). */
function dbxc_trash(string $token, string $path, string $rev): void {
    [$code, $data] = dbxc_api($token, 'files/delete_v2', ['path' => $path, 'parent_rev' => $rev]);
    if ($code !== 200) throw new RuntimeException("Dropbox delete failed (HTTP $code): " . ($data['error_summary'] ?? json_encode($data)));
}

/** Bytes of a file at a revision. */
function dbxc_bytes(string $token, string $rev): string {
    $dl = calc_dbx_download($token, 'rev:' . $rev);
    if ($dl === null) throw new RuntimeException('Revision ' . $rev . ' not found in Dropbox.');
    return $dl['bytes'];
}

// ── Cells ────────────────────────────────────────────────────────────────────

/** A cell value as compared: "n:12.5", "s:text", "f:=SUM(A1:A3)", "b:1"; null = empty. */
function dbxc_norm($v): ?string {
    if ($v instanceof \PhpOffice\PhpSpreadsheet\RichText\RichText) $v = $v->getPlainText();
    if ($v === null || $v === '') return null;
    if (is_bool($v)) return 'b:' . ($v ? 1 : 0);
    if (is_int($v) || is_float($v)) {
        $n = is_float($v) ? rtrim(rtrim(sprintf('%.10F', $v), '0'), '.') : (string)$v;
        return 'n:' . ($n === '-0' ? '0' : $n);
    }
    $s = (string)$v;
    return ($s[0] === '=' ? 'f:' : 's:') . $s;
}

/** A dbxc_norm() value as shown to people. */
function dbxc_show(?string $v): string {
    if ($v === null) return '(empty)';
    $s = substr($v, 2);
    if ($v[0] === 'b') $s = $s === '1' ? 'TRUE' : 'FALSE';
    return mb_strlen($s) > 60 ? mb_substr($s, 0, 57) . '…' : $s;
}

/** An Excel file's content: ['sheets' => [titles], 'cells' => ['Sheet!B7' => norm]]. */
function dbxc_cells(string $bytes, string $ext): array {
    calc_require_spreadsheet();
    $tmp = tempnam(sys_get_temp_dir(), 'dbxc');
    file_put_contents($tmp, $bytes);
    try {
        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReader($ext === 'xls' ? 'Xls' : 'Xlsx');
        $reader->setReadDataOnly(true);
        $ss = $reader->load($tmp);
        $out = ['sheets' => [], 'cells' => []];
        foreach ($ss->getWorksheetIterator() as $ws) {
            $title = $ws->getTitle();
            $out['sheets'][] = $title;
            foreach ($ws->getCellCollection()->getCoordinates() as $coord) {
                $v = dbxc_norm($ws->getCell($coord)->getValue());
                if ($v !== null) $out['cells'][$title . '!' . $coord] = $v;
            }
        }
        $ss->disconnectWorksheets();
        ksort($out['cells']);   // so two files compare with ===
        return $out;
    } finally {
        @unlink($tmp);
    }
}

/** Keys whose value differs between two cell maps. */
function dbxc_diff(array $a, array $b): array {
    $out = [];
    foreach ($a + $b as $k => $_) {
        if (($a[$k] ?? null) !== ($b[$k] ?? null)) $out[] = $k;
    }
    return $out;
}

/**
 * The decision once C's base A is known (dbxc_cells() shapes). → ['outcome' => already_in | merged | conflict,
 * 'reason' => DBXC_REASONS key|null, 'keys' => the cells to write into O (C's changes not yet in O), 'both' => the
 * cells O changed too, to another value]. Pure: no Dropbox, testable alone.
 */
function dbxc_decide(array $a, array $o, array $c, string $ext): array {
    $missing = [];
    foreach (dbxc_diff($a['cells'], $c['cells']) as $k) {
        if (($o['cells'][$k] ?? null) !== ($c['cells'][$k] ?? null)) $missing[] = $k;
    }
    $res = ['outcome' => 'already_in', 'reason' => null, 'keys' => $missing, 'both' => []];
    if (!$missing) return $res;
    foreach ($missing as $k) {
        if (($o['cells'][$k] ?? null) !== ($a['cells'][$k] ?? null)) $res['both'][] = $k;
    }
    $sheetGone = false;
    foreach ($missing as $k) {
        if (!in_array(substr($k, 0, strrpos($k, '!')), $o['sheets'], true)) $sheetGone = true;
    }
    $res['outcome'] = 'conflict';
    if ($a['sheets'] !== $c['sheets'] || $sheetGone)          $res['reason'] = 'structure';
    elseif ($res['both'])                                     $res['reason'] = 'same_cells';
    elseif (count($missing) > DBXC_MAX_MERGE)                 $res['reason'] = 'too_many';
    elseif ($ext !== 'xlsx')                                  $res['reason'] = 'not_xlsx';
    else                                                      $res['outcome'] = 'merged';
    return $res;
}

/** Write one dbxc_norm() value into a PhpSpreadsheet cell. */
function dbxc_set_cell($cell, ?string $v): void {
    $T = '\PhpOffice\PhpSpreadsheet\Cell\DataType';
    if ($v === null) { $cell->setValue(null); return; }
    $s = substr($v, 2);
    switch ($v[0]) {
        case 'f': $cell->setValueExplicit($s, constant($T . '::TYPE_FORMULA')); break;
        case 'n': $cell->setValueExplicit((float)$s, constant($T . '::TYPE_NUMERIC')); break;
        case 'b': $cell->setValueExplicit($s === '1', constant($T . '::TYPE_BOOL')); break;
        default:  $cell->setValueExplicit($s, constant($T . '::TYPE_STRING'));
    }
}

/** O's cells with C's values written in $keys: what O must contain after a merge. */
function dbxc_expected(array $o, array $c, array $keys): array {
    $cells = $o['cells'];
    foreach ($keys as $k) {
        if (isset($c['cells'][$k])) $cells[$k] = $c['cells'][$k];
        else unset($cells[$k]);
    }
    ksort($cells);
    return ['sheets' => $o['sheets'], 'cells' => $cells];
}

/**
 * Write C's cells $keys into O (dbxc_entry $o at $o['rev'], cells $O), recalculate and read the rewritten file back:
 * it must equal dbxc_expected(), else nothing is uploaded and 'other' lists the cells the rewrite would change.
 * $upload: rev-checked upload (O unchanged since the listing), then read back again from Dropbox.
 * → ['ok' => bool, 'rev' => new rev | null, 'other' => [cells]]. Throws on Dropbox errors.
 */
function dbxc_merge(string $token, array $o, array $O, array $C, array $keys, bool $upload): array {
    calc_require_spreadsheet();
    if ($upload) {
        $dl = calc_dbx_download($token, $o['path']);
        if ($dl === null || $dl['rev'] !== $o['rev']) throw new RuntimeException('The original changed during the run — not merged, next run.');
        $bytes = $dl['bytes'];
    } else {
        $bytes = dbxc_bytes($token, $o['rev']);
    }
    $expect = dbxc_expected($O, $C, $keys);
    $tmpIn = tempnam(sys_get_temp_dir(), 'dbxi');
    $tmpOut = tempnam(sys_get_temp_dir(), 'dbxo') . '.xlsx';
    file_put_contents($tmpIn, $bytes);
    try {
        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReader('Xlsx');
        $reader->setIncludeCharts(true);
        $ss = $reader->load($tmpIn);
        foreach ($keys as $k) {
            $p = strrpos($k, '!');
            $ws = $ss->getSheetByName(substr($k, 0, $p));
            if (!$ws) throw new RuntimeException('Sheet missing in the original: ' . substr($k, 0, $p));
            dbxc_set_cell($ws->getCell(substr($k, $p + 1)), $C['cells'][$k] ?? null);
        }
        \PhpOffice\PhpSpreadsheet\Calculation\Calculation::getInstance($ss)->clearCalculationCache();
        $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($ss, 'Xlsx');
        $writer->setIncludeCharts(true);
        $writer->setPreCalculateFormulas(true);   // cached values for the Hub parser, as fill_calc
        $writer->save($tmpOut);
        $ss->disconnectWorksheets();
        $out = (string)file_get_contents($tmpOut);
    } finally {
        @unlink($tmpIn); @unlink($tmpOut);
    }
    $built = dbxc_cells($out, 'xlsx');
    if ($built !== $expect) {
        return ['ok' => false, 'rev' => null, 'other' => $built['sheets'] !== $expect['sheets'] ? ['(sheets)'] : dbxc_diff($expect['cells'], $built['cells'])];
    }
    if (!$upload) return ['ok' => true, 'rev' => null, 'other' => []];
    $meta = calc_dbx_upload_update($token, $o['path'], $out, $o['rev']);
    $newRev = (string)($meta['rev'] ?? '');
    $back = dbxc_cells(dbxc_bytes($token, $newRev), 'xlsx');
    if ($back !== $expect) {
        throw new RuntimeException('Merged, but the original reads back different in ' . implode(', ', array_slice(dbxc_diff($expect['cells'], $back['cells']), 0, 10))
                                   . ' — check it (its previous version is in the Dropbox history).');
    }
    return ['ok' => true, 'rev' => $newRev, 'other' => []];
}

// ── One conflicted copy ──────────────────────────────────────────────────────

/** The cells to show for a copy: [{cell, base, original, copy, both}]. */
function dbxc_cell_rows(array $keys, ?array $a, array $o, array $c, array $both): array {
    $rows = [];
    foreach (array_slice($keys, 0, DBXC_MAX_MERGE) as $k) {
        $rows[] = ['cell' => $k, 'base' => $a ? dbxc_show($a['cells'][$k] ?? null) : null,
                   'original' => dbxc_show($o['cells'][$k] ?? null), 'copy' => dbxc_show($c['cells'][$k] ?? null),
                   'both' => in_array($k, $both, true)];
    }
    return $rows;
}

/**
 * Analyse a conflicted copy $c against its original $o (dbxc_entry(), null = not found) and, when $live, resolve it.
 * → ['outcome', 'action' => trashed|merged|null, 'o_rev', 'detail' => [...]]
 */
function dbxc_process(string $token, array $c, ?array $o, bool $live): array {
    $r = ['outcome' => 'error', 'action' => null, 'o_rev' => $o['rev'] ?? null, 'detail' => []];
    $finish = function (string $outcome, array $detail = []) use (&$r, $token, $c, $live) {
        $r['outcome'] = $outcome;
        $r['detail'] = $detail + $r['detail'];
        if ($live && DBXC_OUTCOMES[$outcome][1] && $outcome !== 'merged') {
            dbxc_trash($token, $c['path'], $c['rev']);
            $r['action'] = 'trashed';
        }
        return $r;
    };
    if (!$o) return $finish('no_original');
    if ($c['hash'] !== '' && $c['hash'] === $o['hash']) return $finish('identical');

    $ext = strtolower(pathinfo($o['name'], PATHINFO_EXTENSION));
    $revs = dbxc_revisions($token, $o['path']);
    foreach ($revs as $rv) {
        if ($rv['hash'] === $c['hash'] && $rv['rev'] !== $o['rev']) return $finish('old_version', ['base_saved' => date('Y-m-d H:i', $rv['server'])]);
    }
    if (!in_array($ext, ['xlsx', 'xlsm', 'xls'], true)) return $finish('not_excel');

    $C = dbxc_cells(dbxc_bytes($token, $c['rev']), $ext);
    $O = dbxc_cells(dbxc_bytes($token, $o['rev']), $ext);
    if ($C === $O) return $finish('identical');

    // C's base: of the revisions of O saved before C (a few minutes' margin, O itself included), the one closest to C.
    $best = null;
    $n = 0;
    foreach ($revs as $rv) {
        if ($rv['server'] === null || ($c['saved'] && $rv['server'] > $c['saved'] + 300)) continue;
        if (++$n > DBXC_MAX_REVS) break;
        $R = $rv['rev'] === $o['rev'] ? $O : dbxc_cells(dbxc_bytes($token, $rv['rev']), $ext);
        if ($R === $C) return $finish('old_version', ['base_saved' => date('Y-m-d H:i', $rv['server'])]);
        $d = count(dbxc_diff($R['cells'], $C['cells']));
        if ($best === null || $d < $best['d']) $best = ['d' => $d, 'cells' => $R, 'saved' => $rv['server']];
    }
    if ($best === null) {
        $keys = dbxc_diff($O['cells'], $C['cells']);
        return $finish('no_history', ['cells' => dbxc_cell_rows($keys, null, $O, $C, []), 'n_cells' => count($keys)]);
    }
    $dec = dbxc_decide($best['cells'], $O, $C, $ext);
    $detail = ['base_saved' => date('Y-m-d H:i', $best['saved']), 'n_cells' => count($dec['keys']), 'reason' => $dec['reason'],
               'cells' => dbxc_cell_rows($dec['keys'], $best['cells'], $O, $C, $dec['both'])];
    if ($dec['outcome'] !== 'merged') return $finish($dec['outcome'], $detail);
    // Not live: the merge is still built and read back (not uploaded), so the page shows what will really happen.
    $m = dbxc_merge($token, $o, $O, $C, $dec['keys'], $live);
    if (!$m['ok']) return $finish('conflict', ['reason' => 'rewrite', 'other_cells' => array_slice($m['other'], 0, 10)] + $detail);
    $r['outcome'] = 'merged';
    $r['detail'] = $detail;
    if ($live) {
        $r['o_rev'] = $m['rev'];
        dbxc_trash($token, $c['path'], $c['rev']);
        $r['action'] = 'merged';
    }
    return $r;
}

// ── Booking: folder, request, owner ──────────────────────────────────────────

/** Top folder below CK_BASE of a path ("/001_Safari/<folder>/…"). */
function dbxc_folder(string $path): string {
    return (string)explode('/', trim(substr($path, strlen(CK_BASE)), '/'))[0];
}

/** ['request_id', 'owner_user_id', 'trip_end'] of a top folder (CK tracker row; else the seller in its tag; else the fallback). */
function dbxc_folder_info(PDO $db, string $folder): array {
    static $rows = null;
    if ($rows === null) {
        $rows = [];
        foreach (ck_tracker_rows($db) as $r) $rows[mb_strtolower($r['folder_name'])] = $r;
    }
    $row = $rows[mb_strtolower($folder)] ?? null;
    if ($row) {
        $o = ck_owners_resolve($db, $row);
        return ['request_id' => $row['reqs'] ? min(array_map(fn($q) => (int)$q['id'], $row['reqs'])) : null,
                'owner_user_id' => $o['ck_owner']['user_id'] ?? null, 'trip_end' => $row['end_date'] ?: null];
    }
    $u = null;
    foreach (ck_seller_names($folder) as $n) if ($u = ck_seller_user($db, $n)) break;
    $u = $u ?: ck_user_find($db, CK_OWNER_FALLBACK);
    return ['request_id' => null, 'owner_user_id' => $u ? (int)$u['id'] : null, 'trip_end' => null];
}

// ── Run ──────────────────────────────────────────────────────────────────────

/**
 * Find and process the conflicted copies. $opt: live (default DBX_CONFLICTS_LIVE), budget (seconds, default 240),
 * notify (memos + mails once nothing is left to check; default = live). Copies are checked again when C or O
 * changed, when a planned action can now be done (live), or after an error (once a day). Copies gone since the
 * last run are marked gone (someone fixed them). → summary.
 */
function dbxc_run(PDO $db, array $opt = []): array {
    dbxc_schema($db);
    $live   = $opt['live'] ?? dbxc_live();
    $notify = $opt['notify'] ?? $live;
    $until  = time() + (int)($opt['budget'] ?? 240);
    $now    = date('Y-m-d H:i:s');
    $sum    = ['live' => $live, 'conflicts' => 0, 'checked' => 0, 'left' => 0, 'gone' => 0, 'outcomes' => [], 'errors' => [], 'notified' => null];
    if (!$db->query("SELECT GET_LOCK('dbxc_run', 0)")->fetchColumn()) return ['ok' => false, 'msg' => 'Another run is in progress.'] + $sum;
    try {
        @ini_set('memory_limit', '512M');
        $token = dropbox_get_access_token();
        $files = dbxc_list($token);
        $found = [];
        foreach ($files as $lower => $f) {
            $p = dbxc_parse_name($f['name']);
            if (!$p) continue;
            $oLower = mb_strtolower(dirname($f['path']) . '/' . $p['original']);
            $found[sha1($lower)] = ['c' => $f, 'o' => $files[$oLower] ?? null];
        }
        $sum['conflicts'] = count($found);

        $rows = [];
        foreach ($db->query("SELECT * FROM dbx_conflicts")->fetchAll(PDO::FETCH_ASSOC) as $r) $rows[$r['path_hash']] = $r;
        $gone = $db->prepare("UPDATE dbx_conflicts SET status = 'gone', resolved_at = ? WHERE id = ?");
        foreach ($rows as $h => $r) {
            if ($r['status'] === 'open' && !isset($found[$h])) { $gone->execute([$now, $r['id']]); $sum['gone']++; }
        }
        $seen = $db->prepare("UPDATE dbx_conflicts SET last_seen_at = ? WHERE id = ?");
        $pending = [];
        foreach ($found as $h => $x) {
            $r = $rows[$h] ?? null;
            if ($r) $seen->execute([$now, $r['id']]);
            $due = !$r || $r['status'] !== 'open' || $r['c_rev'] !== $x['c']['rev'] || $r['o_rev'] !== ($x['o']['rev'] ?? null)
                || ($live && DBXC_OUTCOMES[$r['outcome']][1] && $r['action'] === null)
                || ($r['outcome'] === 'error' && substr($r['checked_at'], 0, 10) < date('Y-m-d'));
            if ($due) $pending[$h] = $x;
        }
        uasort($pending, fn($a, $b) => [$a['c']['path'], $a['c']['saved']] <=> [$b['c']['path'], $b['c']['saved']]);

        $save = $db->prepare("INSERT INTO dbx_conflicts (path_hash, path, original_path, folder, request_id, owner_user_id, trip_end, c_rev, o_rev,
                                  c_saved_at, outcome, status, action, detail, first_seen_at, last_seen_at, checked_at, resolved_at)
                              VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                              ON DUPLICATE KEY UPDATE mailed_at = IF(status = 'open', mailed_at, NULL), path = VALUES(path), original_path = VALUES(original_path), folder = VALUES(folder),
                                  request_id = VALUES(request_id), owner_user_id = VALUES(owner_user_id), trip_end = VALUES(trip_end),
                                  c_rev = VALUES(c_rev), o_rev = VALUES(o_rev), c_saved_at = VALUES(c_saved_at), outcome = VALUES(outcome),
                                  status = VALUES(status), action = VALUES(action), detail = VALUES(detail), last_seen_at = VALUES(last_seen_at),
                                  checked_at = VALUES(checked_at), resolved_at = VALUES(resolved_at)");
        // (mailed_at first: MySQL applies the assignments in order, so it still sees the old status — a copy that
        //  comes back after being resolved is mailed again.)
        $merged = [];   // originals rewritten in this run: their other copies wait for the next run (fresh rev)
        foreach ($pending as $h => $x) {
            $c = $x['c'];
            $o = $x['o'];
            if (time() >= $until || ($o && isset($merged[$o['lower']]))) { $sum['left']++; continue; }
            try {
                $res = dbxc_process($token, $c, $o, $live);
            } catch (Throwable $e) {
                $res = ['outcome' => 'error', 'action' => null, 'o_rev' => $o['rev'] ?? null, 'detail' => ['error' => mb_substr($e->getMessage(), 0, 500)]];
                $sum['errors'][] = $c['path'] . ': ' . $e->getMessage();
            }
            if ($res['action'] === 'merged') $merged[$o['lower']] = true;
            $folder = dbxc_folder($c['path']);
            $info = dbxc_folder_info($db, $folder);
            $done = $res['action'] !== null;
            $save->execute([$h, $c['path'], dirname($c['path']) . '/' . (dbxc_parse_name($c['name'])['original'] ?? ''), $folder,
                            $info['request_id'], $info['owner_user_id'], $info['trip_end'], $c['rev'], $res['o_rev'],
                            $c['saved'] ? date('Y-m-d H:i:s', $c['saved']) : null, $res['outcome'], $done ? 'resolved' : 'open', $res['action'],
                            json_encode($res['detail'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                            $rows[$h]['first_seen_at'] ?? $now, $now, $now, $done ? $now : null]);
            $sum['checked']++;
            $sum['outcomes'][$res['outcome']] = ($sum['outcomes'][$res['outcome']] ?? 0) + 1;
            if ($done && $info['request_id']) {
                timeline_log((int)$info['request_id'], 'calc_update', $res['action'] === 'merged'
                    ? 'Conflicted copy merged into ' . basename($o['path']) : 'Conflicted copy removed: ' . $c['name'],
                    ['body' => $res['action'] === 'merged'
                        ? 'Cells copied from the conflicted copy: ' . implode(', ', array_column($res['detail']['cells'], 'cell'))
                        : DBXC_OUTCOMES[$res['outcome']][0] . '. The copy is in the Dropbox trash (Deleted files).']);
            }
        }
        if ($sum['left'] === 0 && $notify) $sum['notified'] = dbxc_notify($db);
        $sum['ok'] = true;
        $sum['done'] = $sum['left'] === 0;
        dbxc_meta_set($db, 'last_run', json_encode(['at' => $now] + $sum, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $sum;
    } finally {
        $db->query("SELECT RELEASE_LOCK('dbxc_run')");
    }
}

/**
 * Nightly entry point (conflicts_cron.php, every 30 min): inside DBXC_NIGHT_FROM–DBXC_NIGHT_TO EAT, until the day's
 * run is complete (each call continues where the last one stopped).
 */
function dbxc_nightly(PDO $db): string {
    dbxc_schema($db);
    $h = (int)date('G');
    if ($h < DBXC_NIGHT_FROM || $h >= DBXC_NIGHT_TO) return 'outside the night window (' . DBXC_NIGHT_FROM . ':00–' . DBXC_NIGHT_TO . ':00 EAT)';
    if (dbxc_meta($db, 'last_done') === date('Y-m-d')) return 'already done today';
    $s = dbxc_run($db);
    if (empty($s['ok'])) return $s['msg'];
    if ($s['done']) dbxc_meta_set($db, 'last_done', date('Y-m-d'));
    $o = [];
    foreach ($s['outcomes'] as $k => $n) $o[] = "$k $n";
    return ($s['live'] ? 'live' : 'dry run') . ": {$s['conflicts']} conflicted copies, {$s['checked']} checked (" . implode(', ', $o) . ")"
         . ", {$s['gone']} gone, {$s['left']} left" . ($s['errors'] ? ', ' . count($s['errors']) . ' errors' : '');
}

// ── Owners: memos + mail ─────────────────────────────────────────────────────

/** Dropbox web link of a folder. */
function dbxc_dropbox_url(string $path): string {
    return 'https://www.dropbox.com/home' . implode('/', array_map('rawurlencode', explode('/', $path)));
}

/** Open copies the owners must fix (trips not ended long ago), grouped [owner_user_id][folder] => rows. */
function dbxc_open_by_owner(PDO $db): array {
    $st = $db->prepare("SELECT * FROM dbx_conflicts WHERE status = 'open' AND outcome NOT IN ('identical','old_version','already_in','merged')
                          AND (trip_end IS NULL OR trip_end >= ?) ORDER BY folder, path");
    $st->execute([date('Y-m-d', strtotime('-' . DBXC_PAST_DAYS . ' days'))]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $uid = (int)($r['owner_user_id'] ?: (ck_user_find($db, CK_OWNER_FALLBACK)['id'] ?? 0));
        if ($uid) $out[$uid][$r['folder']][] = $r;
    }
    return $out;
}

/** What to do with one copy, in plain words (memo and mail). */
function dbxc_explain(array $r): array {
    $d = json_decode((string)$r['detail'], true) ?: [];
    $why = DBXC_OUTCOMES[$r['outcome']][0];
    if ($r['outcome'] === 'conflict' && !empty($d['reason'])) $why .= ' (' . DBXC_REASONS[$d['reason']] . ')';
    if ($r['outcome'] === 'error' && !empty($d['error'])) $why .= ': ' . $d['error'];
    $cells = [];
    foreach ($d['cells'] ?? [] as $x) {
        $cells[] = $x['cell'] . ' — original: ' . $x['original'] . ' / copy: ' . $x['copy'] . ($x['both'] ? '  ← changed in both' : '');
    }
    if (($d['n_cells'] ?? 0) > count($cells)) $cells[] = '… ' . ($d['n_cells'] - count($cells)) . ' more cells';
    return ['why' => $why, 'cells' => $cells];
}

/** Memo body (HTML) of one booking's copies. */
function dbxc_memo_html(string $folder, array $list): string {
    $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $html = '<p>Dropbox kept two versions of these files (Excel open on two PCs). Open the original and the conflicted copy, '
          . 'copy into the original what is only in the copy, save it and delete the copy. This memo closes by itself the night after.</p>';
    foreach ($list as $r) {
        $x = dbxc_explain($r);
        $html .= '<p><b>' . $e(basename($r['path'])) . '</b><br>' . $e($x['why']) . '</p>';
        if ($x['cells']) $html .= '<p>' . implode('<br>', array_map($e, $x['cells'])) . '</p>';
    }
    $html .= '<p><a href="' . $e(dbxc_dropbox_url(CK_BASE . '/' . $folder)) . '">Open the folder in Dropbox</a> · <a href="' . DBXC_PAGE_URL . '">Conflicted copies in the Hub</a></p>';
    return $html;
}

/**
 * Owners' memos and mails (live runs, once the night's check is complete): one memo per owner and booking
 * (ext_key dbxc:<folder hash>, rewritten each night, closed when its copies are gone) and one mail per owner
 * with the copies not mailed yet plus the ones the Hub resolved on their bookings since the last mail.
 */
function dbxc_notify(PDO $db): array {
    memo_schema($db);
    $now = date('Y-m-d H:i:s');
    $groups = dbxc_open_by_owner($db);
    $res = ['memos_open' => 0, 'memos_closed' => 0, 'mails' => 0];

    $memos = [];
    foreach ($db->query("SELECT id, user_id, ext_key FROM memos WHERE source = 'dropbox' AND ext_key LIKE 'dbxc:%'
                         AND deleted_at IS NULL AND status NOT IN ('done','archived')")->fetchAll(PDO::FETCH_ASSOC) as $m) {
        $memos[$m['user_id'] . '|' . $m['ext_key']] = (int)$m['id'];
    }
    $upd = $db->prepare("UPDATE memos SET title = ?, body = ?, request_id = ?, updated_at = ? WHERE id = ?");
    $ins = $db->prepare("INSERT INTO memos (user_id, title, body, type, status, priority, pinned, color, due_date, reminder_at, reminder_emails,
                             reminder_sent, recur_rule, sort_order, request_id, source, ext_key, created_at, updated_at)
                         VALUES (?, ?, ?, 'todo', 'open', 'high', 0, NULL, ?, NULL, NULL, 0, 'none', 0, ?, 'dropbox', ?, ?, ?)");
    $keep = [];
    foreach ($groups as $uid => $folders) {
        foreach ($folders as $folder => $list) {
            $key = 'dbxc:' . substr(sha1(mb_strtolower($folder)), 0, 30);
            $title = 'Conflicted copies in Dropbox: ' . mb_substr($folder, 0, 180);
            $rid = $list[0]['request_id'] ? (int)$list[0]['request_id'] : null;
            $html = dbxc_memo_html($folder, $list);
            if (isset($memos[$uid . '|' . $key])) {
                $upd->execute([$title, $html, $rid, $now, $memos[$uid . '|' . $key]]);
            } else {
                $ins->execute([$uid, $title, $html, date('Y-m-d'), $rid, $key, $now, $now]);
                $memos[$uid . '|' . $key] = (int)$db->lastInsertId();
            }
            $keep[$uid . '|' . $key] = true;
            $res['memos_open']++;
        }
    }
    foreach ($memos as $k => $id) {
        if (isset($keep[$k])) continue;
        memo_close($db, $id, 'The conflicted copies are gone — closed automatically.');
        $res['memos_closed']++;
    }

    // Mail: new copies to fix + what the Hub resolved on the owner's bookings since its last mail.
    $users = ck_users($db);
    $since = dbxc_meta($db, 'last_mail') ?? date('Y-m-d H:i:s', strtotime('-1 day'));
    $st = $db->prepare("SELECT * FROM dbx_conflicts WHERE status = 'resolved' AND resolved_at >= ? AND (trip_end IS NULL OR trip_end >= ?)");
    $st->execute([$since, date('Y-m-d', strtotime('-' . DBXC_PAST_DAYS . ' days'))]);
    $auto = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $auto[(int)$r['owner_user_id']][] = $r;
    $mark = $db->prepare("UPDATE dbx_conflicts SET mailed_at = ? WHERE id = ?");
    $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    foreach (array_unique(array_merge(array_keys($groups), array_keys($auto))) as $uid) {
        $new = [];
        foreach ($groups[$uid] ?? [] as $list) foreach ($list as $r) if (!$r['mailed_at']) $new[] = $r;
        $u = $users[$uid] ?? null;
        if ((!$new && empty($auto[$uid])) || !$u || !filter_var($u['email'], FILTER_VALIDATE_EMAIL)) continue;
        $first = strtok((string)($u['full_name'] ?: $u['username']), ' ');
        $html = '<p>Dear ' . $e($first) . ',</p>';
        $text = "Dear $first,\n\n";
        if ($new) {
            $html .= '<p>Dropbox kept two versions of these files (Excel open on two PCs) and the Hub could not merge them. '
                   . 'Please open the original and the conflicted copy, copy into the original what is only in the copy, save it and delete the copy. '
                   . 'You also have a memo for each booking on your Memo Board.</p>';
            $text .= "Dropbox kept two versions of these files (Excel open on two PCs) and the Hub could not merge them. Please open the original "
                   . "and the conflicted copy, copy into the original what is only in the copy, save it and delete the copy. You also have a memo "
                   . "for each booking on your Memo Board.\n";
            foreach ($new as $r) {
                $x = dbxc_explain($r);
                $html .= '<h3 style="font-size:14px;margin:16px 0 4px">' . $e($r['folder']) . '</h3><p style="margin:0"><b>' . $e(basename($r['path']))
                       . '</b> — ' . $e($x['why']) . ' · <a href="' . $e(dbxc_dropbox_url(dirname($r['path']))) . '">Dropbox</a></p>';
                if ($x['cells']) $html .= '<p style="margin:4px 0;font-size:12px;color:#444">' . implode('<br>', array_map($e, $x['cells'])) . '</p>';
                $text .= "\n" . $r['folder'] . "\n" . basename($r['path']) . ' — ' . $x['why'] . "\n" . ($x['cells'] ? implode("\n", $x['cells']) . "\n" : '');
            }
        }
        if (!empty($auto[$uid])) {
            $html .= '<p>' . ($new ? 'Resolved by the Hub, nothing to do:' : 'The Hub resolved these conflicted copies on your bookings, nothing to do:') . '</p><ul>';
            $text .= "\n" . ($new ? 'Resolved by the Hub, nothing to do:' : 'The Hub resolved these conflicted copies on your bookings, nothing to do:') . "\n";
            foreach ($auto[$uid] as $r) {
                $html .= '<li>' . $e(basename($r['path'])) . ' — ' . $e(DBXC_OUTCOMES[$r['outcome']][0]) . '</li>';
                $text .= '- ' . basename($r['path']) . ' — ' . DBXC_OUTCOMES[$r['outcome']][0] . "\n";
            }
            $html .= '</ul>';
        }
        $html .= '<p><a href="' . DBXC_PAGE_URL . '">Conflicted copies in the Hub</a></p><p>Savannah Explorers Hub</p>';
        $text .= "\nConflicted copies: " . DBXC_PAGE_URL . "\n";
        $n = count($new);
        $subject = $n ? 'Dropbox: ' . $n . ' conflicted cop' . ($n === 1 ? 'y' : 'ies') . ' to fix' : 'Dropbox: conflicted copies resolved by the Hub';
        if (send_hub_email($u['email'], $subject, '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#222;line-height:1.4">' . $html . '</div>',
                           'Savannah Explorers Hub', 'noreply@savannahexplorers.com', '', $text)) {
            foreach ($new as $r) $mark->execute([$now, $r['id']]);
            $res['mails']++;
        }
    }
    dbxc_meta_set($db, 'last_mail', $now);
    return $res;
}

// ── Report (page + Agent API) ────────────────────────────────────────────────

/** Public shape of a dbx_conflicts row. */
function dbxc_row_out(array $r, array $users): array {
    $d = json_decode((string)$r['detail'], true) ?: [];
    $u = $users[(int)$r['owner_user_id']] ?? null;
    return [
        'id' => (int)$r['id'], 'path' => $r['path'], 'file' => basename($r['path']), 'original' => basename($r['original_path']),
        'folder' => $r['folder'], 'request_id' => $r['request_id'] ? (int)$r['request_id'] : null,
        'owner' => $u ? ($u['full_name'] ?: $u['username']) : null, 'owner_user_id' => $r['owner_user_id'] ? (int)$r['owner_user_id'] : null,
        'trip_end' => $r['trip_end'], 'saved_at' => $r['c_saved_at'], 'outcome' => $r['outcome'], 'outcome_label' => DBXC_OUTCOMES[$r['outcome']][0] ?? $r['outcome'],
        'auto' => DBXC_OUTCOMES[$r['outcome']][1] ?? false, 'status' => $r['status'], 'action' => $r['action'],
        'reason' => isset($d['reason']) ? (DBXC_REASONS[$d['reason']] ?? $d['reason']) : null, 'error' => $d['error'] ?? null,
        'base_saved' => $d['base_saved'] ?? null, 'n_cells' => $d['n_cells'] ?? null, 'cells' => $d['cells'] ?? [],
        'first_seen_at' => $r['first_seen_at'], 'checked_at' => $r['checked_at'], 'resolved_at' => $r['resolved_at'], 'mailed_at' => $r['mailed_at'],
        'dropbox_url' => dbxc_dropbox_url(dirname($r['path'])),
    ];
}

/**
 * Open copies (to fix, or planned when not live) and the ones resolved / gone in the last $days days. $ownerId:
 * only that owner's. → ['live', 'last_run', 'last_done', 'open' => [...], 'closed' => [...]]
 */
function dbxc_report(PDO $db, ?int $ownerId = null, int $days = 30): array {
    dbxc_schema($db);
    $users = ck_users($db);
    $w = $ownerId ? ' AND owner_user_id = ' . (int)$ownerId : '';
    $open = $db->query("SELECT * FROM dbx_conflicts WHERE status = 'open'$w ORDER BY folder, path")->fetchAll(PDO::FETCH_ASSOC);
    $st = $db->prepare("SELECT * FROM dbx_conflicts WHERE status <> 'open' AND resolved_at >= ?$w ORDER BY resolved_at DESC LIMIT 300");
    $st->execute([date('Y-m-d', strtotime("-$days days"))]);
    return [
        'live' => dbxc_live(),
        'last_run' => json_decode((string)dbxc_meta($db, 'last_run'), true),
        'last_done' => dbxc_meta($db, 'last_done'),
        'open' => array_map(fn($r) => dbxc_row_out($r, $users), $open),
        'closed' => array_map(fn($r) => dbxc_row_out($r, $users), $st->fetchAll(PDO::FETCH_ASSOC)),
    ];
}
