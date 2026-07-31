<?php
/** @var \Kirby\Cms\Block $block */
$items = $block->items()->toStructure();
if ($items->isEmpty()) { return; }
?>
<section class="cs__inner reveal">
  <div class="cs-facts">
    <?php foreach ($items as $it): ?>
      <div class="cs-facts__item"><div class="v"><?= esc($it->value()) ?></div><div class="l"><?= esc($it->label()) ?></div></div>
    <?php endforeach ?>
  </div>
</section>
