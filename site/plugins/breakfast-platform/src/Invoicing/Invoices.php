<?php

declare(strict_types=1);

namespace Breakfast\Platform\Invoicing;

use Breakfast\Platform\Support\Clock;
use Breakfast\Platform\Support\FileStore;
use Breakfast\Platform\Support\Uuid;

/**
 * Lightweight invoicing service for the studio, stored as flat files.
 *
 * All money is stored and computed in integer pence; quantities in thousandths,
 * tax/discount in basis points — so totals are exact, never floating point.
 * Draft invoices are freely editable; issuing an invoice allocates a monotonic
 * number, snapshots the seller details and locks the figures. Every state change
 * is recorded as an immutable invoice_event. Nothing here fabricates a paid or
 * sent state — those only change through recordPayment()/send().
 *
 * Records live in FileStore collections: invoices, invoice_items,
 * invoice_payments, invoice_events, invoice_settings (single 'default' record),
 * and a per-prefix/year counter under 'invoice_sequences'.
 */
final class Invoices
{
    /** @var list<string> */
    public const STATUSES = ['draft', 'issued', 'sent', 'viewed', 'partial', 'paid', 'overdue', 'void'];

    public function __construct(private readonly FileStore $store)
    {
    }

    // ==================================================================
    // Settings (single record)
    // ==================================================================

    /** @return array<string,mixed> */
    public function settings(): array
    {
        $row = $this->store->find('invoice_settings', 'default');
        if ($row === null) {
            $row = $this->store->put('invoice_settings', [
                'uuid'               => 'default',
                'currency'           => 'GBP',
                'invoice_prefix'     => 'INV',
                'default_terms_days' => 14,
                'company_legal_name' => '',
                'company_address'    => '',
                'company_email'      => '',
                'payment_details'    => '',
                'vat_number'         => '',
                'vat_registered'     => 0,
                'default_vat_rate'   => 0,
                'default_notes'      => '',
                'updated_at'         => Clock::nowIso(),
            ]);
        }

        return $row;
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function updateSettings(array $data): array
    {
        $this->settings(); // ensure the record exists
        $updated = $this->store->update('invoice_settings', 'default', static function (array $row) use ($data): array {
            foreach ([
                'company_legal_name', 'company_address', 'company_email', 'payment_details',
                'vat_number', 'currency', 'invoice_prefix', 'default_notes',
            ] as $f) {
                if (array_key_exists($f, $data)) {
                    $row[$f] = (string) $data[$f];
                }
            }
            if (array_key_exists('vat_registered', $data)) {
                $row['vat_registered'] = !empty($data['vat_registered']) ? 1 : 0;
            }
            if (array_key_exists('default_vat_rate', $data)) {
                $row['default_vat_rate'] = (int) $data['default_vat_rate'];
            }
            if (array_key_exists('default_terms_days', $data)) {
                $row['default_terms_days'] = max(0, (int) $data['default_terms_days']);
            }
            $row['updated_at'] = Clock::nowIso();

            return $row;
        });

        return $updated ?? $this->settings();
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
        $rows = $this->store->all('invoices');
        if (!empty($filters['status'])) {
            $rows = array_filter($rows, static fn (array $r): bool => (string) ($r['status'] ?? '') === (string) $filters['status']);
        }
        $rows = array_values($rows);
        usort($rows, static fn ($a, $b) => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));
        $rows = array_slice($rows, 0, (int) ($filters['limit'] ?? 100));

        return array_map(fn (array $r): array => $this->withDerived($r), $rows);
    }

    /** @return array<string,mixed>|null */
    public function find(string $uuid): ?array
    {
        $inv = $this->store->find('invoices', $uuid);
        if ($inv === null) {
            return null;
        }
        $inv = $this->withDerived($inv);

        $items = array_values(array_filter($this->store->all('invoice_items'), static fn (array $r): bool => (string) ($r['invoice_uuid'] ?? '') === $uuid));
        usort($items, static fn ($a, $b) => (int) ($a['position'] ?? 0) <=> (int) ($b['position'] ?? 0));

        $payments = array_values(array_filter($this->store->all('invoice_payments'), static fn (array $r): bool => (string) ($r['invoice_uuid'] ?? '') === $uuid));
        usort($payments, static fn ($a, $b) => strcmp((string) ($b['paid_on'] ?? ''), (string) ($a['paid_on'] ?? '')));

        $events = array_values(array_filter($this->store->all('invoice_events'), static fn (array $r): bool => (string) ($r['invoice_uuid'] ?? '') === $uuid));
        usort($events, static fn ($a, $b) => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));

        $inv['items']    = $items;
        $inv['payments'] = $payments;
        $inv['events']   = $events;

        return $inv;
    }

    /** @return array<string,mixed>|null */
    public function findByToken(string $token): ?array
    {
        if ($token === '') {
            return null;
        }
        foreach ($this->store->all('invoices') as $inv) {
            if ((string) ($inv['public_token'] ?? '') === $token) {
                return $this->find((string) $inv['uuid']);
            }
        }

        return null;
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
        $uuid     = Uuid::v4();
        $now      = Clock::nowIso();
        $settings = $this->settings();

        $this->store->put('invoices', [
            'uuid'              => $uuid,
            'number'            => null,
            'status'            => 'draft',
            'contact_uuid'      => $data['contact_uuid'] ?? null,
            'company_uuid'      => $data['company_uuid'] ?? null,
            'project'           => (string) ($data['project'] ?? ''),
            'project_uuid'      => $data['project_uuid'] ?? null,
            'currency'          => (string) ($settings['currency'] ?? 'GBP'),
            'issue_date'        => null,
            'due_date'          => null,
            'bill_to_name'      => (string) ($data['bill_to_name'] ?? ''),
            'bill_to_address'   => (string) ($data['bill_to_address'] ?? ''),
            'bill_to_email'     => (string) ($data['bill_to_email'] ?? ''),
            'seller_name'       => '',
            'seller_address'    => '',
            'seller_vat_number' => '',
            'vat_registered'    => 0,
            'payment_details'   => '',
            'notes'             => (string) ($data['notes'] ?? (string) ($settings['default_notes'] ?? '')),
            'terms'             => (string) ($data['terms'] ?? ''),
            'subtotal'          => 0,
            'discount_total'    => 0,
            'tax_total'         => 0,
            'total'             => 0,
            'amount_paid'       => 0,
            'public_token'      => null,
            'snapshot'          => null,
            'document_status'   => 'none',
            'issued_at'         => null,
            'sent_at'           => null,
            'viewed_at'         => null,
            'paid_at'           => null,
            'voided_at'         => null,
            'created_by'        => $actor,
            'created_at'        => $now,
            'updated_at'        => $now,
        ]);

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
        $inv = $this->store->find('invoices', $uuid);
        if ($inv === null) {
            throw new InvoiceException(404, 'Invoice not found.');
        }
        if ((string) $inv['status'] !== 'draft') {
            throw new InvoiceException(409, 'Only draft invoices can be edited.');
        }

        $this->store->update('invoices', $uuid, static function (array $row) use ($data): array {
            foreach (['project', 'bill_to_name', 'bill_to_address', 'bill_to_email', 'notes', 'terms', 'contact_uuid', 'company_uuid', 'due_date'] as $f) {
                if (array_key_exists($f, $data)) {
                    $row[$f] = $data[$f] === null ? null : (string) $data[$f];
                }
            }
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
    // Issue / send / payments / void
    // ==================================================================

    /** @return array<string,mixed> */
    public function issue(string $uuid, string $actor): array
    {
        $inv = $this->store->find('invoices', $uuid);
        if ($inv === null) {
            throw new InvoiceException(404, 'Invoice not found.');
        }
        if ((string) $inv['status'] !== 'draft') {
            throw new InvoiceException(409, 'This invoice has already been issued.');
        }
        $itemCount = count(array_filter($this->store->all('invoice_items'), static fn (array $r): bool => (string) ($r['invoice_uuid'] ?? '') === $uuid));
        if ($itemCount === 0) {
            throw new InvoiceException(422, 'Add at least one line item before issuing.');
        }

        $settings  = $this->settings();
        $prefix    = (string) ($settings['invoice_prefix'] ?? 'INV');
        $year      = (int) date('Y');
        $number    = $this->allocateNumber($prefix, $year);
        $issueDate = date('Y-m-d');
        $dueDate   = date('Y-m-d', time() + max(0, (int) ($settings['default_terms_days'] ?? 14)) * 86400);
        $token     = bin2hex(random_bytes(20));
        $now       = Clock::nowIso();

        $this->store->update('invoices', $uuid, static function (array $row) use ($number, $issueDate, $dueDate, $settings, $token, $now): array {
            $row['number']            = $number;
            $row['status']            = 'issued';
            $row['issue_date']        = $issueDate;
            $row['due_date']          = $dueDate;
            $row['seller_name']       = (string) ($settings['company_legal_name'] ?? '');
            $row['seller_address']    = (string) ($settings['company_address'] ?? '');
            $row['seller_vat_number'] = (string) ($settings['vat_number'] ?? '');
            $row['vat_registered']    = (int) ($settings['vat_registered'] ?? 0);
            $row['payment_details']   = (string) ($settings['payment_details'] ?? '');
            $row['public_token']      = $token;
            $row['issued_at']         = $now;
            $row['updated_at']        = $now;

            return $row;
        });
        $this->event($uuid, 'issued', 'Issued as ' . $number, $actor);

        // Freeze the immutable financial snapshot and mark the document as
        // pending generation. The PDF is rendered from THIS snapshot, never from
        // live settings/client data, so the issued document is stable.
        $detail   = $this->find($uuid) ?? [];
        $snapshot = InvoiceSnapshot::build($detail, $settings);
        $this->store->update('invoices', $uuid, static function (array $row) use ($snapshot): array {
            $row['snapshot']        = $snapshot;
            $row['document_status'] = 'pending';
            $row['updated_at']      = Clock::nowIso();

            return $row;
        });

        return $this->find($uuid) ?? [];
    }

    /**
     * The frozen snapshot for an issued invoice, or null if not yet issued.
     *
     * @return array<string,mixed>|null
     */
    public function snapshot(string $uuid): ?array
    {
        $inv = $this->store->find('invoices', $uuid);
        $snap = $inv['snapshot'] ?? null;

        return is_array($snap) && $snap !== [] ? $snap : null;
    }

    public function setDocumentStatus(string $uuid, string $status): void
    {
        $this->store->update('invoices', $uuid, static function (array $row) use ($status): array {
            $row['document_status'] = $status;
            $row['updated_at']      = Clock::nowIso();

            return $row;
        });
    }

    /**
     * Issued invoices whose PDF has not been generated yet — everything with a
     * frozen snapshot that is not already marked 'generated'.
     *
     * @return list<array<string,mixed>>
     */
    public function needingPdfBackfill(): array
    {
        $rows = array_values(array_filter($this->store->all('invoices'), static function (array $r): bool {
            $snap = $r['snapshot'] ?? null;

            return is_array($snap) && $snap !== [] && (string) ($r['document_status'] ?? 'none') !== 'generated';
        }));
        usort($rows, static fn ($a, $b) => [(string) ($a['issue_date'] ?? ''), (string) ($a['number'] ?? '')] <=> [(string) ($b['issue_date'] ?? ''), (string) ($b['number'] ?? '')]);

        return array_map(static fn (array $r): array => [
            'uuid'            => $r['uuid'] ?? '',
            'number'          => $r['number'] ?? '',
            'document_status' => $r['document_status'] ?? 'none',
        ], $rows);
    }

    /** Record an immutable invoice event (public wrapper for the document service). */
    public function logEvent(string $uuid, string $type, string $detail, string $actor): void
    {
        $this->event($uuid, $type, $detail, $actor);
    }

    public function markSent(string $uuid, string $actor, string $detail): void
    {
        $now = Clock::nowIso();
        $this->store->update('invoices', $uuid, static function (array $row) use ($now): array {
            if ((string) ($row['status'] ?? '') === 'issued') {
                $row['status'] = 'sent';
            }
            $row['sent_at']    = $row['sent_at'] ?? $now;
            $row['updated_at'] = $now;

            return $row;
        });
        $this->event($uuid, 'sent', $detail, $actor);
    }

    public function markViewed(string $uuid): void
    {
        $now = Clock::nowIso();
        $this->store->update('invoices', $uuid, static function (array $row) use ($now): array {
            if (in_array((string) ($row['status'] ?? ''), ['issued', 'sent'], true)) {
                $row['status'] = 'viewed';
            }
            $row['viewed_at']  = $row['viewed_at'] ?? $now;
            $row['updated_at'] = $now;

            return $row;
        });
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function recordPayment(string $uuid, array $data, string $actor): array
    {
        $inv = $this->store->find('invoices', $uuid);
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

        $this->store->put('invoice_payments', [
            'uuid'         => Uuid::v4(),
            'invoice_uuid' => $uuid,
            'amount'       => $amount,
            'paid_on'      => (string) ($data['paid_on'] ?? date('Y-m-d')),
            'method'       => (string) ($data['method'] ?? ''),
            'reference'    => (string) ($data['reference'] ?? ''),
            'note'         => (string) ($data['note'] ?? ''),
            'created_by'   => $actor,
            'created_at'   => Clock::nowIso(),
        ]);

        $this->store->update('invoices', $uuid, static function (array $row) use ($amount): array {
            $paid  = (int) ($row['amount_paid'] ?? 0) + $amount;
            $total = (int) ($row['total'] ?? 0);
            $status = $paid >= $total && $total > 0 ? 'paid' : 'partial';
            $row['amount_paid'] = $paid;
            $row['status']      = $status;
            $row['paid_at']     = $status === 'paid' ? Clock::nowIso() : ($row['paid_at'] ?? null);
            $row['updated_at']  = Clock::nowIso();

            return $row;
        });
        $this->event($uuid, 'payment', 'Payment recorded: ' . $this->money($amount), $actor);

        return $this->find($uuid) ?? [];
    }

    /** @return array<string,mixed> */
    public function void(string $uuid, string $actor): array
    {
        $inv = $this->store->find('invoices', $uuid);
        if ($inv === null) {
            throw new InvoiceException(404, 'Invoice not found.');
        }
        if ((string) $inv['status'] === 'paid') {
            throw new InvoiceException(409, 'A paid invoice cannot be voided.');
        }
        $now = Clock::nowIso();
        $this->store->update('invoices', $uuid, static function (array $row) use ($now): array {
            $row['status']     = 'void';
            $row['voided_at']  = $now;
            $row['updated_at'] = $now;

            return $row;
        });
        $this->event($uuid, 'void', 'Invoice voided', $actor);

        return $this->find($uuid) ?? [];
    }

    /**
     * Link an invoice to a project (used by conversion from proposals /
     * retainers). No-op if the invoice is gone.
     */
    public function assignToProject(string $invoiceUuid, ?string $projectUuid): void
    {
        $this->store->update('invoices', $invoiceUuid, static function (array $row) use ($projectUuid): array {
            $row['project_uuid'] = $projectUuid;
            $row['updated_at']   = Clock::nowIso();

            return $row;
        });
    }

    /**
     * All invoice records linked to a project (raw, no items/events attached).
     *
     * @return list<array<string,mixed>>
     */
    public function forProject(string $projectUuid): array
    {
        return array_values(array_filter(
            $this->store->all('invoices'),
            static fn (array $r): bool => (string) ($r['project_uuid'] ?? '') === $projectUuid
        ));
    }

    // ==================================================================
    // Internals
    // ==================================================================

    private function allocateNumber(string $prefix, int $year): string
    {
        $seq = $this->store->bump('invoice_sequences', $prefix . '-' . $year);

        return sprintf('%s-%d-%04d', $prefix, $year, $seq);
    }

    /** @param array<int,mixed> $items */
    private function replaceItems(string $invoiceUuid, array $items): void
    {
        foreach ($this->store->all('invoice_items') as $existing) {
            if ((string) ($existing['invoice_uuid'] ?? '') === $invoiceUuid) {
                $this->store->delete('invoice_items', (string) ($existing['uuid'] ?? ''));
            }
        }

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

            $this->store->put('invoice_items', [
                'uuid'          => Uuid::v4(),
                'invoice_uuid'  => $invoiceUuid,
                'position'      => $pos++,
                'description'   => $description,
                'quantity'      => $qty,
                'unit_price'    => $unit,
                'tax_rate'      => $taxRate,
                'discount'      => $discount,
                'line_subtotal' => $subtotal,
                'line_tax'      => $tax,
                'line_total'    => $subtotal + $tax,
            ]);
        }
    }

    private function recompute(string $uuid): void
    {
        $sub = 0;
        $tax = 0;
        foreach ($this->store->all('invoice_items') as $item) {
            if ((string) ($item['invoice_uuid'] ?? '') === $uuid) {
                $sub += (int) ($item['line_subtotal'] ?? 0);
                $tax += (int) ($item['line_tax'] ?? 0);
            }
        }
        $this->store->update('invoices', $uuid, static function (array $row) use ($sub, $tax): array {
            $row['subtotal']   = $sub;
            $row['tax_total']  = $tax;
            $row['total']      = $sub + $tax;
            $row['updated_at'] = Clock::nowIso();

            return $row;
        });
    }

    /**
     * Add derived fields (amount_due, overdue flag) without persisting them.
     *
     * @param array<string,mixed> $r
     * @return array<string,mixed>
     */
    private function withDerived(array $r): array
    {
        $r['amount_due'] = (int) ($r['total'] ?? 0) - (int) ($r['amount_paid'] ?? 0);
        $overdue = in_array((string) ($r['status'] ?? ''), ['issued', 'sent', 'viewed', 'partial'], true)
            && !empty($r['due_date']) && strtotime((string) $r['due_date']) !== false
            && strtotime((string) $r['due_date']) < strtotime(date('Y-m-d'));
        $r['overdue'] = $overdue;

        return $r;
    }

    private function event(string $uuid, string $type, string $detail, string $actor): void
    {
        $this->store->put('invoice_events', [
            'uuid'         => Uuid::v4(),
            'invoice_uuid' => $uuid,
            'type'         => $type,
            'detail'       => $detail,
            'actor'        => $actor,
            'created_at'   => Clock::nowIso(),
        ]);
    }

    private function money(int $pence): string
    {
        return '£' . number_format($pence / 100, 2);
    }
}
