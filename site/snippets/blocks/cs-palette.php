<?php
/** @var \Kirby\Cms\Block $block */
use Breakfast\Platform\Content\ArtDirection;
$sw = $block->swatches()->toStructure();
if ($sw->isEmpty()) { return; }
?>
<section class="cs__inner reveal">
  <div class="cs-palette">
    <?php foreach ($sw as $s):
      $key = $s->colour()->value();
      $hex = ArtDirection::COLOURS[$key] ?? ArtDirection::COLOURS['ink'];
      $fg  = ArtDirection::onColour($key);
    ?>
      <div class="cs-palette__swatch" style="background:<?= esc($hex, 'attr') ?>;color:<?= esc($fg, 'attr') ?>"><?= esc($s->label()->or($key)) ?></div>
    <?php endforeach ?>
  </div>
</section>
