<?php
/** @var \Kirby\Cms\Block $block */
$img = $block->image()->toFile();
if (!$img) { return; }
$scale = $block->scale()->value();
$wrap  = $scale === 'fullbleed' ? 'cs-fullbleed' : ($scale === 'oversized' ? 'cs-oversized' : 'cs__inner');
?>
<section class="cs-shot <?= $wrap ?> reveal">
  <figure class="cs-surface"><?= $img->crop(2200, 1300)->html(['alt' => esc($img->alt()->or('Screenshot')), 'loading' => 'lazy']) ?></figure>
  <?php if ($block->caption()->isNotEmpty()): ?><figcaption class="cs-cap<?= $wrap === 'cs__inner' ? '' : ' cs__inner' ?>"><?= esc($block->caption()) ?></figcaption><?php endif ?>
</section>
