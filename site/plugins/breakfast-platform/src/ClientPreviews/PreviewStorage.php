<?php

declare(strict_types=1);

namespace Breakfast\Platform\ClientPreviews;

/**
 * Owns the on-disk layout for validated preview bytes, entirely OUTSIDE the
 * public docroot, the Kirby content tree, the CRM database and git:
 *
 *   {root}/temporary/{token}/            scratch space for upload + validation
 *   {root}/versions/{preview}/{version}/manifest.json
 *   {root}/versions/{preview}/{version}/files/...   the served, immutable files
 *
 * Nothing here executes; the responder streams bytes from `files/` only. Files
 * are written non-executable (0644) inside 0755 directories.
 */
final class PreviewStorage
{
    private const DIR_MODE  = 0755;
    private const FILE_MODE = 0644;

    public function __construct(private readonly string $root)
    {
    }

    public function root(): string
    {
        return $this->root;
    }

    public function ensureRoot(): void
    {
        $this->makeDir($this->root);
        $this->makeDir($this->root . '/temporary');
        $this->makeDir($this->root . '/versions');
    }

    /**
     * Create a fresh, unique scratch directory for one upload/validation run.
     */
    public function createTempDir(string $token): string
    {
        $dir = $this->root . '/temporary/' . preg_replace('/[^a-zA-Z0-9_-]/', '', $token);
        $this->makeDir($dir);

        return $dir;
    }

    public function versionDir(string $previewUuid, string $versionUuid): string
    {
        return $this->root . '/versions/' . $this->safe($previewUuid) . '/' . $this->safe($versionUuid);
    }

    public function versionFilesDir(string $previewUuid, string $versionUuid): string
    {
        return $this->versionDir($previewUuid, $versionUuid) . '/files';
    }

    /**
     * Path, relative to the storage root, of a version directory (what we persist
     * in the DB so paths are portable and never absolute).
     */
    public function relativeVersionDir(string $previewUuid, string $versionUuid): string
    {
        return 'versions/' . $this->safe($previewUuid) . '/' . $this->safe($versionUuid);
    }

    public function absolute(string $relative): string
    {
        return $this->root . '/' . ltrim($relative, '/');
    }

    /**
     * Atomically promote a validated directory of files into a version's final
     * `files/` location. The destination must not pre-exist (versions are
     * immutable). Uses rename() so publish is atomic on the same filesystem.
     */
    public function promote(string $validatedFilesDir, string $previewUuid, string $versionUuid): string
    {
        $versionDir = $this->versionDir($previewUuid, $versionUuid);
        $this->makeDir($versionDir);
        $dest = $versionDir . '/files';

        if (is_dir($dest)) {
            throw new \RuntimeException('Version files directory already exists (versions are immutable)');
        }

        if (@rename($validatedFilesDir, $dest) === false) {
            // Cross-device fallback: deep copy then remove the source.
            $this->copyTree($validatedFilesDir, $dest);
            $this->deleteTree($validatedFilesDir);
        }

        $this->hardenTree($dest);

        return $dest;
    }

    public function writeManifest(string $previewUuid, string $versionUuid, string $json): string
    {
        $versionDir = $this->versionDir($previewUuid, $versionUuid);
        $this->makeDir($versionDir);
        $path = $versionDir . '/manifest.json';
        file_put_contents($path, $json);
        @chmod($path, self::FILE_MODE);

        return $path;
    }

    public function deleteTree(string $path): void
    {
        if (is_link($path)) {
            @unlink($path);

            return;
        }
        if (is_file($path)) {
            @unlink($path);

            return;
        }
        if (is_dir($path) === false) {
            return;
        }
        foreach (scandir($path) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $this->deleteTree($path . '/' . $item);
        }
        @rmdir($path);
    }

    /**
     * Remove scratch directories older than $cutoffTs (unix seconds).
     *
     * @return int number of scratch dirs removed
     */
    public function pruneTempBefore(int $cutoffTs): int
    {
        $base = $this->root . '/temporary';
        if (is_dir($base) === false) {
            return 0;
        }
        $removed = 0;
        foreach (scandir($base) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $base . '/' . $item;
            if (is_dir($path) && filemtime($path) !== false && filemtime($path) < $cutoffTs) {
                $this->deleteTree($path);
                $removed++;
            }
        }

        return $removed;
    }

    private function makeDir(string $dir): void
    {
        if (is_dir($dir) === false) {
            @mkdir($dir, self::DIR_MODE, true);
        }
    }

    private function copyTree(string $src, string $dest): void
    {
        $this->makeDir($dest);
        foreach (scandir($src) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $s = $src . '/' . $item;
            $d = $dest . '/' . $item;
            if (is_link($s)) {
                continue; // never copy symlinks into a served version
            }
            if (is_dir($s)) {
                $this->copyTree($s, $d);
            } else {
                copy($s, $d);
                @chmod($d, self::FILE_MODE);
            }
        }
    }

    private function hardenTree(string $dir): void
    {
        @chmod($dir, self::DIR_MODE);
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->hardenTree($path);
            } else {
                @chmod($path, self::FILE_MODE);
            }
        }
    }

    private function safe(string $segment): string
    {
        return (string) preg_replace('/[^a-zA-Z0-9_-]/', '', $segment);
    }
}
