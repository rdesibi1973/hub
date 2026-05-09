<?php
/**
 * One-time setup — creates the quotes tables.
 * Run via browser once, then DELETE this file.
 */
require_once 'config.php';
requireLogin();
if (!isAdmin()) { http_response_code(403); die('Admin only.'); }

$db  = db();
$out = [];
$err = null;

try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS quotes (
            id              INT AUTO_INCREMENT PRIMARY KEY,
            quote_number    VARCHAR(10)  NOT NULL,
            request_id      INT          NOT NULL,
            agent_id        INT          NOT NULL,
            customer_name   VARCHAR(200) NOT NULL,
            agent_name      VARCHAR(150),
            agency_name     VARCHAR(150),
            start_date      DATE,
            adults          TINYINT      NOT NULL DEFAULT 2,
            teens           TINYINT      NOT NULL DEFAULT 0,
            children        TINYINT      NOT NULL DEFAULT 0,
            program         VARCHAR(100),
            markup_type     ENUM('standard','to','custom') NOT NULL DEFAULT 'standard',
            markup_pct      DECIMAL(6,2) NOT NULL DEFAULT 25.00,
            bank_commission DECIMAL(10,2) NOT NULL DEFAULT 100.00,
            total_costs     DECIMAL(10,2) NOT NULL DEFAULT 0,
            total_price     DECIMAL(10,2) NOT NULL DEFAULT 0,
            status          ENUM('draft','final') NOT NULL DEFAULT 'draft',
            notes           TEXT,
            created_by      INT NOT NULL,
            created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_request (request_id),
            INDEX idx_agent   (agent_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    $out[] = '✓ Table <code>quotes</code> ready.';

    $db->exec("
        CREATE TABLE IF NOT EXISTS quote_days (
            id           INT AUTO_INCREMENT PRIMARY KEY,
            quote_id     INT NOT NULL,
            day_number   TINYINT NOT NULL,
            location     VARCHAR(200),
            lodge        VARCHAR(200),
            lodge_custom DECIMAL(10,2),
            jeep         ENUM('none','half','full') NOT NULL DEFAULT 'full',
            drinks       TINYINT(1) NOT NULL DEFAULT 1,
            park         VARCHAR(50)  NOT NULL DEFAULT 'none',
            park_custom  DECIMAL(10,2),
            flight       VARCHAR(50)  NOT NULL DEFAULT 'none',
            flight_custom DECIMAL(10,2),
            day_total    DECIMAL(10,2) NOT NULL DEFAULT 0,
            FOREIGN KEY (quote_id) REFERENCES quotes(id) ON DELETE CASCADE,
            INDEX idx_quote (quote_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    $out[] = '✓ Table <code>quote_days</code> ready.';

    $db->exec("
        CREATE TABLE IF NOT EXISTS quote_day_items (
            id           INT AUTO_INCREMENT PRIMARY KEY,
            quote_day_id INT NOT NULL,
            description  VARCHAR(500),
            item_type    ENUM('pax','fixed') NOT NULL DEFAULT 'fixed',
            amount       DECIMAL(10,2) NOT NULL DEFAULT 0,
            FOREIGN KEY (quote_day_id) REFERENCES quote_days(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    $out[] = '✓ Table <code>quote_day_items</code> ready.';

    // Add 'quotes' permission to admin role
    $adminRoleId = $db->query("SELECT id FROM roles WHERE name='admin' LIMIT 1")->fetchColumn();
    if ($adminRoleId) {
        $db->prepare("INSERT IGNORE INTO role_permissions (role_id, module) VALUES (?,?)")
           ->execute([$adminRoleId, 'quotes']);
        $out[] = '✓ Permission <b>quotes</b> added to admin role.';
    }

} catch (PDOException $e) {
    $err = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Quotes DB Setup</title>
<style>body{font-family:sans-serif;max-width:600px;margin:60px auto;background:#F7F5F2;}
.item{padding:8px 12px;margin:6px 0;border-radius:6px;background:#fff;border-left:4px solid #1A6B3A;font-size:.9rem;}
.err{border-left-color:#C0211B;background:#FAE8E7;}
.done{margin-top:24px;padding:16px;background:#D6EDD9;border-radius:8px;color:#1A6B3A;font-weight:700;}
</style>
</head>
<body>
<h2>Quotes — DB Setup</h2>
<?php if ($err): ?>
  <div class="item err">❌ <?= htmlspecialchars($err) ?></div>
<?php else: ?>
  <?php foreach ($out as $l): ?><div class="item"><?= $l ?></div><?php endforeach; ?>
  <div class="done">✓ Done! Delete this file from the server.</div>
<?php endif; ?>
</body>
</html>
