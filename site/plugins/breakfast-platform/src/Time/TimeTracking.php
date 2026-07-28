<?php

declare(strict_types=1);

namespace Breakfast\Platform\Time;

use Breakfast\Platform\Support\Clock;
use Breakfast\Platform\Support\FileStore;
use Breakfast\Platform\Support\Platform;
use Breakfast\Platform\Support\Uuid;

/**
 * Time tracking (Phase 4 — Operations), stored as flat files.
 *
 * Time is logged against a project (and optionally a task) either with an
 * explicit duration or a live start/stop timer — at most one running timer per
 * user. Billable time carries a per-hour rate in pence; the billable value is
 * always server-computed, never trusted from the client. Once an entry is
 * attached to an invoice it is locked (billed) and can no longer be edited or
 * deleted. Durations are integer seconds; money is integer pence.
 */
final class TimeTracking
{
    /** @var list<string> */
    public const ACTIVITIES = ['general', 'design', 'build', 'meeting', 'admin', 'support'];

    public function __construct(private readonly Platform $platform)
    {
    }

    private function store(): FileStore
    {
        return $this->platform->fileStore();
    }

    // ==================================================================
    // Read
    // ==================================================================

    /**
     * @param array<string,mixed> $filters
     * @return list<array<string,mixed>>
     */
    public function list(array $filters = []): array
    {
        $rows = $this->store()->all('time_entries');
        foreach (['project_uuid', 'task_uuid', 'author'] as $col) {
            if (!empty($filters[$col])) {
                $rows = array_filter($rows, static fn (array $r): bool => (string) ($r[$col] ?? '') === (string) $filters[$col]);
            }
        }
        if (isset($filters['billable'])) {
            $want = !empty($filters['billable']) ? 1 : 0;
            $rows = array_filter($rows, static fn (array $r): bool => (int) ($r['billable'] ?? 0) === $want);
        }
        if (!empty($filters['unbilled'])) {
            $rows = array_filter($rows, static fn (array $r): bool => (int) ($r['billed'] ?? 0) === 0);
        }
        $rows = array_values($rows);
        usort($rows, static fn ($a, $b) => strcmp(
            (string) ($b['started_at'] ?? $b['created_at'] ?? ''),
            (string) ($a['started_at'] ?? $a['created_at'] ?? '')
        ));

        return array_slice($rows, 0, (int) ($filters['limit'] ?? 500));
    }

    /** @return array<string,mixed>|null */
    public function find(string $uuid): ?array
    {
        return $this->store()->find('time_entries', $uuid);
    }

    /**
     * Billable/non-billable/total seconds and the billable + still-unbilled value
     * for a project. Running timers are counted at their elapsed-so-far duration.
     *
     * @return array{billable_seconds:int,nonbillable_seconds:int,total_seconds:int,billable_value:int,unbilled_value:int,running:bool}
     */
    public function rollup(string $projectUuid): array
    {
        $billable = 0;
        $nonbillable = 0;
        $value = 0;
        $unbilled = 0;
        $running = false;
        foreach ($this->store()->all('time_entries') as $r) {
            if ((string) ($r['project_uuid'] ?? '') !== $projectUuid) {
                continue;
            }
            $secs = $this->effectiveSeconds($r);
            if ((int) ($r['running'] ?? 0) === 1) {
                $running = true;
            }
            if ((int) ($r['billable'] ?? 0) === 1) {
                $billable += $secs;
                $lineValue = (int) round($secs / 3600 * (int) ($r['rate_pence'] ?? 0));
                $value += $lineValue;
                if ((int) ($r['billed'] ?? 0) === 0) {
                    $unbilled += $lineValue;
                }
            } else {
                $nonbillable += $secs;
            }
        }

        return [
            'billable_seconds' => $billable, 'nonbillable_seconds' => $nonbillable, 'total_seconds' => $billable + $nonbillable,
            'billable_value' => $value, 'unbilled_value' => $unbilled, 'running' => $running,
        ];
    }

    // ==================================================================
    // Manual entries
    // ==================================================================

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function create(string $projectUuid, array $data, string $actor): array
    {
        if (!$this->platform->projects()->exists($projectUuid)) {
            throw new TimeException(404, 'Project not found.');
        }
        $seconds = $this->durationToSeconds($data);
        if ($seconds <= 0) {
            throw new TimeException(422, 'Enter a duration greater than zero.');
        }
        $uuid = Uuid::v4();
        $now = Clock::nowIso();
        $this->store()->put('time_entries', [
            'uuid'             => $uuid,
            'project_uuid'     => $projectUuid,
            'task_uuid'        => $this->nullable($data['task_uuid'] ?? null),
            'author'           => (string) ($data['author'] ?? $actor),
            'description'      => (string) ($data['description'] ?? ''),
            'activity'         => $this->activity($data),
            'started_at'       => $this->nullable($data['started_at'] ?? null) ?? substr($now, 0, 10),
            'duration_seconds' => $seconds,
            'billable'         => !empty($data['billable'] ?? true) ? 1 : 0,
            'rate_pence'       => (int) round(((float) ($data['rate'] ?? 0)) * 100),
            'running'          => 0,
            'billed'           => 0,
            'invoice_uuid'     => null,
            'revision'         => 0,
            'created_at'       => $now,
            'updated_at'       => $now,
        ]);

        return $this->find($uuid) ?? [];
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function update(string $uuid, array $data, string $actor, ?int $expectedRevision = null): array
    {
        $entry = $this->requireEditable($uuid);
        if ($expectedRevision !== null && (int) $entry['revision'] !== $expectedRevision) {
            throw new TimeException(409, 'This entry was changed by someone else. Reload and try again.');
        }
        $seconds = null;
        if (array_key_exists('hours', $data) || array_key_exists('minutes', $data) || array_key_exists('duration_seconds', $data)) {
            $seconds = $this->durationToSeconds($data);
            if ($seconds <= 0) {
                throw new TimeException(422, 'Enter a duration greater than zero.');
            }
        }
        $this->store()->update('time_entries', $uuid, function (array $row) use ($data, $seconds): array {
            foreach (['description', 'started_at', 'task_uuid'] as $f) {
                if (array_key_exists($f, $data)) {
                    $row[$f] = in_array($f, ['started_at', 'task_uuid'], true) ? $this->nullable($data[$f]) : (string) $data[$f];
                }
            }
            if (array_key_exists('activity', $data) && in_array((string) $data['activity'], self::ACTIVITIES, true)) {
                $row['activity'] = (string) $data['activity'];
            }
            if (array_key_exists('billable', $data)) {
                $row['billable'] = !empty($data['billable']) ? 1 : 0;
            }
            if (array_key_exists('rate', $data)) {
                $row['rate_pence'] = (int) round(((float) $data['rate']) * 100);
            }
            if ($seconds !== null) {
                $row['duration_seconds'] = $seconds;
            }
            $row['revision']   = (int) ($row['revision'] ?? 0) + 1;
            $row['updated_at'] = Clock::nowIso();

            return $row;
        });

        return $this->find($uuid) ?? [];
    }

    public function delete(string $uuid, string $actor): void
    {
        $this->requireEditable($uuid);
        $this->store()->delete('time_entries', $uuid);
    }

    // ==================================================================
    // Live timer (one running per user)
    // ==================================================================

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function startTimer(string $projectUuid, array $data, string $actor): array
    {
        if ($this->runningTimer($actor) !== null) {
            throw new TimeException(409, 'You already have a running timer. Stop it first.');
        }
        if (!$this->platform->projects()->exists($projectUuid)) {
            throw new TimeException(404, 'Project not found.');
        }
        $uuid = Uuid::v4();
        $now = Clock::nowIso();
        $this->store()->put('time_entries', [
            'uuid'             => $uuid,
            'project_uuid'     => $projectUuid,
            'task_uuid'        => $this->nullable($data['task_uuid'] ?? null),
            'author'           => $actor,
            'description'      => (string) ($data['description'] ?? ''),
            'activity'         => $this->activity($data),
            'started_at'       => $now,
            'duration_seconds' => 0,
            'billable'         => !empty($data['billable'] ?? true) ? 1 : 0,
            'rate_pence'       => (int) round(((float) ($data['rate'] ?? 0)) * 100),
            'running'          => 1,
            'billed'           => 0,
            'invoice_uuid'     => null,
            'revision'         => 0,
            'created_at'       => $now,
            'updated_at'       => $now,
        ]);

        return $this->find($uuid) ?? [];
    }

    /** @return array<string,mixed> */
    public function stopTimer(string $actor): array
    {
        $running = $this->runningTimer($actor);
        if ($running === null) {
            throw new TimeException(409, 'You don’t have a running timer.');
        }
        $elapsed = $this->effectiveSeconds($running);
        $now = Clock::nowIso();
        $this->store()->update('time_entries', (string) $running['uuid'], static function (array $row) use ($elapsed, $now): array {
            $row['running']          = 0;
            $row['duration_seconds'] = $elapsed;
            $row['revision']         = (int) ($row['revision'] ?? 0) + 1;
            $row['updated_at']       = $now;

            return $row;
        });

        return $this->find((string) $running['uuid']) ?? [];
    }

    /** @return array<string,mixed>|null */
    public function runningTimer(string $actor): ?array
    {
        foreach ($this->store()->all('time_entries') as $row) {
            if ((string) ($row['author'] ?? '') === $actor && (int) ($row['running'] ?? 0) === 1) {
                return $row;
            }
        }

        return null;
    }

    // ==================================================================
    // Billing lock
    // ==================================================================

    /**
     * Lock a set of unbilled entries against an invoice. Idempotent per entry.
     *
     * @param list<string> $entryUuids
     */
    public function markBilled(array $entryUuids, string $invoiceUuid, string $actor): int
    {
        $count = 0;
        $now = Clock::nowIso();
        foreach ($entryUuids as $uuid) {
            $entry = $this->find((string) $uuid);
            if ($entry === null || (int) $entry['billed'] === 1 || (int) $entry['running'] === 1) {
                continue;
            }
            $this->store()->update('time_entries', (string) $uuid, static function (array $row) use ($invoiceUuid, $now): array {
                $row['billed']       = 1;
                $row['invoice_uuid'] = $invoiceUuid;
                $row['revision']     = (int) ($row['revision'] ?? 0) + 1;
                $row['updated_at']   = $now;

                return $row;
            });
            $count++;
        }

        return $count;
    }

    // ==================================================================
    // Internals
    // ==================================================================

    /** @param array<string,mixed> $entry */
    private function effectiveSeconds(array $entry): int
    {
        if ((int) ($entry['running'] ?? 0) === 1) {
            $start = strtotime((string) ($entry['started_at'] ?? ''));
            $base = (int) ($entry['duration_seconds'] ?? 0);
            if ($start !== false) {
                return $base + max(0, (int) (strtotime(Clock::nowIso()) - $start));
            }

            return $base;
        }

        return (int) ($entry['duration_seconds'] ?? 0);
    }

    /** @param array<string,mixed> $data */
    private function activity(array $data): string
    {
        $activity = (string) ($data['activity'] ?? 'general');

        return in_array($activity, self::ACTIVITIES, true) ? $activity : 'general';
    }

    /** @param array<string,mixed> $data */
    private function durationToSeconds(array $data): int
    {
        if (array_key_exists('duration_seconds', $data)) {
            return max(0, (int) $data['duration_seconds']);
        }
        $hours = (float) ($data['hours'] ?? 0);
        $minutes = (float) ($data['minutes'] ?? 0);

        return (int) round($hours * 3600 + $minutes * 60);
    }

    /** @return array<string,mixed> */
    private function requireEditable(string $uuid): array
    {
        $entry = $this->find($uuid);
        if ($entry === null) {
            throw new TimeException(404, 'Time entry not found.');
        }
        if ((int) $entry['billed'] === 1) {
            throw new TimeException(409, 'A billed time entry can no longer be changed.');
        }
        if ((int) $entry['running'] === 1) {
            throw new TimeException(409, 'Stop the running timer before editing it.');
        }

        return $entry;
    }

    private function nullable(mixed $value): ?string
    {
        $v = trim((string) ($value ?? ''));

        return $v === '' ? null : $v;
    }
}
