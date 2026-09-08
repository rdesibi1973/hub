<?php
/**
 * modules/iti/includes/iti_map.php
 * Self-contained static itinerary map (PHP GD, no external services).
 * Draws a branded route map — numbered red markers + connecting line + labels —
 * from the same points as the interactive preview (iti_get_program_map_points).
 *
 * iti_render_itinerary_map(array $points, string $outFile): bool
 *   $points: [ ['day'=>int,'name'=>string,'lat'=>float,'lng'=>float], ... ]
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

    $midLat = ($minLat + $maxLat) / 2.0;
    $cosMid = max(0.2, cos(deg2rad($midLat)));

    // ── Canvas geometry (supersampled for smooth edges) ──────────────
    $SS = 2;
    $W  = 900;  $M = 70;  $maxContentH = 560;
    $geoW = ($maxLng - $minLng) * $cosMid;
    $geoH = ($maxLat - $minLat);
    $availW = $W - 2 * $M;
    $ppd = $availW / $geoW;
    $contentH = $ppd * $geoH;
    if ($contentH > $maxContentH) { $ppd = $maxContentH / $geoH; $contentH = $maxContentH; }
    $contentW = $ppd * $geoW;
    $H = (int)ceil($contentH + 2 * $M);
    $offX = $M + ($availW - $contentW) / 2.0;
    $offY = $M;

    $project = function ($lat, $lng) use ($offX, $offY, $minLng, $maxLat, $cosMid, $ppd, $SS) {
        return [
            ($offX + ($lng - $minLng) * $cosMid * $ppd) * $SS,
            ($offY + ($maxLat - $lat) * $ppd) * $SS,
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

    imagefilledrectangle($img, 0, 0, $W * $SS - 1, $H * $SS - 1, $BG);
    imagesetthickness($img, $SS);
    imagerectangle($img, $SS, $SS, $W * $SS - 1 - $SS, $H * $SS - 1 - $SS, $BORDER);

    // Pre-compute pixel positions
    $pts = [];
    foreach ($points as $p) $pts[] = $project((float)$p['lat'], (float)$p['lng']);

    // Group stops sharing coordinates so a repeated lodge draws one marker
    // ("2 & 4") instead of overlapping pins. The route line below still visits
    // every point in order, so an out-and-back leg stays visible.
    $groups = []; $gidx = [];
    foreach ($points as $i => $p) {
        $key = round((float)$p['lat'], 4) . ',' . round((float)$p['lng'], 4);
        if (isset($gidx[$key])) {
            $groups[$gidx[$key]]['nums'][] = $i + 1;
        } else {
            $gidx[$key] = count($groups);
            $groups[] = [
                'nums' => [$i + 1],
                'name' => trim((string)($p['name'] ?? '')),
                'px'   => $pts[$i],
            ];
        }
    }

    // ── Route line: white casing + red on top ───────────────────────
    if ($n >= 2) {
        imagesetthickness($img, (int)(7 * $SS));
        for ($i = 1; $i < $n; $i++)
            imageline($img, (int)$pts[$i-1][0], (int)$pts[$i-1][1], (int)$pts[$i][0], (int)$pts[$i][1], $WHITE);
        imagesetthickness($img, (int)(3 * $SS));
        for ($i = 1; $i < $n; $i++)
            imageline($img, (int)$pts[$i-1][0], (int)$pts[$i-1][1], (int)$pts[$i][0], (int)$pts[$i][1], $RED);
        imagesetthickness($img, $SS);
    }

    // ── Fonts ────────────────────────────────────────────────────────
    $ttf     = iti_map_font(false);
    $ttfBold = iti_map_font(true) ?? $ttf;
    $labelPt = 13 * $SS;
    $numPt   = 12 * $SS;

    // ── Labels (drawn before markers so markers sit on top) ──────────
    $r = 15 * $SS;
    foreach ($groups as $g) {
        [$cx, $cy] = $g['px'];
        $name = $g['name'];
        if ($name === '') continue;
        $nlen = function_exists('mb_strlen') ? mb_strlen($name) : strlen($name);
        if ($nlen > 28) {
            $name = function_exists('mb_substr') ? mb_substr($name, 0, 27) . '…' : substr($name, 0, 27) . '...';
        }
        $numLabel = implode(' & ', $g['nums']);
        $label = $numLabel . '. ' . $name;
        [$tw, $th]  = iti_map_text_size($label, $labelPt, $ttf);
        [$nw0, ]    = iti_map_text_size($numLabel, $numPt, $ttfBold);
        $halfW = max($r, $nw0 / 2 + 8 * $SS); // must match the marker loop
        $tx = $cx + $halfW + 6 * $SS;
        if ($tx + $tw > $W * $SS - 6 * $SS) $tx = $cx - $halfW - 6 * $SS - $tw; // flip to left edge
        $ty = $cy - $th / 2;
        $ty = max(3 * $SS, min($ty, $H * $SS - $th - 3 * $SS));
        iti_map_text($img, $tx, $ty, $label, $labelPt, $ttf, $BLACK, $WHITE);
    }

    // ── Markers: red pill, white ring, number(s) ─────────────────────
    foreach ($groups as $g) {
        [$cx, $cy] = $g['px'];
        $num = implode(' & ', $g['nums']);
        [$nw, $nh] = iti_map_text_size($num, $numPt, $ttfBold);
        $halfW = max($r, $nw / 2 + 8 * $SS);
        iti_map_pill($img, $cx, $cy, $halfW + 2 * $SS, $r + 2 * $SS, $WHITE); // ring
        iti_map_pill($img, $cx, $cy, $halfW, $r, $RED);                       // fill
        $nx = $cx - $nw / 2;
        if ($ttfBold) {
            imagettftext($img, $numPt, 0, (int)round($nx), (int)round($cy + $nh / 2), $WHITE, $ttfBold, $num);
        } else {
            imagestring($img, 5, (int)round($nx), (int)round($cy - $nh / 2), $num, $WHITE);
        }
    }

    // ── Footnote ─────────────────────────────────────────────────────
    $foot = 'Approximate locations — not to scale';
    iti_map_text($img, 6 * $SS, $H * $SS - (14 * $SS), $foot, 9 * $SS, $ttf, $GREY, $BG);

    // ── Downsample to final size and save ────────────────────────────
    $final = imagecreatetruecolor($W, $H);
    imagecopyresampled($final, $img, 0, 0, 0, 0, $W, $H, $W * $SS, $H * $SS);
    $ok = imagepng($final, $outFile);
    imagedestroy($img);
    imagedestroy($final);
    return (bool)$ok;
}
