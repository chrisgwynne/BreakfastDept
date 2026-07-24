<?php

declare(strict_types=1);

namespace Breakfast\Tests\Unit;

use Breakfast\Platform\Security\SecurityHeaders;
use Breakfast\Platform\Settings\SecretBox;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Regression tests for security-hardening fixes found during the platform
 * security audit.
 */
final class SecurityHardeningTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir() . '/bf-sec-' . bin2hex(random_bytes(6));
        @mkdir($this->tmp, 0777, true);
    }

    protected function tearDown(): void
    {
        putenv('APP_ENV');
        putenv('PLATFORM_SECRET_KEY');
        $this->rrmdir($this->tmp);
        parent::tearDown();
    }

    /**
     * SecretBox must fail closed in production when no key is provided, rather
     * than silently generating a local key file that an ephemeral filesystem
     * could regenerate (rendering stored ciphertext undecryptable).
     */
    public function testSecretBoxRefusesGeneratedKeyInProduction(): void
    {
        putenv('APP_ENV=production');
        putenv('PLATFORM_SECRET_KEY'); // ensure unset
        $this->expectException(RuntimeException::class);
        new SecretBox($this->tmp);
    }

    public function testSecretBoxUsesProvidedKeyInProduction(): void
    {
        putenv('APP_ENV=production');
        $key = base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
        $box = new SecretBox($this->tmp, $key);
        $this->assertSame('hello', $box->decrypt($box->encrypt('hello')));
    }

    public function testSecretBoxGeneratesLocalKeyInDevelopment(): void
    {
        putenv('APP_ENV=development');
        putenv('PLATFORM_SECRET_KEY');
        $box = new SecretBox($this->tmp);
        $this->assertSame('hello', $box->decrypt($box->encrypt('hello')));
        $this->assertFileExists($this->tmp . '/secret.key');
    }

    /**
     * The app-level CSP for first-party pages must forbid framing, object/base
     * hijacking, and must not permit 'unsafe-inline' scripts.
     */
    public function testSecurityHeadersCspIsStrict(): void
    {
        $csp = (new SecurityHeaders(true))->csp();
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
        $this->assertStringContainsString("base-uri 'self'", $csp);
        $this->assertStringNotContainsString("'unsafe-inline' 'unsafe-eval'", $csp);
        // script-src must not contain unsafe-inline (nonce-based instead).
        $this->assertMatchesRegularExpression("/script-src [^;]*'nonce-/", $csp);
        $this->assertDoesNotMatchRegularExpression("/script-src [^;]*'unsafe-inline'/", $csp);
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
