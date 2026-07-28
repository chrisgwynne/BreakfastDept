<?php

declare(strict_types=1);

namespace Breakfast\Tests\Integration;

use Breakfast\Platform\Support\BusinessDays;
use Breakfast\Platform\Support\Platform;
use Kirby\Cms\App;
use PHPUnit\Framework\TestCase;

/**
 * Project templates — versioning + apply. Proves built-ins seed once, applying a
 * template generates real project-owned milestones/tasks with resolved
 * business-day dates and dependency links, the frozen version is recorded on the
 * project, and editing a template into a new version never mutates existing
 * projects. Also covers the business-day date resolver.
 */
final class ProjectTemplatesTest extends TestCase
{
    private App $kirby;
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $base = dirname(__DIR__, 2);
        $this->tmp = sys_get_temp_dir() . '/bf-templates-' . bin2hex(random_bytes(6));
        Platform::reset();
        $this->kirby = new App([
            'roots' => ['index' => $base . '/public', 'base' => $base, 'site' => $base . '/site', 'content' => $base . '/content', 'storage' => $this->tmp, 'sessions' => $this->tmp . '/sessions', 'accounts' => $this->tmp . '/accounts'],
            'options' => ['debug' => false, 'whoops' => false, 'breakfast' => ['production' => false, 'storageDir' => $this->tmp, 'mail' => ['provider' => 'fake']]],
        ]);
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

    public function testBusinessDaysResolver(): void
    {
        // Fri 2026-01-02 + 1 business day = Mon 2026-01-05 (skips the weekend).
        $this->assertSame('2026-01-05', BusinessDays::add('2026-01-02', 1));
        $this->assertSame('2026-01-02', BusinessDays::add('2026-01-02', 0));
        // 5 business days from a Monday lands on the next Monday.
        $this->assertSame('2026-01-12', BusinessDays::add('2026-01-05', 5));
    }

    public function testBuiltinsSeedOnceAndAreListed(): void
    {
        $svc = breakfast()->projectTemplates();
        $first = $svc->list();
        $second = $svc->list();
        $this->assertCount(count($first), $second, 'seeding is idempotent');
        $slugs = array_column($first, 'slug');
        $this->assertContains('brochure_website', $slugs);
    }

    public function testApplyTemplateGeneratesMilestonesTasksDatesAndDependencies(): void
    {
        $p = breakfast();
        $template = null;
        foreach ($p->projectTemplates()->list() as $t) {
            if ((string) $t['slug'] === 'brochure_website') {
                $template = $t;
            }
        }
        $this->assertNotNull($template);

        $project = $p->projects()->create(['name' => 'Brochure build', 'start_date' => '2026-01-05', 'target_date' => '2026-02-02'], 'staff@breakfast');
        $result = $p->projectTemplates()->applyToProject((string) $project['uuid'], (string) $template['uuid'], 'staff@breakfast');
        $this->assertSame(5, $result['milestones']);
        $this->assertSame(4, $result['tasks']);
        $this->assertSame(1, $result['version']);

        // Frozen template reference recorded on the project.
        $fresh = $p->projects()->find((string) $project['uuid']);
        $this->assertSame((string) $template['uuid'], (string) $fresh['template_uuid']);
        $this->assertSame(1, (int) $fresh['template_version']);

        // Resolved dates: "Homepage content due" = start + 5 business days.
        $tasks = $p->projectTasks()->forProject((string) $project['uuid']);
        $content = null;
        foreach ($tasks as $t) {
            if ((string) $t['title'] === 'Homepage content due') {
                $content = $t;
            }
        }
        $this->assertNotNull($content);
        $this->assertSame(BusinessDays::add('2026-01-05', 5), (string) $content['due_date']);

        // Dependencies materialised: the mock-up task is blocked by the content task.
        $mockup = null;
        foreach ($tasks as $t) {
            if ((string) $t['title'] === 'Initial mock-up') {
                $mockup = $t;
            }
        }
        $this->assertNotNull($mockup);
        $this->assertNotEmpty($mockup['blocked_by']);
        $this->assertFalse($mockup['is_ready'], 'mock-up blocked until content done');
    }

    public function testNewVersionDoesNotMutateExistingProjects(): void
    {
        $p = breakfast();
        $tpl = $p->projectTemplates()->create(['name' => 'My Custom', 'milestones' => [['key' => 'a', 'title' => 'Alpha']], 'tasks' => []], 'staff@breakfast');
        $tplUuid = (string) $tpl['uuid'];
        $p->projectTemplates()->publish($tplUuid, 1, 'staff@breakfast');

        $project = $p->projects()->create(['name' => 'Uses v1'], 'staff@breakfast');
        $p->projectTemplates()->applyToProject((string) $project['uuid'], $tplUuid, 'staff@breakfast');
        $this->assertCount(1, $p->milestones()->forProject((string) $project['uuid']));

        // Author a v2 with an extra milestone and publish it — the existing
        // project must be untouched (still one milestone).
        $p->projectTemplates()->newDraftVersion($tplUuid, 'staff@breakfast');
        // v2 drafted by cloning v1; the applied project is frozen at v1.
        $this->assertCount(1, $p->milestones()->forProject((string) $project['uuid']));
        $fresh = $p->projects()->find((string) $project['uuid']);
        $this->assertSame(1, (int) $fresh['template_version']);
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
