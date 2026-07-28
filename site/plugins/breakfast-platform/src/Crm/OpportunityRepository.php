<?php

declare(strict_types=1);

namespace Breakfast\Platform\Crm;

use Breakfast\Platform\Support\Clock;
use Breakfast\Platform\Support\FileRepository;
use Breakfast\Platform\Support\Uuid;

final class OpportunityRepository extends FileRepository
{
    private const COLUMNS = [
        'title', 'contact_uuid', 'company_uuid', 'enquiry_uuid', 'stage',
        'estimated_value', 'currency', 'probability', 'services', 'expected_close_date',
        'next_action', 'next_action_date', 'owner', 'lost_reason', 'won_at', 'lost_at', 'notes',
        'archived_at', 'close_outcome',
    ];

    protected function collection(): string
    {
        return 'opportunities';
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function create(array $data): array
    {
        $now = $this->now();

        return $this->persist([
            'uuid'                => Uuid::v4(),
            'title'               => (string) ($data['title'] ?? 'New opportunity'),
            'contact_uuid'        => $data['contact_uuid'] ?? null,
            'company_uuid'        => $data['company_uuid'] ?? null,
            'enquiry_uuid'        => $data['enquiry_uuid'] ?? null,
            'stage'               => $data['stage'] ?? 'new',
            'estimated_value'     => (int) ($data['estimated_value'] ?? 0),
            'currency'            => $data['currency'] ?? 'GBP',
            'probability'         => (int) ($data['probability'] ?? 0),
            'services'            => is_array($data['services'] ?? null) ? $data['services'] : [],
            'expected_close_date' => $data['expected_close_date'] ?? null,
            'next_action'         => $data['next_action'] ?? null,
            'next_action_date'    => $data['next_action_date'] ?? null,
            'owner'               => $data['owner'] ?? null,
            'lost_reason'         => null,
            'won_at'              => null,
            'lost_at'             => null,
            'notes'               => $data['notes'] ?? null,
            'archived_at'         => null,
            'close_outcome'       => null,
            'created_at'          => $now,
            'updated_at'          => $now,
        ]);
    }

    /** @return array<string,mixed>|null */
    public function find(string $uuid): ?array
    {
        return $this->findRecord($uuid);
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>|null
     */
    public function update(string $uuid, array $data): ?array
    {
        if (array_key_exists('services', $data) && !is_array($data['services'])) {
            $data['services'] = [];
        }

        return $this->patch($uuid, $data, self::COLUMNS);
    }

    /**
     * @param array{stage?:string,owner?:string,search?:string,open?:bool,include_archived?:bool,limit?:int,offset?:int} $filters
     * @return array<int,array<string,mixed>>
     */
    public function search(array $filters = []): array
    {
        $rows      = $this->records();
        $contacts  = $this->indexBy('contacts');
        $companies = $this->indexBy('companies');

        if (!empty($filters['stage'])) {
            $rows = array_filter($rows, fn (array $r): bool => ($r['stage'] ?? null) === $filters['stage']);
        }

        if (!empty($filters['owner'])) {
            $rows = array_filter($rows, fn (array $r): bool => ($r['owner'] ?? null) === $filters['owner']);
        }

        if (!empty($filters['open'])) {
            $rows = array_filter($rows, fn (array $r): bool => !in_array($r['stage'] ?? null, ['won', 'lost'], true));
        }

        // Archived opportunities are hidden from the active pipeline unless
        // explicitly requested (e.g. an archive view).
        if (empty($filters['include_archived'])) {
            $rows = array_filter($rows, fn (array $r): bool => ($r['archived_at'] ?? null) === null);
        }

        if (!empty($filters['search'])) {
            $needle = strtolower((string) $filters['search']);
            $rows   = array_filter($rows, function (array $r) use ($needle, $contacts): bool {
                $contact = $contacts[(string) ($r['contact_uuid'] ?? '')] ?? [];

                return $this->contains($r['title'] ?? '', $needle)
                    || $this->contains($contact['display_name'] ?? '', $needle);
            });
        }

        $rows = $this->sortBy(array_values($rows), 'updated_at', 'desc');
        $rows = $this->joinNames($rows, $contacts, $companies);

        return $this->paginate($rows, (int) ($filters['limit'] ?? 200), (int) ($filters['offset'] ?? 0));
    }

    public function countOpen(): int
    {
        return count($this->openOpportunities());
    }

    /** Total estimated value (minor units) of open opportunities. */
    public function openPipelineValue(): int
    {
        $sum = 0;
        foreach ($this->openOpportunities() as $row) {
            $sum += (int) ($row['estimated_value'] ?? 0);
        }

        return $sum;
    }

    /**
     * Soft-archive an opportunity (kept, hidden from the pipeline, restorable).
     *
     * @return array<string,mixed>|null
     */
    public function archive(string $uuid, ?string $outcome = null): ?array
    {
        $record = $this->findRecord($uuid);
        if ($record === null || ($record['archived_at'] ?? null) !== null) {
            return $record;
        }

        $patch = ['archived_at' => $this->now()];
        if ($outcome !== null) {
            $patch['close_outcome'] = $outcome;
        }

        return $this->patch($uuid, $patch, ['archived_at', 'close_outcome']);
    }

    /**
     * Restore an archived opportunity back into the active pipeline.
     *
     * @return array<string,mixed>|null
     */
    public function restore(string $uuid): ?array
    {
        return $this->patch($uuid, ['archived_at' => null, 'close_outcome' => null], ['archived_at', 'close_outcome']);
    }

    /** @return array<string,array{count:int,value:int}> keyed by stage */
    public function pipelineByStage(): array
    {
        $out = [];
        foreach ($this->openOpportunities() as $row) {
            $stage = (string) ($row['stage'] ?? '');
            if (!isset($out[$stage])) {
                $out[$stage] = ['count' => 0, 'value' => 0];
            }
            $out[$stage]['count']++;
            $out[$stage]['value'] += (int) ($row['estimated_value'] ?? 0);
        }

        return $out;
    }

    /**
     * Opportunities that have had no next action set or are past their next
     * action date — used by Hermes "find stale opportunities".
     *
     * @return array<int,array<string,mixed>>
     */
    public function stale(int $days = 21): array
    {
        $cutoff   = Clock::now()->modify('-' . $days . ' days')->format('c');
        $now      = Clock::nowIso();
        $contacts = $this->indexBy('contacts');

        $rows = array_filter($this->openOpportunities(), function (array $r) use ($cutoff, $now): bool {
            $nextAction = $r['next_action_date'] ?? null;
            $overdue    = $nextAction === null || (string) $nextAction < $now;

            return $overdue && (string) ($r['updated_at'] ?? '') < $cutoff;
        });

        $rows = $this->sortBy(array_values($rows), 'updated_at', 'asc');

        foreach ($rows as &$row) {
            $row['contact_name'] = $contacts[(string) ($row['contact_uuid'] ?? '')]['display_name'] ?? null;
        }
        unset($row);

        return $rows;
    }

    /**
     * Open (non-won, non-lost, non-archived) opportunities.
     *
     * @return list<array<string,mixed>>
     */
    private function openOpportunities(): array
    {
        return array_values(array_filter(
            $this->records(),
            fn (array $r): bool => !in_array($r['stage'] ?? null, ['won', 'lost'], true)
                && ($r['archived_at'] ?? null) === null
        ));
    }

    /**
     * @param list<array<string,mixed>>           $rows
     * @param array<string,array<string,mixed>>   $contacts
     * @param array<string,array<string,mixed>>   $companies
     * @return list<array<string,mixed>>
     */
    private function joinNames(array $rows, array $contacts, array $companies): array
    {
        foreach ($rows as &$row) {
            $row['contact_name'] = $contacts[(string) ($row['contact_uuid'] ?? '')]['display_name'] ?? null;
            $row['company_name'] = $companies[(string) ($row['company_uuid'] ?? '')]['name'] ?? null;
        }
        unset($row);

        return $rows;
    }

    /**
     * Records of a related collection keyed by uuid.
     *
     * @return array<string,array<string,mixed>>
     */
    private function indexBy(string $collection): array
    {
        $out = [];
        foreach ($this->store->all($collection) as $row) {
            $out[(string) ($row['uuid'] ?? '')] = $row;
        }

        return $out;
    }
}
