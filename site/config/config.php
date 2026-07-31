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

// The standalone Breakfast Admin application (a custom Vue SPA) is served at
// `/breakfast-admin`. The Kirby Panel is NOT that application — it is demoted to
// an undisclosed super-admin/emergency console at its own private slug so it can
// never collide with, or be mistaken for, the real admin. Its location is not
// advertised anywhere in the product. Normalised to lowercase [a-z0-9-].
$panelSlug = strtolower(trim(Env::get('BREAKFAST_PANEL_SLUG', 'studio-console')));
$panelSlug = (string) preg_replace('/[^a-z0-9-]/', '', $panelSlug);
if ($panelSlug === '' || $panelSlug === 'panel' || $panelSlug === $adminSlug) {
    $panelSlug = 'studio-console';
}

return [
    // Debug is OFF in production. Never leak stack traces or paths publicly.
    'debug' => $debug,

    'url' => Env::get('APP_URL', null),

    // Panel — relocated to the private admin slug and rebranded (see the
    // `breakfast.adminSlug` value and the `panel`/`panel/*` guard route in the
    // platform plugin, which makes the old `/panel` location return 404).
    'panel' => [
        'slug'    => $panelSlug,
        // Panel self-signup installer. Fail-closed: OFF in production by default
        // (keyed off APP_ENV, not a hostname-loaded override), ON only in
        // development so the first admin can be created without config editing.
        // It is additionally self-securing — Kirby only renders it while there
        // are ZERO users. Set PANEL_INSTALL=true to force it on for a one-off
        // production bootstrap, then unset it.
        'install' => Env::bool('PANEL_INSTALL', $isProduction === false),
        // No branded menu / home / CSS: the day-to-day admin is the standalone
        // Breakfast Admin app at /breakfast-admin. The Kirby Panel here is the
        // vanilla, undisclosed super-admin console only.
    ],

    // Secure session cookies. HTTPS-only in production.
    'cookie' => [
        'samesite' => 'Lax',
        // Mark cookies Secure in production so they are never sent over plain
        // HTTP (the site is HTTPS-only there).
        'secure' => $isProduction,
    ],
    'auth' => [
        'methods' => ['password' => ['2fa' => false]],
    ],

    // Explicit admin/Panel session lifetimes (rather than relying on framework
    // defaults). A normal login expires 2h after creation and after 30 min of
    // inactivity; a "remember me" login lasts 14 days (no inactivity timeout).
    // All three are env-tunable so an operator can tighten them further.
    'session' => [
        'durationNormal' => Env::int('SESSION_DURATION', 7200),          // absolute lifetime of a normal session
        'timeout'        => Env::int('SESSION_TIMEOUT', 1800),           // inactivity timeout for a normal session
        'durationLong'   => Env::int('SESSION_DURATION_LONG', 1209600),  // "remember me" absolute lifetime
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
            // GA4 is enabled only in production and remains consent-gated by
            // the existing analytics/consent layer. Environment values can
            // still override these defaults for staging or local development.
            'provider'  => Env::get('ANALYTICS_PROVIDER', $isProduction ? 'ga4' : 'none'),
            'site'      => Env::get('ANALYTICS_SITE', $isProduction ? 'G-FSZWKZ1FZH' : ''),
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

        // ------------------------------------------------------------------
        // Case-study screenshots. Live capture shells out to a trusted headless
        // command (SCREENSHOT_CMD) that runs on the host's own infrastructure;
        // when it is unset, the no-network stub driver is used. The SSRF/host
        // allow-list and the public-use + privacy gates are always enforced by
        // Screenshots\ScreenshotService (see docs/case-studies.md).
        // ------------------------------------------------------------------
        'screenshots' => [
            'cmd'        => Env::get('SCREENSHOT_CMD', ''),
            'timeout'    => Env::int('SCREENSHOT_TIMEOUT', 30),
            'maxBytes'   => Env::int('SCREENSHOT_MAX_BYTES', 8 * 1024 * 1024),
            // Extra always-allowed capture hosts (comma-separated), in addition
            // to the hosts a project explicitly lists for itself.
            'allowHosts' => array_values(array_filter(array_map('trim', explode(',', Env::get('SCREENSHOT_ALLOW_HOSTS', ''))))),
            // https-only by default; enable http capture only for internal test use.
            'allowHttp'  => Env::bool('SCREENSHOT_ALLOW_HTTP', false),
        ],
    ],
];
