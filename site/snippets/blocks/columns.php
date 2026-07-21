<?php /** @var \Kirby\Cms\Block $block */ ?>
<div class="columns">
  <div class="prose"><?= $block->left()->toBlocks() ?></div>
  <div class="prose"><?= $block->right()->toBlocks() ?></div>
</div>
