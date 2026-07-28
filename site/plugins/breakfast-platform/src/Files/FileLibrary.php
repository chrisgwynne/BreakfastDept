<?php

declare(strict_types=1);

namespace Breakfast\Platform\Files;

use Breakfast\Platform\Support\Clock;
use Breakfast\Platform\Support\FileStore;
use Breakfast\Platform\Support\Uuid;

/**
 * Client/project file library — one shared, versioned, secured store.
 *
 * Bytes live OUTSIDE the webroot under opaque keys with 0600 permissions and a
 * sha256 recorded per version; replacement never overwrites bytes in place — it
 * creates a new immutable version and preserves the old. Downloads verify the
 * stored hash. Legal/financial documents (invoices, signed contracts, sent
 * proposals, receipts) are flagged immutable and cannot be replaced or deleted
 * through this library. Deleting a referenced file is blocked by default.
 *
 * File metadata is a flat-file record; each file's versions, links, events and
 * access events live embedded in that record as native arrays. Only the opaque
 * byte blobs remain on disk.
 */
final class FileLibrary
{
    private const COLLECTION = 'client_files';

    /** @var list<string> */
    public const CATEGORIES = ['brand', 'logo', 'photography', 'copy', 'content', 'legal', 'contract', 'invoice', 'proposal', 'website', 'export', 'analytics', 'seo', 'project', 'onboarding', 'general'];

    public function __construct(
        private readonly FileStore $store,
        private readonly string $storageDir,
        private readonly FileValidator $validator = new FileValidator(),
    ) {
    }

    // ==================================================================
    // Read
    // ==================================================================

    /**
     * @param array<string,mixed> $filters
     * @return list<array<string,mixed>>
     */
    public function list(array $filters = []): array
    {
        $rows = $this->store->all(self::COLLECTION);
        if (empty($filters['include_archived'])) {
            $rows = array_filter($rows, static fn (array $f): bool => (int) ($f['archived'] ?? 0) === 0);
        }
        if (!empty($filters['project_uuid'])) {
            $rows = array_filter($rows, static fn (array $f): bool => (string) ($f['project_uuid'] ?? '') === (string) $filters['project_uuid']);
        }
        if (!empty($filters['company_uuid'])) {
            $rows = array_filter($rows, static fn (array $f): bool => (string) ($f['company_uuid'] ?? '') === (string) $filters['company_uuid']);
        }
        if (!empty($filters['category'])) {
            $rows = array_filter($rows, static fn (array $f): bool => (string) ($f['category'] ?? '') === (string) $filters['category']);
        }
        $rows = array_values($rows);
        usort($rows, static fn ($a, $b) => strcmp((string) ($b['updated_at'] ?? ''), (string) ($a['updated_at'] ?? '')));

        // List endpoints never load bytes — only the current version's metadata.
        return array_map(fn (array $f): array => $this->withCurrent($f), array_slice($rows, 0, (int) ($filters['limit'] ?? 200)));
    }

    /** @return array<string,mixed>|null */
    public function find(string $uuid): ?array
    {
        $f = $this->store->find(self::COLLECTION, $uuid);
        if ($f === null) {
            return null;
        }
        $f = $this->withCurrent($f);
        $versions = is_array($f['versions'] ?? null) ? array_values($f['versions']) : [];
        usort($versions, static fn ($a, $b) => (int) ($b['version'] ?? 0) <=> (int) ($a['version'] ?? 0));
        $f['versions'] = array_map(static fn (array $v): array => [
            'uuid' => $v['uuid'] ?? '', 'version' => (int) ($v['version'] ?? 0), 'original_name' => $v['original_name'] ?? '', 'extension' => $v['extension'] ?? '',
            'mime' => $v['mime'] ?? '', 'byte_size' => (int) ($v['byte_size'] ?? 0), 'sha256' => $v['sha256'] ?? '', 'width' => $v['width'] ?? null, 'height' => $v['height'] ?? null,
            'thumb_state' => $v['thumb_state'] ?? '', 'change_note' => $v['change_note'] ?? '', 'uploader' => $v['uploader'] ?? '', 'created_at' => $v['created_at'] ?? '',
        ], $versions);
        $f['links'] = array_map(static fn (array $l): array => [
            'entity_type' => $l['entity_type'] ?? '', 'entity_uuid' => $l['entity_uuid'] ?? '', 'created_at' => $l['created_at'] ?? '',
        ], is_array($f['links'] ?? null) ? array_values($f['links']) : []);
        $events = is_array($f['events'] ?? null) ? array_values($f['events']) : [];
        usort($events, static fn ($a, $b) => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));
        $f['events'] = array_slice(array_map(static fn (array $e): array => [
            'type' => $e['type'] ?? '', 'detail' => $e['detail'] ?? '', 'actor' => $e['actor'] ?? '', 'created_at' => $e['created_at'] ?? '',
        ], $events), 0, 50);

        return $f;
    }

    // ==================================================================
    // Upload / replace
    // ==================================================================

    /**
     * Validate + store a new file (version 1). Returns the file record.
     *
     * @param array<string,mixed> $meta display_name, category, project_uuid, company_uuid, source, immutable, client_visible, tags, links
     * @return array<string,mixed>
     */
    public function upload(string $tmpPath, string $originalName, string $declaredMime, array $meta, string $actor): array
    {
        $v = $this->validator->validate($tmpPath, $originalName, $declaredMime);
        $category = in_array((string) ($meta['category'] ?? 'general'), self::CATEGORIES, true) ? (string) $meta['category'] : 'general';

        $uuid = Uuid::v4();
        $now = Clock::nowIso();
        $version = $this->buildVersion($uuid, 1, $tmpPath, $originalName, $v, (string) ($meta['change_note'] ?? 'Initial upload'), $actor);
        $links = [];
        foreach (is_array($meta['links'] ?? null) ? $meta['links'] : [] as $link) {
            if (is_array($link) && !empty($link['entity_type']) && !empty($link['entity_uuid'])) {
                $links[] = ['uuid' => Uuid::v4(), 'entity_type' => (string) $link['entity_type'], 'entity_uuid' => (string) $link['entity_uuid'], 'created_by' => $actor, 'created_at' => $now];
            }
        }
        $this->store->put(self::COLLECTION, [
            'uuid'            => $uuid,
            'display_name'    => (string) ($meta['display_name'] ?? $originalName),
            'description'     => (string) ($meta['description'] ?? ''),
            'category'        => $category,
            'tags'            => is_array($meta['tags'] ?? null) ? array_values(array_map('strval', $meta['tags'])) : [],
            'folder_uuid'     => $this->nullable($meta['folder_uuid'] ?? null),
            'company_uuid'    => $this->nullable($meta['company_uuid'] ?? null),
            'project_uuid'    => $this->nullable($meta['project_uuid'] ?? null),
            'current_version' => 1,
            'source'          => (string) ($meta['source'] ?? 'upload'),
            'immutable'       => !empty($meta['immutable']) ? 1 : 0,
            'client_visible'  => !empty($meta['client_visible']) ? 1 : 0,
            'archived'        => 0,
            'uploader'        => $actor,
            'versions'        => [$version],
            'links'           => $links,
            'events'          => [],
            'access_events'   => [],
            'created_at'      => $now,
            'updated_at'      => $now,
        ]);
        $this->event($uuid, 'uploaded', 'Uploaded ' . $originalName, $actor);

        return $this->find($uuid) ?? [];
    }

    /**
     * Replace a file's bytes — a NEW immutable version; the previous version is
     * preserved. Refuses immutable (legal/financial) documents.
     *
     * @return array<string,mixed>
     */
    public function replace(string $fileUuid, string $tmpPath, string $originalName, string $declaredMime, string $changeNote, string $actor): array
    {
        $file = $this->store->find(self::COLLECTION, $fileUuid);
        if ($file === null) {
            throw new FileException(404, 'File not found.');
        }
        if ((int) $file['immutable'] === 1) {
            throw new FileException(409, 'This is a protected legal or financial document and cannot be replaced.');
        }
        $v = $this->validator->validate($tmpPath, $originalName, $declaredMime);
        $next = (int) $file['current_version'] + 1;
        $version = $this->buildVersion($fileUuid, $next, $tmpPath, $originalName, $v, $changeNote !== '' ? $changeNote : 'Replaced', $actor);
        $this->store->update(self::COLLECTION, $fileUuid, static function (array $row) use ($version, $next): array {
            $row['versions'][]      = $version;
            $row['current_version'] = $next;
            $row['updated_at']      = Clock::nowIso();

            return $row;
        });
        $this->event($fileUuid, 'replaced', 'New version ' . $next, $actor);

        return $this->find($fileUuid) ?? [];
    }

    /**
     * Roll the current pointer back to a previous version (bytes preserved).
     *
     * @return array<string,mixed>
     */
    public function restoreVersion(string $fileUuid, int $version, string $actor): array
    {
        $file = $this->store->find(self::COLLECTION, $fileUuid);
        if ($file === null) {
            throw new FileException(404, 'File not found.');
        }
        if ((int) $file['immutable'] === 1) {
            throw new FileException(409, 'This protected document cannot be rolled back.');
        }
        if ($this->versionRow($file, $version) === null) {
            throw new FileException(404, 'That version does not exist.');
        }
        $this->store->update(self::COLLECTION, $fileUuid, static function (array $row) use ($version): array {
            $row['current_version'] = $version;
            $row['updated_at']      = Clock::nowIso();

            return $row;
        });
        $this->event($fileUuid, 'rolled_back', 'Current set to version ' . $version, $actor);

        return $this->find($fileUuid) ?? [];
    }

    /**
     * Register an existing immutable pipeline document (invoice/contract/etc.) as
     * a read-only library entry pointing at its own store. No bytes are copied
     * and it can never be replaced through the library.
     */
    /**
     * @param array<string,mixed> $meta
     * @return array<string,mixed>
     */
    public function registerImmutable(array $meta, string $actor): array
    {
        $uuid = Uuid::v4();
        $now = Clock::nowIso();
        $this->store->put(self::COLLECTION, [
            'uuid'            => $uuid,
            'display_name'    => (string) ($meta['display_name'] ?? 'Document'),
            'description'     => (string) ($meta['description'] ?? ''),
            'category'        => (string) ($meta['category'] ?? 'legal'),
            'tags'            => [],
            'folder_uuid'     => null,
            'company_uuid'    => $this->nullable($meta['company_uuid'] ?? null),
            'project_uuid'    => $this->nullable($meta['project_uuid'] ?? null),
            'current_version' => 1,
            'source'          => (string) ($meta['source'] ?? 'system'),
            'immutable'       => 1,
            'client_visible'  => !empty($meta['client_visible']) ? 1 : 0,
            'archived'        => 0,
            'uploader'        => $actor,
            'versions'        => [],
            'links'           => [],
            'events'          => [],
            'access_events'   => [],
            'created_at'      => $now,
            'updated_at'      => $now,
        ]);
        $this->event($uuid, 'registered', 'Linked immutable document', $actor);

        return $this->find($uuid) ?? [];
    }

    // ==================================================================
    // Links / usage
    // ==================================================================

    /** @return array<string,mixed> */
    public function link(string $fileUuid, string $entityType, string $entityUuid, string $actor): array
    {
        if ($this->store->find(self::COLLECTION, $fileUuid) === null) {
            throw new FileException(404, 'File not found.');
        }
        $this->linkInternal($fileUuid, $entityType, $entityUuid, $actor);

        return $this->find($fileUuid) ?? [];
    }

    public function unlink(string $fileUuid, string $entityType, string $entityUuid): void
    {
        $this->store->update(self::COLLECTION, $fileUuid, static function (array $row) use ($entityType, $entityUuid): array {
            $row['links'] = array_values(array_filter(is_array($row['links'] ?? null) ? $row['links'] : [], static fn (array $l): bool => !((string) ($l['entity_type'] ?? '') === $entityType && (string) ($l['entity_uuid'] ?? '') === $entityUuid)));

            return $row;
        });
    }

    /** @return list<array<string,mixed>> */
    public function usage(string $fileUuid): array
    {
        $file = $this->store->find(self::COLLECTION, $fileUuid);

        return array_map(static fn (array $l): array => [
            'entity_type' => $l['entity_type'] ?? '', 'entity_uuid' => $l['entity_uuid'] ?? '', 'created_at' => $l['created_at'] ?? '',
        ], is_array($file['links'] ?? null) ? array_values($file['links']) : []);
    }

    // ==================================================================
    // Archive / restore / delete
    // ==================================================================

    /** @return array<string,mixed> */
    public function archive(string $fileUuid, string $actor): array
    {
        if ($this->store->find(self::COLLECTION, $fileUuid) === null) {
            throw new FileException(404, 'File not found.');
        }
        $this->setArchived($fileUuid, 1);
        $this->event($fileUuid, 'archived', 'Archived', $actor);

        return $this->find($fileUuid) ?? [];
    }

    /** @return array<string,mixed> */
    public function restore(string $fileUuid, string $actor): array
    {
        $this->setArchived($fileUuid, 0);
        $this->event($fileUuid, 'restored', 'Restored', $actor);

        return $this->find($fileUuid) ?? [];
    }

    /**
     * Permanent delete — restricted, blocked for immutable or referenced files.
     *
     * @return array<string,mixed>
     */
    public function delete(string $fileUuid, string $reason, string $actor): array
    {
        $file = $this->store->find(self::COLLECTION, $fileUuid);
        if ($file === null) {
            throw new FileException(404, 'File not found.');
        }
        if ((int) $file['immutable'] === 1) {
            throw new FileException(409, 'A protected legal or financial document cannot be deleted.');
        }
        $refs = count(is_array($file['links'] ?? null) ? $file['links'] : []);
        if ($refs > 0) {
            throw new FileException(409, 'This file is still in use (' . $refs . ' reference(s)). Unlink it first or archive instead.');
        }
        if (trim($reason) === '') {
            throw new FileException(422, 'A reason is required to permanently delete a file.');
        }
        // Remove stored bytes for every version.
        foreach (is_array($file['versions'] ?? null) ? $file['versions'] : [] as $ver) {
            $this->unlinkStored((string) ($ver['storage_key'] ?? ''));
            if ((string) ($ver['thumb_key'] ?? '') !== '') {
                $this->unlinkStored((string) $ver['thumb_key']);
            }
        }
        $this->store->delete(self::COLLECTION, $fileUuid);

        return ['ok' => true, 'reason' => $reason];
    }

    // ==================================================================
    // Download
    // ==================================================================

    /**
     * Read a version's bytes with an integrity check + access-event record.
     *
     * @return array{filename:string,mime:string,bytes:string}
     */
    public function download(string $fileUuid, ?int $version, string $actor, string $context = 'staff'): array
    {
        $file = $this->store->find(self::COLLECTION, $fileUuid);
        if ($file === null) {
            throw new FileException(404, 'File not found.');
        }
        $ver = $version ?? (int) $file['current_version'];
        $row = $this->versionRow($file, $ver);
        if ($row === null) {
            throw new FileException(404, 'That version does not exist.');
        }
        $bytes = $this->readStored((string) $row['storage_key'], (string) $row['sha256']);
        $this->store->update(self::COLLECTION, $fileUuid, static function (array $f) use ($ver, $actor, $context): array {
            $f['access_events'][] = ['uuid' => Uuid::v4(), 'version' => $ver, 'actor' => $actor, 'context' => $context, 'created_at' => Clock::nowIso()];

            return $f;
        });
        $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', (string) $row['original_name']) ?: ('file.' . (string) $row['extension']);

        return ['filename' => $safeName, 'mime' => (string) $row['detected_mime'], 'bytes' => $bytes];
    }

    /** @return array{filename:string,bytes:string}|null */
    public function thumbnail(string $fileUuid): ?array
    {
        $file = $this->store->find(self::COLLECTION, $fileUuid);
        if ($file === null) {
            return null;
        }
        $row = $this->versionRow($file, (int) $file['current_version']);
        if ($row === null || (string) ($row['thumb_state'] ?? '') !== 'ready' || (string) ($row['thumb_key'] ?? '') === '') {
            return null;
        }
        $path = $this->storageDir . '/' . (string) $row['thumb_key'];
        if (!is_file($path)) {
            return null;
        }

        return ['filename' => 'thumb.jpg', 'bytes' => (string) file_get_contents($path)];
    }

    /** @return array<string,mixed>|null exact-hash duplicate (any file's version) */
    public function findDuplicate(string $sha256): ?array
    {
        foreach ($this->store->all(self::COLLECTION) as $file) {
            foreach (is_array($file['versions'] ?? null) ? $file['versions'] : [] as $ver) {
                if ((string) ($ver['sha256'] ?? '') === $sha256) {
                    return ['file_uuid' => (string) $file['uuid'], 'version' => (int) ($ver['version'] ?? 0)];
                }
            }
        }

        return null;
    }

    // ==================================================================
    // Internals
    // ==================================================================

    /**
     * Write a version's bytes (+ thumbnail) to disk and return the version record.
     *
     * @param array{extension:string,detected_mime:string,byte_size:int,sha256:string,width:?int,height:?int,thumbable:bool} $v
     * @return array<string,mixed>
     */
    private function buildVersion(string $fileUuid, int $version, string $tmpPath, string $originalName, array $v, string $note, string $actor): array
    {
        $key = $fileUuid . '/' . $version . '_' . bin2hex(random_bytes(8)) . '.' . $v['extension'];
        $dest = $this->storageDir . '/' . $key;
        $dir = dirname($dest);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new FileException(500, 'Could not store the file.');
        }
        if (!@copy($tmpPath, $dest)) {
            throw new FileException(500, 'Could not store the file.');
        }
        @chmod($dest, 0600);

        $thumbKey = '';
        $thumbState = $v['thumbable'] ? 'processing' : 'unsupported';
        if ($v['thumbable']) {
            $thumbKey = $this->makeThumbnail($dest, $fileUuid, $version);
            $thumbState = $thumbKey !== '' ? 'ready' : 'failed';
        }

        return [
            'uuid' => Uuid::v4(), 'version' => $version, 'original_name' => $originalName, 'extension' => $v['extension'],
            'mime' => $v['detected_mime'], 'detected_mime' => $v['detected_mime'], 'byte_size' => $v['byte_size'], 'sha256' => $v['sha256'],
            'width' => $v['width'], 'height' => $v['height'], 'storage_key' => $key, 'thumb_key' => $thumbKey, 'thumb_state' => $thumbState,
            'change_note' => $note, 'uploader' => $actor, 'created_at' => Clock::nowIso(),
        ];
    }

    private function makeThumbnail(string $sourcePath, string $fileUuid, int $version): string
    {
        if (!function_exists('imagecreatetruecolor')) {
            return '';
        }
        $info = @getimagesize($sourcePath);
        if ($info === false) {
            return '';
        }
        $src = match ($info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($sourcePath),
            IMAGETYPE_PNG  => @imagecreatefrompng($sourcePath),
            IMAGETYPE_GIF  => @imagecreatefromgif($sourcePath),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($sourcePath) : false,
            default        => false,
        };
        if (!$src) {
            return '';
        }
        $w = (int) $info[0];
        $h = (int) $info[1];
        $max = 320;
        $scale = min(1.0, $max / max(1, max($w, $h)));
        $tw = max(1, (int) ($w * $scale));
        $th = max(1, (int) ($h * $scale));
        $thumb = imagecreatetruecolor($tw, $th);
        imagecopyresampled($thumb, $src, 0, 0, 0, 0, $tw, $th, $w, $h);
        $key = $fileUuid . '/thumb_' . $version . '.jpg';
        $dest = $this->storageDir . '/' . $key;
        @mkdir(dirname($dest), 0700, true);
        $ok = @imagejpeg($thumb, $dest, 78);
        imagedestroy($thumb);
        imagedestroy($src);
        if ($ok) {
            @chmod($dest, 0600);

            return $key;
        }

        return '';
    }

    private function readStored(string $key, string $expectedHash): string
    {
        $base = realpath($this->storageDir);
        $real = realpath($this->storageDir . '/' . $key);
        if ($base === false || $real === false || strncmp($real, $base . DIRECTORY_SEPARATOR, strlen($base) + 1) !== 0) {
            throw new FileException(404, 'File not found.');
        }
        $bytes = file_get_contents($real);
        if ($bytes === false || ($expectedHash !== '' && hash('sha256', $bytes) !== $expectedHash)) {
            throw new FileException(409, 'The stored file failed its integrity check.');
        }

        return $bytes;
    }

    private function unlinkStored(string $key): void
    {
        if ($key === '') {
            return;
        }
        $base = realpath($this->storageDir);
        $real = realpath($this->storageDir . '/' . $key);
        if ($base !== false && $real !== false && strncmp($real, $base . DIRECTORY_SEPARATOR, strlen($base) + 1) === 0) {
            @unlink($real);
        }
    }

    private function linkInternal(string $fileUuid, string $entityType, string $entityUuid, string $actor): void
    {
        $this->store->update(self::COLLECTION, $fileUuid, static function (array $row) use ($entityType, $entityUuid, $actor): array {
            $links = is_array($row['links'] ?? null) ? $row['links'] : [];
            foreach ($links as $l) {
                if ((string) ($l['entity_type'] ?? '') === $entityType && (string) ($l['entity_uuid'] ?? '') === $entityUuid) {
                    return $row; // already linked (UNIQUE equivalent)
                }
            }
            $links[] = ['uuid' => Uuid::v4(), 'entity_type' => $entityType, 'entity_uuid' => $entityUuid, 'created_by' => $actor, 'created_at' => Clock::nowIso()];
            $row['links'] = $links;

            return $row;
        });
    }

    private function setArchived(string $fileUuid, int $archived): void
    {
        $this->store->update(self::COLLECTION, $fileUuid, static function (array $row) use ($archived): array {
            $row['archived']   = $archived;
            $row['updated_at'] = Clock::nowIso();

            return $row;
        });
    }

    /**
     * @param array<string,mixed> $file
     * @return array<string,mixed>|null
     */
    private function versionRow(array $file, int $version): ?array
    {
        foreach (is_array($file['versions'] ?? null) ? $file['versions'] : [] as $v) {
            if ((int) ($v['version'] ?? 0) === $version) {
                return $v;
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed> $f
     * @return array<string,mixed>
     */
    private function withCurrent(array $f): array
    {
        $cur = $this->versionRow($f, (int) ($f['current_version'] ?? 0));
        $f['current'] = $cur === null ? [] : [
            'version' => (int) ($cur['version'] ?? 0), 'original_name' => $cur['original_name'] ?? '', 'extension' => $cur['extension'] ?? '',
            'mime' => $cur['mime'] ?? '', 'detected_mime' => $cur['detected_mime'] ?? '', 'byte_size' => (int) ($cur['byte_size'] ?? 0), 'sha256' => $cur['sha256'] ?? '',
            'width' => $cur['width'] ?? null, 'height' => $cur['height'] ?? null, 'thumb_state' => $cur['thumb_state'] ?? '',
        ];
        $f['tags'] = is_array($f['tags'] ?? null) ? array_values(array_map('strval', $f['tags'])) : $this->decodeTags($f['tags'] ?? '[]');
        $f['reference_count'] = count(is_array($f['links'] ?? null) ? $f['links'] : []);

        return $f;
    }

    /** @return list<string> */
    private function decodeTags(mixed $raw): array
    {
        $decoded = json_decode((string) $raw, true);

        return is_array($decoded) ? array_values(array_map('strval', $decoded)) : [];
    }

    private function event(string $fileUuid, string $type, string $detail, string $actor): void
    {
        $this->store->update(self::COLLECTION, $fileUuid, static function (array $row) use ($type, $detail, $actor): array {
            $row['events'][] = ['uuid' => Uuid::v4(), 'type' => $type, 'detail' => $detail, 'actor' => $actor, 'created_at' => Clock::nowIso()];

            return $row;
        });
    }

    private function nullable(mixed $value): ?string
    {
        $v = trim((string) ($value ?? ''));

        return $v === '' ? null : $v;
    }
}
