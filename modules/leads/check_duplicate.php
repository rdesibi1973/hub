<?php
require_once 'config.php';
header('Content-Type: application/json');

$name       = trim($_GET['name']       ?? '');
$email      = trim($_GET['email']      ?? '');
$whatsapp   = trim($_GET['whatsapp']   ?? '');
$exclude_id = (int)($_GET['exclude_id'] ?? 0);

// Last-7-digits comparison, matching hs_check_duplicates() in hubspot_sync.php.
// Normalization happens in PHP because MySQL 5.7 has no REGEXP_SUBSTR.
function phoneTail(string $s): string {
    return substr(preg_replace('/\D/', '', $s), -7);
}

// ── Email-only check ─────────────────────────────────────────────────────────
if ($email !== '' && $name === '') {
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { echo json_encode([]); exit; }
    $db = db();
    $sql = $exclude_id
        ? "SELECT id, customer_name, email FROM requests WHERE LOWER(email) = LOWER(?) AND id != ?"
        : "SELECT id, customer_name, email FROM requests WHERE LOWER(email) = LOWER(?)";
    $params = $exclude_id ? [$email, $exclude_id] : [$email];
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    $results = [];
    foreach ($rows as $row) {
        $results[] = [
            'id'           => $row['id'],
            'name'         => $row['customer_name'],
            'email'        => $row['email'],
            'level'        => 'email',
            'reason'       => 'Same email address',
            'source_table' => 'requests',
        ];
    }
    $st = $db->prepare("SELECT id, customer_name, email FROM lead_staging WHERE LOWER(email) = LOWER(?)");
    $st->execute([$email]);
    foreach ($st->fetchAll() as $row) {
        $results[] = [
            'id'           => $row['id'],
            'name'         => $row['customer_name'],
            'email'        => $row['email'],
            'level'        => 'email',
            'reason'       => 'Same email address (lead not yet promoted)',
            'source_table' => 'lead_staging',
        ];
    }
    echo json_encode($results);
    exit;
}

// ── WhatsApp-only check ──────────────────────────────────────────────────────
if ($whatsapp !== '' && $name === '') {
    $tail = phoneTail($whatsapp);
    if (strlen($tail) < 7) { echo json_encode([]); exit; }
    $db = db();
    $results = [];

    $sql = $exclude_id
        ? "SELECT id, customer_name, whatsapp FROM requests WHERE whatsapp IS NOT NULL AND whatsapp != '' AND id != ?"
        : "SELECT id, customer_name, whatsapp FROM requests WHERE whatsapp IS NOT NULL AND whatsapp != ''";
    $stmt = $db->prepare($sql);
    $stmt->execute($exclude_id ? [$exclude_id] : []);
    foreach ($stmt->fetchAll() as $row) {
        if (phoneTail($row['whatsapp']) !== $tail) continue;
        $results[] = [
            'id'           => $row['id'],
            'name'         => $row['customer_name'],
            'whatsapp'     => $row['whatsapp'],
            'level'        => 'whatsapp',
            'reason'       => 'Same WhatsApp / phone number',
            'source_table' => 'requests',
        ];
    }

    $st = $db->query("SELECT id, customer_name, phone FROM lead_staging WHERE phone IS NOT NULL AND phone != ''");
    foreach ($st->fetchAll() as $row) {
        if (phoneTail($row['phone']) !== $tail) continue;
        $results[] = [
            'id'           => $row['id'],
            'name'         => $row['customer_name'],
            'whatsapp'     => $row['phone'],
            'level'        => 'whatsapp',
            'reason'       => 'Same WhatsApp / phone number (lead not yet promoted)',
            'source_table' => 'lead_staging',
        ];
    }

    echo json_encode(array_slice($results, 0, 5));
    exit;
}

if (strlen($name) < 2) { echo json_encode([]); exit; }

$db = db();
$sql = $exclude_id
    ? "SELECT id, customer_name FROM requests WHERE id != ?"
    : "SELECT id, customer_name FROM requests";
$stmt = $db->prepare($sql);
$stmt->execute($exclude_id ? [$exclude_id] : []);
$all = [];
foreach ($stmt->fetchAll() as $row) {
    $row['source_table'] = 'requests';
    $all[] = $row;
}
foreach ($db->query("SELECT id, customer_name FROM lead_staging")->fetchAll() as $row) {
    $row['source_table'] = 'lead_staging';
    $all[] = $row;
}

function normalize(string $s): string {
    return mb_strtolower(trim(preg_replace('/\s+/', ' ', $s)));
}

function tokenSet(string $s): array {
    $tokens = explode(' ', normalize($s));
    sort($tokens);
    return $tokens;
}

// Jaro-Winkler similarity (0-1)
function jaroWinkler(string $s1, string $s2): float {
    $s1 = normalize($s1); $s2 = normalize($s2);
    $l1 = strlen($s1); $l2 = strlen($s2);
    if ($l1 === 0 && $l2 === 0) return 1.0;
    if ($l1 === 0 || $l2 === 0) return 0.0;

    $matchDist = max(floor(max($l1,$l2)/2)-1, 0);
    $s1m = array_fill(0,$l1,false);
    $s2m = array_fill(0,$l2,false);
    $matches = 0; $transpositions = 0;

    for ($i=0;$i<$l1;$i++) {
        $start = max(0,$i-(int)$matchDist);
        $end   = min($i+(int)$matchDist+1,$l2);
        for ($j=$start;$j<$end;$j++) {
            if ($s2m[$j] || $s1[$i]!==$s2[$j]) continue;
            $s1m[$i]=$s2m[$j]=true; $matches++; break;
        }
    }
    if ($matches === 0) return 0.0;

    $k=0;
    for ($i=0;$i<$l1;$i++) {
        if (!$s1m[$i]) continue;
        while (!$s2m[$k]) $k++;
        if ($s1[$i]!==$s2[$k]) $transpositions++;
        $k++;
    }
    $jaro = ($matches/$l1 + $matches/$l2 + ($matches-$transpositions/2)/$matches)/3;

    // Winkler prefix bonus
    $prefix = 0;
    for ($i=0;$i<min(4,min($l1,$l2));$i++) {
        if ($s1[$i]===$s2[$i]) $prefix++; else break;
    }
    return $jaro + $prefix * 0.1 * (1-$jaro);
}

$inputNorm   = normalize($name);
$inputTokens = tokenSet($name);
$results = [];

foreach ($all as $row) {
    $candidate     = $row['customer_name'];
    $candNorm      = normalize($candidate);
    $candTokens    = tokenSet($candidate);

    $level  = null;
    $reason = '';

    // 1. Exact match (normalized)
    if ($candNorm === $inputNorm) {
        $level = 'high'; $reason = 'Identical name';

    // 2. Same tokens, different order (e.g. "Mario Rossi" vs "Rossi Mario")
    } elseif ($inputTokens === $candTokens) {
        $level = 'high'; $reason = 'First and last name swapped';

    // 3. Jaro-Winkler ≥ 0.92
    } elseif (jaroWinkler($name, $candidate) >= 0.92) {
        $level = 'medium'; $reason = 'Very similar name (possible typo)';

    // 4. Levenshtein ≤ 2
    } elseif (levenshtein($inputNorm, $candNorm) <= 2) {
        $level = 'medium'; $reason = 'Very similar name';

    // 5. At least one token matches exactly
    } else {
        $shared = array_intersect($inputTokens, $candTokens);
        if ($shared && min(strlen($name), strlen($candidate)) > 3) {
            $level = 'low'; $reason = 'Similar name';
        }
    }

    if ($level) {
        $results[] = [
            'id'           => $row['id'],
            'name'         => $candidate,
            'level'        => $level,
            'reason'       => $row['source_table'] === 'lead_staging'
                                ? $reason . ' (lead not yet promoted)'
                                : $reason,
            'source_table' => $row['source_table'],
        ];
    }
}

// Sort: high first, then medium, then low
usort($results, fn($a,$b) => ['high'=>0,'medium'=>1,'low'=>2][$a['level']] <=> ['high'=>0,'medium'=>1,'low'=>2][$b['level']]);

echo json_encode(array_slice($results, 0, 5)); // max 5 results
