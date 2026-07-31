<?php

declare(strict_types=1);

namespace Breakfast\Platform\Content;

/**
 * Actionable, non-blocking warnings for a case study: performance budget,
 * oversized images, missing alt text, and too many heavy/animated layout blocks.
 *
 * Pure and framework-free so it is unit-testable; a thin adapter in
 * {@see ContentAudit} extracts the inputs from a Kirby page. These are WARNINGS —
 * they never block publication (that is reserved for real accessibility, privacy
 * or technical failures), but they are surfaced so the editor can act.
 */
final class CaseStudyWarnings
{
    /** Default page image-transfer budget (bytes). Configurable per call. */
    public const DEFAULT_BUDGET = 6_000_000;

    /** A single source image above this is flagged as oversized. */
    public const PER_IMAGE_CAP = 1_500_000;

    /** Heavy image blocks above this count risk page weight / animation overload. */
    public const HEAVY_BLOCK_LIMIT = 16;

    /** Full-page captures above this many on one page are discouraged. */
    public const FULLPAGE_LIMIT = 3;

    private const HEAVY_BLOCKS = ['cs-shot', 'cs-fullbleed', 'cs-fullpage', 'cs-stack', 'cs-devices', 'cs-strip', 'cs-rotated'];

    /**
     * @param list<array{label:string,bytes:int,hasAlt:bool}> $images
     * @param array<string,int>                               $blockCounts type => count
     * @return list<array{category:string,message:string}>
     */
    public static function inspect(array $images, array $blockCounts, int $budget = self::DEFAULT_BUDGET): array
    {
        $warnings = [];

        $total = 0;
        foreach ($images as $img) {
            $total += max(0, $img['bytes']);
            if ($img['bytes'] > self::PER_IMAGE_CAP) {
                $warnings[] = ['category' => 'oversized-image', 'message' => sprintf(
                    'Image "%s" is %s — over the %s per-image cap. Add a smaller crop or re-export.',
                    $img['label'],
                    self::mb($img['bytes']),
                    self::mb(self::PER_IMAGE_CAP)
                )];
            }
            if ($img['hasAlt'] === false) {
                $warnings[] = ['category' => 'missing-alt', 'message' => sprintf('Image "%s" has no alt text — add a description for screen readers.', $img['label'])];
            }
        }
        if ($total > $budget) {
            $warnings[] = ['category' => 'performance-budget', 'message' => sprintf(
                'Estimated image transfer is %s, over the %s budget. Use smaller crops, fewer full-page captures, or the strip/frame treatments.',
                self::mb($total),
                self::mb($budget)
            )];
        }

        $heavy = 0;
        foreach (self::HEAVY_BLOCKS as $t) {
            $heavy += $blockCounts[$t] ?? 0;
        }
        if ($heavy > self::HEAVY_BLOCK_LIMIT) {
            $warnings[] = ['category' => 'too-many-image-blocks', 'message' => sprintf(
                '%d image/animated blocks on one page (limit %d) — consider splitting the story or reusing crops.',
                $heavy,
                self::HEAVY_BLOCK_LIMIT
            )];
        }

        $fullpage = $blockCounts['cs-fullpage'] ?? 0;
        if ($fullpage > self::FULLPAGE_LIMIT) {
            $warnings[] = ['category' => 'too-many-fullpage', 'message' => sprintf(
                '%d full-page captures (limit %d) — these are heavy; prefer clipped strips or a scrollable frame.',
                $fullpage,
                self::FULLPAGE_LIMIT
            )];
        }

        return $warnings;
    }

    private static function mb(int $bytes): string
    {
        return number_format($bytes / 1_000_000, 1) . ' MB';
    }
}
