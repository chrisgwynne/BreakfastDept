<?php

declare(strict_types=1);

namespace Breakfast\Platform\ClientPreviews;

use Breakfast\Platform\Crm\Repository;
use Breakfast\Platform\Support\Uuid;

/**
 * Persistence for `client_previews`. All slugs are stored lowercased so the
 * UNIQUE index enforces case-insensitive uniqueness; `slugTaken()` also blocks a
 * slug that is any preview's immediately-previous slug, preventing confusing
 * reuse. Only whitelisted columns are ever written.
 */
final class PreviewRepository extends Repository
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

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function create(array $data): array
    {
        $uuid = Uuid::v4();
        $now  = $this->now();
        $slug = (string) ($data['public_slug'] ?? '');

        $this->db->run(
            'INSERT INTO client_previews
                (uuid, name, client_display_name, contact_uuid, company_uuid, opportunity_uuid,
                 public_slug, status, visibility, indexable, note, entry_file,
                 analytics_enabled, created_by, created_at, updated_at, expires_at)
             VALUES
                (:uuid, :name, :cdn, :contact, :company, :opp,
                 :slug, :status, :visibility, :indexable, :note, :entry,
                 :analytics, :by, :now, :now, :expires)',
            [
                'uuid'       => $uuid,
                'name'       => (string) ($data['name'] ?? 'Untitled preview'),
                'cdn'        => $data['client_display_name'] ?? null,
                'contact'    => $data['contact_uuid'] ?? null,
                'company'    => $data['company_uuid'] ?? null,
                'opp'        => $data['opportunity_uuid'] ?? null,
                'slug'       => strtolower($slug),
                'status'     => (string) ($data['status'] ?? PreviewStatus::DRAFT),
                'visibility' => (string) ($data['visibility'] ?? PreviewStatus::VISIBILITY_UNLISTED),
                'indexable'  => 0,
                'note'       => $data['note'] ?? null,
                'entry'      => (string) ($data['entry_file'] ?? 'index.html'),
                'analytics'  => (int) ($data['analytics_enabled'] ?? 1),
                'by'         => $data['created_by'] ?? null,
                'now'        => $now,
                'expires'    => $data['expires_at'] ?? null,
            ]
        );

        /** @var array<string,mixed> $row */
        $row = $this->find($uuid) ?? [];

        return $row;
    }

    /** @return array<string,mixed>|null */
    public function find(string $uuid): ?array
    {
        return $this->db->one('SELECT * FROM client_previews WHERE uuid = :u', ['u' => $uuid]);
    }

    /** @return array<string,mixed>|null */
    public function findBySlug(string $slug): ?array
    {
        return $this->db->one(
            'SELECT * FROM client_previews WHERE public_slug = :s',
            ['s' => strtolower(trim($slug))]
        );
    }

    /**
     * Find a preview by a slug it USED to have (for canonical redirects from an
     * old subdomain/URL to the current one). Never matches the current slug.
     *
     * @return array<string,mixed>|null
     */
    public function findByPreviousSlug(string $slug): ?array
    {
        return $this->db->one(
            'SELECT * FROM client_previews WHERE previous_slug = :s AND public_slug != :s LIMIT 1',
            ['s' => strtolower(trim($slug))]
        );
    }

    /**
     * Whether a slug is unavailable: taken by another preview's current slug, or
     * held as another preview's immediately-previous slug.
     */
    public function slugTaken(string $slug, ?string $exceptUuid = null): bool
    {
        $slug = strtolower(trim($slug));
        $row  = $this->db->one(
            'SELECT uuid FROM client_previews
             WHERE (public_slug = :s OR previous_slug = :s)
               AND (:except IS NULL OR uuid != :except)
             LIMIT 1',
            ['s' => $slug, 'except' => $exceptUuid]
        );

        return $row !== null;
    }

    /**
     * @param array<string,mixed> $filters
     * @return array<int,array<string,mixed>>
     */
    public function all(array $filters = []): array
    {
        $where  = ['1 = 1'];
        $params = [];

        if (!empty($filters['status'])) {
            $where[]          = 'status = :status';
            $params['status'] = $filters['status'];
        }
        if (isset($filters['include_archived']) && $filters['include_archived'] === false) {
            $where[] = 'archived_at IS NULL';
        }
        if (!empty($filters['contact_uuid'])) {
            $where[]           = 'contact_uuid = :contact';
            $params['contact'] = $filters['contact_uuid'];
        }
        if (!empty($filters['search'])) {
            $where[]         = '(name LIKE :q OR client_display_name LIKE :q OR public_slug LIKE :q)';
            $params['q']     = '%' . $filters['search'] . '%';
        }

        $params['l'] = (int) ($filters['limit'] ?? 200);

        return $this->db->all(
            'SELECT * FROM client_previews WHERE ' . implode(' AND ', $where) .
            ' ORDER BY updated_at DESC LIMIT :l',
            $params
        );
    }

    /**
     * @param array<string,mixed> $patch
     * @return array<string,mixed>|null
     */
    public function update(string $uuid, array $patch): ?array
    {
        $patch['updated_at'] = $this->now();
        [$set, $params] = $this->assignments($patch, [...self::UPDATABLE, 'updated_at']);
        if ($set === '') {
            return $this->find($uuid);
        }
        $params['uuid'] = $uuid;
        $this->db->run('UPDATE client_previews SET ' . $set . ' WHERE uuid = :uuid', $params);

        return $this->find($uuid);
    }

    public function recordView(string $uuid): void
    {
        $this->db->run(
            'UPDATE client_previews SET view_count = view_count + 1, last_viewed_at = :now WHERE uuid = :u',
            ['now' => $this->now(), 'u' => $uuid]
        );
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function dueForExpiry(string $nowIso): array
    {
        return $this->db->all(
            "SELECT * FROM client_previews
             WHERE status = :active AND expires_at IS NOT NULL AND expires_at < :now",
            ['active' => PreviewStatus::ACTIVE, 'now' => $nowIso]
        );
    }

    public function count(string $status = ''): int
    {
        if ($status === '') {
            return (int) $this->db->scalar('SELECT COUNT(*) FROM client_previews WHERE archived_at IS NULL');
        }

        return (int) $this->db->scalar(
            'SELECT COUNT(*) FROM client_previews WHERE status = :s AND archived_at IS NULL',
            ['s' => $status]
        );
    }
}
