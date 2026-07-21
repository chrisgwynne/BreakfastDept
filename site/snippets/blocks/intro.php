<?php /** @var \Kirby\Cms\Block $block */ ?>
<div class="block-intro section__head">
  <?php if ($block->kicker()->isNotEmpty()): ?><span class="kicker"><?= esc($block->kicker()) ?></span><?php endif ?>
  <?php if ($block->heading()->isNotEmpty()): ?><h2 class="section__title"><?= esc($block->heading()) ?></h2><?php endif ?>
  <?php if ($block->text()->isNotEmpty()): ?><p class="section__lead"><?= esc($block->text()) ?></p><?php endif ?>
</div>
