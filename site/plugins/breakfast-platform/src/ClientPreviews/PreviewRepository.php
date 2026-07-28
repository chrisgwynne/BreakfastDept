<?php

declare(strict_types=1);

namespace Breakfast\Platform\ClientPreviews;

use Breakfast\Platform\Support\FileRepository;
use Breakfast\Platform\Support\Uuid;

/**
 * Flat-file persistence for client previews. Slugs are stored lowercased so
 * uniqueness is case-insensitive; `slugTaken()` also blocks a slug that is any
 * preview's immediately-previous slug, preventing confusing reuse. Only
 * whitelisted columns are ever written on update.
 */
final class PreviewRepository extends FileRepository
{
    /** @var list<string> */
    private const UPDATABLE = [
        'name', 'client_display_name', 'contact_uuid', 'company_uuid',
        'opportunity_uuid', 'status', 'visibility', 'indexable', 'note',
        'entry_file', 'current_version_uuid', 'version_count', 'bytes',
        'file_count', 'password_protected', 'password_hash', 'analytics_enabled',
        'title_override', 'favicon_override', 'updated_by', 'published_by',
        'published_at', 'expires_at', 'last_viewed_at', 'disabled_at',
        'archived_at', 'view_count', 'previous_slug', 'public_slug',
    ];

    protected function collection(): string
    {
        return 'client_previews';
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function create(array $data): array
    {
        $now = $this->now();

        return $this->persist([
            'uuid'                 => Uuid::v4(),
            'name'                 => (string) ($data['name'] ?? 'Untitled preview'),
            'client_display_name'  => $data['client_display_name'] ?? null,
            'contact_uuid'         => $data['contact_uuid'] ?? null,
            'company_uuid'         => $data['company_uuid'] ?? null,
            'opportunity_uuid'     => $data['opportunity_uuid'] ?? null,
            'public_slug'          => strtolower((string) ($data['public_slug'] ?? '')),
            'previous_slug'        => null,
            'status'               => (string) ($data['status'] ?? PreviewStatus::DRAFT),
            'visibility'           => (string) ($data['visibility'] ?? PreviewStatus::VISIBILITY_UNLISTED),
            'indexable'            => 0,
            'note'                 => $data['note'] ?? null,
            'entry_file'           => (string) ($data['entry_file'] ?? 'index.html'),
            'current_version_uuid' => null,
            'version_count'        => 0,
            'bytes'                => 0,
            'file_count'           => 0,
            'password_protected'   => 0,
            'password_hash'        => null,
            'analytics_enabled'    => (int) ($data['analytics_enabled'] ?? 1),
            'title_override'       => null,
            'favicon_override'     => null,
            'created_by'           => $data['created_by'] ?? null,
            'updated_by'           => null,
            'published_by'         => null,
            'created_at'           => $now,
            'updated_at'           => $now,
            'published_at'         => null,
            'expires_at'           => $data['expires_at'] ?? null,
            'last_viewed_at'       => null,
            'disabled_at'          => null,
            'archived_at'          => null,
            'view_count'           => 0,
        ]);
    }

    /** @return array<string,mixed>|null */
    public function find(string $uuid): ?array
    {
        return $this->findRecord($uuid);
    }

    /** @return array<string,mixed>|null */
    public function findBySlug(string $slug): ?array
    {
        $slug = strtolower(trim($slug));
        foreach ($this->records() as $row) {
            if ((string) ($row['public_slug'] ?? '') === $slug) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Find a preview by a slug it USED to have (for canonical redirects from an
     * old subdomain/URL to the current one). Never matches the current slug.
     *
     * @return array<string,mixed>|null
     */
    public function findByPreviousSlug(string $slug): ?array
    {
        $slug = strtolower(trim($slug));
        foreach ($this->records() as $row) {
            if ((string) ($row['previous_slug'] ?? '') === $slug && (string) ($row['public_slug'] ?? '') !== $slug) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Whether a slug is unavailable: taken by another preview's current slug, or
     * held as another preview's immediately-previous slug.
     */
    public function slugTaken(string $slug, ?string $exceptUuid = null): bool
    {
        $slug = strtolower(trim($slug));
        foreach ($this->records() as $row) {
            if ($exceptUuid !== null && (string) ($row['uuid'] ?? '') === $exceptUuid) {
                continue;
            }
            if ((string) ($row['public_slug'] ?? '') === $slug || (string) ($row['previous_slug'] ?? '') === $slug) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,mixed> $filters
     * @return array<int,array<string,mixed>>
     */
    public function all(array $filters = []): array
    {
        $rows = $this->records();

        if (!empty($filters['status'])) {
            $rows = array_filter($rows, fn (array $r): bool => ($r['status'] ?? null) === $filters['status']);
        }
        if (isset($filters['include_archived']) && $filters['include_archived'] === false) {
            $rows = array_filter($rows, fn (array $r): bool => ($r['archived_at'] ?? null) === null);
        }
        if (!empty($filters['contact_uuid'])) {
            $rows = array_filter($rows, fn (array $r): bool => ($r['contact_uuid'] ?? null) === $filters['contact_uuid']);
        }
        if (!empty($filters['search'])) {
            $needle = strtolower((string) $filters['search']);
            $rows   = array_filter($rows, fn (array $r): bool => $this->contains($r['name'] ?? '', $needle)
                || $this->contains($r['client_display_name'] ?? '', $needle)
                || $this->contains($r['public_slug'] ?? '', $needle));
        }

        $rows = $this->sortBy(array_values($rows), 'updated_at', 'desc');

        return $this->paginate($rows, (int) ($filters['limit'] ?? 200));
    }

    /**
     * @param array<string,mixed> $patch
     * @return array<string,mixed>|null
     */
    public function update(string $uuid, array $patch): ?array
    {
        return $this->patch($uuid, $patch, self::UPDATABLE);
    }

    public function recordView(string $uuid): void
    {
        $now = $this->now();
        $this->store->update($this->collection(), $uuid, static function (array $r) use ($now): array {
            $r['view_count']     = (int) ($r['view_count'] ?? 0) + 1;
            $r['last_viewed_at'] = $now;

            return $r;
        });
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function dueForExpiry(string $nowIso): array
    {
        return array_values(array_filter($this->records(), static fn (array $r): bool => (string) ($r['status'] ?? '') === PreviewStatus::ACTIVE
            && ($r['expires_at'] ?? null) !== null
            && (string) $r['expires_at'] < $nowIso));
    }

    public function delete(string $uuid): void
    {
        $this->store->delete($this->collection(), $uuid);
    }

    public function count(string $status = ''): int
    {
        $rows = array_filter($this->records(), static fn (array $r): bool => ($r['archived_at'] ?? null) === null);

        if ($status !== '') {
            $rows = array_filter($rows, static fn (array $r): bool => ($r['status'] ?? null) === $status);
        }

        return count($rows);
    }
}
