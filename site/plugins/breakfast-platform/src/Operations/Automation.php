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

    // ==================================================================
    // Rule CRUD
    // ==================================================================

    /** @return list<array<string,mixed>> */
    public function list(): array
    {
        return $this->db()->all('SELECT * FROM automation_rules ORDER BY created_at ASC LIMIT 200');
    }

    /** @return array<string,mixed>|null */
    public function find(string $uuid): ?array
    {
        return $this->db()->one('SELECT * FROM automation_rules WHERE uuid = :u', ['u' => $uuid]);
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
        $this->db()->run(
            'INSERT INTO automation_rules (uuid, name, trigger_type, threshold_days, enabled, created_by, created_at, updated_at)
             VALUES (:uuid, :name, :trigger, :days, :enabled, :actor, :now, :now)',
            ['uuid' => $uuid, 'name' => $name, 'trigger' => $trigger, 'days' => max(0, (int) ($data['threshold_days'] ?? 0)), 'enabled' => !empty($data['enabled'] ?? true) ? 1 : 0, 'actor' => $actor, 'now' => $now]
        );

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
        $sets = ['updated_at = :now', 'revision = revision + 1'];
        $params = ['u' => $uuid, 'now' => Clock::nowIso()];
        if (array_key_exists('name', $data)) {
            $sets[] = 'name = :name';
            $params['name'] = trim((string) $data['name']) ?: (string) $rule['name'];
        }
        if (array_key_exists('threshold_days', $data)) {
            $sets[] = 'threshold_days = :days';
            $params['days'] = max(0, (int) $data['threshold_days']);
        }
        if (array_key_exists('enabled', $data)) {
            $sets[] = 'enabled = :enabled';
            $params['enabled'] = !empty($data['enabled']) ? 1 : 0;
        }
        $this->db()->run('UPDATE automation_rules SET ' . implode(', ', $sets) . ' WHERE uuid = :u', $params);

        return $this->find($uuid) ?? [];
    }

    public function delete(string $uuid): void
    {
        // Remove child fires explicitly (foreign-key cascade enforcement is off
        // during the flat-file migration), then the rule itself.
        $this->db()->run('DELETE FROM automation_fires WHERE rule_uuid = :u', ['u' => $uuid]);
        $this->db()->run('DELETE FROM automation_rules WHERE uuid = :u', ['u' => $uuid]);
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
        foreach ($this->db()->all('SELECT * FROM automation_rules WHERE enabled = 1') as $rule) {
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
            if ($this->db()->one('SELECT uuid FROM automation_fires WHERE rule_uuid = :r AND target_ref = :t', ['r' => $uuid, 't' => (string) $t['target_ref']]) !== null) {
                continue; // already fired
            }
            $task = $this->platform->projectTasks()->create($projectUuid, [
                'title' => (string) $rule['name'] . ': ' . (string) $t['label'],
                'source' => 'automation', 'source_ref' => $uuid . ':' . (string) $t['target_ref'],
            ], 'automation');
            $this->db()->run(
                'INSERT INTO automation_fires (uuid, rule_uuid, target_ref, project_uuid, task_uuid, detail, created_at) VALUES (:uuid, :r, :t, :p, :task, :detail, :now) ON CONFLICT (rule_uuid, target_ref) DO NOTHING',
                ['uuid' => Uuid::v4(), 'r' => $uuid, 't' => (string) $t['target_ref'], 'p' => $projectUuid, 'task' => (string) $task['uuid'], 'detail' => (string) $t['label'], 'now' => Clock::nowIso()]
            );
            $this->platform->audit()->event('automation.fired', 'project', $projectUuid, $actor, ['rule' => $uuid, 'target' => (string) $t['target_ref']]);
            $fired++;
        }
        $this->db()->run('UPDATE automation_rules SET last_run_at = :now, fire_count = fire_count + :n WHERE uuid = :u', ['now' => Clock::nowIso(), 'n' => $fired, 'u' => $uuid]);

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
