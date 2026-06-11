<?php
require_once __DIR__ . '/config.php';

// ── Session bootstrap ─────────────────────────────────
function start_session(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'lifetime' => SESSION_LIFETIME,
            'path'     => '/',
            'secure'   => (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on'),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

// ── Auth checks ───────────────────────────────────────
function is_logged_in(): bool {
    start_session();
    return isset($_SESSION['user_id']);
}

function require_login(): void {
    start_session();
    if (!isset($_SESSION['user_id'])) {
        $next = urlencode($_SERVER['REQUEST_URI'] ?? '');
        header('Location: ' . BASE_URL . '/login.php' . ($next ? "?next=$next" : ''));
        exit;
    }
    // If session is stale (role_name missing), reload user data from DB.
    // Use $pdo global (hub pages) or db() function (leads module) — whichever is available.
    if (empty($_SESSION['role_name'])) {
        $conn = $GLOBALS['pdo'] ?? (function_exists('db') ? db() : null);
        if ($conn === null) {
            // No DB connection available — force re-login
            $_SESSION = [];
            session_destroy();
            $next = urlencode($_SERVER['REQUEST_URI'] ?? '');
            header('Location: ' . BASE_URL . '/login.php' . ($next ? "?next=$next" : ''));
            exit;
        }
        $stmt = $conn->prepare('
            SELECT u.*, r.name AS role_name
            FROM   users u
            JOIN   roles r ON u.role_id = r.id
            WHERE  u.id = ? AND u.is_active = 1
            LIMIT  1
        ');
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch();
        if (!$user) {
            // User no longer valid — force re-login
            $_SESSION = [];
            session_destroy();
            $next = urlencode($_SERVER['REQUEST_URI'] ?? '');
            header('Location: ' . BASE_URL . '/login.php' . ($next ? "?next=$next" : ''));
            exit;
        }
        $ps = $conn->prepare('SELECT module FROM role_permissions WHERE role_id = ?');
        $ps->execute([$user['role_id']]);
        $_SESSION['username']    = $user['username'];
        $_SESSION['full_name']   = $user['full_name'];
        $_SESSION['role_name']   = $user['role_name'];
        $_SESSION['role_id']     = $user['role_id'];
        $_SESSION['permissions'] = $ps->fetchAll(PDO::FETCH_COLUMN);
    }
}

function has_permission(string $module): bool {
    start_session();
    return in_array($module, $_SESSION['permissions'] ?? [], true);
}

function require_permission(string $module): void {
    require_login();
    if (!has_permission($module)) {
        include __DIR__ . '/../errors/403.php';
        exit;
    }
}

function require_admin(): void {
    require_permission('admin');
}

function current_user(): array {
    start_session();
    return [
        'id'          => $_SESSION['user_id']   ?? null,
        'username'    => $_SESSION['username']   ?? '',
        'full_name'   => $_SESSION['full_name']  ?? '',
        'role_name'   => $_SESSION['role_name']  ?? '',
        'role_id'     => $_SESSION['role_id']    ?? null,
        'permissions' => $_SESSION['permissions'] ?? [],
    ];
}

function is_admin(): bool {
    start_session();
    return ($_SESSION['role_name'] ?? '') === 'admin';
}

// ── Login / Logout ────────────────────────────────────
function login_user(PDO $pdo, string $username, string $password): bool {
    $stmt = $pdo->prepare('
        SELECT u.*, r.name AS role_name
        FROM   users u
        JOIN   roles r ON u.role_id = r.id
        WHERE  u.username = ? AND u.is_active = 1
        LIMIT  1
    ');
    $stmt->execute([strtolower(trim($username))]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        return false;
    }

    // Load permissions for this role
    $ps = $pdo->prepare('SELECT module FROM role_permissions WHERE role_id = ?');
    $ps->execute([$user['role_id']]);
    $permissions = $ps->fetchAll(PDO::FETCH_COLUMN);

    // Regenerate session ID on login (session fixation protection)
    session_regenerate_id(true);

    $_SESSION['user_id']     = $user['id'];
    $_SESSION['username']    = $user['username'];
    $_SESSION['full_name']   = $user['full_name'];
    $_SESSION['role_name']   = $user['role_name'];
    $_SESSION['role_id']     = $user['role_id'];
    $_SESSION['permissions'] = $permissions;

    // Record last login time
    $pdo->prepare('UPDATE users SET last_login = NOW() WHERE id = ?')
        ->execute([$user['id']]);

    // Force the session file to be written to disk now. On BlueHost shared
    // hosting the new session ID created by session_regenerate_id(true) can
    // otherwise still be pending when the post-login redirect lands on the
    // next page, causing an intermittent "access denied" that clears on reload.
    session_write_close();

    return true;
}

function logout_user(): void {
    start_session();
    $_SESSION = [];
    session_destroy();
}

// ── CSRF ──────────────────────────────────────────────
function csrf_token(): string {
    start_session();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf(): void {
    start_session();
    $submitted = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $submitted)) {
        http_response_code(400);
        die('Invalid or expired request token. Please go back and try again.');
    }
    // Rotate token after use
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ── Flash messages ────────────────────────────────────
function flash(string $message, string $type = 'info'): void {
    start_session();
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function get_flash_messages(): array {
    start_session();
    $msgs = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $msgs;
}

// ── Helpers ───────────────────────────────────────────
function e(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $url): never {
    // Ensure any pending session data is flushed to disk before we hand the
    // browser off to the next page, to avoid a redirect outrunning the
    // session write on slow shared-hosting filesystems.
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    header('Location: ' . $url);
    exit;
}
