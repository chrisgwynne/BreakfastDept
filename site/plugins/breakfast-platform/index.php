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
/**
 * Send a client a passwordless portal sign-in link through the existing mail
 * pipeline (durable queue + provider abstraction). Best-effort by design: the
 * caller wraps this in a try/catch so a mail outage never blocks sign-in, and
 * (outside production) the link is also shown on the confirmation page.
 */
function breakfast_portal_send_link(string $email, string $url): void
{
    if (!\Breakfast\Platform\Mail\EmailAddress::isValid($email)) {
        return;
    }
    $cfg = breakfast()->mailConfig();
    $sender = \Breakfast\Platform\Mail\EmailAddress::create(
        (string) ($cfg['senderEmail'] ?? $cfg['from'] ?? 'studio@example.com'),
        (string) ($cfg['senderName'] ?? $cfg['fromName'] ?? 'Breakfast')
    );
    $to = \Breakfast\Platform\Mail\EmailAddress::create($email);
    $safeUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
    $message = new \Breakfast\Platform\Mail\MailMessage(
        messageType: 'portal_login_link',
        sender: $sender,
        to: [$to],
        subject: 'Your sign-in link',
        html: '<p>Here is your secure sign-in link. It works once and expires in 15 minutes.</p>'
            . '<p><a href="' . $safeUrl . '">Sign in to your project portal</a></p>',
        text: "Here is your secure sign-in link (works once, expires in 15 minutes):\n" . $url,
        tags: ['portal', 'transactional'],
    );
    breakfast()->mailService()->queue($message);
}

/**
 * Security headers for the standalone tokened client HTML pages (invoice,
 * proposal, contract, onboarding, change request, portal). These documents do
 * NOT pass through the public site layout, so without this they would ship with
 * no CSP. Defence in depth: a strict CSP with frame-ancestors 'none' (stops
 * clickjacking of the sign / pay / approve flows), no-referrer (stops the
 * capability token leaking via the Referer header), nosniff, COOP,
 * Permissions-Policy, no-store, and — in production — HSTS. Pass a per-request
 * nonce for the two pages that carry a legitimate inline <script>.
 *
 * @return array<string,string>
 */
function breakfast_client_headers(?string $nonce = null): array
{
    $scriptSrc = $nonce !== null ? "'self' 'nonce-" . $nonce . "'" : "'self'";
    $csp = "default-src 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'none'; "
        . "form-action 'self'; script-src " . $scriptSrc . "; style-src 'self' 'unsafe-inline'; "
        . "img-src 'self' data:; font-src 'self'; connect-src 'self'";
    $headers = [
        'Content-Security-Policy'    => $csp,
        'X-Content-Type-Options'     => 'nosniff',
        'X-Frame-Options'            => 'DENY',
        'Referrer-Policy'            => 'no-referrer',
        'Permissions-Policy'         => 'geolocation=(), camera=(), microphone=(), payment=(), interest-cohort=()',
        'Cross-Origin-Opener-Policy' => 'same-origin',
        'X-Robots-Tag'               => 'noindex, nofollow',
        'Cache-Control'              => 'private, no-store',
    ];
    if (breakfast()->isProduction()) {
        $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
    }

    return $headers;
}

/**
 * Security headers for the admin SPA shell (built index.html). The shell has no
 * inline scripts, so script-src 'self' holds. Frame-ancestors 'none' prevents
 * the admin being framed; no-store keeps the shell out of shared caches.
 *
 * @return array<string,string>
 */
function breakfast_admin_shell_headers(): array
{
    $csp = "default-src 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'none'; "
        . "form-action 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; "
        . "img-src 'self' data:; font-src 'self'; connect-src 'self'; manifest-src 'self'";
    $headers = [
        'Content-Security-Policy'    => $csp,
        'X-Content-Type-Options'     => 'nosniff',
        'X-Frame-Options'            => 'DENY',
        'Referrer-Policy'            => 'strict-origin-when-cross-origin',
        'Permissions-Policy'         => 'geolocation=(), camera=(), microphone=(), payment=(), interest-cohort=()',
        'Cross-Origin-Opener-Policy' => 'same-origin',
        'X-Robots-Tag'               => 'noindex, nofollow',
        'Cache-Control'              => 'no-store',
    ];
    if (breakfast()->isProduction()) {
        $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
    }

    return $headers;
}

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

        // Portfolio is flat-file Kirby content (content/1_work/*): /work and each
        // /work/{slug} case study are ordinary Kirby pages served by the normal
        // page routing, editable as files and deployed by pushing. (The old
        // database-backed /work routes were removed with the DB portfolio module.)

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

        // Stripe payment webhook. Verifies the signature against the RAW request
        // body (never the parsed form) with the endpoint signing secret, enforces
        // event idempotency, and reconciles verified payments to invoices. Its own
        // auth (signature), NOT Hermes HMAC and NOT Panel session.
        //
        // Deliberately NOT under the `api/` slug: Kirby reserves `/api/*` for its
        // own API router, which swallows unknown paths there — so a non-api,
        // top-level path is used, matched by the front-end router and stable for
        // the Stripe dashboard endpoint URL.
        [
            'pattern' => 'webhooks/stripe',
            'method'  => 'POST',
            'action'  => function () {
                $platform = breakfast();
                $settings = $platform->stripeSettings();
                $payload  = file_get_contents('php://input') ?: '';
                $sig      = (string) (kirby()->request()->header('Stripe-Signature') ?? '');

                try {
                    $verifier = new \Breakfast\Platform\Payments\StripeWebhook($settings->webhookSecret());
                    $event    = $verifier->constructEvent($payload, $sig);
                } catch (\Breakfast\Platform\Payments\PaymentException $e) {
                    // A failed signature must never be processed. 400 tells Stripe
                    // to retry (transient) or that the secret is wrong (operator fix).
                    return \Kirby\Http\Response::json(['error' => 'invalid_signature'], $e->status >= 400 && $e->status < 500 ? 400 : $e->status);
                }

                try {
                    $platform->payments()->handleEvent($event);
                } catch (\Throwable $e) {
                    $platform->logger()->error('stripe', 'Webhook handling failed: ' . $e->getMessage());

                    return \Kirby\Http\Response::json(['error' => 'processing_error'], 500);
                }

                return \Kirby\Http\Response::json(['received' => true], 200);
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
                $auth  = new Authenticator($store, $platform->fileStore(), (int) ($platform->config('hermes')['replayWindow'] ?? 300));
                $api   = new HermesApi($platform, $auth, new AuditLog($platform->fileStore()), new DraftFactory());

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

        // Authenticated draft preview for the standalone admin's website editor.
        // Registered before the SPA shell route so it isn't swallowed by the
        // client-side catch-all. Renders the page's unpublished draft (changes
        // version) in memory — nothing is persisted, and it never publishes to
        // preview.
        [
            'pattern' => 'breakfast-admin/preview/page/(:any)',
            'method'  => 'GET',
            'action'  => function (string $id) {
                $user = kirby()->user();
                if (!\Breakfast\Platform\Security\PanelGate::canEditWebsite($user)) {
                    return new \Kirby\Http\Response('Not found', 'text/plain', 404);
                }
                try {
                    $html = (new \Breakfast\Platform\Website\WebsiteContent(kirby(), breakfast()))->previewHtml($id);
                } catch (\Breakfast\Platform\Website\WebsiteException $e) {
                    return new \Kirby\Http\Response($e->getMessage(), 'text/plain', $e->status);
                }

                // The rendered page carries the site layout's nonce-based CSP
                // (site/snippets/layouts/header.php emits SecurityHeaders during
                // render), so we do NOT set a second, conflicting CSP here — that
                // would break the page's own nonce'd scripts. We add the
                // framing/sniffing/referrer/caching protections the render path
                // does not, so an authored draft cannot be clickjacked, sniffed,
                // cached, or leak the admin URL via Referer.
                return new \Kirby\Http\Response($html, 'text/html', 200, [
                    'X-Robots-Tag'           => 'noindex, nofollow',
                    'X-Frame-Options'        => 'DENY',
                    'X-Content-Type-Options' => 'nosniff',
                    'Referrer-Policy'        => 'no-referrer',
                    'Cache-Control'          => 'private, no-store, max-age=0',
                ]);
            },
        ],

        // Authenticated invoice PDF download. Streams the stored PDF from the
        // private store (outside the webroot) with an integrity check; ordinary
        // downloads return the original issued file. Registered before the SPA
        // catch-all. ?document=<uuid> selects a specific version.
        [
            'pattern' => 'breakfast-admin/invoices/(:any)/download',
            'method'  => 'GET',
            'action'  => function (string $id) {
                $user = kirby()->user();
                if (!\Breakfast\Platform\Security\PanelGate::canViewInvoicePdf($user)) {
                    return new \Kirby\Http\Response('Not found', 'text/plain', 404);
                }
                try {
                    $documentUuid = get('document');
                    $result = breakfast()->invoiceDocuments()->download($id, is_string($documentUuid) ? $documentUuid : null);
                } catch (\Breakfast\Platform\Invoicing\InvoiceException $e) {
                    return new \Kirby\Http\Response($e->getMessage(), 'text/plain', $e->status);
                }
                $filename = preg_replace('/[^A-Za-z0-9.\-]/', '', (string) ($result['doc']['filename'] ?? 'invoice.pdf')) ?: 'invoice.pdf';

                return new \Kirby\Http\Response($result['bytes'], 'application/pdf', 200, [
                    'Content-Disposition' => 'attachment; filename="' . $filename . '"',
                    'X-Content-Type-Options' => 'nosniff',
                    'Cache-Control' => 'private, no-store',
                ]);
            },
        ],

        // Authenticated staff download of a proposal's stored PDF (before the
        // SPA catch-all, or the shell would swallow it).
        [
            'pattern' => 'breakfast-admin/proposals/(:any)/download',
            'method'  => 'GET',
            'action'  => function (string $id) {
                if (!\Breakfast\Platform\Security\PanelGate::canViewProposals(kirby()->user())) {
                    return new \Kirby\Http\Response('Not found', 'text/plain', 404);
                }
                try {
                    $result = breakfast()->proposalDocuments()->download($id);
                } catch (\Breakfast\Platform\Proposals\ProposalException $e) {
                    return new \Kirby\Http\Response($e->getMessage(), 'text/plain', $e->status);
                }
                $filename = preg_replace('/[^A-Za-z0-9.\-]/', '', $result['filename']) ?: 'proposal.pdf';

                return new \Kirby\Http\Response($result['bytes'], 'application/pdf', 200, [
                    'Content-Disposition' => 'attachment; filename="' . $filename . '"',
                    'X-Content-Type-Options' => 'nosniff',
                    'Cache-Control' => 'private, no-store',
                ]);
            },
        ],

        // Authenticated staff download of a contract's stored PDF (unsigned or
        // signed). Before the SPA catch-all.
        [
            'pattern' => 'breakfast-admin/contracts/(:any)/(unsigned|signed)/download',
            'method'  => 'GET',
            'action'  => function (string $id, string $which) {
                if (!\Breakfast\Platform\Security\PanelGate::canViewContracts(kirby()->user())) {
                    return new \Kirby\Http\Response('Not found', 'text/plain', 404);
                }
                try {
                    $result = breakfast()->contractDocuments()->download($id, $which);
                } catch (\Breakfast\Platform\Contracts\ContractException $e) {
                    return new \Kirby\Http\Response($e->getMessage(), 'text/plain', $e->status);
                }
                $filename = preg_replace('/[^A-Za-z0-9.\-]/', '', $result['filename']) ?: 'contract.pdf';

                return new \Kirby\Http\Response($result['bytes'], 'application/pdf', 200, [
                    'Content-Disposition' => 'attachment; filename="' . $filename . '"',
                    'X-Content-Type-Options' => 'nosniff',
                    'Cache-Control' => 'private, no-store',
                ]);
            },
        ],

        // Authenticated staff download of a payment receipt PDF. Streams the
        // stored receipt from the private store with an integrity check. Before
        // the SPA catch-all.
        [
            'pattern' => 'breakfast-admin/payments/(:any)/receipt',
            'method'  => 'GET',
            'action'  => function (string $id) {
                if (!\Breakfast\Platform\Security\PanelGate::canViewPayments(kirby()->user())) {
                    return new \Kirby\Http\Response('Not found', 'text/plain', 404);
                }
                try {
                    $result = breakfast()->payments()->downloadReceipt($id);
                } catch (\Breakfast\Platform\Payments\PaymentException $e) {
                    return new \Kirby\Http\Response($e->getMessage(), 'text/plain', $e->status);
                }
                $filename = preg_replace('/[^A-Za-z0-9.\-]/', '', $result['filename']) ?: 'receipt.pdf';

                return new \Kirby\Http\Response($result['bytes'], 'application/pdf', 200, [
                    'Content-Disposition' => 'attachment; filename="' . $filename . '"',
                    'X-Content-Type-Options' => 'nosniff',
                    'Cache-Control' => 'private, no-store',
                ]);
            },
        ],

        // Authenticated staff download of a client-library file (a specific
        // version via ?version=N). Streams from the private store with an
        // integrity check + access-event record. Before the SPA catch-all.
        [
            'pattern' => 'breakfast-admin/files/(:any)/download',
            'method'  => 'GET',
            'action'  => function (string $id) {
                $user = kirby()->user();
                if (!\Breakfast\Platform\Security\PanelGate::canViewFiles($user)) {
                    return new \Kirby\Http\Response('Not found', 'text/plain', 404);
                }
                try {
                    $version = get('version');
                    $result = breakfast()->files()->download($id, is_numeric($version) ? (int) $version : null, (string) $user->email());
                } catch (\Breakfast\Platform\Files\FileException $e) {
                    return new \Kirby\Http\Response($e->getMessage(), 'text/plain', $e->status);
                }

                return new \Kirby\Http\Response($result['bytes'], 'application/octet-stream', 200, [
                    'Content-Disposition' => 'attachment; filename="' . $result['filename'] . '"',
                    'X-Content-Type-Options' => 'nosniff',
                    'Cache-Control' => 'private, no-store',
                ]);
            },
        ],

        // Authenticated staff thumbnail for a client-library image (safe, small).
        [
            'pattern' => 'breakfast-admin/files/(:any)/thumb',
            'method'  => 'GET',
            'action'  => function (string $id) {
                if (!\Breakfast\Platform\Security\PanelGate::canViewFiles(kirby()->user())) {
                    return new \Kirby\Http\Response('Not found', 'text/plain', 404);
                }
                $thumb = breakfast()->files()->thumbnail($id);
                if ($thumb === null) {
                    return new \Kirby\Http\Response('No thumbnail', 'text/plain', 404);
                }

                return new \Kirby\Http\Response($thumb['bytes'], 'image/jpeg', 200, ['Cache-Control' => 'private, max-age=300', 'X-Content-Type-Options' => 'nosniff']);
            },
        ],

        // Authenticated staff download of a change request's frozen PDF. Streams
        // the immutable document from the private store with a sha256 integrity
        // check. Registered before the SPA catch-all.
        [
            'pattern' => 'breakfast-admin/change-requests/(:any)/download',
            'method'  => 'GET',
            'action'  => function (string $id) {
                if (!\Breakfast\Platform\Security\PanelGate::canViewChangeRequests(kirby()->user())) {
                    return new \Kirby\Http\Response('Not found', 'text/plain', 404);
                }
                try {
                    $result = breakfast()->changeRequests()->download($id);
                } catch (\Breakfast\Platform\ChangeRequests\ChangeRequestException $e) {
                    return new \Kirby\Http\Response($e->getMessage(), 'text/plain', $e->status);
                }

                return new \Kirby\Http\Response($result['bytes'], 'application/pdf', 200, [
                    'Content-Disposition' => 'attachment; filename="' . $result['filename'] . '"',
                    'X-Content-Type-Options' => 'nosniff',
                    'Cache-Control' => 'private, no-store',
                ]);
            },
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

                return new \Kirby\Http\Response(file_get_contents($index) ?: '', 'text/html', 200, breakfast_admin_shell_headers());
            },
        ],

        // Admin invoice preview (any invoice, incl. drafts) — requires a signed-in
        // user with invoice access. Renders the same branded, print-ready document.
        [
            'pattern' => 'invoice/preview/(:any)',
            'action'  => function (string $id) {
                $user = kirby()->user();
                if (!\Breakfast\Platform\Security\PanelGate::canViewInvoices($user)) {
                    $error = kirby()->site()->errorPage();

                    return new \Kirby\Http\Response($error !== null ? $error->render() : 'Not found', 'text/html', 404);
                }
                $inv = breakfast()->invoices()->find($id);
                if ($inv === null) {
                    return new \Kirby\Http\Response('Invoice not found', 'text/plain', 404);
                }

                return new \Kirby\Http\Response(snippet('invoice', ['invoice' => $inv], true), 'text/html', 200, breakfast_client_headers());
            },
        ],

        // Public client "pay now" action (/invoice/<token>/pay). Creates a Stripe
        // Checkout Session for the invoice's outstanding balance (amount computed
        // server-side, never supplied by the client) and redirects to Stripe's
        // hosted page. The invoice is NEVER marked paid here — only a verified
        // webhook does that. Placed before the GET view route.
        [
            'pattern' => 'invoice/(:any)/pay',
            'method'  => 'POST',
            'action'  => function (string $token) {
                $platform = breakfast();
                $inv = $platform->invoices()->findByToken($token);
                if ($inv === null) {
                    return new \Kirby\Http\Response('Not found', 'text/plain', 404);
                }
                try {
                    $siteUrl = rtrim((string) kirby()->site()->url(), '/');
                    $link = $platform->payments()->createCheckout((string) $inv['uuid'], 'client', $siteUrl);
                } catch (\Breakfast\Platform\Payments\PaymentException $e) {
                    return new \Kirby\Http\Response($e->getMessage(), 'text/plain', $e->status);
                }
                $url = (string) ($link['url'] ?? '');
                if ($url === '') {
                    return new \Kirby\Http\Response('Could not start the payment.', 'text/plain', 502);
                }

                return \Kirby\Http\Response::redirect($url, 303);
            },
        ],

        // Signed client invoice link (/invoice/<token>). The unguessable token is
        // the capability; viewing marks the invoice viewed. No login required.
        [
            'pattern' => 'invoice/(:any)',
            'action'  => function (string $token) {
                $svc = breakfast()->invoices();
                $inv = $svc->findByToken($token);
                if ($inv === null) {
                    $error = kirby()->site()->errorPage();

                    return new \Kirby\Http\Response($error !== null ? $error->render() : 'Not found', 'text/html', 404);
                }
                $svc->markViewed((string) $inv['uuid']);

                return new \Kirby\Http\Response(snippet('invoice', ['invoice' => $inv], true), 'text/html', 200, breakfast_client_headers());
            },
        ],

        // Public client proposal actions (POST). The unguessable token is the
        // capability; acceptance is evidenced (name/email/ip-hash/ua). Placed
        // before the GET view route so /accept etc. aren't swallowed by (:any).
        [
            'pattern' => 'proposal/(:any)/(accept|reject|select)',
            'method'  => 'POST',
            'action'  => function (string $token, string $verb) {
                $svc = breakfast()->proposals();
                $p   = $svc->findByToken($token);
                if ($p === null) {
                    return new \Kirby\Http\Response('Not found', 'text/plain', 404);
                }
                $uuid = (string) $p['uuid'];
                try {
                    if ($verb === 'select') {
                        $ids = get('options');
                        $svc->selectOptions($uuid, is_array($ids) ? array_map('strval', $ids) : [], 'client');
                    } elseif ($verb === 'reject') {
                        $svc->reject($uuid, (string) get('reason', ''), 'client');
                    } else {
                        $ipHash = hash('sha256', (string) (kirby()->option('breakfast.webhookSecret', '') ?? '') . '|' . (string) (kirby()->visitor()->ip() ?? ''));
                        $svc->accept($uuid, [
                            'name'       => (string) get('name', ''),
                            'email'      => (string) get('email', ''),
                            'ip_hash'    => $ipHash,
                            'user_agent' => (string) (kirby()->request()->header('User-Agent') ?? ''),
                            'wording'    => 'I confirm I am authorised to accept this proposal on behalf of the client.',
                        ]);
                    }
                } catch (\Breakfast\Platform\Proposals\ProposalException $e) {
                    return new \Kirby\Http\Response($e->getMessage(), 'text/plain', $e->status);
                }

                return \Kirby\Http\Response::redirect(rtrim((string) kirby()->site()->url(), '/') . '/proposal/' . $token);
            },
        ],

        // Signed client proposal link (/proposal/<token>). Viewing marks it
        // viewed (never accepted). No login required.
        [
            'pattern' => 'proposal/(:any)',
            'method'  => 'GET',
            'action'  => function (string $token) {
                $svc = breakfast()->proposals();
                $p   = $svc->findByToken($token);
                if ($p === null) {
                    $error = kirby()->site()->errorPage();

                    return new \Kirby\Http\Response($error !== null ? $error->render() : 'Not found', 'text/html', 404);
                }
                $svc->markViewed((string) $p['uuid']);

                return new \Kirby\Http\Response(snippet('proposal', ['proposal' => $svc->find((string) $p['uuid']), 'token' => $token], true), 'text/html', 200, breakfast_client_headers());
            },
        ],

        // Public client contract signing actions (POST). The unguessable token
        // is the capability; signing is an explicit, evidenced act.
        [
            'pattern' => 'contract/(:any)/(sign|decline)',
            'method'  => 'POST',
            'action'  => function (string $token, string $verb) {
                $svc = breakfast()->contracts();
                $c   = $svc->findByToken($token);
                if ($c === null) {
                    return new \Kirby\Http\Response('Not found', 'text/plain', 404);
                }
                $uuid = (string) $c['uuid'];
                try {
                    if ($verb === 'decline') {
                        $svc->decline($uuid, (string) get('reason', ''), 'client');
                    } else {
                        if ((string) get('consent', '') !== 'yes' || trim((string) get('name', '')) === '') {
                            return new \Kirby\Http\Response('You must enter your name and confirm consent to sign.', 'text/plain', 422);
                        }
                        // The client party (order 1) signs through the hosted flow.
                        $party = null;
                        foreach (($c['parties'] ?? []) as $p) {
                            if ((string) $p['role'] === 'client') {
                                $party = (string) $p['uuid'];
                                break;
                            }
                        }
                        if ($party === null) {
                            return new \Kirby\Http\Response('No client signatory.', 'text/plain', 409);
                        }
                        $ipHash = hash('sha256', (string) (kirby()->option('breakfast.webhookSecret', '') ?? '') . '|' . (string) (kirby()->visitor()->ip() ?? ''));
                        $svc->sign($uuid, $party, [
                            'name'       => (string) get('name', ''),
                            'email'      => (string) get('email', (string) ($c['client_email'] ?? '')),
                            'ip_hash'    => $ipHash,
                            'user_agent' => (string) (kirby()->request()->header('User-Agent') ?? ''),
                            'token_id'   => substr($token, 0, 8),
                            'method'     => 'typed',
                            'wording'    => (string) ($c['signature_wording'] ?? 'I agree to this contract.'),
                        ]);
                    }
                } catch (\Breakfast\Platform\Contracts\ContractException $e) {
                    return new \Kirby\Http\Response($e->getMessage(), 'text/plain', $e->status);
                }

                return \Kirby\Http\Response::redirect(rtrim((string) kirby()->site()->url(), '/') . '/contract/' . $token);
            },
        ],

        // Public client onboarding actions (POST /onboarding/<token>/save|submit).
        // The opaque token is the capability; answers are server-validated and
        // durable. Registered before the GET view route.
        [
            'pattern' => 'onboarding/(:any)/(save|submit)',
            'method'  => 'POST',
            'action'  => function (string $token, string $verb) {
                $svc = breakfast()->onboarding();
                $inst = $svc->findByToken($token);
                if ($inst === null) {
                    return new \Kirby\Http\Response('This onboarding link is invalid or has expired.', 'text/plain', 404);
                }
                $uuid = (string) $inst['uuid'];
                try {
                    if ($verb === 'save') {
                        $answers = get('answers');
                        $svc->saveDraft($uuid, is_array($answers) ? $answers : [], null, 'client');
                    } else {
                        $answers = get('answers');
                        if (is_array($answers) && $answers !== []) {
                            $svc->saveDraft($uuid, $answers, null, 'client');
                        }
                        $svc->submit($uuid, 'client');
                    }
                } catch (\Breakfast\Platform\Onboarding\OnboardingException $e) {
                    return \Kirby\Http\Response::json(['error' => $e->getMessage()], $e->status);
                }

                return \Kirby\Http\Response::json(['ok' => true, 'status' => (string) ($svc->find($uuid)['status'] ?? '')]);
            },
        ],

        // Hosted client onboarding form (/onboarding/<token>). Viewing marks it
        // 'viewed' (never in_progress). No login required.
        [
            'pattern' => 'onboarding/(:any)',
            'method'  => 'GET',
            'action'  => function (string $token) {
                $svc = breakfast()->onboarding();
                $inst = $svc->findByToken($token);
                if ($inst === null) {
                    $error = kirby()->site()->errorPage();

                    return new \Kirby\Http\Response($error !== null ? $error->render() : 'Not found', 'text/html', 404);
                }
                $svc->markViewed((string) $inst['uuid']);
                $structure = breakfast()->onboardingTemplates()->versionStructure((string) $inst['template_uuid'], (int) $inst['version']);

                $nonce = rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');

                return new \Kirby\Http\Response(snippet('onboarding', ['instance' => $svc->find((string) $inst['uuid']), 'structure' => $structure, 'token' => $token, 'nonce' => $nonce], true), 'text/html', 200, breakfast_client_headers($nonce));
            },
        ],

        // Signed client contract link (/contract/<token>). Viewing marks it
        // viewed (never signed). No login required.
        [
            'pattern' => 'contract/(:any)',
            'method'  => 'GET',
            'action'  => function (string $token) {
                $svc = breakfast()->contracts();
                $c   = $svc->findByToken($token);
                if ($c === null) {
                    $error = kirby()->site()->errorPage();

                    return new \Kirby\Http\Response($error !== null ? $error->render() : 'Not found', 'text/html', 404);
                }
                $svc->markViewed((string) $c['uuid']);

                return new \Kirby\Http\Response(snippet('contract-sign', ['contract' => $svc->find((string) $c['uuid']), 'token' => $token], true), 'text/html', 200, breakfast_client_headers());
            },
        ],

        // Public client change-request decision (POST /change/<token>/approve|reject).
        // The opaque token is the capability; approval is explicit + evidenced.
        // Registered before the GET view route.
        [
            'pattern' => 'change/(:any)/(approve|reject)',
            'method'  => 'POST',
            'action'  => function (string $token, string $verb) {
                $svc = breakfast()->changeRequests();
                $cr  = $svc->findByToken($token);
                if ($cr === null) {
                    return new \Kirby\Http\Response('Not found', 'text/plain', 404);
                }
                $decision = $verb === 'approve' ? 'approved' : 'rejected';
                if ($decision === 'approved' && trim((string) get('name', '')) === '') {
                    return new \Kirby\Http\Response('Please enter your name to approve.', 'text/plain', 422);
                }
                $ipHash = hash('sha256', (string) (kirby()->option('breakfast.webhookSecret', '') ?? '') . '|' . (string) (kirby()->visitor()->ip() ?? ''));
                try {
                    $svc->decide((string) $cr['uuid'], $decision, [
                        'name'       => (string) get('name', ''),
                        'email'      => (string) get('email', ''),
                        'comment'    => (string) get('comment', ''),
                        'ip_hash'    => $ipHash,
                        'user_agent' => (string) (kirby()->request()->header('User-Agent') ?? ''),
                        'token_id'   => substr($token, 0, 8),
                    ], 'client');
                } catch (\Breakfast\Platform\ChangeRequests\ChangeRequestException $e) {
                    return new \Kirby\Http\Response($e->getMessage(), 'text/plain', $e->status);
                }

                return \Kirby\Http\Response::redirect(rtrim((string) kirby()->site()->url(), '/') . '/change/' . $token);
            },
        ],

        // Hosted client change-request page (/change/<token>). Viewing marks it
        // 'viewed' (never approved). No login required.
        [
            'pattern' => 'change/(:any)',
            'method'  => 'GET',
            'action'  => function (string $token) {
                $svc = breakfast()->changeRequests();
                $cr  = $svc->findByToken($token);
                if ($cr === null) {
                    $error = kirby()->site()->errorPage();

                    return new \Kirby\Http\Response($error !== null ? $error->render() : 'Not found', 'text/html', 404);
                }
                $svc->markViewed((string) $cr['uuid']);

                $nonce = rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');

                return new \Kirby\Http\Response(snippet('change-request', ['cr' => $svc->find((string) $cr['uuid']), 'token' => $token, 'nonce' => $nonce], true), 'text/html', 200, breakfast_client_headers($nonce));
            },
        ],

        // ============================================================
        // Client portal (Phase 3) — a separate trust boundary from staff.
        // Passwordless: request a magic link, consume it to mint a server-side
        // session carried by an httpOnly `bf_portal` cookie. Registered before
        // the SPA/sitemap routes.
        // ============================================================

        // Request a sign-in link (POST /portal/login). Always reports success to
        // avoid email enumeration; a link is only minted for an active identity.
        [
            'pattern' => 'portal/login',
            'method'  => 'POST',
            'action'  => function () {
                $email  = (string) get('email', '');
                $ipHash = hash('sha256', (string) (kirby()->option('breakfast.webhookSecret', '') ?? '') . '|' . (string) (kirby()->visitor()->ip() ?? ''));
                // Throttle sign-in-link requests to stop mail-bombing a known
                // client address and IP-driven abuse. Over the limit we still show
                // the neutral "check your email" page (no enumeration) but mint
                // nothing. 5 per email / 15 min, 20 per IP / 15 min.
                $emailKey = hash('sha256', strtolower(trim($email)));
                $rl = breakfast()->rateLimiter();
                $throttled = $email === ''
                    || $rl->hit('portal_login_email:' . $emailKey, 5, 900)['allowed'] === false
                    || $rl->hit('portal_login_ip:' . $ipHash, 20, 900)['allowed'] === false;
                $result = $throttled ? ['sent' => false, 'identity_uuid' => null, 'token' => null] : breakfast()->portal()->requestLogin($email, $ipHash);
                $devLink = null;
                if ($result['sent'] && $result['token'] !== null) {
                    $url = rtrim((string) kirby()->site()->url(), '/') . '/portal/verify/' . $result['token'];
                    // Best-effort transactional delivery through the existing mail
                    // pipeline; never fail the request if mail is unavailable.
                    try {
                        breakfast_portal_send_link($email, $url);
                    } catch (\Throwable) { /* delivery is best-effort */ }
                    // Outside production, reveal the link on the confirmation page
                    // so the flow is demonstrable without an inbox.
                    if (!breakfast()->isProduction()) {
                        $devLink = $url;
                    }
                }

                return new \Kirby\Http\Response(snippet('portal-login', ['sent' => true, 'devLink' => $devLink], true), 'text/html', 200, breakfast_client_headers());
            },
        ],

        // Consume a magic link (GET /portal/verify/<token>) → set the session
        // cookie and land on the portal. One-shot; invalid/expired links show a
        // friendly retry.
        [
            'pattern' => 'portal/verify/(:any)',
            'method'  => 'GET',
            'action'  => function (string $token) {
                $ipHash = hash('sha256', (string) (kirby()->option('breakfast.webhookSecret', '') ?? '') . '|' . (string) (kirby()->visitor()->ip() ?? ''));
                try {
                    $result = breakfast()->portal()->consumeMagicLink($token, $ipHash, (string) (kirby()->request()->header('User-Agent') ?? ''));
                } catch (\Breakfast\Platform\Portal\PortalException $e) {
                    return new \Kirby\Http\Response(snippet('portal-login', ['sent' => false, 'error' => $e->getMessage()], true), 'text/html', $e->status, breakfast_client_headers());
                }
                $secure = breakfast()->isProduction() ? ' Secure;' : '';
                $cookie = 'bf_portal=' . rawurlencode((string) $result['session_token']) . '; Path=/portal; Max-Age=2592000; HttpOnly;' . $secure . ' SameSite=Lax';

                return new \Kirby\Http\Response('', 'text/html', 302, [
                    'Location' => rtrim((string) kirby()->site()->url(), '/') . '/portal',
                    'Set-Cookie' => $cookie,
                    'X-Robots-Tag' => 'noindex, nofollow',
                ]);
            },
        ],

        // Sign out (POST /portal/logout) — revoke the session and clear the
        // cookie. POST (not GET) so a cross-site navigation cannot force logout.
        [
            'pattern' => 'portal/logout',
            'method'  => 'POST',
            'action'  => function () {
                breakfast()->portal()->logout((string) ($_COOKIE['bf_portal'] ?? ''));

                return new \Kirby\Http\Response('', 'text/html', 302, [
                    'Location' => rtrim((string) kirby()->site()->url(), '/') . '/portal',
                    'Set-Cookie' => 'bf_portal=; Path=/portal; Max-Age=0; HttpOnly; SameSite=Lax',
                    'X-Robots-Tag' => 'noindex, nofollow',
                ]);
            },
        ],

        // The portal home (GET /portal). Valid session → the client's project
        // list; otherwise the sign-in page. No staff session is ever consulted.
        [
            'pattern' => 'portal',
            'method'  => 'GET',
            'action'  => function () {
                $identity = breakfast()->portal()->identityFromSession((string) ($_COOKIE['bf_portal'] ?? ''));
                if ($identity === null) {
                    return new \Kirby\Http\Response(snippet('portal-login', ['sent' => false], true), 'text/html', 200, breakfast_client_headers());
                }
                $projects = breakfast()->portal()->accessibleProjects((string) $identity['uuid']);
                $previews = breakfast()->portal()->accessiblePreviews((string) $identity['uuid']);
                $documents = breakfast()->portal()->accessibleDocuments((string) $identity['uuid']);

                return new \Kirby\Http\Response(snippet('portal-home', ['identity' => $identity, 'projects' => $projects, 'previews' => $previews, 'documents' => $documents], true), 'text/html', 200, breakfast_client_headers());
            },
        ],

        // A single project the client has been granted (GET /portal/project/<uuid>).
        // Access is server-enforced against live grants — never the URL.
        [
            'pattern' => 'portal/project/(:any)',
            'method'  => 'GET',
            'action'  => function (string $projectUuid) {
                $identity = breakfast()->portal()->identityFromSession((string) ($_COOKIE['bf_portal'] ?? ''));
                if ($identity === null) {
                    return new \Kirby\Http\Response(snippet('portal-login', ['sent' => false], true), 'text/html', 401, breakfast_client_headers());
                }
                try {
                    $data = breakfast()->portal()->portalProject((string) $identity['uuid'], $projectUuid);
                } catch (\Breakfast\Platform\Portal\PortalException $e) {
                    return new \Kirby\Http\Response($e->getMessage(), 'text/plain', $e->status, ['X-Robots-Tag' => 'noindex, nofollow']);
                }

                return new \Kirby\Http\Response(snippet('portal-project', ['identity' => $identity, 'data' => $data], true), 'text/html', 200, breakfast_client_headers());
            },
        ],

        // Client submits feedback or a sign-off on a granted project
        // (POST /portal/project/<uuid>/feedback). Access enforced by the session
        // + a live grant.
        [
            'pattern' => 'portal/project/(:any)/feedback',
            'method'  => 'POST',
            'action'  => function (string $projectUuid) {
                $identity = breakfast()->portal()->identityFromSession((string) ($_COOKIE['bf_portal'] ?? ''));
                if ($identity === null) {
                    return new \Kirby\Http\Response('Please sign in.', 'text/plain', 401, ['X-Robots-Tag' => 'noindex, nofollow']);
                }
                $ipHash = hash('sha256', (string) (kirby()->option('breakfast.webhookSecret', '') ?? '') . '|' . (string) (kirby()->visitor()->ip() ?? ''));
                try {
                    breakfast()->portal()->submitFeedback((string) $identity['uuid'], $projectUuid, [
                        'kind' => (string) get('kind', 'comment'),
                        'body' => (string) get('body', ''),
                        'approved_label' => (string) get('approved_label', ''),
                        'ip_hash' => $ipHash,
                        'user_agent' => (string) (kirby()->request()->header('User-Agent') ?? ''),
                    ]);
                } catch (\Breakfast\Platform\Portal\PortalException $e) {
                    return new \Kirby\Http\Response($e->getMessage(), 'text/plain', $e->status, ['X-Robots-Tag' => 'noindex, nofollow']);
                }

                return \Kirby\Http\Response::redirect(rtrim((string) kirby()->site()->url(), '/') . '/portal/project/' . $projectUuid);
            },
        ],

        // Client posts a message to staff on a granted project
        // (POST /portal/project/<uuid>/message).
        [
            'pattern' => 'portal/project/(:any)/message',
            'method'  => 'POST',
            'action'  => function (string $projectUuid) {
                $identity = breakfast()->portal()->identityFromSession((string) ($_COOKIE['bf_portal'] ?? ''));
                if ($identity === null) {
                    return new \Kirby\Http\Response('Please sign in.', 'text/plain', 401, ['X-Robots-Tag' => 'noindex, nofollow']);
                }
                try {
                    breakfast()->portal()->postClientMessage((string) $identity['uuid'], $projectUuid, (string) get('body', ''));
                } catch (\Breakfast\Platform\Portal\PortalException $e) {
                    return new \Kirby\Http\Response($e->getMessage(), 'text/plain', $e->status, ['X-Robots-Tag' => 'noindex, nofollow']);
                }

                return \Kirby\Http\Response::redirect(rtrim((string) kirby()->site()->url(), '/') . '/portal/project/' . $projectUuid . '#messages');
            },
        ],

        // Client download of a shared (client-visible) project file. Guarded by
        // the portal session AND a live project grant; streams via the same
        // integrity-checked library path staff use.
        [
            'pattern' => 'portal/file/(:any)/download',
            'method'  => 'GET',
            'action'  => function (string $fileUuid) {
                $identity = breakfast()->portal()->identityFromSession((string) ($_COOKIE['bf_portal'] ?? ''));
                if ($identity === null) {
                    return new \Kirby\Http\Response('Please sign in.', 'text/plain', 401, ['X-Robots-Tag' => 'noindex, nofollow']);
                }
                try {
                    breakfast()->portal()->assertFileDownloadable((string) $identity['uuid'], $fileUuid);
                    $result = breakfast()->files()->download($fileUuid, null, 'portal:' . (string) $identity['uuid']);
                } catch (\Breakfast\Platform\Portal\PortalException $e) {
                    return new \Kirby\Http\Response($e->getMessage(), 'text/plain', $e->status);
                } catch (\Breakfast\Platform\Files\FileException $e) {
                    return new \Kirby\Http\Response($e->getMessage(), 'text/plain', $e->status);
                }

                return new \Kirby\Http\Response($result['bytes'], 'application/octet-stream', 200, [
                    'Content-Disposition' => 'attachment; filename="' . $result['filename'] . '"',
                    'X-Content-Type-Options' => 'nosniff',
                    'Cache-Control' => 'private, no-store',
                    'X-Robots-Tag' => 'noindex, nofollow',
                ]);
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
                $row = $platform->fileStore()->find('uploads', $uuid);
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

    // The business admin/CRM now lives entirely in the standalone Breakfast Admin
    // application (a custom Vue SPA at /breakfast-admin, backed by the
    // /breakfast-admin/api/v1 layer above). The former custom CRM / dashboard /
    // operations Panel areas and their Panel API routes have been removed — that
    // old admin UI no longer exists anywhere.
    //
    // The one exception is Client Previews *management* (upload, versioning,
    // publish), which the standalone app currently surfaces read-only. Until that
    // authoring flow is rebuilt in the SPA, it stays available in the undisclosed
    // super-admin console so the capability is never lost. Everything here is
    // Panel-authenticated and re-checked server-side on every request.
    'api' => [
        'routes' => require __DIR__ . '/api/previews.php',
    ],

    'areas' => [
        'previews' => require __DIR__ . '/areas/previews.php',
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
        // Analytics is configurable through the admin (content) OR the
        // environment. Content values win when set, so the measurement ID can be
        // pasted into the website editor without a redeploy; env is the fallback
        // for infra-managed setups. Provider stays "none" (nothing loads) until
        // a real provider + id is supplied.
        'analytics' => function () {
            /** @var \Kirby\Cms\Site $this */
            $env      = (array) (kirby()->option('breakfast')['analytics'] ?? []);
            $provider = trim((string) $this->content()->get('analytics_provider')->value()) ?: (string) ($env['provider'] ?? 'none');
            $siteId   = trim((string) $this->content()->get('analytics_site')->value()) ?: (string) ($env['site'] ?? '');

            return new \Breakfast\Platform\Analytics\Analytics([
                'provider'  => $provider,
                'site'      => $siteId,
                'scriptUrl' => (string) ($env['scriptUrl'] ?? ''),
            ]);
        },
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
