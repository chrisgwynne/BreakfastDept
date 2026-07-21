<?php /** @var \Kirby\Cms\Block $block */
$items = $block->items()->toStructure();
if ($items->isEmpty()) return;
?>
<div class="faq">
  <?php if ($block->heading()->isNotEmpty()): ?><h2 class="section__title" style="margin-bottom:var(--s-8)"><?= esc($block->heading()) ?></h2><?php endif ?>
  <?php foreach ($items as $item): ?>
    <details class="faq__item">
      <summary class="faq__q"><?= esc($item->question()) ?></summary>
      <div class="faq__a"><?= $item->answer()->kt() ?></div>
    </details>
  <?php endforeach ?>
</div>
