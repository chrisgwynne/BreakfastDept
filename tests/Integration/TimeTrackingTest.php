<?php

declare(strict_types=1);

namespace Breakfast\Tests\Integration;

use Breakfast\Platform\Support\Database;
use Breakfast\Platform\Support\Platform;
use Breakfast\Platform\Time\TimeException;
use Breakfast\Platform\Time\TimeTracking;
use Kirby\Cms\App;
use PHPUnit\Framework\TestCase;

/**
 * Time tracking. Proves manual entries with server-computed value, the
 * one-running-timer-per-user rule, start/stop capture, the billed lock (invoiced
 * entries can't be edited or deleted), and the project rollup of billable vs
 * non-billable seconds and unbilled value.
 */
final class TimeTrackingTest extends TestCase
{
    private App $kirby;
    private string $tmp;
    private TimeTracking $svc;
    private string $project;

    protected function setUp(): void
    {
        parent::setUp();
        $base = dirname(__DIR__, 2);
        $this->tmp = sys_get_temp_dir() . '/bf-time-' . bin2hex(random_bytes(6));
        @mkdir($this->tmp . '/database', 0777, true);
        Database::reset();
        Platform::reset();
        $this->kirby = new App([
            'roots' => ['index' => $base . '/public', 'base' => $base, 'site' => $base . '/site', 'content' => $base . '/content', 'storage' => $this->tmp, 'sessions' => $this->tmp . '/sessions', 'accounts' => $this->tmp . '/accounts'],
            'options' => ['debug' => false, 'whoops' => false, 'breakfast' => ['production' => false, 'storageDir' => $this->tmp, 'dbPath' => $this->tmp . '/database/crm.sqlite', 'mail' => ['provider' => 'fake']]],
        ]);
        breakfast()->migrator()->migrate();
        $this->svc = breakfast()->time();
        $this->project = (string) breakfast()->projects()->create(['name' => 'Time project'], 'staff@breakfast')['uuid'];
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

    public function testManualEntryRequiresPositiveDuration(): void
    {
        $this->expectException(TimeException::class);
        $this->svc->create($this->project, ['hours' => 0, 'minutes' => 0], 'chris@breakfast');
    }

    public function testRollupComputesBillableValueAndSplits(): void
    {
        // 2h billable at £80/h + 30m non-billable.
        $this->svc->create($this->project, ['hours' => 2, 'billable' => true, 'rate' => 80, 'description' => 'Build'], 'chris@breakfast');
        $this->svc->create($this->project, ['minutes' => 30, 'billable' => false, 'description' => 'Internal chat'], 'chris@breakfast');

        $r = $this->svc->rollup($this->project);
        $this->assertSame(7200, $r['billable_seconds']);
        $this->assertSame(1800, $r['nonbillable_seconds']);
        $this->assertSame(9000, $r['total_seconds']);
        $this->assertSame(16000, $r['billable_value']);   // 2h × £80 = £160.00
        $this->assertSame(16000, $r['unbilled_value']);
    }

    public function testOnlyOneRunningTimerPerUser(): void
    {
        $this->svc->startTimer($this->project, ['description' => 'Design'], 'chris@breakfast');
        try {
            $this->svc->startTimer($this->project, [], 'chris@breakfast');
            $this->fail('expected a second timer to be rejected');
        } catch (TimeException $e) {
            $this->assertSame(409, $e->status);
        }
        // A different user can run their own timer.
        $this->svc->startTimer($this->project, [], 'sam@breakfast');
        $this->assertNotNull($this->svc->runningTimer('sam@breakfast'));

        // Stopping captures a duration and clears the running flag.
        $stopped = $this->svc->stopTimer('chris@breakfast');
        $this->assertSame(0, (int) $stopped['running']);
        $this->assertNull($this->svc->runningTimer('chris@breakfast'));
        // Stopping again is an error.
        $this->expectException(TimeException::class);
        $this->svc->stopTimer('chris@breakfast');
    }

    public function testBilledEntriesAreLocked(): void
    {
        $entry = $this->svc->create($this->project, ['hours' => 1, 'billable' => true, 'rate' => 100], 'chris@breakfast');
        $id = (string) $entry['uuid'];
        $n = $this->svc->markBilled([$id], 'invoice-123', 'chris@breakfast');
        $this->assertSame(1, $n);

        // Re-billing is idempotent (0 additional).
        $this->assertSame(0, $this->svc->markBilled([$id], 'invoice-123', 'chris@breakfast'));

        // A billed entry can't be edited or deleted.
        try {
            $this->svc->update($id, ['description' => 'tamper'], 'chris@breakfast');
            $this->fail('expected billed entry edit to be blocked');
        } catch (TimeException $e) {
            $this->assertSame(409, $e->status);
        }
        $this->expectException(TimeException::class);
        $this->svc->delete($id, 'chris@breakfast');
    }

    public function testUnbilledValueExcludesBilledEntries(): void
    {
        $a = $this->svc->create($this->project, ['hours' => 1, 'billable' => true, 'rate' => 100], 'chris@breakfast');
        $this->svc->create($this->project, ['hours' => 1, 'billable' => true, 'rate' => 100], 'chris@breakfast');
        $this->svc->markBilled([(string) $a['uuid']], 'inv-1', 'chris@breakfast');

        $r = $this->svc->rollup($this->project);
        $this->assertSame(20000, $r['billable_value']);   // both count toward total value
        $this->assertSame(10000, $r['unbilled_value']);   // only the unbilled one remains billable
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
