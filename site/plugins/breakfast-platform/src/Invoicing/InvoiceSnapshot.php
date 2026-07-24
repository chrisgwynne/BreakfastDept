<?php

declare(strict_types=1);

namespace Breakfast\Platform\Invoicing;

/**
 * Builds the immutable financial snapshot captured when an invoice is issued.
 *
 * The snapshot freezes the seller identity, customer details, line items, tax
 * treatment, totals, terms and payment instructions so the issued document can
 * always be reproduced byte-for-byte, regardless of later changes to company
 * settings, the client record or branding.
 */
final class InvoiceSnapshot
{
    public const VERSION = 1;

    /**
     * @param array<string,mixed> $invoice the invoice detail (already seller-snapshotted at issue)
     * @param array<string,mixed> $settings invoice_settings row
     * @return array<string,mixed>
     */
    public static function build(array $invoice, array $settings): array
    {
        $items = [];
        foreach (is_array($invoice['items'] ?? null) ? $invoice['items'] : [] as $it) {
            $items[] = [
                'description'   => (string) ($it['description'] ?? ''),
                'quantity'      => (int) ($it['quantity'] ?? 0),
                'unit_price'    => (int) ($it['unit_price'] ?? 0),
                'tax_rate'      => (int) ($it['tax_rate'] ?? 0),
                'discount'      => (int) ($it['discount'] ?? 0),
                'line_subtotal' => (int) ($it['line_subtotal'] ?? 0),
                'line_tax'      => (int) ($it['line_tax'] ?? 0),
                'line_total'    => (int) ($it['line_total'] ?? 0),
            ];
        }

        $vatReg = !empty($invoice['vat_registered']) || !empty($settings['vat_registered']);

        return [
            'snapshot_version' => self::VERSION,
            'number'      => (string) ($invoice['number'] ?? ''),
            'status'      => (string) ($invoice['status'] ?? 'issued'),
            'currency'    => (string) ($invoice['currency'] ?? ($settings['currency'] ?? 'GBP')),
            'issue_date'  => (string) ($invoice['issue_date'] ?? ''),
            'due_date'    => (string) ($invoice['due_date'] ?? ''),
            'project'     => (string) ($invoice['project'] ?? ''),
            'vat_registered' => $vatReg,
            'seller' => [
                'name'           => (string) ($invoice['seller_name'] ?? $settings['company_legal_name'] ?? 'Breakfast'),
                'address'        => (string) ($invoice['seller_address'] ?? $settings['company_address'] ?? ''),
                'email'          => (string) ($settings['company_email'] ?? ''),
                'phone'          => (string) ($settings['company_phone'] ?? ''),
                'company_number' => (string) ($settings['company_number'] ?? ''),
                'vat_number'     => (string) ($invoice['seller_vat_number'] ?? $settings['vat_number'] ?? ''),
                'payment_details' => (string) ($invoice['payment_details'] ?? $settings['payment_details'] ?? ''),
            ],
            'bill_to' => [
                'name'    => (string) ($invoice['bill_to_name'] ?? ''),
                'address' => (string) ($invoice['bill_to_address'] ?? ''),
                'email'   => (string) ($invoice['bill_to_email'] ?? ''),
            ],
            'items'          => $items,
            'subtotal'       => (int) ($invoice['subtotal'] ?? 0),
            'discount_total' => (int) ($invoice['discount_total'] ?? 0),
            'tax_total'      => (int) ($invoice['tax_total'] ?? 0),
            'total'          => (int) ($invoice['total'] ?? 0),
            'amount_paid'    => (int) ($invoice['amount_paid'] ?? 0),
            'amount_due'     => (int) ($invoice['total'] ?? 0) - (int) ($invoice['amount_paid'] ?? 0),
            'notes'          => (string) ($invoice['notes'] ?? ''),
            'terms'          => (string) ($invoice['terms'] ?? ''),
        ];
    }
}
