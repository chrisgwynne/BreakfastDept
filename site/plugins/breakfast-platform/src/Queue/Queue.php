<?php

declare(strict_types=1);

namespace Breakfast\Platform\Queue;

use Breakfast\Platform\Support\Clock;
use Breakfast\Platform\Support\FileStore;
use Breakfast\Platform\Support\Logger;
use Breakfast\Platform\Support\Uuid;

/**
 * Durable, flat-file-backed job queue (outbox).
 *
 * External side effects (emails, webhook deliveries) are enqueued here so that
 * persisting a valid enquiry never depends on an external service. Jobs are
 * reserved under a time-boxed lease so a crashed worker's jobs recover, retried
 * with exponential backoff, and de-duplicated via an optional dedupe key.
 *
 * Each job is one JSON record. The atomic claim is enforced by FileStore's
 * per-collection lock: two workers may pick the same candidate, but only the one
 * whose conditional update sees a still-claimable record wins.
 */
final class Queue
{
    private const COLLECTION = 'jobs';

    /** Backoff schedule in seconds, indexed by attempt number. */
    private const BACKOFF = [10, 60, 300, 900, 3600];

    public function __construct(
        private readonly FileStore $store,
        private readonly Logger $logger
    ) {
    }

    /**
     * Enqueue a job. If a dedupe key is supplied and a live job with that key
     * already exists, the existing job's UUID is returned instead of inserting
     * a duplicate.
     *
     * @param array<string,mixed> $payload
     */
    public function push(string $type, array $payload, ?string $dedupeKey = null, int $maxAttempts = 5, int $delaySeconds = 0): string
    {
        if ($dedupeKey !== null) {
            foreach ($this->store->all(self::COLLECTION) as $job) {
                if ((string) ($job['dedupe_key'] ?? '') === $dedupeKey && in_array((string) ($job['status'] ?? ''), ['pending', 'reserved'], true)) {
                    return (string) $job['uuid'];
                }
            }
        }

        $uuid        = Uuid::v4();
        $now         = Clock::now();
        $availableAt = $now->modify('+' . max(0, $delaySeconds) . ' seconds')->format('c');

        $this->store->put(self::COLLECTION, [
            'uuid'         => $uuid,
            'type'         => $type,
            'payload'      => json_encode($payload, JSON_UNESCAPED_SLASHES) ?: '{}',
            'status'       => 'pending',
            'attempts'     => 0,
            'max_attempts' => $maxAttempts,
            'available_at' => $availableAt,
            'reserved_at'  => null,
            'lease_until'  => null,
            'completed_at' => null,
            'last_error'   => null,
            'dedupe_key'   => $dedupeKey,
            'created_at'   => $now->format('c'),
        ]);

        return $uuid;
    }

    /**
     * Atomically reserve the next runnable job under a lease. Returns null when
     * there is nothing to do. Reclaims jobs whose lease has expired (crash
     * recovery) by treating them as available again.
     */
    public function reserve(int $leaseSeconds = 120): ?Job
    {
        $now = Clock::nowIso();
        $runnable = array_values(array_filter($this->store->all(self::COLLECTION), static fn (array $j): bool => self::claimable($j, $now)));
        usort($runnable, static fn ($a, $b) => strcmp((string) ($a['available_at'] ?? ''), (string) ($b['available_at'] ?? '')));

        $leaseUntil = Clock::now()->modify('+' . $leaseSeconds . ' seconds')->format('c');
        foreach ($runnable as $candidate) {
            $claimed = false;
            // Conditional claim under the collection lock — the mutator only
            // reserves if the record is STILL claimable, so a concurrent worker
            // that already reserved it makes this a no-op and we try the next.
            $row = $this->store->update(self::COLLECTION, (string) $candidate['uuid'], static function (array $r) use ($now, $leaseUntil, &$claimed): array {
                if (!self::claimable($r, $now)) {
                    return $r; // lost the race
                }
                $r['status']      = 'reserved';
                $r['reserved_at'] = $now;
                $r['lease_until'] = $leaseUntil;
                $claimed = true;

                return $r;
            });
            if ($claimed && $row !== null) {
                return Job::fromRow($row);
            }
        }

        return null;
    }

    public function markDone(string $uuid): void
    {
        $this->store->update(self::COLLECTION, $uuid, static function (array $r): array {
            $r['status']       = 'done';
            $r['completed_at'] = Clock::nowIso();
            $r['last_error']   = null;

            return $r;
        });
    }

    /**
     * Record a failed attempt. Reschedules with backoff until max attempts is
     * reached, then marks the job failed and enqueues a failure alert.
     */
    public function markFailed(Job $job, string $error): void
    {
        $attempts = $job->attempts + 1;
        $error    = mb_substr($error, 0, 1000);

        if ($attempts >= $job->maxAttempts) {
            $this->store->update(self::COLLECTION, $job->uuid, static function (array $r) use ($attempts, $error): array {
                $r['status']     = 'failed';
                $r['attempts']   = $attempts;
                $r['last_error'] = $error;

                return $r;
            });

            $this->logger->error('queue', 'Job permanently failed', [
                'job'      => $job->uuid,
                'type'     => $job->type,
                'attempts' => $attempts,
            ]);

            // Avoid recursing on the alert job itself.
            if ($job->type !== 'alert.job_failed') {
                $this->push('alert.job_failed', [
                    'job_uuid' => $job->uuid,
                    'job_type' => $job->type,
                    'error'    => $error,
                ], 'alert:' . $job->uuid, 3);
            }

            return;
        }

        $backoff     = self::BACKOFF[min($job->attempts, count(self::BACKOFF) - 1)];
        $availableAt = Clock::now()->modify('+' . $backoff . ' seconds')->format('c');

        $this->store->update(self::COLLECTION, $job->uuid, static function (array $r) use ($attempts, $availableAt, $error): array {
            $r['status']       = 'pending';
            $r['attempts']     = $attempts;
            $r['available_at'] = $availableAt;
            $r['reserved_at']  = null;
            $r['lease_until']  = null;
            $r['last_error']   = $error;

            return $r;
        });

        $this->logger->warning('queue', 'Job attempt failed, rescheduled', [
            'job'      => $job->uuid,
            'type'     => $job->type,
            'attempts' => $attempts,
            'retry_in' => $backoff,
        ]);
    }

    public function pendingCount(): int
    {
        return count(array_filter($this->store->all(self::COLLECTION), static fn (array $j): bool => in_array((string) ($j['status'] ?? ''), ['pending', 'reserved'], true)));
    }

    public function failedCount(): int
    {
        return count(array_filter($this->store->all(self::COLLECTION), static fn (array $j): bool => (string) ($j['status'] ?? '') === 'failed'));
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function failedJobs(int $limit = 50): array
    {
        $rows = array_values(array_filter($this->store->all(self::COLLECTION), static fn (array $j): bool => (string) ($j['status'] ?? '') === 'failed'));
        usort($rows, static fn ($a, $b) => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));

        return array_slice($rows, 0, $limit);
    }

    /** Requeue a failed job for another run (Panel action). */
    public function retry(string $uuid): void
    {
        $this->store->update(self::COLLECTION, $uuid, static function (array $r): array {
            if ((string) ($r['status'] ?? '') !== 'failed') {
                return $r;
            }
            $r['status']       = 'pending';
            $r['attempts']     = 0;
            $r['available_at'] = Clock::nowIso();
            $r['reserved_at']  = null;
            $r['lease_until']  = null;
            $r['last_error']   = null;

            return $r;
        });
    }

    /**
     * A job is claimable when it is pending and due, or reserved with an expired
     * lease (crash recovery).
     *
     * @param array<string,mixed> $j
     */
    private static function claimable(array $j, string $now): bool
    {
        $status = (string) ($j['status'] ?? '');
        if ($status === 'pending') {
            return (string) ($j['available_at'] ?? '') <= $now;
        }
        if ($status === 'reserved') {
            $lease = (string) ($j['lease_until'] ?? '');

            return $lease !== '' && $lease < $now;
        }

        return false;
    }
}
