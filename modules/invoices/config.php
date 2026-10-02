<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';

function db(): PDO { global $pdo; return $pdo; }

function requireInvoiceAccess(): void { require_permission('invoices'); }

function isInvoiceAdmin(): bool {
    return in_array(current_user()['role_name'] ?? '', ['admin', 'manager']);
}

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

// Constants, numbering, recalculation, credit notes, payments, folder status,
// PDF, import: shared with the Agent API.
require_once __DIR__ . '/includes/invoice_service.php';
