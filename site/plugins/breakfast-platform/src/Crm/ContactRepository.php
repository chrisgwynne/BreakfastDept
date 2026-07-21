<?php

declare(strict_types=1);

namespace Breakfast\Platform\Crm;

use Breakfast\Platform\Support\Uuid;

final class ContactRepository extends Repository
{
    private const COLUMNS = [
        'first_name', 'last_name', 'display_name', 'email', 'email_normalised',
        'phone', 'company_uuid', 'role', 'website', 'location', 'contact_type',
        'lead_source', 'marketing_consent', 'marketing_consent_at', 'consent_source',
        'tags', 'owner', 'status', 'notes', 'last_contacted_at', 'archived_at', 'anonymised_at',
    ];

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
        $uuid = Uuid::v4();
        $now  = $this->now();
        $display = $data['display_name']
            ?? trim(((string) ($data['first_name'] ?? '')) . ' ' . ((string) ($data['last_name'] ?? '')));

        if ($display === '') {
            $display = $data['email'] ?? 'Unknown contact';
        }

        $this->db->run(
            'INSERT INTO contacts (
                uuid, first_name, last_name, display_name, email, email_normalised, phone,
                company_uuid, role, website, location, contact_type, lead_source,
                marketing_consent, marketing_consent_at, consent_source, tags, owner, status,
                notes, created_at, updated_at, last_contacted_at
             ) VALUES (
                :uuid, :first_name, :last_name, :display_name, :email, :email_normalised, :phone,
                :company_uuid, :role, :website, :location, :contact_type, :lead_source,
                :marketing_consent, :marketing_consent_at, :consent_source, :tags, :owner, :status,
                :notes, :created_at, :updated_at, :last_contacted_at
             )',
            [
                'uuid'                 => $uuid,
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
                'tags'                 => $this->encodeJson($data['tags'] ?? null),
                'owner'                => $data['owner'] ?? null,
                'status'               => $data['status'] ?? 'active',
                'notes'                => $data['notes'] ?? null,
                'created_at'           => $now,
                'updated_at'           => $now,
                'last_contacted_at'    => $data['last_contacted_at'] ?? null,
            ]
        );

        return $this->find($uuid) ?? [];
    }

    /** @return array<string,mixed>|null */
    public function find(string $uuid): ?array
    {
        return $this->hydrate($this->db->one('SELECT * FROM contacts WHERE uuid = :u', ['u' => $uuid]));
    }

    /** @return array<string,mixed>|null */
    public function findByEmail(string $email): ?array
    {
        $norm = self::normaliseEmail($email);

        if ($norm === null) {
            return null;
        }

        return $this->hydrate($this->db->one(
            'SELECT * FROM contacts WHERE email_normalised = :e ORDER BY created_at ASC LIMIT 1',
            ['e' => $norm]
        ));
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

        if (array_key_exists('tags', $data) && is_array($data['tags'])) {
            $data['tags'] = $this->encodeJson($data['tags']);
        }

        [$clause, $params] = $this->assignments($data, self::COLUMNS);

        if ($clause !== '') {
            $params['uuid']       = $uuid;
            $params['updated_at'] = $this->now();
            $this->db->run(
                'UPDATE contacts SET ' . $clause . ', updated_at = :updated_at WHERE uuid = :uuid',
                $params
            );
        }

        return $this->find($uuid);
    }

    public function archive(string $uuid): void
    {
        $this->db->run(
            'UPDATE contacts SET status = :s, archived_at = :a, updated_at = :a WHERE uuid = :u',
            ['s' => 'archived', 'a' => $this->now(), 'u' => $uuid]
        );
    }

    /**
     * Irreversibly anonymise a contact's personal data (GDPR erasure), keeping
     * the row for referential integrity and audit.
     */
    public function anonymise(string $uuid): void
    {
        $now = $this->now();
        $this->db->run(
            'UPDATE contacts SET
                first_name = NULL, last_name = NULL, display_name = :dn,
                email = NULL, email_normalised = NULL, phone = NULL, website = NULL,
                location = NULL, notes = NULL, tags = NULL,
                marketing_consent = :mc, status = :st,
                anonymised_at = :now, updated_at = :now
             WHERE uuid = :u',
            ['dn' => 'Anonymised contact', 'mc' => 'denied', 'st' => 'anonymised', 'now' => $now, 'u' => $uuid]
        );
    }

    /**
     * @param array{search?:string,status?:string,owner?:string,type?:string,limit?:int,offset?:int} $filters
     * @return array<int,array<string,mixed>>
     */
    public function search(array $filters = []): array
    {
        $where  = ['1 = 1'];
        $params = [];

        if (!empty($filters['search'])) {
            $where[] = '(display_name LIKE :q OR email_normalised LIKE :q OR company_uuid IN (SELECT uuid FROM companies WHERE name LIKE :q))';
            $params['q'] = '%' . strtolower((string) $filters['search']) . '%';
        }

        if (!empty($filters['status'])) {
            $where[] = 'status = :status';
            $params['status'] = $filters['status'];
        }

        if (!empty($filters['owner'])) {
            $where[] = 'owner = :owner';
            $params['owner'] = $filters['owner'];
        }

        if (!empty($filters['type'])) {
            $where[] = 'contact_type = :type';
            $params['type'] = $filters['type'];
        }

        $params['l'] = (int) ($filters['limit'] ?? 100);
        $params['o'] = (int) ($filters['offset'] ?? 0);

        $rows = $this->db->all(
            'SELECT c.*, co.name AS company_name
             FROM contacts c LEFT JOIN companies co ON co.uuid = c.company_uuid
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY c.updated_at DESC LIMIT :l OFFSET :o',
            $params
        );

        return array_map(fn ($r) => $this->hydrate($r) ?? [], $rows);
    }

    public function count(string $status = ''): int
    {
        if ($status === '') {
            return (int) $this->db->scalar('SELECT COUNT(*) FROM contacts');
        }

        return (int) $this->db->scalar('SELECT COUNT(*) FROM contacts WHERE status = :s', ['s' => $status]);
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

        $row['tags'] = $this->decodeJson($row['tags'] ?? null);

        return $row;
    }
}
