<?php

declare(strict_types=1);

namespace Breakfast\Tests\Integration;

use Breakfast\Platform\Support\Database;
use Breakfast\Platform\Support\Platform;
use Kirby\Cms\App;
use Kirby\Http\Request;
use Kirby\Http\Response;
use PHPUnit\Framework\TestCase;

/**
 * Proves per-preview origin isolation end-to-end through the real front-end
 * dispatch: each preview is served only from its own {slug}.{base} host, an old
 * subdomain 301s to the current one, the apex / multi-level / reserved hosts are
 * never served, the password gate works per subdomain, and the password cookie
 * is host-only with a per-preview name (so it can never unlock another preview).
 */
final class PreviewSubdomainTest extends TestCase
{
    private const BASE = 'preview.test';

    private App $kirby;
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $base = dirname(__DIR__, 2);
        $this->tmp = sys_get_temp_dir() . '/bf-sub-' . bin2hex(random_bytes(6));
        @mkdir($this->tmp . '/database', 0777, true);

        Database::reset();
        Platform::reset();

        $this->kirby = new App([
            'roots' => [
                'index'    => $base . '/public',
                'base'     => $base,
                'site'     => $base . '/site',
                'content'  => $base . '/content',
                'storage'  => $this->tmp,
                'sessions' => $this->tmp . '/sessions',
                'accounts' => $this->tmp . '/accounts',
            ],
            'options' => [
                'debug'  => false,
                'whoops' => false,
                'breakfast' => [
                    'production' => false,
                    'storageDir' => $this->tmp,
                    'dbPath'     => $this->tmp . '/database/crm.sqlite',
                    'previews'   => [
                        'storageDir'   => $this->tmp . '/client-previews',
                        'wildcardBase' => self::BASE,
                        'scheme'       => 'http',
                        'limits'       => [],
                    ],
                    'mail' => ['provider' => 'fake'],
                ],
            ],
        ]);
        breakfast()->migrator()->migrate();
    }

    protected function tearDown(): void
    {
        $_COOKIE = [];
        $_SERVER['HTTP_HOST'] = '';
        Database::reset();
        Platform::reset();
        $this->rrmdir($this->tmp);
        App::destroy();
        restore_error_handler();
        restore_exception_handler();
        parent::tearDown();
    }

    private function seed(string $slug, string $body): string
    {
        $preview = breakfast()->previews()->create(['name' => $slug, 'public_slug' => $slug]);
        $zip = $this->tmp . '/z-' . bin2hex(random_bytes(3)) . '.zip';
        $z = new \ZipArchive();
        $z->open($zip, \ZipArchive::CREATE);
        $z->addFromString('index.html', '<title>' . $slug . '</title><h1 id="' . $slug . '-marker">' . $body . '</h1>');
        $z->close();
        $r = breakfast()->previewUpload()->uploadZip($preview['uuid'], $zip, 'x.zip', null);
        breakfast()->previewPublisher()->publish($preview['uuid'], $r['version']['uuid'], null);

        return $preview['uuid'];
    }

    /** @param array<string,mixed> $body */
    private function dispatch(string $host, string $path, string $method = 'GET', array $body = []): Response
    {
        $_SERVER['HTTP_HOST'] = $host;
        $request = new Request(['query' => [], 'body' => $body, 'method' => $method]);
        $prop = new \ReflectionProperty(App::class, 'request');
        $prop->setAccessible(true);
        $prop->setValue($this->kirby, $request);

        return breakfast_preview_dispatch($path);
    }

    public function testEachPreviewServedOnlyFromItsOwnSubdomain(): void
    {
        $this->seed('acme', 'ACME BODY');
        $this->seed('beta', 'BETA BODY');

        $acme = $this->dispatch('acme.' . self::BASE, '');
        $this->assertSame(200, $acme->code());
        $this->assertStringContainsString('acme-marker', $acme->body());
        $this->assertStringNotContainsString('beta-marker', $acme->body(), 'acme host never serves beta');

        $beta = $this->dispatch('beta.' . self::BASE, '');
        $this->assertSame(200, $beta->code());
        $this->assertStringContainsString('beta-marker', $beta->body());
    }

    public function testApexMultiLevelAndUnknownAreNeutral404(): void
    {
        $this->seed('acme', 'A');
        foreach (['' . self::BASE, 'a.b.' . self::BASE, 'nope.' . self::BASE, 'api.' . self::BASE] as $host) {
            $res = $this->dispatch($host, '');
            $this->assertSame(404, $res->code(), $host . ' must not serve a preview');
        }
    }

    public function testOldSubdomainRedirectsToCanonical(): void
    {
        $uuid = $this->seed('acme', 'A');
        breakfast()->previews()->update($uuid, ['previous_slug' => 'acme', 'public_slug' => 'acme-two']);

        $res = $this->dispatch('acme.' . self::BASE, 'about.html');
        $this->assertSame(301, $res->code());
        $this->assertSame('http://acme-two.' . self::BASE . '/about.html', $res->header('Location'));
    }

    public function testPasswordGateAndHostOnlyPerPreviewCookie(): void
    {
        $acme = $this->seed('acme', 'A');
        $beta = $this->seed('beta', 'B');
        breakfast()->previewPasswords()->setPassword($beta, 'beta-secret', null);

        // Beta is gated; acme is not.
        $this->assertSame(401, $this->dispatch('beta.' . self::BASE, '')->code());
        $this->assertSame(200, $this->dispatch('acme.' . self::BASE, '')->code());

        // Unlock beta: a host-only cookie (no Domain) with a beta-specific name.
        $unlock = $this->dispatch('beta.' . self::BASE, '', 'POST', ['preview_password' => 'beta-secret']);
        $this->assertSame(303, $unlock->code());
        $cookie = $unlock->header('Set-Cookie');
        $this->assertNotNull($cookie);
        $this->assertStringNotContainsStringIgnoringCase('Domain=', (string) $cookie, 'cookie must be host-only');
        $betaCookieName = \Breakfast\Platform\ClientPreviews\PreviewResponder::cookieName('beta');
        $acmeCookieName = \Breakfast\Platform\ClientPreviews\PreviewResponder::cookieName('acme');
        $this->assertStringContainsString($betaCookieName, (string) $cookie);
        $this->assertNotSame($betaCookieName, $acmeCookieName, 'cookie names are per-preview');
    }

    private function rrmdir(string $dir): void
    {
        if (is_dir($dir) === false) {
            return;
        }
        foreach (scandir($dir) ?: [] as $i) {
            if ($i === '.' || $i === '..') {
                continue;
            }
            $p = $dir . '/' . $i;
            is_dir($p) ? $this->rrmdir($p) : @unlink($p);
        }
        @rmdir($dir);
    }
}
