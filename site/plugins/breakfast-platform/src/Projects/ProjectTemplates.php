<?php

declare(strict_types=1);

namespace Breakfast\Platform\Projects;

use Breakfast\Platform\Support\BusinessDays;
use Breakfast\Platform\Support\Clock;
use Breakfast\Platform\Support\Database;
use Breakfast\Platform\Support\Uuid;

/**
 * Versioned project templates. Built-ins are seeded once into the database as
 * published version 1; custom templates and new versions live in the same
 * tables. Applying a template to a project copies the frozen version's
 * milestone/task rows into project-owned records with resolved business-day
 * dates and dependency links, and records the template + version on the project.
 * A later template edit is a new version and never mutates existing projects.
 */
final class ProjectTemplates
{
    public function __construct(private readonly Database $db)
    {
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
            if ($this->db->one('SELECT uuid FROM project_templates WHERE slug = :s', ['s' => $slug]) !== null) {
                continue;
            }
            $this->db->transaction(function (Database $db) use ($slug, $def): void {
                $tUuid = Uuid::v4();
                $now = Clock::nowIso();
                $db->run(
                    'INSERT INTO project_templates (uuid, slug, name, description, category, project_type, current_version, builtin, created_at, updated_at)
                     VALUES (:uuid, :slug, :name, :desc, :cat, :type, 1, 1, :now, :now)',
                    ['uuid' => $tUuid, 'slug' => $slug, 'name' => (string) $def['name'], 'desc' => (string) $def['description'], 'cat' => (string) $def['category'], 'type' => (string) $def['project_type'], 'now' => $now]
                );
                $vUuid = $this->insertVersion($db, $tUuid, 1, 'published', 'Built-in', $now);
                $this->writeRows($db, $vUuid, $def);
            });
        }
    }

    // ==================================================================
    // Read
    // ==================================================================

    /** @return list<array<string,mixed>> */
    public function list(): array
    {
        $this->seedBuiltins();

        return $this->db->all('SELECT * FROM project_templates WHERE archived = 0 ORDER BY name ASC');
    }

    /** @return array<string,mixed>|null */
    public function find(string $uuid): ?array
    {
        $t = $this->db->one('SELECT * FROM project_templates WHERE uuid = :u', ['u' => $uuid]);
        if ($t === null) {
            return null;
        }
        $t['versions'] = $this->db->all('SELECT * FROM project_template_versions WHERE template_uuid = :u ORDER BY version DESC', ['u' => $uuid]);

        return $t;
    }

    /** @return array<string,mixed>|null the published version's rows */
    public function publishedVersion(string $templateUuid): ?array
    {
        $t = $this->db->one('SELECT current_version FROM project_templates WHERE uuid = :u', ['u' => $templateUuid]);
        if ($t === null || (int) $t['current_version'] === 0) {
            return null;
        }

        return $this->versionRows($templateUuid, (int) $t['current_version']);
    }

    /** @return array<string,mixed>|null */
    public function versionRows(string $templateUuid, int $version): ?array
    {
        $v = $this->db->one('SELECT * FROM project_template_versions WHERE template_uuid = :t AND version = :v', ['t' => $templateUuid, 'v' => $version]);
        if ($v === null) {
            return null;
        }
        $v['milestones'] = $this->db->all('SELECT * FROM project_template_milestones WHERE version_uuid = :v ORDER BY sort_order ASC', ['v' => (string) $v['uuid']]);
        $v['tasks']      = $this->db->all('SELECT * FROM project_template_tasks WHERE version_uuid = :v ORDER BY sort_order ASC', ['v' => (string) $v['uuid']]);

        return $v;
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
        if ($this->db->one('SELECT uuid FROM project_templates WHERE slug = :s', ['s' => $slug]) !== null) {
            $slug .= '_' . substr(bin2hex(random_bytes(3)), 0, 4);
        }
        $uuid = Uuid::v4();
        $now = Clock::nowIso();
        $this->db->run(
            'INSERT INTO project_templates (uuid, slug, name, description, category, project_type, current_version, builtin, created_at, updated_at)
             VALUES (:uuid, :slug, :name, :desc, :cat, :type, 0, 0, :now, :now)',
            ['uuid' => $uuid, 'slug' => (string) $slug, 'name' => $name, 'desc' => (string) ($data['description'] ?? ''), 'cat' => (string) ($data['category'] ?? ''), 'type' => (string) ($data['project_type'] ?? ''), 'now' => $now]
        );
        // Seed a first draft version (empty or from provided milestones/tasks).
        $vUuid = $this->insertVersion($this->db, $uuid, 1, 'draft', (string) ($data['notes'] ?? ''), $now);
        $this->writeRows($this->db, $vUuid, ['milestones' => $data['milestones'] ?? [], 'tasks' => $data['tasks'] ?? []]);

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
        $t = $this->db->one('SELECT * FROM project_templates WHERE uuid = :u', ['u' => $templateUuid]);
        if ($t === null) {
            throw new ProjectException(404, 'Template not found.');
        }
        $latest = (int) $this->db->scalar('SELECT COALESCE(MAX(version),0) FROM project_template_versions WHERE template_uuid = :t', ['t' => $templateUuid]);
        $next = $latest + 1;
        $now = Clock::nowIso();

        return $this->db->transaction(function (Database $db) use ($templateUuid, $latest, $next, $now): array {
            $vUuid = $this->insertVersion($db, $templateUuid, $next, 'draft', '', $now);
            if ($latest > 0) {
                $prev = $this->versionRows($templateUuid, $latest);
                $this->writeRows($db, $vUuid, [
                    'milestones' => array_map([$this, 'rowToDef'], $prev['milestones'] ?? []),
                    'tasks'      => array_map([$this, 'rowToDefTask'], $prev['tasks'] ?? []),
                ]);
            }

            return $this->find($templateUuid) ?? [];
        });
    }

    /** @return array<string,mixed> */
    public function publish(string $templateUuid, int $version, string $actor): array
    {
        $v = $this->db->one('SELECT uuid, status FROM project_template_versions WHERE template_uuid = :t AND version = :v', ['t' => $templateUuid, 'v' => $version]);
        if ($v === null) {
            throw new ProjectException(404, 'Template version not found.');
        }
        if ((string) $v['status'] === 'published') {
            throw new ProjectException(409, 'That version is already published.');
        }
        $this->db->transaction(function (Database $db) use ($templateUuid, $version, $v): void {
            $db->run("UPDATE project_template_versions SET status = 'published', published_at = :now WHERE uuid = :u", ['now' => Clock::nowIso(), 'u' => (string) $v['uuid']]);
            $db->run('UPDATE project_templates SET current_version = :v, updated_at = :now WHERE uuid = :u', ['v' => $version, 'now' => Clock::nowIso(), 'u' => $templateUuid]);
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
        $project = $this->db->one('SELECT * FROM projects WHERE uuid = :u', ['u' => $projectUuid]);
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

        return $this->db->transaction(function (Database $db) use ($projectUuid, $templateUuid, $version, $resolve, $actor): array {
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
            $db->run('UPDATE projects SET template_uuid = :t, template_version = :v, updated_at = :now WHERE uuid = :u', ['t' => $templateUuid, 'v' => (int) $version['version'], 'now' => Clock::nowIso(), 'u' => $projectUuid]);

            return ['milestones' => count($msMap), 'tasks' => count($taskMap), 'version' => (int) $version['version']];
        });
    }

    // ==================================================================
    // Internals
    // ==================================================================

    private function platformMilestones(): Milestones
    {
        return new Milestones($this->db);
    }

    private function platformTasks(): ProjectTasks
    {
        return new ProjectTasks($this->db);
    }

    private function insertVersion(Database $db, string $templateUuid, int $version, string $status, string $notes, string $now): string
    {
        $uuid = Uuid::v4();
        $db->run(
            'INSERT INTO project_template_versions (uuid, template_uuid, version, status, notes, published_at, created_at)
             VALUES (:uuid, :t, :v, :status, :notes, :pub, :now)',
            ['uuid' => $uuid, 't' => $templateUuid, 'v' => $version, 'status' => $status, 'notes' => $notes, 'pub' => $status === 'published' ? $now : null, 'now' => $now]
        );

        return $uuid;
    }

    /** @param array<string,mixed> $def */
    private function writeRows(Database $db, string $versionUuid, array $def): void
    {
        $now = Clock::nowIso();
        $order = 0;
        foreach (is_array($def['milestones'] ?? null) ? $def['milestones'] : [] as $m) {
            if (!is_array($m)) {
                continue;
            }
            $db->run(
                'INSERT INTO project_template_milestones (uuid, version_uuid, tkey, title, description, due_anchor, due_offset, client_visible, sort_order, blocked_by, is_approval, created_at)
                 VALUES (:uuid, :v, :tkey, :title, :desc, :anchor, :offset, :cv, :order, :blocked, :appr, :now)',
                [
                    'uuid' => Uuid::v4(), 'v' => $versionUuid, 'tkey' => (string) ($m['key'] ?? ('ms' . $order)), 'title' => (string) ($m['title'] ?? ''),
                    'desc' => (string) ($m['description'] ?? ''), 'anchor' => in_array((string) ($m['anchor'] ?? 'project_start'), ['project_start', 'project_target'], true) ? (string) ($m['anchor'] ?? 'project_start') : 'project_start',
                    'offset' => (int) ($m['offset'] ?? 0), 'cv' => array_key_exists('client_visible', $m) ? (!empty($m['client_visible']) ? 1 : 0) : 1,
                    'order' => $order++, 'blocked' => implode(',', is_array($m['blocked_by'] ?? null) ? $m['blocked_by'] : []), 'appr' => !empty($m['is_approval']) ? 1 : 0, 'now' => $now,
                ]
            );
        }
        $order = 0;
        foreach (is_array($def['tasks'] ?? null) ? $def['tasks'] : [] as $t) {
            if (!is_array($t)) {
                continue;
            }
            $db->run(
                'INSERT INTO project_template_tasks (uuid, version_uuid, tkey, milestone_key, title, description, due_anchor, due_offset, estimate_seconds, client_visible, sort_order, blocked_by, created_at)
                 VALUES (:uuid, :v, :tkey, :mkey, :title, :desc, :anchor, :offset, :est, :cv, :order, :blocked, :now)',
                [
                    'uuid' => Uuid::v4(), 'v' => $versionUuid, 'tkey' => (string) ($t['key'] ?? ('task' . $order)), 'mkey' => (string) ($t['milestone'] ?? ''),
                    'title' => (string) ($t['title'] ?? ''), 'desc' => (string) ($t['description'] ?? ''),
                    'anchor' => in_array((string) ($t['anchor'] ?? 'project_start'), ['project_start', 'project_target'], true) ? (string) ($t['anchor'] ?? 'project_start') : 'project_start',
                    'offset' => (int) ($t['offset'] ?? 0), 'est' => (int) ($t['estimate_seconds'] ?? 0),
                    'cv' => !empty($t['client_visible']) ? 1 : 0, 'order' => $order++, 'blocked' => implode(',', is_array($t['blocked_by'] ?? null) ? $t['blocked_by'] : []), 'now' => $now,
                ]
            );
        }
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
