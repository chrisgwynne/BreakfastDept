<?php

declare(strict_types=1);

namespace Breakfast\Platform\Support;

/**
 * Base class for the flat-file repositories that replace the CRM's database
 * tables. Each repository maps to one FileStore collection (a directory of
 * one-JSON-file-per-record) and does its querying in PHP — load the collection,
 * filter, sort, paginate — which is exactly how Kirby treats content and is more
 * than fast enough for a studio's data volumes.
 *
 * The old repositories extended a SQL base (INSERT/UPDATE builders, JSON
 * encode/decode). Here records are plain associative arrays: nested arrays are
 * stored as-is (no JSON column marshalling), so a record read back has the same
 * shape it was written with.
 */
abstract class FileRepository
{
    public function __construct(protected readonly FileStore $store)
    {
    }

    /** The FileStore collection (directory) this repository owns. */
    abstract protected function collection(): string;

    protected function now(): string
    {
        return Clock::nowIso();
    }

    /**
     * Every record in the collection.
     *
     * @return list<array<string,mixed>>
     */
    protected function records(): array
    {
        return $this->store->all($this->collection());
    }

    /** @return array<string,mixed>|null */
    protected function findRecord(string $uuid): ?array
    {
        return $this->store->find($this->collection(), $uuid);
    }

    /**
     * Write a full record (create or overwrite).
     *
     * @param array<string,mixed> $record
     * @return array<string,mixed>
     */
    protected function persist(array $record): array
    {
        return $this->store->put($this->collection(), $record);
    }

    /**
     * Apply a partial update: merge only whitelisted keys over the stored
     * record and stamp updated_at. Returns null if the record is gone.
     *
     * @param array<string,mixed> $data
     * @param list<string>        $allowed
     * @return array<string,mixed>|null
     */
    protected function patch(string $uuid, array $data, array $allowed): ?array
    {
        $now = $this->now();

        return $this->store->update($this->collection(), $uuid, function (array $record) use ($data, $allowed, $now): array {
            foreach ($allowed as $col) {
                if (array_key_exists($col, $data)) {
                    $record[$col] = $data[$col];
                }
            }
            $record['updated_at'] = $now;

            return $record;
        });
    }

    /**
     * Case-insensitive "contains" — the PHP equivalent of SQL LIKE '%needle%'.
     */
    protected function contains(mixed $haystack, string $needle): bool
    {
        if ($needle === '' || !is_string($haystack)) {
            return $needle === '';
        }

        return stripos($haystack, $needle) !== false;
    }

    /**
     * Sort records by a field. Direction 'desc' reverses. Null/missing values
     * sort last. Comparison is string-based (ISO timestamps and names both sort
     * correctly), which mirrors the SQL ORDER BY these replace.
     *
     * @param list<array<string,mixed>> $records
     * @return list<array<string,mixed>>
     */
    protected function sortBy(array $records, string $field, string $direction = 'asc'): array
    {
        usort($records, function (array $a, array $b) use ($field, $direction): int {
            $av = $a[$field] ?? null;
            $bv = $b[$field] ?? null;

            if ($av === $bv) {
                return 0;
            }
            if ($av === null) {
                return 1;
            }
            if ($bv === null) {
                return -1;
            }

            $cmp = strcasecmp((string) $av, (string) $bv);

            return $direction === 'desc' ? -$cmp : $cmp;
        });

        return $records;
    }

    /**
     * Apply limit/offset to an already-filtered, already-sorted list.
     *
     * @param list<array<string,mixed>> $records
     * @return list<array<string,mixed>>
     */
    protected function paginate(array $records, int $limit, int $offset = 0): array
    {
        return array_values(array_slice($records, max(0, $offset), max(0, $limit)));
    }

    /**
     * Allocate the next value of a named monotonic counter (e.g. per-year
     * reference numbering), atomically.
     */
    protected function nextSequence(string $name): int
    {
        return $this->store->bump('counters', $name);
    }
}
