<?php

declare(strict_types=1);

use Breakfast\Platform\Admin\AdminApi;
use Breakfast\Platform\Hermes\Api as HermesApi;
use Breakfast\Platform\Hermes\AuditLog;
use Breakfast\Platform\Hermes\Authenticator;
use Breakfast\Platform\Hermes\CredentialStore;
use Breakfast\Platform\Hermes\DraftFactory;
use Breakfast\Platform\Seo\Meta;
use Breakfast\Platform\Support\Platform;
use Breakfast\Platform\Support\ReadingTime;
use Kirby\Cms\App as Kirby;

// PSR-4 classes are autoloaded via the project's Composer autoloader (loaded in
// public/index.php). This require is a safety net for standalone plugin use.
if (class_exists(Platform::class) === false && is_file(__DIR__ . '/vendor/autoload.php')) {
    require __DIR__ . '/vendor/autoload.php';
}

/**
 * Boot the platform service container once, from the resolved (non-secret)
 * Kirby options plus environment secrets.
 */
function breakfast(): Platform
{
    if (Platform::booted() === false) {
        $kirby  = kirby();
        $config = $kirby->option('breakfast', []);
        Platform::boot($kirby->root('base'), is_array($config) ? $config : []);
    }

    return Platform::instance();
}

/**
 * Serve a built Breakfast Admin static asset with a correct content type.
 *
 * Only used as a fallback for servers that funnel every request through
 * index.php (e.g. the PHP built-in dev server). In production the web server
 * serves these files directly and this never runs. The caller has already
 * verified the file exists and the path contains no traversal.
 */
function breakfast_admin_asset_response(string $file): \Kirby\Http\Response
{
    $types = [
        'js'    => 'text/javascript; charset=utf-8',
        'mjs'   => 'text/javascript; charset=utf-8',
        'css'   => 'text/css; charset=utf-8',
        'html'  => 'text/html; charset=utf-8',
        'json'  => 'application/json',
        'svg'   => 'image/svg+xml',
        'png'   => 'image/png',
        'jpg'   => 'image/jpeg',
        'jpeg'  => 'image/jpeg',
        'webp'  => 'image/webp',
        'ico'   => 'image/x-icon',
        'woff2' => 'font/woff2',
        'woff'  => 'font/woff',
        'map'   => 'application/json',
        'txt'   => 'text/plain; charset=utf-8',
    ];
    $ext  = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $mime = $types[$ext] ?? 'application/octet-stream';

    return new \Kirby\Http\Response(file_get_contents($file) ?: '', $mime);
}

// Register the granular Client Previews permission category. A Kirby plugin's
// own `permissions` key can only register the single category named after the
// plugin (breakfast.platform), so the second category is registered directly on
// the shared static. Every action defaults to denied; roles opt in explicitly in
// their blueprint, and Security\PreviewGate re-checks on the server.
\Kirby\Cms\Permissions::$extendedActions[\Breakfast\Platform\Security\PreviewGate::CATEGORY] = array_fill_keys(
    \Breakfast\Platform\Security\PreviewGate::ACTIONS,
    false
);

/**
 * Router entry for the always-registered preview catch-all. Decides PER REQUEST
 * (never at plugin-load time, which is frozen per worker process) whether this
 * request belongs to a preview origin; if not, it hands control back to the main
 * site via Route::next(). Supports:
 *   - subdomain mode: slug from the HOST ({slug}.{wildcardBase}) — own origin;
 *   - single-host mode: slug from the first path segment on CLIENT_PREVIEW_HOST;
 *   - dev prefix (non-production only): /{devPrefix}/{slug}/... on the main host.
 *
 * @param string $all the request path
 */
function breakfast_preview_dispatch(string $all): \Kirby\Http\Response
{
    $platform = breakfast();
    $urls     = $platform->previewUrls();
    $all      = ltrim($all, '/');
    $host     = (string) preg_replace('/:\d+$/', '', strtolower((string) ($_SERVER['HTTP_HOST'] ?? '')));

    // 1. Subdomain (per-preview origin) mode.
    if ($urls->subdomainMode()) {
        if (\Breakfast\Platform\ClientPreviews\PreviewHost::isPreviewZone($host, $urls->wildcardBase())) {
            return breakfast_preview_dispatch_subdomain($all);
        }
        \Kirby\Http\Route::next(); // not on the preview zone — hand back to main site
    }

    // 2. Single shared preview host.
    $singleHost = strtolower($urls->host());
    if ($urls->subdomainMode() === false && $singleHost !== '' && $host === $singleHost) {
        return breakfast_preview_dispatch_path($all);
    }

    // 3. Development path prefix on the main host (never in production, never when
    //    a real preview origin is configured).
    if ($platform->isProduction() === false && $urls->hasIsolatedOrigin() === false) {
        $prefix = trim((string) ($platform->previewConfig()['devPrefix'] ?? '_preview'), '/');
        if ($prefix !== '' && ($all === $prefix || str_starts_with($all, $prefix . '/'))) {
            return breakfast_preview_dispatch_path(ltrim(substr($all, strlen($prefix)), '/'));
        }
    }

    \Kirby\Http\Route::next(); // fall through to the main site + other routes
    throw new \Kirby\Http\Exceptions\NextRouteException('next'); // @codeCoverageIgnore
}

/**
 * Subdomain mode: resolve the slug from the host, apply canonical redirects, serve.
 */
function breakfast_preview_dispatch_subdomain(string $all): \Kirby\Http\Response
{
    $platform  = breakfast();
    $urls      = $platform->previewUrls();
    $responder = $platform->previewResponder();
    $host      = (string) preg_replace('/:\d+$/', '', strtolower((string) ($_SERVER['HTTP_HOST'] ?? '')));

    if ($all === 'robots.txt') {
        return breakfast_preview_to_response($responder->robots());
    }

    $slug = \Breakfast\Platform\ClientPreviews\PreviewHost::slugFromHost($host, $urls->wildcardBase());
    if ($slug === null) {
        // Apex host or an invalid/multi-level subdomain — never serve a preview.
        return breakfast_preview_to_response($responder->serve('__no_such_preview__', '', []));
    }

    // Canonical redirect: an old subdomain (a preview's previous_slug) 301s to the
    // current one, preserving the path.
    if ($platform->previews()->findBySlug($slug) === null) {
        $moved = $platform->previews()->findByPreviousSlug($slug);
        if ($moved !== null && (string) $moved['status'] === \Breakfast\Platform\ClientPreviews\PreviewStatus::ACTIVE) {
            return \Kirby\Http\Response::redirect(rtrim($urls->url((string) $moved['public_slug']), '/') . '/' . $all, 301);
        }
    }

    return breakfast_preview_serve($slug, $all);
}

/**
 * Path mode (single shared host / dev prefix): slug is the first path segment.
 */
function breakfast_preview_dispatch_path(string $all): \Kirby\Http\Response
{
    $responder = breakfast()->previewResponder();
    $all       = ltrim($all, '/');
    $segments  = $all === '' ? [] : explode('/', $all);
    $slug      = strtolower($segments[0] ?? '');
    $path      = implode('/', array_slice($segments, 1));

    if ($slug === 'robots.txt' || $all === 'robots.txt') {
        return breakfast_preview_to_response($responder->robots());
    }
    if ($slug === '') {
        return breakfast_preview_to_response($responder->serve('', '', []));
    }

    return breakfast_preview_serve($slug, $path);
}

/**
 * Shared serve/unlock handling for a resolved slug + file path (mode-agnostic).
 */
function breakfast_preview_serve(string $slug, string $path): \Kirby\Http\Response
{
    $platform  = breakfast();
    $responder = $platform->previewResponder();
    $request   = kirby()->request();
    $ip        = kirby()->visitor()->ip();
    $ua        = kirby()->visitor()->userAgent();
    $referer   = (string) ($_SERVER['HTTP_REFERER'] ?? '');
    $refHost   = $referer !== '' ? (string) (parse_url($referer, PHP_URL_HOST) ?: '') : null;

    // Password unlock (POST with the password field).
    if ($request->method() === 'POST' && $request->body()->get(\Breakfast\Platform\ClientPreviews\PreviewResponder::UNLOCK_FIELD) !== null) {
        $password = (string) $request->body()->get(\Breakfast\Platform\ClientPreviews\PreviewResponder::UNLOCK_FIELD);

        return breakfast_preview_to_response($responder->unlock($slug, $password, $ip));
    }

    $cookieName = \Breakfast\Platform\ClientPreviews\PreviewResponder::cookieName($slug);
    $token      = $_COOKIE[$cookieName] ?? null;

    return breakfast_preview_to_response($responder->serve($slug, $path, [
        'token'        => is_string($token) ? $token : null,
        'ip'           => $ip,
        'ua'           => $ua,
        'referer_host' => $refHost,
    ]));
}

/**
 * @param array{status:int,headers:array<string,string>,body:string,file?:string,cookie?:array{name:string,value:string,maxage:int,clear?:bool}} $res
 */
function breakfast_preview_to_response(array $res): \Kirby\Http\Response
{
    $headers = $res['headers'];
    // Kirby always appends "; charset=UTF-8" to the response type, so pass the
    // bare MIME (strip any charset we carried) to avoid a duplicated parameter.
    $type = (string) preg_replace('/;\s*charset=[^;]*/i', '', $headers['Content-Type'] ?? 'text/html');
    unset($headers['Content-Type']);

    // Set a single host-only password-session cookie when requested. Host-only =
    // no Domain attribute, so it can never be sent to the admin/main site.
    if (isset($res['cookie'])) {
        $c       = $res['cookie'];
        $secure  = breakfast()->isProduction() ? ' Secure;' : '';
        $maxage  = (int) $c['maxage'];
        $headers['Set-Cookie'] = $c['name'] . '=' . rawurlencode((string) $c['value'])
            . '; Path=/; Max-Age=' . $maxage . '; HttpOnly;' . $secure . ' SameSite=Lax';
    }

    // For a validated file, stream via Response::file so byte-range requests are
    // honoured (video seeking) and Accept-Ranges is advertised, while keeping our
    // allow-listed MIME type and the full preview security-header set.
    $file = (string) ($res['file'] ?? '');
    if ($file !== '' && is_file($file)) {
        return \Kirby\Http\Response::file($file, [
            'type'    => $type,
            'headers' => $headers,
        ]);
    }

    return new \Kirby\Http\Response($res['body'], $type, $res['status'], $headers);
}

// Register the preview-origin catch-all ONLY when the current request is on the
// configured preview host (so it never shadows the main site).
//
Kirby::plugin('breakfast/platform', [
    'options' => [
        'cache' => true,
    ],

    // Custom permission category (registered under the plugin prefix
    // "breakfast.platform"). Defaults to denied; roles opt in explicitly.
    // Admins always bypass via PanelGate.
    'permissions' => [
        'access' => false,
        'manage' => false,
        'export' => false,
    ],

    // ------------------------------------------------------------------
    // Front-end routes: Hermes API, sitemap, robots, RSS, secure downloads.
    // ------------------------------------------------------------------
    'routes' => [
        // Client Previews. Registered FIRST and ALWAYS, but the action decides per
        // request (via the request Host) whether this belongs to a preview origin;
        // if not, it calls Route::next() to hand control straight back to the main
        // site and the other routes below. Deciding per request (not at plugin-load
        // time, which is cached per worker process) is what makes host-based
        // routing correct when one app serves both the main and preview origins.
        [
            // Two patterns so the root ('') is matched as well as any deeper path
            // — a bare (:all?) does not match the empty home path in Kirby.
            'pattern' => ['', '(:all)'],
            'method'  => 'GET|POST',
            'action'  => fn (?string $all = '') => breakfast_preview_dispatch((string) $all),
        ],

        // The Kirby Panel is relocated to a private slug (BREAKFAST_ADMIN_SLUG)
        // and rebranded "Breakfast Admin". The well-known `/panel` entry point is
        // retired: it and every descendant return the branded 404 page. This is a
        // discoverability measure only — authentication, permissions, CSRF and
        // server-side authorisation remain enforced on the real admin routes.
        [
            'pattern' => ['panel', 'panel/(:all)'],
            'action'  => function () {
                $error = kirby()->site()->errorPage();
                $html  = $error !== null ? $error->render() : 'Not found';

                return new \Kirby\Http\Response($html, 'text/html', 404);
            },
        ],

        // Brevo transactional webhook. Independent auth (shared secret / basic),
        // NOT Hermes HMAC and NOT Panel session. Registered before the Hermes
        // catch-all so it matches first, and works even when Hermes is disabled.
        [
            'pattern' => 'api/breakfast/v1/webhooks/brevo',
            'method'  => 'POST',
            'action'  => function () {
                $platform = breakfast();
                $request  = kirby()->request();

                $headers = [];
                foreach ($request->headers() as $key => $value) {
                    $headers[strtolower($key)] = $value;
                }

                $webhook = new \Breakfast\Platform\Mail\BrevoWebhook(
                    $platform->outbound(),
                    $platform->suppressions(),
                    $platform->activities(),
                    is_array($platform->mailConfig()['webhook'] ?? null) ? $platform->mailConfig()['webhook'] : []
                );

                $result = $webhook->handle(
                    file_get_contents('php://input') ?: '',
                    $headers,
                    $request->header('Content-Type')
                );

                return \Kirby\Http\Response::json($result['body'], $result['status']);
            },
        ],

        // Hermes integration API. Registered BEFORE Kirby's own /api so it is
        // matched first. Enforces its own HMAC auth + scopes; not Panel auth.
        [
            'pattern' => 'api/breakfast/v1/(:all?)',
            'method'  => 'GET|POST|PATCH|PUT|DELETE',
            'action'  => function (?string $path = '') {
                $kirby   = kirby();
                $platform = breakfast();

                if (($platform->config('hermes')['enabled'] ?? false) !== true) {
                    return \Kirby\Http\Response::json(['error' => 'hermes_disabled'], 503);
                }

                $store = new CredentialStore();
                $auth  = new Authenticator($store, $platform->db(), (int) ($platform->config('hermes')['replayWindow'] ?? 300));
                $api   = new HermesApi($platform, $auth, new AuditLog($platform->db()), new DraftFactory());

                $request = $kirby->request();
                $headers = [];
                foreach ($request->headers() as $key => $value) {
                    $headers[strtolower($key)] = $value;
                }

                $body   = file_get_contents('php://input') ?: '';
                $result = $api->handle(
                    $request->method(),
                    '/' . trim((string) $path, '/'),
                    $body,
                    $headers
                );

                return \Kirby\Http\Response::json($result['body'], $result['status']);
            },
        ],

        // Standalone Breakfast Admin API. Powers the custom admin SPA served at
        // /breakfast-admin. Its own session-based auth, CSRF and RBAC are enforced
        // inside AdminApi — never Panel auth, never trusting the client.
        //
        // Deliberately NOT under the top-level `/api/` slug: Kirby reserves that
        // namespace for its own Panel API router, which intercepts every `/api/*`
        // path and 404s anything it doesn't recognise. Nesting the admin API under
        // the app's own slug keeps it in the front-end router, and registering it
        // before the SPA shell route below means it always matches first.
        [
            'pattern' => 'breakfast-admin/api/v1/(:all?)',
            'method'  => 'GET|POST|PATCH|PUT|DELETE',
            'action'  => fn (?string $path = '') => (new AdminApi(kirby(), breakfast()))->handle((string) $path),
        ],

        // The standalone Breakfast Admin application shell. The Vue SPA is built to
        // public/breakfast-admin/ (base=/breakfast-admin/). Real built assets are
        // served directly by the web server; every other in-app path (deep links
        // like /breakfast-admin/leads) is a client-side route, so we return the
        // built index.html and let vue-router take over. Requests that look like a
        // missing static asset get a real 404 instead of the HTML shell.
        [
            'pattern' => ['breakfast-admin', 'breakfast-admin/(:all?)'],
            'method'  => 'GET',
            'action'  => function (?string $all = '') {
                $dir   = kirby()->root('index') . '/breakfast-admin';
                $rel   = trim((string) $all, '/');

                // Serve an existing built file (fallback for servers that route
                // every request through index.php, e.g. the PHP dev server).
                if ($rel !== '' && !str_contains($rel, '..')) {
                    $file = $dir . '/' . $rel;
                    if (is_file($file)) {
                        return breakfast_admin_asset_response($file);
                    }
                    // A path with a file extension that does not exist is a missing
                    // asset — never answer it with the HTML shell.
                    if (preg_match('/\.[a-z0-9]{2,5}$/i', $rel) === 1) {
                        return new \Kirby\Http\Response('Not found', 'text/plain', 404);
                    }
                }

                $index = $dir . '/index.html';
                if (is_file($index) === false) {
                    return new \Kirby\Http\Response(
                        'Breakfast Admin has not been built yet.',
                        'text/plain',
                        503
                    );
                }

                return new \Kirby\Http\Response(file_get_contents($index) ?: '', 'text/html');
            },
        ],

        // XML sitemap (excludes drafts / noindex pages).
        [
            'pattern' => 'sitemap.xml',
            'action'  => fn () => (new \Kirby\Http\Response(
                snippet('seo/sitemap', [], true),
                'application/xml'
            )),
        ],

        // robots.txt
        [
            'pattern' => 'robots.txt',
            'action'  => fn () => (new \Kirby\Http\Response(
                snippet('seo/robots', [], true),
                'text/plain'
            )),
        ],

        // Journal RSS feed.
        [
            'pattern' => 'journal/feed.rss',
            'action'  => function () {
                $journal = kirby()->page('journal');
                if ($journal === null) {
                    return \Kirby\Http\Response::json(['error' => 'not_found'], 404);
                }

                return new \Kirby\Http\Response(
                    snippet('seo/rss', ['parent' => $journal], true),
                    'application/rss+xml'
                );
            },
        ],

        // Secure admin-only download for uploaded enquiry files.
        [
            'pattern' => 'admin/download/upload/(:any)',
            'action'  => function (string $uuid) {
                $user = kirby()->user();
                if ($user === null || $user->role()->permissions()->for('breakfast.platform', 'access') !== true) {
                    return \Kirby\Http\Response::json(['error' => 'forbidden'], 403);
                }

                $platform = breakfast();
                $row = $platform->db()->one('SELECT * FROM uploads WHERE uuid = :u', ['u' => $uuid]);
                if ($row === null) {
                    return \Kirby\Http\Response::json(['error' => 'not_found'], 404);
                }

                $path = $platform->uploadsDir() . '/' . basename((string) $row['stored_name']);
                if (is_file($path) === false) {
                    return \Kirby\Http\Response::json(['error' => 'missing_file'], 404);
                }

                return \Kirby\Http\Response::download($path, (string) $row['original_name']);
            },
        ],
    ],

    // ------------------------------------------------------------------
    // Panel API routes for the CRM area (Panel-authenticated + permission
    // checked server-side; never trust the client to hide buttons).
    // ------------------------------------------------------------------
    'api' => [
        'routes' => array_merge(
            require __DIR__ . '/api/crm.php',
            require __DIR__ . '/api/admin.php',
            require __DIR__ . '/api/previews.php',
        ),
    ],

    // ------------------------------------------------------------------
    // Custom Breakfast Admin areas: branded dashboard, CRM, operations.
    // ------------------------------------------------------------------
    'areas' => [
        'dashboard' => require __DIR__ . '/areas/dashboard.php',
        'crm'       => require __DIR__ . '/areas/crm.php',
        'previews'  => require __DIR__ . '/areas/previews.php',
        'ops'       => require __DIR__ . '/areas/ops.php',
    ],

    // ------------------------------------------------------------------
    // Page methods used by templates.
    // ------------------------------------------------------------------
    'pageMethods' => [
        'seoMeta' => function () {
            /** @var \Kirby\Cms\Page $this */
            return new Meta($this, $this->site());
        },
        'readingTime' => function () {
            /** @var \Kirby\Cms\Page $this */
            $text = '';
            foreach (['body', 'text', 'blocks'] as $field) {
                $text .= ' ' . $this->content()->get($field)->value();
            }

            return ReadingTime::minutes($text);
        },
    ],

    'siteMethods' => [
        'analytics' => fn () => breakfast()->analytics(),
    ],

    'fieldMethods' => [
        // Safe excerpt from any field.
        'excerptSafe' => function ($field, int $length = 160) {
            $text = trim(preg_replace('/\s+/', ' ', strip_tags((string) $field->value())) ?? '');

            return mb_strlen($text) <= $length ? $text : rtrim(mb_substr($text, 0, $length - 1)) . '…';
        },
    ],

    // ------------------------------------------------------------------
    // Hooks: fire content webhooks + purge cache on publish/update.
    // ------------------------------------------------------------------
    'hooks' => [
        'page.changeStatus:after' => function ($newPage) {
            if ($newPage->isListed()) {
                breakfast()->webhooks()->dispatch('content.published', [
                    'uuid'     => $newPage->uuid()->id(),
                    'title'    => $newPage->title()->value(),
                    'template' => $newPage->intendedTemplate()->name(),
                    'url'      => $newPage->url(),
                ]);
            }
        },
        'page.update:after' => function ($newPage) {
            if ($newPage->isListed()) {
                breakfast()->webhooks()->dispatch('content.updated', [
                    'uuid'  => $newPage->uuid()->id(),
                    'title' => $newPage->title()->value(),
                ]);
            }
        },
    ],

    // Blueprints, templates and snippets that ship WITH the plugin (reusable
    // field groups + CRM blueprints). Page-level blueprints live in site/.
    'blueprints' => [
        'fields/seo'        => __DIR__ . '/blueprints/fields/seo.yml',
    ],

    'translations' => [
        'en' => require __DIR__ . '/translations/en.php',
    ],
]);
