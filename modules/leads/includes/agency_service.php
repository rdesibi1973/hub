<?php
/**
 * agency_service.php — creating and editing an agency (agencies table).
 *
 * agency_create(): agencies.php (Add), ajax_create_agency.php (➕ Add Agency on request_add /
 * staging), api_create_agency.php (Java BackOffice) and the Agent API (create_agency).
 * agency_update(): agencies.php (Edit) and the Agent API (update_agency).
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

/**
 * Check (and, unless $dryRun, save) changes to agency $id. Only the keys present in $f change:
 * nome, short_name ("" → CamelCase of the name), type (re-applies the -PS / -LAM suffix), attiva (0|1),
 * address, email ("" / null clears address and email).
 * Returns ok, error, error_code (not_found|name|type|email|short_name|duplicate|db), agency (after),
 * changes {field: {from, to}}, duplicates[] (on error_code duplicate).
 */
function agency_update(PDO $db, int $id, array $f, bool $dryRun = false): array {
    $res = ['ok' => false, 'error' => null, 'error_code' => null, 'changes' => [], 'duplicates' => []];
    $st = $db->prepare('SELECT id, nome, short_name, type, attiva, address, email FROM agencies WHERE id = ?');
    $st->execute([$id]);
    $cur = $st->fetch(PDO::FETCH_ASSOC);
    if (!$cur) return array_merge($res, ['error' => 'Agency ' . $id . ' not found.', 'error_code' => 'not_found']);

    $new = $cur;
    if (array_key_exists('nome', $f)) $new['nome'] = trim((string)$f['nome']);
    if ($new['nome'] === '') return array_merge($res, ['error' => 'Agency name is required.', 'error_code' => 'name']);
    if (array_key_exists('type', $f)) {
        $new['type'] = trim((string)$f['type']);
        if (!array_key_exists($new['type'], agency_types())) {
            return array_merge($res, ['error' => 'type must be one of: ' . implode(', ', array_keys(agency_types())) . '.', 'error_code' => 'type']);
        }
    }
    if (array_key_exists('attiva', $f)) $new['attiva'] = $f['attiva'] ? 1 : 0;
    foreach (['address', 'email'] as $k) {
        if (array_key_exists($k, $f)) $new[$k] = trim((string)$f[$k]) !== '' ? trim((string)$f[$k]) : null;
    }
    if ($new['email'] !== null && $new['email'] !== $cur['email'] && !filter_var($new['email'], FILTER_VALIDATE_EMAIL)) {
        return array_merge($res, ['error' => 'Invalid email "' . $new['email'] . '".', 'error_code' => 'email']);
    }
    if (array_key_exists('short_name', $f) || $new['type'] !== $cur['type']) {
        $short = array_key_exists('short_name', $f) ? (string)$f['short_name'] : (string)$cur['short_name'];
        $new['short_name'] = agency_short_name($new['nome'], $short, $new['type']);
        if (preg_match('#[\s()\[\]/\\\\:*?"<>|]#', $new['short_name'])) {
            return array_merge($res, ['error' => 'Invalid short name "' . $new['short_name'] . '" (no spaces, brackets or / \ : * ? " < > |).',
                                      'error_code' => 'short_name']);
        }
    }

    foreach (['nome', 'short_name', 'type', 'attiva', 'address', 'email'] as $k) {
        if ((string)$new[$k] !== (string)$cur[$k]) $res['changes'][$k] = ['from' => $cur[$k], 'to' => $new[$k]];
    }
    $res['agency'] = ['id' => $id, 'nome' => $new['nome'], 'short_name' => $new['short_name'], 'type' => $new['type'],
                      'attiva' => (int)$new['attiva'], 'address' => $new['address'], 'email' => $new['email']];
    if (isset($res['changes']['nome']) || isset($res['changes']['short_name'])) {
        $dup = array_values(array_filter(agency_duplicates($db, $new['nome'], (string)$new['short_name']),
                                         function ($d) use ($id) { return $d['id'] !== $id; }));
        if ($dup) {
            return array_merge($res, ['error' => 'Agency "' . $dup[0]['nome'] . '" already exists.', 'error_code' => 'duplicate', 'duplicates' => $dup]);
        }
    }
    if ($dryRun || !$res['changes']) return array_merge($res, ['ok' => true]);
    try {
        $db->prepare('UPDATE agencies SET nome=?, short_name=?, type=?, attiva=?, address=?, email=? WHERE id=?')
           ->execute([$new['nome'], $new['short_name'], $new['type'], $new['attiva'], $new['address'], $new['email'], $id]);
    } catch (PDOException $e) {
        error_log('agency_update: ' . $e->getMessage());
        return array_merge($res, ['error' => 'Database error while saving the agency.', 'error_code' => 'db']);
    }
    $res['ok'] = true;
    return $res;
}
