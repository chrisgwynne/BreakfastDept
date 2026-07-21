<?php

declare(strict_types=1);

namespace Breakfast\Platform\Crm;

use Breakfast\Platform\Support\Clock;
use Breakfast\Platform\Support\Uuid;

final class TaskRepository extends Repository
{
    private const COLUMNS = [
        'title', 'contact_uuid', 'company_uuid', 'opportunity_uuid', 'assigned_to',
        'due_date', 'priority', 'status', 'notes', 'completed_at',
    ];

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function create(array $data): array
    {
        $uuid = Uuid::v4();
        $now  = $this->now();

        $this->db->run(
            'INSERT INTO tasks (
                uuid, title, contact_uuid, company_uuid, opportunity_uuid, assigned_to,
                due_date, priority, status, notes, created_at, updated_at
             ) VALUES (
                :uuid, :title, :contact_uuid, :company_uuid, :opportunity_uuid, :assigned_to,
                :due_date, :priority, :status, :notes, :created_at, :updated_at
             )',
            [
                'uuid'             => $uuid,
                'title'            => (string) ($data['title'] ?? 'Follow up'),
                'contact_uuid'     => $data['contact_uuid'] ?? null,
                'company_uuid'     => $data['company_uuid'] ?? null,
                'opportunity_uuid' => $data['opportunity_uuid'] ?? null,
                'assigned_to'      => $data['assigned_to'] ?? null,
                'due_date'         => $data['due_date'] ?? null,
                'priority'         => $data['priority'] ?? 'normal',
                'status'           => $data['status'] ?? 'open',
                'notes'            => $data['notes'] ?? null,
                'created_at'       => $now,
                'updated_at'       => $now,
            ]
        );

        return $this->find($uuid) ?? [];
    }

    /** @return array<string,mixed>|null */
    public function find(string $uuid): ?array
    {
        return $this->db->one('SELECT * FROM tasks WHERE uuid = :u', ['u' => $uuid]);
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>|null
     */
    public function update(string $uuid, array $data): ?array
    {
        // Completing a task stamps completed_at automatically.
        if (($data['status'] ?? null) === 'done' && empty($data['completed_at'])) {
            $data['completed_at'] = $this->now();
        }

        [$clause, $params] = $this->assignments($data, self::COLUMNS);

        if ($clause !== '') {
            $params['uuid']       = $uuid;
            $params['updated_at'] = $this->now();
            $this->db->run(
                'UPDATE tasks SET ' . $clause . ', updated_at = :updated_at WHERE uuid = :uuid',
                $params
            );
        }

        return $this->find($uuid);
    }

    /** @return array<string,mixed>|null */
    public function complete(string $uuid): ?array
    {
        return $this->update($uuid, ['status' => 'done']);
    }

    /**
     * @param array{status?:string,assigned_to?:string,overdue?:bool,limit?:int,offset?:int} $filters
     * @return array<int,array<string,mixed>>
     */
    public function search(array $filters = []): array
    {
        $where  = ['1 = 1'];
        $params = [];

        if (!empty($filters['status'])) {
            $where[] = 't.status = :status';
            $params['status'] = $filters['status'];
        }

        if (!empty($filters['assigned_to'])) {
            $where[] = 't.assigned_to = :assignee';
            $params['assignee'] = $filters['assigned_to'];
        }

        if (!empty($filters['overdue'])) {
            $where[] = "t.status = 'open' AND t.due_date IS NOT NULL AND t.due_date < :now";
            $params['now'] = Clock::nowIso();
        }

        $params['l'] = (int) ($filters['limit'] ?? 200);
        $params['o'] = (int) ($filters['offset'] ?? 0);

        return $this->db->all(
            'SELECT t.*, c.display_name AS contact_name, o.title AS opportunity_title
             FROM tasks t
             LEFT JOIN contacts c ON c.uuid = t.contact_uuid
             LEFT JOIN opportunities o ON o.uuid = t.opportunity_uuid
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY (t.due_date IS NULL), t.due_date ASC LIMIT :l OFFSET :o',
            $params
        );
    }

    public function countOverdue(): int
    {
        return (int) $this->db->scalar(
            "SELECT COUNT(*) FROM tasks WHERE status = 'open' AND due_date IS NOT NULL AND due_date < :now",
            ['now' => Clock::nowIso()]
        );
    }

    public function countUpcoming(int $days = 7): int
    {
        $now   = Clock::nowIso();
        $until = Clock::now()->modify('+' . $days . ' days')->format('c');

        return (int) $this->db->scalar(
            "SELECT COUNT(*) FROM tasks WHERE status = 'open' AND due_date >= :now AND due_date <= :until",
            ['now' => $now, 'until' => $until]
        );
    }

    /**
     * Open tasks now past their due date — used to fire task.overdue webhooks.
     *
     * @return array<int,array<string,mixed>>
     */
    public function overdue(): array
    {
        return $this->db->all(
            "SELECT * FROM tasks WHERE status = 'open' AND due_date IS NOT NULL AND due_date < :now ORDER BY due_date ASC",
            ['now' => Clock::nowIso()]
        );
    }
}
