<?php
/**
 * api/jeep_demand.php — JSON for Operations → Jeep demand (jeep_demand.php).
 *   GET  ?action=data[&prov=0|1][&guide_year=YYYY]   everything the page shows (jeep_page_data())
 *   GET  ?action=day&date=YYYY-MM-DD[&prov=0|1]      the safaris on the road that day
 *   GET  ?action=csv&what=daily|folders[&prov=0|1]   CSV download
 *   POST ?action=scan {mode: incremental|full|continue}
 *        incremental = "Refresh now" (also re-checks every /001_Safari folder); full / continue = full rebuild (admin),
 *        the page calls continue until remaining = 0. Each call works ~25 s (resumable).
 * Viewing and "Refresh now": module operations. Full rebuild: admin. Logic: includes/jeep_service.php.
 */
ob_start();
require_once __DIR__ . '/../../leads/config.php';     // $pdo, auth, Dropbox keys (server-only)
require_once __DIR__ . '/../includes/jeep_service.php';

function jd_json(array $d, int $code = 200): void {
    if (ob_get_length()) ob_clean();
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}

if (!is_logged_in()) jd_json(['ok' => false, 'error' => 'Session expired — log in again'], 401);
if (!has_permission('operations')) jd_json(['ok' => false, 'error' => 'Needs the Operations module'], 403);
$db = $pdo;
hub_db_timezone($db);
$action = (string)($_GET['action'] ?? 'data');
$prov = !isset($_GET['prov']) || $_GET['prov'] !== '0';

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if ($action === 'day') {
            $date = (string)($_GET['date'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) jd_json(['ok' => false, 'error' => 'Invalid date'], 400);
            jd_json(['ok' => true, 'date' => $date, 'rows' => jeep_day_detail($db, $date, $prov)]);
        }
        if ($action === 'csv') {
            $what = ($_GET['what'] ?? '') === 'folders' ? 'folders' : 'daily';
            $csv = jeep_csv($db, $what, $prov);
            if (ob_get_length()) ob_clean();
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="jeep_' . $what . '_' . date('Ymd') . '.csv"');
            echo $csv;
            exit;
        }
        if ($action !== 'data') jd_json(['ok' => false, 'error' => 'Unknown action'], 400);
        $gy = (int)($_GET['guide_year'] ?? 0);
        jd_json(['ok' => true, 'is_admin' => is_admin()] + jeep_page_data($db, $prov, $gy >= 2020 && $gy <= 2100 ? $gy : null));
    }

    // POST: JSON body only (a cross-site form cannot send it).
    if (stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') === false) jd_json(['ok' => false, 'error' => 'JSON body expected'], 415);
    $in = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($in)) jd_json(['ok' => false, 'error' => 'Invalid JSON'], 400);
    if ($action !== 'scan') jd_json(['ok' => false, 'error' => 'Unknown action'], 400);
    $mode = (string)($in['mode'] ?? 'incremental');
    if (!in_array($mode, ['incremental', 'full', 'continue'], true)) jd_json(['ok' => false, 'error' => 'Invalid mode'], 400);
    if ($mode !== 'incremental' && !is_admin()) jd_json(['ok' => false, 'error' => 'Only an admin can rebuild all'], 403);
    ignore_user_abort(true);
    @set_time_limit(120);
    $res = jeep_scan_run($db, ['mode' => $mode, 'budget' => 25, 'now' => $mode === 'incremental']);
    jd_json(['ok' => true, 'summary' => jeep_run_summary($res)] + $res);
} catch (Throwable $e) {
    jd_json(['ok' => false, 'error' => $e->getMessage()], 500);
}
