<?php
// dropbox_helper.php
// Dropbox API v2 wrapper.
// Requires: DROPBOX_APP_KEY, DROPBOX_APP_SECRET, DROPBOX_REFRESH_TOKEN in config.php
// Usage: require_once 'dropbox_helper.php';

/**
 * Exchange the stored refresh token for a short-lived access token.
 * Dropbox access tokens expire in ~4h; we always fetch a fresh one per request.
 */
function dropbox_get_access_token(): string {
    $ch = curl_init('https://api.dropbox.com/oauth2/token');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'grant_type'    => 'refresh_token',
            'refresh_token' => DROPBOX_REFRESH_TOKEN,
            'client_id'     => DROPBOX_APP_KEY,
            'client_secret' => DROPBOX_APP_SECRET,
        ]),
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200) {
        throw new RuntimeException("Dropbox token refresh failed (HTTP $code): $body");
    }
    $data = json_decode($body, true);
    if (empty($data['access_token'])) {
        throw new RuntimeException("No access_token in Dropbox response: $body");
    }
    return $data['access_token'];
}

/**
 * Create a folder in Dropbox.
 * @param  string $token  Access token from dropbox_get_access_token()
 * @param  string $path   Full Dropbox path, e.g. '/2026/SmithJohn(BTG-Roberto)'
 * @return array          Dropbox metadata response
 * @throws RuntimeException on API error (including path/conflict)
 */
function dropbox_create_folder(string $token, string $path, bool $throwOnConflict = false): array {
    $ch = curl_init('https://api.dropboxapi.com/2/files/create_folder_v2');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'path'       => $path,
            'autorename' => false,
        ]),
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($body, true) ?? [];
    if ($code !== 200) {
        // path/conflict means the folder already exists
        $errTag = $data['error']['.tag'] ?? '';
        if ($code === 409 && $errTag === 'path' &&
            ($data['error']['path']['.tag'] ?? '') === 'conflict') {
            if ($throwOnConflict) {
                throw new RuntimeException("Folder already exists: {$path}");
            }
            return $data; // caller chose to ignore conflict
        }
        throw new RuntimeException("Dropbox create_folder failed (HTTP $code): $body");
    }
    return $data;
}

/**
 * Upload a small text file to Dropbox (overwrites if exists).
 * @param  string $token   Access token
 * @param  string $path    Full Dropbox path, e.g. '/2026/SmithJohn(BTG-Roberto)/CustomerInfo.txt'
 * @param  string $content File content as plain text
 * @return array           Dropbox file metadata
 */
function dropbox_upload_text(string $token, string $path, string $content): array {
    $ch = curl_init('https://content.dropboxapi.com/2/files/upload');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/octet-stream',
            'Dropbox-API-Arg: ' . json_encode([
                'path'       => $path,
                'mode'       => 'overwrite',
                'autorename' => false,
                'mute'       => false,
            ]),
        ],
        CURLOPT_POSTFIELDS => $content,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200) {
        throw new RuntimeException("Dropbox upload failed (HTTP $code): $body");
    }
    return json_decode($body, true) ?? [];
}

/**
 * Delete a file or folder in Dropbox (moves to trash).
 * Uses files/delete_v2 — the item is moved to Dropbox trash, not permanently erased.
 *
 * @param  string $token  Access token from dropbox_get_access_token()
 * @param  string $path   Full Dropbox path, e.g. '/2026/SmithJohn(Roberto-Drct)'
 * @return array          Dropbox metadata response
 * @throws RuntimeException if the item does not exist or the API call fails
 */
function dropbox_delete_folder(string $token, string $path): array {
    $ch = curl_init('https://api.dropboxapi.com/2/files/delete_v2');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode(['path' => $path]),
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($body, true) ?? [];
    if ($code !== 200) {
        $errTag = $data['error_summary'] ?? $body;
        throw new RuntimeException("Dropbox delete_v2 failed (HTTP $code): $errTag");
    }
    return $data;
}
