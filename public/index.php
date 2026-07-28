<?php

/**
 * Front controller.
 *
 * The web docroot is this `public/` directory. Everything sensitive — content,
 * site config, the flat-file data tree (`storage/data/`), sessions, cache, logs,
 * private uploads
 * and the Kirby/Composer vendor tree — lives ABOVE the docroot and is never
 * directly reachable over HTTP. Roots are declared explicitly so the layout is
 * unambiguous regardless of how the host resolves paths.
 */

use Kirby\Cms\App as Kirby;

$base = dirname(__DIR__);

require $base . '/vendor/autoload.php';

echo (new Kirby([
    'roots' => [
        'index'    => __DIR__,
        'base'     => $base,
        'site'     => $base . '/site',
        'content'  => $base . '/content',
        'storage'  => $base . '/storage',
        'accounts' => $base . '/storage/accounts',
        'sessions' => $base . '/storage/sessions',
        'cache'    => $base . '/storage/cache',
        'logs'     => $base . '/storage/logs',
        'media'    => __DIR__ . '/media',
        'assets'   => __DIR__ . '/assets',
    ],
]))->render();
