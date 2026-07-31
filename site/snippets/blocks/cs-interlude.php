<?php
/** @var \Kirby\Cms\Block $block */
if ($block->text()->isEmpty()) { return; }
?>
<section class="cs__inner reveal" style="text-align:center">
  <p class="cs-specimen__row" style="font-size:clamp(1.8rem,6vw,4rem);line-height:1.05;font-weight:900;letter-spacing:var(--ad-display-track)"><?= esc($block->text()) ?></p>
</section>
