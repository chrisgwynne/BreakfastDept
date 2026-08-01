<?php

declare(strict_types=1);

namespace Breakfast\Platform\Composer;

use Breakfast\Platform\Portfolio\PortfolioSimilarity;

/**
 * Storytelling health + uniqueness scores for a case study.
 *
 * Not SEO, not accessibility — this measures whether the page tells a strong,
 * memorable story and whether it stands apart from the rest of the portfolio.
 * Every sub-score comes with a plain-language reason so the editor knows exactly
 * what to improve. Pure and framework-free.
 */
final class StoryHealth
{
    /**
     * @param array<string,mixed> $record
     * @return array{overall:int,metrics:list<array{key:string,label:string,score:int,reason:string}>}
     */
    public static function score(array $record): array
    {
        /** @var list<string> $blocks */
        $blocks = array_values(array_map('strval', is_array($record['blocks'] ?? null) ? $record['blocks'] : []));
        $n = count($blocks);
        $imageCount = max(0, (int) ($record['imageCount'] ?? 0));
        $ending = (string) ($record['ending'] ?? '');
        $rhythm = RhythmAnalysis::analyse($blocks);

        $m = [];

        // Opening impact — does the page open on a statement or a big image?
        $first = $blocks[0] ?? '';
        $openImpact = in_array($first, ['cs-statement', 'cs-interlude'], true) ? 90
            : (in_array($first, ['cs-shot', 'cs-fullbleed', 'cs-composition', 'cs-devices'], true) ? 80 : 45);
        $m[] = ['key' => 'opening', 'label' => 'Opening impact', 'score' => $openImpact,
            'reason' => $openImpact >= 80 ? 'Opens on a strong ' . ($first ?: 'block') . '.' : 'Opens on a quiet block — lead with a statement or a big image.'];

        // Visual interest — variety of visual treatments.
        $richTypes = count(array_unique(array_intersect($blocks, BlockTaxonomy::RICH)));
        $visInterest = min(100, 30 + $richTypes * 25);
        $m[] = ['key' => 'visual', 'label' => 'Visual interest', 'score' => $visInterest,
            'reason' => $richTypes >= 2 ? $richTypes . ' crafted treatments (rotate/stack/layer/annotate/devices).' : 'Few crafted treatments — add rotated, stacked, layered or annotated moments.'];

        // Image variety — enough distinct imagery.
        $imgVariety = min(100, $imageCount * 20);
        $m[] = ['key' => 'images', 'label' => 'Image variety', 'score' => $imgVariety,
            'reason' => $imageCount >= 4 ? $imageCount . ' images across the page.' : 'Only ' . $imageCount . ' image(s) — show more of the actual work.'];

        // Reading rhythm — straight from the rhythm score.
        $m[] = ['key' => 'rhythm', 'label' => 'Reading rhythm', 'score' => $rhythm['score'],
            'reason' => $rhythm['flags'] === [] ? 'Text and imagery are well balanced.' : count($rhythm['flags']) . ' pacing issue(s) to smooth out.'];

        // Quote placement.
        $quoteAt = array_search('cs-quote', $blocks, true);
        $quoteScore = $quoteAt === false ? 30 : ($n >= 4 && $quoteAt >= (int) ceil($n * 0.7) ? 55 : 90);
        $m[] = ['key' => 'quote', 'label' => 'Quote placement', 'score' => $quoteScore,
            'reason' => $quoteAt === false ? 'No pull quote — add one line that lands.' : ($quoteScore === 90 ? 'A pull quote lands in the first two-thirds.' : 'The quote sits too low on the page.')];

        // Ending strength.
        $endScore = match ($ending) {
            'giant-shot', 'results', 'testimonial', 'mobile-row' => 90,
            'motif', 'lessons', 'next-teaser' => 80,
            default => 45,
        };
        $m[] = ['key' => 'ending', 'label' => 'Ending strength', 'score' => $endScore,
            'reason' => $endScore >= 80 ? 'Closes on a designed ending (' . $ending . ').' : 'Ending is the generic CTA — choose a designed close.'];

        $overall = (int) round(array_sum(array_column($m, 'score')) / max(1, count($m)));

        return ['overall' => $overall, 'metrics' => $m];
    }

    /**
     * Per-axis uniqueness against the rest of the portfolio, so every case study
     * can be pushed to feel unmistakably different.
     *
     * @param array<string,mixed> $record
     * @param array<string,array<string,mixed>> $others published records keyed by id
     * @return array{overall:int,nearest:?string,axes:list<array{key:string,label:string,score:int,reason:string}>}
     */
    public static function uniqueness(array $record, array $others): array
    {
        if ($others === []) {
            return ['overall' => 100, 'nearest' => null, 'axes' => [
                ['key' => 'overall', 'label' => 'Overall', 'score' => 100, 'reason' => 'The only published case study so far.'],
            ]];
        }

        $analysis = PortfolioSimilarity::analyse($record, $others);
        $nearestId = $analysis['nearest'];
        $nearest = $nearestId !== null ? $others[$nearestId] : [];
        $fa = PortfolioSimilarity::fingerprint($record);
        $fb = PortfolioSimilarity::fingerprint($nearest);

        $axis = static function (string $key, string $label, bool $same, string $a): array {
            return ['key' => $key, 'label' => $label, 'score' => $same ? 30 : 100,
                'reason' => $same ? 'Matches the nearest project (' . $a . ').' : 'Distinct (' . $a . ').'];
        };

        $axes = [
            $axis('hero', 'Hero', $fa['hero'] === $fb['hero'], $fa['hero']),
            $axis('ending', 'Ending', $fa['ending'] === $fb['ending'], $fa['ending']),
            $axis('palette', 'Colour', $fa['accent'] === $fb['accent'] && $fa['bg'] === $fb['bg'], 'palette'),
            $axis('layout', 'Layout', $fa['blocks'] === $fb['blocks'], 'block order'),
        ];
        $overall = (int) round(100 - $analysis['score'] * 100);

        return ['overall' => max(0, min(100, $overall)), 'nearest' => $nearestId, 'axes' => $axes];
    }
}
