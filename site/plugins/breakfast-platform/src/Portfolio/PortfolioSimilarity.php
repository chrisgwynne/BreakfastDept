<?php

declare(strict_types=1);

namespace Breakfast\Platform\Portfolio;

/**
 * Editorial similarity analyser for case studies.
 *
 * Derives a structural fingerprint from a case study's resolved art direction
 * (hero, ending, preset, pull-quote, motif, transition, palette) and its block
 * sequence, then scores how close two case studies are. It is an EDITORIAL guard
 * — not a security control — against publishing two portfolio pages that collapse
 * into the same layout with different words (the exact failure this system is
 * meant to prevent).
 *
 * Pure and framework-free, so it is fully unit-testable. A score of 1.0 means
 * two pages are structurally identical; 0.0 means they share nothing. The
 * {@see THRESHOLD} is the point past which two pages are "too alike" and one of
 * them should be reworked before both go live.
 */
final class PortfolioSimilarity
{
    /** Above this combined score two case studies are flagged as too alike. */
    public const THRESHOLD = 0.68;

    /** Weights sum to 1.0; each axis contributes its share when the two match. */
    private const W_HERO       = 0.17;
    private const W_ENDING     = 0.15;
    private const W_PRESET     = 0.11;
    private const W_PULLQUOTE  = 0.07;
    private const W_MOTIF      = 0.06;
    private const W_TRANSITION = 0.06;
    private const W_PALETTE    = 0.14; // accent + secondary + bg, evenly split
    private const W_BLOCKSET   = 0.13; // Jaccard over block types
    private const W_BLOCKSEQ   = 0.11; // ordered-sequence similarity

    /**
     * Normalise a raw record into a stable fingerprint.
     *
     * @param array<string,mixed> $r
     * @return array{hero:string,ending:string,preset:string,pullquote:string,motif:string,transition:string,accent:string,secondary:string,bg:string,blocks:list<string>}
     */
    public static function fingerprint(array $r): array
    {
        $blocks = [];
        foreach (is_array($r['blocks'] ?? null) ? $r['blocks'] : [] as $b) {
            $blocks[] = (string) $b;
        }

        return [
            'hero'       => (string) ($r['hero'] ?? ''),
            'ending'     => (string) ($r['ending'] ?? ''),
            'preset'     => (string) ($r['preset'] ?? ''),
            'pullquote'  => (string) ($r['pullquote'] ?? ''),
            'motif'      => (string) ($r['motif'] ?? ''),
            'transition' => (string) ($r['transition'] ?? ''),
            'accent'     => (string) ($r['accent'] ?? ''),
            'secondary'  => (string) ($r['secondary'] ?? ''),
            'bg'         => (string) ($r['bg'] ?? ''),
            'blocks'     => $blocks,
        ];
    }

    /**
     * Compare two fingerprints (or raw records — they are normalised first).
     *
     * @param array<string,mixed> $a
     * @param array<string,mixed> $b
     * @return array{score:float,repeatedHero:bool,repeatedEnding:bool,repeatedPreset:bool,repeatedSequence:bool,repeatedPalette:bool,sharedBlockTypes:list<string>,recommendations:list<string>}
     */
    public static function compare(array $a, array $b): array
    {
        $fa = self::fingerprint($a);
        $fb = self::fingerprint($b);

        $score = 0.0;
        $score += $fa['hero'] !== '' && $fa['hero'] === $fb['hero'] ? self::W_HERO : 0.0;
        $score += $fa['ending'] !== '' && $fa['ending'] === $fb['ending'] ? self::W_ENDING : 0.0;
        $score += $fa['preset'] !== '' && $fa['preset'] === $fb['preset'] ? self::W_PRESET : 0.0;
        $score += $fa['pullquote'] !== '' && $fa['pullquote'] === $fb['pullquote'] ? self::W_PULLQUOTE : 0.0;
        $score += $fa['motif'] !== '' && $fa['motif'] === $fb['motif'] ? self::W_MOTIF : 0.0;
        $score += $fa['transition'] !== '' && $fa['transition'] === $fb['transition'] ? self::W_TRANSITION : 0.0;

        $paletteHits = 0;
        foreach (['accent', 'secondary', 'bg'] as $k) {
            if ($fa[$k] !== '' && $fa[$k] === $fb[$k]) {
                $paletteHits++;
            }
        }
        $score += self::W_PALETTE * ($paletteHits / 3);

        $jaccard = self::jaccard($fa['blocks'], $fb['blocks']);
        $score += self::W_BLOCKSET * $jaccard;

        $seq = self::sequenceSimilarity($fa['blocks'], $fb['blocks']);
        $score += self::W_BLOCKSEQ * $seq;

        $shared = array_values(array_intersect(array_unique($fa['blocks']), array_unique($fb['blocks'])));
        sort($shared);

        $repeatedHero     = $fa['hero'] !== '' && $fa['hero'] === $fb['hero'];
        $repeatedEnding   = $fa['ending'] !== '' && $fa['ending'] === $fb['ending'];
        $repeatedPreset   = $fa['preset'] !== '' && $fa['preset'] === $fb['preset'];
        $repeatedPalette  = $paletteHits === 3;
        $repeatedSequence = $fa['blocks'] !== [] && $fa['blocks'] === $fb['blocks'];

        $rec = [];
        if ($repeatedHero) {
            $rec[] = 'Choose a different hero composition — both use "' . $fa['hero'] . '".';
        }
        if ($repeatedEnding) {
            $rec[] = 'Vary the ending — both close on "' . $fa['ending'] . '".';
        }
        if ($repeatedPalette) {
            $rec[] = 'Shift the palette — both share the same accent, secondary and background.';
        }
        if ($seq >= 0.6 && !$repeatedSequence) {
            $rec[] = 'Reorder or swap blocks — the block sequences are closely aligned.';
        }
        if ($repeatedSequence) {
            $rec[] = 'The block sequence is identical — rebuild one page with different layout blocks.';
        }
        if ($jaccard >= 0.6 && count($shared) >= 3) {
            $rec[] = 'Introduce block types the other page does not use (shared: ' . implode(', ', $shared) . ').';
        }

        return [
            'score'            => round($score, 3),
            'repeatedHero'     => $repeatedHero,
            'repeatedEnding'   => $repeatedEnding,
            'repeatedPreset'   => $repeatedPreset,
            'repeatedSequence' => $repeatedSequence,
            'repeatedPalette'  => $repeatedPalette,
            'sharedBlockTypes' => $shared,
            'recommendations'  => $rec,
        ];
    }

    /**
     * Compare a target record against a set of others; return the nearest match.
     *
     * @param array<string,mixed>       $target
     * @param array<string,array<string,mixed>> $others keyed by id
     * @return array{nearest:?string,score:float,tooSimilar:bool,detail:?array<string,mixed>}
     */
    public static function analyse(array $target, array $others): array
    {
        $best = null;
        $bestScore = 0.0;
        $bestDetail = null;
        foreach ($others as $id => $other) {
            $cmp = self::compare($target, $other);
            if ($cmp['score'] > $bestScore) {
                $bestScore = $cmp['score'];
                $best = (string) $id;
                $bestDetail = $cmp;
            }
        }

        return [
            'nearest'    => $best,
            'score'      => round($bestScore, 3),
            'tooSimilar' => $bestScore >= self::THRESHOLD,
            'detail'     => $bestDetail,
        ];
    }

    /**
     * Jaccard index over the SET of block types (order-independent).
     *
     * @param list<string> $a
     * @param list<string> $b
     */
    private static function jaccard(array $a, array $b): float
    {
        $sa = array_unique($a);
        $sb = array_unique($b);
        if ($sa === [] && $sb === []) {
            return 0.0;
        }
        $inter = count(array_intersect($sa, $sb));
        $union = count(array_unique(array_merge($sa, $sb)));

        return $union === 0 ? 0.0 : $inter / $union;
    }

    /**
     * Ordered-sequence similarity via longest common subsequence, normalised by
     * the longer sequence. Rewards pages that walk the reader through the same
     * block order even when the exact set differs.
     *
     * @param list<string> $a
     * @param list<string> $b
     */
    private static function sequenceSimilarity(array $a, array $b): float
    {
        $n = count($a);
        $m = count($b);
        if ($n === 0 || $m === 0) {
            return 0.0;
        }
        // Classic LCS DP.
        $dp = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = 1; $i <= $n; $i++) {
            for ($j = 1; $j <= $m; $j++) {
                $dp[$i][$j] = $a[$i - 1] === $b[$j - 1]
                    ? $dp[$i - 1][$j - 1] + 1
                    : max($dp[$i - 1][$j], $dp[$i][$j - 1]);
            }
        }

        return $dp[$n][$m] / max($n, $m);
    }
}
