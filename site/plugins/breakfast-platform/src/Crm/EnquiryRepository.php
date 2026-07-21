<?php

declare(strict_types=1);

namespace Breakfast\Platform\Crm;

use Breakfast\Platform\Support\Clock;
use Breakfast\Platform\Support\Uuid;

final class EnquiryRepository extends Repository
{
    private const COLUMNS = [
        'contact_uuid', 'company_uuid', 'summary', 'status', 'owner',
        'spam_status', 'risk_score',
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
            'INSERT INTO enquiries (
                uuid, reference, form_type, contact_uuid, company_uuid, payload, summary,
                source_page, referrer, utm, landing_page, ip_hash, status, owner,
                spam_status, risk_score, consent, created_at, updated_at
             ) VALUES (
                :uuid, :reference, :form_type, :contact_uuid, :company_uuid, :payload, :summary,
                :source_page, :referrer, :utm, :landing_page, :ip_hash, :status, :owner,
                :spam_status, :risk_score, :consent, :created_at, :updated_at
             )',
            [
                'uuid'         => $uuid,
                'reference'    => $data['reference'] ?? $this->nextReference(),
                'form_type'    => (string) ($data['form_type'] ?? 'contact'),
                'contact_uuid' => $data['contact_uuid'] ?? null,
                'company_uuid' => $data['company_uuid'] ?? null,
                'payload'      => $this->encodeJson($data['payload'] ?? []) ?? '{}',
                'summary'      => $data['summary'] ?? null,
                'source_page'  => $data['source_page'] ?? null,
                'referrer'     => $data['referrer'] ?? null,
                'utm'          => $this->encodeJson($data['utm'] ?? null),
                'landing_page' => $data['landing_page'] ?? null,
                'ip_hash'      => $data['ip_hash'] ?? null,
                'status'       => $data['status'] ?? 'new',
                'owner'        => $data['owner'] ?? null,
                'spam_status'  => $data['spam_status'] ?? 'unknown',
                'risk_score'   => (int) ($data['risk_score'] ?? 0),
                'consent'      => $this->encodeJson($data['consent'] ?? null),
                'created_at'   => $now,
                'updated_at'   => $now,
            ]
        );

        return $this->find($uuid) ?? [];
    }

    /**
     * Atomically allocate the next human-readable reference, e.g. ENQ-2026-0001.
     */
    public function nextReference(): string
    {
        return $this->db->transaction(function () {
            $year = Clock::now()->format('Y');
            $name = 'enquiry-' . $year;

            $this->db->run(
                'INSERT INTO counters (name, value) VALUES (:n, 1)
                 ON CONFLICT(name) DO UPDATE SET value = value + 1',
                ['n' => $name]
            );

            $value = (int) $this->db->scalar('SELECT value FROM counters WHERE name = :n', ['n' => $name]);

            return sprintf('ENQ-%s-%04d', $year, $value);
        });
    }

    /** @return array<string,mixed>|null */
    public function find(string $uuid): ?array
    {
        return $this->hydrate($this->db->one('SELECT * FROM enquiries WHERE uuid = :u', ['u' => $uuid]));
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>|null
     */
    public function update(string $uuid, array $data): ?array
    {
        [$clause, $params] = $this->assignments($data, self::COLUMNS);

        if ($clause !== '') {
            $params['uuid']       = $uuid;
            $params['updated_at'] = $this->now();
            $this->db->run(
                'UPDATE enquiries SET ' . $clause . ', updated_at = :updated_at WHERE uuid = :uuid',
                $params
            );
        }

        return $this->find($uuid);
    }

    /**
     * @param array{status?:string,form_type?:string,spam?:string,search?:string,limit?:int,offset?:int} $filters
     * @return array<int,array<string,mixed>>
     */
    public function search(array $filters = []): array
    {
        $where  = ['1 = 1'];
        $params = [];

        if (!empty($filters['status'])) {
            $where[] = 'e.status = :status';
            $params['status'] = $filters['status'];
        }

        if (!empty($filters['form_type'])) {
            $where[] = 'e.form_type = :ft';
            $params['ft'] = $filters['form_type'];
        }

        if (!empty($filters['spam'])) {
            $where[] = 'e.spam_status = :spam';
            $params['spam'] = $filters['spam'];
        }

        if (!empty($filters['search'])) {
            $where[] = '(e.reference LIKE :q OR e.summary LIKE :q OR c.display_name LIKE :q OR c.email_normalised LIKE :q)';
            $params['q'] = '%' . strtolower((string) $filters['search']) . '%';
        }

        $params['l'] = (int) ($filters['limit'] ?? 100);
        $params['o'] = (int) ($filters['offset'] ?? 0);

        $rows = $this->db->all(
            'SELECT e.*, c.display_name AS contact_name, c.email AS contact_email
             FROM enquiries e LEFT JOIN contacts c ON c.uuid = e.contact_uuid
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY e.created_at DESC LIMIT :l OFFSET :o',
            $params
        );

        return array_map(fn ($r) => $this->hydrate($r) ?? [], $rows);
    }

    public function count(string $status = ''): int
    {
        if ($status === '') {
            return (int) $this->db->scalar('SELECT COUNT(*) FROM enquiries');
        }

        return (int) $this->db->scalar('SELECT COUNT(*) FROM enquiries WHERE status = :s', ['s' => $status]);
    }

    /**
     * Enquiries grouped by a JSON payload field (e.g. lead source, service).
     *
     * @return array<string,int>
     */
    public function countBySource(): array
    {
        $rows = $this->db->all(
            "SELECT COALESCE(json_extract(payload, '$.heard_about'), 'unknown') AS k, COUNT(*) AS n
             FROM enquiries GROUP BY k ORDER BY n DESC"
        );

        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r['k']] = (int) $r['n'];
        }

        return $out;
    }

    /**
     * Prune the IP hash from enquiries older than the given retention (days).
     */
    public function pruneIpHashes(int $olderThanDays = 30): int
    {
        $cutoff = Clock::now()->modify('-' . $olderThanDays . ' days')->format('c');
        $stmt   = $this->db->run(
            'UPDATE enquiries SET ip_hash = NULL WHERE ip_hash IS NOT NULL AND created_at < :c',
            ['c' => $cutoff]
        );

        return $stmt->rowCount();
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

        $row['payload'] = $this->decodeJson($row['payload'] ?? null);
        $row['utm']     = $this->decodeJson($row['utm'] ?? null);
        $row['consent'] = $this->decodeJson($row['consent'] ?? null);

        return $row;
    }
}
