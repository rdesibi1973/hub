<?php
/**
 * Draft .eml out of mails/ into old/ (handoff 12 Nov 2026; includes/drafts_service.php). Local checks, no Dropbox: an
 * in-memory folder tree behind the same callables as Dropbox (list / heads / move), requests in SQLite.
 *   C:/Utility/PHP/php.exe -d extension_dir=C:/Utility/PHP/ext -d extension=pdo_sqlite -d extension=mbstring tools/tests/drafts_cleanup_test.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../../modules/leads/includes/drafts_service.php';
require_once __DIR__ . '/../../modules/operations/includes/followup_service.php';

$fail = 0;
$t = function (string $name, bool $ok, $got = null) use (&$fail) {
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $got === null ? '' : '  got: ' . json_encode($got, JSON_UNESCAPED_SLASHES)) . "\n";
    if (!$ok) $fail++;
};

// ── Classification ──
$draft = "X-Unsent: 1\r\nTo: res@uzuri.example\r\nSubject: Remco1108 Uzuri e posto TL\r\n\r\nbody X-Unsent: 1\r\n";
$t('X-Unsent: 1 → draft', drafts_is_draft_head($draft));
$t('spaces / case tolerated', drafts_is_draft_head("Subject: a\nx-unsent :   1 \n\nbody"));
$t('only in the body → not a draft', !drafts_is_draft_head("Subject: a\r\n\r\nX-Unsent: 1\r\n"));
$t('X-Unsent: 0 → not a draft', !drafts_is_draft_head("X-Unsent: 0\r\n\r\n"));
$t('free name (2), (3)', drafts_free_name('a.eml', ['A.eml']) === 'a (2).eml' && drafts_free_name('a.eml', ['a.eml', 'a (2).eml']) === 'a (3).eml');

// ── In-memory Dropbox ──
$fs = [];   // full path => content (files); folders implied by the paths, plus empty ones in $dirs
$dirs = [];
$put = function (string $p, string $c) use (&$fs) { $fs[$p] = $c; };
$moves = 0;
$dbx = [
    'list' => function (string $p) use (&$fs, &$dirs) {
        $p = rtrim($p, '/'); $pre = strtolower($p) . '/';
        $folders = []; $files = []; $seen = false;
        foreach (array_merge(array_keys($fs), array_keys($dirs)) as $k) {
            if (strpos(strtolower($k), $pre) !== 0) continue;
            $seen = true;
            $rest = substr($k, strlen($pre));
            if (strpos($rest, '/') !== false) $folders[explode('/', $rest)[0]] = true;
            elseif (isset($fs[$k])) $files[] = ['name' => $rest, 'size' => strlen($fs[$k]), 'modified' => '2026-11-12T06:00:00Z'];
            else $folders[$rest] = true;
        }
        if (!$seen && !isset($dirs[$p])) return null;
        return ['folders' => array_keys($folders), 'files' => $files];
    },
    'heads' => function (array $ps) use (&$fs) { $o = []; foreach ($ps as $p) $o[$p] = isset($fs[$p]) ? substr($fs[$p], 0, DRAFTS_HEAD_BYTES) : null; return $o; },
    'move' => function (string $from, string $to) use (&$fs, &$moves) {
        if (!isset($fs[$from])) return 'src_missing';
        foreach (array_keys($fs) as $k) if (strcasecmp($k, $to) === 0) return 'exists';
        $fs[$to] = $fs[$from]; unset($fs[$from]); $moves++;
        return 'moved';
    },
];

$R = '/001_Safari/00_2027/08_10AUG_Remco1108(ABC-Lydia)_BALANCE';   // REMCO1108, request 2882
$real = "From: Uzuri Reservations <res@uzuri.example>\r\nDate: Thu, 22 Oct 2026 10:15:00 +0300\r\nSubject: RE: P247/P248 hold\r\n\r\nWe hold until 30/11.\r\n";
$put("$R/mails/2026-10-22_Uzuri_P247-P248 extension to 30-11.eml", $real);
$put("$R/mails/2026-10-20_Glady_Remco1108 Uzuri e posto TL.eml", $draft);
$put("$R/mails/2026-10-25_Remco1108 rooming list.eml", "Date: Sun, 25 Oct 2026\r\nX-Unsent:1\r\n\r\nx");
$put("$R/mails/2026-10-28_DRAFT reply to Uzuri.eml", "From: glady@savannahexplorers.com\r\nSubject: draft-looking name\r\n\r\nsent mail");   // named like a draft, no header
$put("$R/mails/notes.txt", "X-Unsent: 1\r\n\r\n");   // not .eml: never touched
$put("$R/old/2026-10-20_Glady_Remco1108 Uzuri e posto TL.eml", "older copy");          // name clash in old/
$put("$R/invoices/Uzuri proforma.pdf", '%PDF');
$put('/001_Safari/09_01SEP_Rossi(XYZ-Anderson)/mails/a.eml', $draft);
$put('/001_Safari/09_01SEP_Rossi(XYZ-Anderson)/invoices/x.pdf', '%PDF');
$put('/001_Safari/GRP_10OCT_Panorama/11_10OCT_Bianchi(X-Y)/mails/m.eml', $draft);           // group member
$put('/001_Safari/GRP_10OCT_Panorama/RECAP.xlsx', 'x');
$put('/001_Safari/00_CANCELED/07_01JUL_Verdi(X-Y)_CANCELLED/mails/c.eml', $draft);
$put('/2026/Neri(ABC-Roberto)/mails/n.eml', $real);
$put('/2026/Neri(ABC-Roberto)/mails/d.eml', $draft);
$dirs['/2027'] = true;

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec("CREATE TABLE requests (id INTEGER PRIMARY KEY, practice_code TEXT)");
$db->exec("INSERT INTO requests VALUES (2882, '08_10AUG_Remco1108(ABC-Lydia)_DEPOSIT')");   // old status tag: matched by stem
$o = ['dbx' => $dbx, 'year' => 2026, 'folder_of_request' => fn(int $id) => $id === 2882 ? $R : null];

// 1. REMCO1108 dry run
$snap = $fs;
$r = drafts_cleanup($db, $o + ['request_id' => 2882]);
$res = $r['results'][0] ?? [];
sort($res['drafts']);
$t('1. dry run lists the drafts of mails/', ($res['drafts'] ?? null) === ['2026-10-20_Glady_Remco1108 Uzuri e posto TL.eml', '2026-10-25_Remco1108 rooming list.eml'], $res);
$t('1. keeps the Uzuri mail (no X-Unsent)', in_array('2026-10-22_Uzuri_P247-P248 extension to 30-11.eml', $res['kept'], true));
$t('1. linked to request 2882 (stem)', ($res['request_id'] ?? null) === 2882);
$t('1. dry run moves nothing', $fs === $snap && $r['dry_run'] === true && $r['moved'] === 0);

// 2. Confirm on 2882 only
$r = drafts_cleanup($db, $o + ['request_id' => 2882, 'confirm' => true]);
$t('2. two drafts moved', $r['moved'] === 2 && $moves === 2, $r['results'][0]['moves'] ?? $r);
$left = array_map('basename', array_filter(array_keys($fs), fn($k) => strpos($k, "$R/mails/") === 0));
sort($left);
$t('2. mails/ holds only real mails (+ the non-.eml)', $left === ['2026-10-22_Uzuri_P247-P248 extension to 30-11.eml', '2026-10-28_DRAFT reply to Uzuri.eml', 'notes.txt'], $left);
$t('2. drafts in old/', isset($fs["$R/old/2026-10-25_Remco1108 rooming list.eml"]));
$t('2. other bookings untouched', isset($fs['/001_Safari/09_01SEP_Rossi(XYZ-Anderson)/mails/a.eml']));

// 3. Name clash in old/ → (2), nothing overwritten
$t('3. clash → "(2)" suffix', isset($fs["$R/old/2026-10-20_Glady_Remco1108 Uzuri e posto TL (2).eml"]) && $fs["$R/old/2026-10-20_Glady_Remco1108 Uzuri e posto TL.eml"] === 'older copy');

// 4. Named like a draft, no header → untouched
$t('4. "DRAFT" in the name but no X-Unsent → stays', isset($fs["$R/mails/2026-10-28_DRAFT reply to Uzuri.eml"]) && isset($fs["$R/mails/notes.txt"]));

// Set expiry dropdown: only real mails (a draft put back in mails/ is hidden too)
$put("$R/mails/2026-11-01_new draft.eml", $draft);
$line = ['id' => 1, 'dedupe_key' => 'S|2882|P247', 'type' => 'supplier', 'folder' => substr($R, strlen('/001_Safari/')), 'issue' => 'provisional_expired', 'expiry_date' => '2026-10-30',
         'expiry_override' => null, 'closed_at' => null, 'sources' => null];
$glady = ['id' => 7, 'role_name' => 'booking', 'permissions' => [FU_EDIT_PERMISSION], 'followup_admin' => false];
$mailsFiles = $dbx['list']("$R/mails")['files'];
$realOnly = array_map(fn($f) => $f['name'], drafts_filter_real("$R/mails", array_values(array_filter($mailsFiles, fn($f) => drafts_is_eml($f['name']))), $dbx['heads']));
sort($realOnly);
$t('Set expiry list: drafts hidden', $realOnly === ['2026-10-22_Uzuri_P247-P248 extension to 30-11.eml', '2026-10-28_DRAFT reply to Uzuri.eml'], $realOnly);
$chk = followup_override_change($line + ['staff_status' => 'open'], ['expiry_override' => '2026-11-30', 'expiry_override_proof' => 'mails/2026-11-01_new draft.eml'], $glady,
                                ['dir' => $R, 'today' => '2026-11-12', 'proof_exists' => fn() => true, 'heads' => $dbx['heads']]);
$t('a draft is refused as proof', !$chk['ok'] && $chk['code'] === 400 && stripos($chk['error'], 'draft') !== false, $chk);
$chk = followup_override_change($line + ['staff_status' => 'open'], ['expiry_override' => '2026-11-30', 'expiry_override_proof' => 'mails/2026-10-22_Uzuri_P247-P248 extension to 30-11.eml'], $glady,
                                ['dir' => $R, 'today' => '2026-11-12', 'proof_exists' => fn() => true, 'heads' => $dbx['heads']]);
$t('the real mail is accepted as proof', $chk['ok'], $chk);
$sv = followup_save_eml($R, 'x.eml', $draft, false, false, ['exists' => fn() => false]);
$t('a draft cannot be saved into mails/', !$sv['ok'] && $sv['code'] === 400, $sv);
unset($fs["$R/mails/2026-11-01_new draft.eml"]);

// 5. Full dry run in batches with next_offset; nothing moved
$snap = $fs; $moves = 0;
$all = []; $off = 0; $found = 0; $calls = 0;
do {
    $r = drafts_cleanup($db, $o + ['scope' => 'all', 'limit_folders' => 2, 'offset' => $off]);
    $found += $r['drafts_found'];
    foreach ($r['results'] as $x) $all[] = $x['folder'];
    $off = $r['next_offset'];
    $calls++;
} while ($off !== null && $calls < 20);
sort($all);
$t('5. batches end (next_offset null) after several calls', $off === null && $calls >= 3, [$calls, $r['folders_total']]);
$t('5. every root / container / member scanned', $all === ['/001_Safari/00_CANCELED/07_01JUL_Verdi(X-Y)_CANCELLED', '/001_Safari/09_01SEP_Rossi(XYZ-Anderson)',
   '/001_Safari/GRP_10OCT_Panorama/11_10OCT_Bianchi(X-Y)', '/2026/Neri(ABC-Roberto)'], $all);
$t('5. totals: 4 drafts, none moved', $found === 4 && $fs === $snap && $moves === 0, $found);
$r = drafts_cleanup($db, $o + ['scope' => 'safari']);
$t('scope safari: 001_Safari only', !array_filter($r['results'], fn($x) => strpos($x['folder'], '/2026/') === 0) && $r['drafts_found'] === 3, $r['drafts_found']);
$r = drafts_cleanup($db, $o + ['scope' => 'requests']);
$t('scope requests: /2025–/2027 (missing years skipped)', $r['drafts_found'] === 1 && $r['folders_total'] === 1, [$r['drafts_found'], $r['folders_total']]);
$r = drafts_cleanup($db, $o + ['scope' => 'all', 'budget' => 0, 'chunk' => 1]);
$t('time budget: stops after the first chunk with next_offset', $r['next_offset'] === 1 && $r['folders_scanned'] === 1, $r['next_offset']);

// A header read that fails (Dropbox 429): read again once; still failing → "unreadable", never "kept", never moved.
$N = '/2026/Neri(ABC-Roberto)/mails/';
$failOnce = $dbx; $calls = 0;
$failOnce['heads'] = function (array $ps) use ($dbx, &$calls) { $calls++; $o = $dbx['heads']($ps); if ($calls === 1) foreach ($o as $k => $v) $o[$k] = null; return $o; };
$r = drafts_cleanup($db, ['dbx' => $failOnce] + $o + ['scope' => 'requests']);
$t('header read fails once → retried, classified', ($r['results'][0]['drafts'] ?? null) === ['d.eml'] && $r['unreadable'] === 0, $r['results']);
$failAll = $dbx;
$failAll['heads'] = fn(array $ps) => array_fill_keys($ps, null);
$snap = $fs;
$r = drafts_cleanup($db, ['dbx' => $failAll] + $o + ['scope' => 'requests', 'confirm' => true]);
$t('header never read → unreadable, reported, not moved', $r['unreadable'] === 2 && ($r['results'][0]['unreadable'] ?? []) && !($r['results'][0]['kept'] ?? [1]) && $fs === $snap, $r['results']);

echo $fail ? "\n$fail FAILED\n" : "\nALL PASS\n";
exit($fail ? 1 : 0);
