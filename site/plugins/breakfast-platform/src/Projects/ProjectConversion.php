<?php

declare(strict_types=1);

namespace Breakfast\Platform\Projects;

use Breakfast\Platform\Support\Platform;

/**
 * Creates a project from the commercial workflow (accepted proposal, completed
 * contract, successful deposit payment, won opportunity) or manually.
 *
 * The preferred path is proposal-accepted → contract-completed → deposit-paid →
 * project, but this is a POLICY, not a hard-coded rule: the caller may relax any
 * requirement for engagements that legitimately skip a commercial step. The
 * requirement check is explicit and returns a clear reason when unmet.
 */
final class ProjectConversion
{
    public function __construct(private readonly Platform $platform)
    {
    }

    /**
     * Build a project from an accepted proposal, pulling links + non-frozen
     * metadata from the proposal and (when present) its contract and opportunity.
     *
     * @param array<string,mixed> $options require_contract, require_deposit (bools); overrides (array)
     * @return array<string,mixed>
     */
    public function fromProposal(string $proposalUuid, array $options, string $actor): array
    {
        $proposal = $this->platform->proposals()->find($proposalUuid);
        if ($proposal === null) {
            throw new ProjectException(404, 'Proposal not found.');
        }
        if ((string) $proposal['status'] !== 'accepted') {
            throw new ProjectException(409, 'Only an accepted proposal can start a project.');
        }
        $this->guardDuplicate('proposal_uuid', $proposalUuid);

        // Optional commercial gates.
        $contract = $this->latestContractForProposal($proposalUuid);
        if (!empty($options['require_contract'])) {
            if ($contract === null || (string) $contract['status'] !== 'signed') {
                throw new ProjectException(409, 'A completed (signed) contract is required before starting this project.');
            }
        }
        if (!empty($options['require_deposit']) && !$this->depositPaidForProposal($proposalUuid)) {
            throw new ProjectException(409, 'The deposit must be paid before starting this project.');
        }

        $overrides = is_array($options['overrides'] ?? null) ? $options['overrides'] : [];
        $data = array_merge([
            'name'             => (string) ($proposal['title'] ?? 'Project'),
            'company_uuid'     => $proposal['company_uuid'] ?? null,
            'contact_uuid'     => $proposal['contact_uuid'] ?? null,
            'opportunity_uuid' => $proposal['opportunity_uuid'] ?? null,
            'proposal_uuid'    => $proposalUuid,
            'contract_uuid'    => $contract['uuid'] ?? null,
            'quoted_value'     => (int) ($proposal['total'] ?? 0) / 100,
            'client_summary'   => (string) ($proposal['introduction'] ?? ''),
            'scope'            => (string) ($proposal['scope'] ?? ''),
            'status'           => 'planning',
        ], $overrides);

        return $this->platform->projects()->create($data, $actor);
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function fromContract(string $contractUuid, array $options, string $actor): array
    {
        $contract = $this->platform->contracts()->find($contractUuid);
        if ($contract === null) {
            throw new ProjectException(404, 'Contract not found.');
        }
        if ((string) $contract['status'] !== 'signed') {
            throw new ProjectException(409, 'Only a completed (signed) contract can start a project.');
        }
        $this->guardDuplicate('contract_uuid', $contractUuid);

        $overrides = is_array($options['overrides'] ?? null) ? $options['overrides'] : [];
        $data = array_merge([
            'name'             => (string) ($contract['title'] ?? 'Project'),
            'company_uuid'     => $contract['company_uuid'] ?? null,
            'contact_uuid'     => $contract['contact_uuid'] ?? null,
            'opportunity_uuid' => $contract['opportunity_uuid'] ?? null,
            'proposal_uuid'    => $contract['proposal_uuid'] ?? null,
            'contract_uuid'    => $contractUuid,
            'quoted_value'     => (int) ($contract['contract_value'] ?? 0) / 100,
            'status'           => 'planning',
        ], $overrides);

        return $this->platform->projects()->create($data, $actor);
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function fromOpportunity(string $opportunityUuid, array $options, string $actor): array
    {
        $opp = $this->platform->opportunities()->find($opportunityUuid);
        if ($opp === null) {
            throw new ProjectException(404, 'Opportunity not found.');
        }
        if (!empty($options['require_won']) && (string) ($opp['stage'] ?? '') !== 'won') {
            throw new ProjectException(409, 'This opportunity must be won before starting a project.');
        }
        $this->guardDuplicate('opportunity_uuid', $opportunityUuid);

        $overrides = is_array($options['overrides'] ?? null) ? $options['overrides'] : [];
        $data = array_merge([
            'name'             => (string) ($opp['title'] ?? 'Project'),
            'company_uuid'     => $opp['company_uuid'] ?? null,
            'contact_uuid'     => $opp['contact_uuid'] ?? null,
            'opportunity_uuid' => $opportunityUuid,
            'quoted_value'     => (int) ($opp['estimated_value'] ?? 0) / 100,
            'status'           => 'planning',
        ], $overrides);

        return $this->platform->projects()->create($data, $actor);
    }

    // ------------------------------------------------------------------

    /** @return array<string,mixed>|null */
    private function latestContractForProposal(string $proposalUuid): ?array
    {
        $matches = array_values(array_filter(
            $this->platform->contracts()->list(['limit' => 200]),
            static fn (array $c): bool => (string) ($c['proposal_uuid'] ?? '') === $proposalUuid
        ));

        return $matches[0] ?? null;
    }

    private function depositPaidForProposal(string $proposalUuid): bool
    {
        foreach ($this->platform->invoices()->list(['limit' => 500]) as $inv) {
            $full = $this->platform->invoices()->find((string) $inv['uuid']);
            if ($full === null) {
                continue;
            }
            // A deposit invoice raised from this proposal that is (part-)paid.
            if (str_contains((string) ($full['notes'] ?? ''), $proposalUuid) || str_contains((string) ($full['project'] ?? ''), $proposalUuid)) {
                if (in_array((string) $full['status'], ['partial', 'paid'], true)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function guardDuplicate(string $column, string $value): void
    {
        $existing = $this->platform->db()->one(
            "SELECT uuid FROM projects WHERE {$column} = :v AND archived = 0",
            ['v' => $value]
        );
        if ($existing !== null) {
            throw new ProjectException(409, 'A project already exists for this record.');
        }
    }
}
