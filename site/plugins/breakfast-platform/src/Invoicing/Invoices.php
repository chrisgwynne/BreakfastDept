<?php

declare(strict_types=1);

namespace Breakfast\Platform\Invoicing;

use Breakfast\Platform\Support\Clock;
use Breakfast\Platform\Support\Database;
use Breakfast\Platform\Support\Uuid;

/**
 * Lightweight invoicing service for the studio.
 *
 * All money is stored and computed in integer pence; quantities in thousandths,
 * tax/discount in basis points — so totals are exact, never floating point.
 * Draft invoices are freely editable; issuing an invoice allocates a monotonic
 * number, snapshots the seller details and locks the figures. Every state change
 * is recorded as an immutable invoice_event. Nothing here fabricates a paid or
 * sent state — those only change through recordPayment()/send().
 */
final class Invoices
{
    /** @var list<string> */
    public const STATUSES = ['draft', 'issued', 'sent', 'viewed', 'partial', 'paid', 'overdue', 'void'];

    public function __construct(private readonly Database $db)
    {
    }

    // ==================================================================
    // Settings (single row)
    // ==================================================================

    /** @return array<string,mixed> */
    public function settings(): array
    {
        $row = $this->db->one('SELECT * FROM invoice_settings WHERE id = 1');
        if ($row === null) {
            $this->db->run(
                'INSERT INTO invoice_settings (id, currency, invoice_prefix, default_terms_days, updated_at)
                 VALUES (1, :c, :p, :t, :u)',
                ['c' => 'GBP', 'p' => 'INV', 't' => 14, 'u' => Clock::nowIso()]
            );
            $row = $this->db->one('SELECT * FROM invoice_settings WHERE id = 1');
        }

        return $row ?? [];
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function updateSettings(array $data): array
    {
        $this->settings(); // ensure the row exists
        $fields = [
            'company_legal_name', 'company_address', 'company_email', 'payment_details',
            'vat_number', 'currency', 'invoice_prefix', 'default_notes',
        ];
        $sets = [];
        $params = ['u' => Clock::nowIso()];
        foreach ($fields as $f) {
            if (array_key_exists($f, $data)) {
                $sets[] = "$f = :$f";
                $params[$f] = (string) $data[$f];
            }
        }
        if (array_key_exists('vat_registered', $data)) {
            $sets[] = 'vat_registered = :vr';
            $params['vr'] = !empty($data['vat_registered']) ? 1 : 0;
        }
        if (array_key_exists('default_vat_rate', $data)) {
            $sets[] = 'default_vat_rate = :dvr';
            $params['dvr'] = (int) $data['default_vat_rate'];
        }
        if (array_key_exists('default_terms_days', $data)) {
            $sets[] = 'default_terms_days = :dtd';
            $params['dtd'] = max(0, (int) $data['default_terms_days']);
        }
        if ($sets !== []) {
            $this->db->run('UPDATE invoice_settings SET ' . implode(', ', $sets) . ', updated_at = :u WHERE id = 1', $params);
        }

        return $this->settings();
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
        if (!empty($filters['status'])) {
            $where[] = 'status = :status';
            $params['status'] = (string) $filters['status'];
        }
        $params['l'] = (int) ($filters['limit'] ?? 100);
        $rows = $this->db->all(
            'SELECT * FROM invoices WHERE ' . implode(' AND ', $where) . ' ORDER BY created_at DESC LIMIT :l',
            $params
        );

        return array_map(fn (array $r): array => $this->withDerived($r), $rows);
    }

    /** @return array<string,mixed>|null */
    public function find(string $uuid): ?array
    {
        $inv = $this->db->one('SELECT * FROM invoices WHERE uuid = :u', ['u' => $uuid]);
        if ($inv === null) {
            return null;
        }
        $inv = $this->withDerived($inv);
        $inv['items']    = $this->db->all('SELECT * FROM invoice_items WHERE invoice_uuid = :u ORDER BY position ASC', ['u' => $uuid]);
        $inv['payments'] = $this->db->all('SELECT * FROM invoice_payments WHERE invoice_uuid = :u ORDER BY paid_on DESC', ['u' => $uuid]);
        $inv['events']   = $this->db->all('SELECT * FROM invoice_events WHERE invoice_uuid = :u ORDER BY created_at DESC', ['u' => $uuid]);

        return $inv;
    }

    /** @return array<string,mixed>|null */
    public function findByToken(string $token): ?array
    {
        if ($token === '') {
            return null;
        }
        $inv = $this->db->one('SELECT uuid FROM invoices WHERE public_token = :t', ['t' => $token]);

        return $inv === null ? null : $this->find((string) $inv['uuid']);
    }

    // ==================================================================
    // Create / update draft
    // ==================================================================

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function create(array $data, string $actor): array
    {
        $uuid = Uuid::v4();
        $now  = Clock::nowIso();
        $settings = $this->settings();

        $this->db->run(
            'INSERT INTO invoices
                (uuid, status, contact_uuid, company_uuid, project, currency, bill_to_name,
                 bill_to_address, bill_to_email, notes, terms, created_by, created_at, updated_at)
             VALUES
                (:uuid, \'draft\', :contact, :company, :project, :currency, :bname, :baddr, :bemail,
                 :notes, :terms, :actor, :now, :now)',
            [
                'uuid'    => $uuid,
                'contact' => $data['contact_uuid'] ?? null,
                'company' => $data['company_uuid'] ?? null,
                'project' => (string) ($data['project'] ?? ''),
                'currency' => (string) ($settings['currency'] ?? 'GBP'),
                'bname'   => (string) ($data['bill_to_name'] ?? ''),
                'baddr'   => (string) ($data['bill_to_address'] ?? ''),
                'bemail'  => (string) ($data['bill_to_email'] ?? ''),
                'notes'   => (string) ($data['notes'] ?? (string) ($settings['default_notes'] ?? '')),
                'terms'   => (string) ($data['terms'] ?? ''),
                'actor'   => $actor,
                'now'     => $now,
            ]
        );

        $this->replaceItems($uuid, is_array($data['items'] ?? null) ? $data['items'] : []);
        $this->recompute($uuid);
        $this->event($uuid, 'created', 'Draft created', $actor);

        return $this->find($uuid) ?? [];
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function update(string $uuid, array $data, string $actor): array
    {
        $inv = $this->db->one('SELECT status FROM invoices WHERE uuid = :u', ['u' => $uuid]);
        if ($inv === null) {
            throw new InvoiceException(404, 'Invoice not found.');
        }
        if ((string) $inv['status'] !== 'draft') {
            throw new InvoiceException(409, 'Only draft invoices can be edited.');
        }

        $sets = ['updated_at = :now'];
        $params = ['u' => $uuid, 'now' => Clock::nowIso()];
        foreach (['project', 'bill_to_name', 'bill_to_address', 'bill_to_email', 'notes', 'terms', 'contact_uuid', 'company_uuid', 'due_date'] as $f) {
            if (array_key_exists($f, $data)) {
                $sets[] = "$f = :$f";
                $params[$f] = $data[$f] === null ? null : (string) $data[$f];
            }
        }
        $this->db->run('UPDATE invoices SET ' . implode(', ', $sets) . ' WHERE uuid = :u', $params);

        if (array_key_exists('items', $data) && is_array($data['items'])) {
            $this->replaceItems($uuid, $data['items']);
        }
        $this->recompute($uuid);
        $this->event($uuid, 'updated', 'Draft updated', $actor);

        return $this->find($uuid) ?? [];
    }

    // ==================================================================
    // Issue / send / payments / void
    // ==================================================================

    /** @return array<string,mixed> */
    public function issue(string $uuid, string $actor): array
    {
        $inv = $this->db->one('SELECT * FROM invoices WHERE uuid = :u', ['u' => $uuid]);
        if ($inv === null) {
            throw new InvoiceException(404, 'Invoice not found.');
        }
        if ((string) $inv['status'] !== 'draft') {
            throw new InvoiceException(409, 'This invoice has already been issued.');
        }
        if ((int) $this->db->scalar('SELECT COUNT(*) FROM invoice_items WHERE invoice_uuid = :u', ['u' => $uuid]) === 0) {
            throw new InvoiceException(422, 'Add at least one line item before issuing.');
        }

        $settings = $this->settings();

        return $this->db->transaction(function (Database $db) use ($uuid, $actor, $settings): array {
            $prefix = (string) ($settings['invoice_prefix'] ?? 'INV');
            $year   = (int) date('Y');
            $number = $this->allocateNumber($db, $prefix, $year);

            $issueDate = date('Y-m-d');
            $dueDate   = date('Y-m-d', time() + max(0, (int) ($settings['default_terms_days'] ?? 14)) * 86400);
            $token     = bin2hex(random_bytes(20));

            $db->run(
                'UPDATE invoices SET
                    number = :num, status = \'issued\', issue_date = :issue, due_date = :due,
                    seller_name = :sname, seller_address = :saddr, seller_vat_number = :svat,
                    vat_registered = :vreg, payment_details = :pay, public_token = :tok,
                    issued_at = :now, updated_at = :now
                 WHERE uuid = :u',
                [
                    'num'   => $number,
                    'issue' => $issueDate,
                    'due'   => $dueDate,
                    'sname' => (string) ($settings['company_legal_name'] ?? ''),
                    'saddr' => (string) ($settings['company_address'] ?? ''),
                    'svat'  => (string) ($settings['vat_number'] ?? ''),
                    'vreg'  => (int) ($settings['vat_registered'] ?? 0),
                    'pay'   => (string) ($settings['payment_details'] ?? ''),
                    'tok'   => $token,
                    'now'   => Clock::nowIso(),
                    'u'     => $uuid,
                ]
            );
            $this->event($uuid, 'issued', 'Issued as ' . $number, $actor);

            // Freeze the immutable financial snapshot and mark the document as
            // pending generation. The PDF is rendered from THIS snapshot, never
            // from live settings/client data, so the issued document is stable.
            $detail   = $this->find($uuid) ?? [];
            $snapshot = InvoiceSnapshot::build($detail, $settings);
            $db->run(
                "UPDATE invoices SET snapshot = :snap, document_status = 'pending', updated_at = :now WHERE uuid = :u",
                ['snap' => json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '', 'now' => Clock::nowIso(), 'u' => $uuid]
            );

            return $this->find($uuid) ?? [];
        });
    }

    /**
     * The frozen snapshot for an issued invoice, or null if not yet issued.
     *
     * @return array<string,mixed>|null
     */
    public function snapshot(string $uuid): ?array
    {
        $raw = (string) $this->db->scalar('SELECT snapshot FROM invoices WHERE uuid = :u', ['u' => $uuid]);
        if ($raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    public function setDocumentStatus(string $uuid, string $status): void
    {
        $this->db->run('UPDATE invoices SET document_status = :s, updated_at = :n WHERE uuid = :u', ['s' => $status, 'n' => Clock::nowIso(), 'u' => $uuid]);
    }

    /** Record an immutable invoice event (public wrapper for the document service). */
    public function logEvent(string $uuid, string $type, string $detail, string $actor): void
    {
        $this->event($uuid, $type, $detail, $actor);
    }

    public function markSent(string $uuid, string $actor, string $detail): void
    {
        $now = Clock::nowIso();
        $this->db->run(
            "UPDATE invoices SET status = CASE WHEN status = 'issued' THEN 'sent' ELSE status END,
                sent_at = COALESCE(sent_at, :now), updated_at = :now WHERE uuid = :u",
            ['now' => $now, 'u' => $uuid]
        );
        $this->event($uuid, 'sent', $detail, $actor);
    }

    public function markViewed(string $uuid): void
    {
        $now = Clock::nowIso();
        $this->db->run(
            "UPDATE invoices SET status = CASE WHEN status IN ('issued','sent') THEN 'viewed' ELSE status END,
                viewed_at = COALESCE(viewed_at, :now), updated_at = :now WHERE uuid = :u",
            ['now' => $now, 'u' => $uuid]
        );
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function recordPayment(string $uuid, array $data, string $actor): array
    {
        $inv = $this->db->one('SELECT * FROM invoices WHERE uuid = :u', ['u' => $uuid]);
        if ($inv === null) {
            throw new InvoiceException(404, 'Invoice not found.');
        }
        if ((string) $inv['status'] === 'draft') {
            throw new InvoiceException(409, 'Issue the invoice before recording a payment.');
        }
        if ((string) $inv['status'] === 'void') {
            throw new InvoiceException(409, 'This invoice is void.');
        }
        $amount = (int) round(((float) ($data['amount'] ?? 0)) * 100);
        if ($amount <= 0) {
            throw new InvoiceException(422, 'Enter a payment amount greater than zero.');
        }

        return $this->db->transaction(function (Database $db) use ($uuid, $inv, $amount, $data, $actor): array {
            $db->run(
                'INSERT INTO invoice_payments (uuid, invoice_uuid, amount, paid_on, method, reference, note, created_by, created_at)
                 VALUES (:uuid, :inv, :amt, :on, :method, :ref, :note, :actor, :now)',
                [
                    'uuid'  => Uuid::v4(),
                    'inv'   => $uuid,
                    'amt'   => $amount,
                    'on'    => (string) ($data['paid_on'] ?? date('Y-m-d')),
                    'method' => (string) ($data['method'] ?? ''),
                    'ref'   => (string) ($data['reference'] ?? ''),
                    'note'  => (string) ($data['note'] ?? ''),
                    'actor' => $actor,
                    'now'   => Clock::nowIso(),
                ]
            );

            $paid  = (int) $inv['amount_paid'] + $amount;
            $total = (int) $inv['total'];
            $status = $paid >= $total && $total > 0 ? 'paid' : 'partial';
            $db->run(
                'UPDATE invoices SET amount_paid = :paid, status = :status,
                    paid_at = :paidAt, updated_at = :now WHERE uuid = :u',
                [
                    'paid'   => $paid,
                    'status' => $status,
                    'paidAt' => $status === 'paid' ? Clock::nowIso() : ($inv['paid_at'] ?? null),
                    'now'    => Clock::nowIso(),
                    'u'      => $uuid,
                ]
            );
            $this->event($uuid, 'payment', 'Payment recorded: ' . $this->money($amount), $actor);

            return $this->find($uuid) ?? [];
        });
    }

    /** @return array<string,mixed> */
    public function void(string $uuid, string $actor): array
    {
        $inv = $this->db->one('SELECT status FROM invoices WHERE uuid = :u', ['u' => $uuid]);
        if ($inv === null) {
            throw new InvoiceException(404, 'Invoice not found.');
        }
        if ((string) $inv['status'] === 'paid') {
            throw new InvoiceException(409, 'A paid invoice cannot be voided.');
        }
        $this->db->run("UPDATE invoices SET status = 'void', voided_at = :now, updated_at = :now WHERE uuid = :u", ['now' => Clock::nowIso(), 'u' => $uuid]);
        $this->event($uuid, 'void', 'Invoice voided', $actor);

        return $this->find($uuid) ?? [];
    }

    // ==================================================================
    // Internals
    // ==================================================================

    private function allocateNumber(Database $db, string $prefix, int $year): string
    {
        $db->run(
            'INSERT INTO invoice_sequences (prefix, year, next_seq) VALUES (:p, :y, 1)
             ON CONFLICT (prefix, year) DO NOTHING',
            ['p' => $prefix, 'y' => $year]
        );
        $seq = (int) $db->scalar('SELECT next_seq FROM invoice_sequences WHERE prefix = :p AND year = :y', ['p' => $prefix, 'y' => $year]);
        $db->run('UPDATE invoice_sequences SET next_seq = next_seq + 1 WHERE prefix = :p AND year = :y', ['p' => $prefix, 'y' => $year]);

        return sprintf('%s-%d-%04d', $prefix, $year, $seq);
    }

    /** @param array<int,mixed> $items */
    private function replaceItems(string $invoiceUuid, array $items): void
    {
        $this->db->run('DELETE FROM invoice_items WHERE invoice_uuid = :u', ['u' => $invoiceUuid]);
        $pos = 0;
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $qty      = (int) round(((float) ($item['quantity'] ?? 1)) * 1000);
            $unit     = (int) round(((float) ($item['unit_price'] ?? 0)) * 100);
            $taxRate  = (int) round(((float) ($item['tax_rate'] ?? 0)) * 100);   // percent → basis points
            $discount = (int) round(((float) ($item['discount'] ?? 0)) * 100);
            $description = trim((string) ($item['description'] ?? ''));
            if ($description === '' && $unit === 0) {
                continue;
            }

            $gross    = (int) round($qty / 1000 * $unit);
            $subtotal = (int) round($gross * (10000 - $discount) / 10000);
            $tax      = (int) round($subtotal * $taxRate / 10000);

            $this->db->run(
                'INSERT INTO invoice_items
                    (uuid, invoice_uuid, position, description, quantity, unit_price, tax_rate, discount, line_subtotal, line_tax, line_total)
                 VALUES (:uuid, :inv, :pos, :desc, :qty, :unit, :rate, :disc, :sub, :tax, :total)',
                [
                    'uuid' => Uuid::v4(), 'inv' => $invoiceUuid, 'pos' => $pos++,
                    'desc' => $description, 'qty' => $qty, 'unit' => $unit, 'rate' => $taxRate,
                    'disc' => $discount, 'sub' => $subtotal, 'tax' => $tax, 'total' => $subtotal + $tax,
                ]
            );
        }
    }

    private function recompute(string $uuid): void
    {
        $row = $this->db->one(
            'SELECT COALESCE(SUM(line_subtotal),0) AS sub, COALESCE(SUM(line_tax),0) AS tax
             FROM invoice_items WHERE invoice_uuid = :u',
            ['u' => $uuid]
        ) ?? ['sub' => 0, 'tax' => 0];
        $sub = (int) $row['sub'];
        $tax = (int) $row['tax'];
        $this->db->run(
            'UPDATE invoices SET subtotal = :sub, tax_total = :tax, total = :total, updated_at = :now WHERE uuid = :u',
            ['sub' => $sub, 'tax' => $tax, 'total' => $sub + $tax, 'now' => Clock::nowIso(), 'u' => $uuid]
        );
    }

    /**
     * Add derived fields (amount_due, overdue flag) without persisting them.
     *
     * @param array<string,mixed> $r
     * @return array<string,mixed>
     */
    private function withDerived(array $r): array
    {
        $r['amount_due'] = (int) $r['total'] - (int) $r['amount_paid'];
        $overdue = in_array((string) $r['status'], ['issued', 'sent', 'viewed', 'partial'], true)
            && !empty($r['due_date']) && strtotime((string) $r['due_date']) !== false
            && strtotime((string) $r['due_date']) < strtotime(date('Y-m-d'));
        $r['overdue'] = $overdue;

        return $r;
    }

    private function event(string $uuid, string $type, string $detail, string $actor): void
    {
        $this->db->run(
            'INSERT INTO invoice_events (uuid, invoice_uuid, type, detail, actor, created_at)
             VALUES (:uuid, :inv, :type, :detail, :actor, :now)',
            ['uuid' => Uuid::v4(), 'inv' => $uuid, 'type' => $type, 'detail' => $detail, 'actor' => $actor, 'now' => Clock::nowIso()]
        );
    }

    private function money(int $pence): string
    {
        return '£' . number_format($pence / 100, 2);
    }
}
