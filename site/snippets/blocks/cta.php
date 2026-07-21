<?php /** @var \Kirby\Cms\Block $block */
$style = $block->style()->or('primary');
$link = $block->link()->toPage() ? $block->link()->toPage()->url() : $block->link()->value();
?>
<div class="cta-band">
  <?php if ($block->heading()->isNotEmpty()): ?><h2 class="cta-band__title"><?= esc($block->heading()) ?></h2><?php endif ?>
  <?php if ($block->text()->isNotEmpty()): ?><p class="cta-band__text"><?= esc($block->text()) ?></p><?php endif ?>
  <?php if ($block->label()->isNotEmpty()): ?>
    <div class="cta-band__actions">
      <a class="btn btn--<?= $style === 'secondary' ? 'primary' : 'primary' ?> btn--lg" data-track="cta_click" data-track-label="block" href="<?= esc($link ?: url('start-a-project')) ?>"><?= esc($block->label()) ?></a>
    </div>
  <?php endif ?>
</div>
