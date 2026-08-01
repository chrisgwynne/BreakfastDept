<?php

declare(strict_types=1);

namespace Breakfast\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class FaviconTest extends TestCase
{
    public function testSitePublishesACompleteFaviconSet(): void
    {
        $root = dirname(__DIR__, 2);
        $header = (string) file_get_contents($root . '/site/snippets/layouts/header.php');
        $deploy = (string) file_get_contents($root . '/.github/workflows/deploy.yml');
        $webrootConfig = (string) file_get_contents($root . '/deploy/webroot/.htaccess');
        $publicConfig = (string) file_get_contents($root . '/public/.htaccess');
        $deployCommands = array_map(
            static fn (string $line): string => (string) preg_replace('/\s+/', ' ', trim($line)),
            explode("\n", $deploy)
        );

        foreach ([
            '/favicon.ico',
            '/favicon.svg',
            '/apple-touch-icon.png',
            '/site.webmanifest',
        ] as $href) {
            $this->assertStringContainsString($href, $header);
        }

        foreach ([
            'public/favicon.ico',
            'public/favicon.svg',
            'public/apple-touch-icon.png',
            'public/site.webmanifest',
            'public/android-chrome-192x192.png',
            'public/android-chrome-512x512.png',
        ] as $path) {
            $this->assertFileExists($root . '/' . $path);
            $this->assertGreaterThan(0, filesize($root . '/' . $path));
            $this->assertContains(
                'cp ' . $path . ' dist/' . basename($path),
                $deployCommands,
                $path . ' must be copied into the production web root'
            );
        }

        $this->assertStringContainsString(
            '<link rel="icon" href="/favicon.ico" sizes="16x16 32x32 48x48">',
            $header
        );
        foreach ([$webrootConfig, $publicConfig] as $serverConfig) {
            $this->assertStringContainsString(
                'AddType application/manifest+json .webmanifest',
                $serverConfig,
                'Every supported deployment mode must serve the manifest with a JSON manifest MIME type'
            );
        }

        $manifest = json_decode(
            (string) file_get_contents($root . '/public/site.webmanifest'),
            true,
            flags: JSON_THROW_ON_ERROR
        );

        $this->assertSame('Breakfast', $manifest['name'] ?? null);
        $this->assertSame('Breakfast', $manifest['short_name'] ?? null);
        $this->assertSame('/', $manifest['start_url'] ?? null);
        $this->assertSame('/', $manifest['scope'] ?? null);
        $this->assertSame('#f7f2e7', $manifest['background_color'] ?? null);
        $this->assertCount(2, $manifest['icons'] ?? []);
    }
}
