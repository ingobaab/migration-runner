<?php

/**
 * Minimal OAuth Callback Receiver for WordPress Application Password Flow
 *
 * Start via:
 * php -S localhost:8787 oauth-server.php
 * http://localhost:8787/callback?site_url=https://example.com&user_login=admin&password=testpassword123
 * then it creates 'oauth-result.json'
 */

declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

/**
 * Only allow /callback
 */
if ($path !== '/migration-runner/server/oauth-server.php/callback') {
    http_response_code(404);
    print_r($path);
    echo "Invalid endpoint.";
    exit;
}

/**
 * Validate required parameters
 */
$required = ['site_url', 'user_login', 'password'];

foreach ($required as $param) {
    if (empty($_GET[$param])) {
        http_response_code(400);
        echo "Missing parameter: {$param}";
        exit;
    }
}

$siteUrl   = filter_var($_GET['site_url'], FILTER_SANITIZE_URL);
$userLogin = preg_replace('/[^a-zA-Z0-9_\-\.@]/', '', $_GET['user_login']);
$password  = trim($_GET['password']);

/**
 * Basic sanity checks
 */
if (!filter_var($siteUrl, FILTER_VALIDATE_URL)) {
    http_response_code(400);
    echo "Invalid site_url.";
    exit;
}

if (strlen($password) < 10) {
    http_response_code(400);
    echo "Invalid application password.";
    exit;
}

/**
 * Prevent overwriting existing result file
 */
$resultFile = __DIR__ . '/oauth-result.json';

if (file_exists($resultFile)) {
    unlink($resultFile);
}

/**
 * Store credentials
 */
file_put_contents(
    $resultFile,
    json_encode([
        'site_url'   => $siteUrl,
        'user_login' => $userLogin,
        'password'   => $password,
        'received_at'=> date('c')
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
);

http_response_code(200);

echo "<h2>Authorization successful.</h2>";
echo "<p>You may close this window.</p>";
echo "<script>setTimeout(() => window.close(), 1500);</script>";

exit;
