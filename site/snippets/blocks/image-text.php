<?php /** @var \Kirby\Cms\Block $block */
$image = $block->image()->toFile() ?? $block->images()->toFiles()->first();
$flip = $block->flip()->toBool(false);
?>
<div class="image-text <?= $flip ? 'image-text--flip' : '' ?>">
  <?php if ($image): ?>
    <div class="image-text__media"><?= $image->crop(700, 520)->html(['alt' => esc($block->alt()->or($image->alt())), 'loading' => 'lazy']) ?></div>
  <?php endif ?>
  <div class="image-text__body prose">
    <?php if ($block->heading()->isNotEmpty()): ?><h2><?= esc($block->heading()) ?></h2><?php endif ?>
    <?= $block->text()->kt() ?>
  </div>
</div>
