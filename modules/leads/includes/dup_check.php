<?php
/**
 * Shared duplicate detection — the SAME checks used by Incoming/staging and by
 * the live New-Request AJAX (check_duplicate.php / hs_check_duplicates()):
 * name (exact / swapped / Jaro-Winkler / Levenshtein / shared token), email,
 * and phone (last 7 digits), across the requests and lead_staging tables.
 *
 * Used server-side by request_add.php to check BEFORE inserting a new request.
 * Keep the thresholds in sync with check_duplicate.php.
 */

if (!function_exists('dup_normalize')) {
    function dup_normalize(string $s): string {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $s)));
    }
}
if (!function_exists('dup_token_set')) {
    function dup_token_set(string $s): array {
        $t = explode(' ', dup_normalize($s));
        sort($t);
        return $t;
    }
}
if (!function_exists('dup_phone_tail')) {
    function dup_phone_tail(string $s): string {
        return substr(preg_replace('/\D/', '', $s), -7);
    }
}
if (!function_exists('dup_jaro_winkler')) {
    function dup_jaro_winkler(string $s1, string $s2): float {
        $s1 = dup_normalize($s1); $s2 = dup_normalize($s2);
        $l1 = strlen($s1); $l2 = strlen($s2);
        if ($l1 === 0 && $l2 === 0) return 1.0;
        if ($l1 === 0 || $l2 === 0) return 0.0;

        $matchDist = max(floor(max($l1, $l2) / 2) - 1, 0);
        $s1m = array_fill(0, $l1, false);
        $s2m = array_fill(0, $l2, false);
        $matches = 0; $transpositions = 0;

        for ($i = 0; $i < $l1; $i++) {
            $start = max(0, $i - (int)$matchDist);
            $end   = min($i + (int)$matchDist + 1, $l2);
            for ($j = $start; $j < $end; $j++) {
                if ($s2m[$j] || $s1[$i] !== $s2[$j]) continue;
                $s1m[$i] = $s2m[$j] = true; $matches++; break;
            }
        }
        if ($matches === 0) return 0.0;

        $k = 0;
        for ($i = 0; $i < $l1; $i++) {
            if (!$s1m[$i]) continue;
            while (!$s2m[$k]) $k++;
            if ($s1[$i] !== $s2[$k]) $transpositions++;
            $k++;
        }
        $jaro = ($matches / $l1 + $matches / $l2 + ($matches - $transpositions / 2) / $matches) / 3;

        $prefix = 0;
        for ($i = 0; $i < min(4, min($l1, $l2)); $i++) {
            if ($s1[$i] === $s2[$i]) $prefix++; else break;
        }
        return $jaro + $prefix * 0.1 * (1 - $jaro);
    }
}

/**
 * Return likely duplicates for a would-be new request, strongest first.
 * Each candidate: [id, name, source_table('requests'|'lead_staging'),
 *                  severity('definite'|'possible'|'weak'), reason].
 */
function find_duplicate_candidates(PDO $db, string $name, string $email, string $whatsapp, int $excludeId = 0): array {
    $rank  = ['definite' => 0, 'possible' => 1, 'weak' => 2];
    $byKey = [];  // "table:id" => strongest candidate

    $add = function (string $table, $id, string $nm, string $sev, string $reason) use (&$byKey, $rank) {
        $k = $table . ':' . (int)$id;
        if (!isset($byKey[$k]) || $rank[$sev] < $rank[$byKey[$k]['severity']]) {
            $byKey[$k] = ['id' => (int)$id, 'name' => $nm, 'source_table' => $table,
                          'severity' => $sev, 'reason' => $reason];
        }
    };

    // ── Email (exact) ──────────────────────────────────────────────────────────
    $email = trim($email);
    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $q = $excludeId
            ? $db->prepare("SELECT id, customer_name FROM requests WHERE LOWER(email)=LOWER(?) AND id!=?")
            : $db->prepare("SELECT id, customer_name FROM requests WHERE LOWER(email)=LOWER(?)");
        $q->execute($excludeId ? [$email, $excludeId] : [$email]);
        foreach ($q->fetchAll() as $r) $add('requests', $r['id'], $r['customer_name'], 'definite', 'Same email address');
        $s = $db->prepare("SELECT id, customer_name FROM lead_staging WHERE LOWER(email)=LOWER(?)");
        $s->execute([$email]);
        foreach ($s->fetchAll() as $r) $add('lead_staging', $r['id'], $r['customer_name'], 'definite', 'Same email (lead not yet promoted)');
    }

    // ── Phone / WhatsApp (last 7 digits) ────────────────────────────────────────
    $tail = dup_phone_tail($whatsapp);
    if (strlen($tail) >= 7) {
        $q = $excludeId
            ? $db->prepare("SELECT id, customer_name, whatsapp FROM requests WHERE whatsapp IS NOT NULL AND whatsapp!='' AND id!=?")
            : $db->prepare("SELECT id, customer_name, whatsapp FROM requests WHERE whatsapp IS NOT NULL AND whatsapp!=''");
        $q->execute($excludeId ? [$excludeId] : []);
        foreach ($q->fetchAll() as $r) if (dup_phone_tail($r['whatsapp']) === $tail) $add('requests', $r['id'], $r['customer_name'], 'definite', 'Same WhatsApp / phone');
        foreach ($db->query("SELECT id, customer_name, phone FROM lead_staging WHERE phone IS NOT NULL AND phone!=''")->fetchAll() as $r)
            if (dup_phone_tail($r['phone']) === $tail) $add('lead_staging', $r['id'], $r['customer_name'], 'definite', 'Same phone (lead not yet promoted)');
    }

    // ── Name (fuzzy) ────────────────────────────────────────────────────────────
    $name = trim($name);
    if (mb_strlen($name) >= 2) {
        $inputNorm = dup_normalize($name);
        $inputTok  = dup_token_set($name);
        $stmt = $excludeId
            ? $db->prepare("SELECT id, customer_name FROM requests WHERE id!=?")
            : $db->prepare("SELECT id, customer_name FROM requests");
        $stmt->execute($excludeId ? [$excludeId] : []);
        $all = [];
        foreach ($stmt->fetchAll() as $r) { $r['source_table'] = 'requests';     $all[] = $r; }
        foreach ($db->query("SELECT id, customer_name FROM lead_staging")->fetchAll() as $r) { $r['source_table'] = 'lead_staging'; $all[] = $r; }

        foreach ($all as $r) {
            $cand = (string)$r['customer_name'];
            if ($cand === '') continue;
            $cn = dup_normalize($cand);
            $ct = dup_token_set($cand);
            $sev = null; $reason = '';
            if ($cn === $inputNorm)                          { $sev = 'definite'; $reason = 'Identical name'; }
            elseif ($inputTok === $ct)                       { $sev = 'definite'; $reason = 'First and last name swapped'; }
            elseif (dup_jaro_winkler($name, $cand) >= 0.92)  { $sev = 'possible'; $reason = 'Very similar name (possible typo)'; }
            elseif (levenshtein($inputNorm, $cn) <= 2)       { $sev = 'possible'; $reason = 'Very similar name'; }
            else {
                $shared = array_intersect($inputTok, $ct);
                if ($shared && min(mb_strlen($name), mb_strlen($cand)) > 3) { $sev = 'weak'; $reason = 'Similar name'; }
            }
            if ($sev) {
                $rs = $r['source_table'] === 'lead_staging' ? $reason . ' (lead not yet promoted)' : $reason;
                $add($r['source_table'], $r['id'], $cand, $sev, $rs);
            }
        }
    }

    $out = array_values($byKey);
    usort($out, fn($a, $b) => $rank[$a['severity']] <=> $rank[$b['severity']]);
    return $out;
}
