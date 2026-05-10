<?php
// migrate_quote_v2.php — run ONCE to upgrade the quote schema to v2.
// Access: /setup/migrate_quote_v2.php?token=migrate-v2-2026

if (($_GET['token'] ?? '') !== 'migrate-v2-2026') {
    http_response_code(403); die('Forbidden');
}

require_once __DIR__ . '/../modules/leads/config.php';
$db = db();

$log = [];
function run(PDO $db, string $sql, string $label): void {
    global $log;
    try {
        $db->exec($sql);
        $log[] = ['ok', $label];
    } catch (PDOException $e) {
        $log[] = ['err', "$label — " . $e->getMessage()];
    }
}

// ── 1. quote_days schema ──────────────────────────────────────────────────────
run($db,
    "ALTER TABLE quote_days CHANGE COLUMN location route VARCHAR(200) DEFAULT NULL",
    "quote_days: rename location → route");
run($db,
    "ALTER TABLE quote_days ADD COLUMN attraction VARCHAR(200) DEFAULT NULL AFTER route",
    "quote_days: add attraction");
run($db,
    "ALTER TABLE quote_days MODIFY COLUMN jeep ENUM('none','half','full','double','contribution') NOT NULL DEFAULT 'full'",
    "quote_days: extend jeep ENUM (double, contribution)");
run($db,
    "ALTER TABLE quote_days ADD COLUMN jeep_rate_custom DECIMAL(10,2) DEFAULT NULL AFTER jeep",
    "quote_days: add jeep_rate_custom (user override)");
run($db,
    "ALTER TABLE quote_days ADD COLUMN flight_route_id INT DEFAULT NULL AFTER flight_custom",
    "quote_days: add flight_route_id");
run($db,
    "ALTER TABLE quote_days ADD COLUMN transfer_rate_id INT DEFAULT NULL AFTER flight_route_id",
    "quote_days: add transfer_rate_id");
run($db,
    "ALTER TABLE quote_days ADD COLUMN transfer_custom DECIMAL(10,2) DEFAULT NULL AFTER transfer_rate_id",
    "quote_days: add transfer_custom");

// ── 2. New pricing tables ─────────────────────────────────────────────────────
run($db, "CREATE TABLE IF NOT EXISTS jeep_rates (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    type        ENUM('half','full','double','contribution') NOT NULL,
    valid_from  DATE NOT NULL,
    valid_to    DATE DEFAULT NULL,
    rate        DECIMAL(10,2) NOT NULL,
    notes       VARCHAR(200) DEFAULT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", "Create jeep_rates");

run($db, "CREATE TABLE IF NOT EXISTS activity_rates (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(200) NOT NULL,
    category    ENUM('activity','transfer','safari_fixed') NOT NULL,
    item_type   ENUM('pax','fixed') NOT NULL DEFAULT 'pax',
    valid_from  DATE NOT NULL,
    valid_to    DATE DEFAULT NULL,
    rate        DECIMAL(10,2) NOT NULL,
    active      TINYINT(1) NOT NULL DEFAULT 1,
    notes       VARCHAR(200) DEFAULT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", "Create activity_rates");

run($db, "CREATE TABLE IF NOT EXISTS flight_routes (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    route_name  VARCHAR(200) NOT NULL,
    origin      VARCHAR(100) DEFAULT NULL,
    destination VARCHAR(100) DEFAULT NULL,
    airline     VARCHAR(100) DEFAULT NULL,
    valid_from  DATE NOT NULL,
    valid_to    DATE DEFAULT NULL,
    rate_pax    DECIMAL(10,2) NOT NULL,
    active      TINYINT(1) NOT NULL DEFAULT 1,
    notes       VARCHAR(200) DEFAULT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", "Create flight_routes");

run($db, "CREATE TABLE IF NOT EXISTS quote_safari_items (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    quote_id         INT NOT NULL,
    activity_rate_id INT DEFAULT NULL,
    description      VARCHAR(500) NOT NULL,
    item_type        ENUM('pax','fixed') NOT NULL DEFAULT 'pax',
    amount           DECIMAL(10,2) NOT NULL,
    FOREIGN KEY (quote_id) REFERENCES quotes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", "Create quote_safari_items");

// ── 3. Seed data ──────────────────────────────────────────────────────────────
run($db, "INSERT INTO jeep_rates (type, valid_from, valid_to, rate, notes) VALUES
    ('half',        '2026-01-01', '2026-12-31', 125.00, '2026'),
    ('full',        '2026-01-01', '2026-12-31', 250.00, '2026'),
    ('double',      '2026-01-01', '2026-12-31', 500.00, '2026 — 2 jeeps'),
    ('contribution','2026-01-01', '2026-12-31',  80.00, '2026')
    ON DUPLICATE KEY UPDATE rate=VALUES(rate)",
    "Seed jeep_rates 2026");

run($db, "INSERT INTO activity_rates (name, category, item_type, valid_from, rate, notes) VALUES
    ('Emergency',         'safari_fixed', 'pax',   '2026-01-01', 30.00, 'Emergency evacuation — per safari'),
    ('Medivac',           'safari_fixed', 'pax',   '2026-01-01',  5.00, 'Medical evacuation — per safari'),
    ('Lunch boxes',       'activity',     'pax',   '2026-01-01', 10.00, 'Packed lunch'),
    ('Natural Walk',      'activity',     'fixed',  '2026-01-01', 20.00, 'Guided nature walk'),
    ('Cultural Visit',    'activity',     'fixed',  '2026-01-01', 30.00, 'Village / cultural visit'),
    ('Airport Transfer',  'transfer',     'fixed',  '2026-01-01', 60.00, 'Airport pickup/drop')
    ON DUPLICATE KEY UPDATE rate=VALUES(rate)",
    "Seed activity_rates");

run($db, "INSERT INTO flight_routes (route_name, origin, destination, airline, valid_from, rate_pax, notes) VALUES
    ('Arusha → Zanzibar', 'Arusha', 'Zanzibar', 'Coastal Aviation', '2026-01-01', 210.00, 'ex-ZNZ')
    ON DUPLICATE KEY UPDATE rate_pax=VALUES(rate_pax)",
    "Seed flight_routes");

// ── Output ────────────────────────────────────────────────────────────────────
echo '<!doctype html><meta charset=utf-8><title>Migration v2</title>';
echo '<style>body{font-family:monospace;padding:20px}
      .ok{color:#166534}.err{color:#dc2626}</style>';
echo '<h2>Quote v2 Migration</h2><pre>';
foreach ($log as [$status, $msg]) {
    echo '<span class="'.$status.'">'.($status==='ok' ? '✓' : '✗').'</span> '.htmlspecialchars($msg)."\n";
}
echo '</pre><p><strong>Done.</strong> You can delete this file from the server.</p>';
