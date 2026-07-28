<?php

declare(strict_types=1);

namespace Breakfast\Platform\Projects;

use Breakfast\Platform\Support\Clock;
use Breakfast\Platform\Support\Database;
use Breakfast\Platform\Support\Uuid;

/**
 * Projects — the core delivery workspace (Phase 2).
 *
 * A project links back to the immutable commercial records (opportunity,
 * proposal, contract) by reference; it never copies their frozen terms into
 * mutable fields. Delivery state is a real, server-enforced state machine;
 * awaiting-client and blocked time are measured; every transition is recorded
 * as an immutable project_event and (by the caller) audited + surfaced on the
 * CRM timeline. Money is integer pence.
 *
 * Progress is intentionally NOT stored as a vanity percentage here — it is
 * derived from real milestones and tasks (added in later modules).
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
        private readonly \Breakfast\Platform\Support\FileStore $store,
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
        $where = ['1 = 1'];
        $params = [];
        if (!array_key_exists('archived', $filters) || $filters['archived'] === false) {
            $where[] = 'archived = 0';
        }
        if (!empty($filters['status'])) {
            $where[] = 'status = :status';
            $params['status'] = (string) $filters['status'];
        }
        if (!empty($filters['company_uuid'])) {
            $where[] = 'company_uuid = :company';
            $params['company'] = (string) $filters['company_uuid'];
        }
        if (!empty($filters['owner'])) {
            $where[] = 'owner = :owner';
            $params['owner'] = (string) $filters['owner'];
        }
        $params['l'] = (int) ($filters['limit'] ?? 100);
        $rows = $this->db->all('SELECT * FROM projects WHERE ' . implode(' AND ', $where) . ' ORDER BY created_at DESC LIMIT :l', $params);

        return array_map(fn (array $r): array => $this->withDerived($r), $rows);
    }

    /** @return array<string,mixed>|null */
    public function find(string $uuid): ?array
    {
        $row = $this->db->one('SELECT * FROM projects WHERE uuid = :u', ['u' => $uuid]);
        if ($row === null) {
            return null;
        }
        $row = $this->withDerived($row);
        $row['members'] = $this->db->all('SELECT * FROM project_members WHERE project_uuid = :u ORDER BY created_at ASC', ['u' => $uuid]);
        $row['events']  = $this->db->all('SELECT * FROM project_events WHERE project_uuid = :u ORDER BY created_at DESC LIMIT 100', ['u' => $uuid]);

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

        return $this->db->transaction(function (Database $db) use ($data, $name, $actor): array {
            $uuid   = Uuid::v4();
            $now    = Clock::nowIso();
            $number = $this->allocateNumber($db);

            $db->run(
                'INSERT INTO projects (
                    uuid, number, name, company_uuid, contact_uuid, opportunity_uuid, proposal_uuid,
                    contract_uuid, template_uuid, template_version, owner, project_type, service_category,
                    status, priority, health, start_date, target_date, quoted_value, currency,
                    description, internal_summary, client_summary, scope, exclusions, tags,
                    created_by, created_at, updated_at
                 ) VALUES (
                    :uuid, :number, :name, :company, :contact, :opp, :proposal,
                    :contract, :template, :tversion, :owner, :ptype, :service,
                    :status, :priority, \'on_track\', :start, :target, :quoted, :currency,
                    :desc, :isummary, :csummary, :scope, :exclusions, :tags,
                    :actor, :now, :now
                 )',
                [
                    'uuid' => $uuid, 'number' => $number, 'name' => $name,
                    'company' => $this->nullable($data['company_uuid'] ?? null),
                    'contact' => $this->nullable($data['contact_uuid'] ?? null),
                    'opp' => $this->nullable($data['opportunity_uuid'] ?? null),
                    'proposal' => $this->nullable($data['proposal_uuid'] ?? null),
                    'contract' => $this->nullable($data['contract_uuid'] ?? null),
                    'template' => $this->nullable($data['template_uuid'] ?? null),
                    'tversion' => (int) ($data['template_version'] ?? 0),
                    'owner' => (string) ($data['owner'] ?? $actor),
                    'ptype' => (string) ($data['project_type'] ?? ''),
                    'service' => (string) ($data['service_category'] ?? ''),
                    'status' => in_array((string) ($data['status'] ?? 'draft'), self::STATUSES, true) ? (string) ($data['status'] ?? 'draft') : 'draft',
                    'priority' => in_array((string) ($data['priority'] ?? 'normal'), ['low', 'normal', 'high', 'urgent'], true) ? (string) ($data['priority'] ?? 'normal') : 'normal',
                    'start' => $this->nullable($data['start_date'] ?? null),
                    'target' => $this->nullable($data['target_date'] ?? null),
                    'quoted' => (int) round(((float) ($data['quoted_value'] ?? 0)) * 100),
                    'currency' => (string) ($data['currency'] ?? 'GBP'),
                    'desc' => (string) ($data['description'] ?? ''),
                    'isummary' => (string) ($data['internal_summary'] ?? ''),
                    'csummary' => (string) ($data['client_summary'] ?? ''),
                    'scope' => (string) ($data['scope'] ?? ''),
                    'exclusions' => (string) ($data['exclusions'] ?? ''),
                    'tags' => json_encode(is_array($data['tags'] ?? null) ? array_values($data['tags']) : [], JSON_UNESCAPED_SLASHES) ?: '[]',
                    'actor' => $actor, 'now' => $now,
                ]
            );

            // Owner is always a member (lead).
            $this->addMember($db, $uuid, (string) ($data['owner'] ?? $actor), 'lead');
            foreach (is_array($data['members'] ?? null) ? $data['members'] : [] as $m) {
                $email = is_array($m) ? (string) ($m['user_email'] ?? '') : (string) $m;
                if ($email !== '') {
                    $this->addMember($db, $uuid, $email, is_array($m) ? (string) ($m['role'] ?? 'member') : 'member');
                }
            }

            $this->event($db, $uuid, 'created', 'Project created as ' . $number, $actor);

            return $this->find($uuid) ?? [];
        });
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
        $project = $this->db->one('SELECT revision, archived FROM projects WHERE uuid = :u', ['u' => $uuid]);
        if ($project === null) {
            throw new ProjectException(404, 'Project not found.');
        }
        if ((int) $project['archived'] === 1) {
            throw new ProjectException(409, 'Restore the project before editing it.');
        }
        if ($expectedRevision !== null && (int) $project['revision'] !== $expectedRevision) {
            throw new ProjectException(409, 'This project was changed by someone else. Reload and try again.');
        }

        $sets = ['updated_at = :now', 'revision = revision + 1'];
        $params = ['u' => $uuid, 'now' => Clock::nowIso()];
        $textCols = ['name', 'owner', 'project_type', 'service_category', 'description', 'internal_summary', 'client_summary', 'scope', 'exclusions', 'start_date', 'target_date', 'currency'];
        foreach ($textCols as $col) {
            if (array_key_exists($col, $data)) {
                $sets[] = "$col = :$col";
                $params[$col] = in_array($col, ['start_date', 'target_date'], true) ? $this->nullable($data[$col]) : (string) $data[$col];
            }
        }
        if (array_key_exists('priority', $data) && in_array((string) $data['priority'], ['low', 'normal', 'high', 'urgent'], true)) {
            $sets[] = 'priority = :priority';
            $params['priority'] = (string) $data['priority'];
        }
        if (array_key_exists('health', $data) && in_array((string) $data['health'], ['on_track', 'at_risk', 'off_track'], true)) {
            $sets[] = 'health = :health';
            $params['health'] = (string) $data['health'];
        }
        foreach (['quoted_value', 'estimated_cost'] as $money) {
            if (array_key_exists($money, $data)) {
                $sets[] = "$money = :$money";
                $params[$money] = (int) round(((float) $data[$money]) * 100);
            }
        }
        if (array_key_exists('tags', $data) && is_array($data['tags'])) {
            $sets[] = 'tags = :tags';
            $params['tags'] = json_encode(array_values($data['tags']), JSON_UNESCAPED_SLASHES) ?: '[]';
        }

        $this->db->run('UPDATE projects SET ' . implode(', ', $sets) . ' WHERE uuid = :u', $params);
        $this->event($this->db, $uuid, 'updated', 'Project details updated', $actor);

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
        $project = $this->db->one('SELECT * FROM projects WHERE uuid = :u', ['u' => $uuid]);
        if ($project === null) {
            throw new ProjectException(404, 'Project not found.');
        }
        $from = (string) $project['status'];
        if ((int) $project['archived'] === 1) {
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

        return $this->db->transaction(function (Database $db) use ($uuid, $project, $from, $to, $data, $actor): array {
            $now = Clock::nowIso();
            $sets = ['status = :to', 'updated_at = :now', 'revision = revision + 1'];
            $params = ['u' => $uuid, 'to' => $to, 'now' => $now];

            // Leaving awaiting_client accrues the measured waiting time.
            if ($from === 'awaiting_client' && !empty($project['awaiting_since'])) {
                $elapsed = max(0, strtotime($now) - (int) strtotime((string) $project['awaiting_since']));
                $sets[] = 'awaiting_seconds = awaiting_seconds + :elapsed';
                $sets[] = 'awaiting_since = NULL';
                $params['elapsed'] = $elapsed;
            }
            if ($to === 'awaiting_client') {
                $sets[] = 'awaiting_since = :since';
                $params['since'] = $now;
            }
            if ($to === 'blocked') {
                $sets[] = 'blocked_reason = :reason';
                $params['reason'] = trim((string) ($data['reason'] ?? ''));
            } elseif ($from === 'blocked') {
                $sets[] = "blocked_reason = ''";
            }
            if ($to === 'cancelled') {
                $sets[] = 'cancel_reason = :creason';
                $params['creason'] = trim((string) ($data['reason'] ?? ''));
            }
            if ($to === 'completed') {
                $sets[] = 'completed_at = :cnow';
                $sets[] = 'actual_completion = :cdate';
                $params['cnow'] = $now;
                $params['cdate'] = date('Y-m-d');
            }

            $db->run('UPDATE projects SET ' . implode(', ', $sets) . ' WHERE uuid = :u', $params);
            $detail = 'Status ' . $from . ' → ' . $to . (($data['reason'] ?? '') !== '' ? ' (' . (string) $data['reason'] . ')' : '');
            $this->event($db, $uuid, 'status_changed', $detail, $actor);

            return $this->find($uuid) ?? [];
        });
    }

    /**
     * Reopen a completed project (deliberate action, not a free transition).
     *
     * @return array<string,mixed>
     */
    public function reopen(string $uuid, string $actor): array
    {
        $project = $this->db->one('SELECT status, archived FROM projects WHERE uuid = :u', ['u' => $uuid]);
        if ($project === null) {
            throw new ProjectException(404, 'Project not found.');
        }
        if ((string) $project['status'] !== 'completed') {
            throw new ProjectException(409, 'Only a completed project can be reopened.');
        }
        $this->db->run("UPDATE projects SET status = 'active', completed_at = NULL, actual_completion = NULL, updated_at = :now, revision = revision + 1 WHERE uuid = :u", ['now' => Clock::nowIso(), 'u' => $uuid]);
        $this->event($this->db, $uuid, 'reopened', 'Project reopened', $actor);

        return $this->find($uuid) ?? [];
    }

    /** @return array<string,mixed> */
    public function archive(string $uuid, string $actor): array
    {
        $project = $this->db->one('SELECT archived FROM projects WHERE uuid = :u', ['u' => $uuid]);
        if ($project === null) {
            throw new ProjectException(404, 'Project not found.');
        }
        if ((int) $project['archived'] === 1) {
            throw new ProjectException(409, 'This project is already archived.');
        }
        $this->db->run("UPDATE projects SET archived = 1, archived_at = :now, updated_at = :now, revision = revision + 1 WHERE uuid = :u", ['now' => Clock::nowIso(), 'u' => $uuid]);
        $this->event($this->db, $uuid, 'archived', 'Project archived', $actor);

        return $this->find($uuid) ?? [];
    }

    /** @return array<string,mixed> */
    public function restore(string $uuid, string $actor): array
    {
        $project = $this->db->one('SELECT archived FROM projects WHERE uuid = :u', ['u' => $uuid]);
        if ($project === null) {
            throw new ProjectException(404, 'Project not found.');
        }
        if ((int) $project['archived'] === 0) {
            throw new ProjectException(409, 'This project is not archived.');
        }
        $this->db->run("UPDATE projects SET archived = 0, archived_at = NULL, updated_at = :now, revision = revision + 1 WHERE uuid = :u", ['now' => Clock::nowIso(), 'u' => $uuid]);
        $this->event($this->db, $uuid, 'restored', 'Project restored', $actor);

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
        if ($this->db->one('SELECT uuid FROM projects WHERE uuid = :u', ['u' => $uuid]) === null) {
            throw new ProjectException(404, 'Project not found.');
        }

        return $this->db->transaction(function (Database $db) use ($uuid, $members, $actor): array {
            $db->run('DELETE FROM project_members WHERE project_uuid = :u', ['u' => $uuid]);
            foreach ($members as $m) {
                $email = is_array($m) ? (string) ($m['user_email'] ?? '') : (string) $m;
                if ($email !== '') {
                    $this->addMember($db, $uuid, $email, is_array($m) ? (string) ($m['role'] ?? 'member') : 'member');
                }
            }
            $this->event($db, $uuid, 'members_changed', 'Team updated', $actor);

            return $this->find($uuid) ?? [];
        });
    }

    /** Record an immutable project event (public wrapper for other services). */
    public function logEvent(string $uuid, string $type, string $detail, string $actor): void
    {
        $this->event($this->db, $uuid, $type, $detail, $actor);
    }

    // ==================================================================
    // Internals
    // ==================================================================

    private function allocateNumber(Database $db): string
    {
        $year = (int) date('Y');
        $db->run(
            'INSERT INTO project_sequences (prefix, year, next_seq) VALUES (\'PRJ\', :y, 1)
             ON CONFLICT (prefix, year) DO NOTHING',
            ['y' => $year]
        );
        $seq = (int) $db->scalar('SELECT next_seq FROM project_sequences WHERE prefix = \'PRJ\' AND year = :y', ['y' => $year]);
        $db->run('UPDATE project_sequences SET next_seq = next_seq + 1 WHERE prefix = \'PRJ\' AND year = :y', ['y' => $year]);

        return sprintf('PRJ-%d-%04d', $year, $seq);
    }

    private function addMember(Database $db, string $projectUuid, string $email, string $role): void
    {
        $db->run(
            'INSERT INTO project_members (uuid, project_uuid, user_email, role, created_at)
             VALUES (:uuid, :p, :email, :role, :now)
             ON CONFLICT (project_uuid, user_email) DO UPDATE SET role = excluded.role',
            ['uuid' => Uuid::v4(), 'p' => $projectUuid, 'email' => $email, 'role' => in_array($role, ['lead', 'member', 'reviewer'], true) ? $role : 'member', 'now' => Clock::nowIso()]
        );
    }

    private function event(Database $db, string $uuid, string $type, string $detail, string $actor): void
    {
        $db->run(
            'INSERT INTO project_events (uuid, project_uuid, type, detail, actor, created_at)
             VALUES (:uuid, :p, :type, :detail, :actor, :now)',
            ['uuid' => Uuid::v4(), 'p' => $uuid, 'type' => $type, 'detail' => $detail, 'actor' => $actor, 'now' => Clock::nowIso()]
        );
    }

    /**
     * Add derived financial roll-up (invoiced/paid from linked invoices) and
     * decode JSON columns, without persisting them.
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
        $r['tags']           = $this->decodeTags($r['tags'] ?? '[]');

        // Real progress, derived from delivery tasks/milestones — never a typed
        // vanity percentage. Progress = completed tasks / total tasks.
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
        $r['awaiting_seconds_live'] = (int) $r['awaiting_seconds'] + $open;

        return $r;
    }

    /**
     * @return list<string>
     */
    private function decodeTags(mixed $raw): array
    {
        $decoded = json_decode((string) $raw, true);

        return is_array($decoded) ? array_values(array_map(static fn ($t): string => (string) $t, $decoded)) : [];
    }

    private function nullable(mixed $value): ?string
    {
        $v = trim((string) ($value ?? ''));

        return $v === '' ? null : $v;
    }
}
