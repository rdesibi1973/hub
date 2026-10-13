<?php
/**
 * agency_service.php — creating and editing an agency (agencies table).
 *
 * agency_create(): agencies.php (Add), ajax_create_agency.php (➕ Add Agency on request_add /
 * staging), api_create_agency.php (Java BackOffice) and the Agent API (create_agency).
 * agency_update(): agencies.php (Edit) and the Agent API (update_agency).
 * agency_contacts() / agency_contact_save() / agency_contact_delete(): agencies.php (contacts) and the
 * Agent API (list_agencies, save_agency_contact, delete_agency_contact).
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

// ── Contacts (agency_contacts) ───────────────────────────────────────────────

/** Contacts of the given agencies, primary first: [agency_id => [rows]]. */
function agency_contacts(PDO $db, array $agencyIds): array {
    $agencyIds = array_values(array_unique(array_map('intval', $agencyIds)));
    if (!$agencyIds) return [];
    $in = implode(',', array_fill(0, count($agencyIds), '?'));
    $st = $db->prepare("SELECT id, agency_id, name, role, email, phone, is_primary, notes FROM agency_contacts
                        WHERE agency_id IN ($in) ORDER BY is_primary DESC, name ASC");
    $st->execute($agencyIds);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) $out[(int)$c['agency_id']][] = $c;
    return $out;
}

/**
 * Check (and, unless $dryRun, save) a contact of agency $agencyId. $contactId 0 = new contact.
 * $f (only the keys present change on an edit): name*, role, email, phone, notes ("" / null clears),
 * is_primary (0|1; a primary contact makes the agency's other contacts non-primary).
 * A new contact with the same email, or the same name (ignoring case and punctuation), as an existing
 * contact of the agency → error_code duplicate.
 * Returns ok, error, error_code (not_found|name|email|duplicate|db), contact (after), changes {field: {from, to}},
 * duplicates[].
 */
function agency_contact_save(PDO $db, int $agencyId, int $contactId, array $f, bool $dryRun = false): array {
    $res = ['ok' => false, 'error' => null, 'error_code' => null, 'changes' => [], 'duplicates' => []];
    $st = $db->prepare('SELECT id FROM agencies WHERE id = ?');
    $st->execute([$agencyId]);
    if (!$st->fetchColumn()) return array_merge($res, ['error' => 'Agency ' . $agencyId . ' not found.', 'error_code' => 'not_found']);
    $fields = ['name', 'role', 'email', 'phone', 'is_primary', 'notes'];
    if ($contactId) {
        $st = $db->prepare('SELECT id, agency_id, name, role, email, phone, is_primary, notes FROM agency_contacts WHERE id = ? AND agency_id = ?');
        $st->execute([$contactId, $agencyId]);
        $cur = $st->fetch(PDO::FETCH_ASSOC);
        if (!$cur) return array_merge($res, ['error' => 'Contact ' . $contactId . ' not found for agency ' . $agencyId . '.', 'error_code' => 'not_found']);
    } else {
        $cur = ['id' => null, 'agency_id' => $agencyId, 'name' => '', 'role' => null, 'email' => null, 'phone' => null, 'is_primary' => 0, 'notes' => null];
    }
    $new = $cur;
    foreach ($fields as $k) {
        if (!array_key_exists($k, $f)) continue;
        if ($k === 'is_primary') { $new[$k] = $f[$k] ? 1 : 0; continue; }
        $v = trim((string)$f[$k]);
        $new[$k] = ($v === '' && $k !== 'name') ? null : $v;
    }
    if ($new['name'] === '') return array_merge($res, ['error' => 'Contact name is required.', 'error_code' => 'name']);
    if ($new['email'] !== null && $new['email'] !== $cur['email'] && !filter_var($new['email'], FILTER_VALIDATE_EMAIL)) {
        return array_merge($res, ['error' => 'Invalid email "' . $new['email'] . '".', 'error_code' => 'email']);
    }
    foreach ($fields as $k) {
        if ((string)$new[$k] !== (string)$cur[$k]) $res['changes'][$k] = ['from' => $contactId ? $cur[$k] : null, 'to' => $new[$k]];
    }
    $res['contact'] = $new;
    if (!$contactId) {
        foreach (agency_contacts($db, [$agencyId])[$agencyId] ?? [] as $c) {
            if (($new['email'] !== null && strcasecmp((string)$c['email'], $new['email']) === 0)
                || agency_key((string)$c['name']) === agency_key($new['name'])) {
                $res['duplicates'][] = $c;
            }
        }
        if ($res['duplicates']) {
            return array_merge($res, ['error' => 'Contact "' . $res['duplicates'][0]['name'] . '" already exists for this agency.', 'error_code' => 'duplicate']);
        }
    }
    if ($dryRun || ($contactId && !$res['changes'])) return array_merge($res, ['ok' => true]);
    try {
        if ($new['is_primary']) {
            $db->prepare('UPDATE agency_contacts SET is_primary=0 WHERE agency_id=?')->execute([$agencyId]);
        }
        if ($contactId) {
            $db->prepare('UPDATE agency_contacts SET name=?, role=?, email=?, phone=?, is_primary=?, notes=? WHERE id=? AND agency_id=?')
               ->execute([$new['name'], $new['role'], $new['email'], $new['phone'], $new['is_primary'], $new['notes'], $contactId, $agencyId]);
        } else {
            $db->prepare('INSERT INTO agency_contacts (agency_id, name, role, email, phone, is_primary, notes) VALUES (?, ?, ?, ?, ?, ?, ?)')
               ->execute([$agencyId, $new['name'], $new['role'], $new['email'], $new['phone'], $new['is_primary'], $new['notes']]);
            $res['contact']['id'] = (int)$db->lastInsertId();
        }
    } catch (PDOException $e) {
        error_log('agency_contact_save: ' . $e->getMessage());
        return array_merge($res, ['error' => 'Database error while saving the contact.', 'error_code' => 'db']);
    }
    $res['ok'] = true;
    return $res;
}

/** Check (and, unless $dryRun, delete) contact $contactId. Returns ok, error, error_code (not_found|db), contact. */
function agency_contact_delete(PDO $db, int $contactId, bool $dryRun = false): array {
    $st = $db->prepare('SELECT id, agency_id, name, role, email, phone, is_primary, notes FROM agency_contacts WHERE id = ?');
    $st->execute([$contactId]);
    $c = $st->fetch(PDO::FETCH_ASSOC);
    if (!$c) return ['ok' => false, 'error' => 'Contact ' . $contactId . ' not found.', 'error_code' => 'not_found'];
    if (!$dryRun) {
        try {
            $db->prepare('DELETE FROM agency_contacts WHERE id = ?')->execute([$contactId]);
        } catch (PDOException $e) {
            error_log('agency_contact_delete: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Database error while deleting the contact.', 'error_code' => 'db', 'contact' => $c];
        }
    }
    return ['ok' => true, 'error' => null, 'error_code' => null, 'contact' => $c];
}
