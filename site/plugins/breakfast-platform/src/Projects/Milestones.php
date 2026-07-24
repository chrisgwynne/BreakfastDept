<?php

declare(strict_types=1);

namespace Breakfast\Platform\Projects;

use Breakfast\Platform\Support\Clock;
use Breakfast\Platform\Support\Database;
use Breakfast\Platform\Support\Uuid;

/**
 * Milestones — real delivery checkpoints with a dependency graph.
 *
 * Progress is derived from the milestone's tasks (or an explicit manual value
 * when configured), never invented. Dependencies form a DAG: adding a
 * dependency that would create a cycle is rejected. Completing a blocker does
 * NOT auto-complete dependents — it only makes them "ready".
 */
final class Milestones
{
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

    public function __construct(private readonly Database $db)
    {
    }

    /** @return list<array<string,mixed>> */
    public function forProject(string $projectUuid): array
    {
        $rows = $this->db->all('SELECT * FROM milestones WHERE project_uuid = :p ORDER BY sort_order ASC, created_at ASC', ['p' => $projectUuid]);

        return array_map(fn (array $m): array => $this->withDerived($m), $rows);
    }

    /** @return array<string,mixed>|null */
    public function find(string $uuid): ?array
    {
        $m = $this->db->one('SELECT * FROM milestones WHERE uuid = :u', ['u' => $uuid]);

        return $m === null ? null : $this->withDerived($m);
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
            throw new ProjectException(422, 'Enter a milestone title.');
        }
        $uuid = Uuid::v4();
        $now  = Clock::nowIso();
        $order = (int) $this->db->scalar('SELECT COALESCE(MAX(sort_order),0) + 1 FROM milestones WHERE project_uuid = :p', ['p' => $projectUuid]);
        $this->db->run(
            'INSERT INTO milestones (uuid, project_uuid, title, description, owner, due_date, status, priority, client_visible, sort_order, progress_method, manual_progress, linked_invoice, linked_preview, created_at, updated_at)
             VALUES (:uuid, :p, :title, :desc, :owner, :due, :status, :priority, :cv, :order, :pm, :mp, :li, :lp, :now, :now)',
            [
                'uuid' => $uuid, 'p' => $projectUuid, 'title' => $title,
                'desc' => (string) ($data['description'] ?? ''), 'owner' => (string) ($data['owner'] ?? ''),
                'due' => $this->nullable($data['due_date'] ?? null),
                'status' => in_array((string) ($data['status'] ?? 'not_started'), self::STATUSES, true) ? (string) ($data['status'] ?? 'not_started') : 'not_started',
                'priority' => (string) ($data['priority'] ?? 'normal'),
                'cv' => !empty($data['client_visible']) ? 1 : (array_key_exists('client_visible', $data) ? 0 : 1),
                'order' => $order,
                'pm' => in_array((string) ($data['progress_method'] ?? 'tasks'), ['tasks', 'manual'], true) ? (string) ($data['progress_method'] ?? 'tasks') : 'tasks',
                'mp' => max(0, min(100, (int) ($data['manual_progress'] ?? 0))),
                'li' => $this->nullable($data['linked_invoice'] ?? null),
                'lp' => $this->nullable($data['linked_preview'] ?? null),
                'now' => $now,
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
        $m = $this->db->one('SELECT revision FROM milestones WHERE uuid = :u', ['u' => $uuid]);
        if ($m === null) {
            throw new ProjectException(404, 'Milestone not found.');
        }
        if ($expectedRevision !== null && (int) $m['revision'] !== $expectedRevision) {
            throw new ProjectException(409, 'This milestone was changed by someone else. Reload and try again.');
        }
        $sets = ['updated_at = :now', 'revision = revision + 1'];
        $params = ['u' => $uuid, 'now' => Clock::nowIso()];
        foreach (['title', 'description', 'owner', 'due_date'] as $f) {
            if (array_key_exists($f, $data)) {
                $sets[] = "$f = :$f";
                $params[$f] = $f === 'due_date' ? $this->nullable($data[$f]) : (string) $data[$f];
            }
        }
        if (array_key_exists('priority', $data)) {
            $sets[] = 'priority = :priority';
            $params['priority'] = (string) $data['priority'];
        }
        if (array_key_exists('client_visible', $data)) {
            $sets[] = 'client_visible = :cv';
            $params['cv'] = !empty($data['client_visible']) ? 1 : 0;
        }
        if (array_key_exists('manual_progress', $data)) {
            $sets[] = 'manual_progress = :mp';
            $params['mp'] = max(0, min(100, (int) $data['manual_progress']));
        }
        if (array_key_exists('progress_method', $data) && in_array((string) $data['progress_method'], ['tasks', 'manual'], true)) {
            $sets[] = 'progress_method = :pm';
            $params['pm'] = (string) $data['progress_method'];
        }
        $this->db->run('UPDATE milestones SET ' . implode(', ', $sets) . ' WHERE uuid = :u', $params);

        return $this->find($uuid) ?? [];
    }

    /** @return array<string,mixed> */
    public function setStatus(string $uuid, string $to, string $actor): array
    {
        $m = $this->db->one('SELECT * FROM milestones WHERE uuid = :u', ['u' => $uuid]);
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
        $sets = ['status = :to', 'updated_at = :now', 'revision = revision + 1'];
        $params = ['u' => $uuid, 'to' => $to, 'now' => Clock::nowIso()];
        if ($to === 'completed') {
            $sets[] = 'completed_date = :cd';
            $params['cd'] = date('Y-m-d');
        } elseif ($from === 'completed') {
            $sets[] = 'completed_date = NULL';
        }
        $this->db->run('UPDATE milestones SET ' . implode(', ', $sets) . ' WHERE uuid = :u', $params);

        return $this->find($uuid) ?? [];
    }

    /** @param list<string> $orderedUuids */
    public function reorder(string $projectUuid, array $orderedUuids, string $actor): void
    {
        $this->db->transaction(function (Database $db) use ($projectUuid, $orderedUuids): void {
            $i = 1;
            foreach ($orderedUuids as $uuid) {
                $db->run('UPDATE milestones SET sort_order = :o, updated_at = :now WHERE uuid = :u AND project_uuid = :p', ['o' => $i++, 'now' => Clock::nowIso(), 'u' => (string) $uuid, 'p' => $projectUuid]);
            }
        });
    }

    /** @return array<string,mixed> */
    public function delete(string $uuid): array
    {
        $m = $this->db->one('SELECT project_uuid FROM milestones WHERE uuid = :u', ['u' => $uuid]);
        if ($m === null) {
            throw new ProjectException(404, 'Milestone not found.');
        }
        $this->db->run('DELETE FROM milestone_dependencies WHERE milestone_uuid = :u OR blocked_by = :u', ['u' => $uuid]);
        $this->db->run('DELETE FROM milestones WHERE uuid = :u', ['u' => $uuid]);

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
        $m = $this->db->one('SELECT project_uuid FROM milestones WHERE uuid = :u', ['u' => $milestoneUuid]);
        $b = $this->db->one('SELECT project_uuid FROM milestones WHERE uuid = :u', ['u' => $blockedBy]);
        if ($m === null || $b === null) {
            throw new ProjectException(404, 'Milestone not found.');
        }
        if ((string) $m['project_uuid'] !== (string) $b['project_uuid']) {
            throw new ProjectException(422, 'Dependencies must be within the same project.');
        }
        // Cycle check: would $blockedBy (transitively) already depend on $milestoneUuid?
        if ($this->dependsOn($blockedBy, $milestoneUuid)) {
            throw new ProjectException(409, 'That would create a circular dependency.');
        }
        $this->db->run(
            'INSERT INTO milestone_dependencies (uuid, project_uuid, milestone_uuid, blocked_by, created_at)
             VALUES (:uuid, :p, :m, :b, :now) ON CONFLICT (milestone_uuid, blocked_by) DO NOTHING',
            ['uuid' => Uuid::v4(), 'p' => (string) $m['project_uuid'], 'm' => $milestoneUuid, 'b' => $blockedBy, 'now' => Clock::nowIso()]
        );

        return $this->find($milestoneUuid) ?? [];
    }

    public function removeDependency(string $milestoneUuid, string $blockedBy): void
    {
        $this->db->run('DELETE FROM milestone_dependencies WHERE milestone_uuid = :m AND blocked_by = :b', ['m' => $milestoneUuid, 'b' => $blockedBy]);
    }

    /** A milestone is ready when every blocker is completed or cancelled. */
    public function isReady(string $milestoneUuid): bool
    {
        $blockers = $this->db->all('SELECT blocked_by FROM milestone_dependencies WHERE milestone_uuid = :m', ['m' => $milestoneUuid]);
        foreach ($blockers as $b) {
            $status = (string) $this->db->scalar('SELECT status FROM milestones WHERE uuid = :u', ['u' => (string) $b['blocked_by']]);
            if (!in_array($status, ['completed', 'cancelled'], true)) {
                return false;
            }
        }

        return true;
    }

    // ==================================================================
    // Internals
    // ==================================================================

    /** Does $a (transitively) depend on $b? DFS over the blocked_by edges. */
    /** @param array<string,bool> $seen */
    private function dependsOn(string $a, string $b, array &$seen = []): bool
    {
        if (isset($seen[$a])) {
            return false;
        }
        $seen[$a] = true;
        foreach ($this->db->all('SELECT blocked_by FROM milestone_dependencies WHERE milestone_uuid = :m', ['m' => $a]) as $edge) {
            $next = (string) $edge['blocked_by'];
            if ($next === $b || $this->dependsOn($next, $b, $seen)) {
                return true;
            }
        }

        return false;
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
            $t = $this->db->one(
                "SELECT COUNT(*) AS total, COALESCE(SUM(CASE WHEN status='completed' THEN 1 ELSE 0 END),0) AS done
                 FROM project_tasks WHERE milestone_uuid = :m AND archived = 0 AND status <> 'cancelled'",
                ['m' => $uuid]
            ) ?? ['total' => 0, 'done' => 0];
            $m['progress_percent'] = (int) $t['total'] > 0 ? (int) round((int) $t['done'] / (int) $t['total'] * 100) : ((string) $m['status'] === 'completed' ? 100 : 0);
            $m['tasks_total'] = (int) $t['total'];
            $m['tasks_completed'] = (int) $t['done'];
        }
        $m['blocked_by'] = array_map(static fn (array $d): string => (string) $d['blocked_by'], $this->db->all('SELECT blocked_by FROM milestone_dependencies WHERE milestone_uuid = :m', ['m' => $uuid]));
        $m['is_ready'] = $this->isReady($uuid);

        return $m;
    }

    private function nullable(mixed $value): ?string
    {
        $v = trim((string) ($value ?? ''));

        return $v === '' ? null : $v;
    }
}
