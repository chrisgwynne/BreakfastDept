<?php

declare(strict_types=1);

namespace Breakfast\Platform\ClientPreviews;

use Breakfast\Platform\Support\Clock;

/**
 * Housekeeping for Client Previews: expire due previews, prune abandoned upload
 * scratch directories, trim access-event history to the retention window and
 * enforce the per-preview version cap on disk. Supports a dry run so an operator
 * can preview the effect before committing.
 */
final class PreviewCleanupService
{
    /**
     * @param array<string,mixed> $config previews config block
     */
    public function __construct(
        private readonly PreviewRepository $previews,
        private readonly PreviewStorage $storage,
        private readonly PreviewAnalyticsService $analytics,
        private readonly PreviewExpiryService $expiry,
        private readonly PreviewPublisher $publisher,
        private readonly array $config,
    ) {
    }

    /**
     * @return array{expired:int,temp_removed:int,events_pruned:int,versions_pruned:int,dry_run:bool}
     */
    public function run(bool $dryRun = false): array
    {
        if ($dryRun) {
            return [
                'expired'         => count($this->previews->dueForExpiry(Clock::nowIso())),
                'temp_removed'    => $this->countStaleTemp(),
                'events_pruned'   => 0,
                'versions_pruned' => 0,
                'dry_run'         => true,
            ];
        }

        $expired = $this->expiry->expireDue();

        $tempCutoff = Clock::timestamp() - (max(1, (int) ($this->config['tempRetentionHours'] ?? 24)) * 3600);
        $tempRemoved = $this->storage->pruneTempBefore($tempCutoff);

        $eventsPruned = $this->analytics->prune();

        $versionsBefore = $this->countVersionsOnDisk();
        foreach ($this->previews->all(['limit' => 5000]) as $preview) {
            $this->publisher->pruneVersions((string) $preview['uuid']);
        }
        $versionsPruned = max(0, $versionsBefore - $this->countVersionsOnDisk());

        return [
            'expired'         => $expired['expired'],
            'temp_removed'    => $tempRemoved,
            'events_pruned'   => $eventsPruned,
            'versions_pruned' => $versionsPruned,
            'dry_run'         => false,
        ];
    }

    private function countStaleTemp(): int
    {
        $base = $this->storage->root() . '/temporary';
        if (is_dir($base) === false) {
            return 0;
        }
        $cutoff = Clock::timestamp() - (max(1, (int) ($this->config['tempRetentionHours'] ?? 24)) * 3600);
        $n = 0;
        foreach (scandir($base) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $base . '/' . $item;
            if (is_dir($path) && filemtime($path) !== false && filemtime($path) < $cutoff) {
                $n++;
            }
        }

        return $n;
    }

    private function countVersionsOnDisk(): int
    {
        $base = $this->storage->root() . '/versions';
        if (is_dir($base) === false) {
            return 0;
        }
        $n = 0;
        foreach (scandir($base) ?: [] as $preview) {
            if ($preview === '.' || $preview === '..') {
                continue;
            }
            $dir = $base . '/' . $preview;
            if (is_dir($dir) === false) {
                continue;
            }
            foreach (scandir($dir) ?: [] as $version) {
                if ($version !== '.' && $version !== '..' && is_dir($dir . '/' . $version)) {
                    $n++;
                }
            }
        }

        return $n;
    }
}
