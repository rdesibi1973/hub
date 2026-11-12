<?php
/**
 * drafts_service.php — draft .eml files out of the booking folders' mails/ into old/ (Roberto, 12 Nov 2026).
 * Cowork keeps a copy of every draft mail it writes (.eml with the header "X-Unsent: 1"); mails/ must hold only real mails
 * (received / sent, supplier proofs saved by staff_mail_save_eml / "Save as proof"), so drafts go to the folder's old/.
 *
 *   draft = extension .eml AND "X-Unsent: 1" in the header block (before the first empty line, first 64 KB read with a
 *           Range request — bodies are never read). Never decided by the file name; other files are never touched.
 *   drafts_cleanup()  one-off scan + move (Agent API drafts_cleanup, BackOffice page drafts_cleanup.php): dry run unless
 *           confirm; batches with offset / next_offset (Dropbox rate limits, PHP time); moves to <folder>/old/<same name>,
 *           "(2)", "(3)"… on a clash; never deletes; a timeline note on the linked request.
 *   drafts_filter_real() readers (Booking follow-up "Set expiry" proof list / proof check) skip drafts.
 * Folders: /001_Safari (its 00_YYYY / 00_CANCELED containers included) and the inquiry years /YYYY (last, this, next);
 * GRP / group folders: their member sub-folders' mails/ too.
 */
require_once __DIR__ . '/folder_parser.php';

const DRAFTS_HEAD_BYTES = 65536;
const DRAFTS_DIR = 'mails';
const DRAFTS_TO  = 'old';
const DRAFTS_BUDGET = 40;          // seconds of scanning per call (the rest: next_offset)

// ── What a draft is ──────────────────────────────────────────────────────────

/** The header block of a mail: everything before the first empty line, at most DRAFTS_HEAD_BYTES. */
function drafts_header_block(string $bytes): string {
    $bytes = substr($bytes, 0, DRAFTS_HEAD_BYTES);
    return preg_match('/\r?\n\r?\n/', $bytes, $m, PREG_OFFSET_CAPTURE) ? substr($bytes, 0, $m[0][1]) : $bytes;
}

/** "X-Unsent: 1" in the header block (case-insensitive, spaces tolerated). */
function drafts_is_draft_head(string $bytes): bool {
    return (bool)preg_match('/^X-Unsent[ \t]*:[ \t]*1[ \t]*\r?$/mi', drafts_header_block($bytes));   // CRLF files too
}

function drafts_is_eml(string $name): bool {
    return (bool)preg_match('/\.eml$/i', $name);
}

/** "x.eml" → "x (2).eml", "x (3).eml" … the first name not in $taken (lower-case names). */
function drafts_free_name(string $name, array $taken): string {
    $set = array_flip(array_map('strtolower', $taken));
    if (!isset($set[strtolower($name)])) return $name;
    $base = preg_replace('/\.eml$/i', '', $name);
    $ext = substr($name, strlen($base));
    for ($i = 2; $i < 1000; $i++) {
        $n = $base . ' (' . $i . ')' . $ext;
        if (!isset($set[strtolower($n)])) return $n;
    }
    return $base . ' (' . uniqid() . ')' . $ext;
}

// ── Dropbox (default implementations; tests pass their own in $o['dbx']) ─────

/** One level of a folder: ['folders' => [names], 'files' => [{name, size, modified}]], or null if it does not exist. */
function drafts_dbx_list(string $token, string $path): ?array {
    $out = ['folders' => [], 'files' => []];
    $cursor = null;
    do {
        $ch = curl_init('https://api.dropboxapi.com/2/files/list_folder' . ($cursor ? '/continue' : ''));
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($cursor ? ['cursor' => $cursor] : ['path' => $path, 'recursive' => false, 'limit' => 2000])]);
        $body = (string)curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code === 409 && stripos($body, 'not_found') !== false) return null;
        if ($code === 429) { sleep(2); continue; }
        if ($code !== 200) throw new RuntimeException("Dropbox list_folder $path failed (HTTP $code): " . substr($body, 0, 300));
        $d = json_decode($body, true) ?: [];
        foreach ($d['entries'] ?? [] as $e) {
            if (($e['.tag'] ?? '') === 'folder') $out['folders'][] = (string)$e['name'];
            elseif (($e['.tag'] ?? '') === 'file') $out['files'][] = ['name' => (string)$e['name'], 'size' => (int)($e['size'] ?? 0),
                                                                      'modified' => (string)($e['server_modified'] ?? '')];
        }
        $cursor = !empty($d['has_more']) ? ($d['cursor'] ?? null) : null;
    } while ($cursor);
    return $out;
}

/**
 * drafts_dbx_list() of many folders at once (parallel list_folder): [path => listing | null]. A paged answer (has_more),
 * a rate limit (429) or any other error falls back to drafts_dbx_list() for that folder.
 */
function drafts_dbx_list_multi(string $token, array $paths, int $concurrency = 8): array {
    $out = [];
    $queue = array_values(array_unique($paths));
    $mh = curl_multi_init();
    $live = []; $retry = [];
    $start = function (string $p) use ($token, $mh, &$live) {
        $ch = curl_init('https://api.dropboxapi.com/2/files/list_folder');
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode(['path' => $p, 'recursive' => false, 'limit' => 2000])]);
        curl_multi_add_handle($mh, $ch);
        $live[(int)$ch] = [$ch, $p];
    };
    while ($queue && count($live) < $concurrency) $start(array_shift($queue));
    do {
        curl_multi_exec($mh, $running);
        curl_multi_select($mh, 1.0);
        while ($info = curl_multi_info_read($mh)) {
            [$ch, $p] = $live[(int)$info['handle']];
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $body = (string)curl_multi_getcontent($ch);
            $d = $code === 200 ? (json_decode($body, true) ?: null) : null;
            if ($code === 409 && stripos($body, 'not_found') !== false) $out[$p] = null;
            elseif ($d === null || !empty($d['has_more'])) $retry[] = $p;
            else {
                $l = ['folders' => [], 'files' => []];
                foreach ($d['entries'] ?? [] as $e) {
                    if (($e['.tag'] ?? '') === 'folder') $l['folders'][] = (string)$e['name'];
                    elseif (($e['.tag'] ?? '') === 'file') $l['files'][] = ['name' => (string)$e['name'], 'size' => (int)($e['size'] ?? 0),
                                                                           'modified' => (string)($e['server_modified'] ?? '')];
                }
                $out[$p] = $l;
            }
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
            unset($live[(int)$info['handle']]);
            if ($queue) $start(array_shift($queue));
        }
    } while ($live);
    curl_multi_close($mh);
    foreach ($retry as $p) $out[$p] = drafts_dbx_list($token, $p);
    return $out;
}

/** The first DRAFTS_HEAD_BYTES of one file (Range request), retried on 429 / 5xx (Retry-After, max 3 tries): bytes | null. */
function drafts_dbx_head_one(string $token, string $p): ?string {
    for ($try = 0; $try < 3; $try++) {
        $ch = curl_init('https://content.dropboxapi.com/2/files/download');
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_POSTFIELDS => '', CURLOPT_HEADER => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Dropbox-API-Arg: ' . json_encode(['path' => $p]),
                                   'Range: bytes=0-' . (DRAFTS_HEAD_BYTES - 1), 'Content-Type: text/plain']]);
        $res = (string)curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        if ($code === 200 || $code === 206) return substr($res, $hs);
        if ($code === 409) return null;                                   // gone / not a file
        $wait = preg_match('/^Retry-After:\s*(\d+)/mi', substr($res, 0, $hs), $m) ? min(5, (int)$m[1]) : 1 + $try;
        sleep(max(1, $wait));
    }
    return null;
}

/**
 * The first DRAFTS_HEAD_BYTES of many files (Range requests, a few in parallel): [path => bytes | null]. Dropbox throttles
 * parallel downloads (seen 12 Nov 2026: 4 of 10 refused in parallel, all fine one by one): failures are read again one by one.
 */
function drafts_dbx_heads(string $token, array $paths, int $concurrency = 3): array {
    $out = [];
    $queue = array_values(array_unique($paths));
    $mh = curl_multi_init();
    $live = [];
    $start = function (string $p) use ($token, $mh, &$live) {
        $ch = curl_init('https://content.dropboxapi.com/2/files/download');
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_POSTFIELDS => '',
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Dropbox-API-Arg: ' . json_encode(['path' => $p]),
                                   'Range: bytes=0-' . (DRAFTS_HEAD_BYTES - 1), 'Content-Type: text/plain']]);
        curl_multi_add_handle($mh, $ch);
        $live[(int)$ch] = [$ch, $p];
    };
    while ($queue && count($live) < $concurrency) $start(array_shift($queue));
    do {
        curl_multi_exec($mh, $running);
        curl_multi_select($mh, 1.0);
        while ($info = curl_multi_info_read($mh)) {
            [$ch, $p] = $live[(int)$info['handle']];
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $out[$p] = ($code === 200 || $code === 206) ? (string)curl_multi_getcontent($ch) : null;
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
            unset($live[(int)$info['handle']]);
            if ($queue) $start(array_shift($queue));
        }
    } while ($live);
    curl_multi_close($mh);
    foreach ($out as $p => $v) if ($v === null) $out[$p] = drafts_dbx_head_one($token, $p);   // throttled: one by one, with back-off
    return $out;
}

/** Move one file (no overwrite): 'moved' | 'exists' | 'src_missing'. Missing parent folders (old/) are created by Dropbox. */
function drafts_dbx_move(string $token, string $from, string $to): string {
    for ($try = 0; $try < 3; $try++) {
        $ch = curl_init('https://api.dropboxapi.com/2/files/move_v2');
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode(['from_path' => $from, 'to_path' => $to, 'autorename' => false])]);
        $body = (string)curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code === 200) return 'moved';
        if ($code === 429) { sleep(2); continue; }
        if ($code === 409 && stripos($body, 'to/conflict') !== false) return 'exists';
        if ($code === 409 && stripos($body, 'from_lookup/not_found') !== false) return 'src_missing';
        throw new RuntimeException("Dropbox move_v2 failed (HTTP $code): " . substr($body, 0, 300));
    }
    throw new RuntimeException('Dropbox move_v2: too many requests, try again later');
}

/** ['list' => fn(path): ?array, 'heads' => fn(paths): array, 'move' => fn(from, to): string] on the real Dropbox. */
function drafts_dbx_default(): array {
    require_once __DIR__ . '/../dropbox_helper.php';
    $token = null;
    $tok = function () use (&$token) { return $token ?? ($token = dropbox_get_access_token()); };
    return ['list'  => fn(string $p) => drafts_dbx_list($tok(), $p),
            'list_multi' => fn(array $ps) => drafts_dbx_list_multi($tok(), $ps),
            'heads' => fn(array $ps) => drafts_dbx_heads($tok(), $ps),
            'move'  => fn(string $f, string $t) => drafts_dbx_move($tok(), $f, $t)];
}

// ── Readers: keep real mails only ────────────────────────────────────────────

/**
 * Of the files of a mails/ folder ({name, …}), the .eml that are not drafts (other files as they are).
 * $heads: fn(array paths) → [path => bytes|null] (default Dropbox). An unreadable head counts as a real mail (never hidden by mistake).
 */
function drafts_filter_real(string $mailsDir, array $files, ?callable $heads = null): array {
    $eml = array_values(array_filter($files, fn($f) => drafts_is_eml((string)$f['name'])));
    if (!$eml) return $files;
    $heads = $heads ?? drafts_dbx_default()['heads'];
    $got = $heads(array_map(fn($f) => rtrim($mailsDir, '/') . '/' . $f['name'], $eml));
    return array_values(array_filter($files, function ($f) use ($got, $mailsDir) {
        if (!drafts_is_eml((string)$f['name'])) return true;
        $h = $got[rtrim($mailsDir, '/') . '/' . $f['name']] ?? null;
        return $h === null || !drafts_is_draft_head($h);
    }));
}

/** Is this file (full Dropbox path) a draft? Unreadable → false. */
function drafts_path_is_draft(string $path, ?callable $heads = null): bool {
    if (!drafts_is_eml($path)) return false;
    $heads = $heads ?? drafts_dbx_default()['heads'];
    $h = $heads([$path])[$path] ?? null;
    return $h !== null && drafts_is_draft_head($h);
}

// ── One-off scan + move ──────────────────────────────────────────────────────

/** The roots of a scope: safari → /001_Safari, requests → /<last year>, /<this year>, /<next year>, all → both. */
function drafts_roots(string $scope, ?int $year = null): array {
    $y = $year ?? (int)date('Y');
    $req = ['/' . ($y - 1), '/' . $y, '/' . ($y + 1)];
    if ($scope === 'safari') return ['/001_Safari'];
    if ($scope === 'requests') return $req;
    return array_merge(['/001_Safari'], $req);
}

/**
 * The booking folders under the roots, sorted (stable offsets): every child of a root, the children of its containers
 * (00_2026, 00_2027, 00_CANCELED… — names starting "00_") instead of the container itself. A missing root is skipped.
 */
function drafts_folders(array $dbx, array $roots): array {
    $out = [];
    foreach ($roots as $root) {
        $l = $dbx['list']($root);
        if ($l === null) continue;
        foreach ($l['folders'] as $f) {
            if (preg_match('/^00_/', $f)) {
                $c = $dbx['list']($root . '/' . $f);
                foreach ($c['folders'] ?? [] as $g) $out[] = $root . '/' . $f . '/' . $g;
            } else {
                $out[] = $root . '/' . $f;
            }
        }
    }
    sort($out, SORT_STRING | SORT_FLAG_CASE);
    return array_values(array_unique($out));
}

/** The request linked to a folder (practice_code = folder name, status tags ignored), or null. $map from drafts_request_map(). */
function drafts_request_of(string $folder, array $map): ?int {
    return $map[dropbox_folder_stem_lc(basename($folder))] ?? null;
}

function dropbox_folder_stem_lc(string $name): string {
    require_once __DIR__ . '/../dropbox_helper.php';
    return mb_strtolower(dropbox_folder_stem($name));
}

/** [folder stem (lower) => request id] of every request with a folder (the newest request wins). */
function drafts_request_map(PDO $db): array {
    $m = [];
    foreach ($db->query("SELECT id, practice_code FROM requests WHERE practice_code IS NOT NULL AND practice_code <> '' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $m[dropbox_folder_stem_lc((string)$r['practice_code'])] = (int)$r['id'];
    }
    return $m;
}

/**
 * Scan (and with confirm, move) the drafts of the booking folders.
 * $o: scope safari|requests|all (default all), request_id, limit_folders, offset, confirm, budget (seconds), dbx (tests),
 *     folder_of_request (fn(int): ?string, tests), year (tests).
 * → ['dry_run', 'scope', 'folders_total', 'offset', 'next_offset' (null = done), 'folders_scanned', 'folders_with_mails',
 *    'drafts_found', 'folders_affected', 'moved', 'results' => [{folder, request_id, drafts[], kept[], moves[]?, errors[]?}], 'errors']
 */
function drafts_cleanup(PDO $db, array $o = []): array {
    $confirm = !empty($o['confirm']);
    $scope = in_array($o['scope'] ?? 'all', ['safari', 'requests', 'all'], true) ? ($o['scope'] ?? 'all') : 'all';
    $dbx = $o['dbx'] ?? drafts_dbx_default();
    $deadline = microtime(true) + (float)($o['budget'] ?? DRAFTS_BUDGET);
    $map = drafts_request_map($db);
    $rid = (int)($o['request_id'] ?? 0);
    if ($rid > 0) {
        $dir = ($o['folder_of_request'] ?? 'drafts_request_folder')($rid);
        if ($dir === null) throw new InvalidArgumentException('Request ' . $rid . ' has no booking folder in Dropbox');
        $folders = [$dir];
        $scope = 'request';
    } else {
        $folders = drafts_folders($dbx, drafts_roots($scope, $o['year'] ?? null));
    }
    $total = count($folders);
    $offset = max(0, (int)($o['offset'] ?? 0));
    $limit = (int)($o['limit_folders'] ?? 0);
    $slice = array_slice($folders, $offset, $limit > 0 ? $limit : null);
    $out = ['dry_run' => !$confirm, 'scope' => $scope, 'folders_total' => $total, 'offset' => $offset, 'next_offset' => null,
            'folders_scanned' => 0, 'folders_with_mails' => 0, 'drafts_found' => 0, 'folders_affected' => 0, 'moved' => 0, 'unreadable' => 0,
            'results' => [], 'errors' => []];
    // Chunks of folders, each step in one parallel round: the folders, their group members, their mails/, the headers.
    $multi = $dbx['list_multi'] ?? function (array $ps) use ($dbx) { $o = []; foreach ($ps as $p) $o[$p] = $dbx['list']($p); return $o; };
    $hasMails = fn(?array $l) => $l && in_array(DRAFTS_DIR, array_map('strtolower', $l['folders']), true);
    $mailsOf = function (string $folder, array $l) { foreach ($l['folders'] as $f) if (strtolower($f) === DRAFTS_DIR) return $folder . '/' . $f; return $folder . '/' . DRAFTS_DIR; };
    $chunkSize = max(1, (int)($o['chunk'] ?? 16));
    for ($i = 0; $i < count($slice); $i += $chunkSize) {
        if ($i > 0 && microtime(true) > $deadline) { $out['next_offset'] = $offset + $i; break; }
        $chunk = array_slice($slice, $i, $chunkSize);
        try {
            $L = $multi($chunk);
            $out['folders_scanned'] += count($chunk);
            $dirs = []; $members = [];
            foreach ($chunk as $unit) {
                $l = $L[$unit] ?? null;
                if ($l === null) continue;
                if ($hasMails($l)) $dirs[$unit] = $l;
                foreach ($l['folders'] as $sub) if (folder_is_booking_leaf($sub)) $members[] = $unit . '/' . $sub;   // group members
            }
            if ($members) foreach ($multi($members) as $p => $ml) if ($hasMails($ml)) $dirs[$p] = $ml;
            if (!$dirs) continue;
            $mailsPaths = [];
            foreach ($dirs as $folder => $l) $mailsPaths[$folder] = $mailsOf($folder, $l);
            $ML = $multi(array_values($mailsPaths));
            $emlPaths = [];
            foreach ($mailsPaths as $folder => $mp) foreach ($ML[$mp]['files'] ?? [] as $f) if (drafts_is_eml($f['name'])) $emlPaths[] = $mp . '/' . $f['name'];
            $H = $emlPaths ? $dbx['heads']($emlPaths) : [];
            foreach ($dirs as $folder => $l) {
                $out['folders_with_mails']++;
                try {
                    $r = drafts_folder($dbx, $folder, $l, $confirm, $map, $ML[$mailsPaths[$folder]] ?? ['folders' => [], 'files' => []], $H);
                } catch (Throwable $e) {
                    $out['errors'][] = ['folder' => $folder, 'error' => $e->getMessage()];
                    continue;
                }
                $out['drafts_found'] += count($r['drafts']);
                $out['unreadable'] += count($r['unreadable'] ?? []);
                if ($r['drafts']) {
                    $out['folders_affected']++;
                    $out['moved'] += count(array_filter($r['moves'] ?? [], fn($m) => $m['result'] === 'moved'));
                }
                if ($r['drafts'] || !empty($r['unreadable'])) $out['results'][] = $r;   // unreadable: run again later
            }
        } catch (Throwable $e) {
            $out['errors'][] = ['folder' => $chunk[0] . ' … (' . count($chunk) . ' folders)', 'error' => $e->getMessage()];
        }
    }
    if ($out['next_offset'] === null && $limit > 0 && $offset + $limit < $total) $out['next_offset'] = $offset + $limit;
    return $out;
}

/** The booking folder of a request in Dropbox (its stored path, else a search by name), or null. */
function drafts_request_folder(int $rid): ?string {
    require_once __DIR__ . '/booking_service.php';
    require_once __DIR__ . '/../dropbox_helper.php';
    $st = db()->prepare("SELECT * FROM requests WHERE id = ?");
    $st->execute([$rid]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r || trim((string)$r['practice_code']) === '') return null;
    $token = dropbox_get_access_token();
    $dir = req_folder_path($r);
    if ($dir !== '' && dropbox_path_exists($token, $dir)) return $dir;
    return dropbox_find_folder($token, $r['practice_code']) ?: null;
}

/**
 * One booking folder: classify mails/*.eml, and with $confirm move the drafts to old/ (free name on a clash) and note it
 * on the linked request's timeline. → {folder, request_id, drafts[], kept[], moves[] (confirm), errors[]}
 */
function drafts_folder(array $dbx, string $folder, array $listing, bool $confirm, array $map, ?array $ml = null, ?array $heads = null): array {
    $mailsName = DRAFTS_DIR;
    foreach ($listing['folders'] as $f) if (strtolower($f) === DRAFTS_DIR) { $mailsName = $f; break; }
    $mails = $folder . '/' . $mailsName;
    $ml = $ml ?? ($dbx['list']($mails) ?? ['folders' => [], 'files' => []]);   // given by drafts_cleanup() (parallel), else read here
    $eml = array_values(array_filter($ml['files'], fn($f) => drafts_is_eml($f['name'])));
    if ($heads === null) $heads = $eml ? $dbx['heads'](array_map(fn($f) => $mails . '/' . $f['name'], $eml)) : [];
    // A header not read (Dropbox rate limit, timeout): read again once; still nothing → "unreadable" (left in mails/, reported).
    $miss = array_values(array_filter(array_map(fn($f) => $mails . '/' . $f['name'], $eml), fn($p) => !isset($heads[$p])));
    if ($miss) $heads = $dbx['heads']($miss) + $heads;
    $drafts = []; $kept = []; $unreadable = [];
    foreach ($eml as $f) {
        $h = $heads[$mails . '/' . $f['name']] ?? null;
        if ($h === null) $unreadable[] = $f['name'];
        elseif (drafts_is_draft_head($h)) $drafts[] = $f['name'];
        else $kept[] = $f['name'];
    }
    $r = ['folder' => $folder, 'request_id' => drafts_request_of($folder, $map), 'drafts' => $drafts, 'kept' => $kept] + ($unreadable ? ['unreadable' => $unreadable] : []);
    if (!$confirm || !$drafts) return $r;
    $oldName = DRAFTS_TO;
    foreach ($listing['folders'] as $f) if (strtolower($f) === DRAFTS_TO) { $oldName = $f; break; }
    $old = $folder . '/' . $oldName;
    $taken = array_map(fn($f) => $f['name'], ($dbx['list']($old) ?? ['files' => []])['files']);
    $r['moves'] = []; $r['errors'] = [];
    foreach ($drafts as $name) {
        $to = drafts_free_name($name, $taken);
        try {
            for ($try = 0; $try < 5; $try++) {
                $res = $dbx['move']($mails . '/' . $name, $old . '/' . $to);
                if ($res !== 'exists') break;
                $taken[] = $to;
                $to = drafts_free_name($name, $taken);
            }
            $taken[] = $to;
            $r['moves'][] = ['from' => DRAFTS_DIR . '/' . $name, 'to' => DRAFTS_TO . '/' . $to, 'result' => $res];
        } catch (Throwable $e) {
            $r['errors'][] = ['file' => $name, 'error' => $e->getMessage()];
        }
    }
    $n = count(array_filter($r['moves'], fn($m) => $m['result'] === 'moved'));
    if ($n && $r['request_id'] && function_exists('db')) {
        require_once __DIR__ . '/timeline_service.php';
        timeline_log($r['request_id'], 'note', $n . ' draft mail' . ($n === 1 ? '' : 's') . ' moved from mails/ to old/',
                     ['body' => implode("\n", array_map(fn($m) => $m['from'] . ' → ' . $m['to'], array_filter($r['moves'], fn($m) => $m['result'] === 'moved')))]);
    }
    return $r;
}

/** A page run in agent_audit_log too (the Agent API logs its own calls). Never throws. */
function drafts_audit(PDO $db, array $user, array $params, array $result): void {
    try {
        $db->prepare("INSERT INTO agent_audit_log (ts, action, request_id, user_id, http_code, dry_run, payload_json, result_json, ip)
                      VALUES (?,?,?,?,?,?,?,?,?)")
           ->execute([date('Y-m-d H:i:s'), 'drafts_cleanup', !empty($params['request_id']) ? (int)$params['request_id'] : null, (int)($user['id'] ?? 0) ?: null,
                      200, empty($params['confirm']) ? 1 : 0, json_encode($params + ['via' => 'page'], JSON_UNESCAPED_UNICODE),
                      substr(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 200000), $_SERVER['REMOTE_ADDR'] ?? null]);
    } catch (Throwable $e) {
        error_log('drafts_audit: ' . $e->getMessage());
    }
}
