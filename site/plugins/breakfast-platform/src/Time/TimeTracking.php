<?php

declare(strict_types=1);

namespace Breakfast\Platform\Time;

use Breakfast\Platform\Support\Clock;
use Breakfast\Platform\Support\Database;
use Breakfast\Platform\Support\Platform;
use Breakfast\Platform\Support\Uuid;

/**
 * Time tracking (Phase 4 — Operations).
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

    private function db(): Database
    {
        return $this->platform->db();
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
        $where = ['1 = 1'];
        $params = [];
        foreach (['project_uuid' => 'project_uuid', 'task_uuid' => 'task_uuid', 'author' => 'author'] as $key => $col) {
            if (!empty($filters[$key])) {
                $where[] = "$col = :$key";
                $params[$key] = (string) $filters[$key];
            }
        }
        if (isset($filters['billable'])) {
            $where[] = 'billable = :billable';
            $params['billable'] = !empty($filters['billable']) ? 1 : 0;
        }
        if (isset($filters['unbilled']) && $filters['unbilled']) {
            $where[] = 'billed = 0';
        }
        $params['l'] = (int) ($filters['limit'] ?? 500);

        return $this->db()->all('SELECT * FROM time_entries WHERE ' . implode(' AND ', $where) . ' ORDER BY COALESCE(started_at, created_at) DESC LIMIT :l', $params);
    }

    /** @return array<string,mixed>|null */
    public function find(string $uuid): ?array
    {
        return $this->db()->one('SELECT * FROM time_entries WHERE uuid = :u', ['u' => $uuid]);
    }

    /**
     * Billable/non-billable/total seconds and the billable + still-unbilled value
     * for a project. Running timers are counted at their elapsed-so-far duration.
     *
     * @return array{billable_seconds:int,nonbillable_seconds:int,total_seconds:int,billable_value:int,unbilled_value:int,running:bool}
     */
    public function rollup(string $projectUuid): array
    {
        $rows = $this->db()->all('SELECT duration_seconds, started_at, billable, rate_pence, running, billed FROM time_entries WHERE project_uuid = :p', ['p' => $projectUuid]);
        $billable = 0;
        $nonbillable = 0;
        $value = 0;
        $unbilled = 0;
        $running = false;
        foreach ($rows as $r) {
            $secs = $this->effectiveSeconds($r);
            if ((int) $r['running'] === 1) {
                $running = true;
            }
            if ((int) $r['billable'] === 1) {
                $billable += $secs;
                $lineValue = (int) round($secs / 3600 * (int) $r['rate_pence']);
                $value += $lineValue;
                if ((int) $r['billed'] === 0) {
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
        if ($this->db()->one('SELECT uuid FROM projects WHERE uuid = :u', ['u' => $projectUuid]) === null) {
            throw new TimeException(404, 'Project not found.');
        }
        $seconds = $this->durationToSeconds($data);
        if ($seconds <= 0) {
            throw new TimeException(422, 'Enter a duration greater than zero.');
        }
        $uuid = Uuid::v4();
        $now = Clock::nowIso();
        $this->db()->run(
            'INSERT INTO time_entries (uuid, project_uuid, task_uuid, author, description, activity, started_at, duration_seconds, billable, rate_pence, running, billed, created_at, updated_at)
             VALUES (:uuid, :p, :task, :author, :desc, :activity, :started, :dur, :billable, :rate, 0, 0, :now, :now)',
            [
                'uuid' => $uuid, 'p' => $projectUuid, 'task' => $this->nullable($data['task_uuid'] ?? null),
                'author' => (string) ($data['author'] ?? $actor), 'desc' => (string) ($data['description'] ?? ''),
                'activity' => $this->activity($data),
                'started' => $this->nullable($data['started_at'] ?? null) ?? substr($now, 0, 10),
                'dur' => $seconds, 'billable' => !empty($data['billable'] ?? true) ? 1 : 0,
                'rate' => (int) round(((float) ($data['rate'] ?? 0)) * 100), 'now' => $now,
            ]
        );

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
        $sets = ['updated_at = :now', 'revision = revision + 1'];
        $params = ['u' => $uuid, 'now' => Clock::nowIso()];
        foreach (['description', 'started_at', 'task_uuid'] as $f) {
            if (array_key_exists($f, $data)) {
                $sets[] = "$f = :$f";
                $params[$f] = in_array($f, ['started_at', 'task_uuid'], true) ? $this->nullable($data[$f]) : (string) $data[$f];
            }
        }
        if (array_key_exists('activity', $data) && in_array((string) $data['activity'], self::ACTIVITIES, true)) {
            $sets[] = 'activity = :activity';
            $params['activity'] = (string) $data['activity'];
        }
        if (array_key_exists('billable', $data)) {
            $sets[] = 'billable = :billable';
            $params['billable'] = !empty($data['billable']) ? 1 : 0;
        }
        if (array_key_exists('rate', $data)) {
            $sets[] = 'rate_pence = :rate';
            $params['rate'] = (int) round(((float) $data['rate']) * 100);
        }
        if (array_key_exists('hours', $data) || array_key_exists('minutes', $data) || array_key_exists('duration_seconds', $data)) {
            $seconds = $this->durationToSeconds($data);
            if ($seconds <= 0) {
                throw new TimeException(422, 'Enter a duration greater than zero.');
            }
            $sets[] = 'duration_seconds = :dur';
            $params['dur'] = $seconds;
        }
        $this->db()->run('UPDATE time_entries SET ' . implode(', ', $sets) . ' WHERE uuid = :u', $params);

        return $this->find($uuid) ?? [];
    }

    public function delete(string $uuid, string $actor): void
    {
        $this->requireEditable($uuid);
        $this->db()->run('DELETE FROM time_entries WHERE uuid = :u', ['u' => $uuid]);
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
        if ($this->db()->one('SELECT uuid FROM projects WHERE uuid = :u', ['u' => $projectUuid]) === null) {
            throw new TimeException(404, 'Project not found.');
        }
        $uuid = Uuid::v4();
        $now = Clock::nowIso();
        $this->db()->run(
            'INSERT INTO time_entries (uuid, project_uuid, task_uuid, author, description, activity, started_at, duration_seconds, billable, rate_pence, running, billed, created_at, updated_at)
             VALUES (:uuid, :p, :task, :author, :desc, :activity, :now, 0, :billable, :rate, 1, 0, :now, :now)',
            [
                'uuid' => $uuid, 'p' => $projectUuid, 'task' => $this->nullable($data['task_uuid'] ?? null), 'author' => $actor,
                'desc' => (string) ($data['description'] ?? ''),
                'activity' => $this->activity($data),
                'billable' => !empty($data['billable'] ?? true) ? 1 : 0, 'rate' => (int) round(((float) ($data['rate'] ?? 0)) * 100), 'now' => $now,
            ]
        );

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
        $this->db()->run('UPDATE time_entries SET running = 0, duration_seconds = :dur, updated_at = :now, revision = revision + 1 WHERE uuid = :u', ['dur' => $elapsed, 'now' => Clock::nowIso(), 'u' => (string) $running['uuid']]);

        return $this->find((string) $running['uuid']) ?? [];
    }

    /** @return array<string,mixed>|null */
    public function runningTimer(string $actor): ?array
    {
        return $this->db()->one('SELECT * FROM time_entries WHERE author = :a AND running = 1 LIMIT 1', ['a' => $actor]);
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
        foreach ($entryUuids as $uuid) {
            $entry = $this->find((string) $uuid);
            if ($entry === null || (int) $entry['billed'] === 1 || (int) $entry['running'] === 1) {
                continue;
            }
            $this->db()->run('UPDATE time_entries SET billed = 1, invoice_uuid = :inv, updated_at = :now, revision = revision + 1 WHERE uuid = :u', ['inv' => $invoiceUuid, 'now' => Clock::nowIso(), 'u' => (string) $uuid]);
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
        if ((int) $entry['running'] === 1) {
            $start = strtotime((string) ($entry['started_at'] ?? ''));
            $base = (int) $entry['duration_seconds'];
            if ($start !== false) {
                return $base + max(0, (int) (strtotime(Clock::nowIso()) - $start));
            }

            return $base;
        }

        return (int) $entry['duration_seconds'];
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
