<?php

/**
 * Original, self-hosted pixel-block motifs, drawn as inline SVG <rect>
 * cells on a grid — no raster image request. Purely decorative — always
 * aria-hidden.
 *
 * Usage: snippet('teletext/pixel-art', ['motif' => 'sunrise', 'size' => 'lg']).
 *
 * @var string $motif  sunrise | mug | monitor | cursor | egg | mountains
 * @var string $size    sm (8x8, hand-authored) | lg (larger, procedural)
 * @var string $colour
 */
$motif  = $motif ?? 'egg';
$size   = $size ?? 'sm';
$colour = $colour ?? 'currentColor';

// ---------- Small (8x8) hand-authored motifs ----------
$smallGrids = [
    'mug'     => [[1,1],[2,1],[3,1],[4,1],[1,2],[4,2],[1,3],[4,2],[1,4],[4,4],[1,5],[2,5],[3,5],[4,5],
                  [5,2],[6,2],[6,3],[5,4],
                  [1,0],[2,0],[3,0]],
    'cursor'  => [[0,0],[0,1],[0,2],[0,3],[0,4],[0,5],[0,6],
                  [1,1],[1,2],[1,3],[1,4],[2,3],[2,4],[3,4],[2,5],[3,5],[3,6],[4,6]],
    'egg'     => [[3,0],[4,0],[2,1],[5,1],[2,2],[5,2],[1,3],[6,3],[1,4],[6,4],
                  [1,5],[6,5],[2,6],[5,6],[3,7],[4,7]],
];

// ---------- Large procedural motifs (generated, not hand-listed) ----------
// Guarded: this snippet can render more than once per request (e.g. a
// sunrise on P100 and a monitor on P300 in the same nav pass), and a plain
// top-level `function` declaration would fatal on the second include.
if (!function_exists('tt_pixel_large_cells')) {
function tt_pixel_large_cells(string $motif): array
{
    $cells = [];
    $w = 32;
    $h = 18;

    if ($motif === 'sunrise') {
        $cx = 16;
        $cy = 12;
        $r  = 8;
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $d = ($x - $cx) ** 2 + ($y - $cy) ** 2;
                if ($d <= $r * $r && $y <= $cy) {
                    $cells[] = [$x, $y, 'sun'];
                }
            }
        }
        // Rays above the sun.
        foreach ([3, 8, 13, 19, 24, 29] as $rx) {
            $top = $rx % 2 === 0 ? 0 : 2;
            for ($y = $top; $y < 3; $y++) {
                $cells[] = [$rx, $y, 'sun'];
            }
        }
        // Horizon (dashed).
        for ($x = 0; $x < $w; $x += 2) {
            $cells[] = [$x, $cy + 1, 'line'];
        }
        return $cells;
    }

    if ($motif === 'mountains') {
        // Two overlapping triangular peaks + a flat sea-level base.
        $peaks = [['cx' => 9, 'base' => 16, 'h' => 11], ['cx' => 22, 'base' => 16, 'h' => 9]];
        foreach ($peaks as $peak) {
            for ($y = $peak['base'] - $peak['h']; $y <= $peak['base']; $y++) {
                $rowFromTop = $y - ($peak['base'] - $peak['h']);
                $half = (int) round($rowFromTop * ($peak['h'] > 0 ? 0.9 : 0));
                for ($x = $peak['cx'] - $half; $x <= $peak['cx'] + $half; $x++) {
                    if ($x >= 0 && $x < $w) {
                        $cells[] = [$x, $y, $rowFromTop < 3 ? 'snow' : 'rock'];
                    }
                }
            }
        }
        for ($x = 0; $x < $w; $x++) {
            $cells[] = [$x, 17, 'line'];
        }
        return $cells;
    }

    if ($motif === 'monitor') {
        // Simple bezel + screen + stand, drawn as a rectangle outline.
        for ($x = 2; $x < 30; $x++) {
            $cells[] = [$x, 2, 'frame'];
            $cells[] = [$x, 13, 'frame'];
        }
        for ($y = 2; $y < 14; $y++) {
            $cells[] = [2, $y, 'frame'];
            $cells[] = [29, $y, 'frame'];
        }
        // A couple of "content" bars inside the screen.
        for ($x = 6; $x < 20; $x++) {
            $cells[] = [$x, 5, 'sun'];
        }
        for ($x = 6; $x < 26; $x++) {
            $cells[] = [$x, 8, 'rock'];
            $cells[] = [$x, 10, 'rock'];
        }
        // Stand.
        for ($x = 14; $x < 18; $x++) {
            $cells[] = [$x, 14, 'frame'];
            $cells[] = [$x, 15, 'frame'];
        }
        for ($x = 10; $x < 22; $x++) {
            $cells[] = [$x, 16, 'frame'];
        }
        return $cells;
    }

    return $cells;
}
}

$colours = [
    'sun'   => 'var(--tt-yellow)',
    'line'  => 'var(--tt-grey)',
    'snow'  => 'var(--tt-white)',
    'rock'  => 'var(--tt-green)',
    'frame' => 'var(--tt-cyan)',
];

if ($size === 'lg') {
    $viewW = 32;
    $viewH = 18;
    $cells = tt_pixel_large_cells($motif);
} else {
    $viewW = 8;
    $viewH = 8;
    $cells = array_map(static fn ($c) => [$c[0], $c[1], null], $smallGrids[$motif] ?? $smallGrids['egg']);
}
?>
<svg class="tt-pixel tt-pixel--<?= esc($size) ?>" viewBox="0 0 <?= $viewW ?> <?= $viewH ?>" preserveAspectRatio="xMidYMid meet" aria-hidden="true" focusable="false">
  <?php foreach ($cells as [$x, $y, $kind]): ?>
    <rect x="<?= $x ?>" y="<?= $y ?>" width="1" height="1" fill="<?= $kind !== null ? esc($colours[$kind] ?? $colour) : esc($colour) ?>"></rect>
  <?php endforeach ?>
</svg>
