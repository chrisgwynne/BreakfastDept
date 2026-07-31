<?php

declare(strict_types=1);

namespace Breakfast\Platform\Content;

/**
 * Art-direction token resolver for case studies.
 *
 * Turns a case study's curated art-direction settings into a SAFE bundle of CSS
 * custom properties and data-attributes for the public template. Every input is
 * validated against a fixed whitelist and falls back to a safe default — the
 * editor can never inject arbitrary CSS, colours or JavaScript. Colours are
 * chosen from a curated palette (keys, not raw hex), so nothing user-entered is
 * ever interpolated into a stylesheet.
 *
 * The class is pure (no framework, no I/O) so it is fully unit-testable. The
 * public template calls {@see resolve()} with the page's field values and uses
 * {@see styleAttr()} / {@see dataAttrs()} to render the root element.
 */
final class ArtDirection
{
    /**
     * Curated colour palette. Editors pick a KEY; only these hex values can ever
     * reach the page, so no untrusted string is interpolated into CSS.
     *
     * @var array<string,string>
     */
    public const COLOURS = [
        'ink'        => '#1c293c',
        'cream'      => '#f5efe3',
        'paper'      => '#fbfbf9',
        'white'      => '#ffffff',
        'yellow'     => '#fdc800',
        'butter'     => '#fff2cc',
        'purple'     => '#432dd7',
        'plum'       => '#37204b',
        'coral'      => '#f2545b',
        'blush'      => '#f6d4da',
        'sage'       => '#7c9473',
        'forest'     => '#1f3d2b',
        'midnight'   => '#0f172a',
        'sky'        => '#2f9fd6',
        'terracotta' => '#c1584b',
        'sand'       => '#e7d8bf',
        'mint'       => '#bfe3d0',
        'charcoal'   => '#22262b',
    ];

    /** Presets seed a coherent starting look; explicit fields still win. */
    public const PRESETS = [
        'bold-editorial', 'playful-collage', 'quiet-premium', 'image-first',
        'device-showcase', 'before-after', 'typography-led', 'colour-led',
        'story-driven',
    ];

    public const HEROES = ['cinematic', 'layered-devices', 'editorial', 'collage', 'minimal', 'split'];

    public const ENDINGS = ['giant-shot', 'testimonial', 'results', 'visit', 'lessons', 'next-teaser', 'motif', 'mobile-row'];

    public const ANIMATIONS = ['quiet', 'playful-stagger', 'mask-reveal', 'parallax-lite', 'fade', 'device-assembly', 'none'];

    public const TRANSITIONS = ['hard-cut', 'slide-panel', 'angled', 'oversized-rule', 'overlap', 'whitespace'];

    public const MOTIFS = ['none', 'dots', 'grid', 'wave', 'star', 'arrow', 'underline', 'sticker'];

    public const CAPTIONS = ['quiet', 'mono-tag', 'handwritten', 'boxed'];

    public const PULLQUOTES = ['plain', 'giant', 'marks', 'panel', 'rotated', 'over-image'];

    public const DISPLAY = ['grotesk-tight', 'grotesk-wide', 'mono-caps'];

    public const BODY = ['sans', 'mono'];

    public const CORNERS = ['sharp', 'soft', 'round', 'pill'];

    public const BORDERS = ['none', 'thin', 'thick', 'heavy'];

    public const DENSITY = ['airy', 'balanced', 'tight'];

    public const IMAGE_SCALE = ['contained', 'bleed', 'oversized'];

    public const ROTATION = ['none', 'subtle', 'playful', 'wild'];

    /** Scalar CSS values for the non-colour tokens. */
    private const CORNER_PX   = ['sharp' => '0px', 'soft' => '10px', 'round' => '18px', 'pill' => '28px'];
    private const BORDER_PX   = ['none' => '0px', 'thin' => '2px', 'thick' => '3px', 'heavy' => '5px'];
    private const DENSITY_MUL = ['airy' => '1.32', 'balanced' => '1', 'tight' => '0.74'];
    private const IMG_MUL     = ['contained' => '1', 'bleed' => '1', 'oversized' => '1.14'];
    private const ROTATION_DEG = ['none' => '0deg', 'subtle' => '3deg', 'playful' => '6deg', 'wild' => '8deg'];
    private const DISPLAY_TRACK = ['grotesk-tight' => '-0.04em', 'grotesk-wide' => '0.005em', 'mono-caps' => '0.02em'];

    /**
     * Baseline values. A preset overrides these; an explicit non-empty field
     * overrides the preset. Everything here is already a valid whitelist key.
     *
     * @var array<string,string>
     */
    private const DEFAULTS = [
        // The out-of-the-box look is deliberately bold, not timid: a strong
        // editorial hero, oversized imagery, a giant pull quote and confident
        // rhythm. A quieter project opts into 'quiet-premium'.
        'preset' => 'bold-editorial',
        'accent' => 'yellow', 'secondary' => 'purple', 'bg' => 'cream', 'text' => 'ink',
        'display' => 'grotesk-wide', 'body' => 'sans',
        'corner' => 'soft', 'border' => 'thin', 'density' => 'airy',
        'image_scale' => 'oversized', 'rotation' => 'subtle',
        'caption' => 'mono-tag', 'pullquote' => 'giant',
        'animation' => 'mask-reveal', 'transition' => 'oversized-rule', 'motif' => 'underline',
        'hero' => 'editorial', 'ending' => 'visit',
    ];

    /**
     * Per-preset seed overrides (only the fields a preset opinionates).
     *
     * @var array<string,array<string,string>>
     */
    private const PRESET_SEED = [
        'bold-editorial' => [
            'hero' => 'editorial', 'display' => 'grotesk-wide', 'density' => 'airy',
            'pullquote' => 'giant', 'transition' => 'oversized-rule', 'animation' => 'mask-reveal',
            'accent' => 'yellow', 'secondary' => 'purple', 'text' => 'ink', 'bg' => 'cream',
            'motif' => 'underline', 'image_scale' => 'oversized', 'rotation' => 'subtle',
        ],
        'playful-collage' => [
            'hero' => 'collage', 'display' => 'grotesk-wide', 'rotation' => 'playful',
            'pullquote' => 'rotated', 'transition' => 'overlap', 'animation' => 'playful-stagger',
            'accent' => 'coral', 'secondary' => 'purple', 'motif' => 'sticker', 'corner' => 'round',
            'image_scale' => 'oversized', 'bg' => 'butter', 'density' => 'balanced',
        ],
        'quiet-premium' => [
            'hero' => 'minimal', 'display' => 'grotesk-tight', 'density' => 'airy',
            'pullquote' => 'marks', 'transition' => 'whitespace', 'animation' => 'fade',
            'accent' => 'ink', 'bg' => 'paper', 'border' => 'none', 'rotation' => 'none', 'corner' => 'sharp',
        ],
        'image-first' => [
            'hero' => 'cinematic', 'image_scale' => 'oversized', 'density' => 'tight',
            'pullquote' => 'over-image', 'transition' => 'overlap', 'animation' => 'parallax-lite',
            'accent' => 'yellow', 'text' => 'ink', 'rotation' => 'subtle',
        ],
        'device-showcase' => [
            'hero' => 'layered-devices', 'transition' => 'slide-panel', 'animation' => 'device-assembly',
            'accent' => 'sky', 'secondary' => 'ink', 'corner' => 'round', 'image_scale' => 'oversized',
            'pullquote' => 'panel',
        ],
        'before-after' => [
            'hero' => 'split', 'transition' => 'angled', 'animation' => 'fade',
            'accent' => 'terracotta', 'secondary' => 'ink', 'pullquote' => 'panel', 'rotation' => 'subtle',
        ],
        'typography-led' => [
            'hero' => 'editorial', 'display' => 'grotesk-wide', 'pullquote' => 'marks',
            'motif' => 'underline', 'animation' => 'mask-reveal', 'density' => 'airy', 'border' => 'none',
            'image_scale' => 'oversized',
        ],
        'colour-led' => [
            'hero' => 'editorial', 'accent' => 'purple', 'secondary' => 'coral', 'bg' => 'butter',
            'transition' => 'slide-panel', 'animation' => 'playful-stagger', 'pullquote' => 'panel',
            'image_scale' => 'oversized', 'motif' => 'wave',
        ],
        'story-driven' => [
            'hero' => 'minimal', 'pullquote' => 'giant', 'transition' => 'oversized-rule',
            'animation' => 'mask-reveal', 'density' => 'balanced', 'accent' => 'yellow',
            'motif' => 'underline', 'rotation' => 'subtle',
        ],
    ];

    /**
     * Resolve raw field values into a validated bundle.
     *
     * @param array<string,string|null> $fields keyed by the field names above (e.g. 'accent', 'hero')
     * @return array{preset:string,vars:array<string,string>,data:array<string,string>}
     */
    public static function resolve(array $fields): array
    {
        $preset = self::pick($fields['preset'] ?? '', self::PRESETS, self::DEFAULTS['preset']);
        $seed   = self::PRESET_SEED[$preset] ?? [];

        // Merge order: defaults ← preset seed ← explicit non-empty field values.
        $merged = self::DEFAULTS;
        foreach ($seed as $k => $v) {
            $merged[$k] = $v;
        }
        foreach (array_keys(self::DEFAULTS) as $k) {
            if ($k === 'preset') {
                continue;
            }
            $val = trim((string) ($fields[$k] ?? ''));
            if ($val !== '') {
                $merged[$k] = $val;
            }
        }

        // Validate each merged value against its whitelist (defensive: a bad
        // stored value falls back to the safe default).
        $accent    = self::colour($merged['accent'], 'yellow');
        $secondary = self::colour($merged['secondary'], 'purple');
        $bg        = self::colour($merged['bg'], 'cream');
        $text      = self::colour($merged['text'], 'ink');

        $display     = self::pick($merged['display'], self::DISPLAY, 'grotesk-tight');
        $body        = self::pick($merged['body'], self::BODY, 'sans');
        $corner      = self::pick($merged['corner'], self::CORNERS, 'soft');
        $border      = self::pick($merged['border'], self::BORDERS, 'thin');
        $density     = self::pick($merged['density'], self::DENSITY, 'balanced');
        $imageScale  = self::pick($merged['image_scale'], self::IMAGE_SCALE, 'contained');
        $rotation    = self::pick($merged['rotation'], self::ROTATION, 'subtle');
        $caption     = self::pick($merged['caption'], self::CAPTIONS, 'quiet');
        $pullquote   = self::pick($merged['pullquote'], self::PULLQUOTES, 'plain');
        $animation   = self::pick($merged['animation'], self::ANIMATIONS, 'quiet');
        $transition  = self::pick($merged['transition'], self::TRANSITIONS, 'whitespace');
        $motif       = self::pick($merged['motif'], self::MOTIFS, 'none');
        $hero        = self::pick($merged['hero'], self::HEROES, 'editorial');
        $ending      = self::pick($merged['ending'], self::ENDINGS, 'visit');

        $vars = [
            '--ad-accent'      => self::COLOURS[$accent],
            '--ad-secondary'   => self::COLOURS[$secondary],
            '--ad-bg'          => self::COLOURS[$bg],
            '--ad-text'        => self::COLOURS[$text],
            '--ad-on-accent'   => self::onColour($accent),
            '--ad-on-secondary' => self::onColour($secondary),
            '--ad-corner'      => self::CORNER_PX[$corner],
            '--ad-border-w'    => self::BORDER_PX[$border],
            '--ad-density'     => self::DENSITY_MUL[$density],
            '--ad-img-scale'   => self::IMG_MUL[$imageScale],
            '--ad-rot'         => self::ROTATION_DEG[$rotation],
            '--ad-display-font' => $display === 'mono-caps' ? 'var(--mono)' : 'var(--font)',
            '--ad-display-track' => self::DISPLAY_TRACK[$display],
            '--ad-body-font'   => $body === 'mono' ? 'var(--mono)' : 'var(--font)',
        ];

        $data = [
            'preset'     => $preset,
            'hero'       => $hero,
            'ending'     => $ending,
            'anim'       => $animation,
            'transition' => $transition,
            'motif'      => $motif,
            'caption'    => $caption,
            'pullquote'  => $pullquote,
            'border'     => $border,
            'density'    => $density,
            'display'    => $display,
            'imagescale' => $imageScale,
            'rotation'   => $rotation,
        ];

        return ['preset' => $preset, 'vars' => $vars, 'data' => $data];
    }

    /**
     * Build a safe inline `style` value ("--ad-x:val;…") from resolved vars.
     * Values come only from our constants, so no escaping-sensitive input flows
     * in; we still whitelist the characters as belt-and-braces.
     *
     * @param array<string,string> $vars
     */
    public static function styleAttr(array $vars): string
    {
        $out = [];
        foreach ($vars as $prop => $value) {
            if (preg_match('/^--[a-z0-9-]+$/', $prop) !== 1) {
                continue;
            }
            if (preg_match('/^[a-z0-9#().,%\/ _-]+$/i', $value) !== 1) {
                continue;
            }
            $out[] = $prop . ':' . $value;
        }

        return implode(';', $out);
    }

    /**
     * Build the `data-ad-*` attribute string from resolved data.
     *
     * @param array<string,string> $data
     */
    public static function dataAttrs(array $data): string
    {
        $out = [];
        foreach ($data as $key => $value) {
            if (preg_match('/^[a-z]+$/', $key) !== 1 || preg_match('/^[a-z-]+$/', $value) !== 1) {
                continue;
            }
            $out[] = 'data-ad-' . $key . '="' . $value . '"';
        }

        return implode(' ', $out);
    }

    /**
     * Pick a value if it is in the whitelist, else the default.
     *
     * @param list<string> $allowed
     */
    private static function pick(string $value, array $allowed, string $default): string
    {
        $value = trim($value);

        return in_array($value, $allowed, true) ? $value : $default;
    }

    private static function colour(string $key, string $default): string
    {
        $key = trim($key);

        return array_key_exists($key, self::COLOURS) ? $key : $default;
    }

    /**
     * Choose a readable foreground (#1c293c or #ffffff) for text placed on a
     * palette colour, using WCAG relative luminance — keeps quote-over-colour and
     * button-on-accent legible regardless of the chosen palette entry.
     */
    public static function onColour(string $key): string
    {
        $hex = self::COLOURS[$key] ?? '#1c293c';
        $r = hexdec(substr($hex, 1, 2)) / 255;
        $g = hexdec(substr($hex, 3, 2)) / 255;
        $b = hexdec(substr($hex, 5, 2)) / 255;
        $lin = static fn (float $c): float => $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        $l = 0.2126 * $lin($r) + 0.7152 * $lin($g) + 0.0722 * $lin($b);

        return $l > 0.4 ? '#1c293c' : '#ffffff';
    }
}
