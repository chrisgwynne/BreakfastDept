<?php
/** @var \Kirby\Cms\Block $block */
$img = $block->image()->toFile();
if (!$img) { return; }
?>
<section class="cs-fullbleed reveal">
  <figure><?= $img->crop(2400, 1200)->html(['alt' => esc($img->alt()->or('Image')), 'loading' => 'lazy']) ?><?php if ($block->caption()->isNotEmpty()): ?><figcaption class="cs-cap cs__inner"><?= esc($block->caption()) ?></figcaption><?php endif ?></figure>
</section>
