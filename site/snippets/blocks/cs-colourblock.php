<?php
/** @var \Kirby\Cms\Block $block */
use Breakfast\Platform\Content\ArtDirection;
if ($block->heading()->isEmpty() && $block->text()->isEmpty()) { return; }
$c = $block->colour()->value();
if ($c === 'accent') { $bg = 'var(--cs-accent)'; $fg = 'var(--cs-on-accent)'; }
elseif ($c === 'secondary') { $bg = 'var(--cs-secondary)'; $fg = 'var(--ad-on-secondary,#fff)'; }
else { $hex = ArtDirection::COLOURS[$c] ?? ArtDirection::COLOURS['ink']; $bg = $hex; $fg = ArtDirection::onColour($c); }
?>
<section class="cs-colourblock reveal" style="background:<?= esc($bg, 'attr') ?>;color:<?= esc($fg, 'attr') ?>">
  <div class="cs__inner">
    <?php if ($block->heading()->isNotEmpty()): ?><p class="cs-statement" style="max-width:26ch"><?= esc($block->heading()) ?></p><?php endif ?>
    <?php if ($block->text()->isNotEmpty()): ?><div class="cs-text" style="margin-top:1rem;max-width:44rem"><?= $block->text()->kt() ?></div><?php endif ?>
  </div>
</section>
