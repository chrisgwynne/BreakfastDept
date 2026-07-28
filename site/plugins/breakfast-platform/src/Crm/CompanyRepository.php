<?php

declare(strict_types=1);

namespace Breakfast\Platform\Crm;

use Breakfast\Platform\Support\FileRepository;
use Breakfast\Platform\Support\Uuid;

final class CompanyRepository extends FileRepository
{
    private const COLUMNS = ['name', 'website', 'industry', 'size_band', 'address', 'notes'];

    protected function collection(): string
    {
        return 'companies';
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed> the created row
     */
    public function create(array $data): array
    {
        $now = $this->now();

        return $this->persist([
            'uuid'        => Uuid::v4(),
            'name'        => (string) ($data['name'] ?? 'Unknown'),
            'website'     => $data['website'] ?? null,
            'industry'    => $data['industry'] ?? null,
            'size_band'   => $data['size_band'] ?? null,
            'address'     => $data['address'] ?? null,
            'notes'       => $data['notes'] ?? null,
            'archived_at' => null,
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);
    }

    /** @return array<string,mixed>|null */
    public function find(string $uuid): ?array
    {
        return $this->findRecord($uuid);
    }

    /** @return array<string,mixed>|null */
    public function findByName(string $name): ?array
    {
        $name = trim($name);
        foreach ($this->records() as $row) {
            if (strcasecmp((string) ($row['name'] ?? ''), $name) === 0) {
                return $row;
            }
        }

        return null;
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

        return $this->findByName($name) ?? $this->create(['name' => $name] + $data);
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>|null
     */
    public function update(string $uuid, array $data): ?array
    {
        return $this->patch($uuid, $data, self::COLUMNS);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function all(int $limit = 200, int $offset = 0, bool $includeArchived = false): array
    {
        $rows = $this->records();

        if (!$includeArchived) {
            $rows = array_values(array_filter($rows, fn (array $r): bool => ($r['archived_at'] ?? null) === null));
        }

        // Denormalise the contact count, as the SQL correlated subquery did.
        $contacts = $this->store->all('contacts');
        foreach ($rows as &$row) {
            $uuid                 = (string) ($row['uuid'] ?? '');
            $row['contact_count'] = count(array_filter(
                $contacts,
                fn (array $c): bool => ($c['company_uuid'] ?? null) === $uuid
            ));
        }
        unset($row);

        return $this->paginate($this->sortBy($rows, 'name', 'asc'), $limit, $offset);
    }

    public function count(bool $includeArchived = false): int
    {
        if ($includeArchived) {
            return count($this->records());
        }

        return count(array_filter($this->records(), fn (array $r): bool => ($r['archived_at'] ?? null) === null));
    }

    /**
     * Soft-archive a company (kept, hidden from active lists, restorable).
     *
     * @return array<string,mixed>|null
     */
    public function archive(string $uuid): ?array
    {
        $record = $this->findRecord($uuid);
        if ($record !== null && ($record['archived_at'] ?? null) === null) {
            return $this->patch($uuid, ['archived_at' => $this->now()], ['archived_at']);
        }

        return $record;
    }

    /**
     * Restore a previously archived company.
     *
     * @return array<string,mixed>|null
     */
    public function restore(string $uuid): ?array
    {
        return $this->patch($uuid, ['archived_at' => null], ['archived_at']);
    }

    /**
     * Count of records linked to a company, for archive/relationship warnings.
     *
     * @return array{contacts:int,opportunities:int}
     */
    public function relatedCounts(string $uuid): array
    {
        $contacts = count(array_filter(
            $this->store->all('contacts'),
            fn (array $c): bool => ($c['company_uuid'] ?? null) === $uuid
        ));
        $opportunities = count(array_filter(
            $this->store->all('opportunities'),
            fn (array $o): bool => ($o['company_uuid'] ?? null) === $uuid && ($o['archived_at'] ?? null) === null
        ));

        return ['contacts' => $contacts, 'opportunities' => $opportunities];
    }
}
