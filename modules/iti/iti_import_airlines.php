<?php
/**
 * iti_import_airlines.php
 * Static import of airlines into iti_airlines table.
 * Run ONCE after CREATE TABLE iti_airlines (see inline SQL comment).
 */
require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_once __DIR__ . '/includes/iti_functions.php';

$db       = db();
$_cu      = current_user();
$can_edit = in_array($_cu['role_name'], ['admin', 'manager']);

// Check table exists
$table_ok = false;
try {
    $db->query("SELECT 1 FROM iti_airlines LIMIT 1");
    $table_ok = true;
} catch (Exception $e) {}

// Existing airline names
$existing = [];
if ($table_ok) {
    foreach ($db->query("SELECT LOWER(name) AS n FROM iti_airlines")->fetchAll() as $r)
        $existing[] = $r['n'];
}

// ── Airline data ──────────────────────────────────────────────────────────────
// type: domestic | regional | international
$AIRLINES = [
    // ── TANZANIAN DOMESTIC / SAFARI ──────────────────────────────────────────
    ['name'=>'Auric Air',          'iata'=>'4Y', 'type'=>'domestic',      'country'=>'Tanzania', 'website'=>'https://www.auricair.com'],
    ['name'=>'Coastal Aviation',   'iata'=>'CQ', 'type'=>'domestic',      'country'=>'Tanzania', 'website'=>'https://coastal.co.tz'],
    ['name'=>'Flightlink',         'iata'=>'YS', 'type'=>'domestic',      'country'=>'Tanzania', 'website'=>'https://flightlink.co.tz'],
    ['name'=>'Air Excel',          'iata'=>'XLL','type'=>'domestic',      'country'=>'Tanzania', 'website'=>'https://airexcel.co.tz'],
    ['name'=>'Safari Airlink',     'iata'=>'',   'type'=>'domestic',      'country'=>'Tanzania', 'website'=>'https://safariairlink.co.tz'],
    ['name'=>'As Salaam Air',      'iata'=>'',   'type'=>'domestic',      'country'=>'Tanzania', 'website'=>'https://assalaamair.co.tz'],
    ['name'=>'Tropical Air',       'iata'=>'',   'type'=>'domestic',      'country'=>'Tanzania', 'website'=>'https://tropicalair.africa'],
    ['name'=>'Precision Air',      'iata'=>'PW', 'type'=>'regional',      'country'=>'Tanzania', 'website'=>'https://precisionairtz.com'],
    ['name'=>'Air Tanzania',       'iata'=>'TC', 'type'=>'regional',      'country'=>'Tanzania', 'website'=>'https://airtanzania.co.tz'],
    // ── REGIONAL EAST AFRICA ─────────────────────────────────────────────────
    ['name'=>'Kenya Airways',      'iata'=>'KQ', 'type'=>'regional',      'country'=>'Kenya',    'website'=>'https://www.kenya-airways.com'],
    ['name'=>'Ethiopian Airlines', 'iata'=>'ET', 'type'=>'international', 'country'=>'Ethiopia', 'website'=>'https://www.ethiopianairlines.com'],
    ['name'=>'RwandAir',           'iata'=>'WB', 'type'=>'regional',      'country'=>'Rwanda',   'website'=>'https://www.rwandair.com'],
    ['name'=>'Uganda Airlines',    'iata'=>'UR', 'type'=>'regional',      'country'=>'Uganda',   'website'=>'https://www.ugandaairlines.co.ug'],
    ['name'=>'Jambojet',           'iata'=>'JM', 'type'=>'regional',      'country'=>'Kenya',    'website'=>'https://www.jambojet.com'],
    ['name'=>'Safarilink Aviation','iata'=>'F2', 'type'=>'regional',      'country'=>'Kenya',    'website'=>'https://www.safarilink.co.ke'],
    ['name'=>'Air Kenya',          'iata'=>'QP', 'type'=>'regional',      'country'=>'Kenya',    'website'=>'https://www.airkenya.com'],
    // ── INTERNATIONAL ────────────────────────────────────────────────────────
    ['name'=>'Emirates',           'iata'=>'EK', 'type'=>'international', 'country'=>'UAE',          'website'=>'https://www.emirates.com'],
    ['name'=>'Qatar Airways',      'iata'=>'QR', 'type'=>'international', 'country'=>'Qatar',         'website'=>'https://www.qatarairways.com'],
    ['name'=>'Turkish Airlines',   'iata'=>'TK', 'type'=>'international', 'country'=>'Turkey',        'website'=>'https://www.turkishairlines.com'],
    ['name'=>'KLM Royal Dutch Airlines','iata'=>'KL','type'=>'international','country'=>'Netherlands','website'=>'https://www.klm.com'],
    ['name'=>'Air France',         'iata'=>'AF', 'type'=>'international', 'country'=>'France',        'website'=>'https://www.airfrance.com'],
    ['name'=>'Lufthansa',          'iata'=>'LH', 'type'=>'international', 'country'=>'Germany',       'website'=>'https://www.lufthansa.com'],
    ['name'=>'Condor',             'iata'=>'DE', 'type'=>'international', 'country'=>'Germany',       'website'=>'https://www.condor.com'],
    ['name'=>'Oman Air',           'iata'=>'WY', 'type'=>'international', 'country'=>'Oman',          'website'=>'https://www.omanair.com'],
];

// Flag duplicates
foreach ($AIRLINES as &$a) {
    $a['is_duplicate'] = in_array(strtolower($a['name']), $existing);
}
unset($a);

// ── POST: import ──────────────────────────────────────────────────────────────
$import_log  = [];
$import_done = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_edit && $table_ok) {
    $stmt = $db->prepare(
        'INSERT INTO iti_airlines (name,iata_code,type,country,website,is_active)
         VALUES (?,?,?,?,?,1)'
    );
    $ok = $skip = $err = 0;
    foreach ($AIRLINES as $i => $a) {
        if (isset($_POST["skip_{$i}"]) || $a['is_duplicate']) { $skip++; continue; }
        try {
            $stmt->execute([$a['name'], $a['iata'] ?: null, $a['type'], $a['country'], $a['website']]);
            $existing[]   = strtolower($a['name']);
            $import_log[] = "✅ " . $a['name'] . " (" . strtoupper($a['type']) . ")";
            $ok++;
        } catch (Exception $e) {
            $import_log[] = "❌ " . $a['name'] . ": " . $e->getMessage();
            $err++;
        }
    }
    $import_log[] = "─── Done: {$ok} imported, {$skip} skipped, {$err} errors ───";
    $import_done  = true;
}

$new_count = count(array_filter($AIRLINES, fn($a) => !$a['is_duplicate']));
$dup_count = count(array_filter($AIRLINES, fn($a) => $a['is_duplicate']));

$page_title = 'Import Airlines';
$extra_css  = iti_extra_css() . '
.import-table{width:100%;border-collapse:collapse;background:#fff;font-size:.78rem;border:1px solid var(--grey-lt);}
.import-table th{background:#f0f0ef;padding:7px 10px;text-align:left;font-size:.71rem;white-space:nowrap;border-bottom:1.5px solid var(--grey-lt);}
.import-table td{padding:8px 10px;border-bottom:1px solid #f0f0ef;vertical-align:middle;}
.import-table tr.dup td{background:#fffbeb;}
.badge-dup{display:inline-block;padding:1px 7px;border-radius:4px;background:#FEF3C7;color:#92400E;font-size:.65rem;font-weight:700;margin-left:4px;}
.badge-dom{background:#dcfce7;color:#166534;}
.badge-reg{background:#dbeafe;color:#1e40af;}
.badge-int{background:#f3e8ff;color:#7e22ce;}
.log-box{background:#1a1a1a;color:#a8e6a3;font-family:monospace;font-size:.75rem;padding:14px 16px;border-radius:8px;max-height:220px;overflow-y:auto;white-space:pre-wrap;margin:12px 0;}
';
include __DIR__ . '/../../includes/layout_header.php';
?>
<main>
<?php iti_nav('Import Airlines'); ?>
<?php iti_flash_render(); ?>

<div class="page-header">
  <div>
    <h2>✈️ Import Airlines</h2>
    <div class="sub">Master Data › Airlines › Import — <?= count($AIRLINES) ?> airlines</div>
  </div>
  <a href="airlines.php" class="btn btn-outline btn-sm">← Back to Airlines</a>
</div>

<?php if (!$table_ok): ?>
<div style="background:#fee2e2;border:1px solid #fca5a5;border-radius:8px;padding:16px 20px;margin-bottom:16px;">
  <strong>⚠ Table <code>iti_airlines</code> does not exist yet.</strong><br>
  Run this SQL on MySQL first, then reload this page:
  <pre style="margin:10px 0 0;background:#1a1a1a;color:#fca5a5;padding:12px;border-radius:6px;font-size:.75rem;overflow-x:auto;">CREATE TABLE `iti_airlines` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `iata_code` varchar(3) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `type` enum('domestic','regional','international') NOT NULL DEFAULT 'international',
  `country` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Tanzania',
  `website` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_airline_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;</pre>
</div>

<?php elseif ($import_done): ?>
<div class="form-card">
  <div class="form-section-title">Import Result</div>
  <div class="log-box"><?= implode("\n", array_map('htmlspecialchars', $import_log)) ?></div>
  <div style="margin-top:16px;display:flex;gap:10px;">
    <a href="airlines.php" class="btn btn-red">→ View Airlines</a>
    <a href="iti_import_airlines.php" class="btn btn-outline">↩ Import again</a>
  </div>
</div>

<?php else: ?>
<form method="POST" action="iti_import_airlines.php">
<div class="form-card">
  <div class="form-section-title">Review &amp; Import</div>
  <p style="font-size:.8rem;color:var(--grey-mid);margin-bottom:16px;">
    <strong><?= $new_count ?></strong> new airlines ready to import
    &nbsp;·&nbsp; <strong><?= $dup_count ?></strong> already in database
  </p>
  <table class="import-table">
    <thead>
      <tr>
        <th style="width:34px;text-align:center;">Skip</th>
        <th>Airline</th>
        <th style="width:50px;">IATA</th>
        <th>Type</th>
        <th>Country</th>
        <th>Website</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($AIRLINES as $i => $a):
      $type_badge = match($a['type']) { 'domestic'=>'dom', 'regional'=>'reg', default=>'int' };
      $type_label = match($a['type']) { 'domestic'=>'Domestic', 'regional'=>'Regional', default=>'International' };
    ?>
    <tr class="<?= $a['is_duplicate'] ? 'dup' : '' ?>">
      <td style="text-align:center;">
        <input type="checkbox" name="skip_<?= $i ?>" value="1" <?= $a['is_duplicate'] ? 'checked' : '' ?>>
      </td>
      <td>
        <strong><?= h($a['name']) ?></strong>
        <?php if ($a['is_duplicate']): ?><span class="badge-dup">EXISTS</span><?php endif; ?>
      </td>
      <td style="font-family:monospace;font-weight:700;color:var(--grey-dk);"><?= h($a['iata']) ?></td>
      <td><span class="badge-dup badge-<?= $type_badge ?>"><?= $type_label ?></span></td>
      <td style="font-size:.75rem;color:var(--grey-mid);"><?= h($a['country']) ?></td>
      <td style="font-size:.72rem;"><a href="<?= h($a['website']) ?>" target="_blank" rel="noopener"><?= h($a['website']) ?></a></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php if ($can_edit): ?>
  <div style="margin-top:18px;display:flex;gap:10px;align-items:center;">
    <button type="submit" class="btn btn-red">⬆ Import Selected Airlines</button>
    <a href="airlines.php" class="btn btn-outline">Cancel</a>
  </div>
  <?php endif; ?>
</div>
</form>
<?php endif; ?>
</main>
<?php include __DIR__ . '/../../includes/layout_footer.php'; ?>
