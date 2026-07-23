<?php

declare(strict_types=1);

namespace Breakfast\Tests\Integration;

use Breakfast\Platform\Admin\AdminApi;
use Breakfast\Platform\Admin\DashboardData;
use Breakfast\Platform\Support\Database;
use Breakfast\Platform\Support\Platform;
use Kirby\Cms\App;
use PHPUnit\Framework\TestCase;

/**
 * Regression: in production, a selected-but-unconfigured mail provider (e.g.
 * Brevo without an API key) throws on construction. The dashboard health readout
 * must NOT instantiate the provider just to name it, or every dashboard/
 * operations request 500s and the whole admin shows "Couldn't load this".
 */
final class DashboardHealthTest extends TestCase
{
    private App $kirby;
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $base = dirname(__DIR__, 2);
        $this->tmp = sys_get_temp_dir() . '/bf-health-' . bin2hex(random_bytes(6));
        @mkdir($this->tmp . '/database', 0777, true);

        Database::reset();
        Platform::reset();

        // Production mode + Brevo selected but NO api key — the failing condition.
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
                    'production' => true,
                    'storageDir' => $this->tmp,
                    'dbPath'     => $this->tmp . '/database/crm.sqlite',
                    'mail'       => ['provider' => 'brevo'],
                ],
            ],
        ]);
        breakfast()->migrator()->migrate();
    }

    protected function tearDown(): void
    {
        Database::reset();
        Platform::reset();
        $this->rrmdir($this->tmp);
        App::destroy();
        restore_error_handler();
        restore_exception_handler();
        parent::tearDown();
    }

    public function testSystemHealthDoesNotThrowWithoutMailKey(): void
    {
        $health = (new DashboardData(breakfast()))->systemHealth();

        $this->assertSame('brevo', $health['mail_provider']);
        $this->assertFalse($health['mail_ready'], 'Brevo without a key must report not-ready');
        $this->assertArrayHasKey('queue_depth', $health);
    }

    public function testDashboardAndOperationsReturn200InProduction(): void
    {
        $this->kirby->impersonate('kirby');
        $api = new AdminApi($this->kirby, breakfast());

        foreach (['dashboard', 'operations'] as $path) {
            $res = $api->handle($path);
            $this->assertSame(200, $res->code(), "$path must not 500 when mail is unconfigured");
        }
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
