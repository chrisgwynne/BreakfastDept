<?php

declare(strict_types=1);

/**
 * Production boot / render verification (invoked by `php bin/console app:check`).
 * Proves the real Kirby app + platform boot and key routes render. Prints no
 * secrets. Exits non-zero on any failure.
 *
 * In scope from bin/console: $platform, $kirby, $base.
 *
 * @var \Breakfast\Platform\Support\Platform $platform
 * @var \Kirby\Cms\App $kirby
 * @var string $base
 */

use Breakfast\Platform\Mail\MailProviderFactory;

$failures = 0;
$check = static function (string $label, bool $ok, string $detail = '') use (&$failures): void {
    if ($ok === false) {
        $failures++;
    }
    fwrite(STDOUT, sprintf("[%s] %s%s\n", $ok ? 'OK' : 'FAIL', $label, $detail !== '' ? ' — ' . $detail : ''));
};

// Composer autoloading + Kirby boot + plugin.
$check('Composer autoloader', class_exists(\Kirby\Cms\App::class));
$check('Kirby boots', $kirby instanceof \Kirby\Cms\App, 'v' . \Kirby\Cms\App::version());
$check('breakfast-platform plugin loaded', $kirby->plugin('breakfast/platform') !== null);

// SQLite + migrations.
try {
    $platform->db()->pdo();
    $check('SQLite opens', true);
} catch (Throwable $e) {
    $check('SQLite opens', false);
}
$applied  = $platform->migrator()->appliedIds();
$expected = array_keys($platform->migrator()->status());
$check('Migrations applied', $applied === $expected, count($applied) . '/' . count($expected));

// Writable storage.
foreach (['cache', 'logs', 'sessions', 'database', 'uploads', 'queue'] as $dir) {
    $path = $platform->storageDir() . '/' . $dir;
    $check('Writable storage/' . $dir, is_dir($path) && is_writable($path));
}

// Public content readable.
$check('Home content readable', $kirby->page('home') !== null);

// Representative routes render.
$routes = ['home', 'work', 'services', 'about', 'journal', 'contact', 'start-a-project', 'privacy'];
foreach ($routes as $slug) {
    $page = $kirby->page($slug);
    $ok   = false;
    if ($page !== null) {
        ob_start();
        try {
            $r    = $page->render();
            $html = is_string($r) ? $r : $r->body();
            $ok   = strlen($html) > 500;
        } catch (Throwable $e) {
            $ok = false;
        }
        ob_end_clean();
    }
    $check('Route /' . $slug . ' renders', $ok);
}
// A representative case study / service / article.
foreach (['work/localmarkers', 'services/new-website', 'journal/five-second-test'] as $deep) {
    $page = $kirby->page($deep);
    $check('Route /' . $deep . ' resolves', $page !== null);
}

// Custom API + webhook routes are registered.
$routeUrls = array_map(static fn ($r) => is_array($r) ? ($r['pattern'] ?? '') : '', $kirby->routes());
$check('Hermes API route registered', in_array('api/breakfast/v1/(:all?)', $routeUrls, true));
$check('Brevo webhook route registered', in_array('api/breakfast/v1/webhooks/brevo', $routeUrls, true));

// Brevo provider can be constructed from valid config WITHOUT sending.
try {
    $provider = MailProviderFactory::make(['provider' => 'brevo', 'brevo' => ['apiKey' => 'check-only-key']], true);
    $check('Brevo provider constructable', $provider->name() === 'brevo');
} catch (Throwable $e) {
    $check('Brevo provider constructable', false);
}

// Standalone Breakfast Admin: the SPA owns /breakfast-admin; the Kirby Panel is
// demoted to a private, undisclosed super-admin slug; /panel is retired.
$panelSlug = (string) ($kirby->option('panel')['slug'] ?? '');
$check('Panel on a private slug', $panelSlug !== '' && $panelSlug !== 'panel' && $panelSlug !== 'breakfast-admin', '/' . $panelSlug);
try {
    $panelResult = $kirby->render('panel');
    $panelCode   = $panelResult instanceof \Kirby\Http\Response ? $panelResult->code() : 200;
    $check('Old /panel returns 404', $panelCode === 404, 'HTTP ' . $panelCode);
} catch (Throwable $e) {
    $check('Old /panel returns 404', false, $e->getMessage());
}

// The standalone admin API responds (unauthenticated session probe → 401 JSON),
// and the SPA shell route is registered so /breakfast-admin serves the app.
try {
    $apiResult = $kirby->render('breakfast-admin/api/v1/session', 'GET');
    $apiCode   = $apiResult instanceof \Kirby\Http\Response ? $apiResult->code() : 0;
    $apiType   = $apiResult instanceof \Kirby\Http\Response ? (string) $apiResult->type() : '';
    $check('Admin API responds (401 JSON when unauthenticated)', $apiCode === 401 && str_contains($apiType, 'json'), 'HTTP ' . $apiCode);
} catch (Throwable $e) {
    $check('Admin API responds (401 JSON when unauthenticated)', false, $e->getMessage());
}

// Client Previews wiring: dev-prefix route present, services constructable,
// preview origin serves security headers even for an unknown slug (404).
$check('Preview dispatch wired', function_exists('breakfast_preview_dispatch') && function_exists('breakfast_preview_serve'));
try {
    $responder = $platform->previewResponder();
    $robots    = $responder->robots();
    $check('Preview responder + robots', str_contains($robots['body'], 'Disallow: /') && isset($robots['headers']['X-Robots-Tag']));
    $miss = $responder->serve('no-such-preview', '', ['ip' => '203.0.113.1']);
    $check('Unknown preview is neutral 404', $miss['status'] === 404 && ($miss['file'] ?? null) === null);
    $csp = $responder->serve('no-such-preview', '', [])['headers']['Content-Security-Policy'] ?? '';
    $check('Preview CSP blocks objects + workers', str_contains($csp, "object-src 'none'") && str_contains($csp, "worker-src 'none'"));
} catch (Throwable $e) {
    $check('Preview responder + robots', false, $e->getMessage());
}

// Client Previews permission category registered (defaults denied).
$catRegistered = isset(\Kirby\Cms\Permissions::$extendedActions[\Breakfast\Platform\Security\PreviewGate::CATEGORY]);
$check('Previews permission category registered', $catRegistered);
$check('Previews denied by default', \Breakfast\Platform\Security\PreviewGate::canView(null) === false);

// DashboardData aggregates without error and leaks no previews table when absent.
try {
    $dash = new \Breakfast\Platform\Admin\DashboardData($platform);
    $summary = $dash->previewsSummary();
    $check('Dashboard aggregation works', is_array($dash->metrics()) && isset($summary['total']));
} catch (Throwable $e) {
    $check('Dashboard aggregation works', false, $e->getMessage());
}

// Debug must be false in production config (informational here in dev).
$check('Debug flag readable', is_bool($kirby->option('debug')), $kirby->option('debug') ? 'debug ON (dev)' : 'debug off');

fwrite(STDOUT, "\n" . ($failures === 0 ? "app:check PASSED\n" : "app:check FAILED ({$failures})\n"));
exit($failures === 0 ? 0 : 1);
