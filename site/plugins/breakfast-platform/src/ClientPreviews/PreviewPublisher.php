<?php

declare(strict_types=1);

namespace Breakfast\Platform\ClientPreviews;

use Breakfast\Platform\Support\Clock;
use Breakfast\Platform\Support\Database;

/**
 * Publishing and rollback for Client Previews.
 *
 * Publish is a deliberate, audited human action: it points the preview at a
 * "ready" (or previously-superseded, for rollback) version, supersedes the rest,
 * and flips the preview to ACTIVE. The version-pointer + status changes are made
 * inside a single database transaction, so the responder (which reads the
 * current pointer) never observes a half-published state. Retention prunes
 * superseded versions beyond the configured cap.
 */
final class PreviewPublisher
{
    /**
     * @param array<string,mixed> $config previews config block
     */
    public function __construct(
        private readonly PreviewRepository $previews,
        private readonly PreviewVersionRepository $versions,
        private readonly PreviewStorage $storage,
        private readonly Database $db,
        private readonly array $config,
    ) {
    }

    /**
     * @return array{ok:bool,error?:string,preview?:array<string,mixed>}
     */
    public function publish(string $previewUuid, string $versionUuid, ?string $actor): array
    {
        $preview = $this->previews->find($previewUuid);
        $version = $this->versions->find($versionUuid);
        if ($preview === null || $version === null || (string) $version['preview_uuid'] !== $previewUuid) {
            return ['ok' => false, 'error' => 'not_found'];
        }
        if (in_array((string) $version['state'], [PreviewStatus::V_READY, PreviewStatus::V_SUPERSEDED, PreviewStatus::V_PUBLISHED], true) === false) {
            return ['ok' => false, 'error' => 'version_not_publishable'];
        }

        $now = Clock::nowIso();

        // Flip the version pointer + statuses atomically. If any write fails the
        // transaction rolls back, so the responder never sees a half-published
        // state (e.g. the current pointer moved but the version not marked
        // published). Filesystem pruning happens AFTER the commit.
        $updated = $this->db->transaction(function () use ($previewUuid, $versionUuid, $version, $actor, $now) {
            $this->versions->update($versionUuid, ['state' => PreviewStatus::V_PUBLISHED, 'published_at' => $now]);
            $this->versions->supersedeOthers($previewUuid, $versionUuid);

            return $this->previews->update($previewUuid, [
                'current_version_uuid' => $versionUuid,
                'entry_file'           => (string) $version['entry_file'],
                'bytes'                => (int) $version['bytes'],
                'file_count'           => (int) $version['file_count'],
                'version_count'        => count($this->versions->forPreview($previewUuid)),
                'status'               => PreviewStatus::ACTIVE,
                'published_at'         => $now,
                'published_by'         => $actor,
                'updated_by'           => $actor,
                'disabled_at'          => null,
            ]);
        });

        $this->pruneVersions($previewUuid);

        return ['ok' => true, 'preview' => $updated ?? []];
    }

    /**
     * Roll back to a specific older version (which becomes the published one).
     *
     * @return array{ok:bool,error?:string,preview?:array<string,mixed>}
     */
    public function rollback(string $previewUuid, string $versionUuid, ?string $actor): array
    {
        return $this->publish($previewUuid, $versionUuid, $actor);
    }

    /**
     * Keep the newest N versions; archive + delete the storage of anything older.
     * Never removes the currently-published version.
     */
    public function pruneVersions(string $previewUuid): void
    {
        $max     = (int) (is_array($this->config['limits'] ?? null) ? ($this->config['limits']['maxVersions'] ?? 10) : 10);
        $preview = $this->previews->find($previewUuid);
        $current = $preview !== null ? (string) ($preview['current_version_uuid'] ?? '') : '';

        $all = $this->versions->forPreview($previewUuid); // newest first
        $kept = 0;
        foreach ($all as $version) {
            $uuid = (string) $version['uuid'];
            $kept++;
            if ($kept <= $max || $uuid === $current) {
                continue;
            }
            if ((string) $version['state'] === PreviewStatus::V_ARCHIVED) {
                continue;
            }
            // Remove the on-disk version directory; keep the row for history.
            $this->storage->deleteTree($this->storage->versionDir($previewUuid, $uuid));
            $this->versions->update($uuid, ['state' => PreviewStatus::V_ARCHIVED, 'archived_at' => Clock::nowIso()]);
        }
    }
}
