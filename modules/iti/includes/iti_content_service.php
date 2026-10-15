<?php
/**
 * iti_content_service.php — ITI master data for the Agent API: lodges and
 * destinations (list / read), their photos (from web links, downloaded into
 * uploads/), and their texts / contacts / coordinates.
 *
 * Writes are plans unless $go: the API sends "confirm": true to apply them.
 * Self-contained like iti_photos.php (the API has the leads db(), not iti_functions.php).
 */
require_once __DIR__ . '/iti_photos.php';
require_once __DIR__ . '/iti_terms.php';   // iti_placeholder_sql(): the website blurb counts as a missing description

const ITI_CS_LANGS = ['en', 'it', 'fr', 'es', 'de'];
const ITI_CS_LODGE_MAX_PHOTOS = 12;

const ITI_CS_ACTIVITY_TYPES = ['game_drive', 'walking_safari', 'cultural', 'boat', 'balloon', 'hiking', 'beach', 'other'];

/** Text fields an agent may change (+ lat/lng, checked apart). */
function iti_cs_editable(string $kind): array {
    $desc = [];
    foreach (ITI_CS_LANGS as $l) $desc[] = 'description_' . $l;
    if ($kind === 'lodge') return array_merge(['website', 'phone', 'emergency_phone', 'email', 'address'], $desc);
    $names = [];
    foreach (ITI_CS_LANGS as $l) $names[] = 'name_' . $l;
    if ($kind === 'activity') return array_merge($names, $desc, ['activity_type', 'destination_id', 'duration_hours']);
    return array_merge($names, $desc, ['region']);
}

/** Activity row → API shape: every name_<lang> / description_<lang>, plus the languages that have one. */
function iti_cs_activity_out(array $r): array {
    $out = [
        'id' => (int)$r['id'], 'activity_type' => $r['activity_type'],
        'destination_id' => $r['destination_id'] !== null ? (int)$r['destination_id'] : null,
        'destination' => $r['dest_name'] ?? null,
        'duration_hours' => $r['duration_hours'] !== null ? (float)$r['duration_hours'] : null,
        'active' => (bool)$r['is_active'], 'name_langs' => [], 'description_langs' => [],
    ];
    foreach (ITI_CS_LANGS as $l) {
        $out['name_' . $l] = (string)($r['name_' . $l] ?? '');
        $out['description_' . $l] = (string)($r['description_' . $l] ?? '');
        if (trim($out['name_' . $l]) !== '') $out['name_langs'][] = $l;
        if (trim($out['description_' . $l]) !== '' && !iti_is_placeholder_text($out['description_' . $l])) $out['description_langs'][] = $l;
    }
    return $out;
}

/**
 * Activities. Filters: q (any name), destination (id, name or code), active (default 1, "all"),
 * missing: name_<lang> | description_<lang> (empty; descriptions also the website placeholder).
 */
function iti_cs_activities(PDO $db, array $f): array {
    $w = []; $a = [];
    $act = (string)($f['active'] ?? '1');
    if ($act !== 'all') { $w[] = 'a.is_active = ?'; $a[] = (int)$act; }
    if (($q = trim((string)($f['q'] ?? ''))) !== '') {
        $w[] = '(' . implode(' OR ', array_map(function ($l) { return 'a.name_' . $l . ' LIKE ?'; }, ITI_CS_LANGS)) . ')';
        foreach (ITI_CS_LANGS as $l) $a[] = "%$q%";
    }
    if (($d = trim((string)($f['destination'] ?? ''))) !== '') {
        if (ctype_digit($d)) { $w[] = 'a.destination_id = ?'; $a[] = (int)$d; }
        else { $w[] = '(d.name_en LIKE ? OR d.code = ?)'; array_push($a, "%$d%", strtoupper($d)); }
    }
    $miss = (string)($f['missing'] ?? '');
    if (preg_match('/^name_(en|it|fr|es|de)$/', $miss)) $w[] = "(a.$miss IS NULL OR TRIM(a.$miss) = '')";
    if (preg_match('/^description_(en|it|fr|es|de)$/', $miss)) $w[] = "(a.$miss IS NULL OR TRIM(a.$miss) = '' OR " . iti_placeholder_sql("a.$miss") . ")";
    $st = $db->prepare('SELECT a.*, d.name_en AS dest_name FROM iti_activities a LEFT JOIN iti_destinations d ON d.id = a.destination_id'
                     . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY a.name_en');
    $st->execute($a);
    return array_map('iti_cs_activity_out', $st->fetchAll(PDO::FETCH_ASSOC));
}

function iti_cs_activity_row(PDO $db, int $id): ?array {
    $st = $db->prepare('SELECT a.*, d.name_en AS dest_name FROM iti_activities a LEFT JOIN iti_destinations d ON d.id = a.destination_id WHERE a.id = ?');
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

/** Lodge row → API shape; $full adds the descriptions. */
function iti_cs_lodge_out(array $r, bool $full = false): array {
    $out = [
        'id' => (int)$r['id'], 'name' => $r['name'], 'destination_id' => (int)$r['destination_id'],
        'destination' => $r['dest_name'] ?? null, 'category' => $r['category'], 'type' => $r['lodge_type'],
        'website' => $r['website'] ?: null,
        'latitude' => $r['latitude'] !== null ? (float)$r['latitude'] : null,
        'longitude' => $r['longitude'] !== null ? (float)$r['longitude'] : null,
        'photos' => iti_photos_decode($r['photos'] ?? null),
        'description_langs' => [], 'active' => (bool)$r['is_active'],
        'needs_completion' => !empty($r['needs_completion']),   // stub made by iti_import_program
    ];
    foreach (ITI_CS_LANGS as $l) if (trim((string)($r['description_' . $l] ?? '')) !== '') $out['description_langs'][] = $l;
    if ($full) {
        foreach (['phone', 'emergency_phone', 'email', 'address'] as $k) $out[$k] = $r[$k] ?? null;
        foreach (ITI_CS_LANGS as $l) $out['description_' . $l] = (string)($r['description_' . $l] ?? '');
    }
    return $out;
}

/** Destination row → API shape; $full adds names and descriptions. */
function iti_cs_destination_out(array $r, bool $full = false): array {
    $out = [
        'id' => (int)$r['id'], 'code' => $r['code'], 'name' => $r['name_en'], 'region' => $r['region'],
        'country' => $r['country'] ?? null, 'type' => $r['dest_type'] ?? null, 'needs_completion' => !empty($r['needs_completion']),
        'latitude' => $r['latitude'] !== null ? (float)$r['latitude'] : null,
        'longitude' => $r['longitude'] !== null ? (float)$r['longitude'] : null,
        'cover_photo' => $r['cover_photo'] ?: null, 'photos' => iti_dest_photos($r), 'lodges' => (int)($r['n_lodges'] ?? 0),
        'description_langs' => [], 'active' => (bool)$r['is_active'],
    ];
    foreach (ITI_CS_LANGS as $l) if (trim((string)($r['description_' . $l] ?? '')) !== '') $out['description_langs'][] = $l;
    if ($full) {
        foreach (ITI_CS_LANGS as $l) { $out['name_' . $l] = (string)($r['name_' . $l] ?? ''); $out['description_' . $l] = (string)($r['description_' . $l] ?? ''); }
    }
    return $out;
}

/**
 * Lodges. Filters: q (name / destination), destination (id or name), active (default 1, "all"),
 * missing: photos | coords | website | description_<lang> (empty or the website placeholder) | placeholder ; limit ≤ 300.
 */
function iti_cs_lodges(PDO $db, array $f): array {
    iti_photos_schema();
    $w = []; $a = [];
    $act = (string)($f['active'] ?? '1');
    if ($act !== 'all') { $w[] = 'l.is_active = ?'; $a[] = (int)$act; }
    if (($q = trim((string)($f['q'] ?? ''))) !== '') { $w[] = '(l.name LIKE ? OR d.name_en LIKE ?)'; $a[] = "%$q%"; $a[] = "%$q%"; }
    if (($d = trim((string)($f['destination'] ?? ''))) !== '') {
        if (ctype_digit($d)) { $w[] = 'l.destination_id = ?'; $a[] = (int)$d; }
        else { $w[] = '(d.name_en LIKE ? OR d.code = ?)'; $a[] = "%$d%"; $a[] = strtoupper($d); }
    }
    $miss = (string)($f['missing'] ?? '');
    if ($miss === 'stub')    $w[] = in_array('needs_completion', iti_table_columns_cs($db, 'iti_lodges'), true) ? 'l.needs_completion = 1' : '0 = 1';
    if ($miss === 'photos')  $w[] = "(l.photos IS NULL OR l.photos IN ('', '[]', 'null'))";
    if ($miss === 'coords')  $w[] = '(l.latitude IS NULL OR l.longitude IS NULL)';
    if ($miss === 'website') $w[] = "(l.website IS NULL OR l.website = '')";
    if (preg_match('/^description_(en|it|fr|es|de)$/', $miss)) $w[] = "(l.$miss IS NULL OR l.$miss = '' OR " . iti_placeholder_sql("l.$miss") . ")";
    if ($miss === 'placeholder') {   // any language holding the website blurb
        $w[] = '(' . implode(' OR ', array_map(function ($l) { return iti_placeholder_sql('l.description_' . $l); }, ITI_CS_LANGS)) . ')';
    }
    $limit = max(1, min(300, (int)($f['limit'] ?? 300)));
    $st = $db->prepare('SELECT l.*, d.name_en AS dest_name FROM iti_lodges l LEFT JOIN iti_destinations d ON d.id = l.destination_id'
                     . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY d.sort_order, d.name_en, l.name LIMIT ' . $limit);
    $st->execute($a);
    return array_map('iti_cs_lodge_out', $st->fetchAll(PDO::FETCH_ASSOC));
}

function iti_cs_lodge_row(PDO $db, int $id): ?array {
    iti_photos_schema();
    $st = $db->prepare('SELECT l.*, d.name_en AS dest_name FROM iti_lodges l LEFT JOIN iti_destinations d ON d.id = l.destination_id WHERE l.id = ?');
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

/** Destinations. Filters: q, active (default 1, "all"), missing: photo | coords |
 * description_<lang> (empty or the website placeholder) | placeholder (in any language). */
function iti_cs_destinations(PDO $db, array $f): array {
    iti_photos_schema();
    $w = []; $a = [];
    $act = (string)($f['active'] ?? '1');
    if ($act !== 'all') { $w[] = 'd.is_active = ?'; $a[] = (int)$act; }
    if (($q = trim((string)($f['q'] ?? ''))) !== '') { $w[] = '(d.name_en LIKE ? OR d.name_it LIKE ? OR d.code = ? OR d.region LIKE ?)'; array_push($a, "%$q%", "%$q%", strtoupper($q), "%$q%"); }
    if (($c = trim((string)($f['country'] ?? ''))) !== '') {   // "KE" or "Kenya" (the column holds the name)
        $w[] = 'd.country = ?';
        $a[] = defined('ITI_COUNTRIES') && isset(ITI_COUNTRIES[strtoupper($c)]) ? ITI_COUNTRIES[strtoupper($c)] : $c;
    }
    $miss = (string)($f['missing'] ?? '');
    if ($miss === 'stub') $w[] = in_array('needs_completion', iti_table_columns_cs($db, 'iti_destinations'), true) ? 'd.needs_completion = 1' : '0 = 1';
    if ($miss === 'photo' || $miss === 'photos') $w[] = "(d.cover_photo IS NULL OR d.cover_photo = '')";
    if ($miss === 'coords') $w[] = '(d.latitude IS NULL OR d.longitude IS NULL)';
    if (preg_match('/^description_(en|it|fr|es|de)$/', $miss)) $w[] = "(d.$miss IS NULL OR d.$miss = '' OR " . iti_placeholder_sql("d.$miss") . ")";
    if ($miss === 'placeholder') {   // any language holding the website blurb
        $w[] = '(' . implode(' OR ', array_map(function ($l) { return iti_placeholder_sql('d.description_' . $l); }, ITI_CS_LANGS)) . ')';
    }
    $st = $db->prepare('SELECT d.*, (SELECT COUNT(*) FROM iti_lodges l WHERE l.destination_id = d.id AND l.is_active = 1) AS n_lodges
                          FROM iti_destinations d' . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY d.sort_order, d.name_en');
    $st->execute($a);
    return array_map('iti_cs_destination_out', $st->fetchAll(PDO::FETCH_ASSOC));
}

function iti_cs_destination_row(PDO $db, int $id): ?array {
    iti_photos_schema();
    $st = $db->prepare('SELECT d.* FROM iti_destinations d WHERE d.id = ?');
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

/**
 * Set the photos of a lodge or a destination (list, ≤ 12, first = main / cover).
 * Destination: "photo" = a new cover, put first (the others stay as gallery).
 * $in: photos [ordered final list] | add [append] , remove [drop], download (default true:
 * web links are copied into uploads/, false: kept as links).
 * Returns the plan; with $go the downloads happen and the row is written. Photos that
 * fail to download are left out and listed in errors.
 */
function iti_cs_set_photos(PDO $db, string $kind, int $id, array $in, bool $go): array {
    $isLodge = $kind === 'lodge';
    $row = $isLodge ? iti_cs_lodge_row($db, $id) : iti_cs_destination_row($db, $id);
    if (!$row) throw new InvalidArgumentException(($isLodge ? 'Lodge ' : 'Destination ') . $id . ' not found');
    $sub = $isLodge ? 'lodges' : 'destinations';
    $max = ITI_CS_LODGE_MAX_PHOTOS;
    $old = $isLodge ? iti_photos_decode($row['photos'] ?? null) : iti_dest_photos($row);
    $clean = function ($v) { $o = []; foreach ((array)$v as $u) { $u = trim((string)$u); if ($u !== '') $o[] = $u; } return $o; };

    if (array_key_exists('photos', $in))      $want = $clean($in['photos']);
    elseif (array_key_exists('photo', $in))   $want = array_merge($clean([$in['photo']]), $old);
    else                                      $want = array_merge($old, $clean($in['add'] ?? []));
    $drop = $clean($in['remove'] ?? []);
    $want = array_values(array_diff(array_unique($want), $drop));
    if (count($want) > $max) throw new InvalidArgumentException('At most ' . $max . ' photos (got ' . count($want) . ')');
    foreach ($want as $u) {
        if (!in_array($u, $old, true) && !preg_match('~^https?://~i', $u)) throw new InvalidArgumentException('Not a web link: ' . $u);
    }
    $download = !array_key_exists('download', $in) || !empty($in['download']);
    $name = $isLodge ? $row['name'] : ($row['code'] . '-' . $row['name_en']);

    // Files sent in the body (our own photos, no web link): appended after the links.
    $files = [];
    foreach ((array)($in['uploads'] ?? []) as $i => $f) {
        $b = is_array($f) ? base64_decode((string)($f['content_base64'] ?? ''), true) : false;
        if ($b === false || $b === '') throw new InvalidArgumentException('uploads[' . $i . ']: content_base64 missing or not base64');
        if (strlen($b) > ITI_PHOTO_MAX_BYTES) throw new InvalidArgumentException('uploads[' . $i . ']: larger than 15 MB');
        $files[] = ['name' => (string)($f['name'] ?? ('upload-' . $i)), 'data' => $b];
    }
    if (count($want) + count($files) > $max) throw new InvalidArgumentException('At most ' . $max . ' photos (got ' . (count($want) + count($files)) . ')');

    $plan = [];
    foreach ($want as $u) $plan[] = ['url' => $u, 'action' => in_array($u, $old, true) ? 'keep' : ($download ? 'download' : 'link')];
    foreach ($files as $i => $f) $plan[] = ['url' => $f['name'], 'action' => 'upload', 'file' => $i, 'bytes' => strlen($f['data'])];
    $removed = array_values(array_diff($old, $want));
    $res = ['id' => $id, 'name' => $isLodge ? $row['name'] : $row['name_en'], 'before' => $old, 'plan' => $plan, 'remove' => $removed];
    if (!$go) return $res;

    $final = []; $errors = [];
    if (function_exists('set_time_limit')) @set_time_limit(300);
    foreach ($plan as $p) {
        $err = null;
        if ($p['action'] === 'upload') {
            $tmp = tempnam(sys_get_temp_dir(), 'itiup');
            file_put_contents($tmp, $files[$p['file']]['data']);
            $local = iti_photo_store($tmp, $p['url'], $sub, $name, false, $err);
            if (is_file($tmp)) @unlink($tmp);
            if ($local !== null) $final[] = $local; else $errors[] = $err ?: ($p['url'] . ': failed');
            continue;
        }
        if ($p['action'] !== 'download') { $final[] = $p['url']; continue; }
        $local = iti_photo_fetch($p['url'], $sub, $name, $err);
        if ($local !== null) $final[] = $local; else $errors[] = $err ?: ($p['url'] . ': failed');
    }
    if ($isLodge) {
        $db->prepare('UPDATE iti_lodges SET photos = ? WHERE id = ?')
           ->execute([$final ? json_encode($final, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null, $id]);
    } else {
        $db->prepare('UPDATE iti_destinations SET photos = ?, cover_photo = ? WHERE id = ?')
           ->execute([$final ? json_encode($final, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null, $final ? $final[0] : '', $id]);
    }
    foreach ($removed as $u) iti_photo_unlink($u, $sub);
    return array_merge($res, ['photos' => $final, 'errors' => $errors]);
}

/**
 * New lodge. $f: name*, destination_id*, category (budget|mid|luxury|ultra_luxury), lodge_type
 * (lodge|tented_camp|hotel|mobile_camp|house) + any editable field (website, contacts, lat/lng,
 * description_<lang>). Refuses a name that already exists. Returns the plan; with $go the new id.
 */
function iti_cs_create_lodge(PDO $db, array $f, bool $go): array {
    iti_photos_schema();
    $name = trim((string)($f['name'] ?? ''));
    $did = (int)($f['destination_id'] ?? 0);
    if ($name === '' || !$did) throw new InvalidArgumentException('name and destination_id are required');
    if (!iti_cs_destination_row($db, $did)) throw new InvalidArgumentException('destination_id ' . $did . ' not found');
    $st = $db->prepare('SELECT id, name FROM iti_lodges WHERE name = ? OR name LIKE ?');
    $st->execute([$name, '%' . $name . '%']);
    if ($dup = $st->fetch(PDO::FETCH_ASSOC)) throw new InvalidArgumentException('Lodge already exists: #' . $dup['id'] . ' ' . $dup['name']);
    $cat = (string)($f['category'] ?? 'mid');
    $type = (string)($f['lodge_type'] ?? 'lodge');
    if (!in_array($cat, ['budget', 'mid', 'luxury', 'ultra_luxury'], true)) throw new InvalidArgumentException('category: budget|mid|luxury|ultra_luxury');
    if (!in_array($type, ['lodge', 'tented_camp', 'hotel', 'mobile_camp', 'house'], true)) throw new InvalidArgumentException('lodge_type: lodge|tented_camp|hotel|mobile_camp|house');
    $row = ['destination_id' => $did, 'name' => $name, 'category' => $cat, 'lodge_type' => $type, 'is_active' => 1];
    foreach (ITI_CS_LANGS as $l) $row['description_' . $l] = '';
    $extra = array_diff_key($f, array_flip(['name', 'destination_id', 'category', 'lodge_type']));
    if ($extra) {
        $allowed = array_merge(iti_cs_editable('lodge'), ['latitude', 'longitude']);
        $bad = array_values(array_diff(array_keys($extra), $allowed));
        if ($bad) throw new InvalidArgumentException('Not settable: ' . implode(', ', $bad));
        foreach ($extra as $k => $v) $row[$k] = ($k === 'latitude' || $k === 'longitude') ? ($v === '' || $v === null ? null : round((float)$v, 6)) : trim((string)$v);
    }
    $cols = iti_table_columns_cs($db, 'iti_lodges');
    $row = array_intersect_key($row, array_flip($cols));
    if (!$go) return ['would_create' => $row];
    $db->prepare('INSERT INTO iti_lodges (`' . implode('`,`', array_keys($row)) . '`) VALUES (' . implode(',', array_fill(0, count($row), '?')) . ')')
       ->execute(array_values($row));
    $id = (int)$db->lastInsertId();
    return ['created' => iti_cs_lodge_out(iti_cs_lodge_row($db, $id), true)];
}

/** Column names of a table (self-contained: the API has no iti_functions.php here). */
function iti_table_columns_cs(PDO $db, string $table): array {
    $out = [];
    foreach ($db->query('SHOW COLUMNS FROM `' . $table . '`')->fetchAll(PDO::FETCH_ASSOC) as $c) $out[] = $c['Field'];
    return $out;
}

/**
 * Change texts / contacts / coordinates of a lodge or destination. $fields: any of
 * iti_cs_editable($kind) + latitude, longitude ("" or null clears); a lodge also
 * destination_id, category, lodge_type (the name stays on the Lodges page). Returns the diff;
 * with $go it is written.
 */
function iti_cs_update(PDO $db, string $kind, int $id, array $fields, bool $go): array {
    $isLodge = $kind === 'lodge';
    $isAct = $kind === 'activity';
    $row = $isLodge ? iti_cs_lodge_row($db, $id) : ($isAct ? iti_cs_activity_row($db, $id) : iti_cs_destination_row($db, $id));
    if (!$row) throw new InvalidArgumentException(($isLodge ? 'Lodge ' : ($isAct ? 'Activity ' : 'Destination ')) . $id . ' not found');
    $allowed = $isAct ? iti_cs_editable($kind)
             : array_merge(iti_cs_editable($kind), ['latitude', 'longitude'], $isLodge ? ['destination_id', 'category', 'lodge_type'] : []);
    // A stub made by iti_import_program: 0 once photos / texts are done (only where the column exists).
    if (!$isAct && array_key_exists('needs_completion', $row)) $allowed[] = 'needs_completion';
    $unknown = array_values(array_diff(array_keys($fields), $allowed));
    if ($unknown) throw new InvalidArgumentException('Not editable here: ' . implode(', ', $unknown) . ' — allowed: ' . implode(', ', $allowed));

    $changes = [];
    foreach ($fields as $k => $v) {
        if ($isAct && $k === 'destination_id') {   // an activity may be generic (null = valid everywhere)
            $v = ($v === '' || $v === null || (int)$v === 0) ? null : (int)$v;
            if ($v !== null && !iti_cs_destination_row($db, $v)) throw new InvalidArgumentException('destination_id ' . $v . ' not found');
            $cur = $row[$k] !== null ? (int)$row[$k] : null;
        } elseif ($k === 'activity_type') {
            $v = trim((string)$v);
            if (!in_array($v, ITI_CS_ACTIVITY_TYPES, true)) throw new InvalidArgumentException('activity_type: ' . implode('|', ITI_CS_ACTIVITY_TYPES));
            $cur = (string)$row[$k];
        } elseif ($k === 'duration_hours') {
            if ($v === '' || $v === null) $v = null;
            elseif (!is_numeric($v) || (float)$v < 0 || (float)$v >= 1000) throw new InvalidArgumentException('duration_hours: a number of hours (0–999.9) or null');
            else $v = round((float)$v, 1);
            $cur = $row[$k] !== null ? round((float)$row[$k], 1) : null;
        } elseif ($k === 'destination_id') {
            $v = (int)$v;
            if (!$v || !iti_cs_destination_row($db, $v)) throw new InvalidArgumentException('destination_id ' . $v . ' not found');
            $cur = (int)$row[$k];
        } elseif ($k === 'category' || $k === 'lodge_type') {
            $v = trim((string)$v);
            $ok = $k === 'category' ? ['budget', 'mid', 'luxury', 'ultra_luxury'] : ['lodge', 'tented_camp', 'hotel', 'mobile_camp', 'house'];
            if (!in_array($v, $ok, true)) throw new InvalidArgumentException($k . ': ' . implode('|', $ok));
            $cur = (string)($row[$k] ?? '');
        } elseif ($k === 'needs_completion') {
            $v = empty($v) ? 0 : 1;
            $cur = (int)$row[$k];
        } elseif ($k === 'latitude' || $k === 'longitude') {
            if ($v === '' || $v === null) $v = null;
            elseif (!is_numeric($v) || abs((float)$v) > ($k === 'latitude' ? 90 : 180)) throw new InvalidArgumentException($k . ': not a valid coordinate');
            else $v = round((float)$v, 6);
            $cur = $row[$k] !== null ? round((float)$row[$k], 6) : null;
        } else {
            $v = trim((string)$v);
            if ($k === 'website' && $v !== '' && !preg_match('~^https?://~i', $v)) throw new InvalidArgumentException('website: must start with http(s)://');
            if ($k === 'email' && $v !== '' && !filter_var($v, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('email: not valid');
            if ($k === 'name_en' && $v === '') throw new InvalidArgumentException('name_en cannot be empty');
            $cur = (string)($row[$k] ?? '');
        }
        if ($v !== $cur) $changes[$k] = ['from' => $cur, 'to' => $v];
    }
    $res = ['id' => $id, 'name' => $isLodge ? $row['name'] : $row['name_en'], 'changes' => $changes];
    if (!$go || !$changes) return $res;

    $set = []; $args = [];
    foreach ($changes as $k => $c) { $set[] = '`' . $k . '` = ?'; $args[] = $c['to']; }
    $args[] = $id;
    $table = $isLodge ? 'iti_lodges' : ($isAct ? 'iti_activities' : 'iti_destinations');
    $db->prepare('UPDATE ' . $table . ' SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($args);
    if ($isAct) $res['activity'] = iti_cs_activity_out(iti_cs_activity_row($db, $id));
    return $res;
}
