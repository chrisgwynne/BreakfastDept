<?php

declare(strict_types=1);

namespace Breakfast\Platform\ClientPreviews;

use Breakfast\Platform\Crm\Repository;
use Breakfast\Platform\Support\Uuid;

/**
 * Persistence for `preview_versions` — the immutable, versioned upload history.
 * A preview's current version is published; older ones are superseded and pruned
 * down to a retention cap by the cleanup service.
 */
final class PreviewVersionRepository extends Repository
{
    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function create(string $previewUuid, array $data): array
    {
        $uuid   = Uuid::v4();
        $number = $this->nextNumber($previewUuid);

        $this->db->run(
            'INSERT INTO preview_versions
                (uuid, preview_uuid, version_number, state, entry_file, source_type,
                 original_filename, checksum, bytes, file_count, storage_path,
                 manifest_path, validation_report, created_by, created_at)
             VALUES
                (:uuid, :preview, :num, :state, :entry, :source,
                 :orig, :checksum, :bytes, :files, :storage,
                 :manifest, :report, :by, :now)',
            [
                'uuid'     => $uuid,
                'preview'  => $previewUuid,
                'num'      => $number,
                'state'    => (string) ($data['state'] ?? PreviewStatus::V_UPLOADED),
                'entry'    => (string) ($data['entry_file'] ?? 'index.html'),
                'source'   => (string) ($data['source_type'] ?? 'zip'),
                'orig'     => $data['original_filename'] ?? null,
                'checksum' => $data['checksum'] ?? null,
                'bytes'    => (int) ($data['bytes'] ?? 0),
                'files'    => (int) ($data['file_count'] ?? 0),
                'storage'  => (string) ($data['storage_path'] ?? ''),
                'manifest' => $data['manifest_path'] ?? null,
                'report'   => isset($data['validation_report']) ? $this->encodeJson($data['validation_report']) : null,
                'by'       => $data['created_by'] ?? null,
                'now'      => $this->now(),
            ]
        );

        /** @var array<string,mixed> $row */
        $row = $this->find($uuid) ?? [];

        return $row;
    }

    /** @return array<string,mixed>|null */
    public function find(string $uuid): ?array
    {
        return $this->db->one('SELECT * FROM preview_versions WHERE uuid = :u', ['u' => $uuid]);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function forPreview(string $previewUuid): array
    {
        return $this->db->all(
            'SELECT * FROM preview_versions WHERE preview_uuid = :p ORDER BY version_number DESC',
            ['p' => $previewUuid]
        );
    }

    /**
     * @param array<string,mixed> $patch
     */
    public function update(string $uuid, array $patch): void
    {
        if (isset($patch['validation_report']) && is_array($patch['validation_report'])) {
            $patch['validation_report'] = $this->encodeJson($patch['validation_report']);
        }
        [$set, $params] = $this->assignments($patch, [
            'state', 'entry_file', 'bytes', 'file_count', 'storage_path',
            'manifest_path', 'validation_report', 'published_at', 'superseded_at',
            'archived_at', 'checksum',
        ]);
        if ($set === '') {
            return;
        }
        $params['uuid'] = $uuid;
        $this->db->run('UPDATE preview_versions SET ' . $set . ' WHERE uuid = :uuid', $params);
    }

    /**
     * Mark every published version of a preview (except $keepUuid) superseded.
     */
    public function supersedeOthers(string $previewUuid, string $keepUuid): void
    {
        $this->db->run(
            "UPDATE preview_versions
             SET state = :superseded, superseded_at = :now
             WHERE preview_uuid = :p AND uuid != :keep AND state = :published",
            [
                'superseded' => PreviewStatus::V_SUPERSEDED,
                'now'        => $this->now(),
                'p'          => $previewUuid,
                'keep'       => $keepUuid,
                'published'  => PreviewStatus::V_PUBLISHED,
            ]
        );
    }

    private function nextNumber(string $previewUuid): int
    {
        return 1 + (int) $this->db->scalar(
            'SELECT COALESCE(MAX(version_number), 0) FROM preview_versions WHERE preview_uuid = :p',
            ['p' => $previewUuid]
        );
    }
}
