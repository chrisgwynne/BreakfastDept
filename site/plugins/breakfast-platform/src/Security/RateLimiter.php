<?php

declare(strict_types=1);

namespace Breakfast\Platform\Security;

use Breakfast\Platform\Support\Clock;
use Breakfast\Platform\Support\Database;

/**
 * Fixed-window rate limiter backed by SQLite.
 *
 * Buckets are keyed strings (e.g. "form:contact:<ip_hash>" or
 * "api:auth:<credential>"). Only keyed hashes are ever stored, never raw IPs.
 * Expired rows are pruned lazily and by the sweeper.
 */
final class RateLimiter
{
    public function __construct(private readonly Database $db)
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

        $this->db->run(
            'INSERT INTO rate_limits (bucket, window_key, hits, expires_at)
             VALUES (:b, :w, 1, :e)
             ON CONFLICT(bucket, window_key) DO UPDATE SET hits = hits + 1',
            ['b' => $bucket, 'w' => $windowId, 'e' => $expiresAt]
        );

        $hits = (int) $this->db->scalar(
            'SELECT hits FROM rate_limits WHERE bucket = :b AND window_key = :w',
            ['b' => $bucket, 'w' => $windowId]
        );

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
        $hits     = (int) $this->db->scalar(
            'SELECT hits FROM rate_limits WHERE bucket = :b AND window_key = :w',
            ['b' => $bucket, 'w' => $windowId]
        );

        return $hits >= $limit;
    }

    /** Remove expired buckets. Safe to call from the queue sweeper. */
    public function prune(): int
    {
        $stmt = $this->db->run(
            'DELETE FROM rate_limits WHERE expires_at < :now',
            ['now' => Clock::nowIso()]
        );

        return $stmt->rowCount();
    }
}
