<?php

declare(strict_types=1);

namespace Breakfast\Platform\Composer;

use Breakfast\Platform\Portfolio\PortfolioSimilarity;

/**
 * The editorial art director. Continuously reviews the current case study and
 * returns specific, structure-referencing notes — never generic design advice.
 *
 * Every note points at something real on the page: a stretch of two text
 * sections, a pull quote sitting too low, an ending that is still the generic
 * global CTA, a hero that matches another project, a colour rhythm that has
 * become repetitive. Each note carries an actionable suggestion.
 *
 * Pure and framework-free; it composes {@see RhythmAnalysis} and
 * {@see PortfolioSimilarity} over a record the Kirby adapter builds.
 */
final class CreativeDirector
{
    /**
     * @param array<string,mixed> $record current case study
     * @param array<string,array<string,mixed>> $others published records keyed by id
     * @return list<array{code:string,severity:string,message:string,action:string}>
     */
    public static function review(array $record, array $others = []): array
    {
        /** @var list<string> $blocks */
        $blocks = array_values(array_map('strval', is_array($record['blocks'] ?? null) ? $record['blocks'] : []));
        $n = count($blocks);
        $imageCount = max(0, (int) ($record['imageCount'] ?? 0));
        $ending = (string) ($record['ending'] ?? '');
        $hasScreenshots = (bool) ($record['hasScreenshots'] ?? false);

        $rhythm = RhythmAnalysis::analyse($blocks);
        $notes = [];

        // Pacing problems, straight from the rhythm analysis.
        foreach ($rhythm['flags'] as $f) {
            $sev = in_array($f['code'], ['text-heavy', 'image-starved'], true) ? 'high' : 'medium';
            $notes[] = ['code' => $f['code'], 'severity' => $sev, 'message' => $f['message'], 'action' => 'Rework the pacing around section ' . ($f['at'] + 1) . '.'];
        }

        // Imagery breadth.
        if ($imageCount <= 1) {
            $notes[] = ['code' => 'one-image', 'severity' => 'high', 'message' => 'You only have one image — a case study should show several areas of the actual work.', 'action' => 'Capture or upload more screenshots, then add a strip or device layout.'];
        }
        if (!$hasScreenshots && $imageCount >= 1) {
            $notes[] = ['code' => 'no-screenshots', 'severity' => 'medium', 'message' => 'No captured screenshots are in the composition — show real pages from the site.', 'action' => 'Attach captures and drop in a full-width or device block.'];
        }
        $hasBigImage = array_intersect($blocks, ['cs-fullbleed', 'cs-fullpage', 'cs-composition']) !== [];
        if (!$hasBigImage && $imageCount >= 2) {
            $notes[] = ['code' => 'no-full-width', 'severity' => 'medium', 'message' => 'Nothing on the page fills the width — the work never dominates.', 'action' => 'Add a full-bleed screenshot or a layered composition.'];
        }
        if (array_filter($blocks, [BlockTaxonomy::class, 'isRich']) === [] && $imageCount >= 1) {
            $notes[] = ['code' => 'no-crafted-moment', 'severity' => 'high', 'message' => 'Every image is a plain full-width picture — there is no crafted moment.', 'action' => 'Rotate, stack, annotate or layer at least one screenshot.'];
        }

        // Pull quote placement.
        $quoteAt = array_search('cs-quote', $blocks, true);
        if ($quoteAt === false) {
            $notes[] = ['code' => 'no-quote', 'severity' => 'medium', 'message' => 'There is no pull quote — the page has no single line that lands.', 'action' => 'Add a giant pull quote as a visual moment.'];
        } elseif ($n >= 4 && $quoteAt >= (int) ceil($n * 0.7)) {
            $notes[] = ['code' => 'quote-buried', 'severity' => 'medium', 'message' => 'The pull quote is buried near the end — its impact is wasted down there.', 'action' => 'Move the quote into the first two-thirds of the page.'];
        }

        // Ending strength — the generic visit-CTA is not a designed close.
        if ($ending === 'visit' || $ending === '') {
            $notes[] = ['code' => 'weak-ending', 'severity' => 'medium', 'message' => 'The ending is the generic visit-the-site CTA — it feels the same as every other page.', 'action' => 'Choose a designed close: giant screenshot, results, motif or mobile row.'];
        }

        // Colour rhythm.
        $colour = 0;
        foreach ($blocks as $b) {
            if ($b === 'cs-colourblock') {
                $colour++;
            }
        }
        if ($colour >= 3) {
            $notes[] = ['code' => 'colour-repetitive', 'severity' => 'low', 'message' => 'Three or more colour panels — the colour rhythm is becoming repetitive.', 'action' => 'Keep one or two colour moments; let the rest breathe.'];
        }

        // Similarity to published work.
        if ($others !== []) {
            $analysis = PortfolioSimilarity::analyse($record, $others);
            if ($analysis['nearest'] !== null && $analysis['score'] >= 0.5) {
                $pct = (int) round($analysis['score'] * 100);
                $detail = is_array($analysis['detail'] ?? null) ? $analysis['detail'] : [];
                $why = [];
                if (!empty($detail['repeatedHero'])) {
                    $why[] = 'same hero';
                }
                if (!empty($detail['repeatedEnding'])) {
                    $why[] = 'same ending';
                }
                if (!empty($detail['repeatedPalette'])) {
                    $why[] = 'same palette';
                }
                if (!empty($detail['repeatedSequence'])) {
                    $why[] = 'same block order';
                }
                $sev = $analysis['score'] >= PortfolioSimilarity::THRESHOLD ? 'high' : 'low';
                $notes[] = [
                    'code' => 'similar-to-published', 'severity' => $sev,
                    'message' => sprintf('This page feels %d%% like %s%s.', $pct, (string) $analysis['nearest'], $why !== [] ? ' (' . implode(', ', $why) . ')' : ''),
                    'action' => 'Change the hero, ending, palette or block order so it reads as its own project.',
                ];
            }
        }

        return $notes;
    }
}
