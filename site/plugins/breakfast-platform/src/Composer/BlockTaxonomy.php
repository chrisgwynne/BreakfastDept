<?php

declare(strict_types=1);

namespace Breakfast\Platform\Composer;

/**
 * Shared editorial taxonomy for the Composer engine.
 *
 * Maps every case-study block type to a visual "kind" (what it does to the
 * page's rhythm) plus a set of capability flags. This is the common vocabulary
 * that the rhythm analyser, creative director, story-health scorer and layout
 * generator all reason over, so they agree on what counts as a screenshot, a
 * quiet beat, a colour moment or a crafted treatment.
 *
 * Pure, framework-free, no I/O.
 */
final class BlockTaxonomy
{
    // Visual kinds — the alphabet the rhythm/pacing analysis reads.
    public const KIND = [
        'cs-statement'   => 'statement',
        'cs-interlude'   => 'statement',
        'cs-text'        => 'text',
        'cs-numbered'    => 'text',
        'cs-quote'       => 'quote',
        'cs-facts'       => 'facts',
        'cs-shot'        => 'image',
        'cs-rotated'     => 'image',
        'cs-fullbleed'   => 'fullbleed',
        'cs-fullpage'    => 'fullbleed',
        'cs-stack'       => 'images',
        'cs-strip'       => 'images',
        'cs-devices'     => 'devices',
        'cs-annotated'   => 'annotated',
        'cs-sticky'      => 'sticky',
        'cs-composition' => 'composition',
        'cs-colourblock' => 'colour',
        'cs-palette'     => 'brand',
        'cs-specimen'    => 'brand',
        'image'          => 'image',
        'gallery'        => 'images',
        'text'           => 'text',
        'video'          => 'fullbleed',
    ];

    /** Blocks that put imagery on the page. */
    public const VISUAL = [
        'cs-shot', 'cs-rotated', 'cs-fullbleed', 'cs-fullpage', 'cs-stack', 'cs-strip',
        'cs-devices', 'cs-annotated', 'cs-sticky', 'cs-composition', 'image', 'gallery', 'video',
    ];

    /** Crafted treatments — a "beyond a plain full-width image" moment. */
    public const RICH = [
        'cs-rotated', 'cs-stack', 'cs-devices', 'cs-fullpage', 'cs-strip',
        'cs-beforeafter', 'cs-annotated', 'cs-sticky', 'cs-composition',
    ];

    /** Blocks carrying a project-specific statement, quote or result. */
    public const VOICE = ['cs-statement', 'cs-quote', 'cs-interlude', 'cs-facts'];

    /** Quiet / editorial beats — the breathing room between visual moments. */
    public const QUIET = ['cs-text', 'cs-numbered', 'cs-interlude', 'cs-statement'];

    /** Blocks that need at least one image reference to render anything. */
    public const NEEDS_IMAGE = ['cs-shot', 'cs-rotated', 'cs-fullbleed', 'cs-fullpage', 'cs-devices', 'cs-annotated', 'cs-sticky'];

    /** Blocks that need two or more images. */
    public const NEEDS_IMAGES = ['cs-stack', 'cs-strip'];

    public static function kind(string $type): string
    {
        return self::KIND[$type] ?? 'other';
    }

    public static function isVisual(string $type): bool
    {
        return in_array($type, self::VISUAL, true);
    }

    public static function isRich(string $type): bool
    {
        return in_array($type, self::RICH, true);
    }

    public static function isVoice(string $type): bool
    {
        return in_array($type, self::VOICE, true);
    }

    /**
     * How many image slots a block of this type wants (for binding real assets).
     */
    public static function imageSlots(string $type): int
    {
        return match ($type) {
            'cs-stack'   => 3,
            'cs-strip'   => 4,
            'cs-devices' => 2,
            default      => in_array($type, self::NEEDS_IMAGE, true) ? 1 : 0,
        };
    }
}
