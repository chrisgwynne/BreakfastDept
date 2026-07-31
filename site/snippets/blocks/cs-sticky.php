<?php
/** @var \Kirby\Cms\Block $block */
$img = $block->image()->toFile();
$steps = $block->steps()->toStructure();
if (!$img || $steps->isEmpty()) { return; }
?>
<section class="cs__inner reveal">
  <div class="cs-sticky">
    <div class="cs-sticky__media"><figure class="cs-surface"><?= $img->crop(1200, 1500)->html(['alt' => esc($img->alt()->or('Project detail')), 'loading' => 'lazy']) ?></figure></div>
    <div class="cs-sticky__steps">
      <?php foreach ($steps as $s): ?>
        <div class="cs-text"><?php if ($s->heading()->isNotEmpty()): ?><h2><?= esc($s->heading()) ?></h2><?php endif ?><?= $s->text()->kt() ?></div>
      <?php endforeach ?>
    </div>
  </div>
</section>
