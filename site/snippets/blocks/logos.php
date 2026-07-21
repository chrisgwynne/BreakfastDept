<?php /** @var \Kirby\Cms\Block $block */
$items = $block->items()->toStructure();
if ($items->isEmpty()) return;
?>
<section class="block-logos">
  <?php if ($block->heading()->isNotEmpty()): ?><p class="section__lead" style="text-align:center"><?= esc($block->heading()) ?></p><?php endif ?>
  <ul class="logos">
    <?php foreach ($items as $item): ?>
      <li class="logos__item"><span class="logos__name"><?= esc($item->name()) ?></span><?php if ($item->category()->isNotEmpty()): ?><span class="logos__cat"><?= esc($item->category()) ?></span><?php endif ?></li>
    <?php endforeach ?>
  </ul>
</section>
