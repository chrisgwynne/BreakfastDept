<?php /** @var \Kirby\Cms\Block $block */
$images = $block->images()->toFiles();
if ($images->isEmpty()) return;
?>
<div class="gallery">
  <?php foreach ($images as $img): ?>
    <?= $img->crop(600, 450)->html(['alt' => esc($img->alt()), 'loading' => 'lazy']) ?>
  <?php endforeach ?>
</div>
