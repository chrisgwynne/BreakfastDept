<?php

declare(strict_types=1);

namespace Breakfast\Platform\Proposals;

use Breakfast\Platform\Support\Clock;
use Breakfast\Platform\Support\Database;
use Breakfast\Platform\Support\Uuid;

/**
 * Proposals & quotes — the commercial entry point of the client lifecycle.
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

    public function __construct(private readonly Database $db)
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
        $where  = ['1 = 1'];
        $params = [];
        if (!empty($filters['status'])) {
            $where[] = 'status = :status';
            $params['status'] = (string) $filters['status'];
        }
        if (!empty($filters['opportunity_uuid'])) {
            $where[] = 'opportunity_uuid = :opp';
            $params['opp'] = (string) $filters['opportunity_uuid'];
        }
        $params['l'] = (int) ($filters['limit'] ?? 100);

        return $this->db->all(
            'SELECT * FROM proposals WHERE ' . implode(' AND ', $where) . ' ORDER BY created_at DESC LIMIT :l',
            $params
        );
    }

    /** @return array<string,mixed>|null */
    public function find(string $uuid): ?array
    {
        $p = $this->db->one('SELECT * FROM proposals WHERE uuid = :u', ['u' => $uuid]);
        if ($p === null) {
            return null;
        }
        $p['items']       = $this->db->all('SELECT * FROM proposal_items WHERE proposal_uuid = :u ORDER BY position ASC', ['u' => $uuid]);
        $p['events']      = $this->db->all('SELECT * FROM proposal_events WHERE proposal_uuid = :u ORDER BY created_at DESC', ['u' => $uuid]);
        $p['acceptances'] = $this->db->all('SELECT * FROM proposal_acceptances WHERE proposal_uuid = :u ORDER BY created_at DESC', ['u' => $uuid]);

        return $p;
    }

    /** @return array<string,mixed>|null */
    public function findByToken(string $token): ?array
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }
        $row = $this->db->one('SELECT uuid FROM proposals WHERE public_token = :t', ['t' => $token]);

        return $row !== null ? $this->find((string) $row['uuid']) : null;
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

        $this->db->run(
            'INSERT INTO proposals
                (uuid, status, title, contact_uuid, company_uuid, opportunity_uuid, project_uuid,
                 currency, owner, expiry_date, created_by, created_at, updated_at)
             VALUES
                (:uuid, \'draft\', :title, :contact, :company, :opp, :project,
                 :currency, :owner, :expiry, :actor, :now, :now)',
            [
                'uuid'    => $uuid,
                'title'   => trim((string) ($data['title'] ?? 'New proposal')),
                'contact' => $this->nullable($data['contact_uuid'] ?? null),
                'company' => $this->nullable($data['company_uuid'] ?? null),
                'opp'     => $this->nullable($data['opportunity_uuid'] ?? null),
                'project' => $this->nullable($data['project_uuid'] ?? null),
                'currency' => (string) ($data['currency'] ?? 'GBP'),
                'owner'   => trim((string) ($data['owner'] ?? $actor)),
                'expiry'  => $this->nullable($data['expiry_date'] ?? null),
                'actor'   => $actor,
                'now'     => $now,
            ]
        );

        $this->applyNarrative($uuid, $data);
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
        $p = $this->db->one('SELECT status FROM proposals WHERE uuid = :u', ['u' => $uuid]);
        if ($p === null) {
            throw new ProposalException(404, 'Proposal not found.');
        }
        if (!in_array((string) $p['status'], self::EDITABLE, true)) {
            throw new ProposalException(409, 'A sent proposal can’t be edited — supersede it with a new version instead.');
        }

        $simple = [
            'title' => 'title', 'currency' => 'currency', 'owner' => 'owner',
            'contact_uuid' => 'contact_uuid', 'company_uuid' => 'company_uuid',
            'opportunity_uuid' => 'opportunity_uuid', 'project_uuid' => 'project_uuid',
            'expiry_date' => 'expiry_date',
        ];
        $sets = [];
        $params = ['u' => $uuid, 'now' => Clock::nowIso()];
        foreach ($simple as $in => $col) {
            if (array_key_exists($in, $data)) {
                $sets[] = "$col = :$col";
                $params[$col] = in_array($col, ['contact_uuid', 'company_uuid', 'opportunity_uuid', 'project_uuid', 'expiry_date'], true)
                    ? $this->nullable($data[$in]) : (string) $data[$in];
            }
        }
        if (array_key_exists('deposit_amount', $data)) {
            $sets[] = 'deposit_amount = :dep';
            $params['dep'] = max(0, (int) round(((float) $data['deposit_amount']) * 100));
        }
        if ($sets !== []) {
            $this->db->run('UPDATE proposals SET ' . implode(', ', $sets) . ', updated_at = :now WHERE uuid = :u', $params);
        }

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
        return $this->db->transaction(function (Database $db) use ($uuid, $actor, $settings): array {
            $p = $db->one('SELECT * FROM proposals WHERE uuid = :u', ['u' => $uuid]);
            if ($p === null) {
                throw new ProposalException(404, 'Proposal not found.');
            }
            if (!in_array((string) $p['status'], ['draft', 'ready'], true)) {
                throw new ProposalException(409, 'This proposal has already been sent.');
            }
            if ((int) $db->scalar('SELECT COUNT(*) FROM proposal_items WHERE proposal_uuid = :u', ['u' => $uuid]) === 0) {
                throw new ProposalException(422, 'Add at least one item before sending.');
            }

            $number = $p['number'] ?: $this->allocateNumber($db, (string) ($settings['proposal_prefix'] ?? 'PRO'), (int) date('Y'));
            $token  = (string) ($p['public_token'] ?: bin2hex(random_bytes(20)));

            $db->run(
                "UPDATE proposals SET
                    number = :num, status = 'sent', public_token = :tok,
                    client_name = :cname, client_email = :cemail,
                    seller_name = :sname, vat_registered = :vreg,
                    document_status = 'pending', sent_at = COALESCE(sent_at, :now), updated_at = :now
                 WHERE uuid = :u",
                [
                    'num'    => $number,
                    'tok'    => $token,
                    'cname'  => (string) ($p['client_name'] ?: ''),
                    'cemail' => (string) ($p['client_email'] ?: ''),
                    'sname'  => (string) ($settings['company_legal_name'] ?? 'Breakfast'),
                    'vreg'   => (int) ($settings['vat_registered'] ?? 0),
                    'now'    => Clock::nowIso(),
                    'u'      => $uuid,
                ]
            );

            $detail   = $this->find($uuid) ?? [];
            $snapshot = ProposalSnapshot::build($detail, $settings);
            $db->run(
                'UPDATE proposals SET snapshot = :snap, updated_at = :now WHERE uuid = :u',
                ['snap' => json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '', 'now' => Clock::nowIso(), 'u' => $uuid]
            );
            $this->event($uuid, 'sent', 'Sent as ' . $number, $actor);

            return $this->find($uuid) ?? [];
        });
    }

    /** A client opened the proposal link — a view, never acceptance. */
    public function markViewed(string $uuid): void
    {
        $now = Clock::nowIso();
        $this->db->run(
            "UPDATE proposals SET status = CASE WHEN status = 'sent' THEN 'viewed' ELSE status END,
                viewed_at = COALESCE(viewed_at, :now), updated_at = :now WHERE uuid = :u",
            ['now' => $now, 'u' => $uuid]
        );
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
        $this->db->run('UPDATE proposal_items SET is_selected = 0 WHERE proposal_uuid = :u AND kind = \'optional\'', ['u' => $uuid]);
        foreach ($selectedItemUuids as $itemUuid) {
            $this->db->run(
                'UPDATE proposal_items SET is_selected = 1 WHERE proposal_uuid = :u AND uuid = :i AND kind = \'optional\'',
                ['u' => $uuid, 'i' => (string) $itemUuid]
            );
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
        return $this->db->transaction(function (Database $db) use ($uuid, $evidence): array {
            $p = $db->one('SELECT * FROM proposals WHERE uuid = :u', ['u' => $uuid]);
            if ($p === null) {
                throw new ProposalException(404, 'Proposal not found.');
            }
            if ((string) $p['status'] === 'accepted') {
                throw new ProposalException(409, 'This proposal has already been accepted.');
            }
            if (!in_array((string) $p['status'], self::LIVE, true)) {
                throw new ProposalException(409, 'This proposal is not open for acceptance.');
            }
            if ($p['expiry_date'] !== null && $p['expiry_date'] !== '' && (string) $p['expiry_date'] < date('Y-m-d')) {
                throw new ProposalException(410, 'This proposal has expired.');
            }
            $name = trim((string) ($evidence['name'] ?? ''));
            if ($name === '') {
                throw new ProposalException(422, 'Please enter your name to accept.');
            }

            $snapshot = (string) $p['snapshot'];
            $hash     = hash('sha256', $snapshot);
            $selected = $db->all("SELECT uuid FROM proposal_items WHERE proposal_uuid = :u AND kind = 'optional' AND is_selected = 1", ['u' => $uuid]);
            $total    = (int) $db->scalar('SELECT total FROM proposals WHERE uuid = :u', ['u' => $uuid]);

            $db->run(
                'INSERT INTO proposal_acceptances
                    (uuid, proposal_uuid, snapshot_version, accepted_name, accepted_email, ip_hash, user_agent,
                     selected_options, total_accepted, wording, document_hash, created_at)
                 VALUES (:uuid, :p, :sv, :name, :email, :ip, :ua, :opts, :total, :wording, :hash, :now)',
                [
                    'uuid'  => Uuid::v4(), 'p' => $uuid, 'sv' => ProposalSnapshot::VERSION,
                    'name'  => $name,
                    'email' => trim((string) ($evidence['email'] ?? '')),
                    'ip'    => (string) ($evidence['ip_hash'] ?? ''),
                    'ua'    => mb_substr((string) ($evidence['user_agent'] ?? ''), 0, 200),
                    'opts'  => json_encode(array_map(static fn ($r): string => (string) $r['uuid'], $selected)) ?: '[]',
                    'total' => $total,
                    'wording' => mb_substr((string) ($evidence['wording'] ?? 'I accept this proposal.'), 0, 500),
                    'hash'  => $hash, 'now' => Clock::nowIso(),
                ]
            );

            $db->run(
                "UPDATE proposals SET status = 'accepted', accepted_at = :now, updated_at = :now WHERE uuid = :u",
                ['now' => Clock::nowIso(), 'u' => $uuid]
            );
            $this->event($uuid, 'accepted', 'Accepted by ' . $name . ' (£' . number_format($total / 100, 2) . ')', 'client');

            return $this->find($uuid) ?? [];
        });
    }

    /** @return array<string,mixed> */
    public function reject(string $uuid, string $reason, string $actorType = 'client'): array
    {
        $this->assertStatus($uuid, self::LIVE, 'Only a live proposal can be rejected.');
        $this->db->run("UPDATE proposals SET status = 'rejected', rejected_at = :now, updated_at = :now WHERE uuid = :u", ['now' => Clock::nowIso(), 'u' => $uuid]);
        $this->event($uuid, 'rejected', trim($reason) !== '' ? 'Rejected: ' . trim($reason) : 'Rejected', $actorType);

        return $this->find($uuid) ?? [];
    }

    /** @return array<string,mixed> */
    public function withdraw(string $uuid, string $actor): array
    {
        $this->assertStatus($uuid, ['sent', 'viewed', 'ready'], 'Only an open proposal can be withdrawn.');
        $this->db->run("UPDATE proposals SET status = 'withdrawn', updated_at = :now WHERE uuid = :u", ['now' => Clock::nowIso(), 'u' => $uuid]);
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
        $this->db->run('UPDATE proposals SET supersedes_uuid = :old WHERE uuid = :new', ['old' => $uuid, 'new' => $copy['uuid']]);

        if (in_array((string) $p['status'], ['sent', 'viewed', 'ready'], true)) {
            $this->db->run("UPDATE proposals SET status = 'superseded', updated_at = :now WHERE uuid = :u", ['now' => Clock::nowIso(), 'u' => $uuid]);
            $this->event($uuid, 'superseded', 'Superseded by ' . ((string) $copy['number'] ?: 'a new version'), $actor);
        }

        return $this->find((string) $copy['uuid']) ?? [];
    }

    // ==================================================================
    // Document hooks (used by the PDF service)
    // ==================================================================

    /** @return array<string,mixed>|null */
    public function snapshot(string $uuid): ?array
    {
        $raw = (string) $this->db->scalar('SELECT snapshot FROM proposals WHERE uuid = :u', ['u' => $uuid]);
        if ($raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    public function setDocumentStatus(string $uuid, string $status): void
    {
        $this->db->run('UPDATE proposals SET document_status = :s, updated_at = :n WHERE uuid = :u', ['s' => $status, 'n' => Clock::nowIso(), 'u' => $uuid]);
    }

    public function logEvent(string $uuid, string $type, string $detail, string $actor): void
    {
        $this->event($uuid, $type, $detail, $actor);
    }

    /** @return list<array<string,mixed>> */
    public function needingPdfBackfill(): array
    {
        return $this->db->all(
            "SELECT uuid, number, document_status FROM proposals
             WHERE snapshot <> '' AND COALESCE(document_status, 'none') <> 'generated'
             ORDER BY sent_at ASC, number ASC"
        );
    }

    // ==================================================================
    // Internals
    // ==================================================================

    /** @param array<string,mixed> $data */
    private function applyNarrative(string $uuid, array $data): void
    {
        $fields = [
            'introduction', 'client_problem', 'recommended_solution', 'scope', 'deliverables',
            'exclusions', 'assumptions', 'timeline', 'payment_schedule', 'terms', 'internal_notes',
            'client_name', 'client_email',
        ];
        $sets = [];
        $params = ['u' => $uuid, 'now' => Clock::nowIso()];
        foreach ($fields as $f) {
            if (array_key_exists($f, $data)) {
                $sets[] = "$f = :$f";
                $params[$f] = (string) $data[$f];
            }
        }
        if ($sets !== []) {
            $this->db->run('UPDATE proposals SET ' . implode(', ', $sets) . ', updated_at = :now WHERE uuid = :u', $params);
        }
    }

    /**
     * @param array<int,mixed> $items
     */
    private function replaceItems(string $uuid, array $items): void
    {
        $this->db->run('DELETE FROM proposal_items WHERE proposal_uuid = :u', ['u' => $uuid]);
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

            $this->db->run(
                'INSERT INTO proposal_items
                    (uuid, proposal_uuid, position, kind, description, quantity, unit_price, tax_rate, discount,
                     recurrence, is_selected, line_subtotal, line_tax, line_total)
                 VALUES (:uuid, :p, :pos, :kind, :desc, :qty, :unit, :rate, :disc, :rec, :sel, :sub, :tax, :total)',
                [
                    'uuid' => Uuid::v4(), 'p' => $uuid, 'pos' => $pos++, 'kind' => $kind,
                    'desc' => $description, 'qty' => $qty, 'unit' => $unit, 'rate' => $taxRate, 'disc' => $discount,
                    'rec' => $recurrence, 'sel' => $selected, 'sub' => $subtotal, 'tax' => $tax, 'total' => $subtotal + $tax,
                ]
            );
        }
    }

    /**
     * Total = fixed items + SELECTED optional items (one-off). Recurring items
     * are summarised separately (indicative per-period figure).
     */
    private function recompute(string $uuid): void
    {
        $oneOff = $this->db->one(
            "SELECT COALESCE(SUM(line_subtotal),0) AS sub, COALESCE(SUM(line_tax),0) AS tax
             FROM proposal_items
             WHERE proposal_uuid = :u AND (kind = 'fixed' OR (kind = 'optional' AND is_selected = 1))",
            ['u' => $uuid]
        ) ?? ['sub' => 0, 'tax' => 0];
        $recurring = (int) $this->db->scalar(
            "SELECT COALESCE(SUM(line_total),0) FROM proposal_items WHERE proposal_uuid = :u AND kind = 'recurring'",
            ['u' => $uuid]
        );
        $sub = (int) $oneOff['sub'];
        $tax = (int) $oneOff['tax'];
        $this->db->run(
            'UPDATE proposals SET subtotal = :sub, tax_total = :tax, total = :total, recurring_total = :rec, updated_at = :now WHERE uuid = :u',
            ['sub' => $sub, 'tax' => $tax, 'total' => $sub + $tax, 'rec' => $recurring, 'now' => Clock::nowIso(), 'u' => $uuid]
        );
    }

    private function allocateNumber(Database $db, string $prefix, int $year): string
    {
        $db->run(
            'INSERT INTO proposal_sequences (prefix, year, next_seq) VALUES (:p, :y, 2)
             ON CONFLICT(prefix, year) DO UPDATE SET next_seq = next_seq + 1',
            ['p' => $prefix, 'y' => $year]
        );
        $seq = (int) $db->scalar('SELECT next_seq - 1 FROM proposal_sequences WHERE prefix = :p AND year = :y', ['p' => $prefix, 'y' => $year]);

        return sprintf('%s-%d-%04d', $prefix, $year, $seq);
    }

    /** @param list<string> $allowed */
    private function assertStatus(string $uuid, array $allowed, string $message): void
    {
        $status = (string) $this->db->scalar('SELECT status FROM proposals WHERE uuid = :u', ['u' => $uuid]);
        if ($status === '') {
            throw new ProposalException(404, 'Proposal not found.');
        }
        if (!in_array($status, $allowed, true)) {
            throw new ProposalException(409, $message);
        }
    }

    /** @param list<string> $from */
    private function transition(string $uuid, array $from, string $to, string $eventType, string $detail, string $actor): void
    {
        $this->assertStatus($uuid, $from, 'This proposal can’t change to ' . $to . ' from its current state.');
        $this->db->run('UPDATE proposals SET status = :s, updated_at = :now WHERE uuid = :u', ['s' => $to, 'now' => Clock::nowIso(), 'u' => $uuid]);
        $this->event($uuid, $eventType, $detail, $actor);
    }

    private function event(string $uuid, string $type, string $detail, string $actor): void
    {
        $this->db->run(
            'INSERT INTO proposal_events (uuid, proposal_uuid, type, detail, actor, created_at) VALUES (:uuid, :p, :t, :d, :a, :now)',
            ['uuid' => Uuid::v4(), 'p' => $uuid, 't' => $type, 'd' => $detail, 'a' => $actor, 'now' => Clock::nowIso()]
        );
    }

    private function nullable(mixed $value): ?string
    {
        $v = trim((string) ($value ?? ''));

        return $v === '' ? null : $v;
    }
}
