<?php

declare(strict_types=1);

namespace Breakfast\Tests\Integration;

use Breakfast\Platform\Projects\ProjectException;
use Breakfast\Platform\Support\Database;
use Breakfast\Platform\Support\Platform;
use Kirby\Cms\App;
use PHPUnit\Framework\TestCase;

/**
 * Milestones + delivery tasks. Proves dependency graphs (circular rejection),
 * the board state machine, that an incomplete blocker gates progression, that
 * completing a blocker makes dependents ready, real progress derivation, and
 * checklist behaviour.
 */
final class DeliveryTest extends TestCase
{
    private App $kirby;
    private string $tmp;
    private string $project;

    protected function setUp(): void
    {
        parent::setUp();
        $base = dirname(__DIR__, 2);
        $this->tmp = sys_get_temp_dir() . '/bf-delivery-' . bin2hex(random_bytes(6));
        @mkdir($this->tmp . '/database', 0777, true);
        Database::reset();
        Platform::reset();
        $this->kirby = new App([
            'roots' => ['index' => $base . '/public', 'base' => $base, 'site' => $base . '/site', 'content' => $base . '/content', 'storage' => $this->tmp, 'sessions' => $this->tmp . '/sessions', 'accounts' => $this->tmp . '/accounts'],
            'options' => ['debug' => false, 'whoops' => false, 'breakfast' => ['production' => false, 'storageDir' => $this->tmp, 'dbPath' => $this->tmp . '/database/crm.sqlite', 'mail' => ['provider' => 'fake']]],
        ]);
        breakfast()->migrator()->migrate();
        $this->project = (string) breakfast()->projects()->create(['name' => 'Delivery test'], 'staff@breakfast')['uuid'];
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

    public function testMilestoneDependencyAndCircularRejection(): void
    {
        $ms = breakfast()->milestones();
        $a = (string) $ms->create($this->project, ['title' => 'Design'], 'staff@breakfast')['uuid'];
        $b = (string) $ms->create($this->project, ['title' => 'Build'], 'staff@breakfast')['uuid'];
        // Build depends on Design.
        $ms->addDependency($b, $a, 'staff@breakfast');
        $this->assertFalse($ms->isReady($b), 'Build blocked until Design completes');
        // Design → Build would be circular.
        $this->assertError(409, fn () => $ms->addDependency($a, $b, 'staff@breakfast'));
        // Build cannot complete while Design is open.
        $ms->setStatus($b, 'active', 'staff@breakfast');
        $this->assertError(409, fn () => $ms->setStatus($b, 'completed', 'staff@breakfast'));
        // Complete Design → Build becomes ready + completable.
        $ms->setStatus($a, 'active', 'staff@breakfast');
        $ms->setStatus($a, 'completed', 'staff@breakfast');
        $this->assertTrue($ms->isReady($b));
        $done = $ms->setStatus($b, 'completed', 'staff@breakfast');
        $this->assertSame('completed', (string) $done['status']);
    }

    public function testTaskBoardTransitionsAndDependencyGate(): void
    {
        $tasks = breakfast()->projectTasks();
        $blocker = (string) $tasks->create($this->project, ['title' => 'Content ready'], 'staff@breakfast')['uuid'];
        $dependent = (string) $tasks->create($this->project, ['title' => 'Build homepage'], 'staff@breakfast')['uuid'];
        $tasks->addDependency($dependent, 'task:' . $blocker, 'staff@breakfast');

        // Dependent cannot move to in_progress while the blocker is open.
        $this->assertError(409, fn () => $tasks->move($dependent, 'in_progress', 'staff@breakfast'));

        // Invalid transition (backlog → review) is rejected.
        $this->assertError(409, fn () => $tasks->move($dependent, 'review', 'staff@breakfast'));

        // Complete the blocker → dependent becomes ready + can progress.
        $tasks->move($blocker, 'in_progress', 'staff@breakfast');
        $tasks->move($blocker, 'completed', 'staff@breakfast');
        $this->assertTrue($tasks->find($dependent)['is_ready']);
        $moved = $tasks->move($dependent, 'in_progress', 'staff@breakfast');
        $this->assertSame('in_progress', (string) $moved['status']);
    }

    public function testProgressIsDerivedFromTasks(): void
    {
        $tasks = breakfast()->projectTasks();
        $t1 = (string) $tasks->create($this->project, ['title' => 'One'], 'staff@breakfast')['uuid'];
        $tasks->create($this->project, ['title' => 'Two'], 'staff@breakfast');
        // 0/2 done → 0%.
        $this->assertSame(0, (int) breakfast()->projects()->find($this->project)['progress_percent']);
        $tasks->move($t1, 'in_progress', 'staff@breakfast');
        $tasks->move($t1, 'completed', 'staff@breakfast');
        // 1/2 done → 50%.
        $this->assertSame(50, (int) breakfast()->projects()->find($this->project)['progress_percent']);
    }

    public function testChecklistAndAssignees(): void
    {
        $tasks = breakfast()->projectTasks();
        $t = (string) $tasks->create($this->project, ['title' => 'Launch', 'assignees' => ['a@x.co', 'b@x.co']], 'staff@breakfast')['uuid'];
        $this->assertCount(2, $tasks->find($t)['assignees']);
        $withItem = $tasks->addChecklistItem($t, 'Final QA', 'staff@breakfast');
        $itemId = (string) $withItem['checklist'][0]['uuid'];
        $done = $tasks->toggleChecklistItem($itemId, true);
        $this->assertSame(1, (int) $done['checklist'][0]['done']);
    }

    public function testMilestoneProgressUsesLinkedTasks(): void
    {
        $ms = breakfast()->milestones();
        $tasks = breakfast()->projectTasks();
        $m = (string) $ms->create($this->project, ['title' => 'Phase 1'], 'staff@breakfast')['uuid'];
        $t1 = (string) $tasks->create($this->project, ['title' => 'A', 'milestone_uuid' => $m], 'staff@breakfast')['uuid'];
        $tasks->create($this->project, ['title' => 'B', 'milestone_uuid' => $m], 'staff@breakfast');
        $tasks->move($t1, 'in_progress', 'staff@breakfast');
        $tasks->move($t1, 'completed', 'staff@breakfast');
        $this->assertSame(50, (int) $ms->find($m)['progress_percent']);
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
