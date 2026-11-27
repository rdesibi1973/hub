<?php
/**
 * commission_service.php — commercial referrers (Promoservice, Lamprati, Elena Perfetti …) and the
 * commission split of each booking. Shared by agencies.php, request_add.php / request_edit.php /
 * request_view.php, commission_split.php, commissions.php and the Agent API (list_agencies,
 * update_agency, save_agency_contact, create_request, update_request, request_resume,
 * list_commercial_referrers, commissions_report, set_commission_split, commission_backfill).
 *
 * Rules (Roberto, 27 Nov 2026):
 *  - The commission goes to whoever introduced the agency and follows it, recorded in the Hub at the time.
 *  - A split is a list [{"referrer_id": 1, "rate": 5.0}, …] (rates in %, of the booking base).
 *  - Resolution of a request:  snapshot (requests.commission_split_json, taken at confirmation)
 *      → its agency contact (agency_contacts.referrer_split_json, from referrer_from)
 *      → the agency default (agencies.referrer_split_json; NULL = from agencies.type)
 *      → the folder tag (-PS / -LAM) when the request has no agency_id → none.
 *    NULL on a contact = "agency default"; "[]" = explicitly no commission.
 *  - The snapshot is taken by Confirm Safari (bs_confirm_commit()) and the group import; afterwards the
 *    booking keeps its split even if the contact's referrer changes. Roberto can change it
 *    (comm_set_request_split(), timeline "Commission split changed").
 *  - Base = paid invoices (not Cancelled; an invoice with nothing paid is left out) − credit notes (credit applied to
 *    an invoice of the same request stays in, same formula as sync_request_value()). A referrer with on_penalties = 0
 *    (Promoservice) gets nothing on a cancelled booking (status Cancelled / _CANCELLED = all penalty); a cancellation
 *    line inside a live trip stays in the base (Roberto, 27 Nov 2026).
 *  - Lamprati counts per semester (commercial_referrers.period = semester).
 *  - agencies.type (savannah | promoservice | lamprati) stays for the folder suffix and old reports; a default split
 *    that does not match it gets a warning, the type is never changed from here.
 *
 * Tables / columns: migrations/088_commission_referrers.sql, also created on first use by comm_schema().
 */

// Bookings before this date make a consultant "existing" (LabTravel: existing → Promoservice 5 %, new → 2.5 + 2.5).
// Proposed in the handoff of 27 Nov 2026 — Roberto to confirm (override in includes/config.php).
if (!defined('COMM_NEW_CONSULTANT_CUTOFF')) define('COMM_NEW_CONSULTANT_CUTOFF', '2026-12-01');

const COMM_BASIS = ['booked', 'travel', 'paid'];

/** Tables and columns, created / added once per PHP request (MySQL: no ADD COLUMN IF NOT EXISTS). */
function comm_schema(PDO $db): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $db->exec("CREATE TABLE IF NOT EXISTS commercial_referrers (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        name          VARCHAR(100) NOT NULL,
        kind          ENUM('rep','internal') NOT NULL DEFAULT 'rep',
        default_rate  DECIMAL(5,2) NOT NULL DEFAULT 0,
        on_penalties  TINYINT(1)   NOT NULL DEFAULT 1,
        period        ENUM('month','semester') NOT NULL DEFAULT 'month',
        agency_type   VARCHAR(20)  NULL,
        active        TINYINT(1)   NOT NULL DEFAULT 1,
        created_at    DATETIME     NULL,
        UNIQUE KEY uq_name (name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS agency_contact_referrer_log (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        agency_id   INT NOT NULL,
        contact_id  INT NULL,
        changed_at  DATETIME NOT NULL,
        actor       VARCHAR(100) NULL,
        from_json   TEXT NULL,
        to_json     TEXT NULL,
        from_date   DATE NULL,
        to_date     DATE NULL,
        note        VARCHAR(500) NULL,
        KEY idx_contact (contact_id),
        KEY idx_agency (agency_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    if (!(int)$db->query("SELECT COUNT(*) FROM commercial_referrers")->fetchColumn()) {
        $now = date('Y-m-d H:i:s');
        $ins = $db->prepare("INSERT INTO commercial_referrers (id, name, kind, default_rate, on_penalties, period, agency_type, active, created_at)
                             VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?)");
        foreach ([[1, 'Promoservice', 'rep', 5.0, 0, 'month', 'promoservice'],
                  [2, 'Lamprati', 'rep', 3.0, 1, 'semester', 'lamprati'],
                  [3, 'Elena Perfetti', 'internal', 5.0, 1, 'month', null]] as $s) {
            try { $ins->execute(array_merge($s, [$now])); } catch (PDOException $e) { /* seeded by a parallel request */ }
        }
    }
    $want = [
        'agencies'        => ['referrer_split_json' => 'TEXT NULL'],
        'agency_contacts' => ['referrer_split_json' => 'TEXT NULL', 'referrer_from' => 'DATE NULL', 'referrer_note' => 'VARCHAR(500) NULL'],
        'requests'        => ['agency_contact_id' => 'INT NULL', 'commission_split_json' => 'TEXT NULL',
                              'commission_split_at' => 'DATETIME NULL', 'commission_split_by' => 'VARCHAR(100) NULL',
                              'commission_split_src' => 'VARCHAR(20) NULL'],
    ];
    $have = [];
    foreach ($db->query("SELECT TABLE_NAME, COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
                         AND TABLE_NAME IN ('agencies','agency_contacts','requests')")->fetchAll(PDO::FETCH_NUM) as $c) {
        $have[$c[0]][$c[1]] = true;
    }
    foreach ($want as $t => $cols) {
        $add = [];
        foreach ($cols as $c => $def) if (empty($have[$t][$c])) $add[] = "ADD COLUMN $c $def";
        if ($add) {
            try { $db->exec("ALTER TABLE $t " . implode(', ', $add)); }
            catch (PDOException $e) { error_log('comm_schema ' . $t . ': ' . $e->getMessage()); }
        }
    }
}

/** Who may change referrers and splits: admin (Roberto). */
function comm_can_edit(): bool {
    if (function_exists('isLeadsAdmin')) return (bool)isLeadsAdmin();
    return function_exists('current_user') && (current_user()['role_name'] ?? '') === 'admin';
}

/** Who sees the commission data (agency page, request page, report): admin / manager / accountant. */
function comm_can_view(): bool {
    if (comm_can_edit()) return true;
    return function_exists('current_user') && in_array(current_user()['role_name'] ?? '', ['admin', 'manager', 'accountant'], true);
}

/** Actor label for logs: the Hub user, or the API user (TL_ACTOR). */
function comm_actor(): string {
    if (!empty($GLOBALS['TL_ACTOR']['source']) && $GLOBALS['TL_ACTOR']['source'] === 'api') return 'agent_api';
    if (function_exists('current_user')) {
        $u = current_user();
        return (string)($u['username'] ?: ($u['full_name'] ?? '')) ?: 'hub';
    }
    return 'hub';
}

// ── Referrers ────────────────────────────────────────────────────────────────

/** Referrers [id => row], active only unless $all. */
function comm_referrers(PDO $db, bool $all = true): array {
    comm_schema($db);
    $out = [];
    foreach ($db->query("SELECT * FROM commercial_referrers ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (!$all && !(int)$r['active']) continue;
        $r['id'] = (int)$r['id'];
        $r['default_rate'] = (float)$r['default_rate'];
        $r['on_penalties'] = (int)$r['on_penalties'];
        $r['active'] = (int)$r['active'];
        $out[$r['id']] = $r;
    }
    return $out;
}

/** Public shape of a referrer. */
function comm_referrer_out(array $r): array {
    return ['id' => (int)$r['id'], 'name' => $r['name'], 'kind' => $r['kind'], 'default_rate' => (float)$r['default_rate'],
            'on_penalties' => (bool)$r['on_penalties'], 'period' => $r['period'], 'agency_type' => $r['agency_type'],
            'active' => (bool)$r['active']];
}

/**
 * Check (and, unless $dryRun, save) a referrer. $id 0 = new. $f: name, kind (rep|internal), default_rate,
 * on_penalties (0|1), period (month|semester), agency_type, active (0|1).
 * Returns ok, error, referrer, changes.
 */
function comm_referrer_save(PDO $db, int $id, array $f, bool $dryRun = false): array {
    $refs = comm_referrers($db);
    if ($id && !isset($refs[$id])) return ['ok' => false, 'error' => 'Referrer ' . $id . ' not found.'];
    $cur = $id ? $refs[$id] : ['id' => null, 'name' => '', 'kind' => 'rep', 'default_rate' => 0.0, 'on_penalties' => 1,
                               'period' => 'month', 'agency_type' => null, 'active' => 1];
    $new = $cur;
    if (array_key_exists('name', $f)) $new['name'] = trim((string)$f['name']);
    if (array_key_exists('kind', $f)) $new['kind'] = (string)$f['kind'];
    if (array_key_exists('default_rate', $f)) $new['default_rate'] = round((float)$f['default_rate'], 2);
    if (array_key_exists('on_penalties', $f)) $new['on_penalties'] = $f['on_penalties'] ? 1 : 0;
    if (array_key_exists('period', $f)) $new['period'] = (string)$f['period'];
    if (array_key_exists('agency_type', $f)) $new['agency_type'] = trim((string)$f['agency_type']) ?: null;
    if (array_key_exists('active', $f)) $new['active'] = $f['active'] ? 1 : 0;
    if ($new['name'] === '') return ['ok' => false, 'error' => 'Name is required.'];
    if (!in_array($new['kind'], ['rep', 'internal'], true)) return ['ok' => false, 'error' => 'kind must be rep or internal.'];
    if (!in_array($new['period'], ['month', 'semester'], true)) return ['ok' => false, 'error' => 'period must be month or semester.'];
    if ($new['default_rate'] < 0 || $new['default_rate'] > 30) return ['ok' => false, 'error' => 'default_rate must be between 0 and 30 (%).'];
    foreach ($refs as $r) {
        if ($r['id'] !== $id && strcasecmp($r['name'], $new['name']) === 0) return ['ok' => false, 'error' => 'Referrer "' . $r['name'] . '" already exists.'];
    }
    $changes = [];
    foreach (['name', 'kind', 'default_rate', 'on_penalties', 'period', 'agency_type', 'active'] as $k) {
        if ((string)$new[$k] !== (string)$cur[$k]) $changes[$k] = ['from' => $id ? $cur[$k] : null, 'to' => $new[$k]];
    }
    if ($dryRun || !$changes) return ['ok' => true, 'error' => null, 'referrer' => $new, 'changes' => $changes];
    $args = [$new['name'], $new['kind'], $new['default_rate'], $new['on_penalties'], $new['period'], $new['agency_type'], $new['active']];
    if ($id) {
        $db->prepare("UPDATE commercial_referrers SET name=?, kind=?, default_rate=?, on_penalties=?, period=?, agency_type=?, active=? WHERE id=?")
           ->execute(array_merge($args, [$id]));
    } else {
        $db->prepare("INSERT INTO commercial_referrers (name, kind, default_rate, on_penalties, period, agency_type, active, created_at) VALUES (?,?,?,?,?,?,?,?)")
           ->execute(array_merge($args, [date('Y-m-d H:i:s')]));
        $new['id'] = (int)$db->lastInsertId();
    }
    return ['ok' => true, 'error' => null, 'referrer' => $new, 'changes' => $changes];
}

// ── Splits ───────────────────────────────────────────────────────────────────

/** Stored JSON → split list, or null when not set (NULL / '' / invalid). */
function comm_split_parse($json): ?array {
    if ($json === null || $json === '') return null;
    $a = is_array($json) ? $json : json_decode((string)$json, true);
    if (!is_array($a)) return null;
    $out = [];
    foreach ($a as $e) {
        if (!is_array($e) || empty($e['referrer_id'])) continue;
        $out[] = ['referrer_id' => (int)$e['referrer_id'], 'rate' => round((float)($e['rate'] ?? 0), 2)];
    }
    return $out;
}

function comm_split_encode(array $split): string {
    return json_encode(array_map(function ($e) { return ['referrer_id' => (int)$e['referrer_id'], 'rate' => (float)$e['rate']]; }, $split));
}

function comm_split_total(array $split): float {
    return round(array_sum(array_column($split, 'rate')), 2);
}

function comm_split_same(?array $a, ?array $b): bool {
    if ($a === null || $b === null) return $a === $b;
    return comm_split_encode($a) === comm_split_encode($b);
}

/**
 * Split given by a caller → [split, error]. Accepts a list of {referrer_id | referrer (name), rate?}
 * (rate omitted = the referrer's default rate), a preset key (comm_presets()), or [] (no commission).
 */
function comm_split_check(PDO $db, $in): array {
    $refs = comm_referrers($db);
    if (is_string($in)) {
        $p = comm_presets($db);
        if (!isset($p[$in]) || $p[$in]['split'] === null) return [null, 'Unknown split preset "' . $in . '".'];
        return [$p[$in]['split'], null];
    }
    if (!is_array($in)) return [null, 'split must be a list of {referrer_id, rate}.'];
    $out = []; $seen = [];
    foreach (array_values($in) as $i => $e) {
        if (!is_array($e)) return [null, 'split[' . $i . '] must be an object {referrer_id, rate}.'];
        $rid = (int)($e['referrer_id'] ?? 0);
        if (!$rid && !empty($e['referrer'])) {
            foreach ($refs as $r) if (strcasecmp($r['name'], trim((string)$e['referrer'])) === 0) $rid = $r['id'];
        }
        if (!$rid || !isset($refs[$rid])) return [null, 'split[' . $i . ']: unknown referrer — see list_commercial_referrers.'];
        if (isset($seen[$rid])) return [null, 'split[' . $i . ']: ' . $refs[$rid]['name'] . ' is there twice.'];
        $rate = array_key_exists('rate', $e) && $e['rate'] !== null && $e['rate'] !== '' ? $e['rate'] : $refs[$rid]['default_rate'];
        if (!is_numeric($rate) || (float)$rate < 0 || (float)$rate > 30) return [null, 'split[' . $i . ']: rate must be a number between 0 and 30 (%).'];
        $seen[$rid] = true;
        $out[] = ['referrer_id' => $rid, 'rate' => round((float)$rate, 2)];
    }
    if (count($out) > 4) return [null, 'At most 4 referrers in a split.'];
    if (comm_split_total($out) > 30) return [null, 'The split totals more than 30 %.'];
    return [$out, null];
}

/** Split with names: [{referrer_id, referrer, rate}]. */
function comm_split_out(PDO $db, ?array $split): ?array {
    if ($split === null) return null;
    $refs = comm_referrers($db);
    return array_map(function ($e) use ($refs) {
        return ['referrer_id' => $e['referrer_id'], 'referrer' => $refs[$e['referrer_id']]['name'] ?? ('#' . $e['referrer_id']), 'rate' => $e['rate']];
    }, $split);
}

/** "Promoservice 5 %" / "Promoservice 2.5 % + Elena Perfetti 2.5 %" / "no commission". */
function comm_split_label(PDO $db, ?array $split): string {
    if ($split === null) return 'agency default';
    if (!$split) return 'no commission';
    return implode(' + ', array_map(function ($e) {
        return $e['referrer'] . ' ' . rtrim(rtrim(number_format($e['rate'], 2, '.', ''), '0'), '.') . ' %';
    }, comm_split_out($db, $split)));
}

/** The split implied by an agency type (referrers with that agency_type, default rate). */
function comm_type_split(PDO $db, string $type): array {
    $out = [];
    foreach (comm_referrers($db, false) as $r) {
        if ($type !== '' && $r['agency_type'] === $type) $out[] = ['referrer_id' => $r['id'], 'rate' => $r['default_rate']];
    }
    return $out;
}

/** Folder tag "Short-PS-Agent" / "Short-LAM-Agent" (folder_agency()) → agency type, else ''. */
function comm_tag_type(?string $tag): string {
    $tag = (string)$tag;
    if (preg_match('/-PS(-|$)/i', $tag)) return 'promoservice';
    if (preg_match('/-LAM(-|$)/i', $tag)) return 'lamprati';
    return '';
}

/** Agency tag of a request's folder ("Short-PS-Agent"), '' if none. */
function comm_folder_tag(array $r): string {
    require_once __DIR__ . '/folder_parser.php';
    return function_exists('folder_agency') ? folder_agency($r) : '';
}

/**
 * Presets for the selectors: key => [label, split (null = agency default / custom)].
 * Built from the referrers: one per active referrer at its default rate, Promoservice + Elena half each, none.
 */
function comm_presets(PDO $db): array {
    $refs = comm_referrers($db, false);
    $p = ['default' => ['label' => 'Agency default', 'split' => null]];
    foreach ($refs as $r) {
        $p['r' . $r['id']] = ['label' => $r['name'] . ' ' . rtrim(rtrim(number_format($r['default_rate'], 2, '.', ''), '0'), '.') . ' %',
                              'split' => [['referrer_id' => $r['id'], 'rate' => $r['default_rate']]]];
    }
    $ps = $el = null;
    foreach ($refs as $r) {
        if ($r['agency_type'] === 'promoservice') $ps = $r;
        if ($r['kind'] === 'internal' && stripos($r['name'], 'elena') !== false) $el = $r;
    }
    if ($ps && $el) {
        $half = round($ps['default_rate'] / 2, 2);
        $p['ps_el'] = ['label' => $ps['name'] . ' ' . $half . ' % + ' . $el['name'] . ' ' . $half . ' %',
                       'split' => [['referrer_id' => $ps['id'], 'rate' => $half], ['referrer_id' => $el['id'], 'rate' => $half]]];
    }
    $p['none'] = ['label' => 'No commission', 'split' => []];
    $p['custom'] = ['label' => 'Custom…', 'split' => null];
    return $p;
}

/** Form input (preset key + custom rates [referrer_id => rate]) → [split|null (default), error]. */
function comm_split_from_form(PDO $db, string $preset, array $rates = []): array {
    if ($preset === '' || $preset === 'default') return [null, null];
    if ($preset === 'custom') {
        $in = [];
        foreach ($rates as $rid => $rate) {
            if (trim((string)$rate) !== '' && (float)$rate > 0) $in[] = ['referrer_id' => (int)$rid, 'rate' => $rate];
        }
        return comm_split_check($db, $in);
    }
    return comm_split_check($db, $preset);
}

/** Preset key matching a split (null → default), or 'custom'. */
function comm_preset_of(PDO $db, ?array $split): string {
    if ($split === null) return 'default';
    foreach (comm_presets($db) as $k => $p) {
        if ($p['split'] !== null && comm_split_same($p['split'], $split)) return $k;
    }
    return 'custom';
}

// ── Agency / contact defaults ────────────────────────────────────────────────

/** Agency default split: stored, else from its type. Returns [split, source 'agency'|'type']. */
function comm_agency_default(PDO $db, array $agency): array {
    $s = comm_split_parse($agency['referrer_split_json'] ?? null);
    if ($s !== null) return [$s, 'agency'];
    return [comm_type_split($db, (string)($agency['type'] ?? '')), 'type'];
}

/** Agency row (id, nome, short_name, type, referrer_split_json) or null — cached. */
function comm_agency(PDO $db, int $id): ?array {
    static $cache = [];
    if ($id <= 0) return null;
    if (!array_key_exists($id, $cache)) {
        comm_schema($db);
        $st = $db->prepare("SELECT id, nome, short_name, type, referrer_split_json FROM agencies WHERE id = ?");
        $st->execute([$id]);
        $cache[$id] = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    return $cache[$id];
}

/** Contact row (with referrer fields) or null — cached. */
function comm_contact(PDO $db, int $id): ?array {
    static $cache = [];
    if ($id <= 0) return null;
    if (!array_key_exists($id, $cache)) {
        comm_schema($db);
        $st = $db->prepare("SELECT id, agency_id, name, email, is_primary, referrer_split_json, referrer_from, referrer_note FROM agency_contacts WHERE id = ?");
        $st->execute([$id]);
        $cache[$id] = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    return $cache[$id];
}

/** Warning when the agency default does not match agencies.type (folder suffix), else null. */
function comm_agency_type_warning(PDO $db, array $agency, array $split): ?string {
    $refs = comm_referrers($db);
    $types = [];
    foreach ($split as $e) if (!empty($refs[$e['referrer_id']]['agency_type'])) $types[] = $refs[$e['referrer_id']]['agency_type'];
    $type = (string)($agency['type'] ?? 'savannah');
    if ($type !== 'savannah' && !in_array($type, $types, true)) {
        return 'Agency type is ' . $type . ' (folder suffix) but its default split has no ' . $type . ' referrer — check the type on Edit.';
    }
    if ($type === 'savannah' && $types) {
        return 'Default split has ' . $types[0] . ' but the agency type is savannah (no -PS / -LAM suffix) — change the type on Edit if the folders must carry it.';
    }
    return null;
}

/**
 * Agency that needs a consultant on its requests: more than one contact and at least one contact with a
 * split different from the agency default (LabTravel / Euphemia).
 */
function comm_agency_needs_contact(PDO $db, int $agencyId): bool {
    static $cache = [];
    if ($agencyId <= 0) return false;
    if (!array_key_exists($agencyId, $cache)) {
        comm_schema($db);
        $st = $db->prepare("SELECT COUNT(*) AS n, SUM(referrer_split_json IS NOT NULL) AS own FROM agency_contacts WHERE agency_id = ?");
        $st->execute([$agencyId]);
        $c = $st->fetch(PDO::FETCH_ASSOC) ?: ['n' => 0, 'own' => 0];
        $cache[$agencyId] = (int)$c['n'] > 1 && (int)$c['own'] > 0;
    }
    return $cache[$agencyId];
}

/** Contact of $agencyId whose email is $email (case-insensitive; one of a comma list), else null. */
function comm_contact_for_email(PDO $db, int $agencyId, string $email): ?int {
    $email = strtolower(trim($email));
    if ($agencyId <= 0 || $email === '') return null;
    $st = $db->prepare("SELECT id, email FROM agency_contacts WHERE agency_id = ? AND email IS NOT NULL AND email <> ''");
    $st->execute([$agencyId]);
    $hits = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
        foreach (preg_split('/[,;\s]+/', strtolower((string)$c['email'])) as $e) {
            if ($e !== '' && $e === $email) { $hits[] = (int)$c['id']; break; }
        }
    }
    return count($hits) === 1 ? $hits[0] : null;
}

/** Error text when $contactId is not a contact of $agencyId, else null. */
function comm_contact_check(PDO $db, ?int $agencyId, int $contactId): ?string {
    if ($contactId <= 0) return null;
    $c = comm_contact($db, $contactId);
    if (!$c) return 'Agency contact ' . $contactId . ' not found.';
    if (!$agencyId) return 'agency_contact_id needs an agency on the request.';
    if ((int)$c['agency_id'] !== (int)$agencyId) return 'Contact "' . $c['name'] . '" belongs to another agency.';
    return null;
}

/** Confirmed bookings per contact of an agency (by agency_contact_id, else by the request email): [contact_id => {count, first, before_cutoff}]. */
function comm_contact_bookings(PDO $db, int $agencyId): array {
    comm_schema($db);
    $st = $db->prepare("SELECT id, email FROM agency_contacts WHERE agency_id = ?");
    $st->execute([$agencyId]);
    $byEmail = []; $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $out[(int)$c['id']] = ['count' => 0, 'first' => null, 'before_cutoff' => 0];
        foreach (preg_split('/[,;\s]+/', strtolower((string)$c['email'])) as $e) if ($e !== '') $byEmail[$e][] = (int)$c['id'];
    }
    if (!$out) return [];
    $st = $db->prepare("SELECT agency_contact_id, LOWER(TRIM(email)) AS email, COALESCE(confirmation_date, date_received) AS d
                        FROM requests WHERE agency_id = ? AND (confirmation_date IS NOT NULL OR status IN ('Booked','Cancelled'))");
    $st->execute([$agencyId]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $cid = (int)$r['agency_contact_id'];
        if (!$cid && isset($byEmail[$r['email']]) && count($byEmail[$r['email']]) === 1) $cid = $byEmail[$r['email']][0];
        if (!$cid || !isset($out[$cid])) continue;
        $out[$cid]['count']++;
        if ($r['d'] !== null && ($out[$cid]['first'] === null || $r['d'] < $out[$cid]['first'])) $out[$cid]['first'] = $r['d'];
        if ($r['d'] !== null && $r['d'] < COMM_NEW_CONSULTANT_CUTOFF) $out[$cid]['before_cutoff']++;
    }
    return $out;
}

/**
 * Check (and, unless $dryRun, save + log) the referrer of a contact. $split null = back to the agency default.
 * $from = date the referrer applies from (YYYY-MM-DD, '' = today when the split changes), $note free text.
 * Returns ok, error, changes {referrer_split, referrer_from, referrer_note}, warnings[], contact (after).
 */
function comm_contact_set(PDO $db, int $contactId, ?array $split, ?string $from, ?string $note, bool $dryRun = false): array {
    comm_schema($db);
    $st = $db->prepare("SELECT c.*, a.type AS agency_type, a.referrer_split_json AS agency_split_json, a.nome AS agency_name
                        FROM agency_contacts c JOIN agencies a ON a.id = c.agency_id WHERE c.id = ?");
    $st->execute([$contactId]);
    $c = $st->fetch(PDO::FETCH_ASSOC);
    if (!$c) return ['ok' => false, 'error' => 'Contact ' . $contactId . ' not found.', 'changes' => [], 'warnings' => []];
    $curSplit = comm_split_parse($c['referrer_split_json']);
    // $from not given: kept while the split stays, today when it changes; given '' = cleared (today again if the split changes).
    if ($from === null) $from = comm_split_same($curSplit, $split) ? $c['referrer_from'] : null;
    else $from = trim($from) !== '' ? trim($from) : null;
    if ($from !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) return ['ok' => false, 'error' => 'referrer_from must be YYYY-MM-DD.', 'changes' => [], 'warnings' => []];
    if (!comm_split_same($curSplit, $split) && $from === null && $split !== null) $from = date('Y-m-d');
    if ($split === null) $from = null;
    $note = $note === null ? $c['referrer_note'] : (trim($note) !== '' ? mb_substr(trim($note), 0, 500) : null);
    $changes = [];
    if (!comm_split_same($curSplit, $split)) $changes['referrer_split'] = ['from' => comm_split_label($db, $curSplit), 'to' => comm_split_label($db, $split)];
    if ((string)$from !== (string)$c['referrer_from']) $changes['referrer_from'] = ['from' => $c['referrer_from'], 'to' => $from];
    if ((string)$note !== (string)$c['referrer_note']) $changes['referrer_note'] = ['from' => $c['referrer_note'], 'to' => $note];
    $warnings = [];
    [$agSplit] = comm_agency_default($db, ['type' => $c['agency_type'], 'referrer_split_json' => $c['agency_split_json']]);
    if ($split !== null && comm_split_total($split) > comm_split_total($agSplit)) {
        $warnings[] = 'The contact\'s split (' . comm_split_total($split) . ' %) is more than the agency default (' . comm_split_total($agSplit)
                    . ' %) — fine only if Roberto agreed.';
    }
    $after = ['id' => (int)$c['id'], 'agency_id' => (int)$c['agency_id'], 'name' => $c['name'], 'referrer_split' => comm_split_out($db, $split),
              'referrer_label' => comm_split_label($db, $split), 'referrer_from' => $from, 'referrer_note' => $note];
    if ($dryRun || !$changes) return ['ok' => true, 'error' => null, 'changes' => $changes, 'warnings' => $warnings, 'contact' => $after];
    $db->prepare("UPDATE agency_contacts SET referrer_split_json = ?, referrer_from = ?, referrer_note = ? WHERE id = ?")
       ->execute([$split === null ? null : comm_split_encode($split), $from, $note, $contactId]);
    $db->prepare("INSERT INTO agency_contact_referrer_log (agency_id, contact_id, changed_at, actor, from_json, to_json, from_date, to_date, note)
                  VALUES (?,?,?,?,?,?,?,?,?)")
       ->execute([(int)$c['agency_id'], $contactId, date('Y-m-d H:i:s'), comm_actor(), $c['referrer_split_json'],
                  $split === null ? null : comm_split_encode($split), $c['referrer_from'], $from, $note]);
    return ['ok' => true, 'error' => null, 'changes' => $changes, 'warnings' => $warnings, 'contact' => $after];
}

/** Check (and, unless $dryRun, save + log) the default split of an agency ($split null = back to "from type"). */
function comm_agency_set(PDO $db, int $agencyId, ?array $split, bool $dryRun = false): array {
    comm_schema($db);
    $st = $db->prepare("SELECT id, nome, type, referrer_split_json FROM agencies WHERE id = ?");
    $st->execute([$agencyId]);
    $a = $st->fetch(PDO::FETCH_ASSOC);
    if (!$a) return ['ok' => false, 'error' => 'Agency ' . $agencyId . ' not found.', 'changes' => [], 'warnings' => []];
    $cur = comm_split_parse($a['referrer_split_json']);
    [$curEff] = comm_agency_default($db, $a);
    [$newEff] = comm_agency_default($db, ['type' => $a['type'], 'referrer_split_json' => $split === null ? null : comm_split_encode($split)]);
    $changes = comm_split_same($cur, $split) ? [] : ['referrer_split' => ['from' => comm_split_label($db, $curEff), 'to' => comm_split_label($db, $newEff)]];
    $warnings = array_values(array_filter([comm_agency_type_warning($db, $a, $newEff)]));
    $out = ['ok' => true, 'error' => null, 'changes' => $changes, 'warnings' => $warnings,
            'referrer_split' => ['split' => comm_split_out($db, $newEff), 'label' => comm_split_label($db, $newEff), 'source' => $split === null ? 'type' : 'agency']];
    if ($dryRun || !$changes) return $out;
    $db->prepare("UPDATE agencies SET referrer_split_json = ? WHERE id = ?")->execute([$split === null ? null : comm_split_encode($split), $agencyId]);
    $db->prepare("INSERT INTO agency_contact_referrer_log (agency_id, contact_id, changed_at, actor, from_json, to_json, note) VALUES (?,?,?,?,?,?,?)")
       ->execute([$agencyId, null, date('Y-m-d H:i:s'), comm_actor(), $a['referrer_split_json'], $split === null ? null : comm_split_encode($split), 'agency default']);
    return $out;
}

/** History of an agency's referrer changes (agency default + contacts), newest first. */
function comm_referrer_history(PDO $db, int $agencyId, int $limit = 100): array {
    comm_schema($db);
    $st = $db->prepare("SELECT l.*, c.name AS contact_name FROM agency_contact_referrer_log l
                        LEFT JOIN agency_contacts c ON c.id = l.contact_id
                        WHERE l.agency_id = ? ORDER BY l.changed_at DESC, l.id DESC LIMIT " . max(1, min(500, $limit)));
    $st->execute([$agencyId]);
    return array_map(function ($l) use ($db) {
        return ['at' => $l['changed_at'], 'actor' => $l['actor'], 'contact_id' => $l['contact_id'] !== null ? (int)$l['contact_id'] : null,
                'contact' => $l['contact_id'] !== null ? ($l['contact_name'] ?? '(deleted contact)') : '(agency default)',
                'from' => comm_split_label($db, comm_split_parse($l['from_json'])), 'to' => comm_split_label($db, comm_split_parse($l['to_json'])),
                'from_date' => $l['from_date'], 'to_date' => $l['to_date'], 'note' => $l['note']];
    }, $st->fetchAll(PDO::FETCH_ASSOC));
}

// ── Request: resolution, snapshot, change ────────────────────────────────────

/**
 * Commission split of a request row (needs id, agency_id, agency_contact_id, commission_split_json, practice_code,
 * group_folder, confirmation_date, date_received). $ignoreSnapshot: what it would be now (confirmation, backfill).
 * Returns split[] (with names), label, total, source (snapshot|contact|agency|type|folder_tag|none), provisional,
 * contact {id, name}|null, agency_id, warning|null.
 */
function comm_resolve(PDO $db, array $r, bool $ignoreSnapshot = false): array {
    comm_schema($db);
    $agencyId = (int)($r['agency_id'] ?? 0);
    $contactId = (int)($r['agency_contact_id'] ?? 0);
    $contact = $contactId ? comm_contact($db, $contactId) : null;
    if ($contact && (int)$contact['agency_id'] !== $agencyId) $contact = null;   // agency changed since: the contact no longer applies
    $out = ['split' => [], 'source' => 'none', 'provisional' => true,
            'contact' => $contact ? ['id' => (int)$contact['id'], 'name' => $contact['name']] : null,
            'agency_id' => $agencyId ?: null, 'warning' => null];
    $snap = $ignoreSnapshot ? null : comm_split_parse($r['commission_split_json'] ?? null);
    if ($snap !== null) {
        $split = $snap; $out['source'] = 'snapshot'; $out['provisional'] = false;
        $out['snapshot_at'] = $r['commission_split_at'] ?? null; $out['snapshot_src'] = $r['commission_split_src'] ?? null;
    } else {
        $split = [];
        $ref = (string)($r['confirmation_date'] ?? '') ?: date('Y-m-d');
        $cs = $contact ? comm_split_parse($contact['referrer_split_json']) : null;
        if ($cs !== null && (empty($contact['referrer_from']) || $contact['referrer_from'] <= $ref)) {
            $split = $cs; $out['source'] = 'contact';
        } elseif ($agencyId && ($a = comm_agency($db, $agencyId))) {
            [$split, $out['source']] = comm_agency_default($db, $a);
        } elseif (!$agencyId) {
            $type = comm_tag_type(comm_folder_tag($r));
            if ($type !== '') { $split = comm_type_split($db, $type); $out['source'] = 'folder_tag'; }
        }
        if ($out['source'] === 'type' || $out['source'] === 'agency') {
            if (!$contactId && comm_agency_needs_contact($db, $agencyId)) {
                $out['warning'] = 'Which consultant? This agency has consultants with their own referrer — set the agency contact on the request.';
            }
        }
    }
    $out['split'] = comm_split_out($db, $split);
    $out['label'] = comm_split_label($db, $split);
    $out['total'] = comm_split_total($split);
    // A split set by hand (Roberto) is a decision: no folder-tag warning on it.
    if (($out['snapshot_src'] ?? '') !== 'manual' && ($tw = comm_tag_warning($db, $r, $split)) !== null) {
        $out['warning'] = trim(($out['warning'] ?? '') . ' ' . $tw);
    }
    return $out;
}

/**
 * The request's own folder tag (practice_code "(Short-PS-Agent)", for a GRP member its subfolder) against its split:
 * "-PS" folder whose agency gives no Promoservice (e.g. a member stored with the group's agency), or a Promoservice /
 * Lamprati agency whose folder has no suffix. Warning text or null.
 */
function comm_tag_warning(PDO $db, array $r, array $split): ?string {
    if (!preg_match('/\(([^)]+)\)/', (string)($r['practice_code'] ?? ''), $m)) return null;
    $tag = $m[1];
    $tagType = comm_tag_type($tag);
    $refs = comm_referrers($db);
    $types = [];
    foreach ($split as $e) if (!empty($refs[$e['referrer_id']]['agency_type'])) $types[] = $refs[$e['referrer_id']]['agency_type'];
    $agency = comm_agency($db, (int)($r['agency_id'] ?? 0));
    $suffix = ['promoservice' => '-PS', 'lamprati' => '-LAM'];
    if ($tagType !== '' && !in_array($tagType, $types, true)) {
        return 'The folder says ' . $suffix[$tagType] . ' but the request\'s agency (' . ($agency['nome'] ?? 'none') . ') gives no '
             . $tagType . ' commission — check the agency of the request.';
    }
    if ($tagType === '' && $agency && isset($suffix[$agency['type']]) && in_array($agency['type'], $types, true) && stripos($tag, 'Drct') === false) {
        return 'Agency ' . $agency['nome'] . ' is ' . $agency['type'] . ' but the folder has no ' . $suffix[$agency['type']]
             . ' — check the agency type or the folder.';
    }
    return null;
}

/** Request row with the columns comm_resolve() needs. */
function comm_request_row(PDO $db, int $reqId): ?array {
    comm_schema($db);
    $st = $db->prepare("SELECT * FROM requests WHERE id = ?");
    $st->execute([$reqId]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Snapshot at confirmation: store the split resolved now (contact → agency) unless a snapshot is there already
 * (a manual one from Roberto, or a re-confirmation). $src: confirm | group_import | backfill.
 */
function comm_snapshot(PDO $db, int $reqId, string $src = 'confirm', bool $agencyOnly = false): ?array {
    try {
        $r = comm_request_row($db, $reqId);
        if (!$r || comm_split_parse($r['commission_split_json'] ?? null) !== null) return null;
        if ($agencyOnly) $r['agency_contact_id'] = null;   // backfill: history = agency default
        $res = comm_resolve($db, $r, true);
        $split = array_map(function ($e) { return ['referrer_id' => $e['referrer_id'], 'rate' => $e['rate']]; }, $res['split']);
        $db->prepare("UPDATE requests SET commission_split_json = ?, commission_split_at = ?, commission_split_by = ?, commission_split_src = ?
                      WHERE id = ? AND commission_split_json IS NULL")
           ->execute([comm_split_encode($split), date('Y-m-d H:i:s'), comm_actor(), $src, $reqId]);
        return $res;
    } catch (Throwable $e) {
        error_log('comm_snapshot ' . $reqId . ': ' . $e->getMessage());
        return null;
    }
}

/** Rollback of a confirmation: the snapshot taken by it goes too (a manual one stays). */
function comm_snapshot_clear(PDO $db, int $reqId): void {
    try {
        comm_schema($db);
        $db->prepare("UPDATE requests SET commission_split_json = NULL, commission_split_at = NULL, commission_split_by = NULL, commission_split_src = NULL
                      WHERE id = ? AND (commission_split_src IS NULL OR commission_split_src IN ('confirm','group_import','backfill'))")->execute([$reqId]);
    } catch (Throwable $e) {
        error_log('comm_snapshot_clear ' . $reqId . ': ' . $e->getMessage());
    }
}

/**
 * Roberto changes a booking's split (or sets it before confirmation). $split null = drop the snapshot
 * (back to contact → agency). $reason required. Timeline "Commission split changed".
 * Returns ok, error, from, to (labels), commission_split (after).
 */
function comm_set_request_split(PDO $db, int $reqId, ?array $split, string $reason, bool $dryRun = false): array {
    $r = comm_request_row($db, $reqId);
    if (!$r) return ['ok' => false, 'error' => 'Request ' . $reqId . ' not found.'];
    $reason = trim($reason);
    if ($reason === '') return ['ok' => false, 'error' => 'A reason is required.'];
    $before = comm_resolve($db, $r);
    $cur = comm_split_parse($r['commission_split_json'] ?? null);
    $r2 = $r;
    $r2['commission_split_json'] = $split === null ? null : comm_split_encode($split);
    $after = comm_resolve($db, $r2);
    $out = ['ok' => true, 'error' => null, 'from' => $before['label'] . ' (' . $before['source'] . ')',
            'to' => $after['label'] . ' (' . $after['source'] . ')', 'changed' => !comm_split_same($cur, $split), 'commission_split' => $after];
    if ($dryRun || !$out['changed']) return $out;
    $db->prepare("UPDATE requests SET commission_split_json = ?, commission_split_at = ?, commission_split_by = ?, commission_split_src = ? WHERE id = ?")
       ->execute([$r2['commission_split_json'], $split === null ? null : date('Y-m-d H:i:s'), $split === null ? null : comm_actor(),
                  $split === null ? null : 'manual', $reqId]);
    if (function_exists('timeline_log')) {
        timeline_log($reqId, 'status_change', 'Commission split changed: ' . $before['label'] . ' → ' . $after['label'],
                     ['body' => 'Reason: ' . $reason]);
    }
    return $out;
}

/** Set / clear the agency contact of a request (checked). Returns error text or null. */
function comm_request_contact_set(PDO $db, int $reqId, ?int $contactId): ?string {
    $r = comm_request_row($db, $reqId);
    if (!$r) return 'Request ' . $reqId . ' not found.';
    if ($contactId && ($e = comm_contact_check($db, (int)$r['agency_id'], $contactId))) return $e;
    $db->prepare("UPDATE requests SET agency_contact_id = ? WHERE id = ?")->execute([$contactId ?: null, $reqId]);
    return null;
}

/** Public shape for get / resume: contact + resolved split (provisional before confirmation, snapshot after). */
function comm_request_out(PDO $db, array $r): array {
    $res = comm_resolve($db, $r);
    return ['agency_contact' => $res['contact'], 'split' => $res['split'], 'label' => $res['label'], 'total' => $res['total'],
            'source' => $res['source'], 'provisional' => $res['provisional'], 'warning' => $res['warning'],
            'snapshot_at' => $res['snapshot_at'] ?? null];
}

/**
 * Backfill: agencies without a stored default get one from their type (savannah → none); confirmed bookings
 * without a snapshot get the agency default (not the contact: history stays as it was paid — LabTravel = Promoservice 5 %).
 * Returns counts + samples; writes only when !$dryRun.
 */
function comm_backfill(PDO $db, bool $dryRun = true, int $limit = 2000): array {
    comm_schema($db);
    $out = ['agencies' => ['count' => 0, 'by_label' => []], 'bookings' => ['count' => 0, 'by_label' => [], 'sample' => []]];
    foreach ($db->query("SELECT id, nome, type, referrer_split_json FROM agencies WHERE referrer_split_json IS NULL")->fetchAll(PDO::FETCH_ASSOC) as $a) {
        $split = comm_type_split($db, (string)$a['type']);
        $lab = comm_split_label($db, $split);
        $out['agencies']['count']++;
        $out['agencies']['by_label'][$lab] = ($out['agencies']['by_label'][$lab] ?? 0) + 1;
        if (!$dryRun) $db->prepare("UPDATE agencies SET referrer_split_json = ? WHERE id = ? AND referrer_split_json IS NULL")->execute([comm_split_encode($split), (int)$a['id']]);
    }
    $st = $db->query("SELECT * FROM requests WHERE commission_split_json IS NULL
                      AND (confirmation_date IS NOT NULL OR status IN ('Booked','Cancelled')) ORDER BY id LIMIT " . max(1, min(5000, $limit)));
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $r['agency_contact_id'] = null;
        $res = comm_resolve($db, $r, true);
        $out['bookings']['count']++;
        $out['bookings']['by_label'][$res['label']] = ($out['bookings']['by_label'][$res['label']] ?? 0) + 1;
        if (count($out['bookings']['sample']) < 30) {
            $out['bookings']['sample'][] = ['request_id' => (int)$r['id'], 'folder' => $r['practice_code'], 'split' => $res['label'], 'source' => $res['source']];
        }
        if (!$dryRun) comm_snapshot($db, (int)$r['id'], 'backfill', true);
    }
    return $out;
}

// ── Report ───────────────────────────────────────────────────────────────────

/** "2026-H1" / "2026-H2" from a date. */
function comm_semester(?string $d): ?string {
    if (!$d) return null;
    return substr($d, 0, 4) . '-H' . ((int)substr($d, 5, 2) <= 6 ? '1' : '2');
}

function comm_is_cancelled(array $r): bool {
    return strcasecmp((string)($r['status'] ?? ''), 'Cancelled') === 0
        || stripos((string)($r['practice_code'] ?? ''), '_CANCELLED') !== false;
}

/**
 * Commissions per booking and referrer.
 * $opt: referrer_id (0 = all), from / to (YYYY-MM-DD), basis booked (confirmation date, else date received)
 *       | travel (start date) | paid (date of the last payment, fully paid bookings only).
 * Returns basis, from, to, rows[] (one per booking × currency × referrer), totals[] (per referrer × currency, with
 * by_period: semester for Lamprati, month otherwise), warnings[].
 */
function comm_report(PDO $db, array $opt): array {
    comm_schema($db);
    $refs  = comm_referrers($db);
    $basis = in_array($opt['basis'] ?? 'booked', COMM_BASIS, true) ? $opt['basis'] : 'booked';
    $from  = (string)($opt['from'] ?? '') ?: date('Y') . '-01-01';
    $to    = (string)($opt['to'] ?? '') ?: date('Y') . '-12-31';
    $refId = (int)($opt['referrer_id'] ?? 0);
    $conf  = "(r.confirmation_date IS NOT NULL OR r.status IN ('Booked','Cancelled'))";
    $sql = "SELECT r.*, a.nome AS agency_name, c.name AS contact_name FROM requests r
            LEFT JOIN agencies a ON a.id = r.agency_id LEFT JOIN agency_contacts c ON c.id = r.agency_contact_id WHERE $conf AND ";
    if ($basis === 'travel') {
        $st = $db->prepare($sql . "r.start_date BETWEEN ? AND ? ORDER BY r.start_date, r.id");
        $st->execute([$from, $to]);
    } elseif ($basis === 'paid') {
        $st = $db->prepare($sql . "r.id IN (SELECT i.request_id FROM invoices i JOIN invoice_payments p ON p.invoice_id = i.id
                                              WHERE p.cancelled_at IS NULL AND p.payment_date BETWEEN ? AND ?) ORDER BY r.id");
        $st->execute([$from, $to]);
    } else {
        $st = $db->prepare($sql . "COALESCE(r.confirmation_date, r.date_received) BETWEEN ? AND ? ORDER BY COALESCE(r.confirmation_date, r.date_received), r.id");
        $st->execute([$from, $to]);
    }
    $reqs = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $reqs[(int)$r['id']] = $r;
    $out = ['basis' => $basis, 'from' => $from, 'to' => $to, 'referrer_id' => $refId ?: null, 'rows' => [], 'totals' => [], 'warnings' => []];
    if (!$reqs) return $out;

    // Money per request and currency.
    $ids = array_keys($reqs);
    $in  = implode(',', array_fill(0, count($ids), '?'));
    $money = [];   // [rid][cur] = {invoices[], unpaid[], total, cn, open, last_paid}
    $slot = function ($rid, $cur) use (&$money) {
        if (!isset($money[$rid][$cur])) $money[$rid][$cur] = ['invoices' => [], 'unpaid' => [], 'total' => 0.0, 'cn' => 0.0, 'open' => 0.0, 'last_paid' => null];
        return $cur;
    };
    $st = $db->prepare("SELECT i.id, i.request_id, i.invoice_number, i.currency, i.total, i.balance_due, i.amount_paid,
                               (SELECT MAX(p.payment_date) FROM invoice_payments p WHERE p.invoice_id = i.id AND p.cancelled_at IS NULL) AS last_paid
                        FROM invoices i WHERE i.status <> 'Cancelled' AND i.request_id IN ($in)");
    $st->execute($ids);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $i) {
        $rid = (int)$i['request_id']; $cur = $slot($rid, $i['currency'] ?: 'USD');
        $m = &$money[$rid][$cur];
        // Only paid invoices count (Roberto, 27 Nov 2026): one with nothing paid yet is left out and named in the note.
        if ((float)$i['total'] > 0 && (float)$i['amount_paid'] <= 0) { $m['unpaid'][] = $i['invoice_number']; unset($m); continue; }
        $m['invoices'][] = $i['invoice_number'];
        $m['total'] += (float)$i['total'];
        $m['open'] += max(0.0, (float)$i['balance_due']);
        if ($i['last_paid'] !== null && ($m['last_paid'] === null || $i['last_paid'] > $m['last_paid'])) $m['last_paid'] = $i['last_paid'];
        unset($m);
    }
    // Credit notes (currency of their invoice), minus credit applied to an invoice of the same request (sync_request_value()).
    $st = $db->prepare("SELECT c.request_id, COALESCE(i.currency, 'USD') AS currency, SUM(c.total) AS t FROM credit_notes c
                        LEFT JOIN invoices i ON i.id = c.invoice_id WHERE c.status <> 'Cancelled' AND c.request_id IN ($in)
                        GROUP BY c.request_id, COALESCE(i.currency, 'USD')");
    $st->execute($ids);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) { $cur = $slot((int)$c['request_id'], $c['currency']); $money[(int)$c['request_id']][$cur]['cn'] += (float)$c['t']; }
    $st = $db->prepare("SELECT c.request_id, COALESCE(si.currency, 'USD') AS currency, SUM(a.amount_cn) AS t FROM credit_note_allocations a
                        JOIN credit_notes c ON c.id = a.credit_note_id JOIN invoices ti ON ti.id = a.invoice_id
                        LEFT JOIN invoices si ON si.id = c.invoice_id
                        WHERE c.status <> 'Cancelled' AND a.cancelled_at IS NULL AND ti.request_id = c.request_id AND ti.status <> 'Cancelled'
                          AND c.request_id IN ($in) GROUP BY c.request_id, COALESCE(si.currency, 'USD')");
    try {
        $st->execute($ids);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) { $cur = $slot((int)$c['request_id'], $c['currency']); $money[(int)$c['request_id']][$cur]['cn'] -= (float)$c['t']; }
    } catch (PDOException $e) { /* allocations table missing: credit notes subtracted in full */ }

    foreach ($reqs as $rid => $r) {
        $res = comm_resolve($db, $r);
        if ($res['warning']) $out['warnings'][] = ['request_id' => $rid, 'folder' => $r['practice_code'], 'warning' => $res['warning']];
        $split = $res['split'];
        if ($refId) $split = array_values(array_filter($split, function ($e) use ($refId) { return $e['referrer_id'] === $refId; }));
        if (!$split) continue;
        $cancelled = comm_is_cancelled($r);
        $booked = $r['confirmation_date'] ?: $r['date_received'];
        $curs = $money[$rid] ?? ['USD' => ['invoices' => [], 'unpaid' => [], 'total' => 0.0, 'cn' => 0.0, 'open' => 0.0, 'last_paid' => null]];
        foreach ($curs as $cur => $m) {
            $paidDate = ($m['total'] > 0 && $m['open'] < 0.01) ? $m['last_paid'] : null;
            if ($basis === 'paid' && ($paidDate === null || $paidDate < $from || $paidDate > $to)) continue;
            $net = round($m['total'] - $m['cn'], 2);
            // Penalty = a cancelled booking only. A cancellation line inside a live trip ("Pemba cancellation fees")
            // stays in the base, as paid to Promoservice so far (Roberto, 27 Nov 2026).
            $penalty = $cancelled ? $net : 0.0;
            $notes = [];
            if (!$m['invoices']) $notes[] = $m['unpaid'] ? 'nothing paid yet' : 'no invoice';
            elseif ($m['open'] >= 0.01) $notes[] = 'not fully paid';
            if ($m['unpaid'] && $m['invoices']) $notes[] = 'unpaid left out: ' . implode(', ', $m['unpaid']);
            $dateKey = $basis === 'travel' ? $r['start_date'] : ($basis === 'paid' ? $paidDate : $booked);
            foreach ($split as $e) {
                $ref = $refs[$e['referrer_id']] ?? null;
                $excl = $ref && !(int)$ref['on_penalties'];
                $base = round($net - ($excl ? $penalty : 0.0), 2);
                $row = ['request_id' => $rid, 'folder' => $r['practice_code'], 'group_folder' => $r['group_folder'] ?? null,
                        'customer' => $r['customer_name'], 'agency' => $r['agency_name'] ?: comm_folder_tag($r),
                        'contact' => $r['contact_name'], 'status' => $r['status'], 'cancelled' => $cancelled,
                        'booked_date' => $booked, 'travel_date' => $r['start_date'] ?? null, 'paid_date' => $paidDate,
                        'period' => ($ref['period'] ?? 'month') === 'semester' ? comm_semester($dateKey) : ($dateKey ? substr($dateKey, 0, 7) : null),
                        'currency' => $cur, 'invoices' => implode(', ', $m['invoices']), 'invoice_total' => round($m['total'], 2),
                        'credit_notes' => round($m['cn'], 2), 'penalty' => $penalty, 'penalty_excluded' => $excl && $penalty != 0.0,
                        'base' => $base, 'referrer_id' => $e['referrer_id'], 'referrer' => $e['referrer'], 'rate' => $e['rate'],
                        'commission' => round($base * $e['rate'] / 100, 2), 'split' => $res['label'], 'split_source' => $res['source'],
                        'provisional' => $res['provisional'], 'note' => $notes ? implode('; ', $notes) : null];
                $out['rows'][] = $row;
                $k = $e['referrer_id'] . '|' . $cur;
                if (!isset($out['totals'][$k])) {
                    $out['totals'][$k] = ['referrer_id' => $e['referrer_id'], 'referrer' => $e['referrer'], 'currency' => $cur,
                                          'bookings' => 0, 'base' => 0.0, 'commission' => 0.0, 'by_period' => []];
                }
                $t = &$out['totals'][$k];
                $t['bookings']++; $t['base'] = round($t['base'] + $base, 2); $t['commission'] = round($t['commission'] + $row['commission'], 2);
                $p = $row['period'] ?? '?';
                if (!isset($t['by_period'][$p])) $t['by_period'][$p] = ['period' => $p, 'bookings' => 0, 'base' => 0.0, 'commission' => 0.0];
                $t['by_period'][$p]['bookings']++;
                $t['by_period'][$p]['base'] = round($t['by_period'][$p]['base'] + $base, 2);
                $t['by_period'][$p]['commission'] = round($t['by_period'][$p]['commission'] + $row['commission'], 2);
                unset($t);
            }
        }
    }
    foreach ($out['totals'] as &$t) { ksort($t['by_period']); $t['by_period'] = array_values($t['by_period']); }
    unset($t);
    $out['totals'] = array_values($out['totals']);
    return $out;
}

/** Report columns for CSV / XLSX: key => header. */
function comm_report_columns(): array {
    return ['folder' => 'Folder', 'agency' => 'Agency', 'contact' => 'Contact', 'status' => 'Status', 'booked_date' => 'Booked',
            'travel_date' => 'Travel', 'paid_date' => 'Paid', 'period' => 'Period', 'referrer' => 'Referrer', 'rate' => 'Rate %',
            'currency' => 'Cur', 'invoices' => 'Invoices', 'invoice_total' => 'Invoice total', 'credit_notes' => 'Credit notes',
            'penalty' => 'Penalty', 'penalty_excluded' => 'Penalty excluded', 'base' => 'Base', 'commission' => 'Commission',
            'split_source' => 'Split from', 'provisional' => 'Provisional', 'note' => 'Note'];
}

/** Rows as a table (header + data + blank + totals) of plain values. */
function comm_report_table(array $rep): array {
    $cols = comm_report_columns();
    $t = [array_values($cols)];
    foreach ($rep['rows'] as $r) {
        $line = [];
        foreach (array_keys($cols) as $k) {
            $v = $r[$k] ?? '';
            $line[] = is_bool($v) ? ($v ? 'yes' : '') : $v;
        }
        $t[] = $line;
    }
    $t[] = [];
    $t[] = ['Totals', 'Referrer', 'Currency', 'Period', 'Bookings', 'Base', 'Commission'];
    foreach ($rep['totals'] as $tot) {
        $t[] = ['', $tot['referrer'], $tot['currency'], 'all', $tot['bookings'], $tot['base'], $tot['commission']];
        foreach ($tot['by_period'] as $p) $t[] = ['', $tot['referrer'], $tot['currency'], $p['period'], $p['bookings'], $p['base'], $p['commission']];
    }
    return $t;
}

function comm_report_csv(array $rep): string {
    $fh = fopen('php://temp', 'w+');
    fwrite($fh, "\xEF\xBB\xBF");   // Excel reads UTF-8
    foreach (comm_report_table($rep) as $line) fputcsv($fh, $line, ';');
    rewind($fh);
    $s = stream_get_contents($fh);
    fclose($fh);
    return $s;
}

/** Minimal one-sheet XLSX (inline strings, numbers as numbers). */
function comm_report_xlsx(array $rep): string {
    $esc = function ($s) { return htmlspecialchars((string)$s, ENT_XML1 | ENT_QUOTES, 'UTF-8'); };
    $col = function (int $n): string { $s = ''; $n++; while ($n > 0) { $n--; $s = chr(65 + $n % 26) . $s; $n = intdiv($n, 26); } return $s; };
    $rowsXml = '';
    foreach (comm_report_table($rep) as $ri => $line) {
        $cells = '';
        foreach (array_values($line) as $ci => $v) {
            $ref = $col($ci) . ($ri + 1);
            if ($v === '' || $v === null) continue;
            $style = $ri === 0 ? ' s="1"' : '';
            if (is_int($v) || is_float($v)) $cells .= '<c r="' . $ref . '"' . $style . '><v>' . $v . '</v></c>';
            else $cells .= '<c r="' . $ref . '" t="inlineStr"' . $style . '><is><t>' . $esc($v) . '</t></is></c>';
        }
        $rowsXml .= '<row r="' . ($ri + 1) . '">' . $cells . '</row>';
    }
    $sheet = '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
           . $rowsXml . '</sheetData></worksheet>';
    $styles = '<?xml version="1.0" encoding="UTF-8"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    $tmp = tempnam(sys_get_temp_dir(), 'comm_');
    $zip = new ZipArchive();
    if ($tmp === false || $zip->open($tmp, ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Cannot build the XLSX file.');
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
        . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Commissions" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
    $zip->addFromString('xl/styles.xml', $styles);
    $zip->close();
    $bin = (string)file_get_contents($tmp);
    @unlink($tmp);
    return $bin;
}
