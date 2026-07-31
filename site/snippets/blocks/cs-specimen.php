<?php
/** @var \Kirby\Cms\Block $block */
?>
<section class="cs__inner reveal">
  <div class="cs-specimen__big"><?= esc($block->big()->or('Aa')) ?></div>
  <?php if ($block->name()->isNotEmpty()): ?><p class="cs-cap"><?= esc($block->name()) ?></p><?php endif ?>
  <?php if ($block->sample()->isNotEmpty()): ?><p class="cs-specimen__row"><?= esc($block->sample()) ?></p><?php endif ?>
</section>
