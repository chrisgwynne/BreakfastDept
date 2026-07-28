<?php

declare(strict_types=1);

namespace Breakfast\Platform\Hermes;

use Breakfast\Platform\Support\Clock;
use Breakfast\Platform\Support\FileStore;
use Breakfast\Platform\Support\Uuid;

/**
 * Immutable audit trail for every Hermes request. Records who (credential),
 * what (method + endpoint + scope), the target, the result and safe metadata.
 * NEVER records secrets, signatures or authentication headers.
 *
 * Stored as flat files — one JSON record per audit entry.
 */
final class AuditLog
{
    private const COLLECTION = 'hermes_audit';

    public function __construct(private readonly FileStore $store)
    {
    }

    /**
     * @param array<string,mixed> $metadata safe fields only
     */
    public function record(
        string $credentialId,
        ?string $scope,
        string $method,
        string $endpoint,
        string $result,
        int $statusCode,
        string $requestId,
        ?string $targetType = null,
        ?string $targetUuid = null,
        array $metadata = []
    ): void {
        $this->store->put(self::COLLECTION, [
            'uuid'          => Uuid::v4(),
            'credential_id' => $credentialId,
            'scope'         => $scope,
            'method'        => $method,
            'endpoint'      => $endpoint,
            'target_type'   => $targetType,
            'target_uuid'   => $targetUuid,
            'request_id'    => $requestId,
            'result'        => $result,
            'status_code'   => $statusCode,
            'metadata'      => $this->safeMeta($metadata),
            'created_at'    => Clock::nowIso(),
        ]);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function recent(int $limit = 100): array
    {
        $rows = $this->store->all(self::COLLECTION);
        usort($rows, static fn ($a, $b) => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));

        return array_slice($rows, 0, $limit);
    }

    /**
     * Record a Panel/CLI-originated action (Client Previews, admin operations)
     * into the same immutable audit log, mapped onto the canonical columns. This
     * is the shape the admin/CLI callers use — distinct from the Hermes-request
     * `record()` signature above.
     *
     * @param array<string,mixed> $metadata
     */
    public function event(string $action, string $targetType, ?string $targetUuid, ?string $actor, array $metadata = []): void
    {
        $this->record(
            'panel:' . ($actor ?? 'system'),
            null,
            'PANEL',
            $action,
            'ok',
            200,
            Uuid::v4(),
            $targetType,
            $targetUuid,
            $metadata
        );
    }

    /**
     * Prune audit entries older than $days so the log cannot grow without bound.
     * Returns the number of rows removed.
     */
    public function prune(int $days = 365): int
    {
        $cutoff = Clock::now()->modify('-' . max(1, $days) . ' days')->format('c');
        $removed = 0;
        foreach ($this->store->all(self::COLLECTION) as $row) {
            if ((string) ($row['created_at'] ?? '') < $cutoff) {
                if ($this->store->delete(self::COLLECTION, (string) ($row['uuid'] ?? ''))) {
                    $removed++;
                }
            }
        }

        return $removed;
    }

    /** @param array<string,mixed> $metadata */
    private function safeMeta(array $metadata): string
    {
        $blocked = ['authorization', 'signature', 'secret', 'token', 'password', 'nonce'];
        $clean   = [];

        foreach ($metadata as $k => $v) {
            $key = strtolower((string) $k);
            foreach ($blocked as $b) {
                if (str_contains($key, $b)) {
                    continue 2;
                }
            }
            $clean[$k] = is_scalar($v) || $v === null ? $v : '[complex]';
        }

        return json_encode($clean, JSON_UNESCAPED_SLASHES) ?: '{}';
    }
}
