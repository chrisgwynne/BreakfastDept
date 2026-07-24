<?php

declare(strict_types=1);

namespace Breakfast\Platform\Crm;

use Breakfast\Platform\Support\Uuid;

final class CompanyRepository extends Repository
{
    private const COLUMNS = ['name', 'website', 'industry', 'size_band', 'address', 'notes'];

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed> the created row
     */
    public function create(array $data): array
    {
        $uuid = Uuid::v4();
        $now  = $this->now();

        $this->db->run(
            'INSERT INTO companies (uuid, name, website, industry, size_band, address, notes, created_at, updated_at)
             VALUES (:uuid, :name, :website, :industry, :size_band, :address, :notes, :created_at, :updated_at)',
            [
                'uuid'       => $uuid,
                'name'       => (string) ($data['name'] ?? 'Unknown'),
                'website'    => $data['website'] ?? null,
                'industry'   => $data['industry'] ?? null,
                'size_band'  => $data['size_band'] ?? null,
                'address'    => $data['address'] ?? null,
                'notes'      => $data['notes'] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        return $this->find($uuid) ?? [];
    }

    /** @return array<string,mixed>|null */
    public function find(string $uuid): ?array
    {
        return $this->db->one('SELECT * FROM companies WHERE uuid = :u', ['u' => $uuid]);
    }

    /** @return array<string,mixed>|null */
    public function findByName(string $name): ?array
    {
        return $this->db->one(
            'SELECT * FROM companies WHERE lower(name) = lower(:n) LIMIT 1',
            ['n' => trim($name)]
        );
    }

    /**
     * Find an existing company by name or create it.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function findOrCreate(string $name, array $data = []): array
    {
        $name = trim($name);

        if ($name === '') {
            return [];
        }

        $existing = $this->findByName($name);

        if ($existing !== null) {
            return $existing;
        }

        return $this->create(['name' => $name] + $data);
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
                'UPDATE companies SET ' . $clause . ', updated_at = :updated_at WHERE uuid = :uuid',
                $params
            );
        }

        return $this->find($uuid);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function all(int $limit = 200, int $offset = 0, bool $includeArchived = false): array
    {
        $where = $includeArchived ? '' : 'WHERE c.archived_at IS NULL';

        return $this->db->all(
            'SELECT c.*, (SELECT COUNT(*) FROM contacts WHERE company_uuid = c.uuid) AS contact_count
             FROM companies c ' . $where . ' ORDER BY c.name COLLATE NOCASE ASC LIMIT :l OFFSET :o',
            ['l' => $limit, 'o' => $offset]
        );
    }

    public function count(bool $includeArchived = false): int
    {
        $where = $includeArchived ? '' : 'WHERE archived_at IS NULL';

        return (int) $this->db->scalar('SELECT COUNT(*) FROM companies ' . $where);
    }

    /**
     * Soft-archive a company (kept, hidden from active lists, restorable).
     *
     * @return array<string,mixed>|null
     */
    public function archive(string $uuid): ?array
    {
        $this->db->run('UPDATE companies SET archived_at = :n, updated_at = :n WHERE uuid = :u AND archived_at IS NULL', ['n' => $this->now(), 'u' => $uuid]);

        return $this->find($uuid);
    }

    /**
     * Restore a previously archived company.
     *
     * @return array<string,mixed>|null
     */
    public function restore(string $uuid): ?array
    {
        $this->db->run('UPDATE companies SET archived_at = NULL, updated_at = :n WHERE uuid = :u', ['n' => $this->now(), 'u' => $uuid]);

        return $this->find($uuid);
    }

    /**
     * Count of records linked to a company, for archive/relationship warnings.
     *
     * @return array{contacts:int,opportunities:int}
     */
    public function relatedCounts(string $uuid): array
    {
        return [
            'contacts'      => (int) $this->db->scalar('SELECT COUNT(*) FROM contacts WHERE company_uuid = :u', ['u' => $uuid]),
            'opportunities' => (int) $this->db->scalar('SELECT COUNT(*) FROM opportunities WHERE company_uuid = :u AND archived_at IS NULL', ['u' => $uuid]),
        ];
    }
}
