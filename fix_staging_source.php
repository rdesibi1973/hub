<?php
/**
 * One-time fix: rebuild initial_request and correct source for staging leads
 * where raw_data contains recent_conversion_event_name but source was set to iBot.
 * Run once via browser, then delete this file.
 */
require_once __DIR__ . '/includes/db.php'; // provides $pdo

$rows = $pdo->query(
    "SELECT id, source, initial_request, raw_data FROM lead_staging WHERE raw_data IS NOT NULL"
)->fetchAll();

$fixed   = 0;
$skipped = 0;

foreach ($rows as $row) {
    $p = json_decode($row['raw_data'], true);
    if (!is_array($p)) { $skipped++; continue; }

    $formName = trim($p['recent_conversion_event_name'] ?? '');
    if (!$formName) { $skipped++; continue; } // genuine iBot, skip

    $ir = $row['initial_request'] ?? '';

    // Replace header line
    $ir = preg_replace('/^--- HubSpot iBot ---/m', '--- HubSpot Form ---', $ir);

    // Inject "Form: <name>" after the header if not already present
    if (strpos($ir, 'Form:') === false) {
        $ir = preg_replace(
            '/(--- HubSpot Form ---\n)/m',
            "$1Form: $formName\n",
            $ir
        );
    }

    $stmt = $pdo->prepare(
        "UPDATE lead_staging SET source='Form', initial_request=? WHERE id=?"
    );
    $stmt->execute([$ir, $row['id']]);

    echo "Fixed ID {$row['id']} — form: " . htmlspecialchars($formName) . "<br>\n";
    $fixed++;
}

echo "<br><strong>Done. Fixed: $fixed, Skipped: $skipped</strong>\n";
