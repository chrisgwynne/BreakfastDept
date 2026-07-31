<?php
/** @var \Kirby\Cms\Block $block */
if ($block->text()->isEmpty()) { return; }
$style = $block->style()->value();          // '' = inherit page default
$over  = $style === 'over-image';
$bg    = $over ? $block->image()->toFile() : null;
$isTestimonial = $block->kind()->value() === 'testimonial';
$open  = $style !== '';                       // wrap to override the page-level pull-quote style
?>
<section class="cs__inner reveal">
<?php if ($open): ?><div data-ad-pullquote="<?= esc($style) ?>"><?php endif ?>
  <figure class="cs-quote<?= $over && $bg ? ' cs-quote--over' : '' ?>">
    <?php if ($over && $bg): ?><?= $bg->crop(1600, 900)->html(['alt' => '', 'loading' => 'lazy']) ?><?php endif ?>
    <?php if ($isTestimonial): ?>
      <blockquote class="cs-quote__text"><?= esc($block->text()) ?></blockquote>
      <?php if ($block->attribution()->isNotEmpty()): ?><figcaption class="cs-quote__cite">— <?= esc($block->attribution()) ?><?php if ($block->role()->isNotEmpty()): ?>, <?= esc($block->role()) ?><?php endif ?></figcaption><?php endif ?>
    <?php else: ?>
      <p class="cs-quote__text"><?= esc($block->text()) ?></p>
    <?php endif ?>
  </figure>
<?php if ($open): ?></div><?php endif ?>
</section>
