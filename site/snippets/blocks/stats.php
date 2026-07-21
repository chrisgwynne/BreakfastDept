<?php /** @var \Kirby\Cms\Block $block */
$items = $block->items()->toStructure();
if ($items->isEmpty()) return;
?>
<div class="stats">
  <?php foreach ($items as $item): ?>
    <div class="stat">
      <div class="stat__value"><?= esc($item->value()) ?></div>
      <div class="stat__label"><?= esc($item->label()) ?></div>
      <?php if ($item->context()->isNotEmpty()): ?><p class="stat__context"><?= esc($item->context()) ?></p><?php endif ?>
    </div>
  <?php endforeach ?>
</div>
