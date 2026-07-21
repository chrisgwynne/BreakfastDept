<?php

declare(strict_types=1);

namespace Breakfast\Tests\Unit;

use Breakfast\Platform\Support\ReadingTime;
use PHPUnit\Framework\TestCase;

final class ReadingTimeTest extends TestCase
{
    public function testEmptyIsZero(): void
    {
        $this->assertSame(0, ReadingTime::minutes(''));
    }
    public function testMinimumOne(): void
    {
        $this->assertSame(1, ReadingTime::minutes('a few words here'));
    }
    public function testScalesWithLength(): void
    {
        $text = str_repeat('word ', 440);
        $this->assertSame(2, ReadingTime::minutes($text, 220));
    }
    public function testStripsTags(): void
    {
        $this->assertSame(1, ReadingTime::minutes('<p>hello <strong>world</strong></p>'));
    }
}
