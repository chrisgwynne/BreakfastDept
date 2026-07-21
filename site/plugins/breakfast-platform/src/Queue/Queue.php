<?php

declare(strict_types=1);

namespace Breakfast\Platform\Queue;

use Breakfast\Platform\Support\Clock;
use Breakfast\Platform\Support\Database;
use Breakfast\Platform\Support\Logger;
use Breakfast\Platform\Support\Uuid;

/**
 * Durable, SQLite-backed job queue (outbox).
 *
 * External side effects (emails, webhook deliveries) are enqueued here so that
 * persisting a valid enquiry never depends on an external service. Jobs are
 * reserved under a time-boxed lease so a crashed worker's jobs recover, retried
 * with exponential backoff, and de-duplicated via an optional dedupe key.
 */
final class Queue
{
    /** Backoff schedule in seconds, indexed by attempt number. */
    private const BACKOFF = [10, 60, 300, 900, 3600];

    public function __construct(
        private readonly Database $db,
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
            $existing = $this->db->scalar(
                'SELECT uuid FROM jobs WHERE dedupe_key = :k AND status IN (:p, :r)',
                ['k' => $dedupeKey, 'p' => 'pending', 'r' => 'reserved']
            );

            if ($existing !== null) {
                return (string) $existing;
            }
        }

        $uuid        = Uuid::v4();
        $now         = Clock::now();
        $availableAt = $now->modify('+' . max(0, $delaySeconds) . ' seconds')->format('c');

        $this->db->run(
            'INSERT INTO jobs (uuid, type, payload, status, attempts, max_attempts, available_at, dedupe_key, created_at)
             VALUES (:uuid, :type, :payload, :status, 0, :max, :avail, :dedupe, :created)',
            [
                'uuid'    => $uuid,
                'type'    => $type,
                'payload' => json_encode($payload, JSON_UNESCAPED_SLASHES) ?: '{}',
                'status'  => 'pending',
                'max'     => $maxAttempts,
                'avail'   => $availableAt,
                'dedupe'  => $dedupeKey,
                'created' => $now->format('c'),
            ]
        );

        return $uuid;
    }

    /**
     * Atomically reserve the next runnable job under a lease. Returns null when
     * there is nothing to do. Reclaims jobs whose lease has expired (crash
     * recovery) by treating them as available again.
     */
    public function reserve(int $leaseSeconds = 120): ?Job
    {
        return $this->db->transaction(function (Database $db) use ($leaseSeconds): ?Job {
            $now = Clock::nowIso();

            $row = $db->one(
                "SELECT * FROM jobs
                 WHERE (status = 'pending' AND available_at <= :now)
                    OR (status = 'reserved' AND lease_until IS NOT NULL AND lease_until < :now)
                 ORDER BY available_at ASC LIMIT 1",
                ['now' => $now]
            );

            if ($row === null) {
                return null;
            }

            $leaseUntil = Clock::now()->modify('+' . $leaseSeconds . ' seconds')->format('c');

            // Conditional claim: the UPDATE only succeeds if the row is STILL
            // claimable. Two overlapping workers can both SELECT the same row, but
            // SQLite serialises writers — the second UPDATE then sees a live lease,
            // matches zero rows, and we yield instead of double-processing.
            $claimed = $db->run(
                "UPDATE jobs SET status = 'reserved', reserved_at = :now, lease_until = :lease
                 WHERE uuid = :u
                   AND (status = 'pending'
                        OR (status = 'reserved' AND lease_until IS NOT NULL AND lease_until < :now2))",
                ['now' => $now, 'lease' => $leaseUntil, 'u' => $row['uuid'], 'now2' => $now]
            );

            if ($claimed->rowCount() !== 1) {
                return null; // lost the race to a concurrent worker
            }

            return Job::fromRow($row);
        });
    }

    public function markDone(string $uuid): void
    {
        $this->db->run(
            "UPDATE jobs SET status = 'done', completed_at = :now, last_error = NULL WHERE uuid = :u",
            ['now' => Clock::nowIso(), 'u' => $uuid]
        );
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
            $this->db->run(
                "UPDATE jobs SET status = 'failed', attempts = :a, last_error = :e WHERE uuid = :u",
                ['a' => $attempts, 'e' => $error, 'u' => $job->uuid]
            );

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

        $this->db->run(
            "UPDATE jobs SET status = 'pending', attempts = :a, available_at = :avail,
                    reserved_at = NULL, lease_until = NULL, last_error = :e WHERE uuid = :u",
            ['a' => $attempts, 'avail' => $availableAt, 'e' => $error, 'u' => $job->uuid]
        );

        $this->logger->warning('queue', 'Job attempt failed, rescheduled', [
            'job'      => $job->uuid,
            'type'     => $job->type,
            'attempts' => $attempts,
            'retry_in' => $backoff,
        ]);
    }

    public function pendingCount(): int
    {
        return (int) $this->db->scalar("SELECT COUNT(*) FROM jobs WHERE status IN ('pending','reserved')");
    }

    public function failedCount(): int
    {
        return (int) $this->db->scalar("SELECT COUNT(*) FROM jobs WHERE status = 'failed'");
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function failedJobs(int $limit = 50): array
    {
        return $this->db->all(
            "SELECT * FROM jobs WHERE status = 'failed' ORDER BY created_at DESC LIMIT :l",
            ['l' => $limit]
        );
    }

    /** Requeue a failed job for another run (Panel action). */
    public function retry(string $uuid): void
    {
        $this->db->run(
            "UPDATE jobs SET status = 'pending', attempts = 0, available_at = :now,
                    reserved_at = NULL, lease_until = NULL, last_error = NULL WHERE uuid = :u AND status = 'failed'",
            ['now' => Clock::nowIso(), 'u' => $uuid]
        );
    }
}
