<?php
/** @var \Kirby\Cms\Block $block */
$imgs = $block->images()->toFiles();
if ($imgs->count() < 2) { return; }
?>
<section class="cs__inner reveal">
  <div class="cs-strip" tabindex="0" role="group" aria-label="<?= esc($block->caption()->or('Screenshot sequence')) ?>">
    <?php foreach ($imgs as $img): ?><figure class="cs-surface"><?= $img->crop(1120, 800)->html(['alt' => esc($img->alt()->or('Screenshot')), 'loading' => 'lazy']) ?></figure><?php endforeach ?>
  </div>
  <?php if ($block->caption()->isNotEmpty()): ?><p class="cs-cap"><?= esc($block->caption()) ?></p><?php endif ?>
</section>
