<?php

declare(strict_types=1);

namespace Breakfast\Platform\Security;

use Breakfast\Platform\Support\Clock;
use Breakfast\Platform\Support\FileStore;

/**
 * Fixed-window rate limiter backed by flat files.
 *
 * Buckets are keyed strings (e.g. "form:contact:<ip_hash>" or
 * "api:auth:<credential>"). Only keyed hashes are ever stored, never raw IPs.
 * Each (bucket, window) is one record keyed on a hash of the pair; the hit
 * counter is incremented atomically under the collection lock. Expired rows are
 * pruned lazily and by the sweeper.
 */
final class RateLimiter
{
    private const COLLECTION = 'rate_limits';

    public function __construct(private readonly FileStore $store)
    {
    }

    /**
     * Register a hit against a bucket and report whether it is now over the
     * limit within the window.
     *
     * @return array{allowed:bool, hits:int, limit:int, retry_after:int}
     */
    public function hit(string $bucket, int $limit, int $windowSeconds): array
    {
        $now       = Clock::timestamp();
        $windowId  = (string) intdiv($now, max(1, $windowSeconds));
        $expiresAt = Clock::now()->modify('+' . $windowSeconds . ' seconds')->format('c');
        $id        = $this->id($bucket, $windowId);

        $inc = static function (array $row) use ($expiresAt): array {
            $row['hits']       = (int) ($row['hits'] ?? 0) + 1;
            $row['expires_at'] = $expiresAt;

            return $row;
        };
        $updated = $this->store->update(self::COLLECTION, $id, $inc);
        if ($updated === null) {
            // First hit in this window — create atomically; if a concurrent
            // writer beat us to it, fall back to an increment.
            $created = $this->store->putIfAbsent(self::COLLECTION, $id, [
                'uuid' => $id, 'bucket' => $bucket, 'window_key' => $windowId, 'hits' => 1, 'expires_at' => $expiresAt,
            ]);
            $hits = $created ? 1 : (int) (($this->store->update(self::COLLECTION, $id, $inc)['hits'] ?? 1));
        } else {
            $hits = (int) ($updated['hits'] ?? 1);
        }

        $retryAfter = ($windowSeconds - ($now % $windowSeconds));

        return [
            'allowed'     => $hits <= $limit,
            'hits'        => $hits,
            'limit'       => $limit,
            'retry_after' => $retryAfter,
        ];
    }

    /** Peek without incrementing. */
    public function tooMany(string $bucket, int $limit, int $windowSeconds): bool
    {
        $windowId = (string) intdiv(Clock::timestamp(), max(1, $windowSeconds));
        $row      = $this->store->find(self::COLLECTION, $this->id($bucket, $windowId));

        return (int) ($row['hits'] ?? 0) >= $limit;
    }

    /** Remove expired buckets. Safe to call from the queue sweeper. */
    public function prune(): int
    {
        $now     = Clock::nowIso();
        $removed = 0;
        foreach ($this->store->all(self::COLLECTION) as $row) {
            if ((string) ($row['expires_at'] ?? '') < $now) {
                if ($this->store->delete(self::COLLECTION, (string) ($row['uuid'] ?? ''))) {
                    $removed++;
                }
            }
        }

        return $removed;
    }

    private function id(string $bucket, string $windowId): string
    {
        return sha1($bucket . '|' . $windowId);
    }
}
