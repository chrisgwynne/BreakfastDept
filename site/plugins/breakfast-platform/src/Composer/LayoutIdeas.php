<?php

declare(strict_types=1);

namespace Breakfast\Platform\Composer;

use Breakfast\Platform\Content\ArtDirection;

/**
 * Generates genuinely different layout ideas for a case study from the approved
 * Breakfast block vocabulary — never fabricated HTML, always real block
 * structures the editor can apply verbatim.
 *
 * Given what the project actually has (how many images, whether there is a
 * quote, the story length, palette, industry), it proposes a handful of distinct
 * editorial directions — Editorial, Image-led, Collage, Device showcase,
 * Magazine, Minimal, Story-driven — each with its own hero, ending, preset seed
 * and an ordered block sequence. Image-hungry blocks are degraded gracefully when
 * there aren't enough assets, so every idea is buildable. Each idea is
 * self-checked to clear the art-direction quality gate's structural minimums.
 *
 * Pure and framework-free; a Kirby adapter binds the project's real images into
 * the chosen idea.
 */
final class LayoutIdeas
{
    /**
     * Concept templates. Each is a coherent editorial direction. `blocks` are
     * ordered {type, min images the block wants}. Degradation rules turn
     * image-hungry blocks into simpler ones when assets are scarce.
     *
     * @var array<string,array{name:string,rationale:string,preset:string,hero:string,ending:string,pullquote:string,blocks:list<array{type:string,need:int}>}>
     */
    private const CONCEPTS = [
        'editorial' => [
            'name' => 'Editorial', 'rationale' => 'Big type leads, imagery supports. Best when the story is strong.',
            'preset' => 'typography-led', 'hero' => 'editorial', 'ending' => 'motif', 'pullquote' => 'marks',
            'blocks' => [
                ['type' => 'cs-statement', 'need' => 0], ['type' => 'cs-shot', 'need' => 1],
                ['type' => 'cs-text', 'need' => 0], ['type' => 'cs-quote', 'need' => 0], ['type' => 'cs-facts', 'need' => 0],
            ],
        ],
        'image-led' => [
            'name' => 'Image-led', 'rationale' => 'The work fills the screen. Best with several strong screenshots.',
            'preset' => 'image-first', 'hero' => 'cinematic', 'ending' => 'giant-shot', 'pullquote' => 'over-image',
            'blocks' => [
                ['type' => 'cs-fullbleed', 'need' => 1], ['type' => 'cs-strip', 'need' => 2],
                ['type' => 'cs-shot', 'need' => 1], ['type' => 'cs-colourblock', 'need' => 0], ['type' => 'cs-quote', 'need' => 0],
            ],
        ],
        'collage' => [
            'name' => 'Collage', 'rationale' => 'Layered, rotated, playful. Best for lively, expressive brands.',
            'preset' => 'playful-collage', 'hero' => 'collage', 'ending' => 'giant-shot', 'pullquote' => 'rotated',
            'blocks' => [
                ['type' => 'cs-composition', 'need' => 2], ['type' => 'cs-stack', 'need' => 2],
                ['type' => 'cs-colourblock', 'need' => 0], ['type' => 'cs-quote', 'need' => 0], ['type' => 'cs-rotated', 'need' => 1],
            ],
        ],
        'device-showcase' => [
            'name' => 'Device showcase', 'rationale' => 'Desktop + mobile, side by side. Best for responsive product work.',
            'preset' => 'device-showcase', 'hero' => 'layered-devices', 'ending' => 'mobile-row', 'pullquote' => 'panel',
            'blocks' => [
                ['type' => 'cs-devices', 'need' => 2], ['type' => 'cs-strip', 'need' => 2],
                ['type' => 'cs-annotated', 'need' => 1], ['type' => 'cs-facts', 'need' => 0], ['type' => 'cs-quote', 'need' => 0],
            ],
        ],
        'magazine' => [
            'name' => 'Magazine', 'rationale' => 'A long, varied feature with brand moments. Best with rich content.',
            'preset' => 'bold-editorial', 'hero' => 'split', 'ending' => 'results', 'pullquote' => 'giant',
            'blocks' => [
                ['type' => 'cs-statement', 'need' => 0], ['type' => 'cs-shot', 'need' => 1], ['type' => 'cs-interlude', 'need' => 0],
                ['type' => 'cs-strip', 'need' => 2], ['type' => 'cs-quote', 'need' => 0], ['type' => 'cs-colourblock', 'need' => 0],
                ['type' => 'cs-specimen', 'need' => 0],
            ],
        ],
        'minimal' => [
            'name' => 'Minimal', 'rationale' => 'Quiet, premium, lots of air. Best when one or two images say it all.',
            'preset' => 'quiet-premium', 'hero' => 'minimal', 'ending' => 'visit', 'pullquote' => 'marks',
            'blocks' => [
                ['type' => 'cs-statement', 'need' => 0], ['type' => 'cs-shot', 'need' => 1], ['type' => 'cs-quote', 'need' => 0],
            ],
        ],
        'story-driven' => [
            'name' => 'Story-driven', 'rationale' => 'Numbered beats carry the reader. Best for process-led projects.',
            'preset' => 'story-driven', 'hero' => 'minimal', 'ending' => 'results', 'pullquote' => 'giant',
            'blocks' => [
                ['type' => 'cs-numbered', 'need' => 0], ['type' => 'cs-shot', 'need' => 1], ['type' => 'cs-numbered', 'need' => 0],
                ['type' => 'cs-annotated', 'need' => 1], ['type' => 'cs-quote', 'need' => 0], ['type' => 'cs-facts', 'need' => 0],
            ],
        ],
    ];

    /**
     * @param array<string,mixed> $record signals about the project:
     *        imageCount:int, preferredKeys?:list<string>
     * @param int $want how many ideas to return (default 3)
     * @return list<array{key:string,name:string,rationale:string,preset:string,hero:string,ending:string,pullquote:string,blocks:list<string>,visualTypes:int}>
     */
    public static function generate(array $record, int $want = 3): array
    {
        $imageCount = max(0, (int) ($record['imageCount'] ?? 0));

        // Order concepts by fit for the available imagery, but always keep the
        // set diverse. Image-hungry concepts rank higher when images are plenty.
        $order = self::rankConcepts($imageCount);

        $ideas = [];
        foreach ($order as $key) {
            $c = self::CONCEPTS[$key];
            $blocks = self::degrade($c['blocks'], $imageCount);
            if (count($blocks) < 3) {
                continue;
            }
            $visualTypes = count(array_unique(array_filter($blocks, [BlockTaxonomy::class, 'isVisual'])));
            $distinctKinds = count(array_unique(array_map([BlockTaxonomy::class, 'kind'], $blocks)));
            $hasRich  = array_filter($blocks, [BlockTaxonomy::class, 'isRich']) !== [];
            $hasVoice = array_filter($blocks, [BlockTaxonomy::class, 'isVoice']) !== [];
            // Every idea must have voice and real variety. Require screenshot
            // variety only once there are enough images to show it — otherwise
            // an image-light project would get no suggestions at all.
            if (!$hasVoice || $distinctKinds < 3) {
                continue;
            }
            if ($imageCount >= 2 && $visualTypes < 2) {
                continue;
            }
            $ideas[] = [
                'key' => $key, 'name' => $c['name'], 'rationale' => $c['rationale'],
                'preset' => $c['preset'], 'hero' => $c['hero'], 'ending' => $c['ending'],
                'pullquote' => $c['pullquote'], 'blocks' => $blocks, 'visualTypes' => $visualTypes,
                'richMoment' => $hasRich,
            ];
            if (count($ideas) >= max(3, $want)) {
                break;
            }
        }

        return $ideas;
    }

    /**
     * A single "inspiration" concept expressed as an editorial mood, mapped to a
     * real preset + layout idea (so "Museum" or "Field notebook" resolves to
     * actual blocks, never invented CSS).
     *
     * @return array<string,array{name:string,concept:string}>
     */
    public static function inspirations(): array
    {
        return [
            'magazine-feature' => ['name' => 'Magazine feature', 'concept' => 'magazine'],
            'gallery'          => ['name' => 'Museum / gallery', 'concept' => 'minimal'],
            'exhibition'       => ['name' => 'Exhibition', 'concept' => 'image-led'],
            'blueprint'        => ['name' => 'Blueprint', 'concept' => 'device-showcase'],
            'field-notebook'   => ['name' => 'Field notebook', 'concept' => 'story-driven'],
            'brochure'         => ['name' => 'Luxury brochure', 'concept' => 'editorial'],
            'scrapbook'        => ['name' => 'Scrapbook', 'concept' => 'collage'],
        ];
    }

    /**
     * @return list<string> concept keys, best fit first, still diverse
     */
    private static function rankConcepts(int $imageCount): array
    {
        if ($imageCount >= 4) {
            return ['collage', 'image-led', 'device-showcase', 'magazine', 'story-driven', 'editorial', 'minimal'];
        }
        if ($imageCount >= 2) {
            return ['editorial', 'image-led', 'story-driven', 'magazine', 'collage', 'device-showcase', 'minimal'];
        }

        return ['editorial', 'minimal', 'story-driven', 'magazine', 'image-led', 'collage', 'device-showcase'];
    }

    /**
     * Replace image-hungry blocks that can't be filled, and drop ones that
     * still can't. Keeps a concept buildable with the assets on hand.
     *
     * @param list<array{type:string,need:int}> $blocks
     * @return list<string>
     */
    private static function degrade(array $blocks, int $imageCount): array
    {
        // When an image block can't be filled and there are no images at all,
        // swap in a no-image richness block (colour / facts / brand) rather than
        // collapsing to plain text — this keeps ideas varied for image-light
        // projects, and cycles so we don't repeat the same swap.
        $noImageRichness = ['cs-colourblock', 'cs-facts', 'cs-interlude', 'cs-specimen'];
        $swap = 0;
        $out = [];
        foreach ($blocks as $b) {
            $type = $b['type'];
            $need = $b['need'];
            if ($need <= $imageCount) {
                // Enough images exist overall; keep (images may be reused across blocks).
                $out[] = $type;
                continue;
            }
            // Not enough for this block — degrade.
            if ($imageCount >= 1) {
                $fallback = 'cs-shot';
            } else {
                $fallback = $noImageRichness[$swap % count($noImageRichness)];
                $swap++;
            }
            // Avoid an immediate duplicate from degradation.
            if ($out !== [] && end($out) === $fallback) {
                $fallback = $noImageRichness[$swap % count($noImageRichness)];
                $swap++;
            }
            if ($out !== [] && end($out) === $fallback) {
                continue;
            }
            $out[] = $fallback;
        }

        // Guarantee at least one voice block.
        if (array_filter($out, [BlockTaxonomy::class, 'isVoice']) === []) {
            $out[] = 'cs-quote';
        }

        return $out;
    }

    /** Whether a preset/hero/ending is a valid whitelisted value (defensive). */
    public static function validDirection(string $preset, string $hero, string $ending): bool
    {
        return in_array($preset, ArtDirection::PRESETS, true)
            && in_array($hero, ArtDirection::HEROES, true)
            && in_array($ending, ArtDirection::ENDINGS, true);
    }
}
