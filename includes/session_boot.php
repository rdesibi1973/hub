<?php
/**
 * Centralised session bootstrap.
 *
 * Configures a PRIVATE session save_path and a 12h lifetime BEFORE starting
 * the session, then starts it. Safe to include from anywhere (hub pages and
 * the leads module): it has no dependency on any credential-bearing config
 * and is idempotent (does nothing if a session is already active).
 *
 * Why this exists: BlueHost/cPanel uses a shared session save_path
 * (/var/cpanel/php/sessions/ea-php83) whose garbage collector is run by other
 * accounts on the same server, deleting our session files early and logging
 * users out after a few minutes. A private save_path inside our own space
 * fixes this.
 */

if (!function_exists('hub_session_boot')) {
    function hub_session_boot(): void {
        if (session_status() !== PHP_SESSION_NONE) {
            return; // already started elsewhere
        }

        $lifetime = defined('SESSION_LIFETIME') ? (int)SESSION_LIFETIME : 43200; // 12h

        // Private save_path: <repo-root>/sessions . __DIR__ is includes/, so go up one.
        $sessDir = dirname(__DIR__) . '/sessions';
        if (is_dir($sessDir) && is_writable($sessDir)) {
            session_save_path($sessDir);
        }

        @ini_set('session.gc_maxlifetime', (string)$lifetime);

        session_set_cookie_params([
            'lifetime' => $lifetime,
            'path'     => '/',
            'secure'   => (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on'),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        session_start();
    }
}
