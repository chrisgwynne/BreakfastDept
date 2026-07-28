<?php

declare(strict_types=1);

namespace Breakfast\Platform\Support;

/**
 * Flat-file record store — the storage engine the admin runs on instead of a
 * database. Each collection is a directory under storage/data/<collection>/ and
 * each record is a single JSON file named <uuid>.json. Records are plain files:
 * portable, git-friendly, editable, and deployed by copying.
 *
 * Safety without a database:
 *  - Writes are atomic (write a temp file, then rename() — a rename is atomic on
 *    POSIX), so a reader never sees a half-written record.
 *  - Mutations take an exclusive lock on a per-collection lock file, so two
 *    concurrent writers can't interleave (the filesystem equivalent of a row
 *    lock; contention is effectively nil at a solo-studio's volume).
 *  - find/list never lock — reads are always a consistent, fully-written file.
 *
 * This is deliberately small: querying is "load the collection and filter in
 * PHP", which is exactly how Kirby treats content and is more than fast enough
 * for these volumes.
 */
final class FileStore
{
    public function __construct(private readonly string $baseDir)
    {
    }

    /** Absolute path to a collection directory (created on demand). */
    private function dir(string $collection): string
    {
        $safe = preg_replace('/[^a-z0-9_-]/', '', strtolower($collection)) ?? '';
        if ($safe === '') {
            throw new \InvalidArgumentException('Invalid collection name.');
        }
        $dir = rtrim($this->baseDir, '/') . '/' . $safe;
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        return $dir;
    }

    private function path(string $collection, string $id): string
    {
        $safeId = preg_replace('/[^a-z0-9_-]/i', '', $id) ?? '';
        if ($safeId === '') {
            throw new \InvalidArgumentException('Invalid record id.');
        }

        return $this->dir($collection) . '/' . $safeId . '.json';
    }

    /**
     * All records in a collection.
     *
     * @return list<array<string,mixed>>
     */
    public function all(string $collection): array
    {
        $out = [];
        foreach (glob($this->dir($collection) . '/*.json') ?: [] as $file) {
            $raw = @file_get_contents($file);
            if ($raw === false) {
                continue;
            }
            $data = json_decode($raw, true);
            if (is_array($data)) {
                $out[] = $data;
            }
        }

        return $out;
    }

    /** @return array<string,mixed>|null */
    public function find(string $collection, string $id): ?array
    {
        $file = $this->path($collection, $id);
        if (!is_file($file)) {
            return null;
        }
        $raw = @file_get_contents($file);
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true);

        return is_array($data) ? $data : null;
    }

    public function exists(string $collection, string $id): bool
    {
        return is_file($this->path($collection, $id));
    }

    /**
     * Write a record (create or overwrite). The record must carry a 'uuid'.
     *
     * @param array<string,mixed> $record
     * @return array<string,mixed> the written record
     */
    public function put(string $collection, array $record): array
    {
        $id = (string) ($record['uuid'] ?? '');
        if ($id === '') {
            throw new \InvalidArgumentException('A record must have a uuid.');
        }

        return $this->withLock($collection, function () use ($collection, $id, $record): array {
            $this->atomicWrite($this->path($collection, $id), $record);

            return $record;
        });
    }

    /**
     * Write a record only if one with this id does not already exist. Returns
     * false if it was already there (the filesystem equivalent of a UNIQUE
     * constraint — used for idempotent event ingestion). Runs under the
     * collection lock so two concurrent writers can't both create it.
     *
     * @param array<string,mixed> $record
     */
    public function putIfAbsent(string $collection, string $id, array $record): bool
    {
        return $this->withLock($collection, function () use ($collection, $id, $record): bool {
            $file = $this->path($collection, $id);
            if (is_file($file)) {
                return false;
            }
            $this->atomicWrite($file, $record);

            return true;
        });
    }

    /**
     * Update a record in place under the collection lock (read → mutate → write),
     * so concurrent updates to the same record can't clobber each other.
     *
     * @param callable(array<string,mixed>):array<string,mixed> $mutator
     * @return array<string,mixed>|null the updated record, or null if it's gone
     */
    public function update(string $collection, string $id, callable $mutator): ?array
    {
        return $this->withLock($collection, function () use ($collection, $id, $mutator): ?array {
            $file = $this->path($collection, $id);
            if (!is_file($file)) {
                return null;
            }
            $current = json_decode((string) file_get_contents($file), true);
            $current = is_array($current) ? $current : [];
            $next = $mutator($current);
            $this->atomicWrite($file, $next);

            return $next;
        });
    }

    public function delete(string $collection, string $id): bool
    {
        return $this->withLock($collection, function () use ($collection, $id): bool {
            $file = $this->path($collection, $id);

            return is_file($file) ? @unlink($file) : false;
        });
    }

    public function count(string $collection): int
    {
        return count(glob($this->dir($collection) . '/*.json') ?: []);
    }

    /**
     * Atomically increment a named counter and return the new value. Used to
     * allocate monotonic human-readable references (e.g. ENQ-2026-0001) without
     * a database sequence. The whole read-create-increment-write runs under the
     * collection lock, so concurrent callers never collide on the same number.
     */
    public function bump(string $collection, string $id, int $by = 1): int
    {
        return $this->withLock($collection, function () use ($collection, $id, $by): int {
            $file    = $this->path($collection, $id);
            $current = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
            $value   = (is_array($current) ? (int) ($current['value'] ?? 0) : 0) + $by;
            $this->atomicWrite($file, ['uuid' => $id, 'value' => $value]);

            return $value;
        });
    }

    /**
     * @param array<string,mixed> $record
     */
    private function atomicWrite(string $file, array $record): void
    {
        $json = json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new \RuntimeException('Could not encode record.');
        }
        $tmp = $file . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
            throw new \RuntimeException('Could not write record to ' . $file);
        }
        @chmod($tmp, 0664);
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            throw new \RuntimeException('Could not commit record to ' . $file);
        }
    }

    /**
     * Run a mutation while holding an exclusive per-collection lock.
     *
     * @template T
     * @param callable():T $fn
     * @return T
     */
    private function withLock(string $collection, callable $fn): mixed
    {
        $lockFile = $this->dir($collection) . '/.lock';
        $handle = fopen($lockFile, 'c');
        if ($handle === false) {
            // If we can't lock, still perform the (atomic) write — better than failing.
            return $fn();
        }
        try {
            flock($handle, LOCK_EX);

            return $fn();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
