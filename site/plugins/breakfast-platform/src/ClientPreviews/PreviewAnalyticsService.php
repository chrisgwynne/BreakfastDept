<?php

declare(strict_types=1);

namespace Breakfast\Platform\ClientPreviews;

use Breakfast\Platform\Support\Clock;

/**
 * Lightweight, first-party, privacy-preserving analytics for previews. It never
 * loads third-party trackers and never claims a specific person viewed a page —
 * only aggregate view counts and a coarse unique estimate (distinct keyed IP
 * hashes). Retention is bounded by config.
 */
final class PreviewAnalyticsService
{
    /**
     * @param array<string,mixed> $config previews config block
     */
    public function __construct(
        private readonly PreviewActivityRepository $activity,
        private readonly array $config,
    ) {
    }

    /**
     * @return array<string,int>
     */
    public function summary(string $previewUuid): array
    {
        return $this->activity->summary($previewUuid);
    }

    /**
     * Prune access events older than the configured retention window.
     */
    public function prune(): int
    {
        $days   = (int) ($this->config['analyticsRetentionDays'] ?? 180);
        $cutoff = Clock::now()->modify('-' . max(1, $days) . ' days')->format('c');

        return $this->activity->pruneAccessBefore($cutoff);
    }
}
