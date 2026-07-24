<?php

declare(strict_types=1);

namespace Breakfast\Platform\Files;

use Breakfast\Platform\Support\Clock;
use Breakfast\Platform\Support\Database;
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
 */
final class FileLibrary
{
    /** @var list<string> */
    public const CATEGORIES = ['brand', 'logo', 'photography', 'copy', 'content', 'legal', 'contract', 'invoice', 'proposal', 'website', 'export', 'analytics', 'seo', 'project', 'onboarding', 'general'];

    public function __construct(
        private readonly Database $db,
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
        $where = ['1 = 1'];
        $params = [];
        if (empty($filters['include_archived'])) {
            $where[] = 'archived = 0';
        }
        if (!empty($filters['project_uuid'])) {
            $where[] = 'project_uuid = :p';
            $params['p'] = (string) $filters['project_uuid'];
        }
        if (!empty($filters['company_uuid'])) {
            $where[] = 'company_uuid = :c';
            $params['c'] = (string) $filters['company_uuid'];
        }
        if (!empty($filters['category'])) {
            $where[] = 'category = :cat';
            $params['cat'] = (string) $filters['category'];
        }
        $params['l'] = (int) ($filters['limit'] ?? 200);
        $rows = $this->db->all('SELECT * FROM client_files WHERE ' . implode(' AND ', $where) . ' ORDER BY updated_at DESC LIMIT :l', $params);

        // List endpoints never load bytes — only the current version's metadata.
        return array_map(fn (array $f): array => $this->withCurrent($f), $rows);
    }

    /** @return array<string,mixed>|null */
    public function find(string $uuid): ?array
    {
        $f = $this->db->one('SELECT * FROM client_files WHERE uuid = :u', ['u' => $uuid]);
        if ($f === null) {
            return null;
        }
        $f = $this->withCurrent($f);
        $f['versions'] = $this->db->all('SELECT uuid, version, original_name, extension, mime, byte_size, sha256, width, height, thumb_state, change_note, uploader, created_at FROM client_file_versions WHERE file_uuid = :u ORDER BY version DESC', ['u' => $uuid]);
        $f['links'] = $this->db->all('SELECT entity_type, entity_uuid, created_at FROM client_file_links WHERE file_uuid = :u', ['u' => $uuid]);
        $f['events'] = $this->db->all('SELECT type, detail, actor, created_at FROM client_file_events WHERE file_uuid = :u ORDER BY created_at DESC LIMIT 50', ['u' => $uuid]);

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

        return $this->db->transaction(function (Database $db) use ($tmpPath, $originalName, $v, $meta, $category, $actor): array {
            $uuid = Uuid::v4();
            $now = Clock::nowIso();
            $db->run(
                'INSERT INTO client_files (uuid, display_name, description, category, tags, folder_uuid, company_uuid, project_uuid, current_version, source, immutable, client_visible, uploader, created_at, updated_at)
                 VALUES (:uuid, :name, :desc, :cat, :tags, :folder, :company, :project, 1, :source, :immutable, :cv, :actor, :now, :now)',
                [
                    'uuid' => $uuid, 'name' => (string) ($meta['display_name'] ?? $originalName), 'desc' => (string) ($meta['description'] ?? ''),
                    'cat' => $category, 'tags' => json_encode(is_array($meta['tags'] ?? null) ? array_values($meta['tags']) : [], JSON_UNESCAPED_SLASHES) ?: '[]',
                    'folder' => $this->nullable($meta['folder_uuid'] ?? null), 'company' => $this->nullable($meta['company_uuid'] ?? null), 'project' => $this->nullable($meta['project_uuid'] ?? null),
                    'source' => (string) ($meta['source'] ?? 'upload'), 'immutable' => !empty($meta['immutable']) ? 1 : 0, 'cv' => !empty($meta['client_visible']) ? 1 : 0,
                    'actor' => $actor, 'now' => $now,
                ]
            );
            $this->writeVersion($db, $uuid, 1, $tmpPath, $originalName, $v, (string) ($meta['change_note'] ?? 'Initial upload'), $actor);
            foreach (is_array($meta['links'] ?? null) ? $meta['links'] : [] as $link) {
                if (is_array($link) && !empty($link['entity_type']) && !empty($link['entity_uuid'])) {
                    $this->linkInternal($db, $uuid, (string) $link['entity_type'], (string) $link['entity_uuid'], $actor);
                }
            }
            $this->event($db, $uuid, 'uploaded', 'Uploaded ' . $originalName, $actor);

            return $this->find($uuid) ?? [];
        });
    }

    /**
     * Replace a file's bytes — a NEW immutable version; the previous version is
     * preserved. Refuses immutable (legal/financial) documents.
     *
     * @return array<string,mixed>
     */
    public function replace(string $fileUuid, string $tmpPath, string $originalName, string $declaredMime, string $changeNote, string $actor): array
    {
        $file = $this->db->one('SELECT * FROM client_files WHERE uuid = :u', ['u' => $fileUuid]);
        if ($file === null) {
            throw new FileException(404, 'File not found.');
        }
        if ((int) $file['immutable'] === 1) {
            throw new FileException(409, 'This is a protected legal or financial document and cannot be replaced.');
        }
        $v = $this->validator->validate($tmpPath, $originalName, $declaredMime);

        return $this->db->transaction(function (Database $db) use ($file, $fileUuid, $tmpPath, $originalName, $v, $changeNote, $actor): array {
            $next = (int) $file['current_version'] + 1;
            $this->writeVersion($db, $fileUuid, $next, $tmpPath, $originalName, $v, $changeNote !== '' ? $changeNote : 'Replaced', $actor);
            $db->run('UPDATE client_files SET current_version = :v, updated_at = :now WHERE uuid = :u', ['v' => $next, 'now' => Clock::nowIso(), 'u' => $fileUuid]);
            $this->event($db, $fileUuid, 'replaced', 'New version ' . $next, $actor);

            return $this->find($fileUuid) ?? [];
        });
    }

    /**
     * Roll the current pointer back to a previous version (bytes preserved).
     *
     * @return array<string,mixed>
     */
    public function restoreVersion(string $fileUuid, int $version, string $actor): array
    {
        $file = $this->db->one('SELECT immutable, current_version FROM client_files WHERE uuid = :u', ['u' => $fileUuid]);
        if ($file === null) {
            throw new FileException(404, 'File not found.');
        }
        if ((int) $file['immutable'] === 1) {
            throw new FileException(409, 'This protected document cannot be rolled back.');
        }
        if ($this->db->one('SELECT uuid FROM client_file_versions WHERE file_uuid = :u AND version = :v', ['u' => $fileUuid, 'v' => $version]) === null) {
            throw new FileException(404, 'That version does not exist.');
        }
        $this->db->run('UPDATE client_files SET current_version = :v, updated_at = :now WHERE uuid = :u', ['v' => $version, 'now' => Clock::nowIso(), 'u' => $fileUuid]);
        $this->event($this->db, $fileUuid, 'rolled_back', 'Current set to version ' . $version, $actor);

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
        $this->db->run(
            'INSERT INTO client_files (uuid, display_name, description, category, tags, company_uuid, project_uuid, current_version, source, immutable, client_visible, uploader, created_at, updated_at)
             VALUES (:uuid, :name, :desc, :cat, \'[]\', :company, :project, 1, :source, 1, :cv, :actor, :now, :now)',
            ['uuid' => $uuid, 'name' => (string) ($meta['display_name'] ?? 'Document'), 'desc' => (string) ($meta['description'] ?? ''), 'cat' => (string) ($meta['category'] ?? 'legal'), 'company' => $this->nullable($meta['company_uuid'] ?? null), 'project' => $this->nullable($meta['project_uuid'] ?? null), 'source' => (string) ($meta['source'] ?? 'system'), 'cv' => !empty($meta['client_visible']) ? 1 : 0, 'actor' => $actor, 'now' => $now]
        );
        $this->event($this->db, $uuid, 'registered', 'Linked immutable document', $actor);

        return $this->find($uuid) ?? [];
    }

    // ==================================================================
    // Links / usage
    // ==================================================================

    /** @return array<string,mixed> */
    public function link(string $fileUuid, string $entityType, string $entityUuid, string $actor): array
    {
        if ($this->db->one('SELECT uuid FROM client_files WHERE uuid = :u', ['u' => $fileUuid]) === null) {
            throw new FileException(404, 'File not found.');
        }
        $this->linkInternal($this->db, $fileUuid, $entityType, $entityUuid, $actor);

        return $this->find($fileUuid) ?? [];
    }

    public function unlink(string $fileUuid, string $entityType, string $entityUuid): void
    {
        $this->db->run('DELETE FROM client_file_links WHERE file_uuid = :f AND entity_type = :t AND entity_uuid = :e', ['f' => $fileUuid, 't' => $entityType, 'e' => $entityUuid]);
    }

    /** @return list<array<string,mixed>> */
    public function usage(string $fileUuid): array
    {
        return $this->db->all('SELECT entity_type, entity_uuid, created_at FROM client_file_links WHERE file_uuid = :u', ['u' => $fileUuid]);
    }

    // ==================================================================
    // Archive / restore / delete
    // ==================================================================

    /** @return array<string,mixed> */
    public function archive(string $fileUuid, string $actor): array
    {
        if ($this->db->one('SELECT uuid FROM client_files WHERE uuid = :u', ['u' => $fileUuid]) === null) {
            throw new FileException(404, 'File not found.');
        }
        $this->db->run('UPDATE client_files SET archived = 1, updated_at = :now WHERE uuid = :u', ['now' => Clock::nowIso(), 'u' => $fileUuid]);
        $this->event($this->db, $fileUuid, 'archived', 'Archived', $actor);

        return $this->find($fileUuid) ?? [];
    }

    /** @return array<string,mixed> */
    public function restore(string $fileUuid, string $actor): array
    {
        $this->db->run('UPDATE client_files SET archived = 0, updated_at = :now WHERE uuid = :u', ['now' => Clock::nowIso(), 'u' => $fileUuid]);
        $this->event($this->db, $fileUuid, 'restored', 'Restored', $actor);

        return $this->find($fileUuid) ?? [];
    }

    /**
     * Permanent delete — restricted, blocked for immutable or referenced files.
     *
     * @return array<string,mixed>
     */
    public function delete(string $fileUuid, string $reason, string $actor): array
    {
        $file = $this->db->one('SELECT * FROM client_files WHERE uuid = :u', ['u' => $fileUuid]);
        if ($file === null) {
            throw new FileException(404, 'File not found.');
        }
        if ((int) $file['immutable'] === 1) {
            throw new FileException(409, 'A protected legal or financial document cannot be deleted.');
        }
        $refs = (int) $this->db->scalar('SELECT COUNT(*) FROM client_file_links WHERE file_uuid = :u', ['u' => $fileUuid]);
        if ($refs > 0) {
            throw new FileException(409, 'This file is still in use (' . $refs . ' reference(s)). Unlink it first or archive instead.');
        }
        if (trim($reason) === '') {
            throw new FileException(422, 'A reason is required to permanently delete a file.');
        }
        // Remove stored bytes for every version.
        foreach ($this->db->all('SELECT storage_key, thumb_key FROM client_file_versions WHERE file_uuid = :u', ['u' => $fileUuid]) as $ver) {
            $this->unlinkStored((string) $ver['storage_key']);
            if ((string) $ver['thumb_key'] !== '') {
                $this->unlinkStored((string) $ver['thumb_key']);
            }
        }
        $this->db->run('DELETE FROM client_files WHERE uuid = :u', ['u' => $fileUuid]);

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
        $file = $this->db->one('SELECT * FROM client_files WHERE uuid = :u', ['u' => $fileUuid]);
        if ($file === null) {
            throw new FileException(404, 'File not found.');
        }
        $ver = $version ?? (int) $file['current_version'];
        $row = $this->db->one('SELECT * FROM client_file_versions WHERE file_uuid = :u AND version = :v', ['u' => $fileUuid, 'v' => $ver]);
        if ($row === null) {
            throw new FileException(404, 'That version does not exist.');
        }
        $bytes = $this->readStored((string) $row['storage_key'], (string) $row['sha256']);
        $this->db->run('INSERT INTO client_file_access_events (uuid, file_uuid, version, actor, context, created_at) VALUES (:uuid, :f, :v, :actor, :ctx, :now)', ['uuid' => Uuid::v4(), 'f' => $fileUuid, 'v' => $ver, 'actor' => $actor, 'ctx' => $context, 'now' => Clock::nowIso()]);
        $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', (string) $row['original_name']) ?: ('file.' . (string) $row['extension']);

        return ['filename' => $safeName, 'mime' => (string) $row['detected_mime'], 'bytes' => $bytes];
    }

    /** @return array{filename:string,bytes:string}|null */
    public function thumbnail(string $fileUuid): ?array
    {
        $file = $this->db->one('SELECT current_version FROM client_files WHERE uuid = :u', ['u' => $fileUuid]);
        if ($file === null) {
            return null;
        }
        $row = $this->db->one("SELECT thumb_key, thumb_state FROM client_file_versions WHERE file_uuid = :u AND version = :v", ['u' => $fileUuid, 'v' => (int) $file['current_version']]);
        if ($row === null || (string) $row['thumb_state'] !== 'ready' || (string) $row['thumb_key'] === '') {
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
        $row = $this->db->one('SELECT file_uuid, version FROM client_file_versions WHERE sha256 = :h LIMIT 1', ['h' => $sha256]);

        return $row === null ? null : ['file_uuid' => (string) $row['file_uuid'], 'version' => (int) $row['version']];
    }

    // ==================================================================
    // Internals
    // ==================================================================

    /** @param array{extension:string,detected_mime:string,byte_size:int,sha256:string,width:?int,height:?int,thumbable:bool} $v */
    private function writeVersion(Database $db, string $fileUuid, int $version, string $tmpPath, string $originalName, array $v, string $note, string $actor): void
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

        $db->run(
            'INSERT INTO client_file_versions (uuid, file_uuid, version, original_name, extension, mime, detected_mime, byte_size, sha256, width, height, storage_key, thumb_key, thumb_state, change_note, uploader, created_at)
             VALUES (:uuid, :f, :v, :orig, :ext, :mime, :dmime, :size, :hash, :w, :h, :key, :tkey, :tstate, :note, :actor, :now)',
            [
                'uuid' => Uuid::v4(), 'f' => $fileUuid, 'v' => $version, 'orig' => $originalName, 'ext' => $v['extension'],
                'mime' => $v['detected_mime'], 'dmime' => $v['detected_mime'], 'size' => $v['byte_size'], 'hash' => $v['sha256'],
                'w' => $v['width'], 'h' => $v['height'], 'key' => $key, 'tkey' => $thumbKey, 'tstate' => $thumbState, 'note' => $note, 'actor' => $actor, 'now' => Clock::nowIso(),
            ]
        );
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
        $base = realpath($this->storageDir);
        $real = realpath($this->storageDir . '/' . $key);
        if ($base !== false && $real !== false && strncmp($real, $base . DIRECTORY_SEPARATOR, strlen($base) + 1) === 0) {
            @unlink($real);
        }
    }

    private function linkInternal(Database $db, string $fileUuid, string $entityType, string $entityUuid, string $actor): void
    {
        $db->run(
            'INSERT INTO client_file_links (uuid, file_uuid, entity_type, entity_uuid, created_by, created_at)
             VALUES (:uuid, :f, :t, :e, :actor, :now) ON CONFLICT (file_uuid, entity_type, entity_uuid) DO NOTHING',
            ['uuid' => Uuid::v4(), 'f' => $fileUuid, 't' => $entityType, 'e' => $entityUuid, 'actor' => $actor, 'now' => Clock::nowIso()]
        );
    }

    /**
     * @param array<string,mixed> $f
     * @return array<string,mixed>
     */
    private function withCurrent(array $f): array
    {
        $cur = $this->db->one('SELECT version, original_name, extension, mime, detected_mime, byte_size, sha256, width, height, thumb_state FROM client_file_versions WHERE file_uuid = :u AND version = :v', ['u' => (string) $f['uuid'], 'v' => (int) $f['current_version']]);
        $f['current'] = $cur ?? [];
        $decoded = json_decode((string) ($f['tags'] ?? '[]'), true);
        $f['tags'] = is_array($decoded) ? array_values(array_map('strval', $decoded)) : [];
        $f['reference_count'] = (int) $this->db->scalar('SELECT COUNT(*) FROM client_file_links WHERE file_uuid = :u', ['u' => (string) $f['uuid']]);

        return $f;
    }

    private function event(Database $db, string $fileUuid, string $type, string $detail, string $actor): void
    {
        $db->run('INSERT INTO client_file_events (uuid, file_uuid, type, detail, actor, created_at) VALUES (:uuid, :f, :type, :detail, :actor, :now)', ['uuid' => Uuid::v4(), 'f' => $fileUuid, 'type' => $type, 'detail' => $detail, 'actor' => $actor, 'now' => Clock::nowIso()]);
    }

    private function nullable(mixed $value): ?string
    {
        $v = trim((string) ($value ?? ''));

        return $v === '' ? null : $v;
    }
}
