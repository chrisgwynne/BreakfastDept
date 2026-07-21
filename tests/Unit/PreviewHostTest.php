<?php

declare(strict_types=1);

namespace Breakfast\Tests\Unit;

use Breakfast\Platform\ClientPreviews\PreviewHost;
use Breakfast\Platform\ClientPreviews\PreviewUrlGenerator;
use PHPUnit\Framework\TestCase;

final class PreviewHostTest extends TestCase
{
    private const BASE = 'preview.breakfastdept.com';

    public function testExtractsSlugFromValidSubdomain(): void
    {
        $this->assertSame('acme', PreviewHost::slugFromHost('acme.' . self::BASE, self::BASE));
        $this->assertSame('acme-shop', PreviewHost::slugFromHost('acme-shop.' . self::BASE, self::BASE));
        // Case-insensitive host, port stripped.
        $this->assertSame('acme', PreviewHost::slugFromHost('ACME.' . self::BASE . ':8080', self::BASE));
    }

    public function testRejectsApexMultiLevelAndInvalid(): void
    {
        $this->assertNull(PreviewHost::slugFromHost(self::BASE, self::BASE), 'apex');
        $this->assertNull(PreviewHost::slugFromHost('a.b.' . self::BASE, self::BASE), 'multi-level');
        $this->assertNull(PreviewHost::slugFromHost('acme.evil.com', self::BASE), 'wrong base');
        $this->assertNull(PreviewHost::slugFromHost('', self::BASE), 'empty');
        // Reserved words are not valid slugs, so cannot be a subdomain.
        $this->assertNull(PreviewHost::slugFromHost('api.' . self::BASE, self::BASE));
        $this->assertNull(PreviewHost::slugFromHost('admin.' . self::BASE, self::BASE));
        // A label that is not a valid slug (leading hyphen) is rejected.
        $this->assertNull(PreviewHost::slugFromHost('-acme.' . self::BASE, self::BASE));
    }

    public function testIsPreviewZone(): void
    {
        $this->assertTrue(PreviewHost::isPreviewZone('acme.' . self::BASE, self::BASE));
        $this->assertTrue(PreviewHost::isPreviewZone(self::BASE, self::BASE), 'apex is in the zone');
        $this->assertTrue(PreviewHost::isPreviewZone('acme.' . self::BASE . ':8000', self::BASE));
        $this->assertFalse(PreviewHost::isPreviewZone('breakfastdept.com', self::BASE));
        $this->assertFalse(PreviewHost::isPreviewZone('acme.evil.com', self::BASE));
        $this->assertFalse(PreviewHost::isPreviewZone('acme.' . self::BASE, ''));
    }

    public function testUrlGeneratorSubdomainMode(): void
    {
        $urls = new PreviewUrlGenerator(['wildcardBase' => self::BASE, 'scheme' => 'https']);
        $this->assertTrue($urls->subdomainMode());
        $this->assertTrue($urls->isPerPreviewIsolated());
        $this->assertTrue($urls->hasIsolatedOrigin());
        $this->assertSame('https://acme.' . self::BASE . '/', $urls->url('ACME'));
        $this->assertSame('acme.' . self::BASE, $urls->canonicalHost('acme'));
    }

    public function testUrlGeneratorFallsBackToPathModes(): void
    {
        $single = new PreviewUrlGenerator(['baseUrl' => 'https://preview.example.com']);
        $this->assertFalse($single->subdomainMode());
        $this->assertFalse($single->isPerPreviewIsolated());
        $this->assertTrue($single->hasIsolatedOrigin());
        $this->assertSame('https://preview.example.com/acme/', $single->url('acme'));

        $dev = new PreviewUrlGenerator(['devPrefix' => '_preview']);
        $this->assertFalse($dev->hasIsolatedOrigin());
        $this->assertStringContainsString('/_preview/acme/', $dev->url('acme'));
    }
}
