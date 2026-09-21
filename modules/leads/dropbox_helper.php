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
 * Download a text file from Dropbox.
 * @param  string $token  Access token from dropbox_get_access_token()
 * @param  string $path   Full Dropbox path, e.g. '/2026/SmithJohn(BTG-Roberto)/CustomerInfo.txt'
 * @return string|null    File content, or null if the file does not exist
 * @throws RuntimeException on unexpected API error
 */
function dropbox_download_text(string $token, string $path): ?string {
    $ch = curl_init('https://content.dropboxapi.com/2/files/download');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $token,
            'Dropbox-API-Arg: ' . json_encode(['path' => $path]),
        ],
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code === 409) {
        return null; // path/not_found — file doesn't exist yet
    }
    if ($code !== 200) {
        throw new RuntimeException("Dropbox download failed (HTTP $code): $body");
    }
    return $body;
}

/**
 * Move/rename a folder in Dropbox.
 * @param  string $token      Access token from dropbox_get_access_token()
 * @param  string $from_path  Full Dropbox path, e.g. '/2026/SmithJohn(BTG-Roberto)_DEPOSIT'
 * @param  string $to_path    New full Dropbox path
 * @return array              Dropbox metadata response
 * @throws RuntimeException on API error
 */
function dropbox_move_folder(string $token, string $from_path, string $to_path): array {
    $ch = curl_init('https://api.dropboxapi.com/2/files/move_v2');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'from_path'  => $from_path,
            'to_path'    => $to_path,
            'autorename' => false,
        ]),
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($body, true) ?? [];
    if ($code !== 200) {
        $errSummary = $data['error_summary'] ?? $body;
        throw new RuntimeException("Dropbox move_v2 failed (HTTP $code): $errSummary");
    }
    return $data;
}

/**
 * Copy a single file within Dropbox (does not overwrite).
 * Returns 'copied' on success, 'exists' if the destination already exists,
 * or 'src_missing' if the source file is not found. Any other error throws.
 *
 * @param  string $token  Access token from dropbox_get_access_token()
 * @param  string $from   Full Dropbox path of the source file
 * @param  string $to     Full Dropbox path of the destination file
 * @return string         'copied' | 'exists' | 'src_missing'
 * @throws RuntimeException on any other API error
 */
function dropbox_copy_file(string $token, string $from, string $to): string {
    $ch = curl_init('https://api.dropboxapi.com/2/files/copy_v2');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'from_path'  => $from,
            'to_path'    => $to,
            'autorename' => false,
        ]),
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code === 200) return 'copied';

    $data    = json_decode($body, true) ?? [];
    $summary = (string)($data['error_summary'] ?? $body);
    if (stripos($summary, 'conflict')  !== false) return 'exists';       // to/conflict/...
    if (stripos($summary, 'not_found') !== false) return 'src_missing';  // from_lookup/not_found/...
    throw new RuntimeException("Dropbox copy_v2 failed (HTTP $code): $summary");
}

/**
 * Cheap existence check for a Dropbox path (files/get_metadata).
 * Returns true if the file/folder exists, false if not_found. Other errors throw.
 *
 * @param  string $token  Access token from dropbox_get_access_token()
 * @param  string $path   Full Dropbox path
 * @return bool
 * @throws RuntimeException on any error other than not_found
 */
function dropbox_path_exists(string $token, string $path): bool {
    if ($path === '' || $path === '/') return false;
    $ch = curl_init('https://api.dropboxapi.com/2/files/get_metadata');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode(['path' => $path]),
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code === 200) return true;
    $data = json_decode($body, true) ?? [];
    if ($code === 409 && stripos((string)($data['error_summary'] ?? ''), 'not_found') !== false) return false;
    throw new RuntimeException("Dropbox get_metadata failed (HTTP $code): $body");
}

/**
 * List file names (not folders, one level deep) inside a Dropbox path.
 * Returns [] if the folder does not exist yet. Handles pagination.
 *
 * @param  string $token  Access token from dropbox_get_access_token()
 * @param  string $path   Full Dropbox path, e.g. '/2026/SmithJohn(BTG-Roberto)'
 * @return string[]       File names (basename only)
 */
function dropbox_list_files(string $token, string $path): array {
    $names  = [];
    $cursor = null;
    do {
        if ($cursor) {
            $url     = 'https://api.dropboxapi.com/2/files/list_folder/continue';
            $payload = json_encode(['cursor' => $cursor]);
        } else {
            $url     = 'https://api.dropboxapi.com/2/files/list_folder';
            $payload = json_encode(['path' => $path, 'recursive' => false]);
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => $payload,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code === 409) return $names;   // path/not_found — folder not created yet
        if ($code !== 200) {
            throw new RuntimeException("Dropbox list_folder failed (HTTP $code): $body");
        }
        $data = json_decode($body, true);
        foreach ($data['entries'] ?? [] as $entry) {
            if (($entry['.tag'] ?? '') === 'file') $names[] = $entry['name'];
        }
        $cursor  = $data['cursor']   ?? null;
        $hasMore = $data['has_more'] ?? false;
    } while ($hasMore && $cursor);

    return $names;
}

/**
 * List all sub-folders (one level deep) inside a Dropbox path.
 * Handles pagination automatically.
 *
 * @param  string $token  Access token from dropbox_get_access_token()
 * @param  string $path   Full Dropbox path, e.g. '/001_Safari'
 * @return string[]       Array of folder names (not full paths)
 * @throws RuntimeException on API error
 */
function dropbox_list_folder(string $token, string $path): array {
    $names  = [];
    $cursor = null;

    do {
        if ($cursor) {
            $url     = 'https://api.dropboxapi.com/2/files/list_folder/continue';
            $payload = json_encode(['cursor' => $cursor]);
        } else {
            $url     = 'https://api.dropboxapi.com/2/files/list_folder';
            $payload = json_encode(['path' => $path, 'recursive' => false]);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => $payload,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 200) {
            throw new RuntimeException("Dropbox list_folder failed (HTTP $code): $body");
        }
        $data = json_decode($body, true);
        foreach ($data['entries'] ?? [] as $entry) {
            if ($entry['.tag'] === 'folder') {
                $names[] = $entry['name'];
            }
        }
        $cursor  = $data['cursor']   ?? null;
        $hasMore = $data['has_more'] ?? false;
    } while ($hasMore && $cursor);

    return $names;
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

/**
 * Find a folder's actual Dropbox path by searching for its name.
 * Returns the full path string (e.g. '/001_Safari/07_15JUL_...') or null if not found.
 */
/**
 * Search for folders whose name matches $query, restricted to $scopePath and all
 * of its subfolders (recursive — Dropbox search_v2 always descends the subtree).
 * Returns a list of ['name' => ..., 'path' => path_display], most-relevant first.
 *
 * @param  string $token      Access token from dropbox_get_access_token()
 * @param  string $query      Substring to look for in the folder name
 * @param  string $scopePath  Subtree to search, e.g. '/000_Contracts' ('' = whole Dropbox)
 * @param  int    $max        Max results (Dropbox caps at 1000)
 * @return array<int,array{name:string,path:string}>
 */
function dropbox_search_folders(string $token, string $query, string $scopePath = '', int $max = 25): array {
    $options = [
        'file_categories' => ['folder'],
        'filename_only'   => true,
        'max_results'     => max(1, min($max, 1000)),
    ];
    if ($scopePath !== '') $options['path'] = rtrim($scopePath, '/');

    $ch = curl_init('https://api.dropboxapi.com/2/files/search_v2');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode(['query' => $query, 'options' => $options]),
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200) {
        throw new RuntimeException("Dropbox search_v2 failed (HTTP $code): $body");
    }
    $data = json_decode($body, true) ?? [];
    $out  = [];
    foreach ($data['matches'] ?? [] as $match) {
        $meta = $match['metadata']['metadata'] ?? [];
        if (($meta['.tag'] ?? '') === 'folder') {
            $out[] = [
                'name' => $meta['name'] ?? '',
                'path' => $meta['path_display'] ?? '',
            ];
        }
    }
    return $out;
}

function dropbox_find_folder(string $token, string $folderName): ?string {
    $ch = curl_init('https://api.dropboxapi.com/2/files/search_v2');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'query'   => $folderName,
            'options' => [
                'file_categories'    => ['folder'],
                'filename_only'      => true,
                'max_results'        => 5,
            ],
        ]),
    ]);
    $body = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($body, true) ?? [];
    foreach ($data['matches'] ?? [] as $match) {
        $meta = $match['metadata']['metadata'] ?? [];
        if (($meta['.tag'] ?? '') === 'folder') {
            $name = $meta['name'] ?? '';
            // Exact name match (case-insensitive)
            if (strcasecmp($name, $folderName) === 0) {
                return $meta['path_display'] ?? null;
            }
        }
    }
    return null;
}

/**
 * Folder "stem": the name with trailing status / _CK tags removed and lowercased,
 * so DB practice_code and the real Dropbox folder match even when the status
 * suffix drifted (e.g. '..._PROGRESS' vs '..._cancelled', or '..._PAID_CK').
 * Strips repeated trailing tags in any order (e.g. '..._CANCELLED_CK_CANCELLED').
 */
function dropbox_folder_stem(string $name): string {
    $s = trim($name);
    $tags = 'CK|PROGRESS|PROVISIONAL|CONFIRMED|DEPOSIT|BALANCE-CASH|BALANCE_CASH|BALANCE|PAID|CANCELLED|CANCELED|LOST';
    do {
        $prev = $s;
        $s = preg_replace('/[_-](?:' . $tags . ')$/i', '', $s);
    } while ($s !== $prev && $s !== '');
    return strtolower($s);
}

/**
 * Locate a request's current folder for re-linking, tolerant of status-suffix drift.
 * Returns ['result' => 'exact'|'stem'|'ambiguous'|'none', 'path' => ?string,
 *          'candidates' => [['name'=>…,'path'=>…], …]].
 *  - exact/stem : a confident single match (path set) — safe to re-link.
 *  - ambiguous  : several folders share the same stem — do NOT auto-link, review.
 *  - none       : the folder was not found (renamed beyond recognition / deleted).
 */
function dropbox_relink_find(string $token, string $name): array {
    $name = trim($name);
    if ($name === '') return ['result' => 'none', 'path' => null, 'candidates' => []];

    // Fast path: exact (case-insensitive) name still exists somewhere.
    $exact = dropbox_find_folder($token, $name);
    if ($exact) return ['result' => 'exact', 'path' => $exact, 'candidates' => []];

    // Fuzzy: match on the stem (status suffix stripped).
    $stem  = dropbox_folder_stem($name);
    $query = preg_replace('/_START.*$/i', '', $name);   // distinctive, suffix-free query
    if ($query === '') $query = $name;

    $hits    = dropbox_search_folders($token, $query, '', 25);
    $matches = [];
    foreach ($hits as $h) {
        if (dropbox_folder_stem($h['name']) === $stem) $matches[] = $h;
    }
    if (count($matches) === 1) return ['result' => 'stem',      'path' => $matches[0]['path'], 'candidates' => $matches];
    if (count($matches) > 1)   return ['result' => 'ambiguous', 'path' => null,               'candidates' => $matches];
    return ['result' => 'none', 'path' => null, 'candidates' => []];
}

