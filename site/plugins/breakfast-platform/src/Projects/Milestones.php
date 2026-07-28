<?php

declare(strict_types=1);

namespace Breakfast\Platform\Projects;

use Breakfast\Platform\Support\Clock;
use Breakfast\Platform\Support\FileStore;
use Breakfast\Platform\Support\Uuid;

/**
 * Milestones — real delivery checkpoints with a dependency graph.
 *
 * Progress is derived from the milestone's tasks (or an explicit manual value
 * when configured), never invented. Dependencies form a DAG: adding a
 * dependency that would create a cycle is rejected. Completing a blocker does
 * NOT auto-complete dependents — it only makes them "ready".
 *
 * Each milestone is one flat-file record; its dependency edges live embedded in
 * that record as a native array. Task-derived progress reads the flat-file
 * project_tasks collection.
 */
final class Milestones
{
    private const COLLECTION = 'milestones';

    /** @var list<string> */
    public const STATUSES = ['not_started', 'active', 'awaiting_client', 'blocked', 'completed', 'cancelled'];

    /** @var array<string,list<string>> */
    private const TRANSITIONS = [
        'not_started'     => ['active', 'blocked', 'cancelled'],
        'active'          => ['awaiting_client', 'blocked', 'completed', 'cancelled'],
        'awaiting_client' => ['active', 'blocked', 'completed', 'cancelled'],
        'blocked'         => ['active', 'awaiting_client', 'cancelled'],
        'completed'       => ['active'],   // reopen
        'cancelled'       => ['not_started'],
    ];

    public function __construct(
        private readonly FileStore $store,
    ) {
    }

    /** @return list<array<string,mixed>> */
    public function forProject(string $projectUuid): array
    {
        $rows = array_values(array_filter($this->store->all(self::COLLECTION), static fn (array $m): bool => (string) ($m['project_uuid'] ?? '') === $projectUuid));
        usort($rows, static fn ($a, $b) => [(int) ($a['sort_order'] ?? 0), (string) ($a['created_at'] ?? '')] <=> [(int) ($b['sort_order'] ?? 0), (string) ($b['created_at'] ?? '')]);

        return array_map(fn (array $m): array => $this->withDerived($m), $rows);
    }

    /** @return array<string,mixed>|null */
    public function find(string $uuid): ?array
    {
        $m = $this->store->find(self::COLLECTION, $uuid);

        return $m === null ? null : $this->withDerived($m);
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
            throw new ProjectException(422, 'Enter a milestone title.');
        }
        $uuid = Uuid::v4();
        $now  = Clock::nowIso();
        $this->store->put(self::COLLECTION, [
            'uuid'            => $uuid,
            'project_uuid'    => $projectUuid,
            'title'           => $title,
            'description'     => (string) ($data['description'] ?? ''),
            'owner'           => (string) ($data['owner'] ?? ''),
            'due_date'        => $this->nullable($data['due_date'] ?? null),
            'status'          => in_array((string) ($data['status'] ?? 'not_started'), self::STATUSES, true) ? (string) ($data['status'] ?? 'not_started') : 'not_started',
            'priority'        => (string) ($data['priority'] ?? 'normal'),
            'client_visible'  => !empty($data['client_visible']) ? 1 : (array_key_exists('client_visible', $data) ? 0 : 1),
            'sort_order'      => $this->nextSortOrder($projectUuid),
            'progress_method' => in_array((string) ($data['progress_method'] ?? 'tasks'), ['tasks', 'manual'], true) ? (string) ($data['progress_method'] ?? 'tasks') : 'tasks',
            'manual_progress' => max(0, min(100, (int) ($data['manual_progress'] ?? 0))),
            'linked_invoice'  => $this->nullable($data['linked_invoice'] ?? null),
            'linked_preview'  => $this->nullable($data['linked_preview'] ?? null),
            'completed_date'  => null,
            'revision'        => 0,
            'dependencies'    => [],
            'created_at'      => $now,
            'updated_at'      => $now,
        ]);

        return $this->find($uuid) ?? [];
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function update(string $uuid, array $data, string $actor, ?int $expectedRevision = null): array
    {
        $m = $this->store->find(self::COLLECTION, $uuid);
        if ($m === null) {
            throw new ProjectException(404, 'Milestone not found.');
        }
        if ($expectedRevision !== null && (int) ($m['revision'] ?? 0) !== $expectedRevision) {
            throw new ProjectException(409, 'This milestone was changed by someone else. Reload and try again.');
        }
        $this->store->update(self::COLLECTION, $uuid, function (array $row) use ($data): array {
            foreach (['title', 'description', 'owner'] as $f) {
                if (array_key_exists($f, $data)) {
                    $row[$f] = (string) $data[$f];
                }
            }
            if (array_key_exists('due_date', $data)) {
                $row['due_date'] = $this->nullable($data['due_date']);
            }
            if (array_key_exists('priority', $data)) {
                $row['priority'] = (string) $data['priority'];
            }
            if (array_key_exists('client_visible', $data)) {
                $row['client_visible'] = !empty($data['client_visible']) ? 1 : 0;
            }
            if (array_key_exists('manual_progress', $data)) {
                $row['manual_progress'] = max(0, min(100, (int) $data['manual_progress']));
            }
            if (array_key_exists('progress_method', $data) && in_array((string) $data['progress_method'], ['tasks', 'manual'], true)) {
                $row['progress_method'] = (string) $data['progress_method'];
            }
            $row['revision']   = (int) ($row['revision'] ?? 0) + 1;
            $row['updated_at'] = Clock::nowIso();

            return $row;
        });

        return $this->find($uuid) ?? [];
    }

    /** @return array<string,mixed> */
    public function setStatus(string $uuid, string $to, string $actor): array
    {
        $m = $this->store->find(self::COLLECTION, $uuid);
        if ($m === null) {
            throw new ProjectException(404, 'Milestone not found.');
        }
        $from = (string) $m['status'];
        if (!in_array($to, self::STATUSES, true)) {
            throw new ProjectException(422, 'Unknown milestone status.');
        }
        if ($from === $to) {
            return $this->find($uuid) ?? [];
        }
        if (!in_array($to, self::TRANSITIONS[$from] ?? [], true)) {
            throw new ProjectException(409, "Cannot move a milestone from {$from} to {$to}.");
        }
        // A milestone cannot complete while a required blocker is unmet.
        if ($to === 'completed' && !$this->isReady($uuid)) {
            throw new ProjectException(409, 'This milestone is blocked by an incomplete dependency.');
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

    /** @return array<string,mixed> */
    public function delete(string $uuid): array
    {
        $m = $this->store->find(self::COLLECTION, $uuid);
        if ($m === null) {
            throw new ProjectException(404, 'Milestone not found.');
        }
        // Drop any edges from other milestones that pointed at this one.
        foreach ($this->store->all(self::COLLECTION) as $other) {
            $deps = is_array($other['dependencies'] ?? null) ? $other['dependencies'] : [];
            if ($deps !== [] && $this->hasBlocker($deps, $uuid)) {
                $this->store->update(self::COLLECTION, (string) $other['uuid'], static function (array $row) use ($uuid): array {
                    $row['dependencies'] = array_values(array_filter(is_array($row['dependencies'] ?? null) ? $row['dependencies'] : [], static fn (array $d): bool => (string) ($d['blocked_by'] ?? '') !== $uuid));

                    return $row;
                });
            }
        }
        $this->store->delete(self::COLLECTION, $uuid);

        return ['ok' => true];
    }

    // ==================================================================
    // Dependencies
    // ==================================================================

    /** @return array<string,mixed> */
    public function addDependency(string $milestoneUuid, string $blockedBy, string $actor): array
    {
        if ($milestoneUuid === $blockedBy) {
            throw new ProjectException(422, 'A milestone cannot depend on itself.');
        }
        $m = $this->store->find(self::COLLECTION, $milestoneUuid);
        $b = $this->store->find(self::COLLECTION, $blockedBy);
        if ($m === null || $b === null) {
            throw new ProjectException(404, 'Milestone not found.');
        }
        if ((string) ($m['project_uuid'] ?? '') !== (string) ($b['project_uuid'] ?? '')) {
            throw new ProjectException(422, 'Dependencies must be within the same project.');
        }
        // Cycle check: would $blockedBy (transitively) already depend on $milestoneUuid?
        if ($this->dependsOn($blockedBy, $milestoneUuid)) {
            throw new ProjectException(409, 'That would create a circular dependency.');
        }
        $this->store->update(self::COLLECTION, $milestoneUuid, static function (array $row) use ($blockedBy): array {
            $deps = is_array($row['dependencies'] ?? null) ? $row['dependencies'] : [];
            foreach ($deps as $d) {
                if ((string) ($d['blocked_by'] ?? '') === $blockedBy) {
                    return $row; // already present
                }
            }
            $deps[] = ['blocked_by' => $blockedBy, 'created_at' => Clock::nowIso()];
            $row['dependencies'] = $deps;

            return $row;
        });

        return $this->find($milestoneUuid) ?? [];
    }

    public function removeDependency(string $milestoneUuid, string $blockedBy): void
    {
        $this->store->update(self::COLLECTION, $milestoneUuid, static function (array $row) use ($blockedBy): array {
            $row['dependencies'] = array_values(array_filter(is_array($row['dependencies'] ?? null) ? $row['dependencies'] : [], static fn (array $d): bool => (string) ($d['blocked_by'] ?? '') !== $blockedBy));

            return $row;
        });
    }

    /** A milestone is ready when every blocker is completed or cancelled. */
    public function isReady(string $milestoneUuid): bool
    {
        $m = $this->store->find(self::COLLECTION, $milestoneUuid);
        foreach (is_array($m['dependencies'] ?? null) ? $m['dependencies'] : [] as $edge) {
            $blocker = $this->store->find(self::COLLECTION, (string) ($edge['blocked_by'] ?? ''));
            $status = (string) ($blocker['status'] ?? '');
            if (!in_array($status, ['completed', 'cancelled'], true)) {
                return false;
            }
        }

        return true;
    }

    // ==================================================================
    // Internals
    // ==================================================================

    /**
     * @param list<array<string,mixed>> $deps
     */
    private function hasBlocker(array $deps, string $blockedBy): bool
    {
        foreach ($deps as $d) {
            if ((string) ($d['blocked_by'] ?? '') === $blockedBy) {
                return true;
            }
        }

        return false;
    }

    /** Does $a (transitively) depend on $b? DFS over the blocked_by edges. */
    /** @param array<string,bool> $seen */
    private function dependsOn(string $a, string $b, array &$seen = []): bool
    {
        if (isset($seen[$a])) {
            return false;
        }
        $seen[$a] = true;
        $m = $this->store->find(self::COLLECTION, $a);
        foreach (is_array($m['dependencies'] ?? null) ? $m['dependencies'] : [] as $edge) {
            $next = (string) ($edge['blocked_by'] ?? '');
            if ($next === $b || $this->dependsOn($next, $b, $seen)) {
                return true;
            }
        }

        return false;
    }

    private function nextSortOrder(string $projectUuid): int
    {
        $max = 0;
        foreach ($this->store->all(self::COLLECTION) as $m) {
            if ((string) ($m['project_uuid'] ?? '') === $projectUuid) {
                $max = max($max, (int) ($m['sort_order'] ?? 0));
            }
        }

        return $max + 1;
    }

    /**
     * @param array<string,mixed> $m
     * @return array<string,mixed>
     */
    private function withDerived(array $m): array
    {
        $uuid = (string) $m['uuid'];
        if ((string) $m['progress_method'] === 'manual') {
            $m['progress_percent'] = (int) $m['manual_progress'];
        } else {
            $total = 0;
            $done = 0;
            foreach ($this->store->all('project_tasks') as $t) {
                if ((string) ($t['milestone_uuid'] ?? '') === $uuid && (int) ($t['archived'] ?? 0) === 0 && (string) ($t['status'] ?? '') !== 'cancelled') {
                    $total++;
                    if ((string) ($t['status'] ?? '') === 'completed') {
                        $done++;
                    }
                }
            }
            $m['progress_percent'] = $total > 0 ? (int) round($done / $total * 100) : ((string) $m['status'] === 'completed' ? 100 : 0);
            $m['tasks_total'] = $total;
            $m['tasks_completed'] = $done;
        }
        $m['blocked_by'] = array_map(static fn (array $d): string => (string) ($d['blocked_by'] ?? ''), is_array($m['dependencies'] ?? null) ? array_values($m['dependencies']) : []);
        $m['is_ready'] = $this->isReady($uuid);

        return $m;
    }

    private function nullable(mixed $value): ?string
    {
        $v = trim((string) ($value ?? ''));

        return $v === '' ? null : $v;
    }
}
