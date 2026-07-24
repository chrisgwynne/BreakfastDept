<?php

declare(strict_types=1);

namespace Breakfast\Tests\Integration;

use Breakfast\Platform\Support\Database;
use Breakfast\Platform\Support\Platform;
use Breakfast\Platform\Website\WebsiteException;
use Breakfast\Platform\Website\WebsiteSections;
use Kirby\Cms\App;
use Kirby\Content\VersionId;
use PHPUnit\Framework\TestCase;

/**
 * Managing a page's content sections (page-builder blocks): add, edit, reorder
 * and delete, each persisted to the DRAFT only, with optimistic-concurrency
 * protection. Uses the real `legal` template (a `Body` blocks field) in a
 * throwaway content root, so nothing in the repo is touched.
 */
final class WebsiteSectionsTest extends TestCase
{
    private App $kirby;
    private string $tmp;
    private WebsiteSections $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $base = dirname(__DIR__, 2);
        $this->tmp = sys_get_temp_dir() . '/bf-sections-' . bin2hex(random_bytes(6));
        @mkdir($this->tmp . '/database', 0777, true);
        @mkdir($this->tmp . '/content/sectiontest', 0777, true);
        // A legal page with two starter blocks.
        $blocks = json_encode([
            ['id' => 'a1', 'type' => 'heading', 'content' => ['level' => 'h2', 'text' => 'First']],
            ['id' => 'a2', 'type' => 'text', 'content' => ['text' => '<p>Second</p>']],
        ]);
        file_put_contents($this->tmp . '/content/sectiontest/legal.txt', "Title: Section Test\n\n----\n\nBody:\n\n{$blocks}\n");

        Database::reset();
        Platform::reset();

        $this->kirby = new App([
            'roots' => [
                'index'    => $base . '/public',
                'base'     => $base,
                'site'     => $base . '/site',
                'content'  => $this->tmp . '/content',
                'storage'  => $this->tmp,
                'sessions' => $this->tmp . '/sessions',
                'accounts' => $this->tmp . '/accounts',
            ],
            'options' => [
                'debug'  => false,
                'whoops' => false,
                'breakfast' => [
                    'production' => false,
                    'storageDir' => $this->tmp,
                    'dbPath'     => $this->tmp . '/database/crm.sqlite',
                    'mail'       => ['provider' => 'fake'],
                ],
            ],
        ]);
        breakfast()->migrator()->migrate();
        $this->kirby->impersonate('kirby');
        $this->svc = new WebsiteSections($this->kirby, breakfast());
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

    public function testListsSectionsWithAConcurrencyHash(): void
    {
        $out = $this->svc->list('sectiontest');
        $this->assertSame('body', $out['field']);
        $this->assertCount(2, $out['sections']);
        $this->assertSame('heading', $out['sections'][0]['type']);
        $this->assertNotEmpty($out['hash']);
    }

    public function testAddReorderEditDeleteFlowPersistsToDraftOnly(): void
    {
        $hash = $this->svc->list('sectiontest')['hash'];

        // Add a text section.
        $afterAdd = $this->svc->add('sectiontest', 'text', $hash, 'admin@test');
        $this->assertCount(3, $afterAdd['sections']);
        $newId = $afterAdd['sections'][2]['id'];

        // Reorder: move the new one to the front.
        $ids = array_column($afterAdd['sections'], 'id');
        $reordered = array_merge([$newId], array_values(array_filter($ids, fn ($i) => $i !== $newId)));
        $afterReorder = $this->svc->reorder('sectiontest', $reordered, $afterAdd['hash'], 'admin@test');
        $this->assertSame($newId, $afterReorder['sections'][0]['id']);

        // Edit the new section's text.
        $afterEdit = $this->svc->updateSection('sectiontest', $newId, '<p>Edited body</p>', $afterReorder['hash'], 'admin@test');
        $edited = array_values(array_filter($afterEdit['sections'], fn ($s) => $s['id'] === $newId))[0];
        $this->assertStringContainsString('Edited body', $edited['text']);

        // Delete it.
        $afterDelete = $this->svc->remove('sectiontest', $newId, $afterEdit['hash'], 'admin@test');
        $this->assertCount(2, $afterDelete['sections']);

        // Everything landed on the DRAFT; live content is unchanged.
        $page = $this->kirby->page('sectiontest');
        $this->assertNotNull($page);
        $this->assertTrue($page->version(VersionId::changes())->exists('default'), 'edits go to the draft');
        $liveContent = array_change_key_case($page->version(VersionId::latest())->read('default') ?? [], CASE_LOWER);
        $live = json_decode((string) ($liveContent['body'] ?? '[]'), true);
        $this->assertCount(2, $live, 'live content is untouched until published');
        $this->assertSame('a1', $live[0]['id']);
    }

    public function testStaleHashIsRejected(): void
    {
        $stale = $this->svc->list('sectiontest')['hash'];
        // A concurrent change moves the real hash on.
        $this->svc->add('sectiontest', 'divider', $stale, 'admin@test');

        // The original hash is now stale — a second write with it must 409.
        $this->assertSectionError(409, fn () => $this->svc->add('sectiontest', 'text', $stale, 'admin@test'));
    }

    public function testReorderMustIncludeEverySectionExactlyOnce(): void
    {
        $hash = $this->svc->list('sectiontest')['hash'];
        $this->assertSectionError(422, fn () => $this->svc->reorder('sectiontest', ['a1'], $hash, 'admin@test'));
    }

    public function testAddRejectsUnknownType(): void
    {
        $hash = $this->svc->list('sectiontest')['hash'];
        $this->assertSectionError(422, fn () => $this->svc->add('sectiontest', 'nonsense', $hash, 'admin@test'));
    }

    private function assertSectionError(int $status, callable $fn): void
    {
        try {
            $fn();
            $this->fail('Expected a WebsiteException');
        } catch (WebsiteException $e) {
            $this->assertSame($status, $e->status);
        }
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
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
