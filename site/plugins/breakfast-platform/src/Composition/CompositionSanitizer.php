<?php

declare(strict_types=1);

namespace Breakfast\Platform\Composition;

/**
 * Sanitises a layered-composition definition to SAFE, bounded values.
 *
 * The layered-composition editor lets an owner arrange screenshots, crops,
 * frames, annotations, shapes and captions on a bounded canvas. This class is
 * the security boundary: it accepts the editor's JSON and returns a composition
 * whose every field is a whitelisted enum or a clamped number. No raw CSS,
 * transforms, arbitrary coordinates, viewport units, z-index strings or
 * JavaScript can ever survive. Applied on save AND on render, so a hand-edited
 * content file can't smuggle anything past it either.
 *
 * Positioning is expressed on a 12-column × 8-row grid (safe integer tracks);
 * scale/rotation/opacity are clamped numbers. Nothing can escape the canvas
 * (the render wrapper is overflow-contained), so a composition never causes
 * horizontal overflow.
 */
final class CompositionSanitizer
{
    public const LAYER_TYPES = ['desktop-shot', 'mobile-shot', 'browser-frame', 'crop', 'annotation', 'shape', 'caption'];
    public const ANCHORS = ['top-left', 'top', 'top-right', 'left', 'center', 'right', 'bottom-left', 'bottom', 'bottom-right'];
    public const SHADOWS = ['none', 'sm', 'md', 'lg'];
    public const BORDERS = ['none', 'thin', 'thick'];
    public const FRAMES = ['none', 'browser', 'phone'];
    public const SHAPES = ['line', 'circle', 'dot', 'arrow', 'underline', 'star'];
    public const ASPECTS = ['16:9', '4:3', '1:1', '3:4', '9:16'];
    public const ROTATIONS = [-8, -5, -3, 0, 3, 5, 8];
    public const BREAKPOINTS = ['tablet', 'mobile'];

    public const COLS = 12;
    public const ROWS = 8;
    public const MAX_LAYERS = 12;

    /**
     * @param array<string,mixed> $comp
     * @return array{aspect:string,layers:list<array<string,mixed>>,responsive:array<string,array<string,array<string,mixed>>>,mobileOrder:list<string>}
     */
    public static function sanitize(array $comp): array
    {
        $aspect = self::pick((string) ($comp['aspect'] ?? '16:9'), self::ASPECTS, '16:9');

        $layers = [];
        $ids = [];
        foreach (self::asList($comp['layers'] ?? []) as $raw) {
            if (!is_array($raw) || count($layers) >= self::MAX_LAYERS) {
                continue;
            }
            $layer = self::layer($raw);
            if ($layer === null) {
                continue;
            }
            // De-duplicate ids so overrides map unambiguously.
            if (in_array($layer['id'], $ids, true)) {
                continue;
            }
            $ids[] = $layer['id'];
            $layers[] = $layer;
        }

        $responsive = [];
        $rawResp = is_array($comp['responsive'] ?? null) ? $comp['responsive'] : [];
        foreach (self::BREAKPOINTS as $bp) {
            $responsive[$bp] = [];
            $bpMap = is_array($rawResp[$bp] ?? null) ? $rawResp[$bp] : [];
            foreach ($bpMap as $lid => $override) {
                $lid = self::safeId((string) $lid);
                if ($lid === '' || !in_array($lid, $ids, true) || !is_array($override)) {
                    continue;
                }
                $responsive[$bp][$lid] = self::override($override);
            }
        }

        $mobileOrder = [];
        foreach (self::asList($comp['mobileOrder'] ?? []) as $lid) {
            $lid = self::safeId((string) $lid);
            if ($lid !== '' && in_array($lid, $ids, true) && !in_array($lid, $mobileOrder, true)) {
                $mobileOrder[] = $lid;
            }
        }

        return ['aspect' => $aspect, 'layers' => $layers, 'responsive' => $responsive, 'mobileOrder' => $mobileOrder];
    }

    /**
     * @param array<string,mixed> $raw
     * @return array<string,mixed>|null
     */
    private static function layer(array $raw): ?array
    {
        $type = self::pick((string) ($raw['type'] ?? ''), self::LAYER_TYPES, '');
        if ($type === '') {
            return null;
        }
        $id = self::safeId((string) ($raw['id'] ?? ''));
        if ($id === '') {
            return null;
        }

        $col = self::clampInt($raw['col'] ?? 1, 1, self::COLS);
        $colSpan = self::clampInt($raw['colSpan'] ?? 6, 1, self::COLS - $col + 1);
        $row = self::clampInt($raw['row'] ?? 1, 1, self::ROWS);
        $rowSpan = self::clampInt($raw['rowSpan'] ?? 4, 1, self::ROWS - $row + 1);

        return [
            'id'       => $id,
            'type'     => $type,
            'image'    => self::safeAsset((string) ($raw['image'] ?? '')),
            'text'     => self::safeText((string) ($raw['text'] ?? '')),
            'shape'    => self::pick((string) ($raw['shape'] ?? 'line'), self::SHAPES, 'line'),
            'anchor'   => self::pick((string) ($raw['anchor'] ?? 'center'), self::ANCHORS, 'center'),
            'col'      => $col,
            'colSpan'  => $colSpan,
            'row'      => $row,
            'rowSpan'  => $rowSpan,
            'scale'    => self::clampFloat($raw['scale'] ?? 1.0, 0.5, 1.4),
            'rotation' => self::snapRotation($raw['rotation'] ?? 0),
            'opacity'  => self::clampFloat($raw['opacity'] ?? 1.0, 0.2, 1.0),
            'shadow'   => self::pick((string) ($raw['shadow'] ?? 'md'), self::SHADOWS, 'md'),
            'border'   => self::pick((string) ($raw['border'] ?? 'thin'), self::BORDERS, 'thin'),
            'frame'    => self::pick((string) ($raw['frame'] ?? 'none'), self::FRAMES, 'none'),
            'visible'  => self::visible($raw['visible'] ?? null),
            'reveal'   => self::clampInt($raw['reveal'] ?? 0, 0, self::MAX_LAYERS),
        ];
    }

    /**
     * A per-breakpoint override carries only the fields a breakpoint may change.
     *
     * @param array<string,mixed> $raw
     * @return array<string,mixed>
     */
    private static function override(array $raw): array
    {
        $out = [];
        if (isset($raw['anchor'])) {
            $out['anchor'] = self::pick((string) $raw['anchor'], self::ANCHORS, 'center');
        }
        if (isset($raw['col'])) {
            $out['col'] = self::clampInt($raw['col'], 1, self::COLS);
        }
        if (isset($raw['colSpan'])) {
            $out['colSpan'] = self::clampInt($raw['colSpan'], 1, self::COLS);
        }
        if (isset($raw['row'])) {
            $out['row'] = self::clampInt($raw['row'], 1, self::ROWS);
        }
        if (isset($raw['rowSpan'])) {
            $out['rowSpan'] = self::clampInt($raw['rowSpan'], 1, self::ROWS);
        }
        if (isset($raw['scale'])) {
            $out['scale'] = self::clampFloat($raw['scale'], 0.5, 1.4);
        }
        if (isset($raw['rotation'])) {
            $out['rotation'] = self::snapRotation($raw['rotation']);
        }
        if (isset($raw['hidden'])) {
            $out['hidden'] = (bool) $raw['hidden'];
        }

        return $out;
    }

    /** @return array{desktop:bool,tablet:bool,mobile:bool} */
    private static function visible(mixed $raw): array
    {
        $v = is_array($raw) ? $raw : [];

        return [
            'desktop' => (bool) ($v['desktop'] ?? true),
            'tablet'  => (bool) ($v['tablet'] ?? true),
            'mobile'  => (bool) ($v['mobile'] ?? true),
        ];
    }

    /**
     * Build a SAFE inline style for a layer: only clamped numeric custom
     * properties and integer grid tracks. Same whitelist discipline as
     * ArtDirection::styleAttr — nothing user-authored is interpolated raw.
     *
     * @param array<string,mixed> $layer
     */
    public static function layerStyle(array $layer): string
    {
        $vars = [
            'grid-column' => (int) $layer['col'] . ' / span ' . (int) $layer['colSpan'],
            'grid-row'    => (int) $layer['row'] . ' / span ' . (int) $layer['rowSpan'],
            '--l-scale'   => number_format((float) $layer['scale'], 2, '.', ''),
            '--l-rot'     => (int) $layer['rotation'] . 'deg',
            '--l-op'      => number_format((float) $layer['opacity'], 2, '.', ''),
        ];
        $out = [];
        foreach ($vars as $prop => $value) {
            if (preg_match('/^[a-z-]+$/', $prop) === 1 && preg_match('/^[-0-9a-z. \/]+$/', $value) === 1) {
                $out[] = $prop . ':' . $value;
            }
        }

        return implode(';', $out);
    }

    /**
     * Build the full set of safe CSS custom properties for a layer, including
     * per-breakpoint overrides (tablet/mobile). case-study.css consumes these
     * with media queries; only clamped numbers + integer tracks are emitted.
     *
     * @param array<string,mixed> $layer
     * @param array<string,mixed> $tablet per-breakpoint override (already sanitised)
     * @param array<string,mixed> $mobile per-breakpoint override (already sanitised)
     */
    public static function layerVars(array $layer, array $tablet = [], array $mobile = []): string
    {
        $vars = [
            '--l-col'   => (string) (int) $layer['col'],
            '--l-cs'    => (string) (int) $layer['colSpan'],
            '--l-row'   => (string) (int) $layer['row'],
            '--l-rs'    => (string) (int) $layer['rowSpan'],
            '--l-scale' => number_format((float) $layer['scale'], 2, '.', ''),
            '--l-rot'   => (int) $layer['rotation'] . 'deg',
            '--l-op'    => number_format((float) $layer['opacity'], 2, '.', ''),
        ];
        foreach (['t' => $tablet, 'm' => $mobile] as $sfx => $ov) {
            if (isset($ov['col'])) {
                $vars['--l-col-' . $sfx] = (string) (int) $ov['col'];
            }
            if (isset($ov['colSpan'])) {
                $vars['--l-cs-' . $sfx] = (string) (int) $ov['colSpan'];
            }
            if (isset($ov['row'])) {
                $vars['--l-row-' . $sfx] = (string) (int) $ov['row'];
            }
            if (isset($ov['rowSpan'])) {
                $vars['--l-rs-' . $sfx] = (string) (int) $ov['rowSpan'];
            }
            if (isset($ov['scale'])) {
                $vars['--l-scale-' . $sfx] = number_format((float) $ov['scale'], 2, '.', '');
            }
            if (isset($ov['rotation'])) {
                $vars['--l-rot-' . $sfx] = (int) $ov['rotation'] . 'deg';
            }
        }
        $out = [];
        foreach ($vars as $prop => $value) {
            if (preg_match('/^--l-[a-z-]+$/', $prop) === 1 && preg_match('/^[-0-9a-z.]+$/', $value) === 1) {
                $out[] = $prop . ':' . $value;
            }
        }

        return implode(';', $out);
    }

    // ---- primitives -------------------------------------------------------

    /** @param list<string> $allowed */
    private static function pick(string $value, array $allowed, string $default): string
    {
        $value = trim($value);

        return in_array($value, $allowed, true) ? $value : $default;
    }

    private static function clampInt(mixed $v, int $min, int $max): int
    {
        $n = (int) $v;
        if ($max < $min) {
            $max = $min;
        }

        return max($min, min($max, $n));
    }

    private static function clampFloat(mixed $v, float $min, float $max): float
    {
        $n = is_numeric($v) ? (float) $v : $min;

        return round(max($min, min($max, $n)), 2);
    }

    private static function snapRotation(mixed $v): int
    {
        $n = (int) round(is_numeric($v) ? (float) $v : 0.0);
        $best = 0;
        $bestD = PHP_INT_MAX;
        foreach (self::ROTATIONS as $r) {
            $d = abs($r - $n);
            if ($d < $bestD) {
                $bestD = $d;
                $best = $r;
            }
        }

        return $best;
    }

    private static function safeId(string $id): string
    {
        $id = preg_replace('/[^A-Za-z0-9_-]/', '', $id) ?? '';

        return substr($id, 0, 40);
    }

    /** Asset reference: a filename or derivative id — safe characters only. */
    private static function safeAsset(string $s): string
    {
        $s = preg_replace('/[^A-Za-z0-9._-]/', '', $s) ?? '';

        return substr($s, 0, 120);
    }

    private static function safeText(string $s): string
    {
        $s = strip_tags($s);
        $s = preg_replace('/\s+/', ' ', $s) ?? '';

        return trim(mb_substr($s, 0, 240));
    }

    /**
     * @param mixed $v
     * @return list<mixed>
     */
    private static function asList(mixed $v): array
    {
        return is_array($v) ? array_values($v) : [];
    }
}
