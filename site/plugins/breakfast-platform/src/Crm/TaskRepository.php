<?php

declare(strict_types=1);

namespace Breakfast\Platform\Crm;

use Breakfast\Platform\Support\Clock;
use Breakfast\Platform\Support\FileRepository;
use Breakfast\Platform\Support\Uuid;

final class TaskRepository extends FileRepository
{
    private const COLUMNS = [
        'title', 'contact_uuid', 'company_uuid', 'opportunity_uuid', 'assigned_to',
        'due_date', 'priority', 'status', 'notes', 'completed_at',
    ];

    protected function collection(): string
    {
        return 'tasks';
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function create(array $data): array
    {
        $now = $this->now();

        return $this->persist([
            'uuid'             => Uuid::v4(),
            'title'            => (string) ($data['title'] ?? 'Follow up'),
            'contact_uuid'     => $data['contact_uuid'] ?? null,
            'company_uuid'     => $data['company_uuid'] ?? null,
            'opportunity_uuid' => $data['opportunity_uuid'] ?? null,
            'assigned_to'      => $data['assigned_to'] ?? null,
            'due_date'         => $data['due_date'] ?? null,
            'priority'         => $data['priority'] ?? 'normal',
            'status'           => $data['status'] ?? 'open',
            'notes'            => $data['notes'] ?? null,
            'completed_at'     => null,
            'created_at'       => $now,
            'updated_at'       => $now,
        ]);
    }

    /** @return array<string,mixed>|null */
    public function find(string $uuid): ?array
    {
        return $this->findRecord($uuid);
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

        return $this->patch($uuid, $data, self::COLUMNS);
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
        $rows          = $this->records();
        $contacts      = $this->indexBy('contacts');
        $opportunities = $this->indexBy('opportunities');

        if (!empty($filters['status'])) {
            $rows = array_filter($rows, fn (array $r): bool => ($r['status'] ?? null) === $filters['status']);
        }

        if (!empty($filters['assigned_to'])) {
            $rows = array_filter($rows, fn (array $r): bool => ($r['assigned_to'] ?? null) === $filters['assigned_to']);
        }

        if (!empty($filters['overdue'])) {
            $now  = Clock::nowIso();
            $rows = array_filter($rows, fn (array $r): bool => ($r['status'] ?? null) === 'open'
                && ($r['due_date'] ?? null) !== null
                && (string) $r['due_date'] < $now);
        }

        // Nulls last, then ascending by due date — matches the SQL ORDER BY.
        $rows = $this->sortBy(array_values($rows), 'due_date', 'asc');

        foreach ($rows as &$row) {
            $row['contact_name']      = $contacts[(string) ($row['contact_uuid'] ?? '')]['display_name'] ?? null;
            $row['opportunity_title'] = $opportunities[(string) ($row['opportunity_uuid'] ?? '')]['title'] ?? null;
        }
        unset($row);

        return $this->paginate($rows, (int) ($filters['limit'] ?? 200), (int) ($filters['offset'] ?? 0));
    }

    public function countOverdue(): int
    {
        return count($this->overdue());
    }

    public function countUpcoming(int $days = 7): int
    {
        $now   = Clock::nowIso();
        $until = Clock::now()->modify('+' . $days . ' days')->format('c');

        return count(array_filter($this->records(), fn (array $r): bool => ($r['status'] ?? null) === 'open'
            && ($r['due_date'] ?? null) !== null
            && (string) $r['due_date'] >= $now
            && (string) $r['due_date'] <= $until));
    }

    /**
     * Open tasks now past their due date — used to fire task.overdue webhooks.
     *
     * @return array<int,array<string,mixed>>
     */
    public function overdue(): array
    {
        $now  = Clock::nowIso();
        $rows = array_filter($this->records(), fn (array $r): bool => ($r['status'] ?? null) === 'open'
            && ($r['due_date'] ?? null) !== null
            && (string) $r['due_date'] < $now);

        return $this->sortBy(array_values($rows), 'due_date', 'asc');
    }

    /**
     * Records of a related collection keyed by uuid.
     *
     * @return array<string,array<string,mixed>>
     */
    private function indexBy(string $collection): array
    {
        $out = [];
        foreach ($this->store->all($collection) as $row) {
            $out[(string) ($row['uuid'] ?? '')] = $row;
        }

        return $out;
    }
}
