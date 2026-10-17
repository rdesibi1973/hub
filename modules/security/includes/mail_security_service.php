<?php
/**
 * mail_security_service.php — mail security scan of the cPanel account (savannp5): mailboxes, filters,
 * forwarders, auto-replies, catch-all. Background: in Oct 2026 a filter named "GLAD" on agustin@
 * redirected client mail from ~50 free-mail domains to savanah.explorers@gmail.com (one "n").
 *
 * Used by modules/security/mail_scan.php (CLI, cPanel cron: scans, writes, emails) and by the Agent API
 * mail_security_status (read-only: last run from the DB, never calls uapi from the web process).
 * Tables: migrations/071_mail_security.sql (mss_schema() creates them too).
 *
 * Data source: the cPanel UAPI command line (`uapi --output=json Module func`, no token when run as the
 * cPanel user), else HTTPS UAPI with CPANEL_API_TOKEN (includes/config.php, server-only).
 */

const MSS_OWN_DOMAINS      = ['savannahexplorers.com', 'theorangicollection.com', 'kifarutentedlodge.com', 'mareraviewlodge.com'];
const MSS_TRUSTED_EXTERNAL = ['savannah.explorers@gmail.com'];          // Roberto's Gmail (two "n")
const MSS_LOOKALIKE_REFS   = ['savannahexplorers', 'theorangicollection', 'kifarutentedlodge', 'mareraviewlodge'];
const MSS_BRAND_STEMS      = ['savan', 'orangi', 'kifaru', 'marera'];
const MSS_MANY_DOMAINS     = 5;                                          // ≥ 5 "contains @domain" rules = Oct 2026 pattern
const MSS_KIND_LABELS      = ['mailbox' => 'Mailboxes', 'filter' => 'Filters', 'gfilter' => 'Account-level filters',
                              'fmtime' => 'Filter files', 'fwd' => 'Forwarders', 'dfwd' => 'Domain forwarders',
                              'autoreply' => 'Auto-replies', 'catchall' => 'Catch-all'];

function mss_schema(PDO $db): void {
    static $done = false;
    if ($done) return;
    $db->exec("CREATE TABLE IF NOT EXISTS mail_security_snapshot (
        id INT AUTO_INCREMENT PRIMARY KEY, taken_at DATETIME NOT NULL, last_seen_at DATETIME NOT NULL,
        hash CHAR(40) NOT NULL, json MEDIUMTEXT NOT NULL, KEY idx_mss_taken (taken_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS mail_security_baseline (
        item_key VARCHAR(190) NOT NULL PRIMARY KEY, item_hash CHAR(40) NOT NULL, label VARCHAR(500) NULL,
        approved_at DATETIME NOT NULL, approved_by VARCHAR(60) NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS mail_security_run (
        id INT AUTO_INCREMENT PRIMARY KEY, ran_at DATETIME NOT NULL, ok TINYINT(1) NOT NULL, error TEXT NULL,
        snapshot_id INT NULL, source VARCHAR(10) NULL, counts_json TEXT NULL, alerts_json MEDIUMTEXT NULL,
        mailed VARCHAR(20) NULL, KEY idx_msr_ran (ran_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

// ── UAPI ─────────────────────────────────────────────────────────────────────

/** How to reach UAPI: ['mode' => 'uapi', 'bin' => path] or ['mode' => 'token', ...]. $bin forces the CLI path. */
function mss_api_context(?string $bin = null): array {
    if ($bin !== null && $bin !== '') return ['mode' => 'uapi', 'bin' => $bin];
    $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
    if (function_exists('exec') && !in_array('exec', $disabled, true)) {
        foreach (['/usr/bin/uapi', '/usr/local/cpanel/bin/uapi'] as $p) {
            if (is_file($p)) return ['mode' => 'uapi', 'bin' => $p];
        }
    }
    if (defined('CPANEL_API_TOKEN') && CPANEL_API_TOKEN !== '') {
        return ['mode' => 'token', 'host' => defined('CPANEL_HOST') ? CPANEL_HOST : 'box2233.bluehost.com',
                'user' => defined('CPANEL_USER') ? CPANEL_USER : 'savannp5', 'token' => CPANEL_API_TOKEN];
    }
    throw new RuntimeException('No UAPI access: exec()/uapi not available and CPANEL_API_TOKEN not set in includes/config.php');
}

/** One UAPI call → its data (array). Any error throws: a half-read state would look like "filter removed". */
function mss_call(array $ctx, string $module, string $func, array $args = []): array {
    $what = $module . '::' . $func . ($args ? ' ' . http_build_query($args) : '');
    if ($ctx['mode'] === 'uapi') {
        $cmd = escapeshellarg($ctx['bin']) . ' --output=json ' . escapeshellarg($module) . ' ' . escapeshellarg($func);
        foreach ($args as $k => $v) $cmd .= ' ' . escapeshellarg($k . '=' . $v);
        $out = [];
        $rc  = 0;
        exec($cmd . ' 2>&1', $out, $rc);
        $raw = implode("\n", $out);
        $j   = json_decode($raw, true);
        $res = is_array($j) ? ($j['result'] ?? $j) : null;
        if (!is_array($res)) throw new RuntimeException("uapi $what: exit $rc, no JSON: " . substr($raw, 0, 300));
    } else {
        $url = 'https://' . $ctx['host'] . ':2083/execute/' . rawurlencode($module) . '/' . rawurlencode($func)
             . ($args ? '?' . http_build_query($args) : '');
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60,
                                CURLOPT_HTTPHEADER => ['Authorization: cpanel ' . $ctx['user'] . ':' . $ctx['token']]]);
        $raw  = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        $res = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($res)) throw new RuntimeException("UAPI $what: HTTP $code $err " . substr((string)$raw, 0, 300));
    }
    if (empty($res['status'])) {
        $errs = $res['errors'] ?? [];
        throw new RuntimeException("UAPI $what failed: " . (is_array($errs) ? implode('; ', $errs) : (string)$errs));
    }
    return is_array($res['data'] ?? null) ? $res['data'] : [];
}

/** Home dir of the cPanel user (filter files live in <home>/etc/<domain>/<user>/filter). */
function mss_home(): string {
    $h = (string)getenv('HOME');
    if ($h === '' && function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
        $h = (string)(posix_getpwuid(posix_geteuid())['dir'] ?? '');
    }
    if ($h === '' && ($p = strpos(__DIR__, '/public_html')) !== false) $h = substr(__DIR__, 0, $p);
    return rtrim($h, '/');
}

// ── Snapshot ─────────────────────────────────────────────────────────────────

function mss_norm_filter(array $f): array {
    $rules = [];
    foreach ((array)($f['rules'] ?? []) as $r) {
        $rules[] = ['part' => trim((string)($r['part'] ?? '')), 'match' => (string)($r['match'] ?? ''),
                    'val' => (string)($r['val'] ?? ''), 'opt' => (string)($r['opt'] ?? '')];
    }
    $actions = [];
    foreach ((array)($f['actions'] ?? []) as $a) {
        $actions[] = ['action' => (string)($a['action'] ?? ''), 'dest' => (string)($a['dest'] ?? '')];
    }
    return ['name' => (string)($f['filtername'] ?? $f['name'] ?? ''),
            'enabled' => !array_key_exists('enabled', $f) || (int)$f['enabled'] === 1,
            'rules' => $rules, 'actions' => $actions];
}

/** Read the whole account. Throws on any UAPI error. */
function mss_collect(array $ctx): array {
    $mailboxes = [];
    foreach (mss_call($ctx, 'Email', 'list_pops') as $p) {
        $e = strtolower(trim((string)($p['email'] ?? '')));
        if (strpos($e, '@') !== false) $mailboxes[$e] = true;
    }
    $mailboxes = array_keys($mailboxes);
    sort($mailboxes);

    $d = mss_call($ctx, 'DomainInfo', 'list_domains');
    $domains = array_merge([(string)($d['main_domain'] ?? '')], (array)($d['addon_domains'] ?? []), (array)($d['parked_domains'] ?? []));
    foreach ($mailboxes as $m) $domains[] = substr($m, strpos($m, '@') + 1);
    $domains = array_values(array_unique(array_filter(array_map('strtolower', $domains))));
    sort($domains);

    $filters = [];
    $mtimes  = [];
    $home    = mss_home();
    foreach ($mailboxes as $m) {
        $list = [];
        foreach (mss_call($ctx, 'Email', 'list_filters', ['account' => $m]) as $f) $list[] = mss_norm_filter((array)$f);
        if ($list) $filters[$m] = $list;
        [$user, $dom] = explode('@', $m, 2);
        $file = $home . '/etc/' . $dom . '/' . $user . '/filter';
        clearstatcache(true, $file);
        if ($home !== '' && is_file($file)) $mtimes[$m] = date('Y-m-d H:i:s', (int)filemtime($file));
    }
    $global = [];
    foreach (mss_call($ctx, 'Email', 'list_filters') as $f) $global[] = mss_norm_filter((array)$f);

    $fwd = [];
    foreach (mss_call($ctx, 'Email', 'list_forwarders') as $f) {
        $fwd[] = ['from' => strtolower(trim((string)($f['dest'] ?? ''))), 'to' => trim((string)($f['forward'] ?? ''))];
    }
    $dfwd = [];
    foreach (mss_call($ctx, 'Email', 'list_domain_forwarders') as $f) {
        $dfwd[] = ['from' => strtolower(trim((string)($f['dest'] ?? ''))), 'to' => strtolower(trim((string)($f['forward'] ?? '')))];
    }

    $auto = [];
    $catch = [];
    foreach ($domains as $dom) {
        foreach (mss_call($ctx, 'Email', 'list_auto_responders', ['domain' => $dom]) as $a) {
            $e = strtolower(trim((string)($a['email'] ?? '')));
            if ($e === '' || isset($auto[$e])) continue;
            $g = mss_call($ctx, 'Email', 'get_auto_responder', ['email' => $e]);
            $auto[$e] = ['email' => $e, 'subject' => (string)($g['subject'] ?? $a['subject'] ?? ''), 'from' => (string)($g['from'] ?? ''),
                         'body' => (string)($g['body'] ?? ''), 'interval' => (string)($g['interval'] ?? ''),
                         'start' => (string)($g['start'] ?? ''), 'stop' => (string)($g['stop'] ?? '')];
        }
        foreach (mss_call($ctx, 'Email', 'list_default_address', ['domain' => $dom]) as $c) {
            // uapi returns the value quoted: "\":fail: No Such User Here\""
            $catch[strtolower((string)($c['domain'] ?? $dom))] = trim((string)($c['defaultaddress'] ?? ''), " \t\"'");
        }
    }
    ksort($auto);
    ksort($catch);

    return ['taken_at' => date('Y-m-d H:i:s'), 'source' => $ctx['mode'], 'domains' => $domains, 'mailboxes' => $mailboxes,
            'filters' => $filters, 'global_filters' => $global, 'filter_mtimes' => $mtimes, 'forwarders' => $fwd,
            'domain_forwarders' => $dfwd, 'autoreplies' => array_values($auto), 'catchall' => $catch];
}

// ── Items + risk rules ───────────────────────────────────────────────────────

function mss_domain_of(string $addr): string {
    $p = strrpos($addr, '@');
    return $p === false ? strtolower($addr) : strtolower(substr($addr, $p + 1));
}

/** Our address: own domain (or account domain) or the trusted Gmail. */
function mss_is_ours(string $addr, array $domains): bool {
    $a = strtolower(trim($addr));
    if (in_array($a, MSS_TRUSTED_EXTERNAL, true)) return true;
    if (strpos($a, '@') === false) return false;
    $dom = mss_domain_of($a);
    foreach (array_merge(MSS_OWN_DOMAINS, $domains) as $d) {
        if ($dom === $d || substr($dom, -strlen('.' . $d)) === '.' . $d) return true;
    }
    return false;
}

/** "Looks like ours but is not" (savanah…, savannah-explorers@gmail…) → reason, else null. */
function mss_lookalike(string $addr, array $domains): ?string {
    $a = strtolower(trim($addr));
    if ($a === '' || mss_is_ours($a, $domains)) return null;
    $at    = strrpos($a, '@');
    $local = $at === false ? $a : substr($a, 0, $at);
    $dom   = $at === false ? '' : substr($a, $at + 1);
    $tokens = [preg_replace('/[^a-z0-9]/', '', $local)];
    if ($dom !== '' && strrpos($dom, '.') !== false) $tokens[] = preg_replace('/[^a-z0-9]/', '', substr($dom, 0, strrpos($dom, '.')));
    foreach ($tokens as $t) {
        if ($t === '') continue;
        foreach (MSS_LOOKALIKE_REFS as $ref) {
            if (levenshtein(substr($t, 0, 255), $ref) <= 2) return 'looks like "' . $ref . '" but is not ours';
        }
        foreach (MSS_BRAND_STEMS as $s) {
            if (strpos($t, $s) !== false) return 'contains "' . $s . '" but is not ours';
        }
    }
    return null;
}

/** HIGH reasons of a filter (rules 2, 3, 4 of the spec). */
function mss_filter_risks(array $f, array $domains): array {
    $why = [];
    foreach ($f['actions'] as $a) {
        $act  = strtolower($a['action']);
        $dest = trim($a['dest']);
        if ($act === 'deliver' || $act === 'redirect') {
            foreach (preg_split('/[,\s]+/', $dest) as $d) {
                if ($d === '') continue;
                if (!mss_is_ours($d, $domains)) $why[] = 'delivers to outside address ' . $d;
                if ($l = mss_lookalike($d, $domains)) $why[] = $d . ' ' . $l;
            }
        } elseif ($act === 'save') {
            $why[] = $dest === '/dev/null' ? 'discards the mail' : 'saves to folder ' . $dest;
        } elseif ($act === 'fail') {
            $why[] = 'bounces the mail (fail)';
        } elseif ($act === 'pipe') {
            $why[] = 'pipes to a program ' . $dest;
        }
    }
    $n = 0;
    foreach ($f['rules'] as $r) {
        if (preg_match('/^@?[a-z0-9-]+(\.[a-z0-9-]+)*\.[a-z]{2,}\.?$|^@/i', trim($r['val']))) $n++;
    }
    if ($n >= MSS_MANY_DOMAINS) $why[] = "matches $n sender domains (pattern of the Oct 2026 attack)";
    return array_values(array_unique($why));
}

function mss_filter_text(array $f): string {
    $rules = [];
    foreach ($f['rules'] as $i => $r) {
        $part = preg_replace('/^\$header_|:$/', '', $r['part']);
        $rules[] = ($i > 0 ? strtoupper($f['rules'][$i - 1]['opt'] ?: 'or') . ' ' : '') . $part . ' ' . $r['match'] . ' ' . $r['val'];
    }
    $acts = [];
    foreach ($f['actions'] as $a) $acts[] = $a['action'] . ($a['dest'] !== '' ? ' ' . $a['dest'] : '');
    $r = implode(' ', $rules);
    if (strlen($r) > 300) $r = substr($r, 0, 300) . '… (' . count($f['rules']) . ' rules)';
    return $r . ' → ' . implode(', ', $acts) . ($f['enabled'] ? '' : ' [disabled]');
}

/** Flat items: key => [kind, label, value, high[]]. Values are compared; labels are for humans. */
function mss_items(array $s): array {
    $doms = $s['domains'];
    $it = [];
    $add = function (string $kind, string $key, string $label, string $value, array $high = [], string $detail = '') use (&$it) {
        $it[$kind . ':' . $key] = ['kind' => $kind, 'label' => $label, 'value' => $value, 'high' => $high, 'detail' => $detail];
    };
    foreach ($s['mailboxes'] as $m) $add('mailbox', $m, 'Mailbox ' . $m, 'exists');
    foreach ($s['filters'] as $m => $list) {
        foreach ($list as $i => $f) {
            $key = $m . ':' . ($f['name'] !== '' ? $f['name'] : '#' . $i);
            $risk = mss_filter_risks($f, $doms);
            $add('filter', $key, 'Filter "' . $f['name'] . '" on ' . $m . ': ' . mss_filter_text($f), json_encode($f), $risk);
        }
    }
    foreach ($s['global_filters'] as $i => $f) {
        $add('gfilter', $f['name'] !== '' ? $f['name'] : '#' . $i, 'Account-level filter "' . $f['name'] . '": ' . mss_filter_text($f),
             json_encode($f), mss_filter_risks($f, $doms));
    }
    foreach ($s['filter_mtimes'] as $m => $t) $add('fmtime', $m, 'Filter file of ' . $m . ' modified ' . $t, $t);
    foreach ($s['forwarders'] as $f) {
        $high = [];
        foreach (preg_split('/[,\s]+/', $f['to']) as $d) {
            if ($d === '') continue;
            if ($d[0] === '|' || $d[0] === '/') $high[] = 'pipes / writes to ' . $d;
            elseif (!mss_is_ours($d, $doms)) $high[] = 'forwards to outside address ' . $d;
            if ($l = mss_lookalike($d, $doms)) $high[] = $d . ' ' . $l;
        }
        $add('fwd', $f['from'] . '>' . $f['to'], 'Forwarder ' . $f['from'] . ' → ' . $f['to'], '1', array_values(array_unique($high)));
    }
    foreach ($s['domain_forwarders'] as $f) {
        $high = mss_is_ours('x@' . $f['to'], $doms) ? [] : ['forwards the whole domain outside: ' . $f['to']];
        $add('dfwd', $f['from'] . '>' . $f['to'], 'Domain forwarder ' . $f['from'] . ' → ' . $f['to'], '1', $high);
    }
    foreach ($s['autoreplies'] as $a) {
        $body = trim(preg_replace('/\s+/', ' ', strip_tags($a['body'])));
        $high = [];
        if ($a['from'] !== '' && preg_match('/[a-z0-9._%+-]+@[a-z0-9.-]+/i', $a['from'], $mm) && ($l = mss_lookalike($mm[0], $doms))) {
            $high[] = 'from ' . $mm[0] . ' ' . $l;
        }
        $add('autoreply', $a['email'], 'Auto-reply on ' . $a['email'] . ': "' . $a['subject'] . '"'
             . ($a['stop'] !== '' && $a['stop'] !== '0' ? ' (until ' . (ctype_digit($a['stop']) ? date('Y-m-d', (int)$a['stop']) : $a['stop']) . ')' : ''),
             sha1(json_encode([$a['subject'], $a['from'], $a['body'], $a['interval'], $a['start'], $a['stop']])), $high,
             mb_substr($body, 0, 300));
    }
    foreach ($s['catchall'] as $dom => $v) {
        $high = [];
        $vv = strtolower($v);
        if (strpos($vv, ':fail:') !== 0 && strpos($vv, ':blackhole:') !== 0 && strpos($vv, '@') !== false && !mss_is_ours($vv, $doms)) {
            $high[] = 'catch-all goes to outside address ' . $v;
        }
        $add('catchall', $dom, 'Catch-all of ' . $dom . ': ' . $v, $v, $high);
    }
    ksort($it);
    return $it;
}

function mss_counts(array $s): array {
    $nf = count($s['global_filters']);
    foreach ($s['filters'] as $l) $nf += count($l);
    return ['mailboxes' => count($s['mailboxes']), 'filters' => $nf, 'forwarders' => count($s['forwarders']) + count($s['domain_forwarders']),
            'autoreplies' => count($s['autoreplies']), 'catchall_not_fail' => count(array_filter($s['catchall'], function ($v) {
                return stripos($v, ':fail:') !== 0; }))];
}

// ── Compare ──────────────────────────────────────────────────────────────────

/**
 * Alerts of this scan. $prevItems null = first run (no diff). $baseline = item_key => item_hash.
 * Diff vs previous snapshot, skipping items whose new value is the approved one; plus every HIGH
 * item present and not approved (even if old). Each alert: change, level, key, kind, label, old_label, reasons, detail.
 */
function mss_alerts(array $cur, ?array $prevItems, array $baseline): array {
    $alerts = [];
    if ($prevItems !== null) {
        foreach ($cur as $k => $c) {
            $p = $prevItems[$k] ?? null;
            if ($p !== null && $p['value'] === $c['value']) continue;
            if (isset($baseline[$k]) && $baseline[$k] === sha1($c['value'])) continue;
            $alerts[$k] = ['change' => $p === null ? 'added' : 'changed', 'key' => $k, 'kind' => $c['kind'], 'label' => $c['label'],
                           'old_label' => $p['label'] ?? null, 'reasons' => $c['high'], 'detail' => $c['detail']];
        }
        foreach ($prevItems as $k => $p) {
            if (isset($cur[$k])) continue;
            $alerts[$k] = ['change' => 'removed', 'key' => $k, 'kind' => $p['kind'], 'label' => $p['label'],
                           'old_label' => null, 'reasons' => [], 'detail' => ''];
        }
    }
    foreach ($cur as $k => $c) {
        if (!$c['high'] || isset($alerts[$k])) continue;
        if (isset($baseline[$k]) && $baseline[$k] === sha1($c['value'])) continue;
        $alerts[$k] = ['change' => 'present', 'key' => $k, 'kind' => $c['kind'], 'label' => $c['label'],
                       'old_label' => null, 'reasons' => $c['high'], 'detail' => $c['detail']];
    }
    foreach ($alerts as &$a) $a['level'] = $a['reasons'] ? 'HIGH' : 'normal';
    unset($a);
    uasort($alerts, function ($a, $b) {
        return [$a['level'] !== 'HIGH', $a['kind'], $a['key']] <=> [$b['level'] !== 'HIGH', $b['kind'], $b['key']];
    });
    return array_values($alerts);
}

/** HIGH items currently present (approved or not), for the weekly report. */
function mss_high_items(array $cur, array $baseline): array {
    $out = [];
    foreach ($cur as $k => $c) {
        if (!$c['high']) continue;
        $out[] = ['key' => $k, 'label' => $c['label'], 'reasons' => $c['high'],
                  'approved' => isset($baseline[$k]) && $baseline[$k] === sha1($c['value'])];
    }
    return $out;
}

/** State approved by Roberto on 16 Oct 2026 (after the cleanup) → differences from the first scan. */
function mss_expected_diff(array $s): array {
    $se = '@savannahexplorers.com';
    $glad = ['info@theorangicollection.com', 'info@mareraviewlodge.com', 'info@kifarutentedlodge.com'];
    foreach (['accountant', 'alessia', 'alex', 'anderson.jr', 'asia.caruso', 'back-office', 'corrado', 'emmanuel', 'front-office',
              'garage', 'glady', 'greyson', 'info', 'micky', 'nuru', 'office', 'operations', 'roberto.capri', 'safari', 'sales',
              'saruni', 'sultan'] as $u) $glad[] = $u . $se;
    $expFwd  = ['back-office' . $se . '>micky' . $se, 'front-office' . $se . '>agustin' . $se, 'sales@mareraviewlodge.com>savannah.explorers@gmail.com'];
    $expAuto = ['eva@theorangicollection.com', 'info@kifarutentedlodge.com', 'info' . $se, 'info@theorangicollection.com',
                'orangi@theorangicollection.com', 'rick' . $se, 'safari' . $se, 'sara' . $se];
    $out = [];

    // Filters: exactly GLAD on the 25 mailboxes + "Send To Roberto" on sales@.
    $seen = [];
    foreach ($s['filters'] as $m => $list) {
        foreach ($list as $f) {
            $txt = strtolower(json_encode($f));
            $toRob = count($f['actions']) > 0 && strtolower(trim($f['actions'][0]['dest'])) === 'savannah.explorers@gmail.com';
            if ($f['name'] === 'GLAD' && in_array($m, $glad, true) && strpos($txt, 'gladnessk@yahoo.com') !== false && $toRob && count($f['actions']) === 1) {
                $seen['GLAD|' . $m] = true;
            } elseif ($f['name'] === 'Send To Roberto' && $m === 'sales' . $se && $toRob && count($f['actions']) === 1) {
                $seen['STR'] = true;
            } else {
                $out[] = 'Unexpected filter "' . $f['name'] . '" on ' . $m . ': ' . mss_filter_text($f);
            }
        }
    }
    foreach ($glad as $m) if (empty($seen['GLAD|' . $m])) $out[] = 'Missing filter "GLAD" (gladnessk@yahoo.com → savannah.explorers@gmail.com) on ' . $m;
    if (empty($seen['STR'])) $out[] = 'Missing filter "Send To Roberto" on sales' . $se;
    if (!empty($s['filters']['agustin' . $se])) $out[] = 'agustin' . $se . ' has filters (expected none)';

    $fwd = [];
    foreach ($s['forwarders'] as $f) $fwd[] = $f['from'] . '>' . strtolower($f['to']);
    foreach (array_diff($fwd, $expFwd) as $f) $out[] = 'Unexpected forwarder ' . str_replace('>', ' → ', $f);
    foreach (array_diff($expFwd, $fwd) as $f) $out[] = 'Missing forwarder ' . str_replace('>', ' → ', $f);

    $auto = array_column($s['autoreplies'], 'email');
    foreach (array_diff($auto, $expAuto) as $a) $out[] = 'Unexpected auto-reply on ' . $a;
    foreach (array_diff($expAuto, $auto) as $a) $out[] = 'Missing auto-reply on ' . $a;

    foreach ($s['catchall'] as $d => $v) if (stripos($v, ':fail:') !== 0) $out[] = 'Catch-all of ' . $d . ' is "' . $v . '" (expected :fail:)';
    foreach ($s['global_filters'] as $f) $out[] = 'Unexpected account-level filter "' . $f['name'] . '": ' . mss_filter_text($f);
    foreach ($s['domain_forwarders'] as $f) $out[] = 'Unexpected domain forwarder ' . $f['from'] . ' → ' . $f['to'];
    return $out;
}

// ── DB ───────────────────────────────────────────────────────────────────────

function mss_baseline(PDO $db): array {
    $b = [];
    foreach ($db->query("SELECT item_key, item_hash FROM mail_security_baseline") as $r) $b[$r['item_key']] = $r['item_hash'];
    return $b;
}

function mss_baseline_rows(PDO $db): array {
    return $db->query("SELECT item_key, label, approved_at, approved_by FROM mail_security_baseline ORDER BY item_key")->fetchAll(PDO::FETCH_ASSOC);
}

function mss_approve(PDO $db, array $items, array $keys, string $by): int {
    $st = $db->prepare("REPLACE INTO mail_security_baseline (item_key, item_hash, label, approved_at, approved_by) VALUES (?,?,?,?,?)");
    $n = 0;
    foreach ($keys as $k) {
        if (!isset($items[$k])) continue;
        $st->execute([substr($k, 0, 190), sha1($items[$k]['value']), mb_substr($items[$k]['label'], 0, 500), date('Y-m-d H:i:s'), $by]);
        $n++;
    }
    return $n;
}

/** Items gone since the last scan lose their approval (if they come back they are alerted again). */
function mss_unapprove(PDO $db, array $keys): void {
    $st = $db->prepare("DELETE FROM mail_security_baseline WHERE item_key = ?");
    foreach ($keys as $k) $st->execute([$k]);
}

/** Last snapshot: [id, snapshot] or null. */
function mss_last_snapshot(PDO $db): ?array {
    $r = $db->query("SELECT id, json FROM mail_security_snapshot ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;
    $s = json_decode($r['json'], true);
    return is_array($s) ? ['id' => (int)$r['id'], 'snapshot' => $s] : null;
}

/** Store the snapshot (new row only when the state changed). Returns its id. */
function mss_save_snapshot(PDO $db, array $s, array $items, ?array $last): int {
    $hash = sha1(json_encode(array_map(function ($i) { return $i['value']; }, $items)));
    $now = date('Y-m-d H:i:s');
    if ($last) {
        $prevHash = sha1(json_encode(array_map(function ($i) { return $i['value']; }, mss_items($last['snapshot']))));
        if ($prevHash === $hash) {
            $db->prepare("UPDATE mail_security_snapshot SET last_seen_at = ? WHERE id = ?")->execute([$now, $last['id']]);
            return $last['id'];
        }
    }
    $db->prepare("INSERT INTO mail_security_snapshot (taken_at, last_seen_at, hash, json) VALUES (?,?,?,?)")
       ->execute([$now, $now, $hash, json_encode($s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    return (int)$db->lastInsertId();
}

function mss_log_run(PDO $db, bool $ok, ?string $error, ?int $snapId, ?string $source, ?array $counts, ?array $alerts, string $mailed): void {
    $db->prepare("INSERT INTO mail_security_run (ran_at, ok, error, snapshot_id, source, counts_json, alerts_json, mailed) VALUES (?,?,?,?,?,?,?,?)")
       ->execute([date('Y-m-d H:i:s'), $ok ? 1 : 0, $error, $snapId, $source,
                  $counts === null ? null : json_encode($counts), $alerts === null ? null : json_encode($alerts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                  $mailed]);
}

/** For the Agent API mail_security_status: last run + last good run, from the DB only. */
function mss_status(PDO $db): array {
    mss_schema($db);
    $last = $db->query("SELECT * FROM mail_security_run ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $good = $db->query("SELECT * FROM mail_security_run WHERE ok = 1 ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$last) return ['ok' => true, 'status' => 'never_run', 'message' => 'No scan yet — modules/security/mail_scan.php has not run on the server.'];
    $g = $good ? json_decode((string)$good['alerts_json'], true) : [];
    $ageH = $good ? round((time() - strtotime($good['ran_at'])) / 3600, 1) : null;
    $status = !$last['ok'] ? 'scan_failed' : ($ageH !== null && $ageH > 26 ? 'stale' : (!empty($g['alerts']) ? 'alerts' : 'ok'));
    $snap = $good && $good['snapshot_id'] ? $db->prepare("SELECT taken_at, last_seen_at FROM mail_security_snapshot WHERE id = ?") : null;
    if ($snap) $snap->execute([(int)$good['snapshot_id']]);
    $snapRow = $snap ? $snap->fetch(PDO::FETCH_ASSOC) : null;
    return ['ok' => true, 'status' => $status,
            'last_run' => ['at' => $last['ran_at'], 'ok' => (bool)$last['ok'], 'error' => $last['error'], 'mailed' => $last['mailed']],
            'last_good_scan' => $good ? ['at' => $good['ran_at'], 'hours_ago' => $ageH, 'source' => $good['source'],
                                         'state_unchanged_since' => $snapRow['taken_at'] ?? null] : null,
            'counts' => $good ? json_decode((string)$good['counts_json'], true) : null,
            'alerts' => $g['alerts'] ?? [], 'high_items' => $g['high'] ?? [],
            'baseline_items' => (int)$db->query("SELECT COUNT(*) FROM mail_security_baseline")->fetchColumn()];
}

// ── Email ────────────────────────────────────────────────────────────────────

function mss_mailscan_line(array $counts): string {
    return 'MAILSCAN: mailboxes=' . $counts['mailboxes'] . '; filters=' . $counts['filters'] . '; forwarders=' . $counts['forwarders']
         . '; autoreplies=' . $counts['autoreplies'] . '; alerts=' . $counts['alerts'] . '; high=' . $counts['high'];
}

/**
 * Build the email. $type: alert | weekly | first | failed. $d: date, counts, alerts, high, items, baseline_rows,
 * expected_diff, error. Returns [subject, html, text].
 */
function mss_email(string $type, array $d): array {
    $h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
    $date = $d['date'];
    $html = '';
    $txt  = '';
    $sec = function (string $title) use (&$html, &$txt, $h) {
        $html .= '<h3 style="font-family:Arial,sans-serif;font-size:15px;margin:18px 0 6px 0;">' . $h($title) . '</h3>';
        $txt  .= "\n== " . $title . " ==\n";
    };
    $list = function (array $lines) use (&$html, &$txt, $h) {
        if (!$lines) { $html .= '<div style="color:#777;">none</div>'; $txt .= "none\n"; return; }
        $html .= '<ul style="margin:0;padding-left:20px;">';
        foreach ($lines as $l) { $html .= '<li>' . $l[0] . '</li>'; $txt .= '- ' . $l[1] . "\n"; }
        $html .= '</ul>';
    };
    $alertLines = function (array $alerts) use ($h) {
        $out = [];
        foreach ($alerts as $a) {
            $lvl = $a['level'] === 'HIGH' ? '<b style="color:#C0211B;">HIGH</b> ' : '';
            $chg = strtoupper($a['change']);
            $t = ($a['level'] === 'HIGH' ? 'HIGH ' : '') . $chg . ': ' . $a['label'];
            $hh = $lvl . '<b>' . $h($chg) . '</b>: ' . $h($a['label']);
            if (!empty($a['old_label']) && $a['old_label'] !== $a['label']) { $t .= "\n    was: " . $a['old_label']; $hh .= '<br><span style="color:#777;">was: ' . $h($a['old_label']) . '</span>'; }
            if ($a['reasons']) { $t .= "\n    why: " . implode('; ', $a['reasons']); $hh .= '<br><span style="color:#C0211B;">' . $h(implode('; ', $a['reasons'])) . '</span>'; }
            if ($a['detail'] !== '') { $t .= "\n    text: " . $a['detail']; $hh .= '<br><span style="color:#555;">text: ' . $h($a['detail']) . '</span>'; }
            $t .= "\n    key: " . $a['key'];
            $out[] = [$hh, $t];
        }
        return $out;
    };

    if ($type === 'failed') {
        $subject = '[Mail Security] SCAN FAILED ' . $date;
        $html .= '<p>The mail security scan did <b>not</b> run. The state of filters / forwarders is unknown.</p>'
               . '<pre style="white-space:pre-wrap;background:#f5f5f5;padding:8px;">' . $h($d['error']) . '</pre>'
               . '<p>Check on the server: <code>php ~/public_html/hub/modules/security/mail_scan.php --now --dry</code></p>';
        $txt  .= "The mail security scan did NOT run. The state of filters / forwarders is unknown.\n\n" . $d['error']
               . "\n\nCheck on the server: php ~/public_html/hub/modules/security/mail_scan.php --now --dry\n";
        $last = 'MAILSCAN: failed=1';
    } else {
        $c = $d['counts'];
        $n = $c['alerts'];
        if ($type === 'first') {
            $subject = '[Mail Security] FIRST RUN ' . $date . ' – full state, ' . count($d['expected_diff']) . ' differences from the 16 Oct list';
            $html .= '<p>First scan: everything found is listed below and saved as the approved baseline'
                   . ($d['high'] ? ' (except the HIGH items, which stay alerted until approved)' : '') . '. Please check it.</p>';
            $txt  .= "First scan: everything found is listed below and saved as the approved baseline. Please check it.\n";
        } elseif ($type === 'weekly') {
            $subject = '[Mail Security] Weekly ' . $date . ' – ' . ($n ? $n . ' alert' . ($n > 1 ? 's' : '') : 'OK');
        } else {
            $subject = '[Mail Security] ALERT ' . $date . ' – ' . $n . ' change' . ($n > 1 ? 's' : '');
        }

        if ($type === 'first') {
            $sec('Differences from the state approved on 16 Oct 2026');
            $list(array_map(function ($l) use ($h) { return ['<b style="color:#C0211B;">' . $h($l) . '</b>', $l]; }, $d['expected_diff']));
        }
        if ($type !== 'first' || $d['alerts']) {
            $sec('Alerts (' . $n . ($c['high'] ? ', ' . $c['high'] . ' HIGH' : '') . ')');
            $list($alertLines($d['alerts']));
        }

        $sec('Summary');
        $rows = [['Mailboxes', $c['mailboxes']], ['Filters', $c['filters']], ['Forwarders', $c['forwarders']],
                 ['Auto-replies', $c['autoreplies']], ['Catch-all not :fail:', $c['catchall_not_fail']]];
        $html .= '<table cellpadding="4" style="border-collapse:collapse;font-family:Arial,sans-serif;font-size:14px;">';
        foreach ($rows as $r) {
            $html .= '<tr><td style="border:1px solid #ddd;">' . $h($r[0]) . '</td><td style="border:1px solid #ddd;text-align:right;">' . (int)$r[1] . '</td></tr>';
            $txt  .= str_pad($r[0], 22) . $r[1] . "\n";
        }
        $html .= '</table>';

        if ($type !== 'alert') {
            $sec('HIGH items present');
            $list(array_map(function ($i) use ($h) {
                $s = ($i['approved'] ? '[approved] ' : '') . $i['label'] . ' — ' . implode('; ', $i['reasons']);
                return [$h($s), $s . "\n    key: " . $i['key']];
            }, $d['high']));
        }
        if ($type === 'first') {
            $byKind = [];
            foreach ($d['items'] as $k => $i) $byKind[$i['kind']][] = $i['label'];
            foreach (MSS_KIND_LABELS as $kind => $title) {
                $sec('Full state — ' . $title . ' (' . count($byKind[$kind] ?? []) . ')');
                $list(array_map(function ($l) use ($h) { return [$h($l), $l]; }, $byKind[$kind] ?? []));
            }
        } elseif ($type === 'weekly') {
            $sec('Approved baseline (' . count($d['baseline_rows']) . ' items)');
            $list(array_map(function ($r) use ($h) {
                $s = ($r['label'] ?: $r['item_key']) . ' — approved ' . substr($r['approved_at'], 0, 10) . ' (' . $r['approved_by'] . ')';
                return [$h($s), $s];
            }, $d['baseline_rows']));
        }
        if ($d['alerts']) {
            $help = 'To approve an item after checking it, on the server: php ~/public_html/hub/modules/security/mail_scan.php --approve=<key>';
            $html .= '<p style="color:#777;font-size:12px;">' . $h($help) . '</p>';
            $txt  .= "\n" . $help . "\n";
        }
        $last = mss_mailscan_line($c);
    }
    $html = '<div style="font-family:Arial,sans-serif;font-size:14px;color:#222;">' . $html
          . '<p style="color:#999;font-size:12px;">Savannah Explorers Hub — mail security scan (modules/security/mail_scan.php)</p>'
          . '<p style="font-family:monospace;font-size:12px;">' . $h($last) . '</p></div>';
    $txt  = trim($txt) . "\n\n-- \nSavannah Explorers Hub — mail security scan\n" . $last . "\n";
    return [$subject, $html, $txt];
}
