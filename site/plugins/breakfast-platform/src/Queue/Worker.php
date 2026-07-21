<?php

declare(strict_types=1);

namespace Breakfast\Platform\Queue;

use Breakfast\Platform\Forms\SubmissionGuard;
use Breakfast\Platform\Support\Platform;
use Throwable;

/**
 * Processes queued jobs. Runs either as a long-lived worker (`work`) or a
 * single bounded pass (`runOnce`) suitable for a cron tick. Job handlers are
 * resolved by type. Each job is idempotent and reserved under a lease, so
 * concurrent workers never process the same job and crashes recover.
 */
final class Worker
{
    public function __construct(private readonly Platform $platform)
    {
    }

    /**
     * Process up to $max jobs then return the number handled. Cron-safe.
     */
    public function runOnce(int $max = 25): int
    {
        $this->applyPendingMigrations();

        $queue     = $this->platform->queue();
        $processed = 0;

        while ($processed < $max) {
            $job = $queue->reserve();

            if ($job === null) {
                break;
            }

            try {
                $this->handle($job);
                $queue->markDone($job->uuid);
            } catch (Throwable $e) {
                // Retryable mail/webhook failures land here → backoff + retry.
                $queue->markFailed($job, $e->getMessage());
            }

            $processed++;
        }

        $this->housekeeping();

        return $processed;
    }

    /**
     * Long-running loop. Sleeps between empty polls. Intended for a supervised
     * process (systemd, supervisor). $iterations = 0 means run forever.
     */
    public function work(int $sleepSeconds = 5, int $iterations = 0): void
    {
        $count = 0;
        while (true) {
            $handled = $this->runOnce(25);

            if ($handled === 0) {
                sleep(max(1, $sleepSeconds));
            }

            if ($iterations > 0 && ++$count >= $iterations) {
                break;
            }
        }
    }

    /**
     * Apply any pending schema migrations before processing jobs.
     *
     * The queue only ever runs from the CLI (`queue:run` cron / `queue:work`),
     * NEVER during a public web request, so this is a safe place to keep the
     * schema current: a freshly deployed release migrates itself on the next
     * cron tick with no manual step, while honouring the rule that migrations
     * never run mid-request. Idempotent — only not-yet-applied migrations run —
     * and best-effort, so a migration problem is logged but never stops existing
     * jobs from draining (it is retried on the next tick).
     */
    private function applyPendingMigrations(): void
    {
        try {
            $applied = $this->platform->migrator()->migrate();
            if ($applied !== []) {
                $this->platform->logger()->info('queue', 'Applied pending migrations', ['ids' => $applied]);
            }
        } catch (Throwable $e) {
            $this->platform->logger()->error('queue', 'Migration on queue run failed', ['error' => $e->getMessage()]);
        }
    }

    private function handle(Job $job): void
    {
        match ($job->type) {
            'mail.send'        => $this->platform->mailService()->deliver($job->payload),
            'webhook.deliver'  => $this->platform->webhooks()->deliver((string) $job->payload['delivery_uuid']),
            'alert.job_failed' => $this->alertFailed($job),
            default            => throw new \RuntimeException('Unknown job type: ' . $job->type),
        };
    }

    private function alertFailed(Job $job): void
    {
        // Best-effort operational alert; failure here must not loop.
        $this->platform->logger()->error('queue', 'Job failure alert', [
            'failed_job'  => $job->payload['job_uuid'] ?? null,
            'failed_type' => $job->payload['job_type'] ?? null,
        ]);
    }

    /**
     * Opportunistic retention housekeeping: prune expired rate-limit buckets,
     * duplicate-submission fingerprints, expired IP hashes, and old email events.
     */
    private function housekeeping(): void
    {
        $this->platform->rateLimiter()->prune();
        (new SubmissionGuard($this->platform->db(), $this->platform->rateLimiter()))->pruneFingerprints();
        $this->platform->enquiries()->pruneIpHashes(30);
        $this->platform->outbound()->pruneEvents(90);
        $this->platform->audit()->prune(365);

        // Client Previews: expire due previews and prune preview access-event
        // history to its retention window. Guarded so a database predating the
        // previews migration (0004) never breaks the worker.
        if ($this->platform->db()->tableExists('client_previews')) {
            $this->platform->previewExpiry()->expireDue();
            $this->platform->previewAnalytics()->prune();
        }
    }
}
