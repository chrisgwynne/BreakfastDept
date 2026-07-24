<?php

declare(strict_types=1);

namespace Breakfast\Tests\Integration;

use Breakfast\Tests\Support\PlatformTestCase;

/**
 * Preview page-visits become meaningful CRM activity on the linked client's
 * timeline — a first view and a genuine return, never a per-asset entry, and
 * never for an unlinked internal preview.
 */
final class PreviewCrmBridgeTest extends PlatformTestCase
{
    /** @return array{uuid:string, contact:string} */
    private function linkedPreview(string $slug): array
    {
        $contact = $this->platform->contacts()->create(['display_name' => 'Client', 'email' => $slug . '@ex.co']);
        $preview = $this->platform->previews()->create([
            'name' => $slug, 'public_slug' => $slug, 'contact_uuid' => $contact['uuid'],
        ]);
        $path = $this->tmpDir . '/z-' . bin2hex(random_bytes(3)) . '.zip';
        $zip  = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE);
        $zip->addFromString('index.html', '<title>Hi</title><h1>Hi</h1>');
        $zip->addFromString('styles.css', 'body{color:red}');
        $zip->close();
        $r = $this->platform->previewUpload()->uploadZip($preview['uuid'], $path, 'z.zip', null);
        $this->platform->previewPublisher()->publish($preview['uuid'], $r['version']['uuid'], null);

        return ['uuid' => (string) $preview['uuid'], 'contact' => (string) $contact['uuid']];
    }

    public function testFirstPageViewCreatesCrmActivity(): void
    {
        $p = $this->linkedPreview('acme');
        $this->platform->previewResponder()->serve('acme', '', ['ip' => '203.0.113.9']);

        $types = array_column($this->platform->activities()->forEntity('contact', $p['contact']), 'type');
        $this->assertContains('preview.first_viewed', $types);
    }

    public function testAssetRequestsDoNotCreateCrmActivity(): void
    {
        $p = $this->linkedPreview('beta');
        // Request only a CSS asset (never the entry document).
        $this->platform->previewResponder()->serve('beta', 'styles.css', ['ip' => '203.0.113.9']);

        $types = array_column($this->platform->activities()->forEntity('contact', $p['contact']), 'type');
        $this->assertNotContains('preview.first_viewed', $types);
        $this->assertNotContains('preview.viewed', $types);
    }

    public function testRepeatViewWithinQuietPeriodIsNotDuplicated(): void
    {
        $p = $this->linkedPreview('gamma');
        $this->platform->previewResponder()->serve('gamma', '', ['ip' => '203.0.113.9']);
        $this->platform->previewResponder()->serve('gamma', '', ['ip' => '203.0.113.9']);

        $views = array_filter(
            $this->platform->activities()->forEntity('contact', $p['contact']),
            static fn ($a) => in_array((string) $a['type'], ['preview.first_viewed', 'preview.viewed'], true)
        );
        // Only the first view is recorded; the immediate repeat is suppressed.
        $this->assertCount(1, $views);
    }
}
