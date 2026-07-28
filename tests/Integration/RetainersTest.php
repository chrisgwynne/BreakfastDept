<?php

declare(strict_types=1);

namespace Breakfast\Tests\Integration;

use Breakfast\Platform\Retainers\RetainerException;
use Breakfast\Platform\Retainers\Retainers;
use Breakfast\Platform\Support\Platform;
use Kirby\Cms\App;
use PHPUnit\Framework\TestCase;

/**
 * Retainers & recurring billing. Proves periodic draft-invoice generation billed
 * in arrears (fee + time overage), time-overage drawdown against the allowance,
 * that covered time is locked so it can never be billed twice, idempotent
 * generation per period, and multi-period catch-up.
 */
final class RetainersTest extends TestCase
{
    private App $kirby;
    private string $tmp;
    private Retainers $svc;
    private string $project;

    protected function setUp(): void
    {
        parent::setUp();
        $base = dirname(__DIR__, 2);
        $this->tmp = sys_get_temp_dir() . '/bf-retainer-' . bin2hex(random_bytes(6));
        Platform::reset();
        $this->kirby = new App([
            'roots' => ['index' => $base . '/public', 'base' => $base, 'site' => $base . '/site', 'content' => $base . '/content', 'storage' => $this->tmp, 'sessions' => $this->tmp . '/sessions', 'accounts' => $this->tmp . '/accounts'],
            'options' => ['debug' => false, 'whoops' => false, 'breakfast' => ['production' => false, 'storageDir' => $this->tmp, 'mail' => ['provider' => 'fake']]],
        ]);
        $this->svc = breakfast()->retainers();
        $this->project = (string) breakfast()->projects()->create(['name' => 'Care plan project'], 'staff@breakfast')['uuid'];
    }

    protected function tearDown(): void
    {
        Platform::reset();
        $this->rrmdir($this->tmp);
        App::destroy();
        restore_error_handler();
        restore_exception_handler();
        parent::tearDown();
    }

    public function testCreateRequiresFeeOrAllowance(): void
    {
        $this->expectException(RetainerException::class);
        $this->svc->create($this->project, ['title' => 'Empty', 'fee' => 0, 'included_hours' => 0], 'staff@breakfast');
    }

    public function testBillsFeePlusOverageAndLocksTime(): void
    {
        // £500/mo, 5 included hours, £90/h overage, starting 1 Jan.
        $ret = $this->svc->create($this->project, [
            'title' => 'Support retainer', 'fee' => 500, 'included_hours' => 5, 'overage_rate' => 90, 'start_date' => '2026-01-01',
        ], 'staff@breakfast');
        $id = (string) $ret['uuid'];

        // Log 7 billable hours inside January (2h over the 5h allowance).
        breakfast()->time()->create($this->project, ['hours' => 7, 'billable' => true, 'rate' => 90, 'started_at' => '2026-01-10'], 'chris@breakfast');

        // Run as of 5 Feb: the January period (01-01 → 02-01) has elapsed.
        $result = $this->svc->runRetainer($id, '2026-02-05', 'staff@breakfast');
        $this->assertSame([1, 1], $result);

        $rid = $id;
        $periods = array_values(array_filter(breakfast()->fileStore()->all('retainer_periods'), static fn (array $p): bool => (string) ($p['retainer_uuid'] ?? '') === $rid));
        $period = $periods[0] ?? [];
        $this->assertSame(25200, (int) $period['used_seconds']);      // 7h
        $this->assertSame(50000, (int) $period['fee_pence']);         // £500 fee
        $this->assertSame(18000, (int) $period['overage_pence']);     // 2h × £90 = £180.00
        $this->assertNotNull($period['invoice_uuid']);

        // Invoice total = £500 fee + £180 overage = £680.00.
        $inv = breakfast()->invoices()->find((string) $period['invoice_uuid']);
        $this->assertSame(68000, (int) $inv['total']);

        // The covered time entry is now locked (billed) and cannot be re-billed.
        $projectUuid = $this->project;
        $billed = count(array_filter(breakfast()->fileStore()->all('time_entries'), static fn (array $e): bool => (string) ($e['project_uuid'] ?? '') === $projectUuid && (int) ($e['billed'] ?? 0) === 1));
        $this->assertSame(1, $billed);
    }

    public function testGenerationIsIdempotentPerPeriod(): void
    {
        $ret = $this->svc->create($this->project, ['title' => 'R', 'fee' => 300, 'start_date' => '2026-01-01'], 'staff@breakfast');
        $id = (string) $ret['uuid'];
        $this->svc->runRetainer($id, '2026-02-05', 'staff@breakfast');
        // Running again for the same window creates no new period/invoice.
        $again = $this->svc->runRetainer($id, '2026-02-05', 'staff@breakfast');
        $this->assertSame([0, 0], $again);
        $this->assertSame(1, count(array_filter(breakfast()->fileStore()->all('retainer_periods'), static fn (array $p): bool => (string) ($p['retainer_uuid'] ?? '') === $id)));
    }

    public function testCatchesUpMultiplePeriods(): void
    {
        $ret = $this->svc->create($this->project, ['title' => 'R', 'fee' => 100, 'start_date' => '2026-01-01'], 'staff@breakfast');
        $id = (string) $ret['uuid'];
        // As of mid-April, Jan, Feb and Mar have all elapsed → three periods.
        $result = $this->svc->runRetainer($id, '2026-04-15', 'staff@breakfast');
        $this->assertSame([3, 3], $result);
        $this->assertSame(3, count(array_filter(breakfast()->fileStore()->all('retainer_periods'), static fn (array $p): bool => (string) ($p['retainer_uuid'] ?? '') === $id)));
    }

    public function testPausedRetainerDoesNotBill(): void
    {
        $ret = $this->svc->create($this->project, ['title' => 'R', 'fee' => 100, 'start_date' => '2026-01-01'], 'staff@breakfast');
        $id = (string) $ret['uuid'];
        $this->svc->setStatus($id, 'paused', 'staff@breakfast');
        $this->assertSame([0, 0], $this->svc->runRetainer($id, '2026-06-01', 'staff@breakfast'));
        $this->assertSame(['invoices' => 0, 'periods' => 0], $this->svc->runDue('2026-06-01', 'staff@breakfast'));
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
