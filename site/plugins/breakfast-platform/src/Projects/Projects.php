<?php

declare(strict_types=1);

namespace Breakfast\Platform\Projects;

use Breakfast\Platform\Support\Clock;
use Breakfast\Platform\Support\Database;
use Breakfast\Platform\Support\FileStore;
use Breakfast\Platform\Support\Uuid;

/**
 * Projects — the core delivery workspace (Phase 2), stored as flat files.
 *
 * A project links back to the immutable commercial records (opportunity,
 * proposal, contract) by reference; it never copies their frozen terms into
 * mutable fields. Delivery state is a real, server-enforced state machine;
 * awaiting-client and blocked time are measured; every transition is recorded
 * as an immutable project_event and (by the caller) audited + surfaced on the
 * CRM timeline. Money is integer pence.
 *
 * Progress is intentionally NOT stored as a vanity percentage here — it is
 * derived from real milestones and tasks (which remain database-backed until
 * their own migration slice).
 */
final class Projects
{
    /** @var list<string> */
    public const STATUSES = [
        'draft', 'planning', 'onboarding', 'active', 'review', 'ready_to_launch', 'completed',
        'awaiting_client', 'blocked', 'paused', 'cancelled', 'archived',
    ];

    /**
     * Allowed transitions. `reopen`, `archive` and `restore` are special actions
     * (methods) rather than free transitions, so a completed project cannot slip
     * straight back to active and archival is always deliberate.
     *
     * @var array<string,list<string>>
     */
    private const TRANSITIONS = [
        'draft'           => ['planning', 'onboarding', 'active', 'cancelled'],
        'planning'        => ['onboarding', 'active', 'awaiting_client', 'blocked', 'paused', 'cancelled'],
        'onboarding'      => ['planning', 'active', 'awaiting_client', 'blocked', 'paused', 'cancelled'],
        'active'          => ['review', 'ready_to_launch', 'awaiting_client', 'blocked', 'paused', 'cancelled'],
        'review'          => ['active', 'ready_to_launch', 'awaiting_client', 'blocked', 'cancelled'],
        'ready_to_launch' => ['active', 'review', 'completed', 'awaiting_client', 'blocked', 'cancelled'],
        'awaiting_client' => ['planning', 'onboarding', 'active', 'review', 'blocked', 'paused', 'cancelled'],
        'blocked'         => ['planning', 'onboarding', 'active', 'review', 'awaiting_client', 'cancelled'],
        'paused'          => ['planning', 'active', 'awaiting_client', 'cancelled'],
        'completed'       => [],       // only reopen() or archive()
        'cancelled'       => [],       // only archive()
        'archived'        => [],       // only restore()
    ];

    public function __construct(
        private readonly Database $db,
        private readonly FileStore $store,
    ) {
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
        $rows = $this->store->all('projects');
        if (!array_key_exists('archived', $filters) || $filters['archived'] === false) {
            $rows = array_filter($rows, static fn (array $r): bool => (int) ($r['archived'] ?? 0) === 0);
        }
        if (!empty($filters['status'])) {
            $rows = array_filter($rows, static fn (array $r): bool => (string) ($r['status'] ?? '') === (string) $filters['status']);
        }
        if (!empty($filters['company_uuid'])) {
            $rows = array_filter($rows, static fn (array $r): bool => (string) ($r['company_uuid'] ?? '') === (string) $filters['company_uuid']);
        }
        if (!empty($filters['owner'])) {
            $rows = array_filter($rows, static fn (array $r): bool => (string) ($r['owner'] ?? '') === (string) $filters['owner']);
        }
        $rows = array_values($rows);
        usort($rows, static fn ($a, $b) => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));
        $rows = array_slice($rows, 0, (int) ($filters['limit'] ?? 100));

        return array_map(fn (array $r): array => $this->withDerived($r), $rows);
    }

    /** @return array<string,mixed>|null */
    public function find(string $uuid): ?array
    {
        $row = $this->store->find('projects', $uuid);
        if ($row === null) {
            return null;
        }
        $row = $this->withDerived($row);

        $members = array_values(array_filter($this->store->all('project_members'), static fn (array $r): bool => (string) ($r['project_uuid'] ?? '') === $uuid));
        usort($members, static fn ($a, $b) => strcmp((string) ($a['created_at'] ?? ''), (string) ($b['created_at'] ?? '')));

        $events = array_values(array_filter($this->store->all('project_events'), static fn (array $r): bool => (string) ($r['project_uuid'] ?? '') === $uuid));
        usort($events, static fn ($a, $b) => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));

        $row['members'] = $members;
        $row['events']  = array_slice($events, 0, 100);

        return $row;
    }

    // ==================================================================
    // Create
    // ==================================================================

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function create(array $data, string $actor): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new ProjectException(422, 'Enter a project name.');
        }

        $uuid   = Uuid::v4();
        $now    = Clock::nowIso();
        $number = $this->allocateNumber();

        $this->store->put('projects', [
            'uuid'              => $uuid,
            'number'            => $number,
            'name'              => $name,
            'company_uuid'      => $this->nullable($data['company_uuid'] ?? null),
            'contact_uuid'      => $this->nullable($data['contact_uuid'] ?? null),
            'opportunity_uuid'  => $this->nullable($data['opportunity_uuid'] ?? null),
            'proposal_uuid'     => $this->nullable($data['proposal_uuid'] ?? null),
            'contract_uuid'     => $this->nullable($data['contract_uuid'] ?? null),
            'template_uuid'     => $this->nullable($data['template_uuid'] ?? null),
            'template_version'  => (int) ($data['template_version'] ?? 0),
            'owner'             => (string) ($data['owner'] ?? $actor),
            'project_type'      => (string) ($data['project_type'] ?? ''),
            'service_category'  => (string) ($data['service_category'] ?? ''),
            'status'            => in_array((string) ($data['status'] ?? 'draft'), self::STATUSES, true) ? (string) ($data['status'] ?? 'draft') : 'draft',
            'priority'          => in_array((string) ($data['priority'] ?? 'normal'), ['low', 'normal', 'high', 'urgent'], true) ? (string) ($data['priority'] ?? 'normal') : 'normal',
            'health'            => 'on_track',
            'start_date'        => $this->nullable($data['start_date'] ?? null),
            'target_date'       => $this->nullable($data['target_date'] ?? null),
            'quoted_value'      => (int) round(((float) ($data['quoted_value'] ?? 0)) * 100),
            'approved_variations' => 0,
            'estimated_cost'    => 0,
            'currency'          => (string) ($data['currency'] ?? 'GBP'),
            'description'       => (string) ($data['description'] ?? ''),
            'internal_summary'  => (string) ($data['internal_summary'] ?? ''),
            'client_summary'    => (string) ($data['client_summary'] ?? ''),
            'scope'             => (string) ($data['scope'] ?? ''),
            'exclusions'        => (string) ($data['exclusions'] ?? ''),
            'tags'              => is_array($data['tags'] ?? null) ? array_values($data['tags']) : [],
            'revision'          => 0,
            'archived'          => 0,
            'archived_at'       => null,
            'awaiting_since'    => null,
            'awaiting_seconds'  => 0,
            'blocked_reason'    => '',
            'cancel_reason'     => '',
            'completed_at'      => null,
            'actual_completion' => null,
            'created_by'        => $actor,
            'created_at'        => $now,
            'updated_at'        => $now,
        ]);

        // Owner is always a member (lead).
        $this->addMember($uuid, (string) ($data['owner'] ?? $actor), 'lead');
        foreach (is_array($data['members'] ?? null) ? $data['members'] : [] as $m) {
            $email = is_array($m) ? (string) ($m['user_email'] ?? '') : (string) $m;
            if ($email !== '') {
                $this->addMember($uuid, $email, is_array($m) ? (string) ($m['role'] ?? 'member') : 'member');
            }
        }

        $this->event($uuid, 'created', 'Project created as ' . $number, $actor);

        return $this->find($uuid) ?? [];
    }

    // ==================================================================
    // Update (optimistic concurrency)
    // ==================================================================

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function update(string $uuid, array $data, string $actor, ?int $expectedRevision = null): array
    {
        $project = $this->store->find('projects', $uuid);
        if ($project === null) {
            throw new ProjectException(404, 'Project not found.');
        }
        if ((int) ($project['archived'] ?? 0) === 1) {
            throw new ProjectException(409, 'Restore the project before editing it.');
        }
        if ($expectedRevision !== null && (int) ($project['revision'] ?? 0) !== $expectedRevision) {
            throw new ProjectException(409, 'This project was changed by someone else. Reload and try again.');
        }

        $this->store->update('projects', $uuid, static function (array $row) use ($data): array {
            $textCols = ['name', 'owner', 'project_type', 'service_category', 'description', 'internal_summary', 'client_summary', 'scope', 'exclusions', 'start_date', 'target_date', 'currency'];
            foreach ($textCols as $col) {
                if (array_key_exists($col, $data)) {
                    $value = in_array($col, ['start_date', 'target_date'], true)
                        ? (trim((string) ($data[$col] ?? '')) === '' ? null : (string) $data[$col])
                        : (string) $data[$col];
                    $row[$col] = $value;
                }
            }
            if (array_key_exists('priority', $data) && in_array((string) $data['priority'], ['low', 'normal', 'high', 'urgent'], true)) {
                $row['priority'] = (string) $data['priority'];
            }
            if (array_key_exists('health', $data) && in_array((string) $data['health'], ['on_track', 'at_risk', 'off_track'], true)) {
                $row['health'] = (string) $data['health'];
            }
            foreach (['quoted_value', 'estimated_cost'] as $money) {
                if (array_key_exists($money, $data)) {
                    $row[$money] = (int) round(((float) $data[$money]) * 100);
                }
            }
            if (array_key_exists('tags', $data) && is_array($data['tags'])) {
                $row['tags'] = array_values($data['tags']);
            }
            $row['revision']   = (int) ($row['revision'] ?? 0) + 1;
            $row['updated_at'] = Clock::nowIso();

            return $row;
        });
        $this->event($uuid, 'updated', 'Project details updated', $actor);

        return $this->find($uuid) ?? [];
    }

    // ==================================================================
    // State machine
    // ==================================================================

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function transition(string $uuid, string $to, array $data, string $actor): array
    {
        if (!in_array($to, self::STATUSES, true)) {
            throw new ProjectException(422, 'Unknown project status.');
        }
        $project = $this->store->find('projects', $uuid);
        if ($project === null) {
            throw new ProjectException(404, 'Project not found.');
        }
        $from = (string) $project['status'];
        if ((int) ($project['archived'] ?? 0) === 1) {
            throw new ProjectException(409, 'Restore the project before changing its status.');
        }
        if ($from === $to) {
            throw new ProjectException(409, 'The project is already ' . $to . '.');
        }
        if (in_array($to, ['archived'], true)) {
            throw new ProjectException(422, 'Use archive to archive a project.');
        }
        if ($to === 'completed' && !in_array($from, self::TRANSITIONS[$from] ?? [], true) && !in_array('completed', self::TRANSITIONS[$from] ?? [], true)) {
            throw new ProjectException(409, 'A project must be ready to launch before it can be completed.');
        }
        if (!in_array($to, self::TRANSITIONS[$from] ?? [], true)) {
            throw new ProjectException(409, "Cannot move a project from {$from} to {$to}.");
        }
        if ($to === 'cancelled' && trim((string) ($data['reason'] ?? '')) === '') {
            throw new ProjectException(422, 'A cancellation reason is required.');
        }
        if ($to === 'blocked' && trim((string) ($data['reason'] ?? '')) === '') {
            throw new ProjectException(422, 'A blocking reason is required.');
        }

        $now    = Clock::nowIso();
        $reason = trim((string) ($data['reason'] ?? ''));
        $this->store->update('projects', $uuid, static function (array $row) use ($from, $to, $reason, $now): array {
            $row['status'] = $to;

            // Leaving awaiting_client accrues the measured waiting time.
            if ($from === 'awaiting_client' && !empty($row['awaiting_since'])) {
                $elapsed = max(0, strtotime($now) - (int) strtotime((string) $row['awaiting_since']));
                $row['awaiting_seconds'] = (int) ($row['awaiting_seconds'] ?? 0) + $elapsed;
                $row['awaiting_since']   = null;
            }
            if ($to === 'awaiting_client') {
                $row['awaiting_since'] = $now;
            }
            if ($to === 'blocked') {
                $row['blocked_reason'] = $reason;
            } elseif ($from === 'blocked') {
                $row['blocked_reason'] = '';
            }
            if ($to === 'cancelled') {
                $row['cancel_reason'] = $reason;
            }
            if ($to === 'completed') {
                $row['completed_at']      = $now;
                $row['actual_completion'] = date('Y-m-d');
            }
            $row['revision']   = (int) ($row['revision'] ?? 0) + 1;
            $row['updated_at'] = $now;

            return $row;
        });
        $detail = 'Status ' . $from . ' → ' . $to . ($reason !== '' ? ' (' . $reason . ')' : '');
        $this->event($uuid, 'status_changed', $detail, $actor);

        return $this->find($uuid) ?? [];
    }

    /**
     * Reopen a completed project (deliberate action, not a free transition).
     *
     * @return array<string,mixed>
     */
    public function reopen(string $uuid, string $actor): array
    {
        $project = $this->store->find('projects', $uuid);
        if ($project === null) {
            throw new ProjectException(404, 'Project not found.');
        }
        if ((string) $project['status'] !== 'completed') {
            throw new ProjectException(409, 'Only a completed project can be reopened.');
        }
        $this->store->update('projects', $uuid, static function (array $row): array {
            $row['status']            = 'active';
            $row['completed_at']      = null;
            $row['actual_completion'] = null;
            $row['revision']          = (int) ($row['revision'] ?? 0) + 1;
            $row['updated_at']        = Clock::nowIso();

            return $row;
        });
        $this->event($uuid, 'reopened', 'Project reopened', $actor);

        return $this->find($uuid) ?? [];
    }

    /** @return array<string,mixed> */
    public function archive(string $uuid, string $actor): array
    {
        $project = $this->store->find('projects', $uuid);
        if ($project === null) {
            throw new ProjectException(404, 'Project not found.');
        }
        if ((int) ($project['archived'] ?? 0) === 1) {
            throw new ProjectException(409, 'This project is already archived.');
        }
        $now = Clock::nowIso();
        $this->store->update('projects', $uuid, static function (array $row) use ($now): array {
            $row['archived']    = 1;
            $row['archived_at'] = $now;
            $row['revision']    = (int) ($row['revision'] ?? 0) + 1;
            $row['updated_at']  = $now;

            return $row;
        });
        $this->event($uuid, 'archived', 'Project archived', $actor);

        return $this->find($uuid) ?? [];
    }

    /** @return array<string,mixed> */
    public function restore(string $uuid, string $actor): array
    {
        $project = $this->store->find('projects', $uuid);
        if ($project === null) {
            throw new ProjectException(404, 'Project not found.');
        }
        if ((int) ($project['archived'] ?? 0) === 0) {
            throw new ProjectException(409, 'This project is not archived.');
        }
        $this->store->update('projects', $uuid, static function (array $row): array {
            $row['archived']    = 0;
            $row['archived_at'] = null;
            $row['revision']    = (int) ($row['revision'] ?? 0) + 1;
            $row['updated_at']  = Clock::nowIso();

            return $row;
        });
        $this->event($uuid, 'restored', 'Project restored', $actor);

        return $this->find($uuid) ?? [];
    }

    // ==================================================================
    // Members
    // ==================================================================

    /**
     * @param list<mixed> $members
     * @return array<string,mixed>
     */
    public function setMembers(string $uuid, array $members, string $actor): array
    {
        if ($this->store->find('projects', $uuid) === null) {
            throw new ProjectException(404, 'Project not found.');
        }

        foreach ($this->store->all('project_members') as $existing) {
            if ((string) ($existing['project_uuid'] ?? '') === $uuid) {
                $this->store->delete('project_members', (string) ($existing['uuid'] ?? ''));
            }
        }
        foreach ($members as $m) {
            $email = is_array($m) ? (string) ($m['user_email'] ?? '') : (string) $m;
            if ($email !== '') {
                $this->addMember($uuid, $email, is_array($m) ? (string) ($m['role'] ?? 'member') : 'member');
            }
        }
        $this->event($uuid, 'members_changed', 'Team updated', $actor);

        return $this->find($uuid) ?? [];
    }

    /** Record an immutable project event (public wrapper for other services). */
    public function logEvent(string $uuid, string $type, string $detail, string $actor): void
    {
        $this->event($uuid, $type, $detail, $actor);
    }

    /** Whether a project exists (guard for other services). */
    public function exists(string $uuid): bool
    {
        return $this->store->exists('projects', $uuid);
    }

    /**
     * The raw stored project record (no derived roll-ups), for other services.
     *
     * @return array<string,mixed>|null
     */
    public function raw(string $uuid): ?array
    {
        return $this->store->find('projects', $uuid);
    }

    /**
     * Active (non-archived) project uuids matching a reference column
     * (opportunity_uuid, proposal_uuid, contract_uuid).
     *
     * @return list<string>
     */
    public function activeUuidsBy(string $column, string $value): array
    {
        $out = [];
        foreach ($this->store->all('projects') as $row) {
            if ((string) ($row[$column] ?? '') === $value && (int) ($row['archived'] ?? 0) === 0) {
                $out[] = (string) $row['uuid'];
            }
        }

        return $out;
    }

    /** Attach a template version to a project. */
    public function assignTemplate(string $uuid, string $templateUuid, int $version): void
    {
        $this->store->update('projects', $uuid, static function (array $row) use ($templateUuid, $version): array {
            $row['template_uuid']    = $templateUuid;
            $row['template_version'] = $version;
            $row['updated_at']       = Clock::nowIso();

            return $row;
        });
    }

    /**
     * Apply an approved change request's financial + schedule impact to a project.
     */
    public function applyChangeVariation(string $uuid, int $addValue, ?string $targetDate): void
    {
        $this->store->update('projects', $uuid, static function (array $row) use ($addValue, $targetDate): array {
            $row['approved_variations'] = (int) ($row['approved_variations'] ?? 0) + $addValue;
            if ($targetDate !== null && $targetDate !== '') {
                $row['target_date'] = $targetDate;
            }
            $row['revision']   = (int) ($row['revision'] ?? 0) + 1;
            $row['updated_at'] = Clock::nowIso();

            return $row;
        });
    }

    // ==================================================================
    // Internals
    // ==================================================================

    private function allocateNumber(): string
    {
        $year = (int) date('Y');
        $seq  = $this->store->bump('project_sequences', 'PRJ-' . $year);

        return sprintf('PRJ-%d-%04d', $year, $seq);
    }

    private function addMember(string $projectUuid, string $email, string $role): void
    {
        $role = in_array($role, ['lead', 'member', 'reviewer'], true) ? $role : 'member';
        // Dedupe by (project, email): update the role if the member already exists.
        foreach ($this->store->all('project_members') as $member) {
            if ((string) ($member['project_uuid'] ?? '') === $projectUuid && (string) ($member['user_email'] ?? '') === $email) {
                $this->store->update('project_members', (string) $member['uuid'], static function (array $row) use ($role): array {
                    $row['role'] = $role;

                    return $row;
                });

                return;
            }
        }
        $this->store->put('project_members', [
            'uuid'         => Uuid::v4(),
            'project_uuid' => $projectUuid,
            'user_email'   => $email,
            'role'         => $role,
            'created_at'   => Clock::nowIso(),
        ]);
    }

    private function event(string $uuid, string $type, string $detail, string $actor): void
    {
        $this->store->put('project_events', [
            'uuid'         => Uuid::v4(),
            'project_uuid' => $uuid,
            'type'         => $type,
            'detail'       => $detail,
            'actor'        => $actor,
            'created_at'   => Clock::nowIso(),
        ]);
    }

    /**
     * Add derived financial roll-up (invoiced/paid from linked invoices),
     * task/milestone progress and normalise tags, without persisting them.
     *
     * @param array<string,mixed> $r
     * @return array<string,mixed>
     */
    private function withDerived(array $r): array
    {
        $uuid = (string) $r['uuid'];
        // Invoices are flat files: roll up invoiced/paid for this project.
        $invoiced = 0;
        $paid     = 0;
        foreach ($this->store->all('invoices') as $inv) {
            if ((string) ($inv['project_uuid'] ?? '') === $uuid && (string) ($inv['status'] ?? '') !== 'void') {
                $invoiced += (int) ($inv['total'] ?? 0);
                $paid     += (int) ($inv['amount_paid'] ?? 0);
            }
        }
        $r['invoiced_value'] = $invoiced;
        $r['paid_value']     = $paid;
        $r['tags']           = is_array($r['tags'] ?? null) ? array_values(array_map(static fn ($t): string => (string) $t, $r['tags'])) : [];

        // Real progress, derived from delivery tasks/milestones (still database-
        // backed) — never a typed vanity percentage.
        $tasks = $this->db->one(
            "SELECT COUNT(*) AS total, COALESCE(SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END),0) AS done
             FROM project_tasks WHERE project_uuid = :u AND archived = 0 AND status <> 'cancelled'",
            ['u' => $uuid]
        ) ?? ['total' => 0, 'done' => 0];
        $ms = $this->db->one(
            "SELECT COUNT(*) AS total, COALESCE(SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END),0) AS done
             FROM milestones WHERE project_uuid = :u AND status <> 'cancelled'",
            ['u' => $uuid]
        ) ?? ['total' => 0, 'done' => 0];
        $r['tasks_total']       = (int) $tasks['total'];
        $r['tasks_completed']   = (int) $tasks['done'];
        $r['milestones_total']  = (int) $ms['total'];
        $r['milestones_done']   = (int) $ms['done'];
        $r['progress_percent']  = (int) $tasks['total'] > 0 ? (int) round((int) $tasks['done'] / (int) $tasks['total'] * 100) : 0;
        // Live awaiting-client seconds include the currently-open window.
        $open = 0;
        if (!empty($r['awaiting_since'])) {
            $open = max(0, strtotime((string) Clock::nowIso()) - (int) strtotime((string) $r['awaiting_since']));
        }
        $r['awaiting_seconds_live'] = (int) ($r['awaiting_seconds'] ?? 0) + $open;

        return $r;
    }

    private function nullable(mixed $value): ?string
    {
        $v = trim((string) ($value ?? ''));

        return $v === '' ? null : $v;
    }
}
