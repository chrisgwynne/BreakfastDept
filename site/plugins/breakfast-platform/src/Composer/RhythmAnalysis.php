<?php

declare(strict_types=1);

namespace Breakfast\Platform\Composer;

/**
 * Visual-rhythm analysis for a case study.
 *
 * Reads the ordered sequence of block kinds and measures how the page *reads* —
 * the balance of text vs image, the pacing (are there stretches with no visual
 * relief, or a wall of images with nothing to read?), the variety, and where the
 * quiet space falls. It returns a rhythm score plus a density track the editor
 * can graph, and specific pacing flags that reference actual positions.
 *
 * This is the measurement the Creative Director and Story Health build on. Pure
 * and framework-free.
 */
final class RhythmAnalysis
{
    /**
     * @param list<string> $blocks ordered block types
     * @return array{
     *   score:int,
     *   textRatio:float,
     *   imageRatio:float,
     *   density:list<array{type:string,kind:string,weight:int}>,
     *   counts:array<string,int>,
     *   flags:list<array{code:string,message:string,at:int}>
     * }
     */
    public static function analyse(array $blocks): array
    {
        $blocks = array_values(array_map('strval', $blocks));
        $n = count($blocks);

        $kinds = array_map([BlockTaxonomy::class, 'kind'], $blocks);
        $counts = [];
        foreach ($kinds as $k) {
            $counts[$k] = ($counts[$k] ?? 0) + 1;
        }

        // Visual weight per kind — how much "loud" the block is on the page.
        $weightOf = static fn (string $kind): int => match ($kind) {
            'fullbleed', 'composition' => 5,
            'images', 'devices'        => 4,
            'image', 'colour'          => 3,
            'quote', 'facts', 'brand', 'annotated', 'sticky' => 2,
            'statement'                => 2,
            'text'                     => 1,
            default                    => 1,
        };
        $density = [];
        foreach ($blocks as $i => $type) {
            $density[] = ['type' => $type, 'kind' => $kinds[$i], 'weight' => $weightOf($kinds[$i])];
        }

        $textCount = ($counts['text'] ?? 0) + ($counts['statement'] ?? 0);
        $visualCount = 0;
        foreach ($kinds as $k) {
            if (in_array($k, ['image', 'images', 'fullbleed', 'devices', 'composition', 'annotated', 'sticky'], true)) {
                $visualCount++;
            }
        }
        $textRatio  = $n > 0 ? round($textCount / $n, 2) : 0.0;
        $imageRatio = $n > 0 ? round($visualCount / $n, 2) : 0.0;

        $flags = [];

        // Two (or more) consecutive quiet/text beats slow the pacing.
        $run = 0;
        for ($i = 0; $i < $n; $i++) {
            $quiet = in_array($kinds[$i], ['text', 'statement'], true);
            $run = $quiet ? $run + 1 : 0;
            if ($run === 2) {
                $flags[] = ['code' => 'consecutive-text', 'message' => 'Two text sections in a row slow the pacing — break them with a screenshot, quote or colour moment.', 'at' => $i];
            }
        }
        // A wall of images with nothing to read.
        $vrun = 0;
        for ($i = 0; $i < $n; $i++) {
            $vis = in_array($kinds[$i], ['image', 'images', 'fullbleed', 'devices', 'composition'], true);
            $vrun = $vis ? $vrun + 1 : 0;
            if ($vrun === 3) {
                $flags[] = ['code' => 'image-wall', 'message' => 'Three image sections in a row with no words — add a statement or quote so the story keeps moving.', 'at' => $i];
            }
        }
        // Same block type repeated back to back.
        for ($i = 1; $i < $n; $i++) {
            if ($blocks[$i] === $blocks[$i - 1]) {
                $flags[] = ['code' => 'repeated-block', 'message' => sprintf('Two %s blocks in a row — vary the treatment or merge them.', $blocks[$i]), 'at' => $i];
            }
        }
        if ($n > 0 && $textRatio > 0.6) {
            $flags[] = ['code' => 'text-heavy', 'message' => 'The page is text-heavy — more than 60% of sections are prose. Add screenshots, a colour panel or a device layout.', 'at' => 0];
        }
        if ($n >= 4 && $visualCount <= 1) {
            $flags[] = ['code' => 'image-starved', 'message' => 'Only one visual section across the whole page — show more of the actual work.', 'at' => 0];
        }

        // Score: variety + balance − pacing problems.
        $distinctKinds = count(array_unique($kinds));
        $variety = $n > 0 ? min(1.0, $distinctKinds / 6) : 0.0;
        $balance = 1.0 - abs(0.5 - ($n > 0 ? $visualCount / max(1, $visualCount + $textCount) : 0.5)) * 2; // 1 at 50/50
        $penalty = min(0.5, count($flags) * 0.12);
        $score = (int) round(max(0.0, min(1.0, 0.45 * $variety + 0.45 * $balance + 0.10 - $penalty)) * 100);

        return [
            'score'      => $score,
            'textRatio'  => $textRatio,
            'imageRatio' => $imageRatio,
            'density'    => $density,
            'counts'     => $counts,
            'flags'      => $flags,
        ];
    }
}
