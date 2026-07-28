<?php

declare(strict_types=1);

namespace Breakfast\Platform\Projects;

use Breakfast\Platform\Support\Clock;
use Breakfast\Platform\Support\FileStore;
use Breakfast\Platform\Support\Uuid;

/**
 * Delivery tasks + board. Project-owned, distinct from the CRM `tasks` table.
 *
 * Board moves are real, server-validated transitions with optimistic
 * concurrency. A task with an incomplete blocker (another task or a milestone)
 * cannot progress into in_progress/review/completed — the dependency genuinely
 * gates progression. Completing a task never auto-completes anything else.
 *
 * Each task is one flat-file record; its dependency edges and checklist live
 * embedded in that record as native arrays.
 */
final class ProjectTasks
{
    private const COLLECTION = 'project_tasks';

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

    public function __construct(
        private readonly FileStore $store,
    ) {
    }

    /**
     * @param array<string,mixed> $filters
     * @return list<array<string,mixed>>
     */
    public function forProject(string $projectUuid, array $filters = []): array
    {
        $rows = array_filter($this->store->all(self::COLLECTION), static fn (array $t): bool => (string) ($t['project_uuid'] ?? '') === $projectUuid && (int) ($t['archived'] ?? 0) === 0);
        if (!empty($filters['status'])) {
            $rows = array_filter($rows, static fn (array $t): bool => (string) ($t['status'] ?? '') === (string) $filters['status']);
        }
        if (!empty($filters['milestone_uuid'])) {
            $rows = array_filter($rows, static fn (array $t): bool => (string) ($t['milestone_uuid'] ?? '') === (string) $filters['milestone_uuid']);
        }
        $rows = array_values($rows);
        usort($rows, static fn ($a, $b) => [(int) ($a['sort_order'] ?? 0), (string) ($a['created_at'] ?? '')] <=> [(int) ($b['sort_order'] ?? 0), (string) ($b['created_at'] ?? '')]);

        return array_map(fn (array $t): array => $this->withDerived($t), $rows);
    }

    /** @return array<string,mixed>|null */
    public function find(string $uuid): ?array
    {
        $t = $this->store->find(self::COLLECTION, $uuid);

        return $t === null ? null : $this->withDerived($t);
    }

    /**
     * Look a task up by its generator's deterministic source reference. Lets
     * flat-file callers (onboarding, automation) generate tasks idempotently.
     *
     * @return array<string,mixed>|null
     */
    public function findBySourceRef(string $sourceRef): ?array
    {
        if ($sourceRef === '') {
            return null;
        }
        foreach ($this->store->all(self::COLLECTION) as $t) {
            if ((string) ($t['source_ref'] ?? '') === $sourceRef) {
                return $this->withDerived($t);
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function create(string $projectUuid, array $data, string $actor): array
    {
        if (!$this->store->exists('projects', $projectUuid)) {
            throw new ProjectException(404, 'Project not found.');
        }
        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            throw new ProjectException(422, 'Enter a task title.');
        }
        $uuid = Uuid::v4();
        $now  = Clock::nowIso();
        $this->store->put(self::COLLECTION, [
            'uuid'             => $uuid,
            'project_uuid'     => $projectUuid,
            'milestone_uuid'   => $this->nullable($data['milestone_uuid'] ?? null),
            'title'            => $title,
            'description'      => (string) ($data['description'] ?? ''),
            'owner'            => (string) ($data['owner'] ?? ''),
            'assignees'        => $this->cleanList($data['assignees'] ?? []),
            'status'           => in_array((string) ($data['status'] ?? 'backlog'), self::STATUSES, true) ? (string) ($data['status'] ?? 'backlog') : 'backlog',
            'priority'         => (string) ($data['priority'] ?? 'normal'),
            'start_date'       => $this->nullable($data['start_date'] ?? null),
            'due_date'         => $this->nullable($data['due_date'] ?? null),
            'estimate_seconds' => max(0, (int) ($data['estimate_seconds'] ?? 0)),
            'billable'         => array_key_exists('billable', $data) ? (!empty($data['billable']) ? 1 : 0) : 1,
            'client_visible'   => !empty($data['client_visible']) ? 1 : 0,
            'labels'           => $this->cleanList($data['labels'] ?? []),
            'source'           => (string) ($data['source'] ?? 'manual'),
            'source_ref'       => (string) ($data['source_ref'] ?? ''),
            'sort_order'       => $this->nextSortOrder($projectUuid),
            'archived'         => 0,
            'completed_date'   => null,
            'revision'         => 0,
            'dependencies'     => [],
            'checklist'        => [],
            'created_by'       => $actor,
            'created_at'       => $now,
            'updated_at'       => $now,
        ]);

        return $this->find($uuid) ?? [];
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function update(string $uuid, array $data, string $actor, ?int $expectedRevision = null): array
    {
        $t = $this->store->find(self::COLLECTION, $uuid);
        if ($t === null) {
            throw new ProjectException(404, 'Task not found.');
        }
        if ($expectedRevision !== null && (int) ($t['revision'] ?? 0) !== $expectedRevision) {
            throw new ProjectException(409, 'This task was changed by someone else. Reload and try again.');
        }
        $this->store->update(self::COLLECTION, $uuid, function (array $row) use ($data): array {
            foreach (['title', 'description', 'owner', 'priority'] as $f) {
                if (array_key_exists($f, $data)) {
                    $row[$f] = (string) $data[$f];
                }
            }
            foreach (['start_date', 'due_date'] as $f) {
                if (array_key_exists($f, $data)) {
                    $row[$f] = $this->nullable($data[$f]);
                }
            }
            if (array_key_exists('milestone_uuid', $data)) {
                $row['milestone_uuid'] = $this->nullable($data['milestone_uuid']);
            }
            foreach (['assignees', 'labels'] as $list) {
                if (array_key_exists($list, $data)) {
                    $row[$list] = $this->cleanList($data[$list]);
                }
            }
            if (array_key_exists('estimate_seconds', $data)) {
                $row['estimate_seconds'] = max(0, (int) $data['estimate_seconds']);
            }
            foreach (['billable', 'client_visible'] as $bool) {
                if (array_key_exists($bool, $data)) {
                    $row[$bool] = !empty($data[$bool]) ? 1 : 0;
                }
            }
            $row['revision']   = (int) ($row['revision'] ?? 0) + 1;
            $row['updated_at'] = Clock::nowIso();

            return $row;
        });

        return $this->find($uuid) ?? [];
    }

    /**
     * Board move — validated transition + dependency gate + optimistic concurrency.
     *
     * @return array<string,mixed>
     */
    public function move(string $uuid, string $to, string $actor, ?int $expectedRevision = null): array
    {
        $t = $this->store->find(self::COLLECTION, $uuid);
        if ($t === null) {
            throw new ProjectException(404, 'Task not found.');
        }
        if ($expectedRevision !== null && (int) ($t['revision'] ?? 0) !== $expectedRevision) {
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
        $this->store->update(self::COLLECTION, $uuid, static function (array $row) use ($from, $to): array {
            $row['status'] = $to;
            if ($to === 'completed') {
                $row['completed_date'] = date('Y-m-d');
            } elseif ($from === 'completed') {
                $row['completed_date'] = null;
            }
            $row['revision']   = (int) ($row['revision'] ?? 0) + 1;
            $row['updated_at'] = Clock::nowIso();

            return $row;
        });

        return $this->find($uuid) ?? [];
    }

    /** @param list<string> $orderedUuids */
    public function reorder(string $projectUuid, array $orderedUuids, string $actor): void
    {
        $i = 1;
        foreach ($orderedUuids as $uuid) {
            $order = $i++;
            $this->store->update(self::COLLECTION, (string) $uuid, static function (array $row) use ($order, $projectUuid): array {
                if ((string) ($row['project_uuid'] ?? '') === $projectUuid) {
                    $row['sort_order'] = $order;
                    $row['updated_at'] = Clock::nowIso();
                }

                return $row;
            });
        }
    }

    /**
     * Bulk status change / assign / archive across many tasks.
     *
     * @param list<string> $uuids
     * @param array<string,mixed> $changes
     * @return array{updated:int}
     */
    public function bulk(array $uuids, array $changes, string $actor): array
    {
        $count = 0;
        foreach ($uuids as $uuid) {
            $uuid = (string) $uuid;
            try {
                if (isset($changes['status'])) {
                    $this->move($uuid, (string) $changes['status'], $actor);
                }
                if (array_key_exists('assignees', $changes) || array_key_exists('archived', $changes)) {
                    if (!empty($changes['archived'])) {
                        $this->setArchived($uuid, 1);
                    } elseif (array_key_exists('assignees', $changes)) {
                        $this->update($uuid, ['assignees' => $changes['assignees']], $actor);
                    }
                }
                $count++;
            } catch (ProjectException) {
                // Skip individual invalid moves; report the count actually applied.
            }
        }

        return ['updated' => $count];
    }

    public function archive(string $uuid): void
    {
        $this->setArchived($uuid, 1);
    }

    public function restore(string $uuid): void
    {
        $this->setArchived($uuid, 0);
    }

    // ==================================================================
    // Dependencies
    // ==================================================================

    /**
     * @return array<string,mixed>
     */
    public function addDependency(string $taskUuid, string $blockedBy, string $actor): array
    {
        $t = $this->store->find(self::COLLECTION, $taskUuid);
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
        $this->store->update(self::COLLECTION, $taskUuid, static function (array $row) use ($blockedBy): array {
            $deps = is_array($row['dependencies'] ?? null) ? $row['dependencies'] : [];
            foreach ($deps as $d) {
                if ((string) ($d['blocked_by'] ?? '') === $blockedBy) {
                    return $row; // already present (UNIQUE equivalent)
                }
            }
            $deps[] = ['blocked_by' => $blockedBy, 'created_at' => Clock::nowIso()];
            $row['dependencies'] = $deps;

            return $row;
        });

        return $this->find($taskUuid) ?? [];
    }

    public function removeDependency(string $taskUuid, string $blockedBy): void
    {
        $this->store->update(self::COLLECTION, $taskUuid, static function (array $row) use ($blockedBy): array {
            $row['dependencies'] = array_values(array_filter(is_array($row['dependencies'] ?? null) ? $row['dependencies'] : [], static fn (array $d): bool => (string) ($d['blocked_by'] ?? '') !== $blockedBy));

            return $row;
        });
    }

    /** Ready when every blocker (task or milestone) is completed/cancelled. */
    public function isReady(string $taskUuid): bool
    {
        $task = $this->store->find(self::COLLECTION, $taskUuid);
        foreach (is_array($task['dependencies'] ?? null) ? $task['dependencies'] : [] as $edge) {
            [$kind, $ref] = array_pad(explode(':', (string) ($edge['blocked_by'] ?? ''), 2), 2, '');
            $collection = $kind === 'milestone' ? 'milestones' : self::COLLECTION;
            $blocker = $this->store->find($collection, $ref);
            $status = (string) ($blocker['status'] ?? '');
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
        if ($this->store->find(self::COLLECTION, $taskUuid) === null) {
            throw new ProjectException(404, 'Task not found.');
        }
        $this->store->update(self::COLLECTION, $taskUuid, static function (array $row) use ($label): array {
            $items = is_array($row['checklist'] ?? null) ? $row['checklist'] : [];
            $order = 0;
            foreach ($items as $it) {
                $order = max($order, (int) ($it['sort_order'] ?? 0) + 1);
            }
            $items[] = ['uuid' => Uuid::v4(), 'label' => trim($label), 'done' => 0, 'sort_order' => $order, 'created_at' => Clock::nowIso()];
            $row['checklist'] = $items;

            return $row;
        });

        return $this->find($taskUuid) ?? [];
    }

    /** @return array<string,mixed> */
    public function toggleChecklistItem(string $itemUuid, bool $done): array
    {
        foreach ($this->store->all(self::COLLECTION) as $task) {
            foreach (is_array($task['checklist'] ?? null) ? $task['checklist'] : [] as $item) {
                if ((string) ($item['uuid'] ?? '') === $itemUuid) {
                    $this->store->update(self::COLLECTION, (string) $task['uuid'], static function (array $row) use ($itemUuid, $done): array {
                        $items = is_array($row['checklist'] ?? null) ? $row['checklist'] : [];
                        foreach ($items as $i => $it) {
                            if ((string) ($it['uuid'] ?? '') === $itemUuid) {
                                $items[$i]['done'] = $done ? 1 : 0;
                            }
                        }
                        $row['checklist'] = $items;

                        return $row;
                    });

                    return $this->find((string) $task['uuid']) ?? [];
                }
            }
        }

        throw new ProjectException(404, 'Checklist item not found.');
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
        $task = $this->store->find(self::COLLECTION, $a);
        foreach (is_array($task['dependencies'] ?? null) ? $task['dependencies'] : [] as $edge) {
            [$kind, $ref] = array_pad(explode(':', (string) ($edge['blocked_by'] ?? ''), 2), 2, '');
            if ($kind !== 'task') {
                continue;
            }
            if ($ref === $b || $this->dependsOn($ref, $b, $seen)) {
                return true;
            }
        }

        return false;
    }

    private function nextSortOrder(string $projectUuid): int
    {
        $max = 0;
        foreach ($this->store->all(self::COLLECTION) as $t) {
            if ((string) ($t['project_uuid'] ?? '') === $projectUuid) {
                $max = max($max, (int) ($t['sort_order'] ?? 0));
            }
        }

        return $max + 1;
    }

    private function setArchived(string $uuid, int $archived): void
    {
        $this->store->update(self::COLLECTION, $uuid, static function (array $row) use ($archived): array {
            $row['archived']   = $archived;
            $row['updated_at'] = Clock::nowIso();

            return $row;
        });
    }

    /**
     * @param array<string,mixed> $t
     * @return array<string,mixed>
     */
    private function withDerived(array $t): array
    {
        $uuid = (string) $t['uuid'];
        $t['assignees']  = $this->cleanList($t['assignees'] ?? []);
        $t['labels']     = $this->cleanList($t['labels'] ?? []);
        $t['blocked_by'] = array_map(static fn (array $d): string => (string) ($d['blocked_by'] ?? ''), is_array($t['dependencies'] ?? null) ? array_values($t['dependencies']) : []);
        $t['is_ready']   = $this->isReady($uuid);
        $checklist = is_array($t['checklist'] ?? null) ? array_values($t['checklist']) : [];
        usort($checklist, static fn ($a, $b) => (int) ($a['sort_order'] ?? 0) <=> (int) ($b['sort_order'] ?? 0));
        $t['checklist'] = array_map(static fn (array $c): array => [
            'uuid' => $c['uuid'] ?? '', 'label' => $c['label'] ?? '', 'done' => (int) ($c['done'] ?? 0), 'sort_order' => (int) ($c['sort_order'] ?? 0),
        ], $checklist);

        return $t;
    }

    /**
     * @param mixed $value
     * @return list<string>
     */
    private function cleanList(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_map(static fn ($v): string => (string) $v, $value));
        }
        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? array_values(array_map(static fn ($v): string => (string) $v, $decoded)) : [];
    }

    private function nullable(mixed $value): ?string
    {
        $v = trim((string) ($value ?? ''));

        return $v === '' ? null : $v;
    }
}
