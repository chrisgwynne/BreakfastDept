<?php

/**
 * Production configuration overrides.
 *
 * Loaded by Kirby when the server sets the appropriate host mapping (see
 * deployment docs) or when merged explicitly. Everything here hardens the
 * defaults for a live, HTTPS-only deployment.
 */

return [
    // Never expose debug information in production.
    'debug' => false,

    // Panel installer disabled once the first admin exists.
    'panel' => [
        'install' => false,
    ],

    // Secure, HTTPS-only cookies.
    'cookie' => [
        'samesite' => 'Lax',
    ],

    // Page cache on for anonymous public routes.
    'cache' => [
        'pages' => ['active' => true],
    ],
];
