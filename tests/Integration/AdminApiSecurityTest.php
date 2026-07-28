<?php

declare(strict_types=1);

namespace Breakfast\Tests\Integration;

use Breakfast\Platform\Admin\AdminApi;
use Breakfast\Platform\Support\Platform;
use Kirby\Cms\App;
use Kirby\Http\Request;
use Kirby\Http\Response;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Security-hardening regression tests for the admin API (audit).
 *
 *  - Logout (DELETE /session) is CSRF-protected: a mutation without a valid
 *    token is refused even for an authenticated user (defence in depth beyond
 *    the SameSite=Lax cookie).
 *  - Vault step-up re-authentication (POST /vault/reauth) is rate-limited so an
 *    attacker riding an admin session cannot brute-force the reveal grant —
 *    the throttle sits IN FRONT OF password validation.
 *
 * Kirby captures request headers from $_SERVER at App construction, so the
 * CSRF header is seeded before the App is built and the session token is
 * pinned to match it.
 */
final class AdminApiSecurityTest extends TestCase
{
    private const CSRF = 'test-csrf-token-abc123';

    private App $kirby;
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir() . '/bf-apisec-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        unset($_SERVER['HTTP_X_CSRF_TOKEN']);
        // Destroy the (dirtied) session so it isn't re-committed to a removed
        // directory at shutdown, then tear the app down.
        try {
            if (isset($this->kirby)) {
                $this->kirby->session()->destroy();
            }
        } catch (\Throwable) {
            // already gone
        }
        Platform::reset();
        App::destroy();
        $this->rrmdir($this->tmp);
        restore_error_handler();
        restore_exception_handler();
        parent::tearDown();
    }

    /**
     * Build a Kirby app as an authenticated admin. When $withCsrf is true the
     * request carries a valid X-CSRF-Token header matching the session token.
     */
    private function boot(bool $withCsrf): void
    {
        if ($withCsrf) {
            $_SERVER['HTTP_X_CSRF_TOKEN'] = self::CSRF;
        } else {
            unset($_SERVER['HTTP_X_CSRF_TOKEN']);
        }

        $base = dirname(__DIR__, 2);
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
                    'previews'   => ['storageDir' => $this->tmp . '/client-previews', 'limits' => []],
                    'mail'       => ['provider' => 'fake'],
                ],
            ],
        ]);
        $this->kirby->impersonate('kirby');
        // Pin the session CSRF token to the seeded header so csrf() validates.
        $this->kirby->session()->set('kirby.csrf', self::CSRF);
    }

    /**
     * Seed the memoised request (method + body) for the next handle() call. The
     * CSRF header itself comes from the environment captured at boot().
     *
     * @param array<string,mixed> $body
     */
    private function seedRequest(string $method, array $body = []): void
    {
        $request = new Request(['body' => $body, 'method' => $method]);
        $prop = new ReflectionProperty(App::class, 'request');
        $prop->setAccessible(true);
        $prop->setValue($this->kirby, $request);
    }

    /** @return array<string,mixed> */
    private function decode(Response $res): array
    {
        $body = json_decode((string) $res->body(), true);

        return is_array($body) ? $body : [];
    }

    public function testLogoutWithoutCsrfIsRefused(): void
    {
        $this->boot(false);
        $api = new AdminApi($this->kirby, breakfast());
        $this->seedRequest('DELETE');
        $res = $api->handle('session');
        $this->assertSame(403, $res->code());
        $this->assertSame('csrf', $this->decode($res)['code'] ?? null);
    }

    public function testLogoutWithValidCsrfSucceeds(): void
    {
        $this->boot(true);
        $api = new AdminApi($this->kirby, breakfast());
        $this->seedRequest('DELETE');
        $res = $api->handle('session');
        $this->assertSame(200, $res->code());
        $this->assertTrue($this->decode($res)['data']['ok'] ?? false);
    }

    public function testVaultReauthIsRateLimitedBeforePasswordCheck(): void
    {
        $this->boot(true);
        $api = new AdminApi($this->kirby, breakfast());

        // 5 attempts with a wrong password each return 401 (auth failure); the
        // 6th is blocked with 429 BEFORE the password is even checked.
        $codes = [];
        for ($i = 0; $i < 6; $i++) {
            $this->seedRequest('POST', ['password' => 'definitely-wrong']);
            $codes[] = $api->handle('vault/reauth')->code();
        }

        $this->assertSame([401, 401, 401, 401, 401, 429], $codes);
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
