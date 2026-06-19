<?php
/**
 * Per-user email signature helpers.
 *
 * Signatures are uploaded as .html files and stored at
 *   admin/uploads/signatures/{user_id}.html
 * They are appended to outgoing emails sent from Hub.
 *
 * No credentials, safe to include anywhere.
 */

if (!function_exists('signature_dir')) {
    function signature_dir(): string {
        return dirname(__DIR__) . '/admin/uploads/signatures';
    }
}

if (!function_exists('signature_path')) {
    function signature_path(int $userId): string {
        return signature_dir() . '/' . $userId . '.html';
    }
}

if (!function_exists('user_has_signature')) {
    function user_has_signature(int $userId): bool {
        return $userId > 0 && is_file(signature_path($userId));
    }
}

if (!function_exists('get_user_signature_html')) {
    /**
     * Returns the sanitized HTML signature for a user, or '' if none.
     */
    function get_user_signature_html(int $userId): string {
        if (!user_has_signature($userId)) return '';
        $html = file_get_contents(signature_path($userId));
        return $html === false ? '' : $html;
    }
}

if (!function_exists('sanitize_signature_html')) {
    /**
     * Strips anything unsafe/unwanted from an uploaded signature:
     * <script>, <iframe>, <object>, <embed>, on*= handlers, javascript: URIs.
     * A signature is presentational HTML only.
     */
    function sanitize_signature_html(string $html): string {
        // Remove dangerous tag blocks (tag + content)
        $html = preg_replace('#<(script|iframe|object|embed|style)\b[^>]*>.*?</\1>#is', '', $html);
        // Remove any self-closing / orphan dangerous tags
        $html = preg_replace('#<(script|iframe|object|embed)\b[^>]*/?>#is', '', $html);
        // Remove inline event handlers: on...="..." or on...='...'
        $html = preg_replace('#\son[a-z]+\s*=\s*"(?:[^"]*)"#is', '', $html);
        $html = preg_replace("#\son[a-z]+\s*=\s*'(?:[^']*)'#is", '', $html);
        // Neutralise javascript: URIs
        $html = preg_replace('#(href|src)\s*=\s*([\'"])\s*javascript:[^\'"]*\2#is', '$1=$2#$2', $html);
        return $html;
    }
}

if (!function_exists('handle_signature_upload')) {
    /**
     * Processes a signature upload/delete for a given user.
     * Returns [okBool, messageString]. Caller handles flash/redirect.
     *
     * $files = $_FILES, $post = $_POST. Expects:
     *   - file field name "signature_file"
     *   - optional checkbox "delete_signature"
     */
    function handle_signature_upload(int $userId, array $files, array $post): array {
        if ($userId <= 0) return [false, 'Invalid user.'];

        // Delete request
        if (!empty($post['delete_signature'])) {
            $p = signature_path($userId);
            if (is_file($p)) @unlink($p);
            return [true, 'Signature removed.'];
        }

        // No file chosen — nothing to do (not an error)
        if (empty($files['signature_file']) || ($files['signature_file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return [true, ''];
        }

        $f = $files['signature_file'];
        if ($f['error'] !== UPLOAD_ERR_OK) {
            return [false, 'Upload failed (error code ' . (int)$f['error'] . ').'];
        }
        if ($f['size'] > 512 * 1024) { // 512 KB ceiling
            return [false, 'Signature file too large (max 512 KB).'];
        }
        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['html', 'htm'], true)) {
            return [false, 'Only .html files are accepted.'];
        }

        $raw = file_get_contents($f['tmp_name']);
        if ($raw === false) return [false, 'Could not read the uploaded file.'];

        $clean = sanitize_signature_html($raw);

        $dir = signature_dir();
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        if (!is_writable($dir)) return [false, 'Signatures folder is not writable on the server.'];

        if (file_put_contents(signature_path($userId), $clean) === false) {
            return [false, 'Could not save the signature.'];
        }
        return [true, 'Signature saved.'];
    }
}

if (!function_exists('signature_html_to_plain')) {
    /**
     * Builds a plain-text version of an HTML signature for the
     * multipart/alternative text part.
     */
    function signature_html_to_plain(string $html): string {
        $t = preg_replace('#<\s*br\s*/?>#i', "\n", $html);
        $t = preg_replace('#</\s*(p|div|tr|li|h[1-6])\s*>#i', "\n", $t);
        $t = strip_tags($t);
        $t = html_entity_decode($t, ENT_QUOTES, 'UTF-8');
        // collapse 3+ newlines, trim each line
        $t = preg_replace("/[ \t]+\n/", "\n", $t);
        $t = preg_replace("/\n{3,}/", "\n\n", $t);
        return trim($t);
    }
}
