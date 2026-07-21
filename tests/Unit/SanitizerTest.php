<?php

declare(strict_types=1);

namespace Breakfast\Tests\Unit;

use Breakfast\Platform\Forms\Sanitizer;
use PHPUnit\Framework\TestCase;

final class SanitizerTest extends TestCase
{
    public function testHeaderInjectionStripped(): void
    {
        $dirty = "Real Name\r\nBcc: victim@example.com";
        $clean = Sanitizer::headerSafe($dirty);
        // Header injection depends on CR/LF; those must be gone.
        $this->assertStringNotContainsString("\r", $clean);
        $this->assertStringNotContainsString("\n", $clean);
    }

    public function testEncodedNewlinesStripped(): void
    {
        $this->assertStringNotContainsString('%0a', strtolower(Sanitizer::headerSafe('x%0aBcc: y')));
    }

    public function testEmailLowercased(): void
    {
        $this->assertSame('a@b.co', Sanitizer::email('  A@B.CO '));
    }

    public function testControlCharsRemoved(): void
    {
        $this->assertSame('abc', Sanitizer::text("a\x00b\x07c"));
    }

    public function testPayloadFlattensArrays(): void
    {
        $out = Sanitizer::payload(['services' => ['Web', 'Shop']]);
        $this->assertSame('Web, Shop', $out['services']);
    }
}
