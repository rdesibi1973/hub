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
        $where[] = '(p.title_en LIKE ? OR r.client_name LIKE ? OR p.ref_number LIKE ?)';
        $q = '%' . $filters['q'] . '%';
        $params[] = $q;
        $params[] = $q;
        $params[] = $q;
    }
    if (!empty($filters['ref'])) {
        $where[] = 'p.ref_number LIKE ?';
        $params[] = '%' . $filters['ref'] . '%';
    }
    if (!empty($filters['lang'])) {
        $where[] = 'p.display_language = ?';
        $params[] = $filters['lang'];
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
function iti_nav(string $current = '', array $breadcrumbs = []): void {
    $links = [
        'Dashboard'    => ITI_MODULE_URL . '/index.php',
        'Programs'     => ITI_MODULE_URL . '/programs.php',
        'Requests'     => ITI_MODULE_URL . '/requests.php',
        'Destinations' => ITI_MODULE_URL . '/destinations.php',
        'Lodges'       => ITI_MODULE_URL . '/lodges.php',
        'Transfers'    => ITI_MODULE_URL . '/transfers.php',
        'Activities'   => ITI_MODULE_URL . '/activities.php',
    ];
    echo '<nav class="iti-nav" style="display:flex;gap:4px;flex-wrap:wrap;margin-bottom:20px;border-bottom:1px solid var(--grey-lt);padding-bottom:8px;">';
    foreach ($links as $label => $url) {
        $active = ($label === $current)
            ? 'background:var(--red);color:#fff;'
            : 'background:transparent;color:var(--grey-dk);';
        echo '<a href="' . $url . '" style="' . $active
           . 'padding:5px 12px;border-radius:6px;text-decoration:none;font-size:.8rem;font-weight:500;">'
           . h($label) . '</a>';
    }
    echo '</nav>';
    // Breadcrumbs
    if ($breadcrumbs) {
        echo '<div style="font-size:.72rem;color:var(--grey-mid);margin-bottom:16px;">';
        echo '<a href="' . ITI_MODULE_URL . '/index.php" style="color:var(--grey-mid);text-decoration:none;">ITI</a>';
        foreach ($breadcrumbs as $b) {
            echo ' › <a href="' . h($b['url']) . '" style="color:var(--grey-mid);text-decoration:none;">' . h($b['label']) . '</a>';
        }
        if ($current) echo ' › <span style="color:var(--black);">' . h($current) . '</span>';
        echo '</div>';
    }
}

// ── Costanti prezzi ───────────────────────────────────────────────────────────
if (!defined('ITI_PRICE_CATEGORIES')) {
    define('ITI_PRICE_CATEGORIES', [
        'rack'    => 'Rack (Direct clients)',
        'sto'     => 'STO (Standard agents)',
        'stospec' => 'STO Special',
    ]);
}

// ── Costanti status programma ─────────────────────────────────────────────────
if (!defined('ITI_PROGRAM_STATUSES')) {
    define('ITI_PROGRAM_STATUSES', [
        'draft'     => 'Draft',
        'sent'      => 'Sent',
        'confirmed' => 'Confirmed',
        'cancelled' => 'Cancelled',
    ]);
}

// ── Costanti attività ─────────────────────────────────────────────────────────
if (!defined('ITI_ACTIVITY_TYPES')) {
    define('ITI_ACTIVITY_TYPES', [
        'game_drive'     => 'Game Drive',
        'walking_safari' => 'Walking Safari',
        'cultural'       => 'Cultural',
        'boat'           => 'Boat Safari',
        'balloon'        => 'Hot Air Balloon',
        'hiking'         => 'Hiking',
        'beach'          => 'Beach',
        'other'          => 'Other',
    ]);
}

if (!defined('ITI_ACTIVITY_ICONS')) {
    define('ITI_ACTIVITY_ICONS', [
        'game_drive'     => '🦁',
        'walking_safari' => '🥾',
        'cultural'       => '🏛️',
        'boat'           => '⛵',
        'balloon'        => '🎈',
        'hiking'         => '🏔️',
        'beach'          => '🏖️',
        'other'          => '⭐',
    ]);
}


// ── Costanti lodge ────────────────────────────────────────────────────────────
if (!defined('ITI_LODGE_CATEGORIES')) {
    define('ITI_LODGE_CATEGORIES', [
        'budget'      => 'Budget',
        'mid'         => 'Mid-range',
        'luxury'      => 'Luxury',
        'ultra_luxury'=> 'Ultra Luxury',
    ]);
}

if (!defined('ITI_LODGE_TYPES')) {
    define('ITI_LODGE_TYPES', [
        'lodge'       => 'Lodge',
        'tented_camp' => 'Tented Camp',
        'hotel'       => 'Hotel',
        'mobile_camp' => 'Mobile Camp',
        'house'       => 'House',
    ]);
}

if (!defined('ITI_REQUEST_STATUSES')) {
    define('ITI_REQUEST_STATUSES', [
        'open'      => 'Open',
        'quoted'    => 'Quoted',
        'confirmed' => 'Confirmed',
        'cancelled' => 'Cancelled',
    ]);
}


// ── Alias per compatibilità con program_edit.php ──────────────────────────────
function iti_get_program_days(int $program_id): array {
    return iti_get_days($program_id);
}

function iti_get_day_activities(int $program_day_id): array {
    $st = db()->prepare(
        'SELECT da.*, a.name_en, a.name_it, a.name_fr, a.name_es, a.name_de,
                a.activity_type, a.duration_hours,
                d.name_en AS dest_name_en
           FROM iti_day_activities da
           JOIN iti_activities a ON a.id = da.activity_id
           LEFT JOIN iti_destinations d ON d.id = a.destination_id
          WHERE da.program_day_id = ?
          ORDER BY da.sort_order'
    );
    $st->execute([$program_day_id]);
    return $st->fetchAll();
}

function iti_get_day_flights(int $program_day_id): array {
    $st = db()->prepare(
        'SELECT df.*, fr.from_airport, fr.to_airport, fr.operator,
                fr.from_code, fr.to_code, fr.duration_min
           FROM iti_day_flights df
           JOIN iti_flight_routes fr ON fr.id = df.flight_route_id
          WHERE df.program_day_id = ?
          ORDER BY df.sort_order'
    );
    $st->execute([$program_day_id]);
    return $st->fetchAll();
}

function iti_get_transfer_routes(): array {
    $st = db()->query(
        'SELECT tr.*, 
                fd.name_en AS from_name,
                td.name_en AS to_name
           FROM iti_transfer_routes tr
           JOIN iti_destinations fd ON fd.id = tr.from_destination
           JOIN iti_destinations td ON td.id = tr.to_destination
          WHERE tr.is_active = 1
          ORDER BY fd.name_en, td.name_en'
    );
    return $st->fetchAll();
}

function iti_get_flight_routes(): array {
    $st = db()->query(
        'SELECT * FROM iti_flight_routes WHERE is_active = 1
          ORDER BY from_airport, to_airport'
    );
    return $st->fetchAll();
}

function iti_get_supplements(int $program_id): array {
    $st = db()->prepare(
        'SELECT * FROM iti_price_supplements WHERE program_id = ? ORDER BY sort_order'
    );
    $st->execute([$program_id]);
    return $st->fetchAll();
}

function iti_get_discounts(int $program_id): array {
    $st = db()->prepare(
        'SELECT * FROM iti_price_discounts WHERE program_id = ? ORDER BY sort_order'
    );
    $st->execute([$program_id]);
    return $st->fetchAll();
}


// ── Alias e funzioni mancanti per program_edit.php ───────────────────────────

function iti_get_program_prices(int $program_id): array {
    return iti_get_prices($program_id);
}

function iti_get_program_inclusions(int $program_id): array {
    return iti_get_inclusions($program_id);
}

// Lodge raggruppati per destinazione: ['dest_name' => [lodge, lodge, ...]]
function iti_lodges_grouped(): array {
    $rows = iti_get_lodges();
    $out  = [];
    foreach ($rows as $l) {
        $dest = $l['dest_name_en'] ?? 'Other';
        $out[$dest][] = $l;
    }
    ksort($out);
    return $out;
}

// Mappa transfer routes: [id => route_row] per lookup veloce
function iti_transfer_routes_map(): array {
    $rows = iti_get_transfer_routes();
    $map  = [];
    foreach ($rows as $r) { $map[$r['id']] = $r; }
    return $map;
}

// Mappa flight routes: [id => route_row]
function iti_flight_routes_map(): array {
    $rows = iti_get_flight_routes();
    $map  = [];
    foreach ($rows as $r) { $map[$r['id']] = $r; }
    return $map;
}

// ── ITI CSS (da iniettare via $extra_css prima di layout_header) ──────────────
function iti_extra_css(): string {
    return '
/* ── ITI MODULE STYLES ───────────────────────────────────────────── */

/* Buttons */
.btn-red     { background:var(--red);      color:#fff; }
.btn-red:hover { background:var(--red-dk); color:#fff; }
.btn-outline { background:var(--white); color:var(--grey-dk); border:1.5px solid var(--grey-lt); }
.btn-outline:hover { border-color:var(--grey-mid); background:var(--off-white); }
.btn-green   { background:var(--green);    color:#fff; }
.btn-green:hover { background:#145530; }
.btn-amber   { background:var(--amber);    color:#fff; }

/* Badges ITI */
.badge-grey  { background:var(--grey-lt);  color:var(--grey-dk); }
.badge-amber { background:var(--amber-lt); color:#7A4F01; }
.badge-green { background:var(--green-lt); color:var(--green); }
.badge-red   { background:var(--red-lt);   color:var(--red-dk); }
.badge-navy  { background:var(--navy-lt);  color:var(--navy); }

/* Page header */
.page-header { display:flex; align-items:flex-start; justify-content:space-between;
               gap:16px; margin-bottom:24px; flex-wrap:wrap; }
.page-header h2 { font-family:"Merriweather",serif; font-size:1.25rem;
                  font-weight:700; color:var(--red-dk); }
.page-header .sub { font-size:.75rem; color:var(--grey-mid); margin-top:3px; }

/* Stat grid */
.stat-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(180px,1fr));
             gap:16px; margin-bottom:28px; }
.stat-card { background:var(--white); border-radius:10px;
             box-shadow:0 1px 8px rgba(0,0,0,.08);
             padding:18px 20px; border-top:3px solid var(--grey-lt); }
.stat-card.red   { border-top-color:var(--red); }
.stat-card.green { border-top-color:var(--green); }
.stat-card.amber { border-top-color:var(--amber); }
.stat-card.blue  { border-top-color:var(--navy); }
.stat-label { font-size:.68rem; font-weight:700; text-transform:uppercase;
              letter-spacing:.1em; color:var(--grey-mid); margin-bottom:6px; }
.stat-value { font-family:"Merriweather",serif; font-size:1.8rem;
              font-weight:700; color:var(--black); line-height:1; }
.stat-sub   { font-size:.72rem; color:var(--grey-mid); margin-top:6px; }

/* Table wrap */
.table-wrap { background:var(--white); border-radius:10px;
              box-shadow:0 1px 8px rgba(0,0,0,.08); overflow:hidden; }
.table-wrap table { width:100%; border-collapse:collapse; font-size:.82rem; }
.table-wrap thead th { background:var(--black); color:rgba(255,255,255,.8);
                       padding:10px 16px; text-align:left; font-size:.65rem;
                       font-weight:700; text-transform:uppercase;
                       letter-spacing:.1em; white-space:nowrap; }
.table-wrap tbody td { padding:11px 16px; border-bottom:1px solid var(--grey-lt);
                       color:var(--grey-dk); vertical-align:middle; }
.table-wrap tbody tr:last-child td { border-bottom:none; }
.table-wrap tbody tr:hover td { background:#FAFAFA; }

/* Form card */
.form-card { background:var(--white); border-radius:10px;
             box-shadow:0 1px 8px rgba(0,0,0,.08);
             padding:28px 32px; margin-bottom:24px; }
.form-section-title { font-size:.68rem; font-weight:700; text-transform:uppercase;
                      letter-spacing:.14em; color:var(--grey-mid);
                      padding-bottom:10px; margin-bottom:16px; margin-top:24px;
                      border-bottom:1px solid var(--grey-lt); }
.form-section-title:first-child { margin-top:0; }
.form-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(220px,1fr));
             gap:16px 20px; }
.form-group label { display:block; font-size:.72rem; font-weight:700;
                    text-transform:uppercase; letter-spacing:.08em;
                    color:var(--grey-dk); margin-bottom:6px; }
.form-group input[type=text],
.form-group input[type=number],
.form-group input[type=email],
.form-group input[type=date],
.form-group select,
.form-group textarea { width:100%; padding:9px 12px;
                       border:1.5px solid var(--grey-lt); border-radius:6px;
                       font-family:"Open Sans",sans-serif; font-size:.85rem;
                       color:var(--black); background:var(--white);
                       transition:border-color .15s; }
.form-group input:focus,
.form-group select:focus,
.form-group textarea:focus { outline:none; border-color:var(--red); }
.form-group textarea { resize:vertical; min-height:80px; }

/* Empty state */
.empty-state { text-align:center; padding:48px 20px; color:var(--grey-mid); }
.empty-state .icon { font-size:2.5rem; margin-bottom:12px; }
.empty-state p { font-size:.85rem; }

/* ITI Nav override */
.iti-nav a:hover { opacity:.85; }
';
}
