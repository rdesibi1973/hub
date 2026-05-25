<?php
// Temporary: log errors to file for debugging
@ini_set('log_errors', '1');
@ini_set('error_log', dirname(__DIR__, 2) . '/wetu_errors.log');

require_once 'config.php';
requireLogin();

/* ═══════════════════════════════════════════════════════════════
   WETU SOAP HELPER
═══════════════════════════════════════════════════════════════ */
if (!defined('WETU_WSDL')) {
    define('WETU_WSDL', 'https://wetu.com/api/itineraryservicev8.asmx?WSDL');
}

/* Custom SoapClient: uses cURL + UTF-8 sanitize for itinerary calls,
   parent::__doRequest for auth calls (to preserve token bytes intact). */
if (!class_exists('WetuSoapClient')) {
class WetuSoapClient extends SoapClient {
    public function __doRequest($request, $location, $action, $version, $oneWay = 0): ?string {
        /* AuthenticateUser: use parent so the session token is not modified */
        if (strpos($action, 'AuthenticateUser') !== false) {
            return parent::__doRequest($request, $location, $action, $version, $oneWay);
        }
        /* All other calls: fetch via cURL and sanitize response before XML parsing */
        $ch = curl_init($location);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING       => '',        // handle gzip/deflate transparently
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $request,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: text/xml; charset=utf-8',
                'SOAPAction: "' . $action . '"',
                'Content-Length: ' . strlen($request),
            ],
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $response = curl_exec($ch);
        curl_close($ch);
        if ($response === false || $response === '') return null;
        return wetu_utf8_sanitize($response);
    }
}
}

if (!function_exists('wetu_client')) {
function wetu_client() {
    return new WetuSoapClient(WETU_WSDL, [
        'exceptions' => true,
        'trace'      => false,
        'cache_wsdl' => WSDL_CACHE_DISK,
        'encoding'   => 'UTF-8',
    ]);
}
}

/* ═══════════════════════════════════════════════════════════════
   LANGUAGE INFERENCE (Wetu JSON List has no language field)
═══════════════════════════════════════════════════════════════ */
if (!function_exists('infer_language')) {
function infer_language(string $name, $s): string {
    $api = is_array($s) ? trim($s['language'] ?? ($s['Language'] ?? ($s['lang'] ?? ''))) : '';
    if ($api !== '') return $api;
    $n = strtoupper($name);
    if (strpos($n, 'ITALIANO') !== false || strpos($n, 'ITALIAN') !== false) return 'Italian';
    if (strpos($n, 'FRENCH')   !== false || strpos($n, 'FRANCESE') !== false) return 'French';
    if (strpos($n, 'GERMAN')   !== false || strpos($n, 'TEDESCO')  !== false || strpos($n, 'DEUTSCH') !== false) return 'German';
    if (strpos($n, 'SPANISH')  !== false || strpos($n, 'ESPANOL')  !== false || strpos($n, 'SPAGNOLO') !== false) return 'Spanish';
    return 'English';
}
}

/* ═══════════════════════════════════════════════════════════════
   STATE
═══════════════════════════════════════════════════════════════ */
$wetu_error   = '';
$wetu_success = '';
$samples      = [];
$created      = null;
$token        = $_SESSION['wetu_token']    ?? null;
$wetu_user    = $_SESSION['wetu_user']     ?? '';
$wetu_op      = $_SESSION['wetu_operator'] ?? '';
$action       = $_POST['action'] ?? ($_GET['action'] ?? '');

/* Pre-fill from GET params (when arriving from request_view / quote_view) */
$prefill_name = trim($_GET['client_name'] ?? '');
$prefill_ref  = trim($_GET['ref_number']  ?? '');
$prefill_pax  = max(1, intval($_GET['pax'] ?? 2));
$prefill_days = max(0, intval($_GET['days'] ?? 0));
$prefill_date = trim($_GET['start_date']  ?? '');

/* ═══════════════════════════════════════════════════════════════
   ACTION: WETU LOGOUT
═══════════════════════════════════════════════════════════════ */
if ($action === 'wetu_logout') {
    unset($_SESSION['wetu_token'], $_SESSION['wetu_user'], $_SESSION['wetu_operator'], $_SESSION['wetu_pass'], $_SESSION['wetu_samples'], $_SESSION['wetu_search_query'], $_SESSION['wetu_search_lang'], $_SESSION['wetu_search_title_only'], $_SESSION['wetu_search_results']);
    header('Location: wetu.php');
    exit;
}

/* ═══════════════════════════════════════════════════════════════
   ACTION: WETU LOGIN
═══════════════════════════════════════════════════════════════ */
/* ── Recursively sanitize all strings in a SOAP object to valid UTF-8 ──
   Processes byte-by-byte: valid UTF-8 sequences pass through unchanged;
   any invalid byte is treated as CP1252 and converted to UTF-8.
   This handles mixed-encoding strings (partly UTF-8, partly legacy). ── */
if (!function_exists('wetu_utf8_sanitize')) {
function wetu_utf8_sanitize($data) {
    if (is_string($data)) {
        if (mb_check_encoding($data, 'UTF-8')) return $data; // fast path
        $out = '';
        $len = strlen($data);
        for ($i = 0; $i < $len; ) {
            $b = ord($data[$i]);
            if ($b < 0x80) {                          // ASCII — always valid
                $out .= $data[$i++];
                continue;
            }
            // Determine expected UTF-8 sequence length
            if    ($b >= 0xF0 && $b <= 0xF4) $seq = 4;
            elseif ($b >= 0xE0 && $b <= 0xEF) $seq = 3;
            elseif ($b >= 0xC2 && $b <= 0xDF) $seq = 2;
            else                               $seq = 0; // invalid lead byte
            if ($seq > 0 && $i + $seq <= $len) {
                $valid = true;
                for ($j = 1; $j < $seq; $j++) {
                    if ((ord($data[$i + $j]) & 0xC0) !== 0x80) { $valid = false; break; }
                }
                if ($valid) { $out .= substr($data, $i, $seq); $i += $seq; continue; }
            }
            // Invalid byte — convert as CP1252 character
            $conv = @iconv('CP1252', 'UTF-8//IGNORE', $data[$i]);
            if ($conv !== false) $out .= $conv;
            $i++;
        }
        return $out;
    }
    if (is_array($data))  return array_map('wetu_utf8_sanitize', $data);
    if (is_object($data)) {
        foreach (get_object_vars($data) as $k => $v) $data->$k = wetu_utf8_sanitize($v);
        return $data;
    }
    return $data;
}
}

/* ═══════════════════════════════════════════════════════════════
   HELPER: fetch ALL Sample itineraries via JSON REST (paginated)
═══════════════════════════════════════════════════════════════ */
if (!function_exists('wetu_json_get')) {
function wetu_json_get(string $url): ?array {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($raw === false || $err || $code !== 200) return null;
    } else {
        $ctx = stream_context_create(['http' => ['timeout' => 20, 'ignore_errors' => true]]);
        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false) return null;
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) return null;
    // Direct array
    if (empty($decoded) || isset($decoded[0])) return $decoded;
    // Wrapper object — try common keys
    foreach (['Itineraries','itineraries','Items','items','Results','results'] as $k) {
        if (isset($decoded[$k]) && is_array($decoded[$k])) return $decoded[$k];
    }
    return $decoded;
}
}

if (!function_exists('wetu_fetch_samples')) {
function wetu_fetch_samples(string $u, string $p): array {
    $all      = [];
    $pageSize = 200;
    $start    = 0;
    $maxPages = 10;   // safety cap: max 2000 samples

    for ($page = 0; $page < $maxPages; $page++) {
        $url = 'https://wetu.com/API/Itinerary/V8/List?' . http_build_query([
            'username' => $u,
            'password' => $p,
            'type'     => 'Sample',
            'results'  => $pageSize,
            'start'    => $start,
            'sort'     => 'ItineraryNameAsc',
        ]);
        $batch = wetu_json_get($url);
        if ($batch === null || empty($batch)) break;
        $all   = array_merge($all, $batch);
        $start += $pageSize;
        if (count($batch) < $pageSize) break;   // last page — done
    }

    return $all;
}
}

/* ── Search: call V8/List with a search term + optional language ── */
if (!function_exists('wetu_search_samples')) {
function wetu_search_samples(string $u, string $p, string $search, string $lang = ''): array {
    $params = [
        'username' => $u,
        'password' => $p,
        'type'     => 'Sample',
        'results'  => 200,
        'start'    => 0,
        'sort'     => 'ItineraryNameAsc',
    ];
    if ($search !== '') $params['search']   = $search;
    if ($lang   !== '') $params['language'] = $lang;

    $url   = 'https://wetu.com/API/Itinerary/V8/List?' . http_build_query($params);
    $batch = wetu_json_get($url);
    return is_array($batch) ? $batch : [];
}
}

/* ═══════════════════════════════════════════════════════════════
   ACTION: WETU LOGIN  — fetch samples immediately, cache in session
═══════════════════════════════════════════════════════════════ */
if ($action === 'wetu_login') {
    $u = trim($_POST['wetu_username'] ?? '');
    $p = trim($_POST['wetu_password'] ?? '');
    if (!$u || !$p) {
        $wetu_error = 'Please enter both username and password.';
    } else {
        try {
            $res  = wetu_client()->AuthenticateUser(['username' => $u, 'password' => $p]);
            $sess = $res->AuthenticateUserResult ?? null;
            if ($sess && !empty($sess->SessionToken)) {
                $fetched = wetu_fetch_samples($u, $p);
                $_SESSION['wetu_token']    = $sess->SessionToken;
                $_SESSION['wetu_user']     = $u;
                $_SESSION['wetu_pass']     = $p;
                $_SESSION['wetu_operator'] = $sess->OperatorName ?? '';
                $_SESSION['wetu_samples']  = $fetched;
                $token    = $sess->SessionToken;
                $wetu_user = $u;
                $wetu_op   = $sess->OperatorName ?? '';
                $samples   = $fetched;
                if (empty($fetched)) {
                    $wetu_error = 'Logged in, but no Sample itineraries were returned by Wetu.';
                }
            } else {
                $wetu_error = 'Authentication failed — please check your credentials.';
            }
        } catch (SoapFault $e) {
            $wetu_error = 'SOAP error: ' . h($e->getMessage());
        } catch (Throwable $e) {
            $wetu_error = 'Error (' . get_class($e) . '): ' . h($e->getMessage()) . ' [line ' . $e->getLine() . ']';
        }
    }
}

/* ═══════════════════════════════════════════════════════════════
   LOAD SAMPLES FROM SESSION CACHE
═══════════════════════════════════════════════════════════════ */
$wetu_debug = '';


if ($token && !$created && !in_array($action, ['wetu_login', 'wetu_search', 'wetu_clear_search'])) {
    $samples = $_SESSION['wetu_samples'] ?? [];
    if (empty($samples) && $token) {
        $wetu_error = 'No samples in session — please disconnect and sign in again.';
    }
}

/* ═══════════════════════════════════════════════════════════════
   ACTION: SEARCH / FILTER
   - Text query  → Wetu full-text search (finds matches in content too)
   - Language    → PHP filter on results (infer_language is reliable)
   - No query, no lang → restore full cached list
═══════════════════════════════════════════════════════════════ */
if ($action === 'wetu_search' && $token) {
    $q          = trim($_POST['wetu_search_query'] ?? '');
    $lang       = trim($_POST['wetu_search_lang']  ?? '');
    $title_only = !empty($_POST['title_only']);
    $u    = $_SESSION['wetu_user'] ?? '';
    $p    = $_SESSION['wetu_pass'] ?? '';
    if (!$u || !$p) {
        $wetu_error = 'Session expired — please disconnect and sign in again.';
        $samples = $_SESSION['wetu_samples'] ?? [];
    } else {
        try {
            if ($title_only || $q === '') {
                /* Title search or no query: filter the full cached list in PHP */
                $base = $_SESSION['wetu_samples'] ?? [];
                if (empty($base)) {
                    $base = wetu_fetch_samples($u, $p);
                    $_SESSION['wetu_samples'] = $base;
                }
            } else {
                /* Full-text search: let Wetu search content + name */
                $base = wetu_search_samples($u, $p, $q, '');
            }

            /* PHP filters: language + AND word match on name (both modes) */
            $ql = strtolower($q);
            $words = $ql !== '' ? preg_split('/\s+/', $ql, -1, PREG_SPLIT_NO_EMPTY) : [];
            $found = array_values(array_filter($base, function($s) use ($words, $lang) {
                if (!is_array($s)) return false;
                $name = strtolower((string)($s['name'] ?? $s['Name'] ?? $s['itinerary_name'] ?? $s['ItineraryName'] ?? ''));
                /* AND: every word must appear in the name */
                foreach ($words as $word) {
                    if (strpos($name, $word) === false) return false;
                }
                /* Language filter */
                if ($lang !== '') {
                    $slang = infer_language(
                        (string)($s['name'] ?? $s['Name'] ?? $s['itinerary_name'] ?? $s['ItineraryName'] ?? ''),
                        $s
                    );
                    if (strcasecmp($slang, $lang) !== 0) return false;
                }
                return true;
            }));

            $_SESSION['wetu_search_query']      = $q;
            $_SESSION['wetu_search_lang']       = $lang;
            $_SESSION['wetu_search_title_only'] = $title_only;
            $_SESSION['wetu_search_results']    = $found;
            $samples = $found;

            $label_parts = [];
            if ($lang)       $label_parts[] = ucfirst(strtolower($lang));
            if ($q)          $label_parts[] = '"' . $q . '"';
            if ($title_only) $label_parts[] = 'title only';
            $label = implode(', ', $label_parts);
            $base_count = count(array_filter($base, 'is_array'));

            $wetu_success = count($found) . ' sample' . (count($found) !== 1 ? 's' : '') . ' found'
                          . ($label ? ' for ' . h($label) : '')
                          . ' (searched in ' . $base_count . ' samples).';
        } catch (Throwable $e) {
            $wetu_error = 'Search error: ' . h($e->getMessage());
            $samples = $_SESSION['wetu_samples'] ?? [];
        }
    }
}

/* ═══════════════════════════════════════════════════════════════
   ACTION: CLEAR SEARCH — restore full sample list
═══════════════════════════════════════════════════════════════ */
if ($action === 'wetu_clear_search') {
    unset($_SESSION['wetu_search_query'], $_SESSION['wetu_search_lang'], $_SESSION['wetu_search_title_only'], $_SESSION['wetu_search_results']);
    header('Location: wetu.php');
    exit;
}

/* ── Load from session: search results take priority over full list ── */
if ($token && empty($samples)) {
    if (isset($_SESSION['wetu_search_results'])) {
        $samples = $_SESSION['wetu_search_results'];
    } elseif (!in_array($action, ['wetu_login', 'wetu_search'])) {
        $samples = $_SESSION['wetu_samples'] ?? [];
    }
}

$wetu_search_active = (isset($_SESSION['wetu_search_query']) && $_SESSION['wetu_search_query'] !== '')
                   || (isset($_SESSION['wetu_search_lang'])  && $_SESSION['wetu_search_lang']  !== '');
$wetu_search_query  = $_SESSION['wetu_search_query'] ?? '';
$wetu_search_lang   = $_SESSION['wetu_search_lang']  ?? '';

/* ═══════════════════════════════════════════════════════════════
   ACTION: CREATE PERSONAL ITINERARY
═══════════════════════════════════════════════════════════════ */
if ($action === 'create_personal' && $token) {
    $sample_id   = trim($_POST['sample_id']   ?? '');
    $client_name = wetu_utf8_sanitize(trim($_POST['client_name'] ?? ''));
    $ref_number  = wetu_utf8_sanitize(trim($_POST['ref_number']  ?? ''));
    $start_date  = trim($_POST['start_date']  ?? '');
    $days        = max(1, intval($_POST['days'] ?? 0));   // kept for Wetu API (uses sample days if 0 → default 1)
    $pax         = intval($_POST['pax'] ?? 0);
    $language    = trim($_POST['language'] ?? 'en');
    if (!preg_match('/^[a-z]{2}$/', $language)) $language = 'en';

    if (!$sample_id)   $wetu_error = 'Please select a Sample itinerary.';
    elseif (!$client_name) $wetu_error = 'Client Name is required.';
    elseif (!$ref_number)  $wetu_error = 'Reference Number is required.';
    else {
        try {
            $c = wetu_client();

            /* Safe token for SOAP: SoapClient rejects binary bytes as invalid UTF-8.
               Wetu server uses ToByteArray(hexString) — it expects hex, not binary.
               If the stored token is raw binary (base64Binary decoded by PHP SoapClient),
               convert to hex so SoapClient can serialize it and Wetu can parse it. */
            $soap_token = mb_check_encoding($token, 'UTF-8') ? $token : bin2hex($token);

            /* 1 — Load the full Sample */
            try {
                $loaded = $c->LoadItinerary(['identifier' => $sample_id, 'sessionToken' => $soap_token]);
            } catch (SoapFault $sf) {
                if (strpos($sf->getMessage(), 'not a valid utf-8') !== false) {
                    throw new Exception('TOKEN_UTF8_ERROR: ' . $sf->getMessage());
                }
                throw $sf;
            }
            $itinerary = $loaded->LoadItineraryResult;
            if (!$itinerary) throw new Exception('Sample itinerary could not be loaded from Wetu.');

            /* Sanitize pass 1: byte-by-byte (handles mixed encoding strings) */
            $itinerary = wetu_utf8_sanitize($itinerary);

            /* Sanitize pass 2: JSON round-trip with increased depth.
               JSON_INVALID_UTF8_SUBSTITUTE replaces remaining invalid bytes with U+FFFD.
               If json_encode fails for any reason, we stay with pass-1 result. */
            $json = json_encode($itinerary, JSON_INVALID_UTF8_SUBSTITUTE, 2048);
            if ($json !== false && $json !== 'null') {
                $clean = json_decode($json, false, 2048);
                if ($clean !== null) {
                    $itinerary = $clean;
                } else {
                    // json_decode failed — log reason and continue with pass-1 result
                    $wetu_debug = 'json_decode failed after encode (json_last_error: ' . json_last_error() . ')';
                }
            } else {
                $wetu_debug = 'json_encode failed (json_last_error: ' . json_last_error() . ' — ' . json_last_error_msg() . ')';
            }

            /* Debug: list available SOAP methods (shown once in debug panel) */
            if (empty($wetu_debug)) {
                try {
                    $fns = $c->__getFunctions();
                    $wetu_debug = 'SOAP methods: ' . implode(' | ', array_slice($fns, 0, 20));
                } catch (Throwable $ignored) {}
            }

            /* 2 — Rewrite for Personal */
            unset($itinerary->Identifier);
            $itinerary->Type            = 'Personal';
            $itinerary->Name            = $client_name;
            $itinerary->ReferenceNumber = $ref_number;
            $itinerary->Language        = $language;
            if ($start_date) {
                $ts = strtotime($start_date);
                if ($ts) $itinerary->StartDate = date('Y-m-d\TH:i:s', $ts);
            }
            $prev = trim($itinerary->Summary ?? '');
            $itinerary->Summary = ($pax > 0 ? 'Pax: ' . $pax . "\n" : '') . $prev;

            /* 3 — Save (with fallback minimal save on UTF-8 encoding errors) */
            $save_warning = '';
            try {
                $save_res = $c->SaveItinerary(['itinerary' => $itinerary, 'sessionToken' => $soap_token]);
            } catch (SoapFault $sf) {
                if (strpos($sf->getMessage(), 'not a valid utf-8') !== false) {
                    /* Fallback: save a clean minimal itinerary shell without day content */
                    $min = new stdClass();
                    $min->Type            = 'Personal';
                    $min->Name            = $client_name;
                    $min->ReferenceNumber = $ref_number;
                    $min->Language        = $language;
                    if ($start_date) {
                        $ts = strtotime($start_date);
                        if ($ts) $min->StartDate = date('Y-m-d\TH:i:s', $ts);
                    }
                    if ($pax > 0) $min->Summary = 'Pax: ' . $pax;
                    $save_res = $c->SaveItinerary(['itinerary' => $min, 'sessionToken' => $soap_token]);
                    $save_warning = 'Note: sample content could not be copied due to special characters in the itinerary text. An empty personal itinerary was created — please add content manually in Wetu.';
                } else {
                    throw $sf;
                }
            }

            $summary  = $save_res->SaveItineraryResult;
            $new_id   = $summary->Identifier    ?? null;
            $short_id = $summary->IdentifierKey ?? null;
            $cons_key = $summary->ConsultantKey ?? null;

            if (!$new_id) throw new Exception('Itinerary saved but no identifier returned by Wetu.');

            $view_url = 'https://wetu.com/Itinerary/' . ($short_id ?: $new_id)
                      . ($cons_key ? '?key=' . urlencode($cons_key) : '');
            $edit_url = 'https://dashboard.wetu.com/ItineraryBuilder/Personal';

            $created = [
                'name'         => $client_name,
                'ref'          => $ref_number,
                'pax'          => $pax,
                'days'         => $days,
                'language'     => strtoupper($language),
                'start_date'   => $start_date,
                'view_url'     => $view_url,
                'edit_url'     => $edit_url,
                'short_id'     => $short_id,
                'save_warning' => $save_warning,
            ];
            $wetu_success = $save_warning
                ? '⚠️ Personal itinerary created (empty shell — content not copied).'
                : 'Personal itinerary created successfully.';

        } catch (SoapFault $e) {
            $tok_info = ' token-hex:' . bin2hex(substr($token ?? '', 0, 16)) . ' utf8:' . (mb_check_encoding($token ?? '', 'UTF-8') ? 'ok' : 'BAD');
            $wetu_error = 'Wetu API error: ' . h($e->getMessage()) . ' &nbsp;<small style="color:#888">[identifier: <code>' . h($sample_id) . '</code>' . h($tok_info) . ']</small>';

        } catch (Exception $e) {
            $wetu_error = h($e->getMessage()) . ' &nbsp;<small style="color:#888">[identifier sent: <code>' . h($sample_id) . '</code>]</small>';
        }
    }
}

/* ─── Build JS samples array + language list ─── */
$samples_js_arr = [];
$lang_set = [];


foreach ($samples as $s) {
    if (!is_array($s)) continue;  // skip wrapper fields (total, page, etc.)
    /* LoadItinerary expects the short alphanumeric key (identifier_key),
       NOT the UUID (identifier field which is the internal DB id) */
    $sid   = $s['identifier_key'] ?? ($s['IdentifierKey'] ??
             ($s['short_id']      ?? ($s['ShortId']       ??
             ($s['identifier']    ?? ($s['Identifier']    ??
             ($s['itinerary_id']  ?? ($s['id']            ?? '')))))));
    $sname = $s['name']        ?? ($s['itinerary_name'] ?? ($s['Name'] ?? ($s['ItineraryName'] ?? 'Unnamed')));
    $sdays = intval($s['days'] ?? ($s['Days'] ?? 0));
    $slang = infer_language((string)$sname, $s);
    if ($sid) {
        $samples_js_arr[] = ['id' => $sid, 'name' => $sname, 'days' => $sdays, 'lang' => $slang];
        $lang_set[$slang] = true;
    }
}
ksort($lang_set);
$languages    = array_keys($lang_set);
$samples_json = json_encode($samples_js_arr, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);

/* ─── Page header ─── */
$pageTitle  = 'Wetu Itinerary Builder';
$extra_css  = '
/* ── WETU PAGE EXTRAS ── */
:root { --wetu: #1E4D7B; --wetu-lt: #E5EFF7; }

.wetu-session-bar {
  background: var(--wetu-lt); border: 1px solid #b8d0e8;
  border-radius: 8px; padding: 10px 16px;
  display: flex; align-items: center; gap: 10px;
  margin-bottom: 22px; font-size: .8rem;
}
.wetu-session-bar .dot { width:8px;height:8px;border-radius:50%;background:#27ae60;flex-shrink:0; }
.wetu-session-bar strong { color: var(--wetu); }
.wetu-session-bar .sep  { color: var(--grey-lt); margin: 0 4px; }

.wetu-alert {
  padding: 11px 16px; border-radius: 7px;
  font-size: .82rem; font-weight: 600;
  margin-bottom: 20px;
  display: flex; align-items: flex-start; gap: 9px;
}
.wetu-alert-error   { background: var(--red-lt);  color: var(--red-dk); border-left: 4px solid var(--red); }
.wetu-alert-success { background: var(--green-lt); color: var(--green);  border-left: 4px solid var(--green); }

.wetu-login-wrap { max-width: 400px; }
.wetu-brand-badge {
  display: inline-flex; align-items: center; gap: 10px;
  background: var(--wetu-lt); border: 1px solid #b8d0e8;
  border-radius: 10px; padding: 10px 18px;
  margin-bottom: 20px;
}
.wetu-brand-badge span { font-family: "Merriweather",serif; font-size:1rem; font-weight:700; color:var(--wetu); }
.login-note { font-size:.72rem; color:var(--grey-mid); margin-top:14px; line-height:1.5; }

.lang-toggle {
  display: flex; border: 1.5px solid var(--grey-lt);
  border-radius: 6px; overflow: hidden; height:38px;
}
.lang-toggle input[type="radio"] { display: none; }
.lang-toggle label {
  flex:1; display:flex; align-items:center; justify-content:center;
  font-size:.82rem; font-weight:700; letter-spacing:.06em;
  text-transform:uppercase; cursor:pointer;
  background:var(--white); color:var(--grey-mid);
  transition:background .15s, color .15s; padding: 0 14px;
}
.lang-toggle label:first-of-type { border-right: 1.5px solid var(--grey-lt); }
.lang-toggle input:checked + label { background: var(--wetu); color: var(--white); }

.field-hint { font-size:.68rem; color:var(--grey-mid); margin-top:3px; }
.sample-count { font-size:.7rem; color:var(--grey-mid); font-weight:600; }

/* Result card */
.result-card { background:var(--white); border-radius:10px; border:2px solid #9dd4aa; box-shadow:0 2px 14px rgba(46,107,62,.1); overflow:hidden; margin-bottom:22px; }
.result-card-hd { background:var(--green-lt); padding:14px 22px; display:flex; align-items:center; gap:10px; }
.result-card-hd h3 { font-family:"Merriweather",serif; font-size:.95rem; font-weight:700; color:var(--green); }
.result-card-bd { padding: 22px; }
.result-meta { display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-bottom:20px; }
.meta-lbl { font-size:.67rem; font-weight:700; text-transform:uppercase; letter-spacing:.1em; color:var(--grey-mid); margin-bottom:2px; }
.meta-val { font-size:.86rem; font-weight:600; color:var(--black); }
.result-link-row { display:flex; align-items:center; gap:10px; background:var(--off-white); border-radius:7px; padding:10px 14px; margin-bottom:8px; }
.result-link-row .lbl { font-size:.7rem; font-weight:700; text-transform:uppercase; letter-spacing:.08em; color:var(--grey-mid); min-width:90px; flex-shrink:0; }
.result-link-row a { font-size:.8rem; color:var(--wetu); text-decoration:none; font-weight:600; word-break:break-all; }
.result-link-row a:hover { text-decoration:underline; }
.copy-btn { margin-left:auto; flex-shrink:0; background:var(--white); border:1.5px solid var(--grey-lt); border-radius:5px; padding:3px 10px; font-size:.7rem; font-weight:600; color:var(--grey-dk); cursor:pointer; transition:all .15s; }
.copy-btn:hover { border-color:var(--wetu); color:var(--wetu); }
.copy-btn.copied { border-color:var(--green); color:var(--green); }

.btn-wetu { background:#1E4D7B !important; color:#fff !important; }
.btn-wetu:hover { background:#163d63 !important; }

/* Ensure anchor .btn elements look like buttons, not links */
a.btn { text-decoration: none !important; }
a.btn-secondary { color: var(--grey-dk) !important; }
a.btn-wetu      { color: #fff !important; }

/* Disconnect button */
.btn-disconnect {
  font-family: inherit; font-size: .75rem; font-weight: 700;
  letter-spacing: .04em; text-transform: uppercase;
  padding: 5px 14px; border-radius: 5px; cursor: pointer;
  background: #fff; color: #C0211B;
  border: 1.5px solid #C0211B;
  transition: background .15s;
}
.btn-disconnect:hover { background: #FAE8E7; }


/* Inline refresh form */
.refresh-form {
  background: #E5EFF7; border: 1px solid #b8d0e8;
  border-radius: 7px; padding: 10px 16px;
  margin-bottom: 12px;
}
';
include __DIR__ . '/includes/header.php';
?>

<div style="max-width:740px;">

<!-- Page title -->
<div class="page-title" style="margin-bottom:22px;">
  🗺️ Wetu Itinerary Builder
  <?php if ($token): ?>
    <span class="sample-count" style="margin-left:auto;font-size:.75rem;">
      <?= count($samples_js_arr) ?> sample<?= count($samples_js_arr) !== 1 ? 's' : '' ?> available
    </span>
  <?php endif; ?>
</div>

<?php if ($wetu_error): ?>
<div class="wetu-alert wetu-alert-error">⚠️ <?= $wetu_error ?>
<?php if ($wetu_debug): ?><br><small style="font-family:monospace;font-weight:400;"><?= $wetu_debug ?></small><?php endif; ?>
</div>
<?php endif; ?>

<?php if ($wetu_success && !$created): ?>
<div class="wetu-alert wetu-alert-success">✅ <?= $wetu_success ?></div>
<?php endif; ?>


<?php /* ════ NOT LOGGED IN ════ */ if (!$token): ?>

<div class="wetu-login-wrap">
  <div class="wetu-brand-badge">
    <span style="font-size:1.3rem;">🌍</span>
    <span>Wetu</span>
  </div>

  <div class="card">
    <div class="card-header">
      <h2>🔐 Sign in to Wetu</h2>
      <span style="font-size:.75rem;color:var(--grey-mid);">Use your personal Wetu credentials</span>
    </div>
    <div class="card-body">
      <form method="POST" action="wetu.php" autocomplete="off">
        <input type="hidden" name="action" value="wetu_login">

        <div class="form-group">
          <label class="form-label" for="wetu_username">Username</label>
          <input class="form-control" type="text" id="wetu_username" name="wetu_username"
                 placeholder="Your Wetu username" autocomplete="username" required>
        </div>

        <div class="form-group">
          <label class="form-label" for="wetu_password">Password</label>
          <input class="form-control" type="password" id="wetu_password" name="wetu_password"
                 placeholder="Your Wetu password" autocomplete="current-password" required>
        </div>

        <div class="form-actions" style="border:none;padding-top:0;margin-top:0;">
          <button type="submit" style="font-family:inherit;font-size:.85rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;padding:10px 22px;border:none;border-radius:6px;cursor:pointer;background:#C0211B;color:#fff;transition:background .15s;">
            🔓 Connect to Wetu
          </button>
        </div>
      </form>
      <p class="login-note">
        Your password is transmitted securely over HTTPS and is never stored.<br>
        Each team member uses their own Wetu account.
      </p>
    </div>
  </div>
</div>


<?php /* ════ RESULT ════ */ elseif ($created): ?>

<!-- Session bar -->
<div class="wetu-session-bar">
  <span class="dot"></span>
  Connected as <strong><?= h($wetu_user) ?></strong>
  <?php if ($wetu_op): ?><span class="sep">|</span><?= h($wetu_op) ?><?php endif; ?>
  <div style="margin-left:auto;display:flex;gap:8px;align-items:center;">
    <form method="POST" action="wetu.php">
      <input type="hidden" name="action" value="wetu_logout">
      <button type="submit" class="btn-disconnect">⏏ Disconnect</button>
    </form>
  </div>
</div>

<div class="result-card">
  <div class="result-card-hd">
    <span style="font-size:1.3rem;">✅</span>
    <h3>Personal Itinerary Created</h3>
  </div>
    <div class="result-card-bd">

    <?php if (!empty($created['save_warning'])): ?>
    <div style="background:#fff3cd;border:1px solid #ffc107;border-radius:6px;padding:10px 14px;margin-bottom:14px;font-size:.82rem;color:#856404;">
      ⚠️ <?= h($created['save_warning']) ?>
    </div>
    <?php endif; ?>

    <div class="result-meta">
      <div><div class="meta-lbl">Client Name</div><div class="meta-val"><?= h($created['name']) ?></div></div>
      <div><div class="meta-lbl">Reference Number</div><div class="meta-val"><?= h($created['ref']) ?></div></div>
      <div><div class="meta-lbl">Duration</div><div class="meta-val"><?= $created['days'] ?> days</div></div>
      <div><div class="meta-lbl">Pax</div><div class="meta-val"><?= $created['pax'] ?></div></div>
      <?php if ($created['start_date']): ?>
      <div><div class="meta-lbl">Start Date</div><div class="meta-val"><?= h(date('d M Y', strtotime($created['start_date']))) ?></div></div>
      <?php endif; ?>
      <div><div class="meta-lbl">Language</div><div class="meta-val"><?= $created['language'] ?></div></div>
    </div>

    <div class="form-actions" style="margin-top:16px;">
      <?php if ($created['edit_url']): ?>
      <a href="<?= h($created['edit_url']) ?>" target="_blank" style="font-family:inherit;font-size:.85rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;padding:9px 18px;border-radius:6px;background:#C0211B;color:#fff;text-decoration:none;display:inline-block;">🗺️ OPEN WETU</a>
      <?php endif; ?>
      <a href="wetu.php" style="font-family:inherit;font-size:.85rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;padding:9px 18px;border-radius:6px;background:#E8E8E8;color:#444;text-decoration:none;display:inline-block;">＋ Create Another</a>
    </div>

  </div>
</div>


<?php /* ════ MAIN FORM ════ */ else: ?>

<!-- Session bar -->
<div class="wetu-session-bar">
  <span class="dot"></span>
  Connected as <strong><?= h($wetu_user) ?></strong>
  <?php if ($wetu_op): ?><span class="sep">|</span><?= h($wetu_op) ?><?php endif; ?>
  <span style="font-size:.7rem;color:#888;margin-left:8px;"><?= count($samples_js_arr) ?> samples</span>
  <div style="margin-left:auto;display:flex;gap:8px;align-items:center;">
    <form method="POST" action="wetu.php">
      <input type="hidden" name="action" value="wetu_logout">
      <button type="submit" class="btn-disconnect">⏏ Disconnect</button>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header">
    <h2>📋 Create Personal Itinerary</h2>
    <span style="font-size:.75rem;color:var(--grey-mid);">Copy a Sample as Personal and customise it for the client</span>
  </div>
  <div class="card-body">

      <!-- FILTER WETU SAMPLE PROGRAMS — standalone form (must not be nested inside create-form) -->
      <div class="form-group" style="margin-bottom:14px;">
        <label class="form-label" style="font-weight:700;letter-spacing:.04em;">FILTER WETU SAMPLE PROGRAMS</label>
        <form method="post" action="wetu.php" style="display:flex;flex-direction:column;gap:8px;" id="wetu-search-form">
          <input type="hidden" name="action" value="wetu_search">

          <!-- Row 1: Language -->
          <div style="display:flex;gap:8px;align-items:center;">
            <select name="wetu_search_lang" id="wetu_search_lang" class="form-control" style="max-width:200px;">
              <option value="">All languages</option>
              <option value="English"  <?= ($wetu_search_lang === 'English'  ? 'selected' : '') ?>>English</option>
              <option value="Italian"  <?= ($wetu_search_lang === 'Italian'  ? 'selected' : '') ?>>Italian</option>
              <option value="German"   <?= ($wetu_search_lang === 'German'   ? 'selected' : '') ?>>German</option>
              <option value="Spanish"  <?= ($wetu_search_lang === 'Spanish'  ? 'selected' : '') ?>>Spanish</option>
              <option value="French"   <?= ($wetu_search_lang === 'French'   ? 'selected' : '') ?>>French</option>
            </select>
          </div>

          <!-- Row 2: Text search + button — full width to match sample dropdown -->
          <div style="display:flex;gap:8px;align-items:center;">
            <input type="text" name="wetu_search_query" id="wetu_search_query"
                   class="form-control"
                   placeholder="e.g. Serengeti, 10 days, beach…"
                   value="<?= h($wetu_search_query) ?>"
                   autocomplete="off"
                   style="flex:1;">
            <button type="submit" style="padding:8px 20px;background:#C0211B;color:#fff;border:none;border-radius:6px;font-weight:700;cursor:pointer;white-space:nowrap;flex-shrink:0;">🔍 Search Wetu</button>
          </div>
        </form>

        <?php if ($wetu_search_active): ?>
          <?php
            $badge_parts = [];
            if ($wetu_search_lang)  $badge_parts[] = ucfirst(strtolower($wetu_search_lang));
            if ($wetu_search_query) $badge_parts[] = '"' . h($wetu_search_query) . '"';
            $badge_label = implode(', ', $badge_parts);
          ?>
          <div style="margin-top:6px;font-size:.8rem;color:#1E4D7B;font-weight:600;">
            Showing <?= count($samples_js_arr) ?> result<?= count($samples_js_arr) !== 1 ? 's' : '' ?>
            <?= $badge_label ? 'for: <em>' . $badge_label . '</em>' : '' ?>
          </div>
        <?php endif; ?>
      </div>

    <form method="POST" action="wetu.php" id="create-form">
      <input type="hidden" name="action" value="create_personal">

      <!-- Sample dropdown -->
      <div class="form-group">
        <label class="form-label">Base Sample Programme <span style="color:#C0211B">*</span></label>

        <!-- Main dropdown (populated by JS) -->
        <select class="form-control" id="sample_id" name="sample_id"
                <?= empty($samples_js_arr) ? 'disabled' : 'required' ?>
                onchange="onSampleChange(this)">
          <option value=""><?= empty($samples_js_arr) ? '— No samples loaded —' : '— Select a Sample —' ?></option>
        </select>
        <div class="field-hint" id="sample_count_hint">
          <?php if (empty($samples_js_arr) && $wetu_debug): ?>
            <details style="margin-top:6px;">
              <summary style="cursor:pointer;color:#C0211B;font-weight:700;">▶ Show Wetu API response (debug)</summary>
              <pre style="font-size:.68rem;background:#f5f5f5;padding:8px;border-radius:4px;margin-top:4px;white-space:pre-wrap;word-break:break-all;"><?= $wetu_debug ?></pre>
            </details>
          <?php elseif (empty($samples_js_arr)): ?>
            No samples returned — try disconnecting and reconnecting.
          <?php endif; ?>
        </div>
      </div>

      <!-- Client Name + Reference -->
      <div class="form-row">
        <div class="form-group">
          <label class="form-label" for="client_name">Client Name <span style="color:var(--red)">*</span></label>
          <input class="form-control" type="text" id="client_name" name="client_name"
                 placeholder="e.g. Smith Family"
                 value="<?= h($_POST['client_name'] ?? $prefill_name) ?>" required>
        </div>
        <div class="form-group">
          <label class="form-label" for="ref_number">Reference Number <span style="color:var(--red)">*</span></label>
          <input class="form-control" type="text" id="ref_number" name="ref_number"
                 placeholder="e.g. SmithFamily-2026-01"
                 value="<?= h($_POST['ref_number'] ?? $prefill_ref) ?>" required>
          <div class="field-hint">This is the Excel filename when downloaded from Wetu</div>
        </div>
      </div>

      <!-- Start Date + Pax -->
      <div class="form-row" style="grid-template-columns:1fr 1fr;">
        <div class="form-group">
          <label class="form-label" for="start_date">Start Date</label>
          <input class="form-control" type="date" id="start_date" name="start_date"
                 value="<?= h($_POST['start_date'] ?? $prefill_date) ?>">
        </div>
        <div class="form-group">
          <label class="form-label" for="pax">Pax</label>
          <input class="form-control" type="number" id="pax" name="pax"
                 min="1" max="99"
                 value="<?= intval($_POST['pax'] ?? $prefill_pax) ?: '' ?>"
                 placeholder="Optional">
        </div>
      </div>

      <!-- Language: auto-set from selected Sample -->
      <input type="hidden" name="language" id="hidden_language" value="en">

      <div class="form-actions">
        <button type="submit" id="submit-btn"
                <?= empty($samples_js_arr) ? 'disabled' : '' ?>
                style="font-family:inherit;font-size:.85rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;padding:10px 22px;border:none;border-radius:6px;cursor:pointer;background:#C0211B;color:#fff;transition:background .15s;">
          🗺️ Create Personal Itinerary
        </button>
        <a href="wetu.php" style="font-family:inherit;font-size:.85rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;padding:10px 20px;border-radius:6px;background:#E8E8E8;color:#444;text-decoration:none;display:inline-block;">Reset</a>
      </div>

    </form>
  </div>
</div>

<?php endif; ?>

</div><!-- /max-width -->

<?php include __DIR__ . '/includes/footer.php'; ?>

<script>
const allSamples = <?= $samples_json ?>;

/* Language display names for the filter LOV */
const langNames = {
    en:'English', it:'Italian', de:'German', es:'Spanish', fr:'French',
    english:'English', italian:'Italian', german:'German', spanish:'Spanish', french:'French'
};

/* ── Build language LOV dynamically from actual sample data ── */

function buildLangLOV() {
    const sel = document.getElementById('filter_lang');
    if (!sel || allSamples.length === 0) return;

    // Collect unique raw lang values
    const seen = {};
    allSamples.forEach(s => { if (s.lang) seen[s.lang] = true; });

    // Sort by display name
    const langs = Object.keys(seen).sort((a, b) => {
        return (langNames[a.toLowerCase()] || a).localeCompare(langNames[b.toLowerCase()] || b);
    });

    // Rebuild options (keep current selection)
    const prev = sel.value;
    sel.innerHTML = '<option value="">All languages</option>';
    langs.forEach(raw => {
        const opt = document.createElement('option');
        opt.value = raw;                                      // exact value from API
        opt.textContent = langNames[raw.toLowerCase()] || raw;
        if (raw === prev) opt.selected = true;
        sel.appendChild(opt);
    });
}

/* ── Populate dropdown on load ── */
document.addEventListener('DOMContentLoaded', function() {
    buildLangLOV();
    filterSamples();
});

function filterSamples() {
    const langRaw = document.getElementById('filter_lang')?.value || '';
    const search  = (document.getElementById('search_sample')?.value || '').toLowerCase().trim();
    const sel     = document.getElementById('sample_id');
    if (!sel) return;

    const prev = sel.value;
    sel.innerHTML = '<option value="">— Select a Sample —</option>';

    let count = 0;
    allSamples.forEach(s => {
        // Language filter: exact match on raw value from API
        if (langRaw && s.lang !== langRaw) return;
        // Name filter: case-insensitive contains
        if (search && !s.name.toLowerCase().includes(search)) return;

        const langTag = {'english':'EN','italian':'IT','german':'DE','spanish':'ES','french':'FR'};
        const opt = document.createElement('option');
        opt.value = s.id;
        opt.dataset.days = s.days;
        opt.dataset.lang = s.lang || '';
        const rawLang = (s.lang || '').toLowerCase();
        const tag = rawLang ? (langTag[rawLang] || rawLang.substring(0,2).toUpperCase()) : '';
        opt.textContent = (tag ? `[${tag}] ` : '') + s.name + (s.days ? ` (${s.days}d)` : '');
        if (s.id === prev) opt.selected = true;
        sel.appendChild(opt);
        count++;
    });

    const hint = document.getElementById('sample_count_hint');
    if (hint) hint.textContent = count === allSamples.length
        ? `${count} samples available`
        : `${count} of ${allSamples.length} samples shown`;
}

function onSampleChange(sel) {
    const opt  = sel.options[sel.selectedIndex];
    const days = parseInt(opt?.dataset.days || '0', 10);
    // Auto-set hidden language from sample
    const raw  = (opt?.dataset.lang || 'en').toLowerCase();
    const langMap = {english:'en', italian:'it', german:'de', spanish:'es', french:'fr'};
    const lang = langMap[raw] || (raw.length === 2 ? raw : raw.substring(0,2)) || 'en';
    const hl   = document.getElementById('hidden_language');
    if (hl) hl.value = lang;
}

function copyLink(elId, btn) {
    const el = document.getElementById(elId);
    copyText(el.href || el.textContent.trim(), btn);
}
function copyText(txt, btn) {
    navigator.clipboard.writeText(txt).then(() => {
        const orig = btn.textContent;
        btn.textContent = '✓ Copied';
        btn.classList.add('copied');
        setTimeout(() => { btn.textContent = orig; btn.classList.remove('copied'); }, 1800);
    });
}

const form = document.getElementById('create-form');
if (form) {
    form.addEventListener('submit', function () {
        const btn = document.getElementById('submit-btn');
        if (btn) { btn.disabled = true; btn.textContent = '⏳ Creating…'; }
    });
}
</script>
