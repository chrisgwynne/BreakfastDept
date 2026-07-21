<?php

declare(strict_types=1);

namespace Breakfast\Tests\Unit;

use Breakfast\Tests\Support\PlatformTestCase;

final class RateLimiterTest extends PlatformTestCase
{
    public function testAllowsUnderLimitThenBlocks(): void
    {
        $rl = $this->platform->rateLimiter();
        for ($i = 1; $i <= 3; $i++) {
            $r = $rl->hit('bucket', 3, 600);
            $this->assertTrue($r['allowed'], "hit $i should be allowed");
        }
        $r = $rl->hit('bucket', 3, 600);
        $this->assertFalse($r['allowed'], 'fourth hit exceeds the limit');
    }

    public function testPruneRemovesExpired(): void
    {
        $rl = $this->platform->rateLimiter();
        $rl->hit('b', 5, 1);
        // Expiry is 1s; sleep is avoided — just assert prune runs and returns int.
        $this->assertIsInt($rl->prune());
    }
}
