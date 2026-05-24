<?php
/**
 * dropbox_open.php
 * Converts a Dropbox /home/PATH URL to a shared link and redirects.
 * Usage: dropbox_open.php?path=/2026/FolderName
 *        dropbox_open.php?url=https://www.dropbox.com/home/2026/FolderName
 */
require_once 'config.php';
requireLogin();

// ── Extract path ──────────────────────────────────────────────────────────────
$path = '';
if (!empty($_GET['path'])) {
    $path = '/' . ltrim(urldecode($_GET['path']), '/');
} elseif (!empty($_GET['url'])) {
    $url  = urldecode($_GET['url']);
    // Extract path from https://www.dropbox.com/home/PATH
    if (preg_match('#dropbox\.com/home(/.*)?$#i', $url, $m)) {
        $path = $m[1] ?? '/';
    }
}

if (!$path) {
    die('Missing path parameter.');
}

// ── Get Dropbox access token using refresh token ──────────────────────────────
function dropbox_get_access_token(): string {
    $ch = curl_init('https://api.dropbox.com/oauth2/token');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'grant_type'    => 'refresh_token',
            'refresh_token' => DROPBOX_REFRESH_TOKEN,
            'client_id'     => DROPBOX_APP_KEY,
            'client_secret' => DROPBOX_APP_SECRET,
        ]),
    ]);
    $res = json_decode(curl_exec($ch), true);
    curl_close($ch);
    return $res['access_token'] ?? '';
}

// ── Create or get shared link ─────────────────────────────────────────────────
function dropbox_get_shared_link(string $access_token, string $path): string {
    // Try creating first
    $ch = curl_init('https://api.dropboxapi.com/2/sharing/create_shared_link_with_settings');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $access_token,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'path'     => $path,
            'settings' => ['requested_visibility' => 'team_only'],
        ]),
    ]);
    $res = json_decode(curl_exec($ch), true);
    curl_close($ch);

    if (!empty($res['url'])) return $res['url'];

    // If link already exists, fetch it
    if (($res['error']['.tag'] ?? '') === 'shared_link_already_exists') {
        $existing = $res['error']['shared_link_already_exists']['metadata']['url'] ?? '';
        if ($existing) return $existing;

        // Fallback: list existing links
        $ch2 = curl_init('https://api.dropboxapi.com/2/sharing/list_shared_links');
        curl_setopt_array($ch2, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $access_token,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode(['path' => $path, 'direct_only' => true]),
        ]);
        $res2 = json_decode(curl_exec($ch2), true);
        curl_close($ch2);
        return $res2['links'][0]['url'] ?? '';
    }

    return '';
}

$access_token = dropbox_get_access_token();
if (!$access_token) {
    die('Could not authenticate with Dropbox. Check API credentials.');
}

$shared_url = dropbox_get_shared_link($access_token, $path);

if ($shared_url) {
    // Convert dl=0 to ?dl=0 and ensure it opens folder view
    $shared_url = preg_replace('/\?dl=\d$/', '', $shared_url) . '?dl=0';
    header('Location: ' . $shared_url);
    exit;
} else {
    // Fallback: open Dropbox web with direct path
    header('Location: https://www.dropbox.com/home' . $path);
    exit;
}
