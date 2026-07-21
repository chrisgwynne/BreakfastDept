<?php

declare(strict_types=1);

namespace Breakfast\Tests\Integration;

use Breakfast\Platform\ClientPreviews\PreviewResponder;
use Breakfast\Platform\ClientPreviews\PreviewStatus;
use Breakfast\Tests\Support\PlatformTestCase;

/**
 * The preview responder emits the full security-header set, gates protected
 * previews, and never sets an admin cookie.
 */
final class PreviewResponderTest extends PlatformTestCase
{
    private function publishedPreview(string $slug): string
    {
        $preview = $this->platform->previews()->create(['name' => $slug, 'public_slug' => $slug]);
        $path    = $this->tmpDir . '/z-' . bin2hex(random_bytes(3)) . '.zip';
        $zip     = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE);
        $zip->addFromString('index.html', '<title>Hi</title><h1>Hi</h1>');
        $zip->close();
        $r = $this->platform->previewUpload()->uploadZip($preview['uuid'], $path, 'z.zip', null);
        $this->platform->previewPublisher()->publish($preview['uuid'], $r['version']['uuid'], null);

        return $preview['uuid'];
    }

    public function testServeEmitsSecurityHeadersAndNoCookie(): void
    {
        $this->publishedPreview('acme');
        $res = $this->platform->previewResponder()->serve('acme', '', ['ip' => '203.0.113.1']);

        $this->assertSame(200, $res['status']);
        $this->assertArrayHasKey('file', $res);
        $h = $res['headers'];
        $this->assertSame('nosniff', $h['X-Content-Type-Options']);
        $this->assertSame('no-referrer', $h['Referrer-Policy']);
        $this->assertSame('same-origin', $h['Cross-Origin-Opener-Policy']);
        $this->assertSame('same-origin', $h['Cross-Origin-Resource-Policy']);
        $this->assertStringContainsString('noindex', $h['X-Robots-Tag']);
        $this->assertStringContainsString("object-src 'none'", $h['Content-Security-Policy']);
        $this->assertStringContainsString("worker-src 'none'", $h['Content-Security-Policy']);
        $this->assertStringContainsString("form-action 'self'", $h['Content-Security-Policy']);
        $this->assertStringContainsString("frame-ancestors 'self'", $h['Content-Security-Policy']);
        $this->assertArrayNotHasKey('Set-Cookie', $h, 'no cookie for a public serve');
        $this->assertArrayNotHasKey('cookie', $res);
    }

    public function testRobotsDisallowsEverything(): void
    {
        $res = $this->platform->previewResponder()->robots();
        $this->assertStringContainsString('Disallow: /', $res['body']);
        $this->assertStringContainsString('noindex', $res['headers']['X-Robots-Tag']);
    }

    public function testPasswordGateAndHostOnlyCookie(): void
    {
        $uuid = $this->publishedPreview('locked');
        $this->platform->previewPasswords()->setPassword($uuid, 'open-sesame', null);

        // No token → gate (401), no file.
        $gate = $this->platform->previewResponder()->serve('locked', '', ['ip' => '203.0.113.1']);
        $this->assertSame(401, $gate['status']);
        $this->assertArrayNotHasKey('file', $gate);
        $this->assertStringContainsString('password', strtolower($gate['body']));

        // Wrong unlock → 401 gate again.
        $bad = $this->platform->previewResponder()->unlock('locked', 'nope', '203.0.113.1');
        $this->assertSame(401, $bad['status']);
        $this->assertArrayNotHasKey('cookie', $bad);

        // Correct unlock → redirect + host-only session cookie for THIS preview.
        $ok = $this->platform->previewResponder()->unlock('locked', 'open-sesame', '203.0.113.1');
        $this->assertSame(303, $ok['status']);
        $this->assertArrayHasKey('cookie', $ok);
        $this->assertSame(PreviewResponder::cookieName('locked'), $ok['cookie']['name']);
        $this->assertNotEmpty($ok['cookie']['value']);
    }

    public function testDisabledServesNeutralNoFile(): void
    {
        $uuid = $this->publishedPreview('gone');
        $this->platform->previews()->update($uuid, ['status' => PreviewStatus::DISABLED]);

        $res = $this->platform->previewResponder()->serve('gone', '', ['ip' => '203.0.113.1']);
        $this->assertSame(403, $res['status']);
        $this->assertArrayNotHasKey('file', $res);
        $this->assertStringNotContainsString('gone', $res['body'], 'neutral page leaks no slug/client detail');
    }
}
