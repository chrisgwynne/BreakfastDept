<?php

declare(strict_types=1);

namespace Breakfast\Platform\ClientPreviews;

use Breakfast\Platform\Support\FileRepository;
use Breakfast\Platform\Support\Uuid;

/**
 * Flat-file persistence for preview versions — the immutable, versioned upload
 * history. A preview's current version is published; older ones are superseded
 * and pruned down to a retention cap by the cleanup service.
 */
final class PreviewVersionRepository extends FileRepository
{
    /** @var list<string> */
    private const UPDATABLE = [
        'state', 'entry_file', 'bytes', 'file_count', 'storage_path',
        'manifest_path', 'validation_report', 'published_at', 'superseded_at',
        'archived_at', 'checksum',
    ];

    protected function collection(): string
    {
        return 'preview_versions';
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function create(string $previewUuid, array $data): array
    {
        return $this->persist([
            'uuid'              => Uuid::v4(),
            'preview_uuid'      => $previewUuid,
            'version_number'    => $this->nextNumber($previewUuid),
            'state'             => (string) ($data['state'] ?? PreviewStatus::V_UPLOADED),
            'entry_file'        => (string) ($data['entry_file'] ?? 'index.html'),
            'source_type'       => (string) ($data['source_type'] ?? 'zip'),
            'original_filename' => $data['original_filename'] ?? null,
            'checksum'          => $data['checksum'] ?? null,
            'bytes'             => (int) ($data['bytes'] ?? 0),
            'file_count'        => (int) ($data['file_count'] ?? 0),
            'storage_path'      => (string) ($data['storage_path'] ?? ''),
            'manifest_path'     => $data['manifest_path'] ?? null,
            'validation_report' => is_array($data['validation_report'] ?? null) ? $data['validation_report'] : null,
            'published_at'      => null,
            'superseded_at'     => null,
            'archived_at'       => null,
            'created_by'        => $data['created_by'] ?? null,
            'created_at'        => $this->now(),
        ]);
    }

    /** @return array<string,mixed>|null */
    public function find(string $uuid): ?array
    {
        return $this->findRecord($uuid);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function forPreview(string $previewUuid): array
    {
        $rows = array_values(array_filter(
            $this->records(),
            static fn (array $r): bool => (string) ($r['preview_uuid'] ?? '') === $previewUuid
        ));
        // Highest version number first.
        usort($rows, static fn ($a, $b) => (int) ($b['version_number'] ?? 0) <=> (int) ($a['version_number'] ?? 0));

        return $rows;
    }

    /**
     * @param array<string,mixed> $patch
     */
    public function update(string $uuid, array $patch): void
    {
        if (array_key_exists('validation_report', $patch) && !is_array($patch['validation_report'])) {
            $patch['validation_report'] = null;
        }
        $this->patch($uuid, $patch, self::UPDATABLE);
    }

    /**
     * Mark every published version of a preview (except $keepUuid) superseded.
     */
    public function supersedeOthers(string $previewUuid, string $keepUuid): void
    {
        $now = $this->now();
        foreach ($this->records() as $row) {
            if ((string) ($row['preview_uuid'] ?? '') === $previewUuid
                && (string) ($row['uuid'] ?? '') !== $keepUuid
                && (string) ($row['state'] ?? '') === PreviewStatus::V_PUBLISHED) {
                $this->patch((string) $row['uuid'], [
                    'state'         => PreviewStatus::V_SUPERSEDED,
                    'superseded_at' => $now,
                ], self::UPDATABLE);
            }
        }
    }

    /** Remove every version of a preview (used when the preview is deleted). */
    public function deleteForPreview(string $previewUuid): void
    {
        foreach ($this->records() as $row) {
            if ((string) ($row['preview_uuid'] ?? '') === $previewUuid) {
                $this->store->delete($this->collection(), (string) ($row['uuid'] ?? ''));
            }
        }
    }

    private function nextNumber(string $previewUuid): int
    {
        $max = 0;
        foreach ($this->records() as $row) {
            if ((string) ($row['preview_uuid'] ?? '') === $previewUuid) {
                $max = max($max, (int) ($row['version_number'] ?? 0));
            }
        }

        return $max + 1;
    }
}
