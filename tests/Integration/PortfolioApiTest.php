<?php

declare(strict_types=1);

namespace Breakfast\Tests\Integration;

use Breakfast\Platform\Admin\AdminApi;
use Breakfast\Platform\Support\Database;
use Breakfast\Platform\Support\Platform;
use Kirby\Cms\App;
use Kirby\Http\Request;
use Kirby\Http\Response;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Portfolio Admin API (CP3): authentication + CSRF enforcement, the record
 * lifecycle over HTTP, transitions, validation, duplicate and homepage
 * selection. Mirrors the reflection-based request seeding used across the
 * AdminApi suite (Kirby captures headers from $_SERVER at construction).
 */
final class PortfolioApiTest extends TestCase
{
    private const CSRF = 'test-csrf-portfolio-abc';

    private App $kirby;
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir() . '/bf-pfapi-' . bin2hex(random_bytes(6));
        @mkdir($this->tmp . '/database', 0777, true);
    }

    protected function tearDown(): void
    {
        unset($_SERVER['HTTP_X_CSRF_TOKEN']);
        try {
            if (isset($this->kirby)) {
                $this->kirby->session()->destroy();
            }
        } catch (\Throwable) {
        }
        Database::reset();
        Platform::reset();
        App::destroy();
        $this->rrmdir($this->tmp);
        restore_error_handler();
        restore_exception_handler();
        parent::tearDown();
    }

    private function boot(bool $auth = true, bool $withCsrf = true): void
    {
        if ($withCsrf) {
            $_SERVER['HTTP_X_CSRF_TOKEN'] = self::CSRF;
        } else {
            unset($_SERVER['HTTP_X_CSRF_TOKEN']);
        }
        $base = dirname(__DIR__, 2);
        Database::reset();
        Platform::reset();
        $this->kirby = new App([
            'roots' => [
                'index' => $base . '/public', 'base' => $base, 'site' => $base . '/site',
                'content' => $base . '/content', 'storage' => $this->tmp,
                'sessions' => $this->tmp . '/sessions', 'accounts' => $this->tmp . '/accounts',
            ],
            'options' => [
                'debug' => false, 'whoops' => false,
                'breakfast' => [
                    'production' => false, 'storageDir' => $this->tmp,
                    'dbPath' => $this->tmp . '/database/crm.sqlite',
                    'previews' => ['storageDir' => $this->tmp . '/client-previews', 'limits' => []],
                    'mail' => ['provider' => 'fake'],
                ],
            ],
        ]);
        breakfast()->migrator()->migrate();
        if ($auth) {
            $this->kirby->impersonate('kirby');
            $this->kirby->session()->set('kirby.csrf', self::CSRF);
        }
    }

    /** @param array<string,mixed> $body */
    private function call(string $method, string $path, array $body = []): array
    {
        $request = new Request(['body' => $body, 'query' => $body, 'method' => $method]);
        $prop = new ReflectionProperty(App::class, 'request');
        $prop->setAccessible(true);
        $prop->setValue($this->kirby, $request);
        $res = (new AdminApi($this->kirby, breakfast()))->handle($path);
        $this->assertInstanceOf(Response::class, $res);
        $decoded = json_decode((string) $res->body(), true);

        return ['code' => $res->code(), 'data' => is_array($decoded['data'] ?? null) ? $decoded['data'] : (is_array($decoded) ? $decoded : [])];
    }

    public function testUnauthenticatedIsRejected(): void
    {
        $this->boot(auth: false);
        $this->assertSame(401, $this->call('GET', 'portfolio')['code']);
    }

    public function testCreateListFindUpdateFlow(): void
    {
        $this->boot();
        $created = $this->call('POST', 'portfolio', ['internal_name' => 'Passo', 'display_title' => 'Passo']);
        $this->assertSame(200, $created['code']);
        $uuid = (string) $created['data']['uuid'];
        $this->assertSame('draft', $created['data']['status']);

        $list = $this->call('GET', 'portfolio');
        $this->assertSame(200, $list['code']);
        $this->assertArrayHasKey('counts', $list['data']);
        $this->assertGreaterThanOrEqual(1, count($list['data']['items']));

        $found = $this->call('GET', 'portfolio/' . $uuid);
        $this->assertSame(200, $found['code']);
        $this->assertArrayHasKey('story', $found['data']);

        $updated = $this->call('PATCH', 'portfolio/' . $uuid, ['summary' => 'A booking-first site.', 'revision' => 1]);
        $this->assertSame(200, $updated['code']);
        $this->assertSame(2, (int) $updated['data']['revision']);
    }

    public function testMutationRequiresCsrf(): void
    {
        $this->boot(auth: true, withCsrf: false);
        $out = $this->call('POST', 'portfolio', ['internal_name' => 'X']);
        $this->assertSame(403, $out['code']);
    }

    public function testValidateAndBlockedPublishThenTransitions(): void
    {
        $this->boot();
        $created = $this->call('POST', 'portfolio', ['internal_name' => 'Passo', 'display_title' => 'Passo', 'client_display_name' => 'Passo', 'summary' => 'Bookings on the site.']);
        $uuid = (string) $created['data']['uuid'];

        // Validation surfaces blockers (no services / approved image / story yet).
        $validate = $this->call('GET', 'portfolio/' . $uuid . '/validate');
        $this->assertSame(200, $validate['code']);
        $this->assertNotEmpty($validate['data']['blockers']);

        // Move draft → internal_review → ready via the action endpoints.
        $this->assertSame(200, $this->call('POST', 'portfolio/' . $uuid . '/submit-review')['code']);
        $this->assertSame(200, $this->call('POST', 'portfolio/' . $uuid . '/mark-ready')['code']);
        // Publishing is blocked by validation (422).
        $this->assertSame(422, $this->call('POST', 'portfolio/' . $uuid . '/publish')['code']);

        // History exposes the editorial events.
        $history = $this->call('GET', 'portfolio/' . $uuid . '/history');
        $this->assertSame(200, $history['code']);
        $this->assertNotEmpty($history['data']['events']);
    }

    public function testDuplicateCreatesDraftCopy(): void
    {
        $this->boot();
        $created = $this->call('POST', 'portfolio', ['internal_name' => 'Passo', 'slug' => 'passo']);
        $uuid = (string) $created['data']['uuid'];
        $dup = $this->call('POST', 'portfolio/' . $uuid . '/duplicate');
        $this->assertSame(200, $dup['code']);
        $this->assertNotSame($uuid, $dup['data']['uuid']);
        $this->assertSame('draft', $dup['data']['status']);
        $this->assertStringStartsWith('passo-copy', (string) $dup['data']['slug']);
    }

    public function testHomepageSelectionRejectsUnpublished(): void
    {
        $this->boot();
        $created = $this->call('POST', 'portfolio', ['internal_name' => 'Passo']);
        $uuid = (string) $created['data']['uuid'];
        // A draft can't be featured.
        $out = $this->call('POST', 'portfolio/homepage', ['order' => [$uuid]]);
        $this->assertSame(422, $out['code']);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
