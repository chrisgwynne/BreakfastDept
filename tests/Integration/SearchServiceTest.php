<?php

declare(strict_types=1);

namespace Breakfast\Tests\Integration;

use Breakfast\Platform\Admin\CrmWrite;
use Breakfast\Platform\Crm\SearchService;
use Breakfast\Platform\Support\Database;
use Breakfast\Platform\Support\Platform;
use Kirby\Cms\App;
use PHPUnit\Framework\TestCase;

/**
 * Global search across CRM entities. Proves it finds records of different types
 * by a shared term, returns typed link-carrying rows, and ignores too-short
 * queries.
 */
final class SearchServiceTest extends TestCase
{
    private App $kirby;
    private string $tmp;
    private SearchService $search;

    protected function setUp(): void
    {
        parent::setUp();
        $base = dirname(__DIR__, 2);
        $this->tmp = sys_get_temp_dir() . '/bf-search-' . bin2hex(random_bytes(6));
        @mkdir($this->tmp . '/database', 0777, true);
        Database::reset();
        Platform::reset();
        $this->kirby = new App([
            'roots' => [
                'index' => $base . '/public', 'base' => $base, 'site' => $base . '/site', 'content' => $base . '/content',
                'storage' => $this->tmp, 'sessions' => $this->tmp . '/sessions', 'accounts' => $this->tmp . '/accounts',
            ],
            'options' => ['debug' => false, 'whoops' => false, 'breakfast' => [
                'production' => false, 'storageDir' => $this->tmp, 'dbPath' => $this->tmp . '/database/crm.sqlite', 'mail' => ['provider' => 'fake'],
            ]],
        ]);
        breakfast()->migrator()->migrate();
        $this->search = new SearchService(breakfast());
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

    public function testFindsAcrossTypesByASharedTerm(): void
    {
        $write = new CrmWrite(breakfast());
        $write->createLead(['name' => 'Sian Roberts', 'email' => 'sian@robertscafe.co', 'company' => 'Roberts Cafe'], 'me');
        breakfast()->calendar()->create(['title' => 'Roberts kickoff', 'starts_at' => '2030-08-05T09:00:00Z', 'ends_at' => '2030-08-05T10:00:00Z'], 'me');

        $results = $this->search->search('roberts');
        $types = array_column($results, 'type');
        $this->assertContains('contact', $types);
        $this->assertContains('company', $types);
        $this->assertContains('event', $types);
        // Every row carries an in-app route.
        foreach ($results as $r) {
            $this->assertNotEmpty($r['route']);
        }
    }

    public function testIgnoresTooShortQueries(): void
    {
        $this->assertSame([], $this->search->search('r'));
        $this->assertSame([], $this->search->search(''));
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
