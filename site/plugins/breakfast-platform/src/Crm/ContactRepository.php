<?php

declare(strict_types=1);

namespace Breakfast\Platform\Crm;

use Breakfast\Platform\Support\FileRepository;
use Breakfast\Platform\Support\Uuid;

final class ContactRepository extends FileRepository
{
    private const COLUMNS = [
        'first_name', 'last_name', 'display_name', 'email', 'email_normalised',
        'phone', 'company_uuid', 'role', 'website', 'location', 'contact_type',
        'lead_source', 'marketing_consent', 'marketing_consent_at', 'consent_source',
        'tags', 'owner', 'status', 'notes', 'last_contacted_at', 'archived_at', 'anonymised_at',
    ];

    protected function collection(): string
    {
        return 'contacts';
    }

    public static function normaliseEmail(?string $email): ?string
    {
        if ($email === null) {
            return null;
        }

        $email = strtolower(trim($email));

        return $email === '' ? null : $email;
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function create(array $data): array
    {
        $now     = $this->now();
        $display = $data['display_name']
            ?? trim(((string) ($data['first_name'] ?? '')) . ' ' . ((string) ($data['last_name'] ?? '')));

        if ($display === '') {
            $display = $data['email'] ?? 'Unknown contact';
        }

        return $this->persist([
            'uuid'                 => Uuid::v4(),
            'first_name'           => $data['first_name'] ?? null,
            'last_name'            => $data['last_name'] ?? null,
            'display_name'         => $display,
            'email'                => $data['email'] ?? null,
            'email_normalised'     => self::normaliseEmail($data['email'] ?? null),
            'phone'                => $data['phone'] ?? null,
            'company_uuid'         => $data['company_uuid'] ?? null,
            'role'                 => $data['role'] ?? null,
            'website'              => $data['website'] ?? null,
            'location'             => $data['location'] ?? null,
            'contact_type'         => $data['contact_type'] ?? 'lead',
            'lead_source'          => $data['lead_source'] ?? null,
            'marketing_consent'    => $data['marketing_consent'] ?? 'unknown',
            'marketing_consent_at' => $data['marketing_consent_at'] ?? null,
            'consent_source'       => $data['consent_source'] ?? null,
            'tags'                 => $this->normaliseTags($data['tags'] ?? null),
            'owner'                => $data['owner'] ?? null,
            'status'               => $data['status'] ?? 'active',
            'notes'                => $data['notes'] ?? null,
            'last_contacted_at'    => $data['last_contacted_at'] ?? null,
            'archived_at'          => null,
            'anonymised_at'        => null,
            'created_at'           => $now,
            'updated_at'           => $now,
        ]);
    }

    /** @return array<string,mixed>|null */
    public function find(string $uuid): ?array
    {
        return $this->findRecord($uuid);
    }

    /** @return array<string,mixed>|null */
    public function findByEmail(string $email): ?array
    {
        $norm = self::normaliseEmail($email);

        if ($norm === null) {
            return null;
        }

        $matches = array_filter(
            $this->records(),
            fn (array $r): bool => ($r['email_normalised'] ?? null) === $norm
        );
        $matches = $this->sortBy(array_values($matches), 'created_at', 'asc');

        return $matches[0] ?? null;
    }

    /**
     * Upsert by email: create a new contact or return (and lightly enrich) an
     * existing one. Used by the form pipeline to avoid duplicate contacts.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function upsertByEmail(array $data): array
    {
        $existing = isset($data['email']) ? $this->findByEmail((string) $data['email']) : null;

        if ($existing === null) {
            return $this->create($data);
        }

        // Fill in blanks without clobbering existing curated values.
        $patch = [];
        foreach (['phone', 'company_uuid', 'role', 'website', 'location'] as $field) {
            if (empty($existing[$field]) && !empty($data[$field])) {
                $patch[$field] = $data[$field];
            }
        }

        $patch['last_contacted_at'] = $this->now();

        return $this->update((string) $existing['uuid'], $patch) ?? $existing;
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>|null
     */
    public function update(string $uuid, array $data): ?array
    {
        if (array_key_exists('email', $data)) {
            $data['email_normalised'] = self::normaliseEmail($data['email']);
        }

        if (array_key_exists('tags', $data)) {
            $data['tags'] = $this->normaliseTags($data['tags']);
        }

        return $this->patch($uuid, $data, self::COLUMNS);
    }

    public function archive(string $uuid): void
    {
        $now = $this->now();
        $this->patch($uuid, ['status' => 'archived', 'archived_at' => $now], self::COLUMNS);
    }

    /**
     * Irreversibly anonymise a contact's personal data (GDPR erasure), keeping
     * the record for referential integrity and audit.
     */
    public function anonymise(string $uuid): void
    {
        $this->patch($uuid, [
            'first_name'        => null,
            'last_name'         => null,
            'display_name'      => 'Anonymised contact',
            'email'             => null,
            'email_normalised'  => null,
            'phone'             => null,
            'website'           => null,
            'location'          => null,
            'notes'             => null,
            'tags'              => [],
            'marketing_consent' => 'denied',
            'status'            => 'anonymised',
            'anonymised_at'     => $this->now(),
        ], self::COLUMNS);
    }

    /**
     * @param array{search?:string,status?:string,owner?:string,type?:string,limit?:int,offset?:int} $filters
     * @return array<int,array<string,mixed>>
     */
    public function search(array $filters = []): array
    {
        $rows      = $this->records();
        $companies = $this->indexCompanies();

        if (!empty($filters['search'])) {
            $needle = strtolower((string) $filters['search']);
            $rows   = array_filter($rows, function (array $r) use ($needle, $companies): bool {
                $companyName = $companies[(string) ($r['company_uuid'] ?? '')]['name'] ?? '';

                return $this->contains($r['display_name'] ?? '', $needle)
                    || $this->contains($r['email_normalised'] ?? '', $needle)
                    || $this->contains($companyName, $needle);
            });
        }

        if (!empty($filters['status'])) {
            $rows = array_filter($rows, fn (array $r): bool => ($r['status'] ?? null) === $filters['status']);
        }

        if (!empty($filters['owner'])) {
            $rows = array_filter($rows, fn (array $r): bool => ($r['owner'] ?? null) === $filters['owner']);
        }

        if (!empty($filters['type'])) {
            $rows = array_filter($rows, fn (array $r): bool => ($r['contact_type'] ?? null) === $filters['type']);
        }

        $rows = $this->sortBy(array_values($rows), 'updated_at', 'desc');

        foreach ($rows as &$row) {
            $row['company_name'] = $companies[(string) ($row['company_uuid'] ?? '')]['name'] ?? null;
        }
        unset($row);

        return $this->paginate($rows, (int) ($filters['limit'] ?? 100), (int) ($filters['offset'] ?? 0));
    }

    public function count(string $status = ''): int
    {
        if ($status === '') {
            return count($this->records());
        }

        return count(array_filter($this->records(), fn (array $r): bool => ($r['status'] ?? null) === $status));
    }

    /**
     * Company records keyed by uuid, for cheap in-memory joins.
     *
     * @return array<string,array<string,mixed>>
     */
    private function indexCompanies(): array
    {
        $out = [];
        foreach ($this->store->all('companies') as $company) {
            $out[(string) ($company['uuid'] ?? '')] = $company;
        }

        return $out;
    }

    /**
     * @param mixed $tags
     * @return list<mixed>
     */
    private function normaliseTags(mixed $tags): array
    {
        return is_array($tags) ? array_values($tags) : [];
    }
}
