<?php

declare(strict_types=1);

namespace Breakfast\Platform\ClientPreviews;

/**
 * Read-only, admin-side inspection of a version's files. It NEVER executes or
 * renders preview content in the admin origin: text/code is returned as plain
 * text for the UI to display escaped, and binary/image files are only described
 * (the UI links to them on the isolated preview origin). Every path is resolved
 * through PreviewPathGuard so nothing outside the version can be read.
 */
final class PreviewFileBrowser
{
    private const MAX_TEXT_BYTES = 262_144; // 256 KB

    private const TEXT_EXT = ['html', 'htm', 'css', 'js', 'mjs', 'json', 'txt', 'xml', 'svg', 'map'];

    public function __construct(private readonly PreviewStorage $storage)
    {
    }

    /**
     * List the files of a version from its manifest.
     *
     * @param array<string,mixed> $version
     * @return list<array{path:string,bytes:int,mime:string}>
     */
    public function listFiles(array $version): array
    {
        $manifestPath = $this->storage->absolute((string) $version['storage_path']) . '/manifest.json';
        if (is_file($manifestPath) === false) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($manifestPath), true);
        if (is_array($decoded) === false || is_array($decoded['files'] ?? null) === false) {
            return [];
        }

        $files = [];
        foreach ($decoded['files'] as $file) {
            if (is_array($file) && isset($file['path'])) {
                $files[] = [
                    'path'  => (string) $file['path'],
                    'bytes' => (int) ($file['bytes'] ?? 0),
                    'mime'  => (string) ($file['mime'] ?? ''),
                ];
            }
        }

        return $files;
    }

    /**
     * Read one file for inspection. Returns text content for code files (capped),
     * or a descriptor for binary/image files — never the raw admin-origin bytes.
     *
     * @param array<string,mixed> $version
     * @return array{ok:bool,error?:string,kind?:string,mime?:string,bytes?:int,content?:string,truncated?:bool,path?:string}
     */
    public function readFile(array $version, string $relPath): array
    {
        $filesDir = $this->storage->absolute((string) $version['storage_path']) . '/files';
        $abs      = PreviewPathGuard::resolveServed($filesDir, $relPath);
        if ($abs === null) {
            return ['ok' => false, 'error' => 'not_found'];
        }

        $ext   = PreviewPathGuard::extension($abs);
        $bytes = (int) filesize($abs);
        $mime  = (string) PreviewPathGuard::mimeFor($abs, true);

        if (in_array($ext, self::TEXT_EXT, true) === false) {
            // Binary/image — describe only; the UI opens it on the preview origin.
            return ['ok' => true, 'kind' => 'binary', 'mime' => $mime, 'bytes' => $bytes, 'path' => $relPath];
        }

        $raw       = (string) file_get_contents($abs, false, null, 0, self::MAX_TEXT_BYTES + 1);
        $truncated = strlen($raw) > self::MAX_TEXT_BYTES;
        if ($truncated) {
            $raw = substr($raw, 0, self::MAX_TEXT_BYTES);
        }

        return [
            'ok'        => true,
            'kind'      => 'text',
            'mime'      => $mime,
            'bytes'     => $bytes,
            'content'   => $raw,
            'truncated' => $truncated,
            'path'      => $relPath,
        ];
    }
}
