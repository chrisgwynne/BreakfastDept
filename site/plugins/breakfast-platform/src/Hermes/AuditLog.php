<?php

declare(strict_types=1);

namespace Breakfast\Platform\Hermes;

use Breakfast\Platform\Support\Clock;
use Breakfast\Platform\Support\Database;
use Breakfast\Platform\Support\Uuid;

/**
 * Immutable audit trail for every Hermes request. Records who (credential),
 * what (method + endpoint + scope), the target, the result and safe metadata.
 * NEVER records secrets, signatures or authentication headers.
 */
final class AuditLog
{
    public function __construct(private readonly Database $db)
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
        $this->db->run(
            'INSERT INTO hermes_audit
                (uuid, credential_id, scope, method, endpoint, target_type, target_uuid,
                 request_id, result, status_code, metadata, created_at)
             VALUES
                (:uuid, :cred, :scope, :method, :endpoint, :ttype, :tuuid,
                 :rid, :result, :status, :meta, :created)',
            [
                'uuid'     => Uuid::v4(),
                'cred'     => $credentialId,
                'scope'    => $scope,
                'method'   => $method,
                'endpoint' => $endpoint,
                'ttype'    => $targetType,
                'tuuid'    => $targetUuid,
                'rid'      => $requestId,
                'result'   => $result,
                'status'   => $statusCode,
                'meta'     => $this->safeMeta($metadata),
                'created'  => Clock::nowIso(),
            ]
        );
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function recent(int $limit = 100): array
    {
        return $this->db->all('SELECT * FROM hermes_audit ORDER BY created_at DESC LIMIT :l', ['l' => $limit]);
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

        return $this->db->run('DELETE FROM hermes_audit WHERE created_at < :cut', ['cut' => $cutoff])->rowCount();
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
