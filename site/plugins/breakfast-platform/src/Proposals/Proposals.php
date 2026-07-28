<?php

declare(strict_types=1);

namespace Breakfast\Platform\Proposals;

use Breakfast\Platform\Support\Clock;
use Breakfast\Platform\Support\FileStore;
use Breakfast\Platform\Support\Uuid;

/**
 * Proposals & quotes — the commercial entry point of the client lifecycle,
 * stored as flat files.
 *
 * Money is integer pence, quantities thousandths, tax/discount basis points, so
 * totals are exact (never floating point) and a proposal converts cleanly into
 * invoices. A draft is freely editable; SENDING allocates a monotonic number,
 * freezes an immutable snapshot, mints a signed public token and locks editing.
 * Acceptance is a deliberate, evidenced act recorded in proposal_acceptances —
 * a page visit is only ever a "viewed" event, never acceptance. Every state
 * change is an immutable proposal_event.
 */
final class Proposals
{
    /** @var list<string> */
    public const STATUSES = ['draft', 'ready', 'sent', 'viewed', 'accepted', 'rejected', 'expired', 'superseded', 'withdrawn'];

    /** States in which a proposal is still editable by staff. */
    private const EDITABLE = ['draft', 'ready'];

    /** States a client can act on through the public link. */
    private const LIVE = ['sent', 'viewed'];

    /** @var list<string> */
    private const NARRATIVE = [
        'introduction', 'client_problem', 'recommended_solution', 'scope', 'deliverables',
        'exclusions', 'assumptions', 'timeline', 'payment_schedule', 'terms', 'internal_notes',
        'client_name', 'client_email',
    ];

    public function __construct(private readonly FileStore $store)
    {
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
        $rows = $this->store->all('proposals');
        if (!empty($filters['status'])) {
            $rows = array_filter($rows, static fn (array $r): bool => (string) ($r['status'] ?? '') === (string) $filters['status']);
        }
        if (!empty($filters['opportunity_uuid'])) {
            $rows = array_filter($rows, static fn (array $r): bool => (string) ($r['opportunity_uuid'] ?? '') === (string) $filters['opportunity_uuid']);
        }
        $rows = array_values($rows);
        usort($rows, static fn ($a, $b) => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));

        return array_slice($rows, 0, (int) ($filters['limit'] ?? 100));
    }

    /** @return array<string,mixed>|null */
    public function find(string $uuid): ?array
    {
        $p = $this->store->find('proposals', $uuid);
        if ($p === null) {
            return null;
        }

        $items = array_values(array_filter($this->store->all('proposal_items'), static fn (array $r): bool => (string) ($r['proposal_uuid'] ?? '') === $uuid));
        usort($items, static fn ($a, $b) => (int) ($a['position'] ?? 0) <=> (int) ($b['position'] ?? 0));

        $events = array_values(array_filter($this->store->all('proposal_events'), static fn (array $r): bool => (string) ($r['proposal_uuid'] ?? '') === $uuid));
        usort($events, static fn ($a, $b) => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));

        $acceptances = array_values(array_filter($this->store->all('proposal_acceptances'), static fn (array $r): bool => (string) ($r['proposal_uuid'] ?? '') === $uuid));
        usort($acceptances, static fn ($a, $b) => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));

        $p['items']       = $items;
        $p['events']      = $events;
        $p['acceptances'] = $acceptances;

        return $p;
    }

    /** @return array<string,mixed>|null */
    public function findByToken(string $token): ?array
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }
        foreach ($this->store->all('proposals') as $row) {
            if ((string) ($row['public_token'] ?? '') === $token) {
                return $this->find((string) $row['uuid']);
            }
        }

        return null;
    }

    // ==================================================================
    // Create / edit
    // ==================================================================

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function create(array $data, string $actor): array
    {
        $uuid = Uuid::v4();
        $now  = Clock::nowIso();

        $record = [
            'uuid'             => $uuid,
            'number'           => null,
            'status'           => 'draft',
            'title'            => trim((string) ($data['title'] ?? 'New proposal')),
            'contact_uuid'     => $this->nullable($data['contact_uuid'] ?? null),
            'company_uuid'     => $this->nullable($data['company_uuid'] ?? null),
            'opportunity_uuid' => $this->nullable($data['opportunity_uuid'] ?? null),
            'project_uuid'     => $this->nullable($data['project_uuid'] ?? null),
            'currency'         => (string) ($data['currency'] ?? 'GBP'),
            'owner'            => trim((string) ($data['owner'] ?? $actor)),
            'expiry_date'      => $this->nullable($data['expiry_date'] ?? null),
            'public_token'     => null,
            'client_name'      => '',
            'client_email'     => '',
            'seller_name'      => '',
            'vat_registered'   => 0,
            'snapshot'         => null,
            'document_status'  => 'none',
            'document_key'     => '',
            'document_hash'    => '',
            'document_bytes'   => 0,
            'deposit_amount'   => 0,
            'subtotal'         => 0,
            'tax_total'        => 0,
            'total'            => 0,
            'recurring_total'  => 0,
            'supersedes_uuid'  => null,
            'sent_at'          => null,
            'viewed_at'        => null,
            'accepted_at'      => null,
            'rejected_at'      => null,
            'created_by'       => $actor,
            'created_at'       => $now,
            'updated_at'       => $now,
        ];
        foreach (self::NARRATIVE as $f) {
            $record[$f] = '';
        }
        $this->store->put('proposals', $record);

        $this->applyNarrative($uuid, $data);
        if (array_key_exists('deposit_amount', $data)) {
            $deposit = max(0, (int) round(((float) $data['deposit_amount']) * 100));
            $this->store->update('proposals', $uuid, static function (array $row) use ($deposit): array {
                $row['deposit_amount'] = $deposit;

                return $row;
            });
        }
        if (isset($data['items']) && is_array($data['items'])) {
            $this->replaceItems($uuid, $data['items']);
        }
        $this->recompute($uuid);
        $this->event($uuid, 'created', 'Proposal created', $actor);

        return $this->find($uuid) ?? [];
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function update(string $uuid, array $data, string $actor): array
    {
        $p = $this->store->find('proposals', $uuid);
        if ($p === null) {
            throw new ProposalException(404, 'Proposal not found.');
        }
        if (!in_array((string) $p['status'], self::EDITABLE, true)) {
            throw new ProposalException(409, 'A sent proposal can’t be edited — supersede it with a new version instead.');
        }

        $this->store->update('proposals', $uuid, function (array $row) use ($data): array {
            $nullable = ['contact_uuid', 'company_uuid', 'opportunity_uuid', 'project_uuid', 'expiry_date'];
            foreach (['title', 'currency', 'owner', 'contact_uuid', 'company_uuid', 'opportunity_uuid', 'project_uuid', 'expiry_date'] as $col) {
                if (array_key_exists($col, $data)) {
                    $row[$col] = in_array($col, $nullable, true) ? $this->nullable($data[$col]) : (string) $data[$col];
                }
            }
            if (array_key_exists('deposit_amount', $data)) {
                $row['deposit_amount'] = max(0, (int) round(((float) $data['deposit_amount']) * 100));
            }
            $row['updated_at'] = Clock::nowIso();

            return $row;
        });

        $this->applyNarrative($uuid, $data);
        if (isset($data['items']) && is_array($data['items'])) {
            $this->replaceItems($uuid, $data['items']);
        }
        $this->recompute($uuid);

        return $this->find($uuid) ?? [];
    }

    /**
     * Mark a draft as ready to send (a review gate).
     *
     * @return array<string,mixed>
     */
    public function markReady(string $uuid, string $actor): array
    {
        $this->transition($uuid, ['draft'], 'ready', 'ready', 'Marked ready to send', $actor);

        return $this->find($uuid) ?? [];
    }

    // ==================================================================
    // Send / lifecycle
    // ==================================================================

    /**
     * Allocate a number, freeze the snapshot, mint a public token and lock it.
     *
     * @param array<string,mixed> $settings invoice_settings row for seller identity
     * @return array<string,mixed>
     */
    public function send(string $uuid, string $actor, array $settings): array
    {
        $p = $this->store->find('proposals', $uuid);
        if ($p === null) {
            throw new ProposalException(404, 'Proposal not found.');
        }
        if (!in_array((string) $p['status'], ['draft', 'ready'], true)) {
            throw new ProposalException(409, 'This proposal has already been sent.');
        }
        $itemCount = count(array_filter($this->store->all('proposal_items'), static fn (array $r): bool => (string) ($r['proposal_uuid'] ?? '') === $uuid));
        if ($itemCount === 0) {
            throw new ProposalException(422, 'Add at least one item before sending.');
        }

        $number = ($p['number'] ?? null) ?: $this->allocateNumber((string) ($settings['proposal_prefix'] ?? 'PRO'), (int) date('Y'));
        $token  = (string) (($p['public_token'] ?? null) ?: bin2hex(random_bytes(20)));
        $now    = Clock::nowIso();

        $this->store->update('proposals', $uuid, static function (array $row) use ($number, $token, $settings, $now): array {
            $row['number']          = $number;
            $row['status']          = 'sent';
            $row['public_token']    = $token;
            $row['client_name']     = (string) (($row['client_name'] ?? '') ?: '');
            $row['client_email']    = (string) (($row['client_email'] ?? '') ?: '');
            $row['seller_name']     = (string) ($settings['company_legal_name'] ?? 'Breakfast');
            $row['vat_registered']  = (int) ($settings['vat_registered'] ?? 0);
            $row['document_status'] = 'pending';
            $row['sent_at']         = $row['sent_at'] ?? $now;
            $row['updated_at']      = $now;

            return $row;
        });

        $detail   = $this->find($uuid) ?? [];
        $snapshot = ProposalSnapshot::build($detail, $settings);
        $this->store->update('proposals', $uuid, static function (array $row) use ($snapshot): array {
            $row['snapshot']   = $snapshot;
            $row['updated_at'] = Clock::nowIso();

            return $row;
        });
        $this->event($uuid, 'sent', 'Sent as ' . $number, $actor);

        return $this->find($uuid) ?? [];
    }

    /** A client opened the proposal link — a view, never acceptance. */
    public function markViewed(string $uuid): void
    {
        $now = Clock::nowIso();
        $this->store->update('proposals', $uuid, static function (array $row) use ($now): array {
            if ((string) ($row['status'] ?? '') === 'sent') {
                $row['status'] = 'viewed';
            }
            $row['viewed_at']  = $row['viewed_at'] ?? $now;
            $row['updated_at'] = $now;

            return $row;
        });
        $this->event($uuid, 'viewed', 'Opened by the client', 'client');
    }

    /**
     * Client selects optional items (by item uuid). Recomputes totals. Only
     * allowed while the proposal is live (sent/viewed).
     *
     * @param list<string> $selectedItemUuids
     * @return array<string,mixed>
     */
    public function selectOptions(string $uuid, array $selectedItemUuids, string $actor): array
    {
        $this->assertStatus($uuid, self::LIVE, 'Options can only be chosen on a live proposal.');
        $selected = array_map('strval', $selectedItemUuids);
        foreach ($this->store->all('proposal_items') as $item) {
            if ((string) ($item['proposal_uuid'] ?? '') !== $uuid || (string) ($item['kind'] ?? '') !== 'optional') {
                continue;
            }
            $isSelected = in_array((string) ($item['uuid'] ?? ''), $selected, true) ? 1 : 0;
            $this->store->update('proposal_items', (string) $item['uuid'], static function (array $row) use ($isSelected): array {
                $row['is_selected'] = $isSelected;

                return $row;
            });
        }
        $this->recompute($uuid);
        $this->event($uuid, 'options_selected', count($selectedItemUuids) . ' optional item(s) selected', $actor);

        return $this->find($uuid) ?? [];
    }

    /**
     * Record a genuine, evidenced acceptance. Freezes nothing new (the snapshot
     * is already frozen at send); captures who accepted, when, what options and a
     * hash of the accepted document.
     *
     * @param array<string,mixed> $evidence name,email,ip_hash,user_agent,wording
     * @return array<string,mixed>
     */
    public function accept(string $uuid, array $evidence): array
    {
        $p = $this->store->find('proposals', $uuid);
        if ($p === null) {
            throw new ProposalException(404, 'Proposal not found.');
        }
        if ((string) $p['status'] === 'accepted') {
            throw new ProposalException(409, 'This proposal has already been accepted.');
        }
        if (!in_array((string) $p['status'], self::LIVE, true)) {
            throw new ProposalException(409, 'This proposal is not open for acceptance.');
        }
        if (($p['expiry_date'] ?? null) !== null && (string) $p['expiry_date'] !== '' && (string) $p['expiry_date'] < date('Y-m-d')) {
            throw new ProposalException(410, 'This proposal has expired.');
        }
        $name = trim((string) ($evidence['name'] ?? ''));
        if ($name === '') {
            throw new ProposalException(422, 'Please enter your name to accept.');
        }

        $snapshot = $p['snapshot'] ?? null;
        $snapJson = is_array($snapshot) ? (json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '') : (string) $snapshot;
        $hash     = hash('sha256', $snapJson);
        $selected = array_values(array_filter($this->store->all('proposal_items'), static fn (array $r): bool => (string) ($r['proposal_uuid'] ?? '') === $uuid
            && (string) ($r['kind'] ?? '') === 'optional'
            && (int) ($r['is_selected'] ?? 0) === 1));
        $total    = (int) ($p['total'] ?? 0);

        $this->store->put('proposal_acceptances', [
            'uuid'             => Uuid::v4(),
            'proposal_uuid'    => $uuid,
            'snapshot_version' => ProposalSnapshot::VERSION,
            'accepted_name'    => $name,
            'accepted_email'   => trim((string) ($evidence['email'] ?? '')),
            'ip_hash'          => (string) ($evidence['ip_hash'] ?? ''),
            'user_agent'       => mb_substr((string) ($evidence['user_agent'] ?? ''), 0, 200),
            'selected_options' => array_map(static fn (array $r): string => (string) $r['uuid'], $selected),
            'total_accepted'   => $total,
            'wording'          => mb_substr((string) ($evidence['wording'] ?? 'I accept this proposal.'), 0, 500),
            'document_hash'    => $hash,
            'created_at'       => Clock::nowIso(),
        ]);

        $now = Clock::nowIso();
        $this->store->update('proposals', $uuid, static function (array $row) use ($now): array {
            $row['status']      = 'accepted';
            $row['accepted_at'] = $now;
            $row['updated_at']  = $now;

            return $row;
        });
        $this->event($uuid, 'accepted', 'Accepted by ' . $name . ' (£' . number_format($total / 100, 2) . ')', 'client');

        return $this->find($uuid) ?? [];
    }

    /** @return array<string,mixed> */
    public function reject(string $uuid, string $reason, string $actorType = 'client'): array
    {
        $this->assertStatus($uuid, self::LIVE, 'Only a live proposal can be rejected.');
        $now = Clock::nowIso();
        $this->store->update('proposals', $uuid, static function (array $row) use ($now): array {
            $row['status']      = 'rejected';
            $row['rejected_at'] = $now;
            $row['updated_at']  = $now;

            return $row;
        });
        $this->event($uuid, 'rejected', trim($reason) !== '' ? 'Rejected: ' . trim($reason) : 'Rejected', $actorType);

        return $this->find($uuid) ?? [];
    }

    /** @return array<string,mixed> */
    public function withdraw(string $uuid, string $actor): array
    {
        $this->assertStatus($uuid, ['sent', 'viewed', 'ready'], 'Only an open proposal can be withdrawn.');
        $this->store->update('proposals', $uuid, static function (array $row): array {
            $row['status']     = 'withdrawn';
            $row['updated_at'] = Clock::nowIso();

            return $row;
        });
        $this->event($uuid, 'withdrawn', 'Withdrawn by ' . $actor, $actor);

        return $this->find($uuid) ?? [];
    }

    /**
     * Create a fresh editable draft that supersedes this proposal, copying its
     * content and items. The original is marked superseded.
     *
     * @return array<string,mixed>
     */
    public function supersede(string $uuid, string $actor): array
    {
        $p = $this->find($uuid);
        if ($p === null) {
            throw new ProposalException(404, 'Proposal not found.');
        }
        $items = array_map(static fn (array $i): array => [
            'kind' => $i['kind'], 'description' => $i['description'],
            'quantity' => (int) $i['quantity'] / 1000, 'unit_price' => (int) $i['unit_price'] / 100,
            'tax_rate' => (int) $i['tax_rate'] / 100, 'discount' => (int) $i['discount'] / 100,
            'recurrence' => $i['recurrence'],
        ], is_array($p['items']) ? $p['items'] : []);

        $copy = $this->create([
            'title' => (string) $p['title'] . ' (v2)',
            'contact_uuid' => $p['contact_uuid'], 'company_uuid' => $p['company_uuid'],
            'opportunity_uuid' => $p['opportunity_uuid'], 'project_uuid' => $p['project_uuid'],
            'currency' => $p['currency'], 'owner' => $p['owner'], 'items' => $items,
            'introduction' => $p['introduction'], 'client_problem' => $p['client_problem'],
            'recommended_solution' => $p['recommended_solution'], 'scope' => $p['scope'],
            'deliverables' => $p['deliverables'], 'exclusions' => $p['exclusions'],
            'assumptions' => $p['assumptions'], 'timeline' => $p['timeline'],
            'payment_schedule' => $p['payment_schedule'], 'terms' => $p['terms'],
            'client_name' => $p['client_name'], 'client_email' => $p['client_email'],
        ], $actor);
        $copyUuid = (string) $copy['uuid'];
        $this->store->update('proposals', $copyUuid, static function (array $row) use ($uuid): array {
            $row['supersedes_uuid'] = $uuid;

            return $row;
        });

        if (in_array((string) $p['status'], ['sent', 'viewed', 'ready'], true)) {
            $this->store->update('proposals', $uuid, static function (array $row): array {
                $row['status']     = 'superseded';
                $row['updated_at'] = Clock::nowIso();

                return $row;
            });
            $this->event($uuid, 'superseded', 'Superseded by ' . ((string) ($copy['number'] ?? '') ?: 'a new version'), $actor);
        }

        return $this->find($copyUuid) ?? [];
    }

    // ==================================================================
    // Document hooks (used by the PDF service)
    // ==================================================================

    /** @return array<string,mixed>|null */
    public function snapshot(string $uuid): ?array
    {
        $p = $this->store->find('proposals', $uuid);
        $snap = $p['snapshot'] ?? null;

        return is_array($snap) && $snap !== [] ? $snap : null;
    }

    public function setDocumentStatus(string $uuid, string $status): void
    {
        $this->store->update('proposals', $uuid, static function (array $row) use ($status): array {
            $row['document_status'] = $status;
            $row['updated_at']      = Clock::nowIso();

            return $row;
        });
    }

    /** Record a generated PDF's storage key, hash and size, marking it generated. */
    public function setDocument(string $uuid, string $key, string $hash, int $bytes): void
    {
        $this->store->update('proposals', $uuid, static function (array $row) use ($key, $hash, $bytes): array {
            $row['document_key']    = $key;
            $row['document_hash']   = $hash;
            $row['document_bytes']  = $bytes;
            $row['document_status'] = 'generated';
            $row['updated_at']      = Clock::nowIso();

            return $row;
        });
    }

    public function logEvent(string $uuid, string $type, string $detail, string $actor): void
    {
        $this->event($uuid, $type, $detail, $actor);
    }

    /** @return list<array<string,mixed>> */
    public function needingPdfBackfill(): array
    {
        $rows = array_values(array_filter($this->store->all('proposals'), static function (array $r): bool {
            $snap = $r['snapshot'] ?? null;

            return is_array($snap) && $snap !== [] && (string) ($r['document_status'] ?? 'none') !== 'generated';
        }));
        usort($rows, static fn ($a, $b) => [(string) ($a['sent_at'] ?? ''), (string) ($a['number'] ?? '')] <=> [(string) ($b['sent_at'] ?? ''), (string) ($b['number'] ?? '')]);

        return array_map(static fn (array $r): array => [
            'uuid'            => $r['uuid'] ?? '',
            'number'          => $r['number'] ?? '',
            'document_status' => $r['document_status'] ?? 'none',
        ], $rows);
    }

    // ==================================================================
    // Internals
    // ==================================================================

    /** @param array<string,mixed> $data */
    private function applyNarrative(string $uuid, array $data): void
    {
        $this->store->update('proposals', $uuid, static function (array $row) use ($data): array {
            $changed = false;
            foreach (self::NARRATIVE as $f) {
                if (array_key_exists($f, $data)) {
                    $row[$f] = (string) $data[$f];
                    $changed = true;
                }
            }
            if ($changed) {
                $row['updated_at'] = Clock::nowIso();
            }

            return $row;
        });
    }

    /**
     * @param array<int,mixed> $items
     */
    private function replaceItems(string $uuid, array $items): void
    {
        foreach ($this->store->all('proposal_items') as $existing) {
            if ((string) ($existing['proposal_uuid'] ?? '') === $uuid) {
                $this->store->delete('proposal_items', (string) ($existing['uuid'] ?? ''));
            }
        }
        $pos = 0;
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $kind = in_array(($item['kind'] ?? 'fixed'), ['fixed', 'optional', 'recurring'], true) ? (string) $item['kind'] : 'fixed';
            $qty      = (int) round(((float) ($item['quantity'] ?? 1)) * 1000);
            $unit     = (int) round(((float) ($item['unit_price'] ?? 0)) * 100);
            $taxRate  = (int) round(((float) ($item['tax_rate'] ?? 0)) * 100);
            $discount = (int) round(((float) ($item['discount'] ?? 0)) * 100);
            $description = trim((string) ($item['description'] ?? ''));
            if ($description === '' && $unit === 0) {
                continue;
            }
            $recurrence = $kind === 'recurring' && in_array(($item['recurrence'] ?? ''), ['monthly', 'quarterly', 'annual'], true)
                ? (string) $item['recurrence'] : '';
            // Optional items default to unselected; fixed/recurring are always in.
            $selected = $kind === 'optional' ? (int) !empty($item['is_selected']) : 1;

            $gross    = (int) round($qty / 1000 * $unit);
            $subtotal = (int) round($gross * (10000 - $discount) / 10000);
            $tax      = (int) round($subtotal * $taxRate / 10000);

            $this->store->put('proposal_items', [
                'uuid'          => Uuid::v4(),
                'proposal_uuid' => $uuid,
                'position'      => $pos++,
                'kind'          => $kind,
                'description'   => $description,
                'quantity'      => $qty,
                'unit_price'    => $unit,
                'tax_rate'      => $taxRate,
                'discount'      => $discount,
                'recurrence'    => $recurrence,
                'is_selected'   => $selected,
                'line_subtotal' => $subtotal,
                'line_tax'      => $tax,
                'line_total'    => $subtotal + $tax,
            ]);
        }
    }

    /**
     * Total = fixed items + SELECTED optional items (one-off). Recurring items
     * are summarised separately (indicative per-period figure).
     */
    private function recompute(string $uuid): void
    {
        $sub = 0;
        $tax = 0;
        $recurring = 0;
        foreach ($this->store->all('proposal_items') as $item) {
            if ((string) ($item['proposal_uuid'] ?? '') !== $uuid) {
                continue;
            }
            $kind = (string) ($item['kind'] ?? '');
            if ($kind === 'fixed' || ($kind === 'optional' && (int) ($item['is_selected'] ?? 0) === 1)) {
                $sub += (int) ($item['line_subtotal'] ?? 0);
                $tax += (int) ($item['line_tax'] ?? 0);
            }
            if ($kind === 'recurring') {
                $recurring += (int) ($item['line_total'] ?? 0);
            }
        }
        $this->store->update('proposals', $uuid, static function (array $row) use ($sub, $tax, $recurring): array {
            $row['subtotal']        = $sub;
            $row['tax_total']       = $tax;
            $row['total']           = $sub + $tax;
            $row['recurring_total'] = $recurring;
            $row['updated_at']      = Clock::nowIso();

            return $row;
        });
    }

    private function allocateNumber(string $prefix, int $year): string
    {
        $seq = $this->store->bump('proposal_sequences', $prefix . '-' . $year);

        return sprintf('%s-%d-%04d', $prefix, $year, $seq);
    }

    /** @param list<string> $allowed */
    private function assertStatus(string $uuid, array $allowed, string $message): void
    {
        $p = $this->store->find('proposals', $uuid);
        if ($p === null) {
            throw new ProposalException(404, 'Proposal not found.');
        }
        if (!in_array((string) ($p['status'] ?? ''), $allowed, true)) {
            throw new ProposalException(409, $message);
        }
    }

    /** @param list<string> $from */
    private function transition(string $uuid, array $from, string $to, string $eventType, string $detail, string $actor): void
    {
        $this->assertStatus($uuid, $from, 'This proposal can’t change to ' . $to . ' from its current state.');
        $this->store->update('proposals', $uuid, static function (array $row) use ($to): array {
            $row['status']     = $to;
            $row['updated_at'] = Clock::nowIso();

            return $row;
        });
        $this->event($uuid, $eventType, $detail, $actor);
    }

    private function event(string $uuid, string $type, string $detail, string $actor): void
    {
        $this->store->put('proposal_events', [
            'uuid'          => Uuid::v4(),
            'proposal_uuid' => $uuid,
            'type'          => $type,
            'detail'        => $detail,
            'actor'         => $actor,
            'created_at'    => Clock::nowIso(),
        ]);
    }

    private function nullable(mixed $value): ?string
    {
        $v = trim((string) ($value ?? ''));

        return $v === '' ? null : $v;
    }
}
