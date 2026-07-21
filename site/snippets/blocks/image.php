<?php /** @var \Kirby\Cms\Block $block */
$image = $block->image()->toFile() ?? $block->images()->toFiles()->first();
$decorative = $block->decorative()->toBool(false);
$alt = $decorative ? '' : $block->alt()->or($image?->alt());
if (!$image) return;
?>
<figure class="block-image">
  <?= $image->crop(1200)->html(['alt' => esc($alt), 'loading' => 'lazy', 'decoding' => 'async']) ?>
  <?php if ($block->caption()->isNotEmpty()): ?>
    <figcaption><?= esc($block->caption()) ?></figcaption>
  <?php endif ?>
</figure>
