<?php

declare(strict_types=1);

namespace Breakfast\Platform\Support;

use RuntimeException;

/**
 * Forward-only SQL migration runner with a history table.
 *
 * Migrations are plain `.sql` files named `NNNN_description.sql`. Each file is
 * applied once, inside a transaction, and recorded in `schema_migrations`.
 *
 * They can be applied via the CLI (`bin/console migrate`), the queue cron, or —
 * for hosts with neither — a guarded boot-time self-heal (see
 * `breakfast_ensure_schema()` in the plugin entry point): it runs at most once
 * per process, only when `hasPending()` is true, under an exclusive file lock so
 * concurrent requests cannot race, and never lets a failure reach the response.
 */
final class Migrator
{
    public function __construct(
        private readonly Database $db,
        private readonly string $migrationsDir
    ) {
    }

    /**
     * Apply all pending migrations.
     *
     * @return list<string> the migration ids that were applied
     */
    public function migrate(): array
    {
        $this->ensureHistoryTable();

        $applied = [];

        foreach ($this->pending() as $id => $file) {
            $sql = file_get_contents($file);

            if ($sql === false) {
                throw new RuntimeException('Cannot read migration ' . $id);
            }

            $this->db->transaction(function (Database $db) use ($sql, $id): void {
                // SQLite/PDO can run multiple statements via exec().
                $db->pdo()->exec($sql);
                $db->run(
                    'INSERT INTO schema_migrations (id, applied_at) VALUES (:id, :at)',
                    ['id' => $id, 'at' => Clock::nowIso()]
                );
            });

            $applied[] = $id;
        }

        return $applied;
    }

    /**
     * Whether any migration has not yet been applied. Cheap: creates the history
     * table if missing, reads the applied ids and compares against the migration
     * files on disk. Used by the boot-time self-heal to avoid taking a lock (or
     * doing any work) once the schema is fully up to date.
     */
    public function hasPending(): bool
    {
        return $this->pending() !== [];
    }

    /**
     * @return array<string,int> migration id => applied_at check (1) — for status output
     */
    public function status(): array
    {
        $this->ensureHistoryTable();
        $done = $this->appliedIds();
        $out  = [];

        foreach ($this->allMigrations() as $id => $file) {
            $out[$id] = in_array($id, $done, true) ? 1 : 0;
        }

        return $out;
    }

    /** @return list<string> */
    public function appliedIds(): array
    {
        $this->ensureHistoryTable();
        $rows = $this->db->all('SELECT id FROM schema_migrations ORDER BY id');

        return array_map(static fn (array $r): string => (string) $r['id'], $rows);
    }

    /** @return array<string,string> id => absolute path, only not-yet-applied */
    private function pending(): array
    {
        $done = $this->appliedIds();
        $out  = [];

        foreach ($this->allMigrations() as $id => $file) {
            if (in_array($id, $done, true) === false) {
                $out[$id] = $file;
            }
        }

        return $out;
    }

    /** @return array<string,string> id => absolute path, sorted */
    private function allMigrations(): array
    {
        if (is_dir($this->migrationsDir) === false) {
            return [];
        }

        $files = glob($this->migrationsDir . '/*.sql') ?: [];
        sort($files, SORT_STRING);

        $out = [];

        foreach ($files as $file) {
            $out[basename($file, '.sql')] = $file;
        }

        return $out;
    }

    private function ensureHistoryTable(): void
    {
        $this->db->pdo()->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                id         TEXT PRIMARY KEY,
                applied_at TEXT NOT NULL
            )'
        );
    }
}
