<?php

declare(strict_types=1);

namespace Breakfast\Platform\Operations;

use Breakfast\Platform\Support\Clock;
use Breakfast\Platform\Support\Database;
use Breakfast\Platform\Support\Platform;
use Breakfast\Platform\Support\Uuid;

/**
 * Automation (Phase 4 — Operations).
 *
 * A scheduled rule engine. Each enabled rule watches a trigger condition
 * evaluated against source-of-truth state and, when a target first matches after
 * its grace period, performs its action — a follow-up task on the target's
 * project. Firing is idempotent per (rule, target): the runner is safe to run on
 * a schedule and never acts twice on the same thing.
 */
final class Automation
{
    /** @var array<string,string> trigger type => human label */
    public const TRIGGERS = [
        'invoice_overdue' => 'Invoice overdue',
        'onboarding_stalled' => 'Onboarding stalled after submission',
        'change_request_unapplied' => 'Approved change not yet applied',
    ];

    public function __construct(private readonly Platform $platform)
    {
    }

    private function db(): Database
    {
        return $this->platform->db();
    }

    private function store(): \Breakfast\Platform\Support\FileStore
    {
        return $this->platform->fileStore();
    }

    // ==================================================================
    // Rule CRUD
    // ==================================================================

    /** @return list<array<string,mixed>> */
    public function list(): array
    {
        $rows = $this->store()->all('automation_rules');
        usort($rows, static fn ($a, $b) => strcmp((string) ($a['created_at'] ?? ''), (string) ($b['created_at'] ?? '')));

        return array_slice($rows, 0, 200);
    }

    /** @return array<string,mixed>|null */
    public function find(string $uuid): ?array
    {
        return $this->store()->find('automation_rules', $uuid);
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function create(array $data, string $actor): array
    {
        $trigger = (string) ($data['trigger_type'] ?? '');
        if (!isset(self::TRIGGERS[$trigger])) {
            throw new AutomationException(422, 'Unknown trigger type.');
        }
        $name = trim((string) ($data['name'] ?? '')) ?: self::TRIGGERS[$trigger];
        $uuid = Uuid::v4();
        $now = Clock::nowIso();
        $this->store()->put('automation_rules', [
            'uuid'           => $uuid,
            'name'           => $name,
            'trigger_type'   => $trigger,
            'threshold_days' => max(0, (int) ($data['threshold_days'] ?? 0)),
            'enabled'        => !empty($data['enabled'] ?? true) ? 1 : 0,
            'revision'       => 0,
            'last_run_at'    => null,
            'fire_count'     => 0,
            'created_by'     => $actor,
            'created_at'     => $now,
            'updated_at'     => $now,
        ]);

        return $this->find($uuid) ?? [];
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function update(string $uuid, array $data, string $actor, ?int $expectedRevision = null): array
    {
        $rule = $this->find($uuid);
        if ($rule === null) {
            throw new AutomationException(404, 'Rule not found.');
        }
        if ($expectedRevision !== null && (int) $rule['revision'] !== $expectedRevision) {
            throw new AutomationException(409, 'This rule was changed by someone else. Reload and try again.');
        }
        $this->store()->update('automation_rules', $uuid, static function (array $row) use ($data): array {
            if (array_key_exists('name', $data)) {
                $row['name'] = trim((string) $data['name']) ?: (string) $row['name'];
            }
            if (array_key_exists('threshold_days', $data)) {
                $row['threshold_days'] = max(0, (int) $data['threshold_days']);
            }
            if (array_key_exists('enabled', $data)) {
                $row['enabled'] = !empty($data['enabled']) ? 1 : 0;
            }
            $row['revision']   = (int) ($row['revision'] ?? 0) + 1;
            $row['updated_at'] = Clock::nowIso();

            return $row;
        });

        return $this->find($uuid) ?? [];
    }

    public function delete(string $uuid): void
    {
        // Remove child fires explicitly, then the rule itself.
        foreach ($this->store()->all('automation_fires') as $fire) {
            if ((string) ($fire['rule_uuid'] ?? '') === $uuid) {
                $this->store()->delete('automation_fires', (string) ($fire['uuid'] ?? ''));
            }
        }
        $this->store()->delete('automation_rules', $uuid);
    }

    // ==================================================================
    // Run
    // ==================================================================

    /**
     * Evaluate every enabled rule as of $asOf (Y-m-d) and fire the action for
     * each newly-matching target. Idempotent per (rule, target).
     *
     * @return array{fired:int,rules:int}
     */
    public function run(string $asOf, string $actor): array
    {
        $asOf = $this->normaliseDate($asOf) ?? substr(Clock::nowIso(), 0, 10);
        $fired = 0;
        $rules = 0;
        foreach ($this->store()->all('automation_rules') as $rule) {
            if ((int) ($rule['enabled'] ?? 0) !== 1) {
                continue;
            }
            $rules++;
            $fired += $this->runRule($rule, $asOf, $actor);
        }

        return ['fired' => $fired, 'rules' => $rules];
    }

    /** @param array<string,mixed> $rule */
    private function runRule(array $rule, string $asOf, string $actor): int
    {
        $uuid = (string) $rule['uuid'];
        $cutoff = date('Y-m-d', strtotime('-' . max(0, (int) $rule['threshold_days']) . ' day', (int) strtotime($asOf)));
        $targets = match ((string) $rule['trigger_type']) {
            'invoice_overdue' => $this->overdueInvoices($asOf),
            'onboarding_stalled' => $this->stalledOnboarding($cutoff),
            'change_request_unapplied' => $this->unappliedChanges($cutoff),
            default => [],
        };
        $fired = 0;
        foreach ($targets as $t) {
            $projectUuid = (string) ($t['project_uuid'] ?? '');
            if ($projectUuid === '') {
                continue; // no project to hang a follow-up task on
            }
            $targetRef = (string) $t['target_ref'];
            // Idempotent per (rule, target): putIfAbsent on a hash of the pair.
            $fireId = sha1($uuid . ':' . $targetRef);
            if ($this->store()->exists('automation_fires', $fireId)) {
                continue; // already fired
            }
            $task = $this->platform->projectTasks()->create($projectUuid, [
                'title' => (string) $rule['name'] . ': ' . (string) $t['label'],
                'source' => 'automation', 'source_ref' => $uuid . ':' . $targetRef,
            ], 'automation');
            $written = $this->store()->putIfAbsent('automation_fires', $fireId, [
                'uuid'         => $fireId,
                'rule_uuid'    => $uuid,
                'target_ref'   => $targetRef,
                'project_uuid' => $projectUuid,
                'task_uuid'    => (string) $task['uuid'],
                'detail'       => (string) $t['label'],
                'created_at'   => Clock::nowIso(),
            ]);
            if (!$written) {
                continue;
            }
            $this->platform->audit()->event('automation.fired', 'project', $projectUuid, $actor, ['rule' => $uuid, 'target' => $targetRef]);
            $fired++;
        }
        $now = Clock::nowIso();
        $this->store()->update('automation_rules', $uuid, static function (array $row) use ($now, $fired): array {
            $row['last_run_at'] = $now;
            $row['fire_count']  = (int) ($row['fire_count'] ?? 0) + $fired;

            return $row;
        });

        return $fired;
    }

    // ==================================================================
    // Trigger evaluators
    // ==================================================================

    /** @return list<array{target_ref:string,project_uuid:string,label:string}> */
    private function overdueInvoices(string $asOf): array
    {
        $rows = array_values(array_filter($this->platform->fileStore()->all('invoices'), static fn (array $r): bool => in_array((string) ($r['status'] ?? ''), ['issued', 'part_paid'], true)
            && (string) ($r['due_date'] ?? '') !== ''
            && (string) $r['due_date'] < $asOf
            && ($r['project_uuid'] ?? null) !== null && (string) $r['project_uuid'] !== ''));

        return array_map(static fn (array $r): array => [
            'target_ref' => 'invoice:' . (string) $r['uuid'], 'project_uuid' => (string) $r['project_uuid'],
            'label' => 'Invoice ' . (string) ($r['number'] ?? '') . ' is overdue',
        ], $rows);
    }

    /** @return list<array{target_ref:string,project_uuid:string,label:string}> */
    private function stalledOnboarding(string $cutoff): array
    {
        $rows = $this->db()->all(
            "SELECT uuid, project_uuid FROM onboarding_instances
             WHERE status IN ('submitted','under_review') AND submitted_at IS NOT NULL AND submitted_at < :cutoff AND project_uuid IS NOT NULL",
            ['cutoff' => $cutoff]
        );

        return array_map(static fn (array $r): array => [
            'target_ref' => 'onboarding:' . (string) $r['uuid'], 'project_uuid' => (string) $r['project_uuid'],
            'label' => 'Onboarding submitted and awaiting review',
        ], $rows);
    }

    /** @return list<array{target_ref:string,project_uuid:string,label:string}> */
    private function unappliedChanges(string $cutoff): array
    {
        $rows = $this->db()->all(
            "SELECT uuid, number, project_uuid FROM change_requests
             WHERE status = 'approved' AND applied = 0 AND decided_at IS NOT NULL AND decided_at < :cutoff",
            ['cutoff' => $cutoff]
        );

        return array_map(static fn (array $r): array => [
            'target_ref' => 'change_request:' . (string) $r['uuid'], 'project_uuid' => (string) $r['project_uuid'],
            'label' => (string) ($r['number'] ?? 'Change') . ' approved but not applied',
        ], $rows);
    }

    private function normaliseDate(string $date): ?string
    {
        $date = trim($date);
        if ($date === '') {
            return null;
        }
        $ts = strtotime($date);

        return $ts === false ? null : date('Y-m-d', $ts);
    }
}
