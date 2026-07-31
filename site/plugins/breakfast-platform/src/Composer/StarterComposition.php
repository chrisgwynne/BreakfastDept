<?php

declare(strict_types=1);

namespace Breakfast\Platform\Composer;

/**
 * A rich editorial starter for a NEW case study.
 *
 * A new project should never begin as hero → paragraph → image → paragraph.
 * This produces a premium composition already in place — a bold opener, a
 * screenshot moment, a numbered beat, a crafted (rotated) shot, a giant pull
 * quote, a project-facts band and a colour close — with guiding editorial
 * prompts the owner replaces. The default already feels art-directed; the owner
 * refines rather than assembles from nothing.
 *
 * Pure and framework-free; returns art-direction seed values and a ready-to-save
 * block array. A Kirby adapter writes it into the draft.
 */
final class StarterComposition
{
    /**
     * @return array{ad:array<string,string>,blocks:list<array{id:string,type:string,content:array<string,mixed>,isHidden:bool}>}
     */
    public static function build(): array
    {
        $ad = [
            'ad_preset'     => 'bold-editorial',
            'ad_hero'       => 'editorial',
            'ad_ending'     => 'motif',
            'ad_pullquote'  => 'giant',
            'ad_motif'      => 'underline',
            'ad_image_scale' => 'oversized',
            'ad_rotation'   => 'subtle',
            'ad_animation'  => 'mask-reveal',
            'ad_transition' => 'oversized-rule',
        ];

        $blocks = [
            self::block('s1', 'cs-statement', [
                'text' => 'Open with the core tension in one bold line — the problem this project set out to solve.',
                'align' => 'left', 'kind' => 'challenge',
            ]),
            self::block('s2', 'cs-shot', [
                'image' => [], 'scale' => 'oversized',
                'caption' => 'Drop in the hero screenshot — the one image that sells the work.',
            ]),
            self::block('s3', 'cs-numbered', [
                'number' => '1', 'heading' => 'The approach',
                'text' => 'One paragraph on how you tackled it. Keep it specific to this client.',
            ]),
            self::block('s4', 'cs-rotated', [
                'image' => [], 'direction' => 'right',
                'caption' => 'A second screenshot, tilted — a crafted moment, not a plain picture.',
            ]),
            self::block('s5', 'cs-quote', [
                'text' => 'A single line that lands — a genuine result or the sharpest thing about the project.',
                'attribution' => '', 'role' => '', 'kind' => 'statement', 'style' => 'giant',
            ]),
            self::block('s6', 'cs-facts', [
                'items' => [
                    ['value' => 'Add', 'label' => 'a real outcome'],
                    ['value' => 'Add', 'label' => 'a second fact'],
                    ['value' => 'Add', 'label' => 'a third fact'],
                ],
            ]),
            self::block('s7', 'cs-colourblock', [
                'colour' => 'accent',
                'heading' => 'Close with a confident summary line in the project’s own voice.',
                'text' => '',
            ]),
        ];

        return ['ad' => $ad, 'blocks' => $blocks];
    }

    /**
     * @param array<string,mixed> $content
     * @return array{id:string,type:string,content:array<string,mixed>,isHidden:bool}
     */
    private static function block(string $id, string $type, array $content): array
    {
        return ['id' => $id, 'type' => $type, 'content' => $content, 'isHidden' => false];
    }
}
