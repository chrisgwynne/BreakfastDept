<?php

declare(strict_types=1);

namespace Breakfast\Tests\Unit;

use Breakfast\Platform\Support\Env;
use PHPUnit\Framework\TestCase;

/**
 * Security regression (audit): the base Kirby config must stay secure-by-default
 * — the Panel installer fails closed in production, session cookies are Secure
 * in production, and admin sessions carry explicit inactivity + absolute
 * timeouts rather than relying on framework defaults.
 */
final class ConfigHardeningTest extends TestCase
{
    /** @return array<string,mixed> */
    private function loadConfig(string $env): array
    {
        Env::reset(); // clear the static cache so this env takes effect
        putenv('APP_ENV=' . $env);
        $_ENV['APP_ENV'] = $env;
        $config = require dirname(__DIR__, 2) . '/site/config/config.php';
        self::assertIsArray($config);

        return $config;
    }

    protected function tearDown(): void
    {
        putenv('APP_ENV');
        unset($_ENV['APP_ENV']);
        Env::reset();
        parent::tearDown();
    }

    public function testSessionTimeoutsAreExplicitAndTight(): void
    {
        $config = $this->loadConfig('production');
        $session = $config['session'] ?? [];
        $this->assertIsArray($session);
        // Inactivity timeout present and no looser than 30 minutes.
        $this->assertArrayHasKey('timeout', $session);
        $this->assertLessThanOrEqual(1800, (int) $session['timeout']);
        $this->assertGreaterThan(0, (int) $session['timeout']);
        // Absolute normal-session lifetime present and no longer than 2 hours.
        $this->assertArrayHasKey('durationNormal', $session);
        $this->assertLessThanOrEqual(7200, (int) $session['durationNormal']);
    }

    public function testCookiesAreSecureInProductionOnly(): void
    {
        $this->assertTrue((bool) ($this->loadConfig('production')['cookie']['secure'] ?? false));
        $this->assertFalse((bool) ($this->loadConfig('development')['cookie']['secure'] ?? true));
        $this->assertSame('Lax', $this->loadConfig('production')['cookie']['samesite'] ?? null);
    }

    public function testPanelInstallerFailsClosedInProduction(): void
    {
        // No PANEL_INSTALL override → installer is OFF in production, ON in dev.
        putenv('PANEL_INSTALL');
        unset($_ENV['PANEL_INSTALL']);
        $this->assertFalse((bool) ($this->loadConfig('production')['panel']['install'] ?? true));
        putenv('PANEL_INSTALL');
        unset($_ENV['PANEL_INSTALL']);
        $this->assertTrue((bool) ($this->loadConfig('development')['panel']['install'] ?? false));
    }

    public function testDebugIsOffInProduction(): void
    {
        $this->assertFalse((bool) ($this->loadConfig('production')['debug'] ?? true));
    }
}
