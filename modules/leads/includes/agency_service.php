<?php
/**
 * agency_service.php — creating an agency (agencies table).
 *
 * Shared by agencies.php (Add), ajax_create_agency.php (➕ Add Agency on request_add /
 * staging), api_create_agency.php (Java BackOffice) and the Agent API (create_agency).
 */

/** Agency types (radio on the Agencies page) => short_name suffix. */
function agency_types(): array {
    return ['savannah' => '', 'promoservice' => '-PS', 'lamprati' => '-LAM'];
}

/**
 * Short name used in folder tags: given, or CamelCase of the name ("eko spostamenti" →
 * "EkoSpostamenti"); any -PS / -LAM is stripped and the type's suffix applied.
 */
function agency_short_name(string $nome, string $short, string $type): string {
    $short = trim($short);
    if ($short === '') {
        $short = preg_replace_callback('/(?:^|\s+|-)(\S)/', function ($m) { return strtoupper($m[1]); }, strtolower(trim($nome)));
        $short = preg_replace('/[\s-]+/', '', $short);
    }
    $short = preg_replace('/-PS$|-LAM$/', '', $short);
    $types = agency_types();
    return $short . ($types[$type] ?? '');
}

/** Name / short name reduced to lower-case letters and digits, for duplicate checks. */
function agency_key(string $s): string {
    return preg_replace('/[^a-z0-9]/', '', strtolower($s));
}

/**
 * Existing agencies whose name or short name matches $nome or $short (ignoring case,
 * spaces and punctuation: "Eko Spostamenti" = "EkoSpostamenti"). Rows: id, nome, short_name, type.
 */
function agency_duplicates(PDO $db, string $nome, string $short): array {
    $keys = array_filter([agency_key($nome), agency_key($short)], 'strlen');
    if (!$keys) return [];
    $out = [];
    foreach ($db->query('SELECT id, nome, short_name, type FROM agencies')->fetchAll(PDO::FETCH_ASSOC) as $a) {
        if (in_array(agency_key((string)$a['nome']), $keys, true) || in_array(agency_key((string)$a['short_name']), $keys, true)) {
            $out[] = ['id' => (int)$a['id'], 'nome' => $a['nome'], 'short_name' => $a['short_name'], 'type' => $a['type']];
        }
    }
    return $out;
}

/**
 * Check (and, unless $dryRun, insert) a new agency.
 * $f: nome*, short_name, type (savannah|promoservice|lamprati; other → savannah), address, email.
 * Returns ok, error, error_code (name|email|short_name|duplicate|db), agency {id?, nome, short_name, type, address, email},
 * duplicates[] (on error_code duplicate).
 */
function agency_create(PDO $db, array $f, bool $dryRun = false): array {
    $nome    = trim((string)($f['nome'] ?? ''));
    $type    = trim((string)($f['type'] ?? 'savannah'));
    if (!array_key_exists($type, agency_types())) $type = 'savannah';
    $address = trim((string)($f['address'] ?? ''));
    $email   = trim((string)($f['email'] ?? ''));
    $res = ['ok' => false, 'error' => null, 'error_code' => null, 'duplicates' => []];
    if ($nome === '') {
        return array_merge($res, ['error' => 'Agency name is required.', 'error_code' => 'name']);
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return array_merge($res, ['error' => 'Invalid email "' . $email . '".', 'error_code' => 'email']);
    }
    $short = agency_short_name($nome, (string)($f['short_name'] ?? ''), $type);
    // The short name goes into folder tags "(Short-Agent)": no spaces, brackets or path characters.
    if (preg_match('#[\s()\[\]/\\\\:*?"<>|]#', $short)) {
        return array_merge($res, ['error' => 'Invalid short name "' . $short . '" (no spaces, brackets or / \ : * ? " < > |).',
                                  'error_code' => 'short_name']);
    }
    $res['agency'] = ['nome' => $nome, 'short_name' => $short, 'type' => $type,
                      'address' => $address !== '' ? $address : null, 'email' => $email !== '' ? $email : null];
    $dup = agency_duplicates($db, $nome, $short);
    if ($dup) {
        return array_merge($res, ['error' => 'Agency "' . $dup[0]['nome'] . '" already exists.', 'error_code' => 'duplicate', 'duplicates' => $dup]);
    }
    if ($dryRun) return array_merge($res, ['ok' => true]);
    try {
        $db->prepare('INSERT INTO agencies (nome, short_name, type, attiva, address, email) VALUES (?, ?, ?, 1, ?, ?)')
           ->execute([$nome, $short, $type, $res['agency']['address'], $res['agency']['email']]);
    } catch (PDOException $e) {
        error_log('agency_create: ' . $e->getMessage());
        return array_merge($res, ['error' => 'Database error while adding the agency.', 'error_code' => 'db']);
    }
    $res['agency'] = ['id' => (int)$db->lastInsertId()] + $res['agency'];
    $res['ok'] = true;
    return $res;
}
