<?php
/** @var \Kirby\Cms\Block $block */
if ($block->heading()->isEmpty() && $block->text()->isEmpty()) { return; }
$num = $block->number()->isEmpty() ? 1 : (int) $block->number()->value();
if ($num < 1) { $num = 1; }
?>
<section class="cs__inner cs-numbered reveal">
  <div class="cs-numbered__n" aria-hidden="true"><?= esc(str_pad((string) $num, 2, '0', STR_PAD_LEFT)) ?></div>
  <div class="cs-text">
    <?php if ($block->heading()->isNotEmpty()): ?><h2><?= esc($block->heading()) ?></h2><?php endif ?>
    <?= $block->text()->kt() ?>
  </div>
</section>
