<?php

declare(strict_types=1);

namespace Breakfast\Tests\Integration;

use Breakfast\Platform\Portfolio\PortfolioStatus;
use Breakfast\Platform\Support\Database;
use Breakfast\Platform\Support\Platform;
use Breakfast\Platform\Support\Uuid;
use Kirby\Cms\App;
use Kirby\Cms\Page;
use PHPUnit\Framework\TestCase;

/**
 * Public rendering (CP6): the /work listing and /work/{slug} case-study
 * templates render from published snapshots via virtual pages, contain the
 * public content, and never leak private canaries into the public HTML.
 */
final class PortfolioPublicRenderTest extends TestCase
{
    private const CANARY_INTERNAL = 'INTERNAL-CANARY-Acme-7f3';
    private const CANARY_UNAPPROVED = 'UNAPPROVED-MEDIA-CANARY';

    private App $kirby;
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $base = dirname(__DIR__, 2);
        $this->tmp = sys_get_temp_dir() . '/bf-pfrender-' . bin2hex(random_bytes(6));
        @mkdir($this->tmp . '/database', 0777, true);
        Database::reset();
        Platform::reset();
        $this->kirby = new App([
            'roots' => [
                'index' => $base . '/public', 'base' => $base, 'site' => $base . '/site',
                'content' => $base . '/content', 'storage' => $this->tmp,
                'sessions' => $this->tmp . '/sessions', 'accounts' => $this->tmp . '/accounts',
            ],
            'options' => ['debug' => false, 'whoops' => false, 'breakfast' => [
                'production' => false, 'storageDir' => $this->tmp,
                'dbPath' => $this->tmp . '/database/crm.sqlite',
                'previews' => ['storageDir' => $this->tmp . '/cp', 'limits' => []],
                'mail' => ['provider' => 'fake'],
            ]],
        ]);
        breakfast()->migrator()->migrate();
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

    private function seedPublished(): array
    {
        $svc = breakfast()->portfolio();
        $rec = $svc->create([
            'internal_name' => self::CANARY_INTERNAL, 'slug' => 'passo',
            'display_title' => 'A booking-first site for Passo', 'client_display_name' => 'Passo',
            'summary' => 'Bookings on the site, not in DMs.', 'industry' => 'Hospitality', 'location' => 'Conwy',
            'website_url' => 'https://example.test', 'company_uuid' => Uuid::v4(),
        ], 'seed');
        $u = $rec['uuid'];
        $t = $svc->createTaxonomy(['kind' => 'service', 'label' => 'Website design', 'service_slug' => 'new-website'], 'seed');
        $svc->setRecordTaxonomies($u, [$t['uuid']], 'seed');
        $m = $svc->attachMedia($u, ['role' => 'cover', 'alt' => 'Passo homepage', 'variants' => ['card' => '/media/x/card.webp'], 'width' => 1200, 'height' => 750], 'seed');
        $svc->approveMedia($m['uuid'], true, ['rights_source' => 'client'], 'seed');
        breakfast()->db()->run('UPDATE portfolio_records SET cover_media_uuid = :m WHERE uuid = :u', ['m' => $m['uuid'], 'u' => $u]);
        // An unapproved image whose alt is a canary — must not appear publicly.
        $svc->attachMedia($u, ['role' => 'gallery', 'alt' => self::CANARY_UNAPPROVED], 'seed');
        $svc->upsertStorySection($u, 'problem', ['heading' => 'The problem', 'body' => '<p>Bookings were lost in DMs.</p>'], 'seed');
        $svc->addResult($u, ['label' => 'Easier for customers to book', 'is_numeric' => false], 'seed');
        $svc->addBlock($u, ['type' => 'quote', 'content' => ['text' => 'The best decision we made.']], 'seed');
        $svc->transition($u, PortfolioStatus::INTERNAL_REVIEW, 'seed');
        $svc->transition($u, PortfolioStatus::READY, 'seed');
        $svc->transition($u, PortfolioStatus::PUBLISHED, 'seed');

        return $svc->publicBySlug('passo');
    }

    public function testCaseStudyRendersPublicContentWithoutLeaks(): void
    {
        $pf = $this->seedPublished();
        $this->assertIsArray($pf);
        $page = Page::factory([
            'slug' => 'passo', 'template' => 'portfolio-case',
            'content' => ['title' => $pf['display_title'], 'seo_title' => $pf['seo']['title'], 'meta_description' => $pf['seo']['description']],
        ]);
        $html = $page->render(['pf' => $pf, 'canonical' => 'https://x/work/passo', 'related' => [], 'nextwork' => null]);

        // Public content present.
        $this->assertStringContainsString('A booking-first site for Passo', $html);
        $this->assertStringContainsString('Bookings were lost in DMs', $html);
        $this->assertStringContainsString('Easier for customers to book', $html);
        $this->assertStringContainsString('application/ld+json', $html); // structured data
        $this->assertStringContainsString('CreativeWork', $html);

        // Private canaries absent from the public HTML.
        $this->assertStringNotContainsString(self::CANARY_INTERNAL, $html);
        $this->assertStringNotContainsString(self::CANARY_UNAPPROVED, $html);
    }

    public function testWorkListingRenders(): void
    {
        $this->seedPublished();
        $items = breakfast()->portfolio()->publicList();
        $page = Page::factory(['slug' => 'work', 'template' => 'portfolio-work', 'content' => ['title' => 'Selected work']]);
        $html = $page->render(['pfItems' => $items]);
        $this->assertStringContainsString('data-test="portfolio-list"', $html);
        $this->assertStringContainsString('A booking-first site for Passo', $html);
        $this->assertStringContainsString('/work/passo', $html);
        $this->assertStringNotContainsString(self::CANARY_INTERNAL, $html);
    }

    public function testEmptyListingRendersFriendlyState(): void
    {
        $page = Page::factory(['slug' => 'work', 'template' => 'portfolio-work', 'content' => ['title' => 'Selected work']]);
        $html = $page->render(['pfItems' => []]);
        $this->assertStringContainsString('data-test="portfolio-empty"', $html);
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
