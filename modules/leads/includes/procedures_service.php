<?php
/**
 * procedures_service.php — office procedures read live from Dropbox /Operations/OfficeProcedures (one copy only:
 * when Roberto replaces a PDF there, the Hub serves the new one). Used by procedures.php (list), procedure_file.php
 * (proxy) and the contextual "?" links (proc_help_link(), map in procedures_map.php).
 *
 * Only top-level .pdf files are shown; orig/ (Word sources) only to admins; old/ and any other sub-folder never.
 * A file is served only if its name matches exactly an entry of the live listing: no path is built from user input.
 * Needs modules/leads/config.php (Dropbox constants) loaded first.
 */

require_once __DIR__ . '/../dropbox_helper.php';

const PROC_DIR = '/Operations/OfficeProcedures';
const PROC_SRC = PROC_DIR . '/orig';
const PROC_LINK_TTL = 600;   // the "?" links check the file exists against a listing at most 10 minutes old

/** Admin: Word sources and the Dropbox folder link. */
function proc_is_admin(): bool {
    if (function_exists('isLeadsAdmin')) return (bool)isLeadsAdmin();
    return (current_user()['role_name'] ?? '') === 'admin';
}

function proc_cache_dir(): string {
    $d = sys_get_temp_dir() . '/hub_procedures';
    if (!is_dir($d)) @mkdir($d, 0700, true);
    return $d;
}

/**
 * Live listing: ['pdf' => [entry…], 'src' => [entry…]] sorted by name; entry = dropbox_list_file_entries() row +
 * 'key' (the name= value of procedure_file.php: the file name, or "orig/<name>" for a Word source).
 * 'src' is filled only with $withSources. Throws on Dropbox errors.
 */
function proc_list(bool $withSources = false): array {
    static $cache = [];
    $k = $withSources ? 'src' : 'pdf';
    if (isset($cache[$k])) return $cache[$k];
    $token = dropbox_get_access_token();
    $byName = function (array $a, array $b) { return strnatcasecmp($a['name'], $b['name']); };
    $pdf = array_values(array_filter((array)dropbox_list_file_entries($token, PROC_DIR), function ($e) {
        return strtolower(pathinfo($e['name'], PATHINFO_EXTENSION)) === 'pdf';
    }));
    foreach ($pdf as &$e) $e['key'] = $e['name'];
    unset($e);
    usort($pdf, $byName);
    $src = [];
    if ($withSources) {
        $src = array_values(array_filter((array)dropbox_list_file_entries($token, PROC_SRC), function ($e) {
            return in_array(strtolower(pathinfo($e['name'], PATHINFO_EXTENSION)), ['docx', 'doc'], true);
        }));
        foreach ($src as &$e) $e['key'] = 'orig/' . $e['name'];
        unset($e);
        usort($src, $byName);
    }
    @file_put_contents(proc_cache_dir() . '/listing.json', json_encode(array_column($pdf, 'name')));
    return $cache[$k] = ['pdf' => $pdf, 'src' => $src];
}

/** The entry whose key is exactly $name (PDFs for everyone, Word sources for admins), or null. */
function proc_find(string $name, bool $admin): ?array {
    if ($name === '' || strpos($name, '..') !== false || strpos($name, '\\') !== false) return null;
    if (strpos($name, '/') !== false && !($admin && strpos($name, 'orig/') === 0 && substr_count($name, '/') === 1)) return null;
    $l = proc_list($admin);
    foreach (array_merge($l['pdf'], $l['src']) as $e) if ($e['key'] === $name) return $e;
    return null;
}

/** File content, cached on disk by Dropbox rev (a new rev = a new file, old copies of that file removed). */
function proc_content(array $e): string {
    $dir = proc_cache_dir();
    $base = sha1($e['path']);
    $f = $dir . '/' . $base . '_' . preg_replace('/[^A-Za-z0-9]/', '', $e['rev']);
    if ($e['rev'] !== '' && is_file($f) && filesize($f) === $e['size']) return (string)file_get_contents($f);
    $data = dropbox_download_text(dropbox_get_access_token(), $e['path']);
    if ($data === null) throw new RuntimeException('File no longer in Dropbox: ' . $e['name']);
    foreach ((array)glob($dir . '/' . $base . '_*') as $old) @unlink($old);
    if ($e['rev'] !== '') @file_put_contents($f, $data);
    return $data;
}

function proc_title(string $name): string {
    return pathinfo($name, PATHINFO_FILENAME);
}

/** procedure_file.php URL of a key (+ #page=N for PDFs). */
function proc_url(string $key, bool $download = false, int $page = 0): string {
    return 'procedure_file.php?name=' . rawurlencode($key) . ($download ? '&dl=1' : '') . ($page > 0 && !$download ? '#page=' . $page : '');
}

/** PDF names known to exist: a listing at most PROC_LINK_TTL old, else a fresh one. [] if Dropbox fails. */
function proc_known_files(): array {
    static $names = null;
    if ($names !== null) return $names;
    $f = proc_cache_dir() . '/listing.json';
    if (is_file($f) && filemtime($f) > time() - PROC_LINK_TTL) {
        $names = (array)json_decode((string)file_get_contents($f), true);
        return $names;
    }
    try {
        $names = array_column(proc_list()['pdf'], 'name');
    } catch (Throwable $e) {
        $names = [];   // Dropbox down: no "?" links for PROC_LINK_TTL, without slowing every page meanwhile
        @file_put_contents($f, '[]');
    }
    return $names;
}

/**
 * Small "?" link to the procedure for $key (procedures_map.php), opening in a new tab at the mapped page.
 * Empty string if the key is unknown or the file is not in Dropbox (never a broken link).
 * $base: path from the calling page to modules/leads/ ('../leads/' for a page in modules/operations/).
 */
function proc_help_link(string $key, string $base = ''): string {
    static $map = null;
    if ($map === null) $map = require __DIR__ . '/procedures_map.php';
    $m = $map[$key] ?? null;
    if (!$m || !in_array($m['file'], proc_known_files(), true)) return '';
    $h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
    return '<a href="' . $h($base . proc_url($m['file'], false, (int)$m['page'])) . '" target="_blank" rel="noopener" title="' . $h($m['title']) . '"'
         . ' style="display:inline-flex;align-items:center;justify-content:center;width:18px;height:18px;border-radius:50%;'
         . 'border:1px solid #C0211B;color:#C0211B;font-size:.7rem;font-weight:700;text-decoration:none;vertical-align:middle;margin-left:6px;line-height:1">?</a>';
}
