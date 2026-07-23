<?php

declare(strict_types=1);

/**
 * Router for PHP's built-in web server (development + the Playwright test
 * server only — it is NOT part of the FTP deploy, which ships
 * deploy/webroot/index.php + an .htaccess instead).
 *
 * It mirrors the production .htaccess: serve a real file if one exists, and hand
 * everything else to Kirby's front controller. Without this, `php -S` resolves a
 * missing path like /breakfast-admin/api/v1/session to the nearest parent
 * directory index (public/breakfast-admin/index.html), which would shadow the
 * admin API with the SPA shell. Routing misses through index.php keeps the API,
 * the SPA deep links and the public pages all correct.
 */

$root = __DIR__;
$path = urldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));

// Serve an existing static file directly (assets, built SPA bundle, etc.).
if ($path !== '/' && $path !== '' && is_file($root . $path)) {
    return false;
}

// Everything else is a Kirby request. Normalise the server vars so Kirby derives
// the full request path (php -S otherwise reports the router script as SCRIPT_NAME).
$_SERVER['SCRIPT_NAME']     = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $root . '/index.php';
$_SERVER['PHP_SELF']        = '/index.php';

require $root . '/index.php';
