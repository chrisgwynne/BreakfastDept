<?php /** @var \Kirby\Cms\Block $block */
$items = $block->items()->toStructure();
if ($items->isEmpty()) return;
?>
<section class="block-testimonials grid grid--<?= $items->count() > 1 ? '2' : '1' ?>">
  <?php foreach ($items as $item): ?>
    <figure class="tcard">
      <blockquote class="quote__text" style="font-size:1.3rem"><?= esc($item->quote()) ?></blockquote>
      <figcaption class="quote__cite">— <?= esc($item->name()) ?><?php if ($item->role()->isNotEmpty()): ?>, <?= esc($item->role()) ?><?php endif ?><?php if ($item->company()->isNotEmpty()): ?>, <?= esc($item->company()) ?><?php endif ?></figcaption>
    </figure>
  <?php endforeach ?>
</section>
