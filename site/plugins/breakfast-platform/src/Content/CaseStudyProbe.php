<?php

declare(strict_types=1);

namespace Breakfast\Platform\Content;

use Kirby\Cms\Page;

/**
 * Thin Kirby adapter: pulls the image + block inputs a case study needs for
 * {@see CaseStudyWarnings} out of a Kirby page's dynamically-typed content API.
 * Isolated here (and excluded from static analysis, like the other Kirby
 * adapters) so the warning logic itself stays pure and analysed.
 */
final class CaseStudyProbe
{
    /**
     * @return array{images:list<array{label:string,bytes:int,hasAlt:bool}>,counts:array<string,int>}
     */
    public static function probe(Page $page): array
    {
        $images = [];
        foreach ($page->images() as $file) {
            $images[] = [
                'label'  => $file->filename(),
                'bytes'  => (int) $file->size(),
                'hasAlt' => $file->alt()->isNotEmpty(),
            ];
        }

        $counts = [];
        $body = $page->content()->get('body');
        if ($body->isNotEmpty()) {
            foreach ($body->toBlocks() as $block) {
                $type = (string) $block->type();
                $counts[$type] = ($counts[$type] ?? 0) + 1;
            }
        }

        return ['images' => $images, 'counts' => $counts];
    }

    /**
     * Build the normalised record the quality gate and similarity analyser
     * consume: resolved art direction, block sequence, image count and the
     * per-block effectiveness summary. Kept beside {@see probe()} (and excluded
     * from static analysis) so the pure logic stays analysed.
     *
     * @return array<string,mixed>
     */
    public static function record(Page $page): array
    {
        $content = $page->content();

        // Resolve art direction exactly as the public template does.
        $adFields = [];
        foreach (['preset', 'accent', 'secondary', 'bg', 'text', 'display', 'body', 'corner',
                  'border', 'density', 'image_scale', 'rotation', 'caption', 'pullquote',
                  'animation', 'transition', 'motif', 'hero', 'ending'] as $k) {
            $adFields[$k] = $content->get('ad_' . $k)->value();
        }
        $ad = ArtDirection::resolve($adFields);

        // "Customised" = the editor set at least one explicit override beyond the preset.
        $presetCustomised = false;
        foreach ($adFields as $k => $v) {
            if ($k !== 'preset' && trim((string) $v) !== '') {
                $presetCustomised = true;
                break;
            }
        }

        $blocks = [];
        $blockSummaries = [];
        $screenshotImage = false;
        $imageFields = ['image', 'images', 'desktop', 'mobile', 'before', 'after'];
        $textFields  = ['text', 'heading'];
        $screenshotBlocks = ['cs-shot', 'cs-rotated', 'cs-stack', 'cs-devices', 'cs-fullpage', 'cs-strip', 'cs-annotated', 'cs-composition'];

        $body = $content->get('body');
        if ($body->isNotEmpty()) {
            foreach ($body->toBlocks() as $block) {
                $type = (string) $block->type();
                $blocks[] = $type;

                $bc = $block->content();
                $imageCount = 0;
                foreach ($imageFields as $f) {
                    $val = $bc->get($f);
                    if ($val->isNotEmpty()) {
                        $imageCount += max(1, count($val->toData('yaml')));
                    }
                }
                $hasText = false;
                foreach ($textFields as $f) {
                    if ($bc->get($f)->isNotEmpty()) {
                        $hasText = true;
                        break;
                    }
                }
                // Layered composition carries images inside its data payload.
                $compHasImage = $type === 'cs-composition' && str_contains((string) $bc->get('data')->value(), 'image');

                if (in_array($type, $screenshotBlocks, true) && ($imageCount > 0 || $compHasImage)) {
                    $screenshotImage = true;
                }

                $blockSummaries[] = [
                    'type'             => $type,
                    'hasImage'         => $imageCount > 0 || $compHasImage,
                    'imageCount'       => $imageCount,
                    'hasText'          => $hasText,
                    'hiddenEverywhere' => $block->isHidden(),
                ];
            }
        }

        return [
            'id'                => $page->slug(),
            'title'             => (string) $page->title(),
            'caseMode'          => $content->get('case_mode')->value() === 'simple' ? 'simple' : 'art-directed',
            'status'            => trim((string) $content->get('project_status')->value()),
            'preset'            => $ad['preset'],
            'presetCustomised'  => $presetCustomised,
            'hero'              => $ad['data']['hero'],
            'ending'            => $ad['data']['ending'],
            'pullquote'         => $ad['data']['pullquote'],
            'motif'             => $ad['data']['motif'],
            'transition'        => $ad['data']['transition'],
            // Resolved palette (hex), so two pages that land on the same colour
            // register as a palette match regardless of how they got there.
            'accent'            => $ad['vars']['--ad-accent'] ?? '',
            'secondary'         => $ad['vars']['--ad-secondary'] ?? '',
            'bg'                => $ad['vars']['--ad-bg'] ?? '',
            'blocks'            => $blocks,
            'imageCount'        => $page->images()->count(),
            'hasScreenshots'    => $screenshotImage,
            'ineffectiveBlocks' => ArtDirectionQualityGate::ineffectiveBlocks($blockSummaries),
        ];
    }
}
