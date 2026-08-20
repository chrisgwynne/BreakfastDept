<?php

/**
 * A small, original, self-hosted set of pixel-block motifs (sunrise, mug,
 * monitor, cursor, egg). Purely decorative — always aria-hidden — drawn on
 * an 8x8 grid with <rect> cells so they stay crisp at any size without a
 * raster image request. Usage: snippet('teletext/pixel-art', ['motif' => 'mug']).
 *
 * @var string $motif
 * @var string $colour
 */
$motif  = $motif ?? 'egg';
$colour = $colour ?? 'currentColor';

// Each motif is an 8x8 grid; only "on" cells are listed as [x,y].
$grids = [
    'sunrise' => [[0,6],[1,6],[2,6],[3,6],[4,6],[5,6],[6,6],[7,6],
                  [2,4],[3,4],[4,4],[5,4],[1,5],[2,5],[3,5],[4,5],[5,5],[6,5],
                  [3,2],[4,2],[3,0],[4,0],[0,3],[1,3],[6,3],[7,3]],
    'mug'     => [[1,1],[2,1],[3,1],[4,1],[1,2],[4,2],[1,3],[4,2],[1,4],[4,4],[1,5],[2,5],[3,5],[4,5],
                  [5,2],[6,2],[6,3],[5,4],
                  [1,0],[2,0],[3,0]],
    'monitor' => [[0,1],[1,1],[2,1],[3,1],[4,1],[5,1],[6,1],[7,1],
                  [0,2],[7,2],[0,3],[7,3],[0,4],[7,4],
                  [0,5],[1,5],[2,5],[3,5],[4,5],[5,5],[6,5],[7,5],
                  [3,6],[4,6],[2,7],[3,7],[4,7],[5,7]],
    'cursor'  => [[0,0],[0,1],[0,2],[0,3],[0,4],[0,5],[0,6],
                  [1,1],[1,2],[1,3],[1,4],[2,3],[2,4],[3,4],[2,5],[3,5],[3,6],[4,6]],
    'egg'     => [[3,0],[4,0],[2,1],[5,1],[2,2],[5,2],[1,3],[6,3],[1,4],[6,4],
                  [1,5],[6,5],[2,6],[5,6],[3,7],[4,7]],
];
$cells = $grids[$motif] ?? $grids['egg'];
?>
<svg class="tt-pixel" viewBox="0 0 8 8" width="32" height="32" aria-hidden="true" focusable="false">
  <?php foreach ($cells as [$x, $y]): ?>
    <rect x="<?= $x ?>" y="<?= $y ?>" width="1" height="1" fill="<?= esc($colour) ?>"></rect>
  <?php endforeach ?>
</svg>
