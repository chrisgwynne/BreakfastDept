<?php

declare(strict_types=1);

namespace Breakfast\Platform\Content;

/**
 * Minimum art-direction quality gate for a case study.
 *
 * A page marked as ART-DIRECTED must clear real composition minimums before it
 * can be published: an intentional hero + ending, enough meaningful blocks, more
 * than one visual block type, at least one image treatment beyond a plain
 * full-width picture, a project-specific statement or quote, and no visually
 * empty / ineffective blocks. This is the guard that stops the pipeline quietly
 * shipping a sparse one-image page dressed up as bespoke work.
 *
 * A record may opt out by declaring itself a "simple" case study — then the
 * minimums are advisory (warnings) rather than blockers. Nothing here ever
 * silently rewrites content; it only reports blockers and warnings.
 *
 * Pure and framework-free, so it is fully unit-testable. A Kirby adapter
 * ({@see CaseStudyProbe::record()}) builds the record shape it consumes.
 */
final class ArtDirectionQualityGate
{
    public const MIN_BLOCKS          = 3;
    public const MIN_VISUAL_TYPES    = 2;
    public const MIN_IMAGES          = 2;

    /** Blocks that put an image on the page. */
    public const VISUAL_BLOCKS = [
        'cs-shot', 'cs-rotated', 'cs-stack', 'cs-devices', 'cs-fullpage', 'cs-fullbleed',
        'cs-strip', 'cs-beforeafter', 'cs-annotated', 'cs-sticky', 'cs-composition',
        'image', 'gallery',
    ];

    /** A "treatment beyond a standard full-width image" — the crafted moments. */
    public const RICH_TREATMENTS = [
        'cs-rotated', 'cs-stack', 'cs-devices', 'cs-fullpage', 'cs-strip',
        'cs-beforeafter', 'cs-annotated', 'cs-sticky', 'cs-composition',
    ];

    /** Blocks that carry a project-specific statement, quote or result. */
    public const STATEMENT_BLOCKS = ['cs-statement', 'cs-quote', 'cs-interlude', 'cs-facts'];

    /**
     * @param array<string,mixed> $record from {@see CaseStudyProbe::record()}
     * @return array{mode:string,blockers:list<string>,warnings:list<string>,passed:bool}
     */
    public static function evaluate(array $record): array
    {
        $mode = ($record['caseMode'] ?? 'art-directed') === 'simple' ? 'simple' : 'art-directed';

        /** @var list<string> $blocks */
        $blocks = array_values(array_map('strval', is_array($record['blocks'] ?? null) ? $record['blocks'] : []));
        $imageCount = max(0, (int) ($record['imageCount'] ?? 0));
        $presetCustomised = (bool) ($record['presetCustomised'] ?? false);
        $hasScreenshots = (bool) ($record['hasScreenshots'] ?? false);
        /** @var list<array{index:int,type:string,reason:string}> $ineffective */
        $ineffective = is_array($record['ineffectiveBlocks'] ?? null) ? $record['ineffectiveBlocks'] : [];

        $visualTypes = array_values(array_unique(array_intersect($blocks, self::VISUAL_BLOCKS)));
        $richTreatments = array_intersect($blocks, self::RICH_TREATMENTS);
        $hasStatement = array_intersect($blocks, self::STATEMENT_BLOCKS) !== [];

        $hard = [];
        $soft = [];

        // --- Minimum composition (blockers for art-directed records) ---
        if (count($blocks) < self::MIN_BLOCKS) {
            $hard[] = sprintf('Add more content blocks — an art-directed case study needs at least %d (has %d).', self::MIN_BLOCKS, count($blocks));
        }
        if (count($visualTypes) < self::MIN_VISUAL_TYPES) {
            $hard[] = sprintf('Use at least %d different visual block types — the page currently relies on %d.', self::MIN_VISUAL_TYPES, count($visualTypes));
        }
        if ($imageCount < self::MIN_IMAGES) {
            $hard[] = sprintf('Add at least %d public images or screenshots (has %d) — one image reads as a placeholder, not a case study.', self::MIN_IMAGES, $imageCount);
        }
        if ($richTreatments === []) {
            $hard[] = 'Add at least one crafted treatment (rotated, stacked, layered, annotated, device or full-page) — a page of plain full-width images is not art-directed.';
        }
        if (!$hasStatement) {
            $hard[] = 'Add a project-specific statement, pull quote or result — the page needs a point of view, not just images.';
        }
        foreach ($ineffective as $b) {
            $idx = (int) ($b['index'] ?? 0);
            $type = (string) ($b['type'] ?? 'block');
            $reason = (string) ($b['reason'] ?? 'contributes nothing visible');
            $hard[] = sprintf('Block %d (%s) %s — fix or remove it before publishing.', $idx + 1, $type, $reason);
        }

        // --- Editorial warnings (never block on their own) ---
        if ($imageCount === 1) {
            $soft[] = 'The page contains only one image — show more of the actual work.';
        }
        if (count($visualTypes) === 1) {
            $soft[] = 'Only one visual block type is used — vary the image treatments.';
        }
        if (!$presetCustomised) {
            $soft[] = 'The preset has not been customised — adjust the palette, hero or motif so the page is unmistakably this client.';
        }
        if (!$hasScreenshots) {
            $soft[] = 'No captured screenshots are attached — a website case study should show real pages from the site.';
        }

        // Simple case studies: the composition minimums are advisory only.
        if ($mode === 'simple') {
            $soft = array_merge($hard, $soft);
            $hard = [];
        }

        return [
            'mode'     => $mode,
            'blockers' => $hard,
            'warnings' => $soft,
            'passed'   => $hard === [],
        ];
    }

    /**
     * Detect blocks that render nothing meaningful, given each block's type and
     * a small, already-extracted content summary. Pure: the Kirby adapter passes
     * whether required media resolved, whether text is present, and visibility.
     *
     * @param list<array{type:string,hasImage:bool,imageCount:int,hasText:bool,hiddenEverywhere:bool}> $blocks
     * @return list<array{index:int,type:string,reason:string}>
     */
    public static function ineffectiveBlocks(array $blocks): array
    {
        $needsImage = ['cs-shot', 'cs-rotated', 'cs-fullbleed', 'cs-fullpage', 'cs-devices', 'cs-annotated', 'cs-sticky'];
        $needsImages = ['cs-stack', 'cs-strip']; // 2+
        $needsText = ['cs-statement', 'cs-interlude', 'cs-text', 'cs-quote', 'cs-numbered'];

        $out = [];
        foreach ($blocks as $i => $b) {
            $type = (string) ($b['type'] ?? '');
            $hasImage = (bool) ($b['hasImage'] ?? false);
            $imageCount = (int) ($b['imageCount'] ?? 0);
            $hasText = (bool) ($b['hasText'] ?? false);
            $hidden = (bool) ($b['hiddenEverywhere'] ?? false);

            $reason = null;
            if ($hidden) {
                $reason = 'is hidden at every breakpoint';
            } elseif (in_array($type, $needsImage, true) && !$hasImage) {
                $reason = 'has no image';
            } elseif (in_array($type, $needsImages, true) && $imageCount < 2) {
                $reason = 'needs at least two images';
            } elseif (in_array($type, $needsText, true) && !$hasText) {
                $reason = 'has no text';
            }

            if ($reason !== null) {
                $out[] = ['index' => $i, 'type' => $type === '' ? 'block' : $type, 'reason' => $reason];
            }
        }

        return $out;
    }
}
