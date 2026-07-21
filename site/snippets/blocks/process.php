<?php /** @var \Kirby\Cms\Block $block */
$steps = $block->steps()->toStructure();
if ($steps->isEmpty()) return;
?>
<section class="block-process">
  <?php if ($block->heading()->isNotEmpty()): ?><div class="section__head"><h2 class="section__title"><?= esc($block->heading()) ?></h2></div><?php endif ?>
  <ol class="timeline">
    <?php $n = 0; foreach ($steps as $step): $n++; ?>
      <li class="timeline__item">
        <span class="timeline__num" aria-hidden="true"><?= $n ?></span>
        <h3><?= esc($step->title()) ?></h3>
        <p class="pcard__summary"><?= esc($step->text()) ?></p>
      </li>
    <?php endforeach ?>
  </ol>
</section>
