<?php

declare(strict_types=1);

namespace Breakfast\Platform\Projects;

use Breakfast\Platform\Support\BusinessDays;
use Breakfast\Platform\Support\Clock;
use Breakfast\Platform\Support\FileStore;
use Breakfast\Platform\Support\Uuid;

/**
 * Versioned project templates, stored as flat files. Built-ins are seeded once
 * as published version 1; custom templates and new versions live in the same
 * collection. Applying a template to a project copies the frozen version's
 * milestone/task rows into project-owned records with resolved business-day
 * dates and dependency links, and records the template + version on the project.
 * A later template edit is a new version and never mutates existing projects.
 *
 * Each template is one JSON record; its versions — and each version's milestones
 * and tasks — live embedded in that record as native arrays.
 */
final class ProjectTemplates
{
    private const COLLECTION = 'project_templates';

    public function __construct(
        private readonly FileStore $store,
    ) {
    }

    // ==================================================================
    // Built-ins
    // ==================================================================

    /**
     * Compact built-in blueprints. Each milestone/task carries a stable `key`, a
     * relative due rule (anchor + business-day offset) and dependency keys.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function builtins(): array
    {
        $simple = static fn (string $name, string $desc, string $type): array => [
            'name' => $name, 'description' => $desc, 'category' => $type, 'project_type' => $type,
            'milestones' => [
                ['key' => 'kickoff', 'title' => 'Kickoff', 'anchor' => 'project_start', 'offset' => 0],
                ['key' => 'delivery', 'title' => 'Delivery', 'anchor' => 'project_target', 'offset' => 0, 'blocked_by' => ['kickoff']],
            ],
            'tasks' => [
                ['key' => 'scope', 'title' => 'Confirm scope', 'milestone' => 'kickoff', 'anchor' => 'project_start', 'offset' => 2],
                ['key' => 'deliver', 'title' => 'Deliver work', 'milestone' => 'delivery', 'anchor' => 'project_target', 'offset' => -2, 'blocked_by' => ['task:scope']],
            ],
        ];

        return [
            'brochure_website' => [
                'name' => 'Brochure website', 'description' => 'A standard small-business brochure site.', 'category' => 'website', 'project_type' => 'brochure',
                'milestones' => [
                    ['key' => 'kickoff', 'title' => 'Kickoff & onboarding', 'anchor' => 'project_start', 'offset' => 0],
                    ['key' => 'design', 'title' => 'Design & mock-up', 'anchor' => 'project_start', 'offset' => 10, 'blocked_by' => ['kickoff']],
                    ['key' => 'review', 'title' => 'Client review', 'anchor' => 'project_start', 'offset' => 13, 'blocked_by' => ['design'], 'is_approval' => true],
                    ['key' => 'build', 'title' => 'Build', 'anchor' => 'project_start', 'offset' => 20, 'blocked_by' => ['review']],
                    ['key' => 'launch', 'title' => 'Launch', 'anchor' => 'project_target', 'offset' => 0, 'blocked_by' => ['build']],
                ],
                'tasks' => [
                    ['key' => 'content', 'title' => 'Homepage content due', 'milestone' => 'kickoff', 'anchor' => 'project_start', 'offset' => 5, 'client_visible' => true],
                    ['key' => 'mockup', 'title' => 'Initial mock-up', 'milestone' => 'design', 'anchor' => 'project_start', 'offset' => 10, 'blocked_by' => ['task:content']],
                    ['key' => 'buildhome', 'title' => 'Build homepage', 'milestone' => 'build', 'anchor' => 'project_start', 'offset' => 16, 'blocked_by' => ['milestone:review']],
                    ['key' => 'qa', 'title' => 'Pre-launch QA', 'milestone' => 'launch', 'anchor' => 'project_target', 'offset' => -2, 'blocked_by' => ['task:buildhome']],
                ],
            ],
            'ecommerce_website' => $simple('Ecommerce website', 'A shop build with catalogue + checkout.', 'ecommerce'),
            'landing_page'      => $simple('Landing page', 'A single high-converting landing page.', 'landing'),
            'website_redesign'  => $simple('Website redesign', 'Rework of an existing site.', 'redesign'),
            'seo_project'       => $simple('SEO project', 'An SEO engagement.', 'seo'),
            'hosting_migration' => $simple('Hosting migration', 'Move a site to new hosting.', 'migration'),
            'website_maintenance' => $simple('Website maintenance', 'Ongoing care work.', 'maintenance'),
            'branding_project'  => $simple('Branding project', 'Brand identity work.', 'branding'),
            'general_digital'   => $simple('General digital project', 'A general engagement.', 'general'),
        ];
    }

    /** Idempotently seed built-in templates as published version 1. */
    public function seedBuiltins(): void
    {
        foreach (self::builtins() as $slug => $def) {
            $uuid = sha1('project-builtin:' . $slug);
            if ($this->store->exists(self::COLLECTION, $uuid)) {
                continue;
            }
            $now = Clock::nowIso();
            $version = $this->buildVersion(1, 'published', 'Built-in', $now, $def);
            $this->store->putIfAbsent(self::COLLECTION, $uuid, [
                'uuid'            => $uuid,
                'slug'            => $slug,
                'name'            => (string) $def['name'],
                'description'     => (string) $def['description'],
                'category'        => (string) $def['category'],
                'project_type'    => (string) $def['project_type'],
                'current_version' => 1,
                'builtin'         => 1,
                'archived'        => 0,
                'versions'        => [$version],
                'created_at'      => $now,
                'updated_at'      => $now,
            ]);
        }
    }

    // ==================================================================
    // Read
    // ==================================================================

    /** @return list<array<string,mixed>> */
    public function list(): array
    {
        $this->seedBuiltins();
        $rows = array_values(array_filter($this->store->all(self::COLLECTION), static fn (array $t): bool => (int) ($t['archived'] ?? 0) === 0));
        usort($rows, static fn ($a, $b) => strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? '')));

        return $rows;
    }

    /** @return array<string,mixed>|null */
    public function find(string $uuid): ?array
    {
        $t = $this->store->find(self::COLLECTION, $uuid);
        if ($t === null) {
            return null;
        }
        $versions = is_array($t['versions'] ?? null) ? array_values($t['versions']) : [];
        usort($versions, static fn ($a, $b) => (int) ($b['version'] ?? 0) <=> (int) ($a['version'] ?? 0));
        $t['versions'] = $versions;

        return $t;
    }

    /** @return array<string,mixed>|null the published version's rows */
    public function publishedVersion(string $templateUuid): ?array
    {
        $t = $this->store->find(self::COLLECTION, $templateUuid);
        if ($t === null || (int) ($t['current_version'] ?? 0) === 0) {
            return null;
        }

        return $this->versionRows($templateUuid, (int) $t['current_version']);
    }

    /** @return array<string,mixed>|null */
    public function versionRows(string $templateUuid, int $version): ?array
    {
        $t = $this->store->find(self::COLLECTION, $templateUuid);
        if ($t === null) {
            return null;
        }
        foreach (is_array($t['versions'] ?? null) ? $t['versions'] : [] as $v) {
            if ((int) ($v['version'] ?? 0) === $version) {
                return $v;
            }
        }

        return null;
    }

    // ==================================================================
    // Authoring
    // ==================================================================

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function create(array $data, string $actor): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new ProjectException(422, 'Enter a template name.');
        }
        $slug = trim((string) ($data['slug'] ?? '')) ?: preg_replace('/[^a-z0-9]+/', '_', strtolower($name));
        $slug = trim((string) $slug, '_');
        if ($slug === '' || $this->slugTaken((string) $slug)) {
            $slug = ($slug ?: 'template') . '_' . substr(bin2hex(random_bytes(3)), 0, 4);
        }
        $uuid = Uuid::v4();
        $now = Clock::nowIso();
        $version = $this->buildVersion(1, 'draft', (string) ($data['notes'] ?? ''), $now, ['milestones' => $data['milestones'] ?? [], 'tasks' => $data['tasks'] ?? []]);
        $this->store->put(self::COLLECTION, [
            'uuid'            => $uuid,
            'slug'            => (string) $slug,
            'name'            => $name,
            'description'     => (string) ($data['description'] ?? ''),
            'category'        => (string) ($data['category'] ?? ''),
            'project_type'    => (string) ($data['project_type'] ?? ''),
            'current_version' => 0,
            'builtin'         => 0,
            'archived'        => 0,
            'versions'        => [$version],
            'created_at'      => $now,
            'updated_at'      => $now,
        ]);

        return $this->find($uuid) ?? [];
    }

    /**
     * Start a new DRAFT version by cloning the latest version's rows, so authors
     * edit a copy and never mutate a published (and possibly applied) version.
     *
     * @return array<string,mixed>
     */
    public function newDraftVersion(string $templateUuid, string $actor): array
    {
        $t = $this->store->find(self::COLLECTION, $templateUuid);
        if ($t === null) {
            throw new ProjectException(404, 'Template not found.');
        }
        $latest = 0;
        foreach (is_array($t['versions'] ?? null) ? $t['versions'] : [] as $v) {
            $latest = max($latest, (int) ($v['version'] ?? 0));
        }
        $next = $latest + 1;
        $now = Clock::nowIso();
        $prev = $latest > 0 ? $this->versionRows($templateUuid, $latest) : null;
        $def = $prev !== null ? [
            'milestones' => array_map([$this, 'rowToDef'], $prev['milestones'] ?? []),
            'tasks'      => array_map([$this, 'rowToDefTask'], $prev['tasks'] ?? []),
        ] : [];
        $version = $this->buildVersion($next, 'draft', '', $now, $def);
        $this->store->update(self::COLLECTION, $templateUuid, static function (array $row) use ($version, $now): array {
            $row['versions'][] = $version;
            $row['updated_at'] = $now;

            return $row;
        });

        return $this->find($templateUuid) ?? [];
    }

    /** @return array<string,mixed> */
    public function publish(string $templateUuid, int $version, string $actor): array
    {
        $v = $this->versionRows($templateUuid, $version);
        if ($v === null) {
            throw new ProjectException(404, 'Template version not found.');
        }
        if ((string) $v['status'] === 'published') {
            throw new ProjectException(409, 'That version is already published.');
        }
        $now = Clock::nowIso();
        $this->store->update(self::COLLECTION, $templateUuid, static function (array $row) use ($version, $now): array {
            $versions = is_array($row['versions'] ?? null) ? $row['versions'] : [];
            foreach ($versions as $i => $ver) {
                if ((int) ($ver['version'] ?? 0) === $version) {
                    $versions[$i]['status']       = 'published';
                    $versions[$i]['published_at'] = $now;
                }
            }
            $row['versions']        = $versions;
            $row['current_version'] = $version;
            $row['updated_at']      = $now;

            return $row;
        });

        return $this->find($templateUuid) ?? [];
    }

    // ==================================================================
    // Apply
    // ==================================================================

    /**
     * Create milestones + tasks on a project from a template's published version.
     * Resolves relative business-day dates and intra-template dependencies, and
     * records the frozen template + version on the project.
     *
     * @return array{milestones:int,tasks:int,version:int}
     */
    public function applyToProject(string $projectUuid, string $templateUuid, string $actor, ?string $startOverride = null): array
    {
        $project = $this->store->find('projects', $projectUuid);
        if ($project === null) {
            throw new ProjectException(404, 'Project not found.');
        }
        $version = $this->publishedVersion($templateUuid);
        if ($version === null) {
            throw new ProjectException(409, 'This template has no published version to apply.');
        }
        $start  = $startOverride ?: (string) ($project['start_date'] ?? '') ?: date('Y-m-d');
        $target = (string) ($project['target_date'] ?? '') ?: BusinessDays::add($start, 20);
        $resolve = static function (string $anchor, int $offset) use ($start, $target): ?string {
            $base = $anchor === 'project_target' ? $target : $start;

            return $base === '' ? null : BusinessDays::add($base, $offset);
        };

        $msMap = [];   // tkey => new milestone uuid
        foreach ($version['milestones'] as $m) {
            $created = $this->platformMilestones()->create($projectUuid, [
                'title' => (string) $m['title'], 'description' => (string) $m['description'],
                'due_date' => $resolve((string) $m['due_anchor'], (int) $m['due_offset']),
                'client_visible' => (int) $m['client_visible'] === 1,
            ], $actor);
            $msMap[(string) $m['tkey']] = (string) $created['uuid'];
        }
        // Milestone dependencies.
        foreach ($version['milestones'] as $m) {
            foreach (array_filter(explode(',', (string) $m['blocked_by'])) as $depKey) {
                if (isset($msMap[$depKey], $msMap[(string) $m['tkey']])) {
                    $this->platformMilestones()->addDependency($msMap[(string) $m['tkey']], $msMap[$depKey], $actor);
                }
            }
        }
        $taskMap = [];
        foreach ($version['tasks'] as $t) {
            $created = $this->platformTasks()->create($projectUuid, [
                'title' => (string) $t['title'], 'description' => (string) $t['description'],
                'milestone_uuid' => $msMap[(string) $t['milestone_key']] ?? null,
                'due_date' => $resolve((string) $t['due_anchor'], (int) $t['due_offset']),
                'estimate_seconds' => (int) $t['estimate_seconds'],
                'client_visible' => (int) $t['client_visible'] === 1,
                'source' => 'template',
            ], $actor);
            $taskMap[(string) $t['tkey']] = (string) $created['uuid'];
        }
        // Task dependencies (task:<key> or milestone:<key>).
        foreach ($version['tasks'] as $t) {
            foreach (array_filter(explode(',', (string) $t['blocked_by'])) as $dep) {
                [$kind, $key] = array_pad(explode(':', $dep, 2), 2, '');
                $blockerUuid = $kind === 'milestone' ? ($msMap[$key] ?? null) : ($taskMap[$key] ?? null);
                if ($blockerUuid !== null && isset($taskMap[(string) $t['tkey']])) {
                    $this->platformTasks()->addDependency($taskMap[(string) $t['tkey']], $kind . ':' . $blockerUuid, $actor);
                }
            }
        }
        $this->store->update('projects', $projectUuid, static function (array $row) use ($templateUuid, $version): array {
            $row['template_uuid']    = $templateUuid;
            $row['template_version'] = (int) $version['version'];
            $row['updated_at']       = Clock::nowIso();

            return $row;
        });

        return ['milestones' => count($msMap), 'tasks' => count($taskMap), 'version' => (int) $version['version']];
    }

    // ==================================================================
    // Internals
    // ==================================================================

    private function platformMilestones(): Milestones
    {
        return new Milestones($this->store);
    }

    private function platformTasks(): ProjectTasks
    {
        return new ProjectTasks($this->store);
    }

    private function slugTaken(string $slug): bool
    {
        foreach ($this->store->all(self::COLLECTION) as $t) {
            if ((string) ($t['slug'] ?? '') === $slug) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build a frozen version record (its milestones/tasks embedded).
     *
     * @param array<string,mixed> $def
     * @return array<string,mixed>
     */
    private function buildVersion(int $version, string $status, string $notes, string $now, array $def): array
    {
        $milestones = [];
        $order = 0;
        foreach (is_array($def['milestones'] ?? null) ? $def['milestones'] : [] as $m) {
            if (!is_array($m)) {
                continue;
            }
            $milestones[] = [
                'uuid' => Uuid::v4(), 'tkey' => (string) ($m['key'] ?? ('ms' . $order)), 'title' => (string) ($m['title'] ?? ''),
                'description' => (string) ($m['description'] ?? ''),
                'due_anchor' => in_array((string) ($m['anchor'] ?? 'project_start'), ['project_start', 'project_target'], true) ? (string) ($m['anchor'] ?? 'project_start') : 'project_start',
                'due_offset' => (int) ($m['offset'] ?? 0), 'client_visible' => array_key_exists('client_visible', $m) ? (!empty($m['client_visible']) ? 1 : 0) : 1,
                'sort_order' => $order++, 'blocked_by' => implode(',', is_array($m['blocked_by'] ?? null) ? $m['blocked_by'] : []), 'is_approval' => !empty($m['is_approval']) ? 1 : 0,
            ];
        }
        $tasks = [];
        $order = 0;
        foreach (is_array($def['tasks'] ?? null) ? $def['tasks'] : [] as $t) {
            if (!is_array($t)) {
                continue;
            }
            $tasks[] = [
                'uuid' => Uuid::v4(), 'tkey' => (string) ($t['key'] ?? ('task' . $order)), 'milestone_key' => (string) ($t['milestone'] ?? ''),
                'title' => (string) ($t['title'] ?? ''), 'description' => (string) ($t['description'] ?? ''),
                'due_anchor' => in_array((string) ($t['anchor'] ?? 'project_start'), ['project_start', 'project_target'], true) ? (string) ($t['anchor'] ?? 'project_start') : 'project_start',
                'due_offset' => (int) ($t['offset'] ?? 0), 'estimate_seconds' => (int) ($t['estimate_seconds'] ?? 0),
                'client_visible' => !empty($t['client_visible']) ? 1 : 0, 'sort_order' => $order++, 'blocked_by' => implode(',', is_array($t['blocked_by'] ?? null) ? $t['blocked_by'] : []),
            ];
        }

        return [
            'uuid'         => Uuid::v4(),
            'version'      => $version,
            'status'       => $status,
            'notes'        => $notes,
            'published_at' => $status === 'published' ? $now : null,
            'created_at'   => $now,
            'milestones'   => $milestones,
            'tasks'        => $tasks,
        ];
    }

    /**
     * @param array<string,mixed> $r
     * @return array<string,mixed>
     */
    private function rowToDef(array $r): array
    {
        return [
            'key' => (string) $r['tkey'], 'title' => (string) $r['title'], 'description' => (string) $r['description'],
            'anchor' => (string) $r['due_anchor'], 'offset' => (int) $r['due_offset'], 'client_visible' => (int) $r['client_visible'] === 1,
            'blocked_by' => array_filter(explode(',', (string) $r['blocked_by'])), 'is_approval' => (int) $r['is_approval'] === 1,
        ];
    }

    /**
     * @param array<string,mixed> $r
     * @return array<string,mixed>
     */
    private function rowToDefTask(array $r): array
    {
        return [
            'key' => (string) $r['tkey'], 'milestone' => (string) $r['milestone_key'], 'title' => (string) $r['title'], 'description' => (string) $r['description'],
            'anchor' => (string) $r['due_anchor'], 'offset' => (int) $r['due_offset'], 'estimate_seconds' => (int) $r['estimate_seconds'],
            'client_visible' => (int) $r['client_visible'] === 1, 'blocked_by' => array_filter(explode(',', (string) $r['blocked_by'])),
        ];
    }
}
