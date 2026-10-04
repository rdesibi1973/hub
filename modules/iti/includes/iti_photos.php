<?php
/**
 * iti_photos.php — photos of lodges (iti_lodges.photos, JSON list) and
 * destinations (iti_destinations.cover_photo): upload, resize, order, remove.
 *
 * Files go to modules/iti/uploads/<lodges|destinations>/ (server only, not in git).
 * The browser already shrinks big photos before upload (see iti_photo_editor());
 * the server shrinks again to ITI_PHOTO_MAX_SIDE when GD is there, and fixes the
 * EXIF rotation of phone pictures. The first photo of a list is the main one.
 * iti_photo_fetch() downloads a photo from a web link (Agent API: photos taken
 * from the lodges' websites) with the same checks and resizing.
 *
 * Self-contained (no iti_functions.php): the Agent API loads it next to the
 * leads config, which has its own db().
 */
if (!defined('ITI_MODULE_URL')) {
    define('ITI_MODULE_URL', (defined('BASE_URL') ? BASE_URL : 'https://hub.savannahexplorers.com') . '/modules/iti');
}

const ITI_PHOTO_MAX_SIDE  = 2000;              // px, longest side
const ITI_PHOTO_MAX_BYTES = 15 * 1024 * 1024;  // per uploaded file

/** iti_lodges.photos (JSON array of URLs, or of {"url": …}) → list of URLs. */
function iti_photos_decode($json): array {
    $a = is_string($json) && $json !== '' ? json_decode($json, true) : null;
    if (!is_array($a)) return [];
    $out = [];
    foreach ($a as $x) {
        $u = trim(is_array($x) ? (string)($x['url'] ?? '') : (string)$x);
        if ($u !== '') $out[] = $u;
    }
    return $out;
}

/** A destination's photos: its gallery (photos JSON), else the single cover_photo. First = cover. */
function iti_dest_photos(array $row): array {
    $list = iti_photos_decode($row['photos'] ?? null);
    $cover = trim((string)($row['cover_photo'] ?? ''));
    if (!$list && $cover !== '') $list = [$cover];
    return $list;
}

/** The photo columns, added when the live database lacks them (called on save). */
function iti_photos_schema(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $db = db();
        foreach ([['iti_lodges', 'photos', 'TEXT NULL DEFAULT NULL'], ['iti_destinations', 'cover_photo', 'VARCHAR(255) NULL DEFAULT NULL'],
                  ['iti_destinations', 'photos', 'TEXT NULL DEFAULT NULL']] as $c) {
            if (!$db->query('SHOW COLUMNS FROM `' . $c[0] . '` LIKE ' . $db->quote($c[1]))->fetch()) {
                $db->exec('ALTER TABLE `' . $c[0] . '` ADD COLUMN `' . $c[1] . '` ' . $c[2]);
            }
        }
    } catch (PDOException $e) {
        error_log('iti_photos_schema: ' . $e->getMessage());
    }
}

function iti_photo_dir(string $sub): string { return dirname(__DIR__) . '/uploads/' . $sub; }
function iti_photo_url_base(string $sub): string { return ITI_MODULE_URL . '/uploads/' . $sub; }

/** $_FILES['x'] of a multiple file input → list of single-file arrays. */
function iti_photo_files(array $f): array {
    if (!isset($f['name'])) return [];
    if (!is_array($f['name'])) return [$f];
    $out = [];
    foreach ($f['name'] as $i => $n) {
        $out[] = ['name' => $n, 'type' => $f['type'][$i] ?? '', 'tmp_name' => $f['tmp_name'][$i] ?? '',
                  'error' => $f['error'][$i] ?? UPLOAD_ERR_NO_FILE, 'size' => $f['size'][$i] ?? 0];
    }
    return $out;
}

/** "Serengeti Kifaru Tented Lodge" → "serengeti-kifaru-tented-lodge" (file name prefix). */
function iti_photo_slug(string $s): string {
    $s = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $s), '-'));
    return $s !== '' ? substr($s, 0, 40) : 'photo';
}

/** Save one uploaded image (form). Returns its public URL, or null with $err set. */
function iti_photo_save(array $file, string $sub, string $prefix, ?string &$err = null): ?string {
    $err = null;
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE || empty($file['tmp_name'])) return null;
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $err = in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
             ? $file['name'] . ': too large for the server.' : $file['name'] . ': upload failed (code ' . $file['error'] . ').';
        return null;
    }
    return iti_photo_store($file['tmp_name'], (string)$file['name'], $sub, $prefix, true, $err);
}

/**
 * Store an image file into uploads/<sub>/: checks it is a real JPG/PNG/WEBP/GIF,
 * then JPEG ≤ ITI_PHOTO_MAX_SIDE when GD can, else the file as it came.
 * $uploaded: the file came from a form (move_uploaded_file) or a download (rename).
 */
function iti_photo_store(string $path, string $label, string $sub, string $prefix, bool $uploaded, ?string &$err = null): ?string {
    $err = null;
    if (@filesize($path) > ITI_PHOTO_MAX_BYTES) { $err = $label . ': larger than 15 MB.'; return null; }
    $info = @getimagesize($path);
    $exts = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif'];
    if (!$info || !isset($exts[$info[2]])) { $err = $label . ': not a JPG, PNG, WEBP or GIF image.'; return null; }

    $dir = iti_photo_dir($sub);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) { $err = 'Cannot create the folder uploads/' . $sub . '.'; return null; }
    $base = iti_photo_slug($prefix) . '_' . date('ymdHis') . '_' . bin2hex(random_bytes(3));

    $img = iti_photo_mem_ok((int)$info[0], (int)$info[1]) ? iti_photo_load($path, $info[2]) : null;
    if ($img) {
        $img = iti_photo_fit($img, ITI_PHOTO_MAX_SIDE);
        $name = $base . '.jpg';
        $ok = imagejpeg($img, $dir . '/' . $name, 84);
        imagedestroy($img);
        if ($ok) return iti_photo_url_base($sub) . '/' . $name;
    }
    // No GD (or it failed): keep the original file.
    $name = $base . '.' . $exts[$info[2]];
    $ok = $uploaded ? move_uploaded_file($path, $dir . '/' . $name) : @rename($path, $dir . '/' . $name);
    if (!$ok) { $err = $label . ': could not be saved.'; return null; }
    return iti_photo_url_base($sub) . '/' . $name;
}

/** Download an image from a web link and store it like an upload. Returns its URL, or null with $err set. */
function iti_photo_fetch(string $url, string $sub, string $prefix, ?string &$err = null): ?string {
    $err = null;
    $tmp = tempnam(sys_get_temp_dir(), 'itiph');
    if ($tmp === false) { $err = 'No temp file.'; return null; }
    try {
        if (!iti_photo_download($url, $tmp, $err)) return null;
        return iti_photo_store($tmp, $url, $sub, $prefix, false, $err);
    } finally {
        if (is_file($tmp)) @unlink($tmp);
    }
}

/**
 * GET a public http(s) URL into $dest. Refuses hosts that resolve to private /
 * reserved addresses (no requests into the server's own network), pins the
 * checked IP for the request, follows at most 3 redirects (each re-checked),
 * stops past ITI_PHOTO_MAX_BYTES, 25 s timeout.
 */
function iti_photo_download(string $url, string $dest, ?string &$err = null): bool {
    if (!function_exists('curl_init')) { $err = 'cURL is not available on the server.'; return false; }
    for ($hop = 0; $hop <= 3; $hop++) {
        $p = parse_url($url);
        $scheme = strtolower((string)($p['scheme'] ?? ''));
        $host = (string)($p['host'] ?? '');
        if (!in_array($scheme, ['http', 'https'], true) || $host === '' || isset($p['user'])) { $err = $url . ': not a public http(s) link.'; return false; }
        $port = (int)($p['port'] ?? ($scheme === 'https' ? 443 : 80));
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        if (!$ips) { $err = $url . ': host not found.'; return false; }
        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) { $err = $url . ': private address refused.'; return false; }
        }
        $fh = fopen($dest, 'wb');
        if (!$fh) { $err = 'Cannot write the temp file.'; return false; }
        $size = 0; $location = '';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RESOLVE        => [$host . ':' . $port . ':' . $ips[0]],
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 25,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; SavannahExplorersHub/1.0; +https://www.savannahexplorers.com)',
            CURLOPT_HTTPHEADER     => ['Accept: image/webp,image/jpeg,image/png,image/*;q=0.8'],
            CURLOPT_HEADERFUNCTION => function ($c, $h) use (&$location) {
                if (stripos($h, 'Location:') === 0) $location = trim(substr($h, 9));
                return strlen($h);
            },
            CURLOPT_WRITEFUNCTION  => function ($c, $data) use ($fh, &$size) {
                $size += strlen($data);
                if ($size > ITI_PHOTO_MAX_BYTES) return 0;   // abort
                return fwrite($fh, $data);
            },
        ]);
        $ok   = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);
        fclose($fh);
        if (in_array($code, [301, 302, 303, 307, 308], true) && $location !== '') {
            $url = iti_photo_abs_url($location, $url);
            continue;
        }
        if ($size > ITI_PHOTO_MAX_BYTES) { $err = $url . ': larger than 15 MB.'; return false; }
        if (!$ok || $code !== 200) { $err = $url . ': download failed (' . ($code ?: $cerr) . ').'; return false; }
        return true;
    }
    $err = $url . ': too many redirects.';
    return false;
}

/** Resolve a redirect Location against the URL it came from. */
function iti_photo_abs_url(string $loc, string $base): string {
    if (preg_match('~^https?://~i', $loc)) return $loc;
    $b = parse_url($base);
    $root = $b['scheme'] . '://' . $b['host'] . (isset($b['port']) ? ':' . $b['port'] : '');
    if (strpos($loc, '//') === 0) return $b['scheme'] . ':' . $loc;
    if (strpos($loc, '/') === 0) return $root . $loc;
    $dir = isset($b['path']) ? preg_replace('~/[^/]*$~', '/', $b['path']) : '/';
    return $root . $dir . $loc;
}

/**
 * Enough memory to decode a w×h image with GD (≈ 5 bytes/pixel + the resized copy)?
 * Raises memory_limit up to 512M when needed; false = keep the original file instead.
 */
function iti_photo_mem_ok(int $w, int $h): bool {
    $need = (int)($w * $h * 5.5) + 40 * 1024 * 1024;
    $lim = trim((string)ini_get('memory_limit'));
    if ($lim === '-1') return true;
    $bytes = (int)$lim * (stripos($lim, 'G') ? 1073741824 : (stripos($lim, 'M') ? 1048576 : (stripos($lim, 'K') ? 1024 : 1)));
    if (memory_get_usage() + $need <= $bytes) return true;
    if ($need + memory_get_usage() > 512 * 1048576) return false;
    return @ini_set('memory_limit', '512M') !== false;
}

/** GD image from a file, EXIF rotation applied (JPEG); null without GD. */
function iti_photo_load(string $path, int $type) {
    if (!function_exists('imagecreatetruecolor')) return null;
    switch ($type) {
        case IMAGETYPE_JPEG: $img = @imagecreatefromjpeg($path); break;
        case IMAGETYPE_PNG:  $img = @imagecreatefrompng($path); break;
        case IMAGETYPE_WEBP: $img = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false; break;
        case IMAGETYPE_GIF:  $img = @imagecreatefromgif($path); break;
        default: $img = false;
    }
    if (!$img) return null;
    if ($type === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $exif = @exif_read_data($path);
        $o = (int)($exif['Orientation'] ?? 1);
        $deg = $o === 3 ? 180 : ($o === 6 ? -90 : ($o === 8 ? 90 : 0));
        if ($deg) { $r = imagerotate($img, $deg, 0); if ($r) { imagedestroy($img); $img = $r; } }
    }
    return $img;
}

/** Shrink so the longest side is ≤ $max (never enlarges); flattens transparency on white. */
function iti_photo_fit($img, int $max) {
    $w = imagesx($img); $h = imagesy($img);
    $k = min(1, $max / max($w, $h));
    $nw = max(1, (int)round($w * $k)); $nh = max(1, (int)round($h * $k));
    $out = imagecreatetruecolor($nw, $nh);
    imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
    imagecopyresampled($out, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
    imagedestroy($img);
    return $out;
}

/** Delete a photo file, only when the URL points into our uploads/<sub>/ folder. */
function iti_photo_unlink(string $url, string $sub): void {
    $base = iti_photo_url_base($sub) . '/';
    if (strpos($url, $base) !== 0) return;
    $name = substr($url, strlen($base));
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $name)) return;   // plain file name only
    $path = iti_photo_dir($sub) . '/' . $name;
    if (is_file($path)) @unlink($path);
}

/**
 * The photo list after a form post of iti_photo_editor():
 * photos_keep[] (existing photos, in the new order) + photos_urls (pasted links)
 * + photos_new[] (uploads). Photos dropped from the list are deleted from disk.
 * $max caps the list; $errors collects messages for the flash.
 */
function iti_photos_from_post(array $old, string $sub, string $prefix, int $max, array &$errors): array {
    $keep = []; $links = []; $ups = [];
    foreach ((array)($_POST['photos_keep'] ?? []) as $u) {
        $u = trim((string)$u);
        if ($u !== '' && in_array($u, $old, true)) $keep[] = $u;
    }
    foreach (preg_split('/\s+/', (string)($_POST['photos_urls'] ?? '')) as $u) {
        $u = trim($u);
        if ($u === '') continue;
        if (!preg_match('~^https?://~i', $u)) { $errors[] = 'Not a web link: ' . $u; continue; }
        $links[] = $u;
    }
    foreach (iti_photo_files($_FILES['photos_new'] ?? []) as $f) {
        if (count($keep) + count($links) + count($ups) >= $max && $max > 1) { $errors[] = 'Only ' . $max . ' photos kept; the rest was skipped.'; break; }
        $err = null;
        $url = iti_photo_save($f, $sub, $prefix, $err);
        if ($url !== null) $ups[] = $url;
        elseif ($err !== null) $errors[] = $err;
    }
    // One-photo fields (destination cover): the newest photo replaces the old one.
    $list = $max === 1 ? array_merge($ups, $links, $keep) : array_merge($keep, $links, $ups);
    $list = array_slice(array_values(array_unique($list)), 0, $max);
    foreach (array_merge($old, $ups) as $u) if (!in_array($u, $list, true)) iti_photo_unlink($u, $sub);
    return $list;
}

/**
 * Photo editor for a form with enctype="multipart/form-data": thumbnails to
 * reorder (◀ ▶) or remove (✕), file picker (several at once, shrunk in the
 * browser to ITI_PHOTO_MAX_SIDE before upload), box for pasted image links.
 * The first photo is marked as the main one.
 */
function iti_photo_editor(array $photos, int $max, string $hint = ''): string {
    ob_start(); ?>
<div class="ph-ed" data-max="<?= $max ?>">
  <div class="ph-grid">
    <?php foreach ($photos as $u): ?>
      <div class="ph-item">
        <img src="<?= h($u) ?>" alt="" loading="lazy">
        <input type="hidden" name="photos_keep[]" value="<?= h($u) ?>">
        <span class="ph-main">Main</span>
        <div class="ph-tools"><button type="button" data-act="left" title="Move left">◀</button><button type="button" data-act="right" title="Move right">▶</button><button type="button" data-act="del" title="Remove">✕</button></div>
      </div>
    <?php endforeach; ?>
  </div>
  <div class="ph-pending"></div>
  <div class="ph-add-row">
    <label class="btn btn-outline btn-sm ph-add">📷 <?= $max > 1 ? 'Add photos' : 'Upload photo' ?>
      <input type="file" name="photos_new[]" accept="image/jpeg,image/png,image/webp,image/gif"<?= $max > 1 ? ' multiple' : '' ?>>
    </label>
    <span class="ph-count"></span>
  </div>
  <textarea name="photos_urls" rows="2" placeholder="…or paste image links (https://…), one per line"></textarea>
  <div class="form-hint" style="margin-top:4px;"><?= $hint !== '' ? h($hint) . ' ' : '' ?>Max <?= $max ?> photo<?= $max > 1 ? 's' : '' ?>. Big photos are shrunk to <?= ITI_PHOTO_MAX_SIDE ?> px automatically. Changes are saved with the form.</div>
<style>
.ph-ed{margin-bottom:18px}
.ph-grid,.ph-pending{display:flex;flex-wrap:wrap;gap:10px;margin-bottom:10px}
.ph-pending:empty{display:none}
.ph-item{position:relative;width:150px;height:105px;border-radius:6px;overflow:hidden;background:#eee;border:1px solid #ddd}
.ph-item img{width:100%;height:100%;object-fit:cover;display:block}
.ph-item .ph-main{display:none;position:absolute;left:6px;top:6px;background:#C0211B;color:#fff;font-size:.65rem;font-weight:700;padding:2px 7px;border-radius:10px;text-transform:uppercase;letter-spacing:.05em}
.ph-grid .ph-item:first-child .ph-main{display:block}
.ph-item.new{border:2px dashed #1A6B3A}
.ph-item.new .ph-tag{position:absolute;left:6px;top:6px;background:#1A6B3A;color:#fff;font-size:.65rem;font-weight:700;padding:2px 7px;border-radius:10px}
.ph-tools{position:absolute;right:4px;bottom:4px;display:flex;gap:3px}
.ph-tools button{border:0;background:rgba(255,255,255,.92);border-radius:4px;width:24px;height:22px;font-size:.7rem;cursor:pointer;padding:0;line-height:22px}
.ph-tools button:hover{background:#fff;color:#C0211B}
.ph-add-row{display:flex;align-items:center;gap:12px;margin-bottom:8px}
.ph-add input{display:none}
.ph-add{cursor:pointer}
.ph-count{font-size:.78rem;color:#777}
.ph-ed textarea{width:100%;font-size:.82rem}
</style>
<script>
(function () {
  var ed = document.currentScript.parentNode, grid = ed.querySelector('.ph-grid'), pend = ed.querySelector('.ph-pending');
  var input = ed.querySelector('input[type=file]'), count = ed.querySelector('.ph-count');
  var MAX = +ed.getAttribute('data-max'), SIDE = <?= ITI_PHOTO_MAX_SIDE ?>;
  grid.addEventListener('click', function (e) {
    var b = e.target.closest('button'); if (!b) return;
    var it = b.closest('.ph-item');
    if (b.dataset.act === 'del') it.remove();
    if (b.dataset.act === 'left' && it.previousElementSibling) grid.insertBefore(it, it.previousElementSibling);
    if (b.dataset.act === 'right' && it.nextElementSibling) grid.insertBefore(it.nextElementSibling, it);
    info();
  });
  function info() {
    var n = grid.children.length + pend.children.length;
    count.textContent = n + ' / ' + MAX + (n > MAX ? ' — too many, the last ones will be skipped' : '');
    count.style.color = n > MAX ? '#C0211B' : '';
  }
  // Shrink a picture in the browser (keeps uploads small); falls back to the original file.
  function shrink(f) {
    return new Promise(function (ok) {
      if (!/^image\/(jpeg|png|webp)$/.test(f.type) || !window.createImageBitmap) return ok(f);
      createImageBitmap(f, {imageOrientation: 'from-image'}).then(function (bm) {
        var k = Math.min(1, SIDE / Math.max(bm.width, bm.height));
        if (k === 1 && f.size < 1.5e6) return ok(f);
        var c = document.createElement('canvas');
        c.width = Math.round(bm.width * k); c.height = Math.round(bm.height * k);
        var x = c.getContext('2d'); x.fillStyle = '#fff'; x.fillRect(0, 0, c.width, c.height); x.drawImage(bm, 0, 0, c.width, c.height);
        c.toBlob(function (b) { ok(b ? new File([b], f.name.replace(/\.\w+$/, '') + '.jpg', {type: 'image/jpeg'}) : f); }, 'image/jpeg', 0.86);
      }).catch(function () { ok(f); });
    });
  }
  input.addEventListener('change', function () {
    var files = Array.prototype.slice.call(input.files);
    pend.innerHTML = '';
    if (MAX === 1 && files.length) grid.innerHTML = '';   // a new photo replaces the old one
    count.textContent = files.length ? 'Preparing ' + files.length + ' photo(s)…' : '';
    Promise.all(files.map(shrink)).then(function (out) {
      try { var dt = new DataTransfer(); out.forEach(function (f) { dt.items.add(f); }); input.files = dt.files; } catch (e) { out = files; }
      out.forEach(function (f) {
        var d = document.createElement('div'); d.className = 'ph-item new';
        d.innerHTML = '<img alt=""><span class="ph-tag">New</span>';
        d.querySelector('img').src = URL.createObjectURL(f);
        pend.appendChild(d);
      });
      info();
    });
  });
  info();
})();
</script>
</div>
<?php
    return (string)ob_get_clean();
}
