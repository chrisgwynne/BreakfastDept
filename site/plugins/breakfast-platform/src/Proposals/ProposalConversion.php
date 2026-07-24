<?php

declare(strict_types=1);

namespace Breakfast\Platform\Proposals;

use Breakfast\Platform\Support\Platform;

/**
 * Turns an accepted proposal into downstream commercial records in one
 * transactional step: mark the linked opportunity won, and raise a draft deposit
 * invoice for the agreed deposit. Each conversion is opt-in (the caller chooses
 * which to run) and idempotent-ish — re-running skips work already done — and
 * everything is recorded on the CRM timeline. Projects and contracts are wired
 * in as those modules land.
 */
final class ProposalConversion
{
    public function __construct(private readonly Platform $platform)
    {
    }

    /**
     * @param list<string> $steps subset of ['won','deposit']
     * @return array{opportunity:?string,invoice:?string,steps:list<string>}
     */
    public function convert(string $uuid, array $steps, string $actor): array
    {
        $proposal = $this->platform->proposals()->find($uuid);
        if ($proposal === null) {
            throw new ProposalException(404, 'Proposal not found.');
        }
        if ((string) $proposal['status'] !== 'accepted') {
            throw new ProposalException(409, 'Only an accepted proposal can be converted.');
        }

        /** @var array{opportunity:?string,invoice:?string,steps:list<string>} $result */
        $result = $this->platform->db()->transaction(function () use ($proposal, $uuid, $steps, $actor): array {
            $done = [];
            $oppUuid = $this->nullable($proposal['opportunity_uuid'] ?? null);
            $invoiceUuid = null;

            if (in_array('won', $steps, true) && $oppUuid !== null) {
                $opp = $this->platform->opportunities()->find($oppUuid);
                if ($opp !== null && (string) ($opp['stage'] ?? '') !== 'won') {
                    $this->platform->crm()->moveOpportunity($oppUuid, 'won', 'user', $actor);
                    $this->platform->proposals()->logEvent($uuid, 'converted', 'Opportunity marked won', $actor);
                    $done[] = 'won';
                }
            }

            if (in_array('deposit', $steps, true) && (int) ($proposal['deposit_amount'] ?? 0) > 0) {
                $deposit = (int) $proposal['deposit_amount'];
                $number  = (string) ($proposal['number'] ?? '');
                $created = $this->platform->invoices()->create([
                    'contact_uuid'  => $this->nullable($proposal['contact_uuid'] ?? null),
                    'company_uuid'  => $this->nullable($proposal['company_uuid'] ?? null),
                    'bill_to_name'  => (string) ($proposal['client_name'] ?? ''),
                    'bill_to_email' => (string) ($proposal['client_email'] ?? ''),
                    'project'       => (string) ($proposal['title'] ?? ''),
                    // Deposit taken on account (0% here); VAT is reconciled on the
                    // final invoice. Amount is the agreed gross deposit.
                    'items' => [[
                        'description' => 'Deposit for ' . (string) ($proposal['title'] ?? 'project') . ' (Proposal ' . $number . ')',
                        'quantity'    => 1,
                        'unit_price'  => $deposit / 100,
                        'tax_rate'    => 0,
                    ]],
                ], $actor);
                $invoiceUuid = (string) ($created['uuid'] ?? '');
                $this->platform->proposals()->logEvent($uuid, 'deposit_invoiced', 'Deposit invoice drafted (£' . number_format($deposit / 100, 2) . ')', $actor);
                $done[] = 'deposit';
            }

            // Timeline note on the contact.
            $contact = $this->nullable($proposal['contact_uuid'] ?? null);
            if ($contact !== null) {
                $this->platform->activities()->record('contact', $contact, 'proposal.converted', 'Proposal ' . (string) ($proposal['number'] ?? '') . ' converted (' . implode(', ', $done) . ')', 'user', $actor, ['proposal' => $uuid]);
            }

            return ['opportunity' => $oppUuid, 'invoice' => $invoiceUuid, 'steps' => $done];
        });

        return $result;
    }

    private function nullable(mixed $value): ?string
    {
        $v = trim((string) ($value ?? ''));

        return $v === '' ? null : $v;
    }
}
