<?php
/** @var \Kirby\Cms\Block $block */
$img = $block->image()->toFile();
if (!$img) { return; }
$dir = $block->direction()->value() === 'left' ? ' cs-rotate--neg' : '';
?>
<section class="cs-rotate<?= $dir ?> cs__inner reveal">
  <figure class="cs-surface"><?= $img->crop(1600, 1000)->html(['alt' => esc($img->alt()->or('Screenshot')), 'loading' => 'lazy']) ?></figure>
  <?php if ($block->caption()->isNotEmpty()): ?><figcaption class="cs-cap"><?= esc($block->caption()) ?></figcaption><?php endif ?>
</section>
