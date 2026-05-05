<?php
require_once 'config.php';
header('Content-Type: application/json');

$name       = trim($_GET['name']       ?? '');
$exclude_id = (int)($_GET['exclude_id'] ?? 0);

if (strlen($name) < 2) { echo json_encode([]); exit; }

$db = db();
$sql = $exclude_id
    ? "SELECT id, customer_name FROM requests WHERE id != ?"
    : "SELECT id, customer_name FROM requests";
$stmt = $db->prepare($sql);
$stmt->execute($exclude_id ? [$exclude_id] : []);
$all = $stmt->fetchAll();

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
        $level = 'high'; $reason = 'Nome identico';

    // 2. Same tokens, different order (e.g. "Mario Rossi" vs "Rossi Mario")
    } elseif ($inputTokens === $candTokens) {
        $level = 'high'; $reason = 'Nome e cognome invertiti';

    // 3. Jaro-Winkler ≥ 0.92
    } elseif (jaroWinkler($name, $candidate) >= 0.92) {
        $level = 'medium'; $reason = 'Nome molto simile (probabile errore di battitura)';

    // 4. Levenshtein ≤ 2
    } elseif (levenshtein($inputNorm, $candNorm) <= 2) {
        $level = 'medium'; $reason = 'Nome molto simile';

    // 5. At least one token matches exactly
    } else {
        $shared = array_intersect($inputTokens, $candTokens);
        if ($shared && min(strlen($name), strlen($candidate)) > 3) {
            $level = 'low'; $reason = 'Stesso ' . (count($shared) > 1 ? 'nome/cognome' : (in_array(end($inputTokens), $shared) ? 'cognome' : 'nome'));
        }
    }

    if ($level) {
        $results[] = [
            'id'     => $row['id'],
            'name'   => $candidate,
            'level'  => $level,
            'reason' => $reason,
        ];
    }
}

// Sort: high first, then medium, then low
usort($results, fn($a,$b) => ['high'=>0,'medium'=>1,'low'=>2][$a['level']] <=> ['high'=>0,'medium'=>1,'low'=>2][$b['level']]);

echo json_encode(array_slice($results, 0, 5)); // max 5 results
