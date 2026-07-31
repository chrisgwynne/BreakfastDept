<?php
/** @var \Kirby\Cms\Block $block */
if ($block->text()->isEmpty() && $block->heading()->isEmpty()) { return; }
$align = $block->align()->value() === 'center' ? ' cs-text--center' : '';
?>
<section class="cs__inner reveal">
  <div class="cs-text<?= $align ?>">
    <?php if ($block->heading()->isNotEmpty()): ?><h2><?= esc($block->heading()) ?></h2><?php endif ?>
    <?= $block->text()->kt() ?>
  </div>
</section>
