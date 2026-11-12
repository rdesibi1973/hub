<?php
// includes/deploy_service.php — deploy without SSH or git on the server.
//
// Why: the BlueHost account has `ulimit -u 25`; the old deploy (SSH login + deploy.sh + `git fetch`, which forks
// helpers) often failed with "fork: Resource temporarily unavailable" and still printed "Deploy done".
// This runs inside ONE web PHP process: download the GitHub zip of one commit, write the files that changed.
//
// - Only files tracked in git are in the zip, so server-only files (includes/config.php, modules/leads/config.php,
//   api.txt, uploads, error_log) are never touched; DEPLOY_PROTECTED is a second safety net.
// - Unchanged files are skipped (zip CRC vs file CRC); changed files are written to a temp file and renamed in place,
//   PHP files are invalidated in OPcache.
// - State (last commit + file list) lives outside the web root in ~/hub_deploy/. Files that were in the previous
//   deployed commit and are gone from this one are listed (not deleted): remove them by hand, as before.
// Entry point: /deploy_hook.php (token DEPLOY_HOOK_TOKEN in includes/config.php). Kept PHP 8.0-compatible (CLI php is 8.0).

const DEPLOY_REPO      = 'rdesibi1973/hub';
const DEPLOY_PROTECTED = ['includes/config.php', 'modules/leads/config.php', 'api.txt', 'deploy.sh', '.git'];

function deploy_state_dir(?string $dir = null): string
{
    // public_html/hub/includes → /home4/savannp5/hub_deploy
    $dir = $dir ?? dirname(__DIR__, 3) . '/hub_deploy';
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) throw new RuntimeException("Cannot create state dir $dir");
    return $dir;
}

function deploy_state_read(string $dir): array
{
    $f = $dir . '/state.json';
    if (!is_file($f)) return [];
    $s = json_decode((string)file_get_contents($f), true);
    return is_array($s) ? $s : [];
}

/** SHA of main on GitHub (used when the caller does not pass one). */
function deploy_remote_head(): string
{
    $ch = curl_init('https://api.github.com/repos/' . DEPLOY_REPO . '/commits/main');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER => ['User-Agent: hub-deploy', 'Accept: application/vnd.github.sha'],
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $sha = trim((string)$body);
    if ($code !== 200 || !preg_match('/^[0-9a-f]{40}$/', $sha)) throw new RuntimeException("GitHub did not return the head of main (HTTP $code)");
    return $sha;
}

function deploy_download(string $sha, string $dest): void
{
    $fh = fopen($dest, 'wb');
    if (!$fh) throw new RuntimeException("Cannot write $dest");
    $ch = curl_init('https://codeload.github.com/' . DEPLOY_REPO . '/zip/' . $sha);
    curl_setopt_array($ch, [
        CURLOPT_FILE => $fh, CURLOPT_TIMEOUT => 180, CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER => ['User-Agent: hub-deploy'],
    ]);
    $ok   = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    fclose($fh);
    if (!$ok || $code !== 200) {
        @unlink($dest);
        throw new RuntimeException("Download of commit $sha failed (HTTP $code" . ($err !== '' ? ", $err" : '') . ') — pushed?');
    }
}

function deploy_is_protected(string $rel): bool
{
    foreach (DEPLOY_PROTECTED as $p) {
        if ($rel === $p || strpos($rel, $p . '/') === 0) return true;
    }
    return false;
}

/**
 * Deploy one commit into $root (default: the Hub folder).
 * Options: sha (40 hex, default = head of main on GitHub), dry (bool: compare only, write nothing),
 *          root / state_dir (overrides, for tests).
 * Returns ['sha','previous','written'=>[...],'unchanged'=>n,'skipped'=>[...],'removed_in_repo'=>[...],'dry'].
 */
function deploy_run(array $opt = []): array
{
    @set_time_limit(300);
    $root = rtrim($opt['root'] ?? dirname(__DIR__), '/');
    $dry  = !empty($opt['dry']);
    $sha  = strtolower(trim((string)($opt['sha'] ?? '')));
    if ($sha === '') $sha = deploy_remote_head();
    if (!preg_match('/^[0-9a-f]{40}$/', $sha)) throw new InvalidArgumentException('sha must be a full 40-character commit id');
    if (!class_exists('ZipArchive')) throw new RuntimeException('PHP zip extension missing');

    $dir  = deploy_state_dir($opt['state_dir'] ?? null);
    $lock = fopen($dir . '/deploy.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) throw new RuntimeException('Another deploy is running');

    $zipFile = $dir . '/download.zip';
    try {
        deploy_download($sha, $zipFile);
        $zip = new ZipArchive();
        if ($zip->open($zipFile) !== true) throw new RuntimeException('Downloaded file is not a valid zip');

        $prev = deploy_state_read($dir);
        $res  = ['sha' => $sha, 'previous' => $prev['sha'] ?? null, 'written' => [], 'unchanged' => 0,
                 'skipped' => [], 'removed_in_repo' => [], 'dry' => $dry];
        $files = [];
        $prefix = null;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $st = $zip->statIndex($i);
            $name = $st['name'];
            if ($prefix === null) $prefix = substr($name, 0, strpos($name, '/') + 1);   // "hub-<sha>/"
            if (strpos($name, $prefix) !== 0) continue;
            $rel = substr($name, strlen($prefix));
            if ($rel === '' || substr($rel, -1) === '/') continue;                    // folders: created on demand
            if (strpos($rel, '\\') !== false || preg_match('#(^|/)\.\.(/|$)#', $rel)) { $res['skipped'][] = $rel; continue; }
            if (deploy_is_protected($rel)) { $res['skipped'][] = $rel; continue; }
            $files[] = $rel;

            $target = $root . '/' . $rel;
            if (is_file($target) && filesize($target) === (int)$st['size']
                && hash_file('crc32b', $target) === sprintf('%08x', $st['crc'])) {
                $res['unchanged']++;
                continue;
            }
            $res['written'][] = $rel;
            if ($dry) continue;

            $tdir = dirname($target);
            if (!is_dir($tdir) && !@mkdir($tdir, 0755, true)) throw new RuntimeException("Cannot create folder $tdir");
            $in  = $zip->getStream($name);
            $tmp = $target . '.deploytmp';
            $out = $in ? fopen($tmp, 'wb') : false;
            if (!$in || !$out) throw new RuntimeException("Cannot write $rel");
            stream_copy_to_stream($in, $out);
            fclose($in);
            fclose($out);
            if (hash_file('crc32b', $tmp) !== sprintf('%08x', $st['crc'])) { @unlink($tmp); throw new RuntimeException("CRC mismatch on $rel"); }
            if (!@rename($tmp, $target)) { @unlink($tmp); throw new RuntimeException("Cannot replace $rel"); }
            @chmod($target, 0644);
            if (function_exists('opcache_invalidate') && substr($rel, -4) === '.php') @opcache_invalidate($target, true);
        }
        $zip->close();
        if (!$files) throw new RuntimeException('Zip contained no files');

        if (!empty($prev['files'])) {
            $now = array_flip($files);
            foreach ($prev['files'] as $f) {
                if (!isset($now[$f]) && is_file($root . '/' . $f)) $res['removed_in_repo'][] = $f;
            }
        }
        if (!$dry) {
            file_put_contents($dir . '/state.json', json_encode([
                'sha' => $sha, 'at' => date('Y-m-d H:i:s'), 'written' => count($res['written']), 'files' => $files,
            ]));
            file_put_contents($dir . '/deploy.log', date('Y-m-d H:i:s') . " $sha written=" . count($res['written'])
                . " unchanged={$res['unchanged']}\n", FILE_APPEND);
        }
        return $res;
    } finally {
        @unlink($zipFile);
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
