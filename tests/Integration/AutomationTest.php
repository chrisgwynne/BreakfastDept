<?php

declare(strict_types=1);

namespace Breakfast\Tests\Integration;

use Breakfast\Platform\Operations\Automation;
use Breakfast\Platform\Operations\AutomationException;
use Breakfast\Platform\Support\Database;
use Breakfast\Platform\Support\Platform;
use Kirby\Cms\App;
use PHPUnit\Framework\TestCase;

/**
 * Automation. Proves the scheduled rule engine: an enabled rule fires a
 * follow-up task on the target's project when a condition matches after its
 * grace period, firing is idempotent per (rule, target), disabled rules never
 * fire, and the grace period is respected.
 */
final class AutomationTest extends TestCase
{
    private App $kirby;
    private string $tmp;
    private Automation $svc;
    private string $project;

    protected function setUp(): void
    {
        parent::setUp();
        $base = dirname(__DIR__, 2);
        $this->tmp = sys_get_temp_dir() . '/bf-automation-' . bin2hex(random_bytes(6));
        @mkdir($this->tmp . '/database', 0777, true);
        Database::reset();
        Platform::reset();
        $this->kirby = new App([
            'roots' => ['index' => $base . '/public', 'base' => $base, 'site' => $base . '/site', 'content' => $base . '/content', 'storage' => $this->tmp, 'sessions' => $this->tmp . '/sessions', 'accounts' => $this->tmp . '/accounts'],
            'options' => ['debug' => false, 'whoops' => false, 'breakfast' => ['production' => false, 'storageDir' => $this->tmp, 'dbPath' => $this->tmp . '/database/crm.sqlite', 'mail' => ['provider' => 'fake']]],
        ]);
        breakfast()->migrator()->migrate();
        $this->svc = breakfast()->automation();
        $this->project = (string) breakfast()->projects()->create(['name' => 'Automation project'], 'staff@breakfast')['uuid'];
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

    public function testUnknownTriggerRejected(): void
    {
        $this->expectException(AutomationException::class);
        $this->svc->create(['name' => 'X', 'trigger_type' => 'nonsense'], 'staff@breakfast');
    }

    private function overdueInvoice(string $due): void
    {
        $inv = breakfast()->invoices()->create(['bill_to_name' => 'Client', 'items' => [['description' => 'Work', 'quantity' => 1, 'unit_price' => 500]]], 'staff@breakfast');
        $projectUuid = $this->project;
        breakfast()->fileStore()->update('invoices', (string) $inv['uuid'], static function (array $row) use ($projectUuid, $due): array {
            $row['project_uuid'] = $projectUuid;
            $row['status']       = 'issued';
            $row['due_date']     = $due;

            return $row;
        });
    }

    public function testOverdueInvoiceFiresFollowUpTaskOnceAndIsIdempotent(): void
    {
        $this->overdueInvoice('2026-01-10');
        $rule = $this->svc->create(['name' => 'Chase overdue invoices', 'trigger_type' => 'invoice_overdue'], 'staff@breakfast');

        // Run after the due date → one follow-up task on the project.
        $result = $this->svc->run('2026-02-01', 'staff@breakfast');
        $this->assertSame(1, $result['fired']);
        $tasks = breakfast()->projectTasks()->forProject($this->project);
        $chase = array_filter($tasks, static fn (array $t): bool => str_contains((string) $t['title'], 'Chase overdue invoices'));
        $this->assertCount(1, $chase);

        // Re-running does not create a second task (idempotent per target).
        $again = $this->svc->run('2026-02-02', 'staff@breakfast');
        $this->assertSame(0, $again['fired']);
        $this->assertSame(1, (int) breakfast()->db()->scalar('SELECT COUNT(*) FROM automation_fires WHERE rule_uuid = :r', ['r' => (string) $rule['uuid']]));
    }

    public function testDisabledRuleNeverFires(): void
    {
        $this->overdueInvoice('2026-01-10');
        $rule = $this->svc->create(['name' => 'Chase', 'trigger_type' => 'invoice_overdue', 'enabled' => false], 'staff@breakfast');
        $this->assertSame(0, $this->svc->run('2026-02-01', 'staff@breakfast')['fired']);

        // Enabling it makes it fire.
        $this->svc->update((string) $rule['uuid'], ['enabled' => true], 'staff@breakfast');
        $this->assertSame(1, $this->svc->run('2026-02-01', 'staff@breakfast')['fired']);
    }

    public function testGracePeriodDefersFiring(): void
    {
        // Not yet overdue as of the run date → nothing fires.
        $this->overdueInvoice('2026-03-01');
        $this->svc->create(['name' => 'Chase', 'trigger_type' => 'invoice_overdue'], 'staff@breakfast');
        $this->assertSame(0, $this->svc->run('2026-02-01', 'staff@breakfast')['fired']);
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
