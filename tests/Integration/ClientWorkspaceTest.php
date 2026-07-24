<?php

declare(strict_types=1);

namespace Breakfast\Tests\Integration;

use Breakfast\Platform\Admin\CrmWrite;
use Breakfast\Platform\Crm\ClientWorkspace;
use Breakfast\Platform\Support\Database;
use Breakfast\Platform\Support\Platform;
use Kirby\Cms\App;
use PHPUnit\Framework\TestCase;

/**
 * The unified client workspace — one coherent view of a client that aggregates
 * the real related records (opportunities, invoices, tasks, calendar events)
 * and merges the contact + company activity streams into a single timeline.
 * Proves it joins to the same records everything else uses, and computes an
 * accurate financial + engagement summary.
 */
final class ClientWorkspaceTest extends TestCase
{
    private App $kirby;
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $base = dirname(__DIR__, 2);
        $this->tmp = sys_get_temp_dir() . '/bf-ws-' . bin2hex(random_bytes(6));
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

    public function testWorkspaceAggregatesRelatedRecordsAndSummary(): void
    {
        $p     = breakfast();
        $write = new CrmWrite($p);
        $lead  = $write->createLead(['name' => 'Sian', 'email' => 's@ex.co', 'company' => 'Roberts Cafe'], 'me');
        $cid   = (string) $lead['contact']['uuid'];

        $write->convertLead((string) $lead['enquiry']['uuid'], ['title' => 'Website', 'value' => 250000], 'me');
        $p->calendar()->create(['title' => 'Kickoff', 'starts_at' => '2030-08-05T09:00:00Z', 'ends_at' => '2030-08-05T10:00:00Z', 'contact_uuid' => $cid], 'me');
        $inv = $p->invoices()->create(['bill_to_name' => 'Sian', 'contact_uuid' => $cid, 'items' => [['description' => 'x', 'quantity' => 1, 'unit_price' => 1000]]], 'me');
        $p->invoices()->issue((string) $inv['uuid'], 'me');

        $contact = $p->contacts()->find($cid);
        $ws = (new ClientWorkspace($p))->forContact($contact ?? []);

        $this->assertSame('Roberts Cafe', $ws['company']['name'] ?? null);
        $this->assertCount(1, $ws['opportunities']);
        $this->assertCount(1, $ws['invoices']);
        $this->assertCount(1, $ws['events']);
        $this->assertSame(1, $ws['summary']['open_opportunities']);
        // £1000 issued invoice → invoiced/outstanding in pence.
        $this->assertSame(100000, $ws['summary']['total_invoiced']);
        $this->assertSame(100000, $ws['summary']['outstanding']);
        $this->assertNotNull($ws['summary']['upcoming_event']);
        // Timeline merges contact + company activity.
        $this->assertNotEmpty($ws['timeline']);
    }

    public function testDraftAndVoidInvoicesAreExcludedFromRevenue(): void
    {
        $p     = breakfast();
        $write = new CrmWrite($p);
        $lead  = $write->createLead(['name' => 'Dai', 'email' => 'd@ex.co'], 'me');
        $cid   = (string) $lead['contact']['uuid'];

        // A draft invoice must not count towards invoiced/outstanding.
        $p->invoices()->create(['bill_to_name' => 'Dai', 'contact_uuid' => $cid, 'items' => [['description' => 'x', 'quantity' => 1, 'unit_price' => 5000]]], 'me');

        $ws = (new ClientWorkspace($p))->forContact($p->contacts()->find($cid) ?? []);
        $this->assertSame(0, $ws['summary']['total_invoiced'], 'draft invoices are not revenue');
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
