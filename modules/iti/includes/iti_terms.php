<?php
/**
 * iti_terms.php — which Terms & Conditions a programme prints.
 *
 * Two standard versions live in iti_terms_conditions (Settings → T&C): one for DIRECT clients
 * (balance and cancellation penalties at 60 days) and one for AGENCIES (45 days). A personal
 * programme linked to a Hub request gets the version of the request's channel: the folder
 * "Name(Agent-Drct)" = direct client, anything else = agency. It is set on create and when the
 * linked request changes; a programme with its own dedicated T&C (override) is never touched.
 * With no T&C chosen, the document picks it from the linked request the same way.
 *
 * Used by iti_doc.php, iti_program_service.php (Agent API), programs.php, program_edit.php,
 * iti_final.php. The pure helpers at the top have no DB (tools/tests/iti_terms_test.php).
 *
 * Keep PHP-7 style.
 */

/** 'direct' for a request folder "(Agent-Drct)", 'agency' for any other "(…)" folder, null without one. */
function iti_terms_variant_from_folder(string $folder): ?string {
    if (!preg_match('/\(([^)]*)\)/', $folder, $m)) return null;
    return preg_match('/-\s*drct\s*$/i', $m[1]) ? 'direct' : 'agency';
}

/** Variant of a standard T&C version from its name: DIRECT / diretti → direct, AGENT / agenzie → agency, else null. */
function iti_terms_variant_of_name(string $name): ?string {
    if (preg_match('/\b(direct|dirett|directo|direkt)/i', $name)) return 'direct';
    if (preg_match('/\b(agent|agenz|agenc)/i', $name)) return 'agency';
    return null;
}

/** The website's meta description pasted in place of a real description (seen in iti_destinations). */
function iti_is_placeholder_text(string $txt): bool {
    $t = trim(preg_replace('/\s+/u', ' ', $txt));
    return $t !== '' && (bool)preg_match('/^Savannah Explorers\s*[-–—]\s*Tour Operator for Safari in Tanzania/i', $t);
}

/** SQL condition: column holds that placeholder (for the missing=description_<lang> filters). */
function iti_placeholder_sql(string $col): string {
    return "($col LIKE 'Savannah Explorers - Tour Operator for Safari in Tanzania%' OR $col LIKE 'Savannah Explorers – Tour Operator for Safari in Tanzania%')";
}

// ── With the DB ──────────────────────────────────────────────────────────────

/** Variant of a Hub request (its folder, or its group folder), null when not found / no folder. */
function iti_terms_variant_for_request(PDO $db, int $requestId): ?string {
    if ($requestId <= 0) return null;
    $st = $db->prepare('SELECT practice_code, group_folder FROM requests WHERE id = ?');
    $st->execute(array($requestId));
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;
    $v = iti_terms_variant_from_folder((string)$r['practice_code']);
    return $v !== null ? $v : iti_terms_variant_from_folder((string)$r['group_folder']);
}

/** The active standard version for a variant (latest effective date), or null. */
function iti_terms_row_for_variant(PDO $db, string $variant) {
    $rows = $db->query('SELECT * FROM iti_terms_conditions WHERE is_active = 1 AND (program_id IS NULL OR program_id = 0)
                        ORDER BY effective_date DESC, id DESC')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) if (iti_terms_variant_of_name((string)$r['name']) === $variant) return $r;
    return null;
}

/** True when the programme has its own dedicated T&C (Program → T&C override). */
function iti_terms_has_override(PDO $db, int $pid): bool {
    $st = $db->prepare('SELECT COUNT(*) FROM iti_terms_conditions WHERE program_id = ?');
    $st->execute(array($pid));
    return (int)$st->fetchColumn() > 0;
}

/**
 * Set terms_id of programme $pid to $variant ('direct' | 'agency'), or to its linked request's
 * variant when $variant is null. Nothing changes with a dedicated override or no standard
 * version for the variant. Returns ['variant', 'terms_id', 'name', 'changed'] or null.
 */
function iti_terms_apply(PDO $db, int $pid, ?string $variant = null, bool $go = true): ?array {
    $st = $db->prepare('SELECT id, terms_id, lead_request_id FROM iti_programs WHERE id = ?');
    $st->execute(array($pid));
    $p = $st->fetch(PDO::FETCH_ASSOC);
    if (!$p || iti_terms_has_override($db, $pid)) return null;
    if ($variant === null) $variant = iti_terms_variant_for_request($db, (int)($p['lead_request_id'] ?? 0));
    if ($variant === null) return null;
    $tr = iti_terms_row_for_variant($db, $variant);
    if (!$tr) return null;
    $changed = (int)$p['terms_id'] !== (int)$tr['id'];
    if ($go && $changed) $db->prepare('UPDATE iti_programs SET terms_id = ? WHERE id = ?')->execute(array((int)$tr['id'], $pid));
    return array('variant' => $variant, 'terms_id' => (int)$tr['id'], 'name' => $tr['name'], 'changed' => $changed);
}

/**
 * The T&C row a programme prints and why: its terms_id ('program'), else the linked
 * request's variant ('request'), else the latest active standard version ('default').
 * Returns ['row' => ?array, 'source', 'variant' (direct | agency | custom | null)].
 */
function iti_terms_resolve(PDO $db, array $p): array {
    $tr = null; $source = 'default';
    if (!empty($p['terms_id'])) {
        $s = $db->prepare('SELECT * FROM iti_terms_conditions WHERE id = ?');
        $s->execute(array((int)$p['terms_id']));
        $tr = $s->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($tr) $source = 'program';
    }
    if (!$tr && ($v = iti_terms_variant_for_request($db, (int)($p['lead_request_id'] ?? 0))) !== null) {
        $tr = iti_terms_row_for_variant($db, $v);
        if ($tr) $source = 'request';
    }
    if (!$tr) {
        try {
            $tr = $db->query('SELECT * FROM iti_terms_conditions WHERE is_active = 1 AND (program_id IS NULL OR program_id = 0) ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (PDOException $e) {   // older schema without program_id
            $tr = $db->query('SELECT * FROM iti_terms_conditions WHERE is_active = 1 ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: null;
        }
    }
    $variant = null;
    if ($tr) $variant = !empty($tr['program_id']) ? 'custom' : iti_terms_variant_of_name((string)$tr['name']);
    return array('row' => $tr, 'source' => $source, 'variant' => $variant);
}
