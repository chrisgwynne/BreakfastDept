<?php

declare(strict_types=1);

namespace Breakfast\Tests\Unit;

use Breakfast\Platform\ClientPreviews\PreviewSlug;
use PHPUnit\Framework\TestCase;

final class PreviewSlugTest extends TestCase
{
    public function testNormaliseLowercasesAndHyphenates(): void
    {
        $this->assertSame('acme-shop', PreviewSlug::normalise('  Acme Shop  '));
        $this->assertSame('acme-shop', PreviewSlug::normalise('acme__shop'));
        $this->assertSame('acme-shop', PreviewSlug::normalise('Acme---Shop'));
        $this->assertSame('acme', PreviewSlug::normalise('-acme-'));
        // Non-ASCII is dropped (not transliterated).
        $this->assertSame('caf', PreviewSlug::normalise('café'));
    }

    public function testValidSlugs(): void
    {
        $this->assertTrue(PreviewSlug::isValid('acme'));
        $this->assertTrue(PreviewSlug::isValid('acme-shop-2025'));
        $this->assertTrue(PreviewSlug::isValid('a1'));
    }

    public function testRejectsReservedWords(): void
    {
        foreach (['admin', 'panel', 'api', 'breakfast-admin', 'previews', 'hermes'] as $reserved) {
            $this->assertFalse(PreviewSlug::isValid($reserved), $reserved . ' must be reserved');
            $this->assertSame('reserved', PreviewSlug::validate($reserved)['error']);
        }
    }

    public function testRejectsBadFormats(): void
    {
        // Consecutive hyphens are collapsed by normalise, so the raw form is
        // rejected as not-normalised (and the invalid_format guard also rejects
        // any hand-built slug carrying them).
        $this->assertSame('not_normalised', PreviewSlug::validate('a--b')['error']);
        $this->assertSame('not_normalised', PreviewSlug::validate('a-')['error']);
        $this->assertSame('not_normalised', PreviewSlug::validate('-acme')['error']);
        $this->assertSame('not_normalised', PreviewSlug::validate('Acme')['error']);
        $this->assertSame('too_short', PreviewSlug::validate('a')['error']);
        $this->assertFalse(PreviewSlug::isValid('acme.shop'));
        $this->assertFalse(PreviewSlug::isValid('../etc'));
        $this->assertFalse(PreviewSlug::isValid(str_repeat('a', 70)));
    }
}
