<?php
/**
 * dropbox_reauth.php  — ONE-TIME USE, DELETE AFTER USE
 * Generates a new Dropbox refresh token with sharing.write scope.
 * Place in hub root, run once, then DELETE this file.
 */

// ── Paste your App Key and App Secret here ────────────────────────────────────
define('APP_KEY',    'YOUR_APP_KEY');
define('APP_SECRET', 'YOUR_APP_SECRET');
// ─────────────────────────────────────────────────────────────────────────────

$redirect_uri = 'https://' . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF'];

// Step 2: exchange code for token
if (!empty($_GET['code'])) {
    $ch = curl_init('https://api.dropbox.com/oauth2/token');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'code'          => $_GET['code'],
            'grant_type'    => 'authorization_code',
            'client_id'     => APP_KEY,
            'client_secret' => APP_SECRET,
            'redirect_uri'  => $redirect_uri,
        ]),
    ]);
    $res = json_decode(curl_exec($ch), true);
    curl_close($ch);

    if (!empty($res['refresh_token'])) {
        echo "<h2>✅ Success!</h2>";
        echo "<p>New <strong>refresh_token</strong> with sharing.write scope:</p>";
        echo "<pre style='background:#f0f0f0;padding:12px;font-size:14px'>" . htmlspecialchars($res['refresh_token']) . "</pre>";
        echo "<p>Copy this value into <code>includes/config.php</code> as <code>DROPBOX_REFRESH_TOKEN</code>.<br>Then <strong>delete this file</strong>.</p>";
    } else {
        echo "<h2>❌ Error</h2><pre>" . htmlspecialchars(json_encode($res, JSON_PRETTY_PRINT)) . "</pre>";
    }
    exit;
}

// Step 1: redirect to Dropbox OAuth
$auth_url = 'https://www.dropbox.com/oauth2/authorize?' . http_build_query([
    'client_id'             => APP_KEY,
    'response_type'         => 'code',
    'redirect_uri'          => $redirect_uri,
    'token_access_type'     => 'offline',   // needed for refresh_token
    'scope'                 => 'files.metadata.write files.metadata.read files.content.write files.content.read sharing.write sharing.read',
]);

header('Location: ' . $auth_url);
exit;
