<?php

declare(strict_types=1);

namespace Breakfast\Platform\Support;

/**
 * Configurable data retention across the platform. Consolidates every "delete
 * old rows / orphaned files" concern into one place with a single policy source,
 * a read-only plan (check / dry-run) and an explicit, opt-in apply step.
 *
 * Design guarantees:
 *  - Nothing is ever deleted without `run(apply: true)`; `plan()` is read-only.
 *  - A retention window of 0 (or null) DISABLES that category — it is never
 *    pruned, so an operator can never accidentally wipe history by leaving a
 *    value blank.
 *  - Orphaned-file scans honour a grace period, so an in-flight upload/validation
 *    is never mistaken for an orphan.
 */
final class RetentionService
{
    /**
     * @param array<string,mixed> $config the `retention` config block
     */
    public function __construct(
        private readonly Platform $platform,
        private readonly array $config,
    ) {
    }

    /**
     * Read-only: how many rows/files each category WOULD remove under the current
     * policy. Used by `retention:check` and `retention:cleanup --dry-run`.
     *
     * @return array<string,array{retain_days:?int,eligible:int}>
     */
    public function plan(): array
    {
        $out = [];
        foreach ($this->categories() as $key => $c) {
            $out[$key] = ['retain_days' => $c['days'], 'eligible' => $c['count']()];
        }

        return $out;
    }

    /**
     * Apply retention. When $apply is false this is a dry run (deletes nothing)
     * and returns the same counts as plan().
     *
     * @return array<string,int>
     */
    public function run(bool $apply): array
    {
        $out = [];
        foreach ($this->categories() as $key => $c) {
            $eligible = $c['count']();
            if ($apply && $eligible > 0) {
                $c['apply']();
            }
            $out[$key] = $eligible;
        }

        return $out;
    }

    /**
     * @return array<string,array{days:?int,count:callable():int,apply:callable():void}>
     */
    private function categories(): array
    {
        $db = $this->platform->db();

        $timeBased = function (string $table, string $column, ?int $days, string $extraWhere = '') use ($db): array {
            if ($days === null || $days <= 0) {
                $noop = static function (): void {
                };

                return ['days' => $days, 'count' => static fn (): int => 0, 'apply' => $noop];
            }
            $cutoff = Clock::now()->modify('-' . $days . ' days')->format('c');
            $where  = $column . ' < :cut' . ($extraWhere !== '' ? ' AND ' . $extraWhere : '');

            return [
                'days'  => $days,
                'count' => static fn (): int => (int) $db->scalar("SELECT COUNT(*) FROM {$table} WHERE {$where}", ['cut' => $cutoff]),
                'apply' => static function () use ($db, $table, $where, $cutoff): void {
                    $db->run("DELETE FROM {$table} WHERE {$where}", ['cut' => $cutoff]);
                },
            ];
        };

        return [
            'crm_activities'     => $timeBased('activities', 'created_at', $this->days('activitiesDays', 730)),
            'preview_analytics'  => $timeBased('preview_access_events', 'occurred_at', $this->days('previewAnalyticsDays', 180)),
            'queue_jobs'         => $timeBased('jobs', 'completed_at', $this->days('queueDays', 30), "status = 'done'"),
            'webhook_deliveries' => $timeBased('webhook_deliveries', 'delivered_at', $this->days('webhookDays', 90), "status = 'delivered'"),
            'email_events'       => $timeBased('email_events', 'event_at', $this->days('emailEventsDays', 90)),
            'audit_log'          => $timeBased('hermes_audit', 'created_at', $this->days('auditDays', 365)),
            'orphaned_uploads'   => $this->orphanedUploads(),
            'orphaned_previews'  => $this->orphanedPreviewDirs(),
        ];
    }

    /**
     * Files under storage/uploads with no matching `uploads` row, older than the
     * grace period.
     *
     * @return array{days:?int,count:callable():int,apply:callable():void}
     */
    private function orphanedUploads(): array
    {
        $dir     = $this->platform->uploadsDir();
        $graceTs = Clock::timestamp() - ($this->graceHours() * 3600);
        $db      = $this->platform->db();

        $find = static function () use ($dir, $graceTs, $db): array {
            if (is_dir($dir) === false) {
                return [];
            }
            $orphans = [];
            foreach (scandir($dir) ?: [] as $item) {
                if ($item === '.' || $item === '..' || $item[0] === '.') {
                    continue;
                }
                $path = $dir . '/' . $item;
                if (is_file($path) === false || (filemtime($path) ?: 0) >= $graceTs) {
                    continue;
                }
                $row = $db->one('SELECT uuid FROM uploads WHERE stored_name = :n LIMIT 1', ['n' => $item]);
                if ($row === null) {
                    $orphans[] = $path;
                }
            }

            return $orphans;
        };

        return [
            'days'  => null,
            'count' => static fn (): int => count($find()),
            'apply' => static function () use ($find): void {
                foreach ($find() as $path) {
                    @unlink($path);
                }
            },
        ];
    }

    /**
     * Version directories under storage/client-previews/versions with no matching
     * `preview_versions` row, older than the grace period.
     *
     * @return array{days:?int,count:callable():int,apply:callable():void}
     */
    private function orphanedPreviewDirs(): array
    {
        $storage = $this->platform->previewStorage();
        $base    = $storage->root() . '/versions';
        $graceTs = Clock::timestamp() - ($this->graceHours() * 3600);
        $db      = $this->platform->db();

        $find = static function () use ($base, $graceTs, $db): array {
            if (is_dir($base) === false) {
                return [];
            }
            $orphans = [];
            foreach (scandir($base) ?: [] as $preview) {
                if ($preview === '.' || $preview === '..') {
                    continue;
                }
                $previewDir = $base . '/' . $preview;
                if (is_dir($previewDir) === false) {
                    continue;
                }
                foreach (scandir($previewDir) ?: [] as $version) {
                    if ($version === '.' || $version === '..') {
                        continue;
                    }
                    $vdir = $previewDir . '/' . $version;
                    if (is_dir($vdir) === false || (filemtime($vdir) ?: 0) >= $graceTs) {
                        continue;
                    }
                    $row = $db->one('SELECT uuid FROM preview_versions WHERE uuid = :u LIMIT 1', ['u' => $version]);
                    if ($row === null) {
                        $orphans[] = $vdir;
                    }
                }
            }

            return $orphans;
        };

        return [
            'days'  => null,
            'count' => static fn (): int => count($find()),
            'apply' => function () use ($find, $storage): void {
                foreach ($find() as $dir) {
                    $storage->deleteTree($dir);
                }
            },
        ];
    }

    private function days(string $key, int $default): int
    {
        $value = $this->config[$key] ?? $default;

        return is_numeric($value) ? (int) $value : $default;
    }

    private function graceHours(): int
    {
        $value = $this->config['orphanGraceHours'] ?? 24;

        return is_numeric($value) ? max(1, (int) $value) : 24;
    }
}
