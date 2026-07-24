<?php

declare(strict_types=1);

namespace Breakfast\Platform\Contracts;

/**
 * The immutable contract version frozen when a contract is sent. Captures the
 * resolved clause text (merge fields already applied), party details, pricing
 * references and identity so the unsigned + signed PDFs always reproduce exactly
 * what the parties agreed, regardless of later record changes.
 */
final class ContractSnapshot
{
    public const VERSION = 1;

    /**
     * @param array<string,mixed> $contract raw contract row
     * @param list<array<string,mixed>> $resolvedSections merge-resolved, enabled sections
     * @param array<string,string> $mergeValues the values used (for the record)
     * @param list<array<string,mixed>> $parties
     * @return array<string,mixed>
     */
    public static function build(array $contract, array $resolvedSections, array $mergeValues, array $parties): array
    {
        return [
            'snapshot_version' => self::VERSION,
            'number'    => (string) ($contract['number'] ?? ''),
            'title'     => (string) ($contract['title'] ?? ''),
            'currency'  => (string) ($contract['currency'] ?? 'GBP'),
            'seller_name' => (string) ($contract['seller_name'] ?? 'Breakfast'),
            'client_name' => (string) ($contract['client_name'] ?? ''),
            'client_email' => (string) ($contract['client_email'] ?? ''),
            'contract_value' => (int) ($contract['contract_value'] ?? 0),
            'deposit_amount' => (int) ($contract['deposit_amount'] ?? 0),
            'effective_date' => (string) ($contract['effective_date'] ?? ''),
            'expiry_date'    => (string) ($contract['expiry_date'] ?? ''),
            'governing_law'  => (string) ($contract['governing_law'] ?? ''),
            'signature_wording' => (string) ($contract['signature_wording'] ?? ''),
            'sections'  => array_map(static fn (array $s): array => [
                'heading' => (string) ($s['heading'] ?? ''),
                'body'    => (string) ($s['body'] ?? ''),
            ], $resolvedSections),
            'parties'   => array_map(static fn (array $p): array => [
                'role'  => (string) ($p['role'] ?? 'client'),
                'name'  => (string) ($p['name'] ?? ''),
                'email' => (string) ($p['email'] ?? ''),
                'order' => (int) ($p['signing_order'] ?? 1),
                'required' => (int) ($p['required'] ?? 1) === 1,
            ], $parties),
            'merge_values' => $mergeValues,
        ];
    }
}
