<?php /** @var \Kirby\Cms\Block $block */
$image = $block->image()->toFile();
?>
<section class="hero" style="padding-block:var(--s-16)">
  <div class="hero__inner">
    <div class="hero__copy">
      <?php if ($block->eyebrow()->isNotEmpty()): ?><p class="eyebrow"><?= esc($block->eyebrow()) ?></p><?php endif ?>
      <h1 class="hero__title"><?= esc($block->heading()) ?></h1>
      <?php if ($block->text()->isNotEmpty()): ?><p class="hero__sub"><?= esc($block->text()) ?></p><?php endif ?>
    </div>
    <?php if ($image): ?><div class="hero__media"><?= $image->crop(700, 520)->html(['alt' => esc($block->heading())]) ?></div><?php endif ?>
  </div>
</section>
