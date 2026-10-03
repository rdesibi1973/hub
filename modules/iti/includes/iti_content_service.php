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

const ITI_CS_LANGS = ['en', 'it', 'fr', 'es', 'de'];
const ITI_CS_LODGE_MAX_PHOTOS = 12;

/** Text fields an agent may change (+ lat/lng, checked apart). */
function iti_cs_editable(string $kind): array {
    $desc = [];
    foreach (ITI_CS_LANGS as $l) $desc[] = 'description_' . $l;
    if ($kind === 'lodge') return array_merge(['website', 'phone', 'emergency_phone', 'email', 'address'], $desc);
    $names = [];
    foreach (ITI_CS_LANGS as $l) $names[] = 'name_' . $l;
    return array_merge($names, $desc, ['region']);
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
        'latitude' => $r['latitude'] !== null ? (float)$r['latitude'] : null,
        'longitude' => $r['longitude'] !== null ? (float)$r['longitude'] : null,
        'cover_photo' => $r['cover_photo'] ?: null, 'lodges' => (int)($r['n_lodges'] ?? 0),
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
 * missing: photos | coords | website | description_<lang> ; limit ≤ 300.
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
    if ($miss === 'photos')  $w[] = "(l.photos IS NULL OR l.photos IN ('', '[]', 'null'))";
    if ($miss === 'coords')  $w[] = '(l.latitude IS NULL OR l.longitude IS NULL)';
    if ($miss === 'website') $w[] = "(l.website IS NULL OR l.website = '')";
    if (preg_match('/^description_(en|it|fr|es|de)$/', $miss)) $w[] = "(l.$miss IS NULL OR l.$miss = '')";
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

/** Destinations. Filters: q, active (default 1, "all"), missing: photo | coords | description_<lang>. */
function iti_cs_destinations(PDO $db, array $f): array {
    iti_photos_schema();
    $w = []; $a = [];
    $act = (string)($f['active'] ?? '1');
    if ($act !== 'all') { $w[] = 'd.is_active = ?'; $a[] = (int)$act; }
    if (($q = trim((string)($f['q'] ?? ''))) !== '') { $w[] = '(d.name_en LIKE ? OR d.name_it LIKE ? OR d.code = ? OR d.region LIKE ?)'; array_push($a, "%$q%", "%$q%", strtoupper($q), "%$q%"); }
    $miss = (string)($f['missing'] ?? '');
    if ($miss === 'photo')  $w[] = "(d.cover_photo IS NULL OR d.cover_photo = '')";
    if ($miss === 'coords') $w[] = '(d.latitude IS NULL OR d.longitude IS NULL)';
    if (preg_match('/^description_(en|it|fr|es|de)$/', $miss)) $w[] = "(d.$miss IS NULL OR d.$miss = '')";
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
 * Set the photos of a lodge (list, ≤ 12, first = main) or the cover of a destination (1).
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
    $max = $isLodge ? ITI_CS_LODGE_MAX_PHOTOS : 1;
    $old = $isLodge ? iti_photos_decode($row['photos'] ?? null) : (trim((string)$row['cover_photo']) !== '' ? [trim((string)$row['cover_photo'])] : []);
    $clean = function ($v) { $o = []; foreach ((array)$v as $u) { $u = trim((string)$u); if ($u !== '') $o[] = $u; } return $o; };

    if (array_key_exists('photos', $in))      $want = $clean($in['photos']);
    elseif (array_key_exists('photo', $in))   $want = $clean([$in['photo']]);
    else                                      $want = array_merge($old, $clean($in['add'] ?? []));
    $drop = $clean($in['remove'] ?? []);
    $want = array_values(array_diff(array_unique($want), $drop));
    if (!$isLodge && count($want) > 1) $want = [end($want)];   // cover: the new one wins
    if (count($want) > $max) throw new InvalidArgumentException('At most ' . $max . ' photos (got ' . count($want) . ')');
    foreach ($want as $u) {
        if (!in_array($u, $old, true) && !preg_match('~^https?://~i', $u)) throw new InvalidArgumentException('Not a web link: ' . $u);
    }
    $download = !array_key_exists('download', $in) || !empty($in['download']);
    $name = $isLodge ? $row['name'] : ($row['code'] . '-' . $row['name_en']);

    $plan = [];
    foreach ($want as $u) $plan[] = ['url' => $u, 'action' => in_array($u, $old, true) ? 'keep' : ($download ? 'download' : 'link')];
    $removed = array_values(array_diff($old, $want));
    $res = ['id' => $id, 'name' => $isLodge ? $row['name'] : $row['name_en'], 'before' => $old, 'plan' => $plan, 'remove' => $removed];
    if (!$go) return $res;

    $final = []; $errors = [];
    if (function_exists('set_time_limit')) @set_time_limit(300);
    foreach ($plan as $p) {
        if ($p['action'] !== 'download') { $final[] = $p['url']; continue; }
        $err = null;
        $local = iti_photo_fetch($p['url'], $sub, $name, $err);
        if ($local !== null) $final[] = $local; else $errors[] = $err ?: ($p['url'] . ': failed');
    }
    if ($isLodge) {
        $db->prepare('UPDATE iti_lodges SET photos = ? WHERE id = ?')
           ->execute([$final ? json_encode($final, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null, $id]);
    } else {
        $db->prepare('UPDATE iti_destinations SET cover_photo = ? WHERE id = ?')->execute([$final ? $final[0] : '', $id]);
    }
    foreach ($removed as $u) iti_photo_unlink($u, $sub);
    return array_merge($res, ['photos' => $final, 'errors' => $errors]);
}

/**
 * Change texts / contacts / coordinates of a lodge or destination. $fields: any of
 * iti_cs_editable($kind) + latitude, longitude ("" or null clears). Returns the diff;
 * with $go it is written.
 */
function iti_cs_update(PDO $db, string $kind, int $id, array $fields, bool $go): array {
    $isLodge = $kind === 'lodge';
    $row = $isLodge ? iti_cs_lodge_row($db, $id) : iti_cs_destination_row($db, $id);
    if (!$row) throw new InvalidArgumentException(($isLodge ? 'Lodge ' : 'Destination ') . $id . ' not found');
    $allowed = array_merge(iti_cs_editable($kind), ['latitude', 'longitude']);
    $unknown = array_values(array_diff(array_keys($fields), $allowed));
    if ($unknown) throw new InvalidArgumentException('Not editable here: ' . implode(', ', $unknown) . ' — allowed: ' . implode(', ', $allowed));

    $changes = [];
    foreach ($fields as $k => $v) {
        if ($k === 'latitude' || $k === 'longitude') {
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
    $db->prepare('UPDATE ' . ($isLodge ? 'iti_lodges' : 'iti_destinations') . ' SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($args);
    return $res;
}
