<?php

declare(strict_types=1);

namespace Breakfast\Platform\ChangeRequests;

use Breakfast\Platform\Support\Clock;
use Breakfast\Platform\Support\Platform;
use Breakfast\Platform\Support\Uuid;

/**
 * Change requests + scope control.
 *
 * A formal, priced, versioned scope change. Draft is editable; SENDING freezes
 * an immutable snapshot (scope + pricing + timeline + linked baseline), mints a
 * client token and generates a real PDF. Client approval is explicit and
 * evidenced (viewing only records 'viewed'). Applying an approved change is a
 * controlled, idempotent action: it adds the approved value to the project,
 * generates tasks (deterministic keys), can adjust the target date and creates a
 * DRAFT invoice whose total matches the approved version — and it NEVER mutates
 * the original proposal or signed contract. Money is integer pence.
 */
final class ChangeRequests
{
    /** @var list<string> */
    public const STATUSES = ['draft', 'internal_review', 'ready', 'sent', 'viewed', 'approved', 'rejected', 'expired', 'withdrawn', 'superseded', 'cancelled', 'in_progress', 'completed'];

    /** @var array<string,list<string>> */
    private const TRANSITIONS = [
        'draft'           => ['internal_review', 'ready', 'cancelled'],
        'internal_review' => ['draft', 'ready', 'cancelled'],
        'ready'           => ['draft', 'sent', 'cancelled'],
        'sent'            => ['viewed', 'approved', 'rejected', 'withdrawn', 'expired', 'superseded'],
        'viewed'          => ['approved', 'rejected', 'withdrawn', 'expired', 'superseded'],
        'approved'        => ['in_progress', 'completed'],
        'in_progress'     => ['completed'],
        'rejected'        => ['superseded'],
    ];

    public function __construct(
        private readonly Platform $platform,
        private readonly string $storageDir,
    ) {
    }

    private function db(): \Breakfast\Platform\Support\Database
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
        if (!empty($filters['project_uuid'])) {
            $where[] = 'project_uuid = :p';
            $params['p'] = (string) $filters['project_uuid'];
        }
        if (!empty($filters['status'])) {
            $where[] = 'status = :s';
            $params['s'] = (string) $filters['status'];
        }
        $params['l'] = (int) ($filters['limit'] ?? 200);

        return $this->db()->all('SELECT * FROM change_requests WHERE ' . implode(' AND ', $where) . ' ORDER BY created_at DESC LIMIT :l', $params);
    }

    /** @return array<string,mixed>|null */
    public function find(string $uuid): ?array
    {
        $cr = $this->db()->one('SELECT * FROM change_requests WHERE uuid = :u', ['u' => $uuid]);
        if ($cr === null) {
            return null;
        }
        $cr['items'] = $this->db()->all('SELECT * FROM change_request_items WHERE change_request_uuid = :u ORDER BY position ASC', ['u' => $uuid]);
        $cr['events'] = $this->db()->all('SELECT type, detail, actor, created_at FROM change_request_events WHERE change_request_uuid = :u ORDER BY created_at DESC LIMIT 100', ['u' => $uuid]);
        $cr['acceptances'] = $this->db()->all('SELECT decision, name, email, wording, approved_total, document_hash, created_at FROM change_request_acceptances WHERE change_request_uuid = :u ORDER BY created_at DESC', ['u' => $uuid]);

        return $cr;
    }

    /** @return array<string,mixed>|null */
    public function findByToken(string $token): ?array
    {
        if (trim($token) === '') {
            return null;
        }
        $cr = $this->db()->one('SELECT uuid FROM change_requests WHERE public_token = :t', ['t' => $token]);

        return $cr === null ? null : $this->find((string) $cr['uuid']);
    }

    // ==================================================================
    // Create / edit draft
    // ==================================================================

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function create(string $projectUuid, array $data, string $actor): array
    {
        $project = $this->db()->one('SELECT * FROM projects WHERE uuid = :u', ['u' => $projectUuid]);
        if ($project === null) {
            throw new ChangeRequestException(404, 'Project not found.');
        }
        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            throw new ChangeRequestException(422, 'Enter a title.');
        }
        $uuid = Uuid::v4();
        $now = Clock::nowIso();
        $this->db()->run(
            'INSERT INTO change_requests (uuid, title, project_uuid, company_uuid, contact_uuid, requester, source, linked_feedback, linked_preview, description, reason, scope_impact, time_impact, target_date_impact, currency, discount_bps, tax_bps, owner, status, created_by, created_at, updated_at)
             VALUES (:uuid, :title, :project, :company, :contact, :requester, :source, :feedback, :preview, :desc, :reason, :scope, :time, :target, :currency, :discount, :tax, :owner, \'draft\', :actor, :now, :now)',
            [
                'uuid' => $uuid, 'title' => $title, 'project' => $projectUuid,
                'company' => $this->nullable($project['company_uuid'] ?? null), 'contact' => $this->nullable($project['contact_uuid'] ?? null),
                'requester' => (string) ($data['requester'] ?? $actor), 'source' => (string) ($data['source'] ?? 'staff'),
                'feedback' => $this->nullable($data['linked_feedback'] ?? null), 'preview' => $this->nullable($data['linked_preview'] ?? null),
                'desc' => (string) ($data['description'] ?? ''), 'reason' => (string) ($data['reason'] ?? ''), 'scope' => (string) ($data['scope_impact'] ?? ''),
                'time' => (string) ($data['time_impact'] ?? ''), 'target' => $this->nullable($data['target_date_impact'] ?? null), 'currency' => (string) ($project['currency'] ?? 'GBP'),
                'discount' => (int) round(((float) ($data['discount'] ?? 0)) * 100), 'tax' => (int) round(((float) ($data['tax_rate'] ?? 0)) * 100),
                'owner' => (string) ($data['owner'] ?? $actor), 'actor' => $actor, 'now' => $now,
            ]
        );
        $this->replaceItems($uuid, is_array($data['items'] ?? null) ? $data['items'] : []);
        $this->recompute($uuid);
        $this->event($uuid, 'created', 'Change request created', $actor);

        return $this->find($uuid) ?? [];
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function update(string $uuid, array $data, string $actor, ?int $expectedRevision = null): array
    {
        $cr = $this->db()->one('SELECT status, revision FROM change_requests WHERE uuid = :u', ['u' => $uuid]);
        if ($cr === null) {
            throw new ChangeRequestException(404, 'Change request not found.');
        }
        if (!in_array((string) $cr['status'], ['draft', 'internal_review'], true)) {
            throw new ChangeRequestException(409, 'Only a draft change request can be edited.');
        }
        if ($expectedRevision !== null && (int) $cr['revision'] !== $expectedRevision) {
            throw new ChangeRequestException(409, 'This change request was changed by someone else. Reload and try again.');
        }
        $sets = ['updated_at = :now', 'revision = revision + 1'];
        $params = ['u' => $uuid, 'now' => Clock::nowIso()];
        foreach (['title', 'description', 'reason', 'scope_impact', 'exclusions', 'assumptions', 'time_impact', 'internal_notes', 'client_notes', 'owner'] as $f) {
            if (array_key_exists($f, $data)) {
                $sets[] = "$f = :$f";
                $params[$f] = (string) $data[$f];
            }
        }
        if (array_key_exists('target_date_impact', $data)) {
            $sets[] = 'target_date_impact = :target';
            $params['target'] = $this->nullable($data['target_date_impact']);
        }
        if (array_key_exists('discount', $data)) {
            $sets[] = 'discount_bps = :discount';
            $params['discount'] = (int) round(((float) $data['discount']) * 100);
        }
        if (array_key_exists('tax_rate', $data)) {
            $sets[] = 'tax_bps = :tax';
            $params['tax'] = (int) round(((float) $data['tax_rate']) * 100);
        }
        if (array_key_exists('expiry_date', $data)) {
            $sets[] = 'expiry_date = :expiry';
            $params['expiry'] = $this->nullable($data['expiry_date']);
        }
        $this->db()->run('UPDATE change_requests SET ' . implode(', ', $sets) . ' WHERE uuid = :u', $params);
        if (array_key_exists('items', $data) && is_array($data['items'])) {
            $this->replaceItems($uuid, $data['items']);
        }
        $this->recompute($uuid);
        $this->event($uuid, 'updated', 'Draft updated', $actor);

        return $this->find($uuid) ?? [];
    }

    // ==================================================================
    // State machine
    // ==================================================================

    /** @return array<string,mixed> */
    public function transition(string $uuid, string $to, string $actor, string $reason = ''): array
    {
        $cr = $this->db()->one('SELECT status FROM change_requests WHERE uuid = :u', ['u' => $uuid]);
        if ($cr === null) {
            throw new ChangeRequestException(404, 'Change request not found.');
        }
        $from = (string) $cr['status'];
        if (!in_array($to, self::STATUSES, true) || !in_array($to, self::TRANSITIONS[$from] ?? [], true)) {
            throw new ChangeRequestException(409, "Cannot move a change request from {$from} to {$to}.");
        }
        if ($to === 'cancelled' && trim($reason) === '') {
            throw new ChangeRequestException(422, 'A cancellation reason is required.');
        }
        $sets = ['status = :to', 'updated_at = :now', 'revision = revision + 1'];
        $params = ['u' => $uuid, 'to' => $to, 'now' => Clock::nowIso()];
        $this->db()->run('UPDATE change_requests SET ' . implode(', ', $sets) . ' WHERE uuid = :u', $params);
        $this->event($uuid, 'status', $from . ' → ' . $to . ($reason !== '' ? ' (' . $reason . ')' : ''), $actor);

        return $this->find($uuid) ?? [];
    }

    /**
     * Freeze + send: allocate a number, freeze the immutable snapshot, generate
     * the real PDF, mint a client token, record a version.
     *
     * @return array{change_request:array<string,mixed>,url:string}
     */
    public function send(string $uuid, string $siteUrl, string $actor): array
    {
        $cr = $this->find($uuid);
        if ($cr === null) {
            throw new ChangeRequestException(404, 'Change request not found.');
        }
        if (!in_array((string) $cr['status'], ['ready', 'internal_review', 'draft'], true)) {
            throw new ChangeRequestException(409, 'This change request has already been sent.');
        }
        if ($cr['items'] === []) {
            throw new ChangeRequestException(422, 'Add at least one item before sending.');
        }

        return $this->db()->transaction(function () use ($uuid, $cr, $siteUrl, $actor): array {
            $number = $this->allocateNumber();
            $token = bin2hex(random_bytes(20));
            $project = $this->platform->projects()->find((string) $cr['project_uuid']) ?? [];
            $snapshot = $this->buildSnapshot($cr, $number, $project);
            $sha = '';
            $docKey = '';
            $status = 'failed';
            try {
                $bytes = (new ChangeRequestPdfRenderer())->render($snapshot, false);
                $sha = hash('sha256', $bytes);
                $docKey = $this->store($uuid, $bytes);
                $status = 'generated';
            } catch (ChangeRequestException) {
                // Stays sendable; document_status = failed.
            }
            $now = Clock::nowIso();
            $this->db()->run(
                "UPDATE change_requests SET status = 'sent', number = :num, public_token = :tok, snapshot = :snap, document_key = :key, document_sha256 = :sha, document_status = :dstatus, sent_at = :now, updated_at = :now, revision = revision + 1 WHERE uuid = :u",
                ['num' => $number, 'tok' => $token, 'snap' => json_encode($snapshot, JSON_UNESCAPED_SLASHES) ?: '', 'key' => $docKey, 'sha' => $sha, 'dstatus' => $status, 'now' => $now, 'u' => $uuid]
            );
            $this->db()->run('INSERT INTO change_request_versions (uuid, change_request_uuid, version, snapshot, document_sha256, created_at) VALUES (:uuid, :cr, 1, :snap, :sha, :now)', ['uuid' => Uuid::v4(), 'cr' => $uuid, 'snap' => json_encode($snapshot, JSON_UNESCAPED_SLASHES) ?: '', 'sha' => $sha, 'now' => $now]);
            $this->event($uuid, 'sent', 'Sent as ' . $number, $actor);
            $contact = (string) ($cr['contact_uuid'] ?? '');
            if ($contact !== '') {
                $this->platform->activities()->record('contact', $contact, 'change_request.sent', 'Change request ' . $number . ' sent', 'user', $actor, ['change_request' => $uuid]);
            }
            $this->platform->audit()->event('change_request.sent', 'project', (string) $cr['project_uuid'], $actor, ['change_request' => $uuid, 'number' => $number]);

            $url = rtrim($siteUrl, '/') . '/change/' . $token;

            return ['change_request' => $this->find($uuid) ?? [], 'url' => $url];
        });
    }

    public function markViewed(string $uuid): void
    {
        $this->db()->run("UPDATE change_requests SET status = CASE WHEN status = 'sent' THEN 'viewed' ELSE status END, updated_at = :now WHERE uuid = :u", ['now' => Clock::nowIso(), 'u' => $uuid]);
    }

    /**
     * Explicit, evidenced client decision. Idempotent: a second approval on an
     * already-approved request returns the existing state.
     *
     * @param array<string,mixed> $evidence
     * @return array<string,mixed>
     */
    public function decide(string $uuid, string $decision, array $evidence, string $actor = 'client'): array
    {
        if (!in_array($decision, ['approved', 'rejected'], true)) {
            throw new ChangeRequestException(422, 'Unknown decision.');
        }
        $cr = $this->db()->one('SELECT * FROM change_requests WHERE uuid = :u', ['u' => $uuid]);
        if ($cr === null) {
            throw new ChangeRequestException(404, 'Change request not found.');
        }
        if (in_array((string) $cr['status'], ['approved', 'rejected'], true)) {
            return $this->find($uuid) ?? []; // idempotent
        }
        if (!in_array((string) $cr['status'], ['sent', 'viewed'], true)) {
            throw new ChangeRequestException(409, 'This change request is not open for a decision.');
        }
        if ($this->isExpired($cr)) {
            throw new ChangeRequestException(410, 'This change request has expired.');
        }
        if ($decision === 'approved' && trim((string) ($evidence['name'] ?? '')) === '') {
            throw new ChangeRequestException(422, 'A name is required to approve.');
        }
        $now = Clock::nowIso();
        $this->db()->transaction(function () use ($uuid, $cr, $decision, $evidence, $now): void {
            $this->db()->run('INSERT INTO change_request_acceptances (uuid, change_request_uuid, decision, name, email, wording, document_hash, approved_total, ip_hash, user_agent, token_id, trace_id, comment, created_at) VALUES (:uuid, :cr, :decision, :name, :email, :wording, :hash, :total, :ip, :ua, :tok, :trace, :comment, :now)', [
                'uuid' => Uuid::v4(), 'cr' => $uuid, 'decision' => $decision, 'name' => (string) ($evidence['name'] ?? ''), 'email' => (string) ($evidence['email'] ?? ''),
                'wording' => (string) ($evidence['wording'] ?? 'I confirm this change and its price.'), 'hash' => (string) $cr['document_sha256'], 'total' => (int) $cr['total'],
                'ip' => (string) ($evidence['ip_hash'] ?? ''), 'ua' => (string) ($evidence['user_agent'] ?? ''), 'tok' => (string) ($evidence['token_id'] ?? ''), 'trace' => Uuid::v4(), 'comment' => (string) ($evidence['comment'] ?? ''), 'now' => $now,
            ]);
            $this->db()->run('UPDATE change_requests SET status = :s, decided_at = :now, updated_at = :now, revision = revision + 1 WHERE uuid = :u', ['s' => $decision, 'now' => $now, 'u' => $uuid]);
        });
        $this->event($uuid, $decision, ucfirst($decision) . ' by ' . (string) ($evidence['name'] ?? 'client'), $actor);
        $contact = (string) ($cr['contact_uuid'] ?? '');
        if ($contact !== '') {
            $this->platform->activities()->record('contact', $contact, 'change_request.' . $decision, 'Change request ' . (string) $cr['number'] . ' ' . $decision, 'client', null, ['change_request' => $uuid]);
        }
        $this->platform->audit()->event('change_request.' . $decision, 'project', (string) $cr['project_uuid'], $actor, ['change_request' => $uuid]);

        return $this->find($uuid) ?? [];
    }

    // ==================================================================
    // Apply approved change (controlled + idempotent)
    // ==================================================================

    /**
     * @param array<string,mixed> $options add_tasks, update_target, create_invoice
     * @return array{applied:bool,tasks:int,invoice:?string}
     */
    public function apply(string $uuid, array $options, string $actor): array
    {
        $cr = $this->find($uuid);
        if ($cr === null) {
            throw new ChangeRequestException(404, 'Change request not found.');
        }
        if ((string) $cr['status'] !== 'approved') {
            throw new ChangeRequestException(409, 'Only an approved change request can be applied.');
        }
        if ((int) $cr['applied'] === 1) {
            throw new ChangeRequestException(409, 'This change request has already been applied.');
        }
        $projectUuid = (string) $cr['project_uuid'];

        return $this->db()->transaction(function () use ($uuid, $cr, $projectUuid, $options, $actor): array {
            $tasks = 0;
            // Add the approved value to the project (NOT the proposal/contract).
            $this->db()->run('UPDATE projects SET approved_variations = approved_variations + :v, updated_at = :now, revision = revision + 1 WHERE uuid = :u', ['v' => (int) $cr['total'], 'now' => Clock::nowIso(), 'u' => $projectUuid]);
            if (!empty($options['update_target']) && !empty($cr['target_date_impact'])) {
                $this->db()->run('UPDATE projects SET target_date = :t, updated_at = :now WHERE uuid = :u', ['t' => (string) $cr['target_date_impact'], 'now' => Clock::nowIso(), 'u' => $projectUuid]);
            }
            // Generate tasks idempotently (deterministic source_ref).
            if (($options['add_tasks'] ?? true)) {
                foreach ($cr['items'] as $index => $item) {
                    // Only committed (fixed) items become delivery tasks; optional
                    // lines are opt-in and excluded from both the total and the plan.
                    if ((string) ($item['kind'] ?? 'fixed') !== 'fixed') {
                        continue;
                    }
                    $sourceRef = 'change_request:' . $uuid . ':' . $index;
                    if ($this->db()->one('SELECT uuid FROM change_request_task_links WHERE source_ref = :r', ['r' => $sourceRef]) !== null) {
                        continue;
                    }
                    $task = $this->platform->projectTasks()->create($projectUuid, [
                        'title' => (string) $item['description'], 'estimate_seconds' => (int) $item['estimate_seconds'],
                        'source' => 'change_request', 'source_ref' => $sourceRef,
                    ], $actor);
                    $this->db()->run('INSERT INTO change_request_task_links (uuid, change_request_uuid, task_uuid, source_ref, created_at) VALUES (:uuid, :cr, :task, :ref, :now) ON CONFLICT (source_ref) DO NOTHING', ['uuid' => Uuid::v4(), 'cr' => $uuid, 'task' => (string) $task['uuid'], 'ref' => $sourceRef, 'now' => Clock::nowIso()]);
                    $tasks++;
                }
            }
            // Create a DRAFT invoice whose total matches the approved version
            // exactly. The change-request total is server-computed from fixed
            // items with a single CR-level discount + tax rounding, so the
            // invoice carries one consolidated line at that approved total —
            // no per-item re-rounding can drift it away from what was signed.
            $invoiceUuid = null;
            if (($options['create_invoice'] ?? true) && (int) $cr['total'] > 0) {
                $projectName = (string) ($this->platform->projects()->find($projectUuid)['name'] ?? 'Client');
                $inv = $this->platform->invoices()->create([
                    'bill_to_name' => $projectName,
                    'contact_uuid' => $this->nullable($cr['contact_uuid'] ?? null), 'company_uuid' => $this->nullable($cr['company_uuid'] ?? null),
                    'project' => 'Change request ' . (string) $cr['number'],
                    'items' => [[
                        'description' => (string) $cr['number'] . ' — ' . (string) $cr['title'],
                        'quantity' => 1, 'unit_price' => (int) $cr['total'] / 100,
                    ]],
                ], $actor);
                $invoiceUuid = (string) ($inv['uuid'] ?? '');
                $this->db()->run('UPDATE invoices SET project_uuid = :p WHERE uuid = :u', ['p' => $projectUuid, 'u' => $invoiceUuid]);
            }
            $this->db()->run("UPDATE change_requests SET applied = 1, status = 'in_progress', updated_at = :now, revision = revision + 1 WHERE uuid = :u", ['now' => Clock::nowIso(), 'u' => $uuid]);
            $this->event($uuid, 'applied', $tasks . ' task(s), invoice ' . ($invoiceUuid ? 'drafted' : 'skipped'), $actor);
            $this->platform->projects()->logEvent($projectUuid, 'change_applied', 'Applied change request ' . (string) $cr['number'] . ' (+' . $this->money((int) $cr['total']) . ')', $actor);
            $this->platform->audit()->event('change_request.applied', 'project', $projectUuid, $actor, ['change_request' => $uuid, 'value' => (int) $cr['total'], 'tasks' => $tasks, 'invoice' => $invoiceUuid]);

            return ['applied' => true, 'tasks' => $tasks, 'invoice' => $invoiceUuid];
        });
    }

    /**
     * Read the stored (immutable) PDF with an integrity check.
     *
     * @return array{filename:string,bytes:string}
     */
    public function download(string $uuid): array
    {
        $cr = $this->db()->one('SELECT number, document_key, document_sha256 FROM change_requests WHERE uuid = :u', ['u' => $uuid]);
        if ($cr === null || (string) $cr['document_key'] === '') {
            throw new ChangeRequestException(404, 'No document available.');
        }
        $base = realpath($this->storageDir);
        $real = realpath($this->storageDir . '/' . (string) $cr['document_key']);
        if ($base === false || $real === false || strncmp($real, $base . DIRECTORY_SEPARATOR, strlen($base) + 1) !== 0) {
            throw new ChangeRequestException(404, 'Document not found.');
        }
        $bytes = file_get_contents($real);
        if ($bytes === false || hash('sha256', $bytes) !== (string) $cr['document_sha256']) {
            throw new ChangeRequestException(409, 'The stored document failed its integrity check.');
        }

        return ['filename' => 'CR-' . preg_replace('/[^A-Za-z0-9\-]/', '', (string) $cr['number']) . '.pdf', 'bytes' => $bytes];
    }

    // ==================================================================
    // Internals
    // ==================================================================

    private function allocateNumber(): string
    {
        $year = (int) date('Y');
        $this->db()->run("INSERT INTO change_request_sequences (prefix, year, next_seq) VALUES ('CR', :y, 2) ON CONFLICT(prefix, year) DO UPDATE SET next_seq = next_seq + 1", ['y' => $year]);
        $seq = (int) $this->db()->scalar("SELECT next_seq - 1 FROM change_request_sequences WHERE prefix = 'CR' AND year = :y", ['y' => $year]);

        return sprintf('CR-%d-%04d', $year, $seq);
    }

    /** @param array<int,mixed> $items */
    private function replaceItems(string $uuid, array $items): void
    {
        $this->db()->run('DELETE FROM change_request_items WHERE change_request_uuid = :u', ['u' => $uuid]);
        $pos = 0;
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $qty = (int) round(((float) ($item['quantity'] ?? 1)) * 1000);
            $unit = (int) round(((float) ($item['unit_price'] ?? 0)) * 100);
            $desc = trim((string) ($item['description'] ?? ''));
            if ($desc === '' && $unit === 0) {
                continue;
            }
            $lineTotal = (int) round($qty / 1000 * $unit);
            $this->db()->run('INSERT INTO change_request_items (uuid, change_request_uuid, position, kind, description, quantity, unit_price, line_total, estimate_seconds, milestone_hint) VALUES (:uuid, :cr, :pos, :kind, :desc, :qty, :unit, :total, :est, :hint)', [
                'uuid' => Uuid::v4(), 'cr' => $uuid, 'pos' => $pos++, 'kind' => in_array((string) ($item['kind'] ?? 'fixed'), ['fixed', 'optional'], true) ? (string) ($item['kind'] ?? 'fixed') : 'fixed',
                'desc' => $desc, 'qty' => $qty, 'unit' => $unit, 'total' => $lineTotal, 'est' => (int) round(((float) ($item['estimate_hours'] ?? 0)) * 3600), 'hint' => (string) ($item['milestone_hint'] ?? ''),
            ]);
        }
    }

    private function recompute(string $uuid): void
    {
        $cr = $this->db()->one('SELECT discount_bps, tax_bps FROM change_requests WHERE uuid = :u', ['u' => $uuid]);
        // Only fixed items count toward the committed total (optional = opt-in later).
        $gross = (int) $this->db()->scalar("SELECT COALESCE(SUM(line_total),0) FROM change_request_items WHERE change_request_uuid = :u AND kind = 'fixed'", ['u' => $uuid]);
        $discount = (int) round($gross * (int) ($cr['discount_bps'] ?? 0) / 10000);
        $subtotal = $gross - $discount;
        $tax = (int) round($subtotal * (int) ($cr['tax_bps'] ?? 0) / 10000);
        $this->db()->run('UPDATE change_requests SET subtotal = :sub, tax_total = :tax, total = :total, updated_at = :now WHERE uuid = :u', ['sub' => $subtotal, 'tax' => $tax, 'total' => $subtotal + $tax, 'now' => Clock::nowIso(), 'u' => $uuid]);
    }

    /**
     * @param array<string,mixed> $cr
     * @param array<string,mixed> $project
     * @return array<string,mixed>
     */
    private function buildSnapshot(array $cr, string $number, array $project): array
    {
        return [
            'number' => $number, 'title' => (string) $cr['title'], 'currency' => (string) $cr['currency'],
            'project_name' => (string) ($project['name'] ?? ''), 'client_name' => (string) ($project['name'] ?? ''),
            'seller_name' => (string) ($this->platform->invoices()->settings()['company_legal_name'] ?? 'Breakfast'),
            'description' => (string) $cr['description'], 'scope_impact' => (string) $cr['scope_impact'], 'time_impact' => (string) $cr['time_impact'],
            'target_date_impact' => (string) ($cr['target_date_impact'] ?? ''),
            'items' => array_map(static fn (array $it): array => ['description' => (string) $it['description'], 'kind' => (string) $it['kind'], 'line_total' => (int) $it['line_total']], $cr['items']),
            'subtotal' => (int) $cr['subtotal'], 'tax_total' => (int) $cr['tax_total'], 'total' => (int) $cr['total'],
        ];
    }

    private function store(string $uuid, string $bytes): string
    {
        $dir = $this->storageDir . '/' . $uuid;
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new ChangeRequestException(500, 'Could not store the document.');
        }
        $key = $uuid . '/' . bin2hex(random_bytes(10)) . '.pdf';
        if (file_put_contents($this->storageDir . '/' . $key, $bytes) === false) {
            throw new ChangeRequestException(500, 'Could not store the document.');
        }
        @chmod($this->storageDir . '/' . $key, 0600);

        return $key;
    }

    /** @param array<string,mixed> $cr */
    private function isExpired(array $cr): bool
    {
        $exp = (string) ($cr['expiry_date'] ?? '');

        return $exp !== '' && strtotime($exp) !== false && strtotime($exp) < time();
    }

    private function event(string $uuid, string $type, string $detail, string $actor): void
    {
        $this->db()->run('INSERT INTO change_request_events (uuid, change_request_uuid, type, detail, actor, created_at) VALUES (:uuid, :cr, :type, :detail, :actor, :now)', ['uuid' => Uuid::v4(), 'cr' => $uuid, 'type' => $type, 'detail' => $detail, 'actor' => $actor, 'now' => Clock::nowIso()]);
    }

    private function money(int $pence): string
    {
        return '£' . number_format($pence / 100, 2);
    }

    private function nullable(mixed $value): ?string
    {
        $v = trim((string) ($value ?? ''));

        return $v === '' ? null : $v;
    }
}
