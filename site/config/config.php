<?php

/**
 * Kirby configuration (base / development defaults).
 *
 * Environment-specific overrides live in config.<host>.php or, for production,
 * config.production.php. Secrets are read from the environment via the Env
 * loader — never hard-coded here.
 */

use Breakfast\Platform\Support\Env;

$base = dirname(__DIR__, 2);
Env::load($base);

$isProduction = Env::get('APP_ENV', 'development') === 'production';
$debug        = Env::bool('APP_DEBUG', $isProduction === false);

// The Kirby Panel is relocated to a private, configurable slug and rebranded as
// "Breakfast Admin". The slug is NOT a security control (auth/permissions/CSRF
// still apply) — it only removes the well-known `/panel` entry point. See
// docs/breakfast-admin.md. Normalised to lowercase [a-z0-9-].
$adminSlug = strtolower(trim(Env::get('BREAKFAST_ADMIN_SLUG', 'breakfast-admin')));
$adminSlug = (string) preg_replace('/[^a-z0-9-]/', '', $adminSlug);
if ($adminSlug === '' || $adminSlug === 'panel') {
    $adminSlug = 'breakfast-admin';
}

return [
    // Debug is OFF in production. Never leak stack traces or paths publicly.
    'debug' => $debug,

    'url' => Env::get('APP_URL', null),

    // Panel — relocated to the private admin slug and rebranded (see the
    // `breakfast.adminSlug` value and the `panel`/`panel/*` guard route in the
    // platform plugin, which makes the old `/panel` location return 404).
    'panel' => [
        'slug'    => $adminSlug,
        // The Panel self-signup installer (create the first admin from the web)
        // is on outside production. Set PANEL_INSTALL=true in the host .env to
        // enable it in production JUST long enough to create your account, then
        // set it back to false. It only ever appears while there are zero users.
        'install' => Env::bool('PANEL_INSTALL', $isProduction === false),
        'css'     => '/assets/css/panel.css',
        // Branded dashboard is the first screen after login.
        'home'    => 'dashboard',
        // Replace Kirby's default Panel menu entirely with the Breakfast Admin
        // navigation. Every entry is permission-gated server-side (see AdminMenu
        // and each area's guard); hiding an entry is never the access control.
        'menu'    => \Breakfast\Platform\Admin\AdminMenu::config(),
    ],

    // Secure session cookies. HTTPS-only in production.
    'cookie' => [
        'samesite' => 'Lax',
    ],
    'auth' => [
        'methods' => ['password' => ['2fa' => false]],
    ],

    // Content cache — safe for public pages; the Panel/CRM/API are never cached.
    'cache' => [
        'pages' => [
            'active' => $isProduction,
        ],
    ],

    // Responsive images.
    'thumbs' => [
        'quality'  => 82,
        'format'   => 'webp',
        'srcsets'  => [
            'default' => [400, 800, 1200, 1600],
            'card'    => [400, 600, 900],
        ],
    ],

    // Email defaults (transport/credentials from env in the platform config).
    'email' => [
        'transport' => [
            'type' => Env::get('MAIL_TRANSPORT', 'mail'),
        ],
    ],

    // Kirby licence from env.
    'license' => Env::get('KIRBY_LICENSE', null),

    // Home + error page slugs.
    'home'  => 'home',
    'error' => 'error',

    // Custom date handling.
    'date' => ['handler' => 'intl'],

    // ------------------------------------------------------------------
    // Breakfast platform configuration (read by the plugin at boot).
    // Only NON-SECRET toggles and resolved values belong in Kirby; actual
    // secrets stay in the environment and are read directly by the platform.
    // ------------------------------------------------------------------
    'breakfast' => [
        'env'         => Env::get('APP_ENV', 'development'),
        'production'  => $isProduction,
        'version'     => Env::get('APP_VERSION', 'dev'),
        'adminSlug'   => $adminSlug,
        'storageDir'  => $base . '/storage',
        'dbPath'      => $base . '/' . ltrim(Env::get('CRM_DB_PATH', 'storage/database/crm.sqlite'), '/'),
        'uploadsDir'  => $base . '/storage/uploads',
        'webhookSecret' => Env::get('WEBHOOK_SIGNING_SECRET', ''),
        'hermes' => [
            'enabled'      => Env::bool('HERMES_ENABLED', false),
            'replayWindow' => Env::int('HERMES_REPLAY_WINDOW', 300),
        ],
        'mail' => [
            // Provider selection: brevo | smtp | fake | null. Dev/test default to
            // fake so no real email is ever sent by accident.
            'provider'    => Env::get('MAIL_PROVIDER', $isProduction ? 'smtp' : 'fake'),
            'from'        => Env::get('MAIL_FROM', 'studio@example.com'),
            'fromName'    => Env::get('MAIL_FROM_NAME', 'Breakfast'),
            'senderEmail' => Env::get('BREVO_SENDER_EMAIL', Env::get('MAIL_FROM', 'studio@example.com')),
            'senderName'  => Env::get('BREVO_SENDER_NAME', Env::get('MAIL_FROM_NAME', 'Breakfast')),
            'enquiriesTo' => Env::get('MAIL_ENQUIRIES_TO', Env::get('MAIL_FROM', 'studio@example.com')),
            'smtp' => [
                'transport' => Env::get('MAIL_TRANSPORT', 'mail'),
                'host'      => Env::get('MAIL_HOST', 'localhost'),
                'port'      => Env::int('MAIL_PORT', 587),
                'security'  => Env::get('MAIL_SECURITY', 'tls'),
                'username'  => Env::get('MAIL_USERNAME', ''),
                'password'  => Env::get('MAIL_PASSWORD', ''),
            ],
            'brevo' => [
                'enabled'     => Env::bool('BREVO_ENABLED', false),
                'apiKey'      => Env::get('BREVO_API_KEY', ''),
                'baseUrl'     => Env::get('BREVO_API_BASE_URL', 'https://api.brevo.com/v3'),
                'trackOpens'  => Env::bool('BREVO_TRACK_OPENS', false),
                'trackClicks' => Env::bool('BREVO_TRACK_CLICKS', false),
            ],
            'webhook' => [
                'secret'        => Env::get('BREVO_WEBHOOK_SECRET', ''),
                'basicUser'     => Env::get('BREVO_WEBHOOK_BASIC_AUTH_USER', ''),
                'basicPassword' => Env::get('BREVO_WEBHOOK_BASIC_AUTH_PASSWORD', ''),
            ],
            'contactSync' => [
                'enabled'       => Env::bool('BREVO_CONTACT_SYNC_ENABLED', false),
                'marketingList' => Env::get('BREVO_MARKETING_LIST_ID', ''),
            ],
            'templateIds' => [
                'enquiry_internal' => Env::get('BREVO_INTERNAL_ENQUIRY_TEMPLATE_ID', ''),
                'enquiry_ack'      => Env::get('BREVO_ENQUIRY_ACK_TEMPLATE_ID', ''),
                'crm_reply'        => Env::get('BREVO_CRM_EMAIL_TEMPLATE_ID', ''),
                'task_reminder'    => Env::get('BREVO_TASK_REMINDER_TEMPLATE_ID', ''),
                'daily_digest'     => Env::get('BREVO_DAILY_DIGEST_TEMPLATE_ID', ''),
                'failed_job'       => Env::get('BREVO_FAILED_JOB_TEMPLATE_ID', ''),
            ],
        ],
        'uploads' => [
            'enabled'    => Env::bool('UPLOADS_ENABLED', false),
            'maxBytes'   => Env::int('UPLOADS_MAX_BYTES', 5_242_880),
            'scannerCmd' => Env::get('UPLOADS_SCANNER_CMD', ''),
        ],
        'analytics' => [
            'provider'  => Env::get('ANALYTICS_PROVIDER', 'none'),
            'site'      => Env::get('ANALYTICS_SITE', ''),
            'scriptUrl' => Env::get('ANALYTICS_SCRIPT_URL', ''),
        ],

        // ------------------------------------------------------------------
        // Data retention (days). 0 = keep forever (never pruned). Applied by
        // `bin/console retention:*` and the queue worker's housekeeping.
        // ------------------------------------------------------------------
        'retention' => [
            'activitiesDays'       => Env::int('RETENTION_ACTIVITIES_DAYS', 730),
            'previewAnalyticsDays' => Env::int('RETENTION_PREVIEW_ANALYTICS_DAYS', 180),
            'queueDays'            => Env::int('RETENTION_QUEUE_DAYS', 30),
            'webhookDays'          => Env::int('RETENTION_WEBHOOK_DAYS', 90),
            'emailEventsDays'      => Env::int('RETENTION_EMAIL_EVENTS_DAYS', 90),
            'auditDays'            => Env::int('RETENTION_AUDIT_DAYS', 365),
            'orphanGraceHours'     => Env::int('RETENTION_ORPHAN_GRACE_HOURS', 24),
        ],

        // ------------------------------------------------------------------
        // Client Previews — static mock-up hosting on a SEPARATE origin.
        // The preview origin MUST NOT share cookies with the admin/main site.
        // Limits are server caps; editors cannot raise them from the UI.
        // ------------------------------------------------------------------
        'previews' => [
            // Per-preview origin isolation (preferred). When set, each preview is
            // served on its own browser origin, {slug}.{wildcardBase}, so one
            // preview can never read another. Requires wildcard DNS + TLS for the
            // zone (see docs/client-preview-deployment.md).
            'wildcardBase' => Env::get('CLIENT_PREVIEW_WILDCARD_BASE', ''),
            'scheme'       => Env::get('CLIENT_PREVIEW_SCHEME', 'https'),
            // Single-host fallback origin (all previews share one origin). Ignored
            // when a wildcard base is set. In dev, falls back to a path prefix on
            // the main host (insufficient isolation — local only).
            'baseUrl'   => Env::get('CLIENT_PREVIEW_BASE_URL', ''),
            'host'      => Env::get('CLIENT_PREVIEW_HOST', ''),
            'devPrefix' => Env::get('CLIENT_PREVIEW_DEV_PREFIX', '/_preview'),
            // Storage root for validated preview bytes (OUTSIDE the docroot).
            'storageDir' => $base . '/storage/client-previews',
            'limits' => [
                'maxZipMb'       => Env::int('CLIENT_PREVIEW_MAX_ZIP_MB', 50),
                'maxExtractedMb' => Env::int('CLIENT_PREVIEW_MAX_EXTRACTED_MB', 250),
                'maxFileMb'      => Env::int('CLIENT_PREVIEW_MAX_FILE_MB', 25),
                'maxFiles'       => Env::int('CLIENT_PREVIEW_MAX_FILES', 3000),
                'maxDepth'       => Env::int('CLIENT_PREVIEW_MAX_DIRECTORY_DEPTH', 20),
                'maxVersions'    => Env::int('CLIENT_PREVIEW_MAX_VERSIONS', 10),
            ],
            'defaultExpiryDays'      => Env::int('CLIENT_PREVIEW_DEFAULT_EXPIRY_DAYS', 30),
            'analyticsRetentionDays' => Env::int('CLIENT_PREVIEW_ANALYTICS_RETENTION_DAYS', 180),
            'tempRetentionHours'     => Env::int('CLIENT_PREVIEW_TEMP_RETENTION_HOURS', 24),
            // SVG is active content. false (default) = reject unless sanitised;
            // previews are served from an isolated origin with restrictive
            // headers, so sanitised SVG is allowed. Video allow-list is opt-in.
            'allowVideo' => Env::bool('CLIENT_PREVIEW_ALLOW_VIDEO', false),
        ],
    ],
];
