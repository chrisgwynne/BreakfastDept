<?php

declare(strict_types=1);

namespace Breakfast\Tests\Unit;

use Breakfast\Platform\Security\Hash;
use PHPUnit\Framework\TestCase;

final class HashTest extends TestCase
{
    protected function setUp(): void
    {
        Hash::setKey('test-key');
    }
    public function testIpHashDeterministicAndNonReversible(): void
    {
        $a = Hash::ip('203.0.113.7');
        $b = Hash::ip('203.0.113.7');
        $this->assertSame($a, $b);
        $this->assertStringNotContainsString('203.0.113.7', $a);
        $this->assertNotSame($a, Hash::ip('203.0.113.8'));
    }
    public function testUnknownIp(): void
    {
        $this->assertSame('unknown', Hash::ip(null));
    }
}
