<?php
/**
 * iti_functions.php
 * Savannah Explorers Hub — Itinerary Builder
 */

// ── Connessione al DB hub ─────────────────────────────────────────────────────
$_iti_config = dirname(__FILE__, 4) . '/includes/config.php';
if (!defined('DB_HOST')) {
    require_once $_iti_config;
}

static $_iti_pdo = null;

function db(): PDO {
    global $_iti_pdo;
    if ($_iti_pdo === null) {
        try {
            $_iti_pdo = new PDO(
                'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                DB_USER, DB_PASS,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );
        } catch (PDOException $ex) {
            http_response_code(500);
            die('DB connection failed: ' . $ex->getMessage());
        }
    }
    return $_iti_pdo;
}

// ── Costanti ──────────────────────────────────────────────────────────────────
define('ITI_VERSION',    '1.0.0');
define('ITI_BASE_URL',   BASE_URL . '/modules/iti');
define('ITI_MODULE_URL', BASE_URL . '/modules/iti');
define('ITI_LANGUAGES',  ['en', 'it', 'fr', 'es', 'de']);
define('ITI_CURRENCIES', ['USD', 'EUR']);
define('ITI_BRANDS', [
    'savannah_explorers' => 'Savannah Explorers',
    'orangi_collection'  => 'The Orangi Collection',
]);
define('ITI_PROGRAM_STATUS_BADGE', [
    'draft'     => 'badge-grey',
    'sent'      => 'badge-amber',
    'confirmed' => 'badge-green',
    'cancelled' => 'badge-red',
]);

// ── Helper: escape HTML ───────────────────────────────────────────────────────
if (!function_exists('h')) {
    function h(?string $val): string {
        return htmlspecialchars((string)$val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

// ── Helper: lingua corrente ───────────────────────────────────────────────────
function iti_lang(): string {
    return $_SESSION['iti_lang'] ?? 'en';
}

// ── Helper: campo localizzato ─────────────────────────────────────────────────
function iti_field(array $row, string $field, string $lang = null): string {
    $lang = $lang ?? iti_lang();
    $key  = $field . '_' . $lang;
    if (!empty($row[$key])) return $row[$key];
    return $row[$field . '_en'] ?? '';
}

// ── Helper: etichetta durata ──────────────────────────────────────────────────
function iti_duration_label(int $days): string {
    $nights = max(0, $days - 1);
    return $days . ' day' . ($days != 1 ? 's' : '')
         . ' / ' . $nights . ' night' . ($nights != 1 ? 's' : '');
}

// ── Helper: formato prezzo ────────────────────────────────────────────────────
function iti_money(?float $amount, string $currency = 'USD'): string {
    if ($amount === null) return '—';
    $symbol = $currency === 'EUR' ? '€' : '$';
    return $symbol . number_format($amount, 2, '.', ',');
}

// ── Helper: UUID v4 ───────────────────────────────────────────────────────────
function iti_uuid(): string {
    $data    = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

// ── Helper: redirect ──────────────────────────────────────────────────────────
function iti_redirect(string $path): void {
    header('Location: ' . ITI_MODULE_URL . '/' . ltrim($path, '/'));
    exit;
}

// ── Helper: flash message ─────────────────────────────────────────────────────
function iti_flash(string $type, string $msg): void {
    $_SESSION['iti_flash'] = ['type' => $type, 'msg' => $msg];
}

// Alias usato da requests.php / programs.php
function iti_flash_set(string $type, string $msg): void {
    iti_flash($type, $msg);
}

function iti_flash_render(): string {
    if (empty($_SESSION['iti_flash'])) return '';
    $f   = $_SESSION['iti_flash'];
    unset($_SESSION['iti_flash']);
    $cls = $f['type'] === 'success' ? 'alert-success' : 'alert-danger';
    return '<div class="alert ' . $cls . ' alert-dismissible fade show" role="alert">'
         . h($f['msg'])
         . '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
}

// ── DESTINATIONS ──────────────────────────────────────────────────────────────
function iti_get_destinations(bool $active_only = true): array {
    $sql = 'SELECT * FROM iti_destinations'
         . ($active_only ? ' WHERE is_active = 1' : '')
         . ' ORDER BY sort_order, name_en';
    return db()->query($sql)->fetchAll();
}

function iti_get_destination(int $id): array|false {
    $st = db()->prepare('SELECT * FROM iti_destinations WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch();
}

// ── LODGES ────────────────────────────────────────────────────────────────────
function iti_get_lodges(int $destination_id = null): array {
    if ($destination_id) {
        $st = db()->prepare(
            'SELECT l.*, d.name_en AS dest_name_en
               FROM iti_lodges l
               JOIN iti_destinations d ON d.id = l.destination_id
              WHERE l.destination_id = ? AND l.is_active = 1
              ORDER BY l.name'
        );
        $st->execute([$destination_id]);
    } else {
        $st = db()->query(
            'SELECT l.*, d.name_en AS dest_name_en
               FROM iti_lodges l
               JOIN iti_destinations d ON d.id = l.destination_id
              WHERE l.is_active = 1
              ORDER BY d.sort_order, l.name'
        );
    }
    return $st->fetchAll();
}

// ── PROGRAMS ─────────────────────────────────────────────────────────────────
// $filters: ['q' => string, 'status' => string]
function iti_get_programs(string $type = null, array $filters = []): array {
    $where  = [];
    $params = [];
    if ($type) {
        $where[] = 'p.program_type = ?';
        $params[] = $type;
    }
    if (!empty($filters['status'])) {
        $where[] = 'p.status = ?';
        $params[] = $filters['status'];
    }
    if (!empty($filters['q'])) {
        $where[] = '(p.title_en LIKE ? OR r.client_name LIKE ?)';
        $q = '%' . $filters['q'] . '%';
        $params[] = $q;
        $params[] = $q;
    }
    $sql = 'SELECT p.*, r.client_name
              FROM iti_programs p
              LEFT JOIN iti_requests r ON r.id = p.request_id'
         . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
         . ' ORDER BY p.updated_at DESC';
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

function iti_get_program(int $id): array|false {
    $st = db()->prepare(
        'SELECT p.*, r.client_name, r.client_email
           FROM iti_programs p
           LEFT JOIN iti_requests r ON r.id = p.request_id
          WHERE p.id = ?'
    );
    $st->execute([$id]);
    return $st->fetch();
}

function iti_get_program_by_token(string $token): array|false {
    $st = db()->prepare(
        'SELECT * FROM iti_programs WHERE public_token = ? AND is_published = 1'
    );
    $st->execute([$token]);
    return $st->fetch();
}

// ── PROGRAM DAYS ──────────────────────────────────────────────────────────────
function iti_get_days(int $program_id): array {
    $st = db()->prepare(
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
function iti_get_prices(int $program_id): array {
    $st = db()->prepare(
        "SELECT * FROM iti_program_prices
          WHERE program_id = ?
          ORDER BY FIELD(price_category,'rack','sto','stospec')"
    );
    $st->execute([$program_id]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $out[$r['price_category']] = $r;
    }
    return $out;
}

// ── INCLUSIONS ────────────────────────────────────────────────────────────────
function iti_get_inclusions(int $program_id): array {
    $lang = iti_lang();
    $col  = "COALESCE(NULLIF(pi.text_{$lang},''), NULLIF(si.text_{$lang},''), pi.text_en, si.text_en) AS display_text";
    $st   = db()->prepare(
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
// $filters: ['q' => string, 'status' => string]
function iti_get_requests(array $filters = []): array {
    $where  = [];
    $params = [];
    if (!empty($filters['status'])) {
        $where[] = 'status = ?';
        $params[] = $filters['status'];
    }
    if (!empty($filters['q'])) {
        $where[] = '(client_name LIKE ? OR client_email LIKE ? OR agent_name LIKE ?)';
        $q = '%' . $filters['q'] . '%';
        $params[] = $q;
        $params[] = $q;
        $params[] = $q;
    }
    $sql = 'SELECT * FROM iti_requests'
         . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
         . ' ORDER BY created_at DESC';
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

function iti_get_request(int $id): array|false {
    $st = db()->prepare('SELECT * FROM iti_requests WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch();
}

// ── TERMS & CONDITIONS ────────────────────────────────────────────────────────
function iti_get_terms(bool $active_only = true): array {
    $sql = 'SELECT * FROM iti_terms_conditions'
         . ($active_only ? ' WHERE is_active = 1' : '')
         . ' ORDER BY effective_date DESC';
    return db()->query($sql)->fetchAll();
}

function iti_get_term(int $id): array|false {
    $st = db()->prepare('SELECT * FROM iti_terms_conditions WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch();
}

// ── ACTIVITIES ────────────────────────────────────────────────────────────────
function iti_get_activities(int $destination_id = null): array {
    if ($destination_id) {
        $st = db()->prepare(
            'SELECT * FROM iti_activities
              WHERE (destination_id = ? OR destination_id IS NULL) AND is_active = 1
              ORDER BY activity_type, name_en'
        );
        $st->execute([$destination_id]);
    } else {
        $st = db()->query(
            'SELECT * FROM iti_activities WHERE is_active = 1 ORDER BY activity_type, name_en'
        );
    }
    return $st->fetchAll();
}

// ── STANDARD INCLUSIONS ───────────────────────────────────────────────────────
function iti_get_standard_inclusions(string $type = null): array {
    if ($type) {
        $st = db()->prepare(
            'SELECT * FROM iti_standard_inclusions WHERE item_type = ? AND is_active = 1 ORDER BY sort_order'
        );
        $st->execute([$type]);
    } else {
        $st = db()->query(
            'SELECT * FROM iti_standard_inclusions WHERE is_active = 1 ORDER BY item_type, sort_order'
        );
    }
    return $st->fetchAll();
}

// ── Costanti lingue (alias per compatibilità) ─────────────────────────────────
if (!defined('ITI_LANGS')) {
    define('ITI_LANGS', ['en', 'it', 'fr', 'es', 'de']);
}
if (!defined('ITI_LANG_LABELS')) {
    define('ITI_LANG_LABELS', [
        'en' => 'English',
        'it' => 'Italiano',
        'fr' => 'Français',
        'es' => 'Español',
        'de' => 'Deutsch',
    ]);
}

// ── Helper: genera <option> tags ──────────────────────────────────────────────
// $options: ['value' => 'Label', ...]  oppure ['value1','value2',...]
function iti_options(array $options, string $selected = ''): string {
    $out = '';
    foreach ($options as $val => $label) {
        if (is_int($val)) { $val = $label; } // array numerico
        $sel  = ($val == $selected) ? ' selected' : '';
        $out .= '<option value="' . h((string)$val) . '"' . $sel . '>' . h((string)$label) . '</option>';
    }
    return $out;
}

// ── Helper: navigazione ITI ───────────────────────────────────────────────────
function iti_nav(string $current = ''): void {
    $links = [
        'Dashboard'    => ITI_MODULE_URL . '/index.php',
        'Programs'     => ITI_MODULE_URL . '/programs.php',
        'Requests'     => ITI_MODULE_URL . '/requests.php',
        'Destinations' => ITI_MODULE_URL . '/destinations.php',
        'Lodges'       => ITI_MODULE_URL . '/lodges.php',
        'Transfers'    => ITI_MODULE_URL . '/transfers.php',
        'Activities'   => ITI_MODULE_URL . '/activities.php',
    ];
    echo '<nav class="iti-nav" style="display:flex;gap:4px;flex-wrap:wrap;margin-bottom:20px;border-bottom:1px solid var(--grey-light,#e5e7eb);padding-bottom:8px;">';
    foreach ($links as $label => $url) {
        $active = ($label === $current)
            ? 'background:var(--red,#c0392b);color:#fff;'
            : 'background:transparent;color:var(--grey-dark,#374151);';
        echo '<a href="' . $url . '" style="' . $active
           . 'padding:5px 12px;border-radius:6px;text-decoration:none;font-size:.8rem;font-weight:500;">'
           . h($label) . '</a>';
    }
    echo '</nav>';
}
