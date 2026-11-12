<?php
// deploy_hook.php — deploy over HTTPS instead of SSH (no git, no extra processes on the server).
// Called by DeployHub.bat on the office PC after `git push`:
//   POST https://hub.savannahexplorers.com/deploy_hook.php
//   header X-Deploy-Token: <DEPLOY_HOOK_TOKEN from includes/config.php>
//   body   sha=<40-char commit>   (optional: default = head of main on GitHub)
//          dry=1                  (optional: list what would change, write nothing)
// Plain-text answer; the last line is "DEPLOY OK <sha>" on success. Logic: includes/deploy_service.php.

ob_start();
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/deploy_service.php';

function deploy_hook_exit(int $code, string $text): void
{
    ob_end_clean();
    http_response_code($code);
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: no-store');
    echo $text;
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') deploy_hook_exit(405, "POST only\n");
$expected = defined('DEPLOY_HOOK_TOKEN') ? (string)DEPLOY_HOOK_TOKEN : '';
if (strlen($expected) < 32) deploy_hook_exit(500, "Deploy token not configured\n");
$given = (string)($_SERVER['HTTP_X_DEPLOY_TOKEN'] ?? '');
if (!hash_equals($expected, $given)) deploy_hook_exit(403, "Forbidden\n");

try {
    $r = deploy_run(['sha' => $_POST['sha'] ?? '', 'dry' => !empty($_POST['dry'])]);
} catch (Throwable $e) {
    deploy_hook_exit(500, 'DEPLOY FAILED: ' . $e->getMessage() . "\n");
}

$out  = ($r['dry'] ? "Dry run — nothing written.\n" : '');
$out .= 'Commit ' . substr($r['sha'], 0, 7) . ($r['previous'] ? ' (before: ' . substr($r['previous'], 0, 7) . ')' : '') . "\n";
$out .= count($r['written']) . ' file(s) ' . ($r['dry'] ? 'would change' : 'updated') . ", {$r['unchanged']} unchanged\n";
foreach ($r['written'] as $f) $out .= "  + $f\n";
if ($r['removed_in_repo']) {
    $out .= "Removed from the repo but still on the server (delete by hand if no longer needed):\n";
    foreach ($r['removed_in_repo'] as $f) $out .= "  - $f\n";
}
if ($r['skipped']) $out .= 'Skipped (protected): ' . implode(', ', $r['skipped']) . "\n";
$out .= ($r['dry'] ? 'DRY RUN OK ' : 'DEPLOY OK ') . $r['sha'] . "\n";
deploy_hook_exit(200, $out);
