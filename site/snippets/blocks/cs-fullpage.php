<?php
/** @var \Kirby\Cms\Block $block */
$img = $block->image()->toFile();
if (!$img) { return; }
$t = $block->treatment()->value();
?>
<section class="cs__inner reveal">
  <?php if ($t === 'frame'): ?>
    <div class="cs-fullpage cs-fullpage--frame cs-surface"><?= $img->crop(1200, 3000)->html(['alt' => esc($img->alt()->or('Full-page capture')), 'loading' => 'lazy']) ?></div>
  <?php elseif ($t === 'pan'): ?>
    <div class="cs-fullpage cs-fullpage--pan cs-surface" data-cs-pan><?= $img->crop(1200, 3000)->html(['alt' => esc($img->alt()->or('Full-page capture')), 'loading' => 'lazy']) ?></div>
  <?php else: ?>
    <div class="cs-fullpage cs-fullpage--strip cs-surface"><?= $img->crop(1400, 640)->html(['alt' => esc($img->alt()->or('Full-page capture')), 'loading' => 'lazy']) ?></div>
  <?php endif ?>
  <?php if ($block->caption()->isNotEmpty()): ?><p class="cs-cap"><?= esc($block->caption()) ?></p><?php endif ?>
</section>
