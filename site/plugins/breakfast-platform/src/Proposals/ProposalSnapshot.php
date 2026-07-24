<?php

declare(strict_types=1);

namespace Breakfast\Platform\Proposals;

/**
 * The immutable snapshot frozen when a proposal is sent. Captures the seller
 * identity, client details, narrative sections, items (with kinds) and totals so
 * the hosted page and PDF always reproduce exactly what the client was sent and
 * accepted, regardless of later settings/record changes.
 */
final class ProposalSnapshot
{
    public const VERSION = 1;

    /**
     * @param array<string,mixed> $proposal proposal detail (with items)
     * @param array<string,mixed> $settings invoice_settings row
     * @return array<string,mixed>
     */
    public static function build(array $proposal, array $settings): array
    {
        $items = [];
        foreach (is_array($proposal['items'] ?? null) ? $proposal['items'] : [] as $it) {
            $items[] = [
                'kind'          => (string) ($it['kind'] ?? 'fixed'),
                'description'   => (string) ($it['description'] ?? ''),
                'quantity'      => (int) ($it['quantity'] ?? 0),
                'unit_price'    => (int) ($it['unit_price'] ?? 0),
                'tax_rate'      => (int) ($it['tax_rate'] ?? 0),
                'discount'      => (int) ($it['discount'] ?? 0),
                'recurrence'    => (string) ($it['recurrence'] ?? ''),
                'is_selected'   => (int) ($it['is_selected'] ?? 0),
                'line_subtotal' => (int) ($it['line_subtotal'] ?? 0),
                'line_tax'      => (int) ($it['line_tax'] ?? 0),
                'line_total'    => (int) ($it['line_total'] ?? 0),
            ];
        }

        return [
            'snapshot_version' => self::VERSION,
            'number'   => (string) ($proposal['number'] ?? ''),
            'title'    => (string) ($proposal['title'] ?? ''),
            'currency' => (string) ($proposal['currency'] ?? ($settings['currency'] ?? 'GBP')),
            'vat_registered' => !empty($proposal['vat_registered']) || !empty($settings['vat_registered']),
            'expiry_date' => (string) ($proposal['expiry_date'] ?? ''),
            'seller' => [
                'name'    => (string) ($proposal['seller_name'] ?? $settings['company_legal_name'] ?? 'Breakfast'),
                'address' => (string) ($settings['company_address'] ?? ''),
                'email'   => (string) ($settings['company_email'] ?? ''),
            ],
            'client' => [
                'name'  => (string) ($proposal['client_name'] ?? ''),
                'email' => (string) ($proposal['client_email'] ?? ''),
            ],
            'sections' => [
                'introduction'         => (string) ($proposal['introduction'] ?? ''),
                'client_problem'       => (string) ($proposal['client_problem'] ?? ''),
                'recommended_solution' => (string) ($proposal['recommended_solution'] ?? ''),
                'scope'                => (string) ($proposal['scope'] ?? ''),
                'deliverables'         => (string) ($proposal['deliverables'] ?? ''),
                'exclusions'           => (string) ($proposal['exclusions'] ?? ''),
                'assumptions'          => (string) ($proposal['assumptions'] ?? ''),
                'timeline'             => (string) ($proposal['timeline'] ?? ''),
                'payment_schedule'     => (string) ($proposal['payment_schedule'] ?? ''),
                'terms'                => (string) ($proposal['terms'] ?? ''),
            ],
            'items'          => $items,
            'subtotal'       => (int) ($proposal['subtotal'] ?? 0),
            'tax_total'      => (int) ($proposal['tax_total'] ?? 0),
            'total'          => (int) ($proposal['total'] ?? 0),
            'deposit_amount' => (int) ($proposal['deposit_amount'] ?? 0),
            'recurring_total' => (int) ($proposal['recurring_total'] ?? 0),
        ];
    }
}
