<?php

declare(strict_types=1);

namespace Breakfast\Tests\Integration;

use Breakfast\Platform\Seo\StructuredData;
use Breakfast\Platform\Support\Database;
use Breakfast\Platform\Support\Platform;
use Kirby\Cms\App;
use PHPUnit\Framework\TestCase;

/**
 * Locks the pre-launch SEO fixes so they can't silently regress:
 * the home page must be indexable (it is unlisted by Kirby convention), it must
 * appear in the sitemap, noindexed pages must still be "follow", the default
 * social image must resolve, and the structured data must be internally
 * consistent (ISO country, single brand name).
 */
final class SeoLaunchTest extends TestCase
{
    private App $kirby;
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $base = dirname(__DIR__, 2);
        $this->tmp = sys_get_temp_dir() . '/bf-seo-' . bin2hex(random_bytes(6));
        @mkdir($this->tmp . '/database', 0777, true);

        Database::reset();
        Platform::reset();

        $this->kirby = new App([
            'roots' => [
                'index'    => $base . '/public',
                'base'     => $base,
                'site'     => $base . '/site',
                'content'  => $base . '/content',
                'storage'  => $this->tmp,
                'sessions' => $this->tmp . '/sessions',
                'accounts' => $this->tmp . '/accounts',
            ],
            'options' => ['debug' => false, 'whoops' => false, 'breakfast' => ['production' => true, 'storageDir' => $this->tmp, 'dbPath' => $this->tmp . '/database/crm.sqlite', 'mail' => ['provider' => 'fake']]],
        ]);
    }

    protected function tearDown(): void
    {
        Database::reset();
        Platform::reset();
        $this->rrmdir($this->tmp);
        App::destroy();
        restore_error_handler();
        restore_exception_handler();
        parent::tearDown();
    }

    public function testHomePageIsIndexable(): void
    {
        $home = $this->kirby->site()->homePage();
        $this->assertNotNull($home);
        $this->assertSame('index, follow', $home->seoMeta()->robots());
    }

    public function testNoindexedPagesAreStillFollowable(): void
    {
        $privacy = $this->kirby->page('privacy');
        $this->assertNotNull($privacy);
        // noindex is fine for a utility page — but never "nofollow".
        $this->assertSame('noindex, follow', $privacy->seoMeta()->robots());
    }

    public function testSitemapContainsTheHomePage(): void
    {
        $xml = snippet('seo/sitemap', [], true);
        $homeUrl = $this->kirby->site()->homePage()->url();
        $this->assertStringContainsString('<loc>' . $homeUrl . '</loc>', $xml);
        $this->assertStringContainsString('<priority>1.0</priority>', $xml);
    }

    public function testRobotsTxtAllowsCrawlingInProduction(): void
    {
        $txt = snippet('seo/robots', [], true);
        $this->assertStringContainsString('Allow: /', $txt);
        $this->assertStringNotContainsString("Disallow: /\n", $txt);
    }

    public function testHomeHasADefaultSocialImage(): void
    {
        $og = $this->kirby->site()->homePage()->seoMeta()->ogImage();
        $this->assertNotNull($og);
        $this->assertStringContainsString('og-default', (string) $og);
    }

    public function testStructuredDataIsConsistent(): void
    {
        $business = (new StructuredData($this->kirby->site()))->business();
        $this->assertSame('Breakfast', $business['name']);
        $this->assertSame('GB', $business['address']['addressCountry'] ?? null);
        $this->assertArrayNotHasKey('telephone', $business, 'no phone is published by design');
    }

    public function testServiceTitlesCarryKeywordAndLocation(): void
    {
        $service = $this->kirby->page('services/new-website');
        $this->assertNotNull($service);
        $this->assertStringContainsString('North Wales', $service->seoMeta()->title());
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $p = $dir . '/' . $item;
            is_dir($p) ? $this->rrmdir($p) : @unlink($p);
        }
        @rmdir($dir);
    }
}
