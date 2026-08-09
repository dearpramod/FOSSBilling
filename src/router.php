<?php
/**
 * PHP built-in server router for FOSSBilling development
 * Usage: php -S localhost:9000 -t src/ src/router.php
 */

// Set environment — use APP_ENV env var if set, otherwise default to production
$appEnv = getenv('APP_ENV') ?: 'prod';
putenv('APP_ENV=' . $appEnv);
$_ENV['APP_ENV'] = $appEnv;
$_SERVER['APP_ENV'] = $appEnv;

$uri = parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Block direct access to runtime data (logs, cache, uploads) — mirrors the
// production rule: location ^~ /data/ { return 403; }
if (preg_match('#^/data(/|$)#', $uri)) {
    http_response_code(403);
    exit;
}

// Serve static files directly if they exist
if ($uri !== '/' && file_exists(__DIR__ . $uri) && !is_dir(__DIR__ . $uri)) {
    return false; // serve the file as-is
}

// Route everything else through index.php
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = __DIR__ . '/index.php';
require __DIR__ . '/index.php';
