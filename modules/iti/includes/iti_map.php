<?php
/**
 * modules/iti/includes/iti_map.php
 * Static itinerary map (PHP GD) on the Esri World Topo basemap of the web map (tiles cached in
 * uploads/_maptiles; plain background if they cannot be fetched).
 * Draws a branded route map — numbered red markers + connecting line + labels —
 * from the same points as the interactive preview (iti_get_program_map_points).
 *
 * iti_render_itinerary_map(array $points, string $outFile): bool
 *   $points: [ ['name'=>string,'lat'=>float,'lng'=>float,
 *               'label'=>?string,   // pill text; defaults to 1-based position
 *               'airport'=>?bool],  // true draws a slate pin instead of red
 *              ... ]
 *   Points sharing coordinates merge into one marker (labels joined by " & ").
 *   Returns true on success (PNG written to $outFile), false otherwise.
 */

/** Locate a usable TTF font, or null to fall back to GD's built-in bitmap font. */
function iti_map_font(bool $bold = false): ?string {
    $names = $bold
        ? ['DejaVuSans-Bold.ttf','LiberationSans-Bold.ttf','FreeSansBold.ttf','arialbd.ttf']
        : ['DejaVuSans.ttf','LiberationSans-Regular.ttf','FreeSans.ttf','arial.ttf'];
    $dirs = [
        __DIR__ . '/../assets/',
        '/usr/share/fonts/truetype/dejavu/',
        '/usr/share/fonts/dejavu/',
        '/usr/share/fonts/truetype/liberation/',
        '/usr/share/fonts/liberation/',
        '/usr/share/fonts/gnu-free/',
        'C:/Windows/Fonts/',
    ];
    foreach ($dirs as $d) {
        foreach ($names as $n) {
            if (is_file($d . $n)) return $d . $n;
        }
    }
    return null;
}

/** Measure text width/height in px for the given font (TTF or built-in). */
function iti_map_text_size(string $text, float $size, ?string $ttf): array {
    if ($ttf) {
        $b = imagettfbbox($size, 0, $ttf, $text);
        return [abs($b[2] - $b[0]), abs($b[7] - $b[1])];
    }
    $f = 5;
    return [imagefontwidth($f) * strlen($text), imagefontheight($f)];
}

/** Draw text with a white halo for legibility. $x,$y = top-left. */
function iti_map_text($img, float $x, float $y, string $text, float $size, ?string $ttf, int $fg, int $halo): void {
    if ($ttf) {
        $by = $y + $size; // TTF baseline
        for ($dx = -1; $dx <= 1; $dx++) {
            for ($dy = -1; $dy <= 1; $dy++) {
                if ($dx || $dy) imagettftext($img, $size, 0, (int)round($x + $dx), (int)round($by + $dy), $halo, $ttf, $text);
            }
        }
        imagettftext($img, $size, 0, (int)round($x), (int)round($by), $fg, $ttf, $text);
    } else {
        $f = 5;
        for ($dx = -1; $dx <= 1; $dx++) {
            for ($dy = -1; $dy <= 1; $dy++) {
                if ($dx || $dy) imagestring($img, $f, (int)round($x + $dx), (int)round($y + $dy), $text, $halo);
            }
        }
        imagestring($img, $f, (int)round($x), (int)round($y), $text, $fg);
    }
}

/** Draw a horizontal pill (stadium) centred at $cx,$cy: two end circles of
 *  radius $r joined by a rectangle, spanning $cx±$halfW. A single number gives
 *  a circle ($halfW == $r); combined numbers ("2 & 4") widen it. */
function iti_map_pill($img, float $cx, float $cy, float $halfW, float $r, int $color): void {
    $halfW = max($halfW, $r);
    $lc = $cx - $halfW + $r; // left end-circle centre
    $rc = $cx + $halfW - $r; // right end-circle centre
    $d  = (int)round($r * 2);
    imagefilledellipse($img, (int)round($lc), (int)round($cy), $d, $d, $color);
    imagefilledellipse($img, (int)round($rc), (int)round($cy), $d, $d, $color);
    imagefilledrectangle($img,
        (int)round($lc), (int)round($cy - $r),
        (int)round($rc), (int)round($cy + $r), $color);
}

/** Web Mercator world coordinates, 0..1 (x from lng, y from lat, y down). */
function iti_map_mx(float $lng): float { return ($lng + 180.0) / 360.0; }
function iti_map_my(float $lat): float {
    $lat = max(-85.0, min(85.0, $lat));
    return (1.0 - log(tan(deg2rad($lat)) + 1.0 / cos(deg2rad($lat))) / M_PI) / 2.0;
}

/** One Esri World Topo tile (JPEG bytes), cached on disk for 90 days; null when it cannot be fetched. */
function iti_map_tile(int $z, int $x, int $y): ?string {
    $dir = __DIR__ . '/../uploads/_maptiles';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) $dir = sys_get_temp_dir() . '/iti_maptiles';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $f = $dir . '/' . $z . '_' . $x . '_' . $y . '.jpg';
    if (is_file($f) && filesize($f) > 0 && filemtime($f) > time() - 90 * 86400) return (string)file_get_contents($f);
    $url = 'https://server.arcgisonline.com/ArcGIS/rest/services/World_Topo_Map/MapServer/tile/' . $z . '/' . $y . '/' . $x;
    $data = null;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 4,
                                CURLOPT_FOLLOWLOCATION => true, CURLOPT_USERAGENT => 'SavannahExplorersHub/1.0 (itinerary map)']);
        $res = curl_exec($ch);
        if ($res !== false && curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200) $data = (string)$res;
        curl_close($ch);
    } else {
        $res = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 8, 'user_agent' => 'SavannahExplorersHub/1.0']]));
        if ($res !== false) $data = $res;
    }
    if ($data === null || strlen($data) < 100) return null;
    @file_put_contents($f, $data);
    return $data;
}

/**
 * Paint the topographic basemap on the supersampled canvas. ($wl, $wt) = world coords of the
 * canvas top-left, $ppu = final pixels per world unit. True when most tiles were drawn.
 */
function iti_map_basemap($img, float $wl, float $wt, float $ppu, int $W, int $H, int $SS): bool {
    if (!function_exists('imagecreatefromstring')) return false;
    $z = (int)max(2, min(13, round(log($ppu / 256.0, 2))));
    $n = 1 << $z;
    $tx0 = (int)floor($wl * $n); $tx1 = (int)floor(($wl + $W / $ppu) * $n);
    $ty0 = (int)floor($wt * $n); $ty1 = (int)floor(($wt + $H / $ppu) * $n);
    $total = ($tx1 - $tx0 + 1) * ($ty1 - $ty0 + 1);
    if ($total > 60) return false;
    $side = $ppu * $SS / $n;   // canvas px per tile
    $drawn = 0; $start = microtime(true);
    for ($ty = $ty0; $ty <= $ty1; $ty++) {
        for ($tx = $tx0; $tx <= $tx1; $tx++) {
            if ($ty < 0 || $ty >= $n || microtime(true) - $start > 25) continue;
            $data = iti_map_tile($z, (($tx % $n) + $n) % $n, $ty);
            $t = $data !== null ? @imagecreatefromstring($data) : false;
            if (!$t) continue;
            $dx = ($tx / $n - $wl) * $ppu * $SS; $dy = ($ty / $n - $wt) * $ppu * $SS;
            imagecopyresampled($img, $t, (int)floor($dx), (int)floor($dy), 0, 0, (int)ceil($side) + 1, (int)ceil($side) + 1, imagesx($t), imagesy($t));
            imagedestroy($t);
            $drawn++;
        }
    }
    if (!$drawn) return false;
    // Light wash so the red route and labels stay readable on the topo colours.
    imagealphablending($img, true);
    imagefilledrectangle($img, 0, 0, $W * $SS - 1, $H * $SS - 1, imagecolorallocatealpha($img, 255, 255, 255, 88));
    return $drawn * 2 >= $total;
}

function iti_render_itinerary_map(array $points, string $outFile): bool {
    if (!function_exists('imagecreatetruecolor') || !function_exists('imagepng')) return false;
    $points = array_values($points);
    $n = count($points);
    if ($n < 1) return false;

    // ── Geographic bounds ────────────────────────────────────────────
    $lats = array_column($points, 'lat');
    $lngs = array_column($points, 'lng');
    $minLat = min($lats); $maxLat = max($lats);
    $minLng = min($lngs); $maxLng = max($lngs);
    if ($maxLat - $minLat < 0.0001) { $minLat -= 0.15; $maxLat += 0.15; }
    if ($maxLng - $minLng < 0.0001) { $minLng -= 0.15; $maxLng += 0.15; }
    // 10% padding around the route
    $padLat = ($maxLat - $minLat) * 0.12 + 0.03;
    $padLng = ($maxLng - $minLng) * 0.12 + 0.03;
    $minLat -= $padLat; $maxLat += $padLat;
    $minLng -= $padLng; $maxLng += $padLng;

    // Web Mercator (0..1 world units), the projection of the basemap tiles.
    $X0 = iti_map_mx($minLng); $X1 = iti_map_mx($maxLng);
    $Y0 = iti_map_my($maxLat); $Y1 = iti_map_my($minLat);

    // ── Canvas geometry (supersampled for smooth edges) ──────────────
    $SS = 2;
    $W  = 900;  $M = 70;  $maxContentH = 560;
    $geoW = $X1 - $X0;
    $geoH = $Y1 - $Y0;
    $availW = $W - 2 * $M;
    $ppu = $availW / $geoW;               // final pixels per world unit
    $contentH = $ppu * $geoH;
    if ($contentH > $maxContentH) { $ppu = $maxContentH / $geoH; $contentH = $maxContentH; }
    $contentW = $ppu * $geoW;
    $H = (int)ceil($contentH + 2 * $M);
    $offX = $M + ($availW - $contentW) / 2.0;
    $offY = $M;

    $project = function ($lat, $lng) use ($offX, $offY, $X0, $Y0, $ppu, $SS) {
        return [
            ($offX + (iti_map_mx($lng) - $X0) * $ppu) * $SS,
            ($offY + (iti_map_my($lat) - $Y0) * $ppu) * $SS,
        ];
    };

    $img = imagecreatetruecolor($W * $SS, $H * $SS);
    if (!$img) return false;
    imageantialias($img, true);

    $c = fn($hex) => imagecolorallocate($img,
        hexdec(substr($hex,0,2)), hexdec(substr($hex,2,2)), hexdec(substr($hex,4,2)));
    $BG    = $c('F7F5F2');
    $BORDER= $c('E5E2DE');
    $RED   = $c('C0211B');
    $WHITE = $c('FFFFFF');
    $BLACK = $c('1A1A1A');
    $GREY  = $c('999591');
    $SLATE = $c('1F5673'); // airport (start/end) pins

    imagefilledrectangle($img, 0, 0, $W * $SS - 1, $H * $SS - 1, $BG);
    // Topographic basemap (same tiles as the web map); the plain background stays where a tile is missing.
    $hasBase = iti_map_basemap($img, $X0 - $offX / $ppu, $Y0 - $offY / $ppu, $ppu, $W, $H, $SS);
    imagesetthickness($img, $SS);
    imagerectangle($img, $SS, $SS, $W * $SS - 1 - $SS, $H * $SS - 1 - $SS, $BORDER);

    // Pre-compute pixel positions
    $pts = [];
    foreach ($points as $p) $pts[] = $project((float)$p['lat'], (float)$p['lng']);

    // Group points sharing coordinates so a repeated lodge draws one marker
    // ("2 & 4") instead of overlapping pins, and an airport used for both
    // arrival and departure shows once. The route line below still visits every
    // point in order, so an out-and-back leg stays visible.
    // Each point may carry an explicit 'label' (pill text) and 'airport' flag;
    // otherwise the label defaults to the point's 1-based position.
    $groups = []; $gidx = [];
    foreach ($points as $i => $p) {
        $lbl   = array_key_exists('label', $p) ? trim((string)$p['label']) : (string)($i + 1);
        $isAir = !empty($p['airport']);
        $key   = round((float)$p['lat'], 4) . ',' . round((float)$p['lng'], 4);
        if (isset($gidx[$key])) {
            if ($lbl !== '' && !in_array($lbl, $groups[$gidx[$key]]['labels'], true)) {
                $groups[$gidx[$key]]['labels'][] = $lbl;
            }
            if ($isAir) $groups[$gidx[$key]]['airport'] = true;
        } else {
            $gidx[$key] = count($groups);
            $groups[] = [
                'labels'  => ($lbl !== '' ? [$lbl] : []),
                'airport' => $isAir,
                'name'    => trim((string)($p['name'] ?? '')),
                'px'      => $pts[$i],
            ];
        }
    }

    // ── Route line: white casing + red on top ───────────────────────
    if ($n >= 2) {
        imageantialias($img, false);   // GD ignores the thickness while antialiasing (the supersampling smooths the line)
        imagesetthickness($img, (int)(7 * $SS));
        for ($i = 1; $i < $n; $i++)
            imageline($img, (int)$pts[$i-1][0], (int)$pts[$i-1][1], (int)$pts[$i][0], (int)$pts[$i][1], $WHITE);
        imagesetthickness($img, (int)(3 * $SS));
        for ($i = 1; $i < $n; $i++)
            imageline($img, (int)$pts[$i-1][0], (int)$pts[$i-1][1], (int)$pts[$i][0], (int)$pts[$i][1], $RED);
        imagesetthickness($img, $SS);
        imageantialias($img, true);
    }

    // ── Fonts ────────────────────────────────────────────────────────
    $ttf     = iti_map_font(false);
    $ttfBold = iti_map_font(true) ?? $ttf;
    $labelPt = 13 * $SS;
    $numPt   = 12 * $SS;

    // ── Labels (drawn before markers so markers sit on top) ──────────
    // Each name goes right, left, above or below its marker (also slightly shifted),
    // at the first spot that overlaps no marker and no label already placed. If none
    // is free, a shorter name is tried, then the name is left out (the legend has it).
    $r = 15 * $SS;
    $halfOf = function (array $g) use ($r, $numPt, $ttfBold, $SS) {
        [$nw0, ] = iti_map_text_size(implode(' & ', $g['labels']), $numPt, $ttfBold);
        return max($r, $nw0 / 2 + 8 * $SS);   // must match the marker loop
    };
    $boxes = [];   // occupied areas [x1, y1, x2, y2]: markers first, then the labels placed
    foreach ($groups as $g) {
        $hw = $halfOf($g) + 3 * $SS;
        $boxes[] = [$g['px'][0] - $hw, $g['px'][1] - $r - 3 * $SS, $g['px'][0] + $hw, $g['px'][1] + $r + 3 * $SS];
    }
    $free = function (array $b) use (&$boxes, $W, $H, $SS) {
        if ($b[0] < 4 * $SS || $b[1] < 3 * $SS || $b[2] > ($W - 4) * $SS || $b[3] > $H * $SS - 18 * $SS) return false;
        foreach ($boxes as $o) {
            if ($b[0] < $o[2] && $b[2] > $o[0] && $b[1] < $o[3] && $b[3] > $o[1]) return false;
        }
        return true;
    };
    $cut = function (string $s, int $max) {
        $len = function_exists('mb_strlen') ? mb_strlen($s) : strlen($s);
        if ($len <= $max) return $s;
        return function_exists('mb_substr') ? rtrim(mb_substr($s, 0, $max - 1)) . '…' : substr($s, 0, $max - 3) . '...';
    };
    foreach ($groups as $g) {
        [$cx, $cy] = $g['px'];
        if ($g['name'] === '') continue;
        $numLabel = implode(' & ', $g['labels']);
        $halfW = $halfOf($g);
        $gap = 6 * $SS;
        foreach ([28, 18] as $max) {
            $name  = $cut($g['name'], $max);
            $label = $numLabel === '' ? $name : $numLabel . '. ' . $name;
            [$tw, $th] = iti_map_text_size($label, $labelPt, $ttf);
            $mid = $cy - $th / 2;
            $cands = [
                [$cx + $halfW + $gap, $mid], [$cx - $halfW - $gap - $tw, $mid],                    // right, left
                [$cx - $tw / 2, $cy - $r - $gap - $th], [$cx - $tw / 2, $cy + $r + $gap],            // above, below
                [$cx + $halfW + $gap, $mid - $th - 2 * $SS], [$cx + $halfW + $gap, $mid + $th + 2 * $SS],
                [$cx - $halfW - $gap - $tw, $mid - $th - 2 * $SS], [$cx - $halfW - $gap - $tw, $mid + $th + 2 * $SS],
            ];
            foreach ($cands as $p) {
                $b = [$p[0] - 2 * $SS, $p[1] - 2 * $SS, $p[0] + $tw + 2 * $SS, $p[1] + $th + 4 * $SS];
                if ($free($b)) {
                    $boxes[] = $b;
                    iti_map_text($img, $p[0], $p[1], $label, $labelPt, $ttf, $BLACK, $WHITE);
                    continue 3;
                }
            }
        }
    }

    // ── Markers: pill, white ring, label (slate for airports) ────────
    foreach ($groups as $g) {
        [$cx, $cy] = $g['px'];
        $num = implode(' & ', $g['labels']);
        [$nw, $nh] = iti_map_text_size($num, $numPt, $ttfBold);
        $halfW = max($r, $nw / 2 + 8 * $SS);
        iti_map_pill($img, $cx, $cy, $halfW + 2 * $SS, $r + 2 * $SS, $WHITE);       // ring
        iti_map_pill($img, $cx, $cy, $halfW, $r, $g['airport'] ? $SLATE : $RED);    // fill
        $nx = $cx - $nw / 2;
        if ($ttfBold) {
            imagettftext($img, $numPt, 0, (int)round($nx), (int)round($cy + $nh / 2), $WHITE, $ttfBold, $num);
        } else {
            imagestring($img, 5, (int)round($nx), (int)round($cy - $nh / 2), $num, $WHITE);
        }
    }

    // ── Footnote ─────────────────────────────────────────────────────
    $foot = $hasBase ? 'Approximate locations · Tiles © Esri' : 'Approximate locations — not to scale';
    iti_map_text($img, 6 * $SS, $H * $SS - (14 * $SS), $foot, 9 * $SS, $ttf, $hasBase ? $BLACK : $GREY, $hasBase ? $WHITE : $BG);

    // ── Downsample to final size and save ────────────────────────────
    $final = imagecreatetruecolor($W, $H);
    imagecopyresampled($final, $img, 0, 0, 0, 0, $W, $H, $W * $SS, $H * $SS);
    $ok = imagepng($final, $outFile);
    imagedestroy($img);
    imagedestroy($final);
    return (bool)$ok;
}
