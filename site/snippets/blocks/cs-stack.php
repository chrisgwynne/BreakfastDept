<?php
/** @var \Kirby\Cms\Block $block */
$imgs = $block->images()->toFiles();
if ($imgs->count() < 2) { return; }
?>
<section class="cs__inner reveal">
  <div class="cs-stack">
    <?php foreach ($imgs->limit(3) as $img): ?><figure class="cs-surface"><?= $img->crop(1400, 950)->html(['alt' => esc($img->alt()->or('Screenshot')), 'loading' => 'lazy']) ?></figure><?php endforeach ?>
  </div>
  <?php if ($block->caption()->isNotEmpty()): ?><p class="cs-cap"><?= esc($block->caption()) ?></p><?php endif ?>
</section>
