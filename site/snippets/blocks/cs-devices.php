<?php
/** @var \Kirby\Cms\Block $block */
$desktop = $block->desktop()->toFile();
$mobile  = $block->mobile()->toFile();
if (!$desktop && !$mobile) { return; }
?>
<section class="cs__inner reveal">
  <div class="cs__devices">
    <?php if ($desktop): ?><figure class="cs-surface"><?= $desktop->crop(1600, 1000)->html(['alt' => esc($desktop->alt()->or('Desktop view')), 'loading' => 'lazy']) ?></figure><?php endif ?>
    <?php if ($mobile): ?><figure class="cs__device-mobile cs-surface" style="width:32%;margin-left:auto;margin-top:-14%;transform:rotate(var(--cs-rot))"><?= $mobile->crop(600, 1000)->html(['alt' => esc($mobile->alt()->or('Mobile view')), 'loading' => 'lazy']) ?></figure><?php endif ?>
  </div>
  <?php if ($block->caption()->isNotEmpty()): ?><p class="cs-cap"><?= esc($block->caption()) ?></p><?php endif ?>
</section>
