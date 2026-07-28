<?php

declare(strict_types=1);

namespace Breakfast\Platform\ChangeRequests;

use Breakfast\Platform\Support\Clock;
use Breakfast\Platform\Support\FileStore;
use Breakfast\Platform\Support\Platform;
use Breakfast\Platform\Support\Uuid;

/**
 * Change requests + scope control, stored as flat files.
 *
 * A formal, priced, versioned scope change. Draft is editable; SENDING freezes
 * an immutable snapshot (scope + pricing + timeline + linked baseline), mints a
 * client token and generates a real PDF. Client approval is explicit and
 * evidenced (viewing only records 'viewed'). Applying an approved change is a
 * controlled, idempotent action: it adds the approved value to the project,
 * generates tasks (deterministic keys), can adjust the target date and creates a
 * DRAFT invoice whose total matches the approved version — and it NEVER mutates
 * the original proposal or signed contract. Money is integer pence.
 *
 * Each change request is one JSON record; its items, events, acceptances,
 * versions and task links live embedded in that record as native arrays.
 */
final class ChangeRequests
{
    private const COLLECTION = 'change_requests';

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
        $rows = $this->store()->all(self::COLLECTION);
        if (!empty($filters['project_uuid'])) {
            $rows = array_filter($rows, static fn (array $r): bool => (string) ($r['project_uuid'] ?? '') === (string) $filters['project_uuid']);
        }
        if (!empty($filters['status'])) {
            $rows = array_filter($rows, static fn (array $r): bool => (string) ($r['status'] ?? '') === (string) $filters['status']);
        }
        $rows = array_values($rows);
        usort($rows, static fn ($a, $b) => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));

        return array_map(fn (array $r): array => $this->shape($r), array_slice($rows, 0, (int) ($filters['limit'] ?? 200)));
    }

    /** @return array<string,mixed>|null */
    public function find(string $uuid): ?array
    {
        $cr = $this->store()->find(self::COLLECTION, $uuid);

        return $cr === null ? null : $this->shape($cr);
    }

    /** @return array<string,mixed>|null */
    public function findByToken(string $token): ?array
    {
        if (trim($token) === '') {
            return null;
        }
        foreach ($this->store()->all(self::COLLECTION) as $cr) {
            if ((string) ($cr['public_token'] ?? '') === $token) {
                return $this->shape($cr);
            }
        }

        return null;
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
        $project = $this->platform->projects()->raw($projectUuid);
        if ($project === null) {
            throw new ChangeRequestException(404, 'Project not found.');
        }
        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            throw new ChangeRequestException(422, 'Enter a title.');
        }
        $uuid = Uuid::v4();
        $now = Clock::nowIso();
        $this->store()->put(self::COLLECTION, [
            'uuid'               => $uuid,
            'number'             => '',
            'title'              => $title,
            'project_uuid'       => $projectUuid,
            'company_uuid'       => $this->nullable($project['company_uuid'] ?? null),
            'contact_uuid'       => $this->nullable($project['contact_uuid'] ?? null),
            'requester'          => (string) ($data['requester'] ?? $actor),
            'source'             => (string) ($data['source'] ?? 'staff'),
            'linked_feedback'    => $this->nullable($data['linked_feedback'] ?? null),
            'linked_preview'     => $this->nullable($data['linked_preview'] ?? null),
            'description'        => (string) ($data['description'] ?? ''),
            'reason'             => (string) ($data['reason'] ?? ''),
            'scope_impact'       => (string) ($data['scope_impact'] ?? ''),
            'exclusions'         => (string) ($data['exclusions'] ?? ''),
            'assumptions'        => (string) ($data['assumptions'] ?? ''),
            'time_impact'        => (string) ($data['time_impact'] ?? ''),
            'target_date_impact' => $this->nullable($data['target_date_impact'] ?? null),
            'internal_notes'     => (string) ($data['internal_notes'] ?? ''),
            'client_notes'       => (string) ($data['client_notes'] ?? ''),
            'currency'           => (string) ($project['currency'] ?? 'GBP'),
            'discount_bps'       => (int) round(((float) ($data['discount'] ?? 0)) * 100),
            'tax_bps'            => (int) round(((float) ($data['tax_rate'] ?? 0)) * 100),
            'expiry_date'        => $this->nullable($data['expiry_date'] ?? null),
            'owner'              => (string) ($data['owner'] ?? $actor),
            'status'             => 'draft',
            'subtotal'           => 0,
            'tax_total'          => 0,
            'total'              => 0,
            'snapshot'           => null,
            'public_token'       => '',
            'document_key'       => '',
            'document_sha256'    => '',
            'document_status'    => '',
            'applied'            => 0,
            'revision'           => 0,
            'sent_at'            => null,
            'decided_at'         => null,
            'items'              => [],
            'events'             => [],
            'acceptances'        => [],
            'versions'           => [],
            'task_links'         => [],
            'created_by'         => $actor,
            'created_at'         => $now,
            'updated_at'         => $now,
        ]);
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
        $cr = $this->store()->find(self::COLLECTION, $uuid);
        if ($cr === null) {
            throw new ChangeRequestException(404, 'Change request not found.');
        }
        if (!in_array((string) $cr['status'], ['draft', 'internal_review'], true)) {
            throw new ChangeRequestException(409, 'Only a draft change request can be edited.');
        }
        if ($expectedRevision !== null && (int) $cr['revision'] !== $expectedRevision) {
            throw new ChangeRequestException(409, 'This change request was changed by someone else. Reload and try again.');
        }
        $this->store()->update(self::COLLECTION, $uuid, function (array $row) use ($data): array {
            foreach (['title', 'description', 'reason', 'scope_impact', 'exclusions', 'assumptions', 'time_impact', 'internal_notes', 'client_notes', 'owner'] as $f) {
                if (array_key_exists($f, $data)) {
                    $row[$f] = (string) $data[$f];
                }
            }
            if (array_key_exists('target_date_impact', $data)) {
                $row['target_date_impact'] = $this->nullable($data['target_date_impact']);
            }
            if (array_key_exists('discount', $data)) {
                $row['discount_bps'] = (int) round(((float) $data['discount']) * 100);
            }
            if (array_key_exists('tax_rate', $data)) {
                $row['tax_bps'] = (int) round(((float) $data['tax_rate']) * 100);
            }
            if (array_key_exists('expiry_date', $data)) {
                $row['expiry_date'] = $this->nullable($data['expiry_date']);
            }
            $row['revision']   = (int) ($row['revision'] ?? 0) + 1;
            $row['updated_at'] = Clock::nowIso();

            return $row;
        });
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
        $cr = $this->store()->find(self::COLLECTION, $uuid);
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
        $this->store()->update(self::COLLECTION, $uuid, static function (array $row) use ($to): array {
            $row['status']     = $to;
            $row['revision']   = (int) ($row['revision'] ?? 0) + 1;
            $row['updated_at'] = Clock::nowIso();

            return $row;
        });
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
            $docKey = $this->storeDocument($uuid, $bytes);
            $status = 'generated';
        } catch (ChangeRequestException) {
            // Stays sendable; document_status = failed.
        }
        $now = Clock::nowIso();
        $this->store()->update(self::COLLECTION, $uuid, static function (array $row) use ($number, $token, $snapshot, $docKey, $sha, $status, $now): array {
            $row['status']          = 'sent';
            $row['number']          = $number;
            $row['public_token']    = $token;
            $row['snapshot']        = $snapshot;
            $row['document_key']    = $docKey;
            $row['document_sha256'] = $sha;
            $row['document_status'] = $status;
            $row['sent_at']         = $now;
            $row['updated_at']      = $now;
            $row['revision']        = (int) ($row['revision'] ?? 0) + 1;
            $row['versions'][]      = [
                'uuid'            => Uuid::v4(),
                'version'         => count($row['versions'] ?? []) + 1,
                'snapshot'        => $snapshot,
                'document_sha256' => $sha,
                'created_at'      => $now,
            ];

            return $row;
        });
        $this->event($uuid, 'sent', 'Sent as ' . $number, $actor);
        $contact = (string) ($cr['contact_uuid'] ?? '');
        if ($contact !== '') {
            $this->platform->activities()->record('contact', $contact, 'change_request.sent', 'Change request ' . $number . ' sent', 'user', $actor, ['change_request' => $uuid]);
        }
        $this->platform->audit()->event('change_request.sent', 'project', (string) $cr['project_uuid'], $actor, ['change_request' => $uuid, 'number' => $number]);

        $url = rtrim($siteUrl, '/') . '/change/' . $token;

        return ['change_request' => $this->find($uuid) ?? [], 'url' => $url];
    }

    public function markViewed(string $uuid): void
    {
        $this->store()->update(self::COLLECTION, $uuid, static function (array $row): array {
            if ((string) ($row['status'] ?? '') === 'sent') {
                $row['status'] = 'viewed';
            }
            $row['updated_at'] = Clock::nowIso();

            return $row;
        });
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
        $cr = $this->store()->find(self::COLLECTION, $uuid);
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
        $this->store()->update(self::COLLECTION, $uuid, static function (array $row) use ($decision, $evidence, $now): array {
            $row['acceptances'][] = [
                'uuid'           => Uuid::v4(),
                'decision'       => $decision,
                'name'           => (string) ($evidence['name'] ?? ''),
                'email'          => (string) ($evidence['email'] ?? ''),
                'wording'        => (string) ($evidence['wording'] ?? 'I confirm this change and its price.'),
                'document_hash'  => (string) ($row['document_sha256'] ?? ''),
                'approved_total' => (int) ($row['total'] ?? 0),
                'ip_hash'        => (string) ($evidence['ip_hash'] ?? ''),
                'user_agent'     => (string) ($evidence['user_agent'] ?? ''),
                'token_id'       => (string) ($evidence['token_id'] ?? ''),
                'trace_id'       => Uuid::v4(),
                'comment'        => (string) ($evidence['comment'] ?? ''),
                'created_at'     => $now,
            ];
            $row['status']     = $decision;
            $row['decided_at'] = $now;
            $row['updated_at'] = $now;
            $row['revision']   = (int) ($row['revision'] ?? 0) + 1;

            return $row;
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

        $tasks = 0;
        // Add the approved value to the project (NOT the proposal/contract).
        $targetImpact = (!empty($options['update_target']) && !empty($cr['target_date_impact'])) ? (string) $cr['target_date_impact'] : null;
        $this->platform->projects()->applyChangeVariation($projectUuid, (int) $cr['total'], $targetImpact);
        // Existing task links (deterministic source_ref) for idempotency.
        $linked = [];
        foreach ($cr['task_links'] as $link) {
            $linked[(string) ($link['source_ref'] ?? '')] = true;
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
                if (isset($linked[$sourceRef])) {
                    continue;
                }
                $task = $this->platform->projectTasks()->create($projectUuid, [
                    'title' => (string) $item['description'], 'estimate_seconds' => (int) $item['estimate_seconds'],
                    'source' => 'change_request', 'source_ref' => $sourceRef,
                ], $actor);
                $this->store()->update(self::COLLECTION, $uuid, static function (array $row) use ($task, $sourceRef): array {
                    foreach ($row['task_links'] ?? [] as $existing) {
                        if ((string) ($existing['source_ref'] ?? '') === $sourceRef) {
                            return $row; // already linked (concurrent apply)
                        }
                    }
                    $row['task_links'][] = ['uuid' => Uuid::v4(), 'task_uuid' => (string) $task['uuid'], 'source_ref' => $sourceRef, 'created_at' => Clock::nowIso()];

                    return $row;
                });
                $linked[$sourceRef] = true;
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
            $this->platform->invoices()->assignToProject($invoiceUuid, $projectUuid);
        }
        $this->store()->update(self::COLLECTION, $uuid, static function (array $row): array {
            $row['applied']    = 1;
            $row['status']     = 'in_progress';
            $row['updated_at'] = Clock::nowIso();
            $row['revision']   = (int) ($row['revision'] ?? 0) + 1;

            return $row;
        });
        $this->event($uuid, 'applied', $tasks . ' task(s), invoice ' . ($invoiceUuid ? 'drafted' : 'skipped'), $actor);
        $this->platform->projects()->logEvent($projectUuid, 'change_applied', 'Applied change request ' . (string) $cr['number'] . ' (+' . $this->money((int) $cr['total']) . ')', $actor);
        $this->platform->audit()->event('change_request.applied', 'project', $projectUuid, $actor, ['change_request' => $uuid, 'value' => (int) $cr['total'], 'tasks' => $tasks, 'invoice' => $invoiceUuid]);

        return ['applied' => true, 'tasks' => $tasks, 'invoice' => $invoiceUuid];
    }

    /**
     * Read the stored (immutable) PDF with an integrity check.
     *
     * @return array{filename:string,bytes:string}
     */
    public function download(string $uuid): array
    {
        $cr = $this->store()->find(self::COLLECTION, $uuid);
        if ($cr === null || (string) ($cr['document_key'] ?? '') === '') {
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

    /**
     * Return a change request with its embedded collections normalised to the
     * shape callers expect (items in position order, events + acceptances newest
     * first).
     *
     * @param array<string,mixed> $cr
     * @return array<string,mixed>
     */
    private function shape(array $cr): array
    {
        $items = is_array($cr['items'] ?? null) ? array_values($cr['items']) : [];
        usort($items, static fn ($a, $b) => (int) ($a['position'] ?? 0) <=> (int) ($b['position'] ?? 0));
        $cr['items'] = $items;

        $events = is_array($cr['events'] ?? null) ? $cr['events'] : [];
        usort($events, static fn ($a, $b) => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));
        $cr['events'] = array_slice(array_map(static fn (array $e): array => [
            'type' => $e['type'] ?? '', 'detail' => $e['detail'] ?? '', 'actor' => $e['actor'] ?? '', 'created_at' => $e['created_at'] ?? '',
        ], $events), 0, 100);

        $acceptances = is_array($cr['acceptances'] ?? null) ? $cr['acceptances'] : [];
        usort($acceptances, static fn ($a, $b) => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));
        $cr['acceptances'] = array_map(static fn (array $a): array => [
            'decision' => $a['decision'] ?? '', 'name' => $a['name'] ?? '', 'email' => $a['email'] ?? '', 'wording' => $a['wording'] ?? '',
            'approved_total' => (int) ($a['approved_total'] ?? 0), 'document_hash' => $a['document_hash'] ?? '', 'created_at' => $a['created_at'] ?? '',
        ], $acceptances);

        return $cr;
    }

    private function allocateNumber(): string
    {
        $year = (int) date('Y');
        $seq = $this->store()->bump('change_request_sequences', 'CR-' . $year);

        return sprintf('CR-%d-%04d', $year, $seq);
    }

    /** @param array<int,mixed> $items */
    private function replaceItems(string $uuid, array $items): void
    {
        $built = [];
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
            $built[] = [
                'uuid' => Uuid::v4(), 'position' => $pos++,
                'kind' => in_array((string) ($item['kind'] ?? 'fixed'), ['fixed', 'optional'], true) ? (string) ($item['kind'] ?? 'fixed') : 'fixed',
                'description' => $desc, 'quantity' => $qty, 'unit_price' => $unit, 'line_total' => $lineTotal,
                'estimate_seconds' => (int) round(((float) ($item['estimate_hours'] ?? 0)) * 3600), 'milestone_hint' => (string) ($item['milestone_hint'] ?? ''),
            ];
        }
        $this->store()->update(self::COLLECTION, $uuid, static function (array $row) use ($built): array {
            $row['items'] = $built;

            return $row;
        });
    }

    private function recompute(string $uuid): void
    {
        $this->store()->update(self::COLLECTION, $uuid, static function (array $row): array {
            // Only fixed items count toward the committed total (optional = opt-in later).
            $gross = 0;
            foreach ($row['items'] ?? [] as $item) {
                if ((string) ($item['kind'] ?? 'fixed') === 'fixed') {
                    $gross += (int) ($item['line_total'] ?? 0);
                }
            }
            $discount = (int) round($gross * (int) ($row['discount_bps'] ?? 0) / 10000);
            $subtotal = $gross - $discount;
            $tax = (int) round($subtotal * (int) ($row['tax_bps'] ?? 0) / 10000);
            $row['subtotal']   = $subtotal;
            $row['tax_total']  = $tax;
            $row['total']      = $subtotal + $tax;
            $row['updated_at'] = Clock::nowIso();

            return $row;
        });
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

    private function storeDocument(string $uuid, string $bytes): string
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
        $this->store()->update(self::COLLECTION, $uuid, static function (array $row) use ($type, $detail, $actor): array {
            $row['events'][] = ['uuid' => Uuid::v4(), 'type' => $type, 'detail' => $detail, 'actor' => $actor, 'created_at' => Clock::nowIso()];

            return $row;
        });
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
