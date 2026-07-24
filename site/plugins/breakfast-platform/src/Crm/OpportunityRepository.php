<?php

declare(strict_types=1);

namespace Breakfast\Platform\Crm;

use Breakfast\Platform\Support\Clock;
use Breakfast\Platform\Support\Uuid;

final class OpportunityRepository extends Repository
{
    private const COLUMNS = [
        'title', 'contact_uuid', 'company_uuid', 'enquiry_uuid', 'stage',
        'estimated_value', 'currency', 'probability', 'services', 'expected_close_date',
        'next_action', 'next_action_date', 'owner', 'lost_reason', 'won_at', 'lost_at', 'notes',
        'archived_at', 'close_outcome',
    ];

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function create(array $data): array
    {
        $uuid = Uuid::v4();
        $now  = $this->now();

        $this->db->run(
            'INSERT INTO opportunities (
                uuid, title, contact_uuid, company_uuid, enquiry_uuid, stage,
                estimated_value, currency, probability, services, expected_close_date,
                next_action, next_action_date, owner, notes, created_at, updated_at
             ) VALUES (
                :uuid, :title, :contact_uuid, :company_uuid, :enquiry_uuid, :stage,
                :estimated_value, :currency, :probability, :services, :expected_close_date,
                :next_action, :next_action_date, :owner, :notes, :created_at, :updated_at
             )',
            [
                'uuid'                => $uuid,
                'title'               => (string) ($data['title'] ?? 'New opportunity'),
                'contact_uuid'        => $data['contact_uuid'] ?? null,
                'company_uuid'        => $data['company_uuid'] ?? null,
                'enquiry_uuid'        => $data['enquiry_uuid'] ?? null,
                'stage'               => $data['stage'] ?? 'new',
                'estimated_value'     => (int) ($data['estimated_value'] ?? 0),
                'currency'            => $data['currency'] ?? 'GBP',
                'probability'         => (int) ($data['probability'] ?? 0),
                'services'            => $this->encodeJson($data['services'] ?? null),
                'expected_close_date' => $data['expected_close_date'] ?? null,
                'next_action'         => $data['next_action'] ?? null,
                'next_action_date'    => $data['next_action_date'] ?? null,
                'owner'               => $data['owner'] ?? null,
                'notes'               => $data['notes'] ?? null,
                'created_at'          => $now,
                'updated_at'          => $now,
            ]
        );

        return $this->find($uuid) ?? [];
    }

    /** @return array<string,mixed>|null */
    public function find(string $uuid): ?array
    {
        return $this->hydrate($this->db->one('SELECT * FROM opportunities WHERE uuid = :u', ['u' => $uuid]));
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>|null
     */
    public function update(string $uuid, array $data): ?array
    {
        if (array_key_exists('services', $data) && is_array($data['services'])) {
            $data['services'] = $this->encodeJson($data['services']);
        }

        [$clause, $params] = $this->assignments($data, self::COLUMNS);

        if ($clause !== '') {
            $params['uuid']       = $uuid;
            $params['updated_at'] = $this->now();
            $this->db->run(
                'UPDATE opportunities SET ' . $clause . ', updated_at = :updated_at WHERE uuid = :uuid',
                $params
            );
        }

        return $this->find($uuid);
    }

    /**
     * @param array{stage?:string,owner?:string,search?:string,open?:bool,limit?:int,offset?:int} $filters
     * @return array<int,array<string,mixed>>
     */
    public function search(array $filters = []): array
    {
        $where  = ['1 = 1'];
        $params = [];

        if (!empty($filters['stage'])) {
            $where[] = 'o.stage = :stage';
            $params['stage'] = $filters['stage'];
        }

        if (!empty($filters['owner'])) {
            $where[] = 'o.owner = :owner';
            $params['owner'] = $filters['owner'];
        }

        if (!empty($filters['open'])) {
            $where[] = "o.stage NOT IN ('won', 'lost')";
        }

        // Archived opportunities are hidden from the active pipeline unless
        // explicitly requested (e.g. an archive view).
        if (empty($filters['include_archived'])) {
            $where[] = 'o.archived_at IS NULL';
        }

        if (!empty($filters['search'])) {
            $where[] = '(o.title LIKE :q OR c.display_name LIKE :q)';
            $params['q'] = '%' . strtolower((string) $filters['search']) . '%';
        }

        $params['l'] = (int) ($filters['limit'] ?? 200);
        $params['o'] = (int) ($filters['offset'] ?? 0);

        $rows = $this->db->all(
            'SELECT o.*, c.display_name AS contact_name, co.name AS company_name
             FROM opportunities o
             LEFT JOIN contacts c ON c.uuid = o.contact_uuid
             LEFT JOIN companies co ON co.uuid = o.company_uuid
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY o.updated_at DESC LIMIT :l OFFSET :o',
            $params
        );

        return array_map(fn ($r) => $this->hydrate($r) ?? [], $rows);
    }

    public function countOpen(): int
    {
        return (int) $this->db->scalar("SELECT COUNT(*) FROM opportunities WHERE stage NOT IN ('won','lost') AND archived_at IS NULL");
    }

    /** Total estimated value (minor units) of open opportunities. */
    public function openPipelineValue(): int
    {
        return (int) $this->db->scalar(
            "SELECT COALESCE(SUM(estimated_value), 0) FROM opportunities WHERE stage NOT IN ('won','lost') AND archived_at IS NULL"
        );
    }

    /**
     * Soft-archive an opportunity (kept, hidden from the pipeline, restorable).
     *
     * @return array<string,mixed>|null
     */
    public function archive(string $uuid, ?string $outcome = null): ?array
    {
        $this->db->run(
            'UPDATE opportunities SET archived_at = :n, close_outcome = COALESCE(:o, close_outcome), updated_at = :n WHERE uuid = :u AND archived_at IS NULL',
            ['n' => $this->now(), 'o' => $outcome, 'u' => $uuid]
        );

        return $this->find($uuid);
    }

    /**
     * Restore an archived opportunity back into the active pipeline.
     *
     * @return array<string,mixed>|null
     */
    public function restore(string $uuid): ?array
    {
        $this->db->run('UPDATE opportunities SET archived_at = NULL, close_outcome = NULL, updated_at = :n WHERE uuid = :u', ['n' => $this->now(), 'u' => $uuid]);

        return $this->find($uuid);
    }

    /** @return array<string,array{count:int,value:int}> keyed by stage */
    public function pipelineByStage(): array
    {
        $rows = $this->db->all(
            "SELECT stage, COUNT(*) AS n, COALESCE(SUM(estimated_value),0) AS v
             FROM opportunities WHERE stage NOT IN ('won','lost') AND archived_at IS NULL GROUP BY stage"
        );

        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r['stage']] = ['count' => (int) $r['n'], 'value' => (int) $r['v']];
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
        $cutoff = Clock::now()->modify('-' . $days . ' days')->format('c');

        $rows = $this->db->all(
            "SELECT o.*, c.display_name AS contact_name FROM opportunities o
             LEFT JOIN contacts c ON c.uuid = o.contact_uuid
             WHERE o.stage NOT IN ('won','lost')
               AND o.archived_at IS NULL
               AND (o.next_action_date IS NULL OR o.next_action_date < :now)
               AND o.updated_at < :cutoff
             ORDER BY o.updated_at ASC",
            ['now' => Clock::nowIso(), 'cutoff' => $cutoff]
        );

        return array_map(fn ($r) => $this->hydrate($r) ?? [], $rows);
    }

    /**
     * @param array<string,mixed>|null $row
     * @return array<string,mixed>|null
     */
    private function hydrate(?array $row): ?array
    {
        if ($row === null) {
            return null;
        }

        $row['services'] = $this->decodeJson($row['services'] ?? null);

        return $row;
    }
}
