<?php
/**
 * iti_functions.php
 * Savannah Explorers Hub — Itinerary Builder
 * Funzioni core del modulo ITI
 */

// ── Connessione al DB hub ────────────────────────────────────────────────────
$config_path = dirname(__FILE__, 4) . '/includes/config.php';
if (!defined('DB_HOST')) {
    require_once $config_path;
}

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    die('Database connection failed: ' . $e->getMessage());
}

// ── Costanti ITI ─────────────────────────────────────────────────────────────
define('ITI_VERSION',   '1.0.0');
define('ITI_BASE_URL',  BASE_URL . '/modules/iti');
define('ITI_LANGUAGES', ['en', 'it', 'fr', 'es', 'de']);
define('ITI_CURRENCIES', ['USD', 'EUR']);
define('ITI_BRANDS', [
    'savannah_explorers' => 'Savannah Explorers',
    'orangi_collection'  => 'The Orangi Collection',
]);

// ── Helper: lingua corrente ───────────────────────────────────────────────────
function iti_lang(): string {
    return $_SESSION['iti_lang'] ?? 'en';
}

// ── Helper: campo localizzato ─────────────────────────────────────────────────
/**
 * Restituisce il valore nella lingua richiesta con fallback a EN.
 * Esempio: iti_field($row, 'name') → $row['name_it'] se lang=it
 */
function iti_field(array $row, string $field, string $lang = null): string {
    $lang = $lang ?? iti_lang();
    $key  = $field . '_' . $lang;
    if (isset($row[$key]) && $row[$key] !== '') {
        return $row[$key];
    }
    // fallback EN
    $key_en = $field . '_en';
    return $row[$key_en] ?? '';
}

// ── Helper: escape HTML ───────────────────────────────────────────────────────
function e(string $val): string {
    return htmlspecialchars($val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ── Helper: formato prezzo ────────────────────────────────────────────────────
function iti_money(float|null $amount, string $currency = 'USD'): string {
    if ($amount === null) return '—';
    $symbol = $currency === 'EUR' ? '€' : '$';
    return $symbol . number_format($amount, 2, '.', ',');
}

// ── Helper: genera UUID v4 (per public_token) ────────────────────────────────
function iti_uuid(): string {
    $data    = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

// ── Helper: redirect ──────────────────────────────────────────────────────────
function iti_redirect(string $path): void {
    header('Location: ' . ITI_BASE_URL . '/' . ltrim($path, '/'));
    exit;
}

// ── Helper: flash message ─────────────────────────────────────────────────────
function iti_flash(string $type, string $msg): void {
    $_SESSION['iti_flash'] = ['type' => $type, 'msg' => $msg];
}

function iti_flash_render(): string {
    if (empty($_SESSION['iti_flash'])) return '';
    $f    = $_SESSION['iti_flash'];
    unset($_SESSION['iti_flash']);
    $cls  = $f['type'] === 'success' ? 'alert-success' : 'alert-danger';
    return '<div class="alert ' . $cls . ' alert-dismissible fade show" role="alert">'
         . e($f['msg'])
         . '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
}

// ── DESTINATIONS ──────────────────────────────────────────────────────────────
function iti_get_destinations(PDO $pdo, bool $active_only = true): array {
    $sql = 'SELECT * FROM iti_destinations' . ($active_only ? ' WHERE is_active = 1' : '') . ' ORDER BY sort_order, name_en';
    return $pdo->query($sql)->fetchAll();
}

function iti_get_destination(PDO $pdo, int $id): array|false {
    $st = $pdo->prepare('SELECT * FROM iti_destinations WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch();
}

// ── LODGES ────────────────────────────────────────────────────────────────────
function iti_get_lodges(PDO $pdo, int $destination_id = null): array {
    if ($destination_id) {
        $st = $pdo->prepare('SELECT l.*, d.name_en AS dest_name_en FROM iti_lodges l JOIN iti_destinations d ON d.id = l.destination_id WHERE l.destination_id = ? AND l.is_active = 1 ORDER BY l.name');
        $st->execute([$destination_id]);
    } else {
        $st = $pdo->query('SELECT l.*, d.name_en AS dest_name_en FROM iti_lodges l JOIN iti_destinations d ON d.id = l.destination_id WHERE l.is_active = 1 ORDER BY d.sort_order, l.name');
    }
    return $st->fetchAll();
}

// ── PROGRAMS ──────────────────────────────────────────────────────────────────
function iti_get_programs(PDO $pdo, string $type = null, string $status = null): array {
    $where = [];
    $params = [];
    if ($type) {
        $where[] = 'p.program_type = ?';
        $params[] = $type;
    }
    if ($status) {
        $where[] = 'p.status = ?';
        $params[] = $status;
    }
    $sql = 'SELECT p.*, r.client_name
            FROM iti_programs p
            LEFT JOIN iti_requests r ON r.id = p.request_id'
         . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
         . ' ORDER BY p.updated_at DESC';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

function iti_get_program(PDO $pdo, int $id): array|false {
    $st = $pdo->prepare('SELECT p.*, r.client_name, r.client_email
                         FROM iti_programs p
                         LEFT JOIN iti_requests r ON r.id = p.request_id
                         WHERE p.id = ?');
    $st->execute([$id]);
    return $st->fetch();
}

function iti_get_program_by_token(PDO $pdo, string $token): array|false {
    $st = $pdo->prepare('SELECT * FROM iti_programs WHERE public_token = ? AND is_published = 1');
    $st->execute([$token]);
    return $st->fetch();
}

// ── PROGRAM DAYS ──────────────────────────────────────────────────────────────
function iti_get_days(PDO $pdo, int $program_id): array {
    $st = $pdo->prepare(
        'SELECT pd.*,
                sl.name AS start_lodge_name,
                el.name AS end_lodge_name
         FROM iti_program_days pd
         LEFT JOIN iti_lodges sl ON sl.id = pd.start_lodge_id
         LEFT JOIN iti_lodges el ON el.id = pd.end_lodge_id
         WHERE pd.program_id = ?
         ORDER BY pd.day_number'
    );
    $st->execute([$program_id]);
    return $st->fetchAll();
}

// ── PRICES ────────────────────────────────────────────────────────────────────
function iti_get_prices(PDO $pdo, int $program_id): array {
    $st = $pdo->prepare('SELECT * FROM iti_program_prices WHERE program_id = ? ORDER BY FIELD(price_category, "rack", "sto", "stospec")');
    $st->execute([$program_id]);
    $rows = $st->fetchAll();
    $out  = [];
    foreach ($rows as $r) {
        $out[$r['price_category']] = $r;
    }
    return $out;
}

// ── INCLUSIONS ────────────────────────────────────────────────────────────────
function iti_get_inclusions(PDO $pdo, int $program_id): array {
    $lang = iti_lang();
    $col  = "COALESCE(NULLIF(pi.text_{$lang},''), NULLIF(si.text_{$lang},''), pi.text_en, si.text_en) AS display_text";
    $st   = $pdo->prepare(
        "SELECT pi.id, pi.item_type, pi.sort_order, {$col}
         FROM iti_program_inclusions pi
         LEFT JOIN iti_standard_inclusions si ON si.id = pi.standard_inclusion_id
         WHERE pi.program_id = ?
         ORDER BY pi.item_type DESC, pi.sort_order"
    );
    $st->execute([$program_id]);
    return $st->fetchAll();
}

// ── REQUESTS ─────────────────────────────────────────────────────────────────
function iti_get_requests(PDO $pdo, string $status = null): array {
    $where  = $status ? 'WHERE status = ?' : '';
    $params = $status ? [$status] : [];
    $st = $pdo->prepare("SELECT * FROM iti_requests {$where} ORDER BY created_at DESC");
    $st->execute($params);
    return $st->fetchAll();
}

function iti_get_request(PDO $pdo, int $id): array|false {
    $st = $pdo->prepare('SELECT * FROM iti_requests WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch();
}
