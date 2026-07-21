<?php

declare(strict_types=1);

namespace Breakfast\Tests\Unit;

use Breakfast\Platform\Hermes\Signer;
use PHPUnit\Framework\TestCase;

final class SignerTest extends TestCase
{
    public function testSignAndVerifyRoundTrip(): void
    {
        $canonical = Signer::canonical('POST', '/crm/tasks', '1700000000', 'nonce-1', '{"title":"x"}');
        $sig = Signer::sign('secret', $canonical);

        $this->assertTrue(Signer::verify('secret', $canonical, $sig));
    }

    public function testTamperedBodyFails(): void
    {
        $c1 = Signer::canonical('POST', '/crm/tasks', '1700000000', 'n', '{"title":"x"}');
        $c2 = Signer::canonical('POST', '/crm/tasks', '1700000000', 'n', '{"title":"HACKED"}');
        $sig = Signer::sign('secret', $c1);

        $this->assertFalse(Signer::verify('secret', $c2, $sig));
    }

    public function testWrongSecretFails(): void
    {
        $c = Signer::canonical('GET', '/health', '1700000000', 'n', '');
        $this->assertFalse(Signer::verify('other', $c, Signer::sign('secret', $c)));
    }

    public function testCanonicalIsStable(): void
    {
        $a = Signer::canonical('get', '/x', '1', 'n', 'body');
        $b = Signer::canonical('GET', '/x', '1', 'n', 'body');
        $this->assertSame($a, $b, 'method is upper-cased for a stable canonical string');
    }
}
