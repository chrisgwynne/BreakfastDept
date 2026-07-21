<?php /** @var \Kirby\Cms\Block $block */
$variant = in_array($block->variant()->value(), ['info','warning'], true) ? $block->variant()->value() : 'info';
?>
<div class="callout callout--<?= $variant ?>">
  <?php if ($block->title()->isNotEmpty()): ?><strong><?= esc($block->title()) ?></strong><?php endif ?>
  <div class="prose"><?= $block->text()->kt() ?></div>
</div>
