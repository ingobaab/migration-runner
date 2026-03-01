<?php

declare(strict_types=1);

/*
 * migration-runner: single-file procedural runner for FlyWP Migrator
 * PHP 8.3+
 *
 * Modes:
 * 1) CLI: start migration pull workflow
 * 2) HTTP callback: receive WordPress Application Password redirect
 */

/* =========================
 * Generic helpers
 * ========================= */

function log_line(string $level, string $message): void
{
    $line = '[' . date('c') . '] [' . $level . '] ' . $message;
    echo $line . PHP_EOL;
}

function fail(string $message, int $code = 1): never
{
    log_line('ERROR', $message);
    exit($code);
}

function safe_host_from_url(string $url): string
{
    $host = parse_url($url, PHP_URL_HOST);
    if (!is_string($host) || $host === '') {
        return 'unknown-site';
    }

    $host = strtolower($host);
    $host = preg_replace('/[^a-z0-9.-]/', '-', $host) ?: 'unknown-site';

    return trim($host, '-');
}

function ensure_dir(string $dir): void
{
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        fail('Could not create directory: ' . $dir);
    }
}

function write_json_file(string $path, array $data): void
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        fail('Could not encode JSON for: ' . $path);
    }

    if (file_put_contents($path, $json) === false) {
        fail('Could not write file: ' . $path);
    }
}

function parse_cli_options(array $argv): array
{
    $options = getopt('', [
        'source:',
        'callback-url:',
        'export-dir::',
        'poll-seconds::',
        'max-wait-seconds::',
        'help::'
    ]);

    if (isset($options['help'])) {
        echo "Usage:\n";
        echo "  php runner.php --source=https://example.com --callback-url=https://your-runner.tld/runner.php [--export-dir=exports] [--poll-seconds=5] [--max-wait-seconds=3600]\n";
        exit(0);
    }

    if (!isset($options['source']) || !is_string($options['source'])) {
        fail('Missing --source');
    }

    if (!isset($options['callback-url']) || !is_string($options['callback-url'])) {
        fail('Missing --callback-url');
    }

    $source = rtrim($options['source'], '/');
    $callbackUrl = $options['callback-url'];

    if (!filter_var($source, FILTER_VALIDATE_URL)) {
        fail('Invalid --source URL');
    }

    if (!filter_var($callbackUrl, FILTER_VALIDATE_URL)) {
        fail('Invalid --callback-url URL');
    }

    $exportDir = isset($options['export-dir']) && is_string($options['export-dir']) && $options['export-dir'] !== ''
        ? rtrim($options['export-dir'], '/')
        : __DIR__ . '/exports';

    $pollSeconds = isset($options['poll-seconds']) ? (int)$options['poll-seconds'] : 5;
    $maxWaitSeconds = isset($options['max-wait-seconds']) ? (int)$options['max-wait-seconds'] : 3600;

    if ($pollSeconds < 1) {
        $pollSeconds = 1;
    }

    if ($maxWaitSeconds < 30) {
        $maxWaitSeconds = 30;
    }

    return [
        'source' => $source,
        'callback_url' => $callbackUrl,
        'export_dir' => $exportDir,
        'poll_seconds' => $pollSeconds,
        'max_wait_seconds' => $maxWaitSeconds,
    ];
}

/* =========================
 * HTTP callback mode
 * ========================= */

function handle_http_callback(): never
{
    $required = ['state', 'site_url', 'user_login', 'password'];
    foreach ($required as $key) {
        if (!isset($_GET[$key]) || !is_string($_GET[$key]) || trim($_GET[$key]) === '') {
            http_response_code(400);
            echo 'Missing parameter: ' . htmlspecialchars($key, ENT_QUOTES);
            exit;
        }
    }

    $state = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$_GET['state']) ?: '';
    $siteUrl = filter_var((string)$_GET['site_url'], FILTER_SANITIZE_URL);
    $userLogin = preg_replace('/[^a-zA-Z0-9_\-\.@]/', '', (string)$_GET['user_login']) ?: '';
    $password = trim((string)$_GET['password']);

    if ($state === '') {
        http_response_code(400);
        echo 'Invalid state';
        exit;
    }

    if (!filter_var($siteUrl, FILTER_VALIDATE_URL)) {
        http_response_code(400);
        echo 'Invalid site_url';
        exit;
    }

    if ($userLogin === '' || strlen($password) < 8) {
        http_response_code(400);
        echo 'Invalid credentials';
        exit;
    }

    $stateDir = __DIR__ . '/.runner_state';
    ensure_dir($stateDir);

    $resultPath = $stateDir . '/oauth-result-' . $state . '.json';

    write_json_file($resultPath, [
        'site_url' => $siteUrl,
        'user_login' => $userLogin,
        'password' => $password,
        'received_at' => date('c'),
    ]);

    http_response_code(200);
    echo '<h2>Authorization successful.</h2>';
    echo '<p>You may close this window.</p>';
    echo '<script>setTimeout(() => window.close(), 1200);</script>';
    exit;
}

/* =========================
 * HTTP client
 * ========================= */

function http_request(
    string $method,
    string $url,
    string $basicUser,
    string $basicPass,
    ?array $jsonBody = null,
    array $extraHeaders = [],
    int $timeout = 180
): array {
    $headers = [
        'Authorization: Basic ' . base64_encode($basicUser . ':' . $basicPass),
        'Accept: application/json',
    ];

    if ($jsonBody !== null) {
        $headers[] = 'Content-Type: application/json';
    }

    foreach ($extraHeaders as $header) {
        $headers[] = $header;
    }

    $ch = curl_init($url);
    if ($ch === false) {
        fail('Could not initialize cURL');
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
    ]);

    if ($jsonBody !== null) {
        $payload = json_encode($jsonBody);
        if ($payload === false) {
            fail('Could not encode request JSON for ' . $url);
        }
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    }

    $responseBody = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($responseBody === false) {
        fail('HTTP request failed: ' . $error . ' (' . $url . ')');
    }

    return [
        'status' => $httpCode,
        'body' => (string)$responseBody,
    ];
}

function http_download_to_file(
    string $url,
    string $targetPath,
    string $basicUser,
    string $basicPass,
    array $extraHeaders = [],
    int $timeout = 900
): void {
    $headers = [
        'Authorization: Basic ' . base64_encode($basicUser . ':' . $basicPass),
        'Accept: */*',
    ];

    foreach ($extraHeaders as $header) {
        $headers[] = $header;
    }

    $fp = fopen($targetPath, 'wb');
    if ($fp === false) {
        fail('Could not open file for writing: ' . $targetPath);
    }

    $ch = curl_init($url);
    if ($ch === false) {
        fclose($fp);
        fail('Could not initialize cURL for download');
    }

    curl_setopt_array($ch, [
        CURLOPT_FILE => $fp,
        CURLOPT_CUSTOMREQUEST => 'GET',
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
    ]);

    $ok = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);

    curl_close($ch);
    fclose($fp);

    if ($ok === false) {
        @unlink($targetPath);
        fail('Download failed: ' . $err . ' (' . $url . ')');
    }

    if ($httpCode >= 400) {
        @unlink($targetPath);
        fail('Download failed with HTTP ' . $httpCode . ' (' . $url . ')');
    }
}

function require_json_success(array $response, string $context): array
{
    if ($response['status'] >= 400) {
        fail($context . ' failed with HTTP ' . $response['status'] . ': ' . $response['body']);
    }

    $decoded = json_decode($response['body'], true);
    if (!is_array($decoded)) {
        fail($context . ' returned invalid JSON: ' . $response['body']);
    }

    return $decoded;
}

function request_with_retry(callable $fn, int $attempts = 3, int $sleepSeconds = 2): mixed
{
    $lastMessage = 'unknown error';

    for ($i = 1; $i <= $attempts; $i++) {
        try {
            return $fn();
        } catch (Throwable $e) {
            $lastMessage = $e->getMessage();
            if ($i < $attempts) {
                sleep($sleepSeconds);
            }
        }
    }

    fail('Retry exhausted: ' . $lastMessage);
}

/* =========================
 * Runner workflow
 * ========================= */

function install_or_update_plugin(string $base, string $user, string $pass): void
{
    $pluginPath = 'flywp-migrator/flywp-migrator';

    $isInstalled = true;
    $installedResp = http_request('GET', $base . '/wp-json/wp/v2/plugins/' . $pluginPath, $user, $pass);
    if ($installedResp['status'] >= 400) {
        $isInstalled = false;
    }

    if ($isInstalled) {
        log_line('INFO', 'Plugin installed, trying update');
        $resp = request_with_retry(function () use ($base, $user, $pass, $pluginPath) {
            return http_request('POST', $base . '/wp-json/wp/v2/plugins/' . $pluginPath, $user, $pass, ['update' => true]);
        });

        if ($resp['status'] >= 400) {
            fail('Plugin update failed: ' . $resp['body']);
        }
    } else {
        log_line('INFO', 'Plugin not installed, installing');
        $resp = request_with_retry(function () use ($base, $user, $pass) {
            return http_request('POST', $base . '/wp-json/wp/v2/plugins', $user, $pass, ['slug' => 'flywp-migrator']);
        });

        if ($resp['status'] >= 400) {
            fail('Plugin install failed: ' . $resp['body']);
        }
    }

    log_line('INFO', 'Activating plugin');
    $activateResp = request_with_retry(function () use ($base, $user, $pass, $pluginPath) {
        return http_request('POST', $base . '/wp-json/wp/v2/plugins/' . $pluginPath, $user, $pass, ['status' => 'active']);
    });

    if ($activateResp['status'] >= 400) {
        fail('Plugin activation failed: ' . $activateResp['body']);
    }
}

function fetch_info(string $base, string $user, string $pass): array
{
    $resp = http_request('GET', $base . '/wp-json/flywp-migrator/v1/info', $user, $pass);
    return require_json_success($resp, 'GET /info');
}

function verify_key(string $base, string $user, string $pass, string $key): array
{
    $resp = http_request('POST', $base . '/wp-json/flywp-migrator/v1/verify', $user, $pass, ['key' => $key]);
    return require_json_success($resp, 'POST /verify');
}

function create_db_dump_job(string $base, string $user, string $pass, string $key): array
{
    $resp = http_request(
        'POST',
        $base . '/wp-json/flywp-migrator/v1/database/dumps?secret=' . rawurlencode($key),
        $user,
        $pass,
        [],
        ['X-FlyWP-Key: ' . $key]
    );

    return require_json_success($resp, 'POST /database/dumps');
}

function get_db_dump_status(string $base, string $user, string $pass, string $key, string $jobId): array
{
    $resp = http_request(
        'GET',
        $base . '/wp-json/flywp-migrator/v1/database/dumps/' . rawurlencode($jobId) . '?secret=' . rawurlencode($key),
        $user,
        $pass,
        null,
        ['X-FlyWP-Key: ' . $key]
    );

    return require_json_success($resp, 'GET /database/dumps/{job_id}');
}

function get_db_dump_download_meta(string $base, string $user, string $pass, string $key, string $jobId): array
{
    $resp = http_request(
        'GET',
        $base . '/wp-json/flywp-migrator/v1/database/dumps/' . rawurlencode($jobId) . '/download?secret=' . rawurlencode($key),
        $user,
        $pass,
        null,
        ['X-FlyWP-Key: ' . $key]
    );

    return require_json_success($resp, 'GET /database/dumps/{job_id}/download');
}

function delete_db_dump_job(string $base, string $user, string $pass, string $key, string $jobId): void
{
    $resp = http_request(
        'DELETE',
        $base . '/wp-json/flywp-migrator/v1/database/dumps/' . rawurlencode($jobId) . '?secret=' . rawurlencode($key),
        $user,
        $pass,
        null,
        ['X-FlyWP-Key: ' . $key]
    );

    if ($resp['status'] >= 400) {
        log_line('ERROR', 'DB job cleanup failed: HTTP ' . $resp['status'] . ' ' . $resp['body']);
    }
}

function download_binary_endpoint(
    string $base,
    string $endpointWithQuery,
    string $targetPath,
    string $user,
    string $pass,
    string $key
): void {
    $url = $base . $endpointWithQuery;
    http_download_to_file($url, $targetPath, $user, $pass, ['X-FlyWP-Key: ' . $key]);
}

function run_cli_workflow(array $cfg): void
{
    $source = $cfg['source'];
    $callbackUrl = $cfg['callback_url'];
    $pollSeconds = $cfg['poll_seconds'];
    $maxWaitSeconds = $cfg['max_wait_seconds'];

    $host = safe_host_from_url($source);
    $domainDir = rtrim($cfg['export_dir'], '/') . '/' . $host;
    $uploadsDir = $domainDir . '/uploads';
    $stateDir = __DIR__ . '/.runner_state';

    ensure_dir($domainDir);
    ensure_dir($uploadsDir);
    ensure_dir($stateDir);

    $state = bin2hex(random_bytes(16));
    $oauthResultPath = $stateDir . '/oauth-result-' . $state . '.json';

    $authorizeUrl = $source
        . '/wp-admin/authorize-application.php?app_name=' . rawurlencode('FlyWP Migration Runner')
        . '&success_url=' . rawurlencode($callbackUrl . '?state=' . $state);

    log_line('INFO', 'Open this URL in your browser and authorize:');
    echo $authorizeUrl . PHP_EOL;

    log_line('INFO', 'Waiting for callback credentials...');
    $started = time();

    while (!file_exists($oauthResultPath)) {
        sleep(1);
        if (time() - $started > $maxWaitSeconds) {
            fail('Timeout waiting for OAuth callback');
        }
    }

    $credsRaw = file_get_contents($oauthResultPath);
    if ($credsRaw === false) {
        fail('Could not read OAuth result file');
    }
    @unlink($oauthResultPath);

    $creds = json_decode($credsRaw, true);
    if (!is_array($creds)) {
        fail('Invalid OAuth result JSON');
    }

    foreach (['site_url', 'user_login', 'password'] as $k) {
        if (!isset($creds[$k]) || !is_string($creds[$k]) || trim($creds[$k]) === '') {
            fail('Missing credential key: ' . $k);
        }
    }

    $siteUrl = rtrim($creds['site_url'], '/');
    $userLogin = $creds['user_login'];
    $appPassword = $creds['password'];

    write_json_file($domainDir . '/credentials.json', [
        'site_url' => $siteUrl,
        'user_login' => $userLogin,
        'password' => $appPassword,
        'saved_at' => date('c'),
    ]);

    log_line('INFO', 'Installing or updating plugin');
    install_or_update_plugin($siteUrl, $userLogin, $appPassword);

    log_line('INFO', 'Fetching /info');
    $info = fetch_info($siteUrl, $userLogin, $appPassword);
    write_json_file($domainDir . '/info.json', $info);

    if (!isset($info['key']) || !is_string($info['key']) || $info['key'] === '') {
        fail('No migration key returned by /info');
    }
    $migrationKey = $info['key'];

    log_line('INFO', 'Verifying key with /verify');
    $verify = verify_key($siteUrl, $userLogin, $appPassword, $migrationKey);
    write_json_file($domainDir . '/verify.json', $verify);

    log_line('INFO', 'Starting database dump job');
    $job = create_db_dump_job($siteUrl, $userLogin, $appPassword, $migrationKey);
    if (!isset($job['job_id']) || !is_string($job['job_id']) || $job['job_id'] === '') {
        fail('database/dumps did not return a valid job_id');
    }
    $jobId = $job['job_id'];

    $dbStatusPath = $domainDir . '/db-status.json';
    $dbDumpPath = $domainDir . '/db-dump.sql';

    $dbComplete = false;
    $waitStart = time();

    while (!$dbComplete) {
        $status = get_db_dump_status($siteUrl, $userLogin, $appPassword, $migrationKey, $jobId);
        write_json_file($dbStatusPath, $status);

        $stateText = isset($status['status']) && is_string($status['status']) ? $status['status'] : 'unknown';
        log_line('INFO', 'DB dump status: ' . $stateText);

        if ($stateText === 'complete') {
            $dbComplete = true;
            break;
        }

        if ($stateText === 'failed') {
            fail('Database dump failed: ' . json_encode($status));
        }

        if (time() - $waitStart > $maxWaitSeconds) {
            fail('Timeout waiting for database dump completion');
        }

        sleep($pollSeconds);
    }

    $meta = get_db_dump_download_meta($siteUrl, $userLogin, $appPassword, $migrationKey, $jobId);
    write_json_file($domainDir . '/db-download-meta.json', $meta);

    $downloadUrl = '';
    if (isset($meta['download']) && is_string($meta['download']) && $meta['download'] !== '') {
        $downloadUrl = $meta['download'];
    }

    if ($downloadUrl === '') {
        fail('No download URL returned for DB dump');
    }

    log_line('INFO', 'Downloading database dump');
    http_download_to_file($downloadUrl, $dbDumpPath, $userLogin, $appPassword, ['X-FlyWP-Key: ' . $migrationKey], 1800);

    log_line('INFO', 'Fetching uploads manifest');
    $manifestResp = http_request(
        'GET',
        $siteUrl . '/wp-json/flywp-migrator/v1/uploads/manifest?secret=' . rawurlencode($migrationKey),
        $userLogin,
        $appPassword,
        null,
        ['X-FlyWP-Key: ' . $migrationKey]
    );
    $manifest = require_json_success($manifestResp, 'GET /uploads/manifest');
    write_json_file($domainDir . '/uploads-manifest.json', $manifest);

    $totalChunks = isset($manifest['total_chunks']) ? (int)$manifest['total_chunks'] : 0;
    if ($totalChunks < 0) {
        $totalChunks = 0;
    }

    for ($i = 0; $i < $totalChunks; $i++) {
        $target = $uploadsDir . '/uploads_chunk_' . str_pad((string)$i, 5, '0', STR_PAD_LEFT) . '.zip';
        log_line('INFO', 'Downloading uploads chunk ' . ($i + 1) . '/' . $totalChunks);
        download_binary_endpoint(
            $siteUrl,
            '/wp-json/flywp-migrator/v1/uploads/download?chunk=' . $i . '&secret=' . rawurlencode($migrationKey),
            $target,
            $userLogin,
            $appPassword,
            $migrationKey
        );
    }

    log_line('INFO', 'Downloading plugins.zip');
    download_binary_endpoint(
        $siteUrl,
        '/wp-json/flywp-migrator/v1/plugins/download?secret=' . rawurlencode($migrationKey),
        $domainDir . '/plugins.zip',
        $userLogin,
        $appPassword,
        $migrationKey
    );

    log_line('INFO', 'Downloading themes.zip');
    download_binary_endpoint(
        $siteUrl,
        '/wp-json/flywp-migrator/v1/themes/download?secret=' . rawurlencode($migrationKey),
        $domainDir . '/themes.zip',
        $userLogin,
        $appPassword,
        $migrationKey
    );

    log_line('INFO', 'Downloading mu-plugins.zip');
    download_binary_endpoint(
        $siteUrl,
        '/wp-json/flywp-migrator/v1/mu-plugins/download?secret=' . rawurlencode($migrationKey),
        $domainDir . '/mu-plugins.zip',
        $userLogin,
        $appPassword,
        $migrationKey
    );

    log_line('INFO', 'Cleaning up DB dump job');
    delete_db_dump_job($siteUrl, $userLogin, $appPassword, $migrationKey, $jobId);

    write_json_file($domainDir . '/run-summary.json', [
        'source' => $source,
        'site_url' => $siteUrl,
        'host_directory' => $domainDir,
        'job_id' => $jobId,
        'uploads_chunks' => $totalChunks,
        'completed_at' => date('c'),
    ]);

    log_line('INFO', 'Migration pull completed: ' . $domainDir);
}

/* =========================
 * Entrypoint
 * ========================= */

if (PHP_SAPI !== 'cli') {
    handle_http_callback();
}

$config = parse_cli_options($argv);
run_cli_workflow($config);
