<?php

declare(strict_types=1);

namespace Breakfast\Tests\Integration;

use Breakfast\Platform\Admin\AdminApi;
use Breakfast\Platform\Support\Database;
use Breakfast\Platform\Support\Platform;
use Kirby\Cms\App;
use PHPUnit\Framework\TestCase;

/**
 * Regression: on a host with no migrate step (FTP-only, no queue cron) the CRM
 * database would never get its schema, so logins worked but every data query
 * 500'd — the "Couldn't load this on every page" outage. Booting the platform
 * must self-heal the schema, so a fresh database is usable without any manual
 * `migrate`. This test deliberately does NOT migrate in setUp.
 */
final class SchemaSelfHealTest extends TestCase
{
    private App $kirby;
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $base = dirname(__DIR__, 2);
        $this->tmp = sys_get_temp_dir() . '/bf-selfheal-' . bin2hex(random_bytes(6));
        @mkdir($this->tmp, 0777, true);

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
                    'production' => true,
                    'storageDir' => $this->tmp,
                    // A database path that does not exist yet — nothing migrates it.
                    'dbPath'     => $this->tmp . '/database/crm.sqlite',
                    'mail'       => ['provider' => 'smtp'],
                ],
            ],
        ]);
        // NOTE: intentionally no ->migrator()->migrate() here.
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

    public function testBootCreatesTheSchemaWithoutAManualMigrate(): void
    {
        $this->assertFileDoesNotExist($this->tmp . '/database/crm.sqlite', 'precondition: no DB yet');

        // First platform access (as any request would do) must self-heal.
        $platform = breakfast();

        $this->assertTrue($platform->db()->tableExists('schema_migrations'), 'history table created');
        $this->assertTrue($platform->db()->tableExists('enquiries'), 'CRM schema applied');
        $this->assertFalse($platform->migrator()->hasPending(), 'all migrations applied');
    }

    public function testDataEndpointsWorkImmediatelyOnAFreshDatabase(): void
    {
        $this->kirby->impersonate('kirby');
        $api = new AdminApi($this->kirby, breakfast());

        // These would have 500'd against an unmigrated database.
        foreach (['dashboard', 'enquiries', 'contacts', 'opportunities'] as $path) {
            $this->assertSame(200, $api->handle($path)->code(), "$path works on a self-healed database");
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
