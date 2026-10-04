<?php
/**
 * modules/iti/img.php?f=<lodges|destinations>/<file>&w=<480|960|1600> — a smaller copy of
 * one of our photos for the web pages. Made once into uploads/_w<w>/…, then the pages link
 * the static file directly (iti_photo_variant()). Public (the client link uses it), but
 * only our own upload folders and the fixed widths.
 */
require_once __DIR__ . '/includes/iti_photos.php';

$f = (string)($_GET['f'] ?? '');
$w = (int)($_GET['w'] ?? 0);
$parts = explode('/', $f, 2);
$path = count($parts) === 2 ? iti_photo_make_variant($parts[0], $parts[1], $w) : null;
if ($path === null || !is_file($path)) { http_response_code(404); exit; }

header('Content-Type: ' . (preg_match('/\.png$/i', $path) ? 'image/png' : 'image/jpeg'));
header('Content-Length: ' . filesize($path));
header('Cache-Control: public, max-age=31536000, immutable');
readfile($path);
