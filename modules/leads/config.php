<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';

function db(): PDO { global $pdo; return $pdo; }
function requireLogin(): void { require_permission('leads'); }
if (!function_exists('flash')) {
    function flash(string $msg, string $type = 'success'): void {
        start_session();
        $_SESSION['flash'][] = ['type' => $type, 'message' => $msg];
    }
}
if (!function_exists('getFlash')) {
    function getFlash(): ?array {
        start_session();
        if (!empty($_SESSION['flash'])) {
            $f = $_SESSION['flash'][0]; array_shift($_SESSION['flash']);
            return ['msg' => $f['message'], 'type' => $f['type']];
        }
        return null;
    }
}
function h(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
const SOURCES = ['Form','Email','Agent','iBot','WhatsApp','Social','Safari Bookings','Other'];
const STATUSES = ['Inquiry'=>'status-inquiry','Quoted'=>'status-quoted','Booked'=>'status-booked','Cancelled'=>'status-cancelled','Lost'=>'status-lost'];

// ── Dropbox API ───────────────────────────────────────────────────────────────
define('DROPBOX_APP_KEY',       'xdgom1tztkme0pz');
define('DROPBOX_APP_SECRET',    '2h3e1bl8zwo2qkh');
define('DROPBOX_REFRESH_TOKEN', 'a2E-SMjzwVMAAAAAAAAAAbIBB0uOCC6fwqyjqMycKnSdEfmsGAVrPdYH5c5RVGo2');
define('DROPBOX_BASE_PATH',     '/2026');

// ── API security key (shared with GUI via config.properties) ─────────────────
define('API_KEY', 'e4c831819c65a1b0ee3f018c4425b84748d1a4e337f0acd8');

// ── Role threshold ────────────────────────────────────────────────────────────
define('ROLE_CAN_SELECT_AGENT', 2);

// ── Staff role helpers ────────────────────────────────────────────────────────
function isLeadsStaff(): bool {
    return (current_user()['role_name'] ?? '') === 'staff';
}
// Returns true for any role that cannot see other agents' requests (not admin/manager)
function isLeadsRestricted(): bool {
    return !in_array(current_user()['role_name'] ?? '', ['admin', 'manager']);
}
function getStaffAgentId(): int {
    if (!isLeadsRestricted()) return 0;
    global $pdo;
    $stmt = $pdo->prepare('SELECT agent_id FROM users WHERE id = ?');
    $stmt->execute([current_user()['id']]);
    return (int)($stmt->fetchColumn() ?: 0);
}

// ── HubSpot Private App token (used by hubspot_sync.php) ─────────────────────
define('HUBSPOT_TOKEN', 'pat-na1-a84e3308-ece4-49d2-8207-137c768befd5');
