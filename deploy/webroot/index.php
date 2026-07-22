<?php

/**
 * Front controller for a SINGLE-FOLDER deploy — where the upload target IS the
 * public web root (e.g. a shared host's `public_html/`, which cannot be pointed
 * at a `public/` subfolder).
 *
 * The whole application (vendor, site, content, storage, bin, .env) is uploaded
 * INTO this directory and is kept off the web by the accompanying `.htaccess`,
 * which hard-denies those paths and routes everything else here.
 *
 * The canonical developer / dedicated-server layout is still the `public/`
 * docroot with everything above it — see `public/index.php`. This file is used
 * only by the FTP deploy build (`.github/workflows/deploy.yml`), which copies it
 * to the web root as `index.php` alongside a matching `.htaccess`.
 */

use Kirby\Cms\App as Kirby;

$base = __DIR__;

require $base . '/vendor/autoload.php';

echo (new Kirby([
    'roots' => [
        'index'    => $base,
        'base'     => $base,
        'site'     => $base . '/site',
        'content'  => $base . '/content',
        'storage'  => $base . '/storage',
        'accounts' => $base . '/storage/accounts',
        'sessions' => $base . '/storage/sessions',
        'cache'    => $base . '/storage/cache',
        'logs'     => $base . '/storage/logs',
        'media'    => $base . '/media',
        'assets'   => $base . '/assets',
    ],
]))->render();
