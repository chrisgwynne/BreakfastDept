<?php

declare(strict_types=1);

namespace Breakfast\Platform\Crm;

use Breakfast\Platform\Support\Uuid;

/**
 * Append-only activity/audit log for CRM entities.
 *
 * There is intentionally no update or delete method: activity records are
 * immutable once written.
 */
final class ActivityRepository extends Repository
{
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
        $uuid = Uuid::v4();
        $now  = $this->now();

        $this->db->run(
            'INSERT INTO activities (uuid, entity_type, entity_uuid, type, actor_type, actor_ref, summary, metadata, created_at)
             VALUES (:uuid, :entity_type, :entity_uuid, :type, :actor_type, :actor_ref, :summary, :metadata, :created_at)',
            [
                'uuid'        => $uuid,
                'entity_type' => $entityType,
                'entity_uuid' => $entityUuid,
                'type'        => $type,
                'actor_type'  => $actorType,
                'actor_ref'   => $actorRef,
                'summary'     => $summary,
                'metadata'    => $this->encodeJson($metadata),
                'created_at'  => $now,
            ]
        );

        return $this->find($uuid) ?? [];
    }

    /** @return array<string,mixed>|null */
    public function find(string $uuid): ?array
    {
        return $this->hydrate($this->db->one('SELECT * FROM activities WHERE uuid = :u', ['u' => $uuid]));
    }

    /**
     * Timeline for a single entity, most recent first.
     *
     * @return array<int,array<string,mixed>>
     */
    public function forEntity(string $entityType, string $entityUuid, int $limit = 100): array
    {
        $rows = $this->db->all(
            'SELECT * FROM activities WHERE entity_type = :t AND entity_uuid = :u
             ORDER BY created_at DESC LIMIT :l',
            ['t' => $entityType, 'u' => $entityUuid, 'l' => $limit]
        );

        return array_map(fn ($r) => $this->hydrate($r) ?? [], $rows);
    }

    /**
     * Recent activity across the whole CRM (for the dashboard feed).
     *
     * @return array<int,array<string,mixed>>
     */
    public function recent(int $limit = 25): array
    {
        $rows = $this->db->all('SELECT * FROM activities ORDER BY created_at DESC LIMIT :l', ['l' => $limit]);

        return array_map(fn ($r) => $this->hydrate($r) ?? [], $rows);
    }

    /**
     * @param array<string,mixed>|null $row
     * @return array<string,mixed>|null
     */
    private function hydrate(?array $row): ?array
    {
        if ($row === null) {
            return null;
        }

        $row['metadata'] = $this->decodeJson($row['metadata'] ?? null);

        return $row;
    }
}
