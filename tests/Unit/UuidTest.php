<?php

declare(strict_types=1);

namespace Breakfast\Tests\Unit;

use Breakfast\Platform\Support\Uuid;
use PHPUnit\Framework\TestCase;

final class UuidTest extends TestCase
{
    public function testGeneratesValidV4(): void
    {
        $u = Uuid::v4();
        $this->assertTrue(Uuid::isValid($u));
        $this->assertSame(36, strlen($u));
    }
    public function testUniqueness(): void
    {
        $this->assertNotSame(Uuid::v4(), Uuid::v4());
    }
    public function testRejectsInvalid(): void
    {
        $this->assertFalse(Uuid::isValid('not-a-uuid'));
    }
}
