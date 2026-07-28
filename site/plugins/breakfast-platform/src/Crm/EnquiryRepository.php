<?php

declare(strict_types=1);

namespace Breakfast\Platform\Crm;

use Breakfast\Platform\Support\Clock;
use Breakfast\Platform\Support\FileRepository;
use Breakfast\Platform\Support\Uuid;

final class EnquiryRepository extends FileRepository
{
    private const COLUMNS = [
        'contact_uuid', 'company_uuid', 'summary', 'status', 'owner',
        'spam_status', 'risk_score',
    ];

    protected function collection(): string
    {
        return 'enquiries';
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function create(array $data): array
    {
        $now = $this->now();

        return $this->persist([
            'uuid'         => Uuid::v4(),
            'reference'    => $data['reference'] ?? $this->nextReference(),
            'form_type'    => (string) ($data['form_type'] ?? 'contact'),
            'contact_uuid' => $data['contact_uuid'] ?? null,
            'company_uuid' => $data['company_uuid'] ?? null,
            'payload'      => is_array($data['payload'] ?? null) ? $data['payload'] : [],
            'summary'      => $data['summary'] ?? null,
            'source_page'  => $data['source_page'] ?? null,
            'referrer'     => $data['referrer'] ?? null,
            'utm'          => is_array($data['utm'] ?? null) ? $data['utm'] : [],
            'landing_page' => $data['landing_page'] ?? null,
            'ip_hash'      => $data['ip_hash'] ?? null,
            'status'       => $data['status'] ?? 'new',
            'owner'        => $data['owner'] ?? null,
            'spam_status'  => $data['spam_status'] ?? 'unknown',
            'risk_score'   => (int) ($data['risk_score'] ?? 0),
            'consent'      => is_array($data['consent'] ?? null) ? $data['consent'] : [],
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);
    }

    /**
     * Allocate the next human-readable reference, e.g. ENQ-2026-0001, from an
     * atomic per-year file counter.
     */
    public function nextReference(): string
    {
        $year  = Clock::now()->format('Y');
        $value = $this->nextSequence('enquiry-' . $year);

        return sprintf('ENQ-%s-%04d', $year, $value);
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
        return $this->patch($uuid, $data, self::COLUMNS);
    }

    /**
     * @param array{status?:string,form_type?:string,spam?:string,search?:string,limit?:int,offset?:int} $filters
     * @return array<int,array<string,mixed>>
     */
    public function search(array $filters = []): array
    {
        $rows     = $this->records();
        $contacts = $this->indexContacts();

        if (!empty($filters['status'])) {
            $rows = array_filter($rows, fn (array $r): bool => ($r['status'] ?? null) === $filters['status']);
        }

        if (!empty($filters['form_type'])) {
            $rows = array_filter($rows, fn (array $r): bool => ($r['form_type'] ?? null) === $filters['form_type']);
        }

        if (!empty($filters['spam'])) {
            $rows = array_filter($rows, fn (array $r): bool => ($r['spam_status'] ?? null) === $filters['spam']);
        }

        if (!empty($filters['search'])) {
            $needle = strtolower((string) $filters['search']);
            $rows   = array_filter($rows, function (array $r) use ($needle, $contacts): bool {
                $contact = $contacts[(string) ($r['contact_uuid'] ?? '')] ?? [];

                return $this->contains($r['reference'] ?? '', $needle)
                    || $this->contains($r['summary'] ?? '', $needle)
                    || $this->contains($contact['display_name'] ?? '', $needle)
                    || $this->contains($contact['email_normalised'] ?? '', $needle);
            });
        }

        $rows = $this->sortBy(array_values($rows), 'created_at', 'desc');

        foreach ($rows as &$row) {
            $contact              = $contacts[(string) ($row['contact_uuid'] ?? '')] ?? [];
            $row['contact_name']  = $contact['display_name'] ?? null;
            $row['contact_email'] = $contact['email'] ?? null;
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
     * Enquiries grouped by a payload field (e.g. lead source, service),
     * most common first.
     *
     * @return array<string,int>
     */
    public function countBySource(): array
    {
        $out = [];
        foreach ($this->records() as $row) {
            $payload = is_array($row['payload'] ?? null) ? $row['payload'] : [];
            $key     = (string) ($payload['heard_about'] ?? 'unknown');
            $key     = $key === '' ? 'unknown' : $key;
            $out[$key] = ($out[$key] ?? 0) + 1;
        }

        arsort($out);

        return $out;
    }

    /**
     * Prune the IP hash from enquiries older than the given retention (days).
     */
    public function pruneIpHashes(int $olderThanDays = 30): int
    {
        $cutoff  = Clock::now()->modify('-' . $olderThanDays . ' days')->format('c');
        $cleared = 0;

        foreach ($this->records() as $row) {
            if (($row['ip_hash'] ?? null) !== null && (string) ($row['created_at'] ?? '') < $cutoff) {
                $this->patch((string) $row['uuid'], ['ip_hash' => null], ['ip_hash']);
                $cleared++;
            }
        }

        return $cleared;
    }

    /**
     * Contact records keyed by uuid, for cheap in-memory joins.
     *
     * @return array<string,array<string,mixed>>
     */
    private function indexContacts(): array
    {
        $out = [];
        foreach ($this->store->all('contacts') as $contact) {
            $out[(string) ($contact['uuid'] ?? '')] = $contact;
        }

        return $out;
    }
}
