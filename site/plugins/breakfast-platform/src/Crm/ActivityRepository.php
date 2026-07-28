<?php

declare(strict_types=1);

namespace Breakfast\Platform\Crm;

use Breakfast\Platform\Support\FileRepository;
use Breakfast\Platform\Support\Uuid;

/**
 * Append-only activity/audit log for CRM entities, stored as flat files.
 *
 * There is intentionally no update or delete method: activity records are
 * immutable once written.
 */
final class ActivityRepository extends FileRepository
{
    protected function collection(): string
    {
        return 'activities';
    }

    /**
     * @param array<string,mixed> $metadata
     * @return array<string,mixed> the written record
     */
    public function record(
        string $entityType,
        string $entityUuid,
        string $type,
        string $summary,
        string $actorType = 'system',
        ?string $actorRef = null,
        array $metadata = []
    ): array {
        return $this->persist([
            'uuid'        => Uuid::v4(),
            'entity_type' => $entityType,
            'entity_uuid' => $entityUuid,
            'type'        => $type,
            'actor_type'  => $actorType,
            'actor_ref'   => $actorRef,
            'summary'     => $summary,
            'metadata'    => $metadata,
            'created_at'  => $this->now(),
        ]);
    }

    /** @return array<string,mixed>|null */
    public function find(string $uuid): ?array
    {
        return $this->findRecord($uuid);
    }

    /**
     * Timeline for a single entity, most recent first.
     *
     * @return array<int,array<string,mixed>>
     */
    public function forEntity(string $entityType, string $entityUuid, int $limit = 100): array
    {
        $rows = array_values(array_filter(
            $this->records(),
            fn (array $r): bool => ($r['entity_type'] ?? null) === $entityType
                && ($r['entity_uuid'] ?? null) === $entityUuid
        ));

        return $this->paginate($this->sortBy($rows, 'created_at', 'desc'), $limit);
    }

    /**
     * Recent activity across the whole CRM (for the dashboard feed).
     *
     * @return array<int,array<string,mixed>>
     */
    public function recent(int $limit = 25): array
    {
        return $this->paginate($this->sortBy($this->records(), 'created_at', 'desc'), $limit);
    }
}
