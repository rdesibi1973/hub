<?php
/**
 * dropbox_open.php
 * Converts a Dropbox path/URL to a public shared link and redirects.
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
    $url = urldecode($_GET['url']);
    if (preg_match('#dropbox\.com/home(/.*)?$#i', $url, $m)) {
        $path = $m[1] ?? '/';
    }
}

if (!$path) {
    die('Missing path parameter.');
}

// ── Get access token ──────────────────────────────────────────────────────────
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

// ── Create or get a PUBLIC shared link ───────────────────────────────────────
function dropbox_get_public_link(string $token, string $path): string {

    $headers = [
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json',
    ];

    // 1. Try creating a public link
    $ch = curl_init('https://api.dropboxapi.com/2/sharing/create_shared_link_with_settings');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_POSTFIELDS     => json_encode([
            'path'     => $path,
            'settings' => ['requested_visibility' => 'public'],
        ]),
    ]);
    $res = json_decode(curl_exec($ch), true);
    curl_close($ch);

    if (!empty($res['url'])) return $res['url'];

    // 2. Link already exists — get it
    if (($res['error']['.tag'] ?? '') === 'shared_link_already_exists') {
        // Try to read URL from the error payload first (faster)
        $existing_url = $res['error']['shared_link_already_exists']['metadata']['url'] ?? '';

        if (!$existing_url) {
            // Fallback: list existing links for this path
            $ch2 = curl_init('https://api.dropboxapi.com/2/sharing/list_shared_links');
            curl_setopt_array($ch2, [
                CURLOPT_POST           => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_POSTFIELDS     => json_encode(['path' => $path, 'direct_only' => true]),
            ]);
            $res2 = json_decode(curl_exec($ch2), true);
            curl_close($ch2);
            $existing_url = $res2['links'][0]['url'] ?? '';
        }

        if ($existing_url) {
            // If the existing link is team_only, update it to public
            $ch3 = curl_init('https://api.dropboxapi.com/2/sharing/modify_shared_link_settings');
            curl_setopt_array($ch3, [
                CURLOPT_POST           => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_POSTFIELDS     => json_encode([
                    'url'      => $existing_url,
                    'settings' => ['requested_visibility' => 'public'],
                ]),
            ]);
            $updated = json_decode(curl_exec($ch3), true);
            curl_close($ch3);

            // Return updated URL if available, else original
            return $updated['url'] ?? $existing_url;
        }
    }

    return '';
}

$access_token = dropbox_get_access_token();
if (!$access_token) {
    die('Could not authenticate with Dropbox. Check API credentials in config.php.');
}

$shared_url = dropbox_get_public_link($access_token, $path);

if ($shared_url) {
    // Use URL as-is (new Dropbox links contain rlkey and must not be modified)
    header('Location: ' . $shared_url);
    exit;
} else {
    // Last resort: direct Dropbox web path (requires login, but at least it opens something)
    header('Location: https://www.dropbox.com/home' . $path);
    exit;
}
