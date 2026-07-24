<?php

declare(strict_types=1);

namespace Breakfast\Platform\Projects;

use Breakfast\Platform\Support\Clock;
use Breakfast\Platform\Support\Database;
use Breakfast\Platform\Support\Uuid;

/**
 * Delivery tasks + board. Project-owned, distinct from the CRM `tasks` table.
 *
 * Board moves are real, server-validated transitions with optimistic
 * concurrency. A task with an incomplete blocker (another task or a milestone)
 * cannot progress into in_progress/review/completed — the dependency genuinely
 * gates progression. Completing a task never auto-completes anything else.
 */
final class ProjectTasks
{
    /** @var list<string> */
    public const STATUSES = ['backlog', 'ready', 'in_progress', 'awaiting_client', 'blocked', 'review', 'completed', 'cancelled'];

    /** States a task may not enter while a blocker is incomplete. */
    private const GATED = ['in_progress', 'review', 'completed'];

    /** @var array<string,list<string>> */
    private const TRANSITIONS = [
        'backlog'         => ['ready', 'in_progress', 'blocked', 'cancelled'],
        'ready'           => ['backlog', 'in_progress', 'awaiting_client', 'blocked', 'cancelled'],
        'in_progress'     => ['ready', 'awaiting_client', 'blocked', 'review', 'completed', 'cancelled'],
        'awaiting_client' => ['ready', 'in_progress', 'blocked', 'review', 'completed', 'cancelled'],
        'blocked'         => ['backlog', 'ready', 'in_progress', 'cancelled'],
        'review'          => ['in_progress', 'awaiting_client', 'completed', 'cancelled'],
        'completed'       => ['in_progress'],
        'cancelled'       => ['backlog'],
    ];

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @param array<string,mixed> $filters
     * @return list<array<string,mixed>>
     */
    public function forProject(string $projectUuid, array $filters = []): array
    {
        $where = ['project_uuid = :p', 'archived = 0'];
        $params = ['p' => $projectUuid];
        if (!empty($filters['status'])) {
            $where[] = 'status = :status';
            $params['status'] = (string) $filters['status'];
        }
        if (!empty($filters['milestone_uuid'])) {
            $where[] = 'milestone_uuid = :ms';
            $params['ms'] = (string) $filters['milestone_uuid'];
        }
        $rows = $this->db->all('SELECT * FROM project_tasks WHERE ' . implode(' AND ', $where) . ' ORDER BY sort_order ASC, created_at ASC', $params);

        return array_map(fn (array $t): array => $this->withDerived($t), $rows);
    }

    /** @return array<string,mixed>|null */
    public function find(string $uuid): ?array
    {
        $t = $this->db->one('SELECT * FROM project_tasks WHERE uuid = :u', ['u' => $uuid]);

        return $t === null ? null : $this->withDerived($t);
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function create(string $projectUuid, array $data, string $actor): array
    {
        if ($this->db->one('SELECT uuid FROM projects WHERE uuid = :u', ['u' => $projectUuid]) === null) {
            throw new ProjectException(404, 'Project not found.');
        }
        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            throw new ProjectException(422, 'Enter a task title.');
        }
        $uuid = Uuid::v4();
        $now  = Clock::nowIso();
        $order = (int) $this->db->scalar('SELECT COALESCE(MAX(sort_order),0) + 1 FROM project_tasks WHERE project_uuid = :p', ['p' => $projectUuid]);
        $this->db->run(
            'INSERT INTO project_tasks (uuid, project_uuid, milestone_uuid, title, description, owner, assignees, status, priority, start_date, due_date, estimate_seconds, billable, client_visible, labels, source, source_ref, sort_order, created_by, created_at, updated_at)
             VALUES (:uuid, :p, :ms, :title, :desc, :owner, :assignees, :status, :priority, :start, :due, :est, :billable, :cv, :labels, :source, :sref, :order, :actor, :now, :now)',
            [
                'uuid' => $uuid, 'p' => $projectUuid, 'ms' => $this->nullable($data['milestone_uuid'] ?? null),
                'title' => $title, 'desc' => (string) ($data['description'] ?? ''), 'owner' => (string) ($data['owner'] ?? ''),
                'assignees' => $this->encodeList($data['assignees'] ?? []),
                'status' => in_array((string) ($data['status'] ?? 'backlog'), self::STATUSES, true) ? (string) ($data['status'] ?? 'backlog') : 'backlog',
                'priority' => (string) ($data['priority'] ?? 'normal'),
                'start' => $this->nullable($data['start_date'] ?? null), 'due' => $this->nullable($data['due_date'] ?? null),
                'est' => max(0, (int) ($data['estimate_seconds'] ?? 0)),
                'billable' => array_key_exists('billable', $data) ? (!empty($data['billable']) ? 1 : 0) : 1,
                'cv' => !empty($data['client_visible']) ? 1 : 0,
                'labels' => $this->encodeList($data['labels'] ?? []),
                'source' => (string) ($data['source'] ?? 'manual'), 'sref' => (string) ($data['source_ref'] ?? ''),
                'order' => $order, 'actor' => $actor, 'now' => $now,
            ]
        );

        return $this->find($uuid) ?? [];
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function update(string $uuid, array $data, string $actor, ?int $expectedRevision = null): array
    {
        $t = $this->db->one('SELECT revision FROM project_tasks WHERE uuid = :u', ['u' => $uuid]);
        if ($t === null) {
            throw new ProjectException(404, 'Task not found.');
        }
        if ($expectedRevision !== null && (int) $t['revision'] !== $expectedRevision) {
            throw new ProjectException(409, 'This task was changed by someone else. Reload and try again.');
        }
        $sets = ['updated_at = :now', 'revision = revision + 1'];
        $params = ['u' => $uuid, 'now' => Clock::nowIso()];
        foreach (['title', 'description', 'owner', 'start_date', 'due_date', 'priority'] as $f) {
            if (array_key_exists($f, $data)) {
                $sets[] = "$f = :$f";
                $params[$f] = in_array($f, ['start_date', 'due_date'], true) ? $this->nullable($data[$f]) : (string) $data[$f];
            }
        }
        if (array_key_exists('milestone_uuid', $data)) {
            $sets[] = 'milestone_uuid = :ms';
            $params['ms'] = $this->nullable($data['milestone_uuid']);
        }
        foreach (['assignees', 'labels'] as $list) {
            if (array_key_exists($list, $data)) {
                $sets[] = "$list = :$list";
                $params[$list] = $this->encodeList($data[$list]);
            }
        }
        if (array_key_exists('estimate_seconds', $data)) {
            $sets[] = 'estimate_seconds = :est';
            $params['est'] = max(0, (int) $data['estimate_seconds']);
        }
        foreach (['billable', 'client_visible'] as $bool) {
            if (array_key_exists($bool, $data)) {
                $sets[] = "$bool = :$bool";
                $params[$bool] = !empty($data[$bool]) ? 1 : 0;
            }
        }
        $this->db->run('UPDATE project_tasks SET ' . implode(', ', $sets) . ' WHERE uuid = :u', $params);

        return $this->find($uuid) ?? [];
    }

    /**
     * Board move — validated transition + dependency gate + optimistic concurrency.
     *
     * @return array<string,mixed>
     */
    public function move(string $uuid, string $to, string $actor, ?int $expectedRevision = null): array
    {
        $t = $this->db->one('SELECT * FROM project_tasks WHERE uuid = :u', ['u' => $uuid]);
        if ($t === null) {
            throw new ProjectException(404, 'Task not found.');
        }
        if ($expectedRevision !== null && (int) $t['revision'] !== $expectedRevision) {
            throw new ProjectException(409, 'This task was changed by someone else. Reload and try again.');
        }
        $from = (string) $t['status'];
        if (!in_array($to, self::STATUSES, true)) {
            throw new ProjectException(422, 'Unknown task status.');
        }
        if ($from === $to) {
            return $this->find($uuid) ?? [];
        }
        if (!in_array($to, self::TRANSITIONS[$from] ?? [], true)) {
            throw new ProjectException(409, "Cannot move a task from {$from} to {$to}.");
        }
        if (in_array($to, self::GATED, true) && !$this->isReady($uuid)) {
            throw new ProjectException(409, 'This task is blocked by an incomplete dependency.');
        }
        $sets = ['status = :to', 'updated_at = :now', 'revision = revision + 1'];
        $params = ['u' => $uuid, 'to' => $to, 'now' => Clock::nowIso()];
        if ($to === 'completed') {
            $sets[] = 'completed_date = :cd';
            $params['cd'] = date('Y-m-d');
        } elseif ($from === 'completed') {
            $sets[] = 'completed_date = NULL';
        }
        $this->db->run('UPDATE project_tasks SET ' . implode(', ', $sets) . ' WHERE uuid = :u', $params);

        return $this->find($uuid) ?? [];
    }

    /** @param list<string> $orderedUuids */
    public function reorder(string $projectUuid, array $orderedUuids, string $actor): void
    {
        $this->db->transaction(function (Database $db) use ($projectUuid, $orderedUuids): void {
            $i = 1;
            foreach ($orderedUuids as $uuid) {
                $db->run('UPDATE project_tasks SET sort_order = :o, updated_at = :now WHERE uuid = :u AND project_uuid = :p', ['o' => $i++, 'now' => Clock::nowIso(), 'u' => (string) $uuid, 'p' => $projectUuid]);
            }
        });
    }

    /**
     * Bulk status change / assign / archive across many tasks in one transaction.
     *
     * @param list<string> $uuids
     * @param array<string,mixed> $changes
     * @return array{updated:int}
     */
    public function bulk(array $uuids, array $changes, string $actor): array
    {
        $count = 0;
        $this->db->transaction(function (Database $db) use ($uuids, $changes, $actor, &$count): void {
            foreach ($uuids as $uuid) {
                $uuid = (string) $uuid;
                try {
                    if (isset($changes['status'])) {
                        $this->move($uuid, (string) $changes['status'], $actor);
                    }
                    if (array_key_exists('assignees', $changes) || array_key_exists('archived', $changes)) {
                        if (!empty($changes['archived'])) {
                            $db->run('UPDATE project_tasks SET archived = 1, updated_at = :now WHERE uuid = :u', ['now' => Clock::nowIso(), 'u' => $uuid]);
                        } elseif (array_key_exists('assignees', $changes)) {
                            $this->update($uuid, ['assignees' => $changes['assignees']], $actor);
                        }
                    }
                    $count++;
                } catch (ProjectException) {
                    // Skip individual invalid moves; report the count actually applied.
                }
            }
        });

        return ['updated' => $count];
    }

    public function archive(string $uuid): void
    {
        $this->db->run('UPDATE project_tasks SET archived = 1, updated_at = :now WHERE uuid = :u', ['now' => Clock::nowIso(), 'u' => $uuid]);
    }

    public function restore(string $uuid): void
    {
        $this->db->run('UPDATE project_tasks SET archived = 0, updated_at = :now WHERE uuid = :u', ['now' => Clock::nowIso(), 'u' => $uuid]);
    }

    // ==================================================================
    // Dependencies
    // ==================================================================

    /**
     * @return array<string,mixed>
     */
    public function addDependency(string $taskUuid, string $blockedBy, string $actor): array
    {
        $t = $this->db->one('SELECT project_uuid FROM project_tasks WHERE uuid = :u', ['u' => $taskUuid]);
        if ($t === null) {
            throw new ProjectException(404, 'Task not found.');
        }
        [$kind, $ref] = array_pad(explode(':', $blockedBy, 2), 2, '');
        if (!in_array($kind, ['task', 'milestone'], true) || $ref === '') {
            throw new ProjectException(422, 'A blocker must be task:<uuid> or milestone:<uuid>.');
        }
        if ($blockedBy === 'task:' . $taskUuid) {
            throw new ProjectException(422, 'A task cannot depend on itself.');
        }
        if ($kind === 'task' && $this->dependsOn($ref, $taskUuid)) {
            throw new ProjectException(409, 'That would create a circular dependency.');
        }
        $this->db->run(
            'INSERT INTO task_dependencies (uuid, project_uuid, task_uuid, blocked_by, created_at)
             VALUES (:uuid, :p, :t, :b, :now) ON CONFLICT (task_uuid, blocked_by) DO NOTHING',
            ['uuid' => Uuid::v4(), 'p' => (string) $t['project_uuid'], 't' => $taskUuid, 'b' => $blockedBy, 'now' => Clock::nowIso()]
        );

        return $this->find($taskUuid) ?? [];
    }

    public function removeDependency(string $taskUuid, string $blockedBy): void
    {
        $this->db->run('DELETE FROM task_dependencies WHERE task_uuid = :t AND blocked_by = :b', ['t' => $taskUuid, 'b' => $blockedBy]);
    }

    /** Ready when every blocker (task or milestone) is completed/cancelled. */
    public function isReady(string $taskUuid): bool
    {
        foreach ($this->db->all('SELECT blocked_by FROM task_dependencies WHERE task_uuid = :t', ['t' => $taskUuid]) as $edge) {
            [$kind, $ref] = array_pad(explode(':', (string) $edge['blocked_by'], 2), 2, '');
            $table = $kind === 'milestone' ? 'milestones' : 'project_tasks';
            $status = (string) $this->db->scalar("SELECT status FROM {$table} WHERE uuid = :u", ['u' => $ref]);
            if (!in_array($status, ['completed', 'cancelled'], true)) {
                return false;
            }
        }

        return true;
    }

    // ==================================================================
    // Checklist
    // ==================================================================

    /** @return array<string,mixed> */
    public function addChecklistItem(string $taskUuid, string $label, string $actor): array
    {
        if ($this->db->one('SELECT uuid FROM project_tasks WHERE uuid = :u', ['u' => $taskUuid]) === null) {
            throw new ProjectException(404, 'Task not found.');
        }
        $order = (int) $this->db->scalar('SELECT COALESCE(MAX(sort_order),0) + 1 FROM task_checklist_items WHERE task_uuid = :t', ['t' => $taskUuid]);
        $this->db->run(
            'INSERT INTO task_checklist_items (uuid, task_uuid, label, sort_order, created_at) VALUES (:uuid, :t, :label, :order, :now)',
            ['uuid' => Uuid::v4(), 't' => $taskUuid, 'label' => trim($label), 'order' => $order, 'now' => Clock::nowIso()]
        );

        return $this->find($taskUuid) ?? [];
    }

    /** @return array<string,mixed> */
    public function toggleChecklistItem(string $itemUuid, bool $done): array
    {
        $item = $this->db->one('SELECT task_uuid FROM task_checklist_items WHERE uuid = :u', ['u' => $itemUuid]);
        if ($item === null) {
            throw new ProjectException(404, 'Checklist item not found.');
        }
        $this->db->run('UPDATE task_checklist_items SET done = :d WHERE uuid = :u', ['d' => $done ? 1 : 0, 'u' => $itemUuid]);

        return $this->find((string) $item['task_uuid']) ?? [];
    }

    // ==================================================================
    // Internals
    // ==================================================================

    /** @param array<string,bool> $seen */
    private function dependsOn(string $a, string $b, array &$seen = []): bool
    {
        if (isset($seen[$a])) {
            return false;
        }
        $seen[$a] = true;
        foreach ($this->db->all('SELECT blocked_by FROM task_dependencies WHERE task_uuid = :t', ['t' => $a]) as $edge) {
            [$kind, $ref] = array_pad(explode(':', (string) $edge['blocked_by'], 2), 2, '');
            if ($kind !== 'task') {
                continue;
            }
            if ($ref === $b || $this->dependsOn($ref, $b, $seen)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,mixed> $t
     * @return array<string,mixed>
     */
    private function withDerived(array $t): array
    {
        $uuid = (string) $t['uuid'];
        $t['assignees'] = $this->decodeList($t['assignees'] ?? '[]');
        $t['labels']    = $this->decodeList($t['labels'] ?? '[]');
        $t['blocked_by'] = array_map(static fn (array $d): string => (string) $d['blocked_by'], $this->db->all('SELECT blocked_by FROM task_dependencies WHERE task_uuid = :t', ['t' => $uuid]));
        $t['is_ready']  = $this->isReady($uuid);
        $t['checklist'] = $this->db->all('SELECT uuid, label, done, sort_order FROM task_checklist_items WHERE task_uuid = :t ORDER BY sort_order ASC', ['t' => $uuid]);

        return $t;
    }

    private function encodeList(mixed $value): string
    {
        $list = is_array($value) ? array_values(array_map(static fn ($v): string => (string) $v, $value)) : [];

        return json_encode($list, JSON_UNESCAPED_SLASHES) ?: '[]';
    }

    /** @return list<string> */
    private function decodeList(mixed $raw): array
    {
        $decoded = json_decode((string) $raw, true);

        return is_array($decoded) ? array_values(array_map(static fn ($v): string => (string) $v, $decoded)) : [];
    }

    private function nullable(mixed $value): ?string
    {
        $v = trim((string) ($value ?? ''));

        return $v === '' ? null : $v;
    }
}
