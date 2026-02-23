<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// ── APP_INSTALLED gate ──────────────────────────────────
// Redirect to /setup.php before Laravel boots if not installed.
// Reads .env directly (lightweight, no Composer autoload needed).
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $envRaw = file_get_contents($envFile);
    if (preg_match('/^APP_INSTALLED\s*=\s*false$/mi', $envRaw)) {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH);
        // Allow API routes and setup.php itself
        if ($path !== '/setup.php' && !str_starts_with($path, '/api/') && $path !== '/api') {
            header('Location: /setup.php', true, 302);
            exit;
        }
    }
}


// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require __DIR__.'/../vendor/autoload.php';

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

$app->handleRequest(Request::capture());
