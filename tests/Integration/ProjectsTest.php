<?php

declare(strict_types=1);

namespace Breakfast\Tests\Integration;

use Breakfast\Platform\Projects\ProjectException;
use Breakfast\Platform\Projects\Projects;
use Breakfast\Platform\Support\Database;
use Breakfast\Platform\Support\Platform;
use Kirby\Cms\App;
use PHPUnit\Framework\TestCase;

/**
 * Projects — the delivery workspace. Proves the real state machine (valid +
 * rejected transitions), reason enforcement, awaiting-client time accounting,
 * completion/reopen/archive/restore rules, financial roll-up from linked
 * invoices, optimistic concurrency, and creation from an accepted proposal.
 */
final class ProjectsTest extends TestCase
{
    private App $kirby;
    private string $tmp;
    private Projects $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $base = dirname(__DIR__, 2);
        $this->tmp = sys_get_temp_dir() . '/bf-projects-' . bin2hex(random_bytes(6));
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
            'options' => ['debug' => false, 'whoops' => false, 'breakfast' => ['production' => false, 'storageDir' => $this->tmp, 'dbPath' => $this->tmp . '/database/crm.sqlite', 'mail' => ['provider' => 'fake']]],
        ]);
        breakfast()->migrator()->migrate();
        $this->svc = breakfast()->projects();
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

    /** @return array<string,mixed> */
    private function make(array $data = []): array
    {
        return $this->svc->create(array_merge(['name' => 'Roberts Cafe website', 'quoted_value' => 3000], $data), 'staff@breakfast');
    }

    public function testCreateAllocatesNumberSeedsOwnerAndStartsDraft(): void
    {
        $p = $this->make();
        $year = (int) date('Y');
        $this->assertSame("PRJ-{$year}-0001", (string) $p['number']);
        $this->assertSame('draft', (string) $p['status']);
        $this->assertSame(300000, (int) $p['quoted_value']);
        $roles = array_column($p['members'], 'role');
        $this->assertContains('lead', $roles);
    }

    public function testForwardTransitionsFollowTheStateMachine(): void
    {
        $p = $this->make();
        $uuid = (string) $p['uuid'];
        foreach (['planning', 'onboarding', 'active', 'review', 'ready_to_launch', 'completed'] as $to) {
            $p = $this->svc->transition($uuid, $to, [], 'staff@breakfast');
            $this->assertSame($to, (string) $p['status']);
        }
        $this->assertNotEmpty($p['completed_at']);
    }

    public function testInvalidTransitionIsRejected(): void
    {
        $p = $this->make();
        // draft cannot jump straight to completed.
        $this->expectException(ProjectException::class);
        $this->svc->transition((string) $p['uuid'], 'completed', [], 'staff@breakfast');
    }

    public function testCancellationAndBlockingRequireReasons(): void
    {
        $p = $this->make(['status' => 'active']);
        $uuid = (string) $p['uuid'];
        $this->assertError(422, fn () => $this->svc->transition($uuid, 'cancelled', [], 'staff@breakfast'));
        $this->assertError(422, fn () => $this->svc->transition($uuid, 'blocked', [], 'staff@breakfast'));

        $blocked = $this->svc->transition($uuid, 'blocked', ['reason' => 'Waiting on hosting access'], 'staff@breakfast');
        $this->assertSame('blocked', (string) $blocked['status']);
        $this->assertStringContainsString('hosting', (string) $blocked['blocked_reason']);
    }

    public function testAwaitingClientTimeIsMeasured(): void
    {
        $p = $this->make(['status' => 'active']);
        $uuid = (string) $p['uuid'];
        $this->svc->transition($uuid, 'awaiting_client', [], 'staff@breakfast');
        // Back-date the awaiting_since so a measurable interval accrues.
        $backdated = date('c', time() - 3600);
        breakfast()->fileStore()->update('projects', $uuid, static function (array $row) use ($backdated): array {
            $row['awaiting_since'] = $backdated;
            return $row;
        });
        $resumed = $this->svc->transition($uuid, 'active', [], 'staff@breakfast');
        $this->assertGreaterThanOrEqual(3500, (int) $resumed['awaiting_seconds']);
    }

    public function testCompletedCannotReturnToActiveWithoutReopen(): void
    {
        $p = $this->make(['status' => 'ready_to_launch']);
        $uuid = (string) $p['uuid'];
        $this->svc->transition($uuid, 'completed', [], 'staff@breakfast');
        $this->assertError(409, fn () => $this->svc->transition($uuid, 'active', [], 'staff@breakfast'));

        $reopened = $this->svc->reopen($uuid, 'staff@breakfast');
        $this->assertSame('active', (string) $reopened['status']);
        $this->assertEmpty($reopened['completed_at']);
    }

    public function testArchiveIsDistinctFromCompletionAndBlocksEditing(): void
    {
        $p = $this->make();
        $uuid = (string) $p['uuid'];
        $archived = $this->svc->archive($uuid, 'staff@breakfast');
        $this->assertSame(1, (int) $archived['archived']);
        // Archived projects are hidden from the default list and can't be edited.
        $this->assertCount(0, $this->svc->list());
        $this->assertError(409, fn () => $this->svc->update($uuid, ['name' => 'x'], 'staff@breakfast'));

        $restored = $this->svc->restore($uuid, 'staff@breakfast');
        $this->assertSame(0, (int) $restored['archived']);
        $this->assertCount(1, $this->svc->list());
    }

    public function testOptimisticConcurrencyRejectsStaleWrite(): void
    {
        $p = $this->make();
        $uuid = (string) $p['uuid'];
        $rev = (int) $p['revision'];
        $this->svc->update($uuid, ['name' => 'First'], 'staff@breakfast', $rev);
        // A second writer with the old revision must be rejected.
        $this->assertError(409, fn () => $this->svc->update($uuid, ['name' => 'Stale'], 'staff@breakfast', $rev));
    }

    public function testFinancialRollUpFromLinkedInvoices(): void
    {
        $p = $this->make();
        $uuid = (string) $p['uuid'];
        // A linked, issued, part-paid invoice.
        $inv = breakfast()->invoices()->create(['bill_to_name' => 'Roberts', 'items' => [['description' => 'Deposit', 'quantity' => 1, 'unit_price' => 1000]]], 'staff@breakfast');
        breakfast()->invoices()->assignToProject((string) $inv['uuid'], $uuid);
        breakfast()->invoices()->issue((string) $inv['uuid'], 'staff@breakfast');
        breakfast()->invoices()->recordPayment((string) $inv['uuid'], ['amount' => 400, 'method' => 'bank'], 'staff@breakfast');

        $fresh = $this->svc->find($uuid);
        $this->assertSame(100000, (int) $fresh['invoiced_value']);
        $this->assertSame(40000, (int) $fresh['paid_value']);
    }

    public function testCreateFromAcceptedProposal(): void
    {
        $p = breakfast();
        $prop = $p->proposals()->create([
            'title' => 'Roberts website', 'client_name' => 'Roberts', 'client_email' => 'r@x.co',
            'items' => [['kind' => 'fixed', 'description' => 'Build', 'quantity' => 1, 'unit_price' => 3000, 'tax_rate' => 0]],
        ], 'staff@breakfast');
        $p->proposals()->send((string) $prop['uuid'], 'staff@breakfast', ['company_legal_name' => 'Breakfast Ltd', 'proposal_prefix' => 'PRO']);
        $p->proposals()->accept((string) $prop['uuid'], ['name' => 'Roberts']);

        $project = $p->projectConversion()->fromProposal((string) $prop['uuid'], [], 'staff@breakfast');
        $this->assertSame('planning', (string) $project['status']);
        $this->assertSame((string) $prop['uuid'], (string) $project['proposal_uuid']);
        $this->assertSame(300000, (int) $project['quoted_value']);

        // A second attempt is blocked as a duplicate.
        $this->assertError(409, fn () => $p->projectConversion()->fromProposal((string) $prop['uuid'], [], 'staff@breakfast'));
    }

    public function testConversionRequiresAcceptedProposal(): void
    {
        $p = breakfast();
        $prop = $p->proposals()->create(['title' => 'Draft', 'client_name' => 'X', 'client_email' => 'x@x.co', 'items' => [['kind' => 'fixed', 'description' => 'a', 'quantity' => 1, 'unit_price' => 100]]], 'staff@breakfast');
        $this->assertError(409, fn () => $p->projectConversion()->fromProposal((string) $prop['uuid'], [], 'staff@breakfast'));
    }

    private function assertError(int $status, callable $fn): void
    {
        try {
            $fn();
            $this->fail('Expected a ProjectException with status ' . $status);
        } catch (ProjectException $e) {
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
