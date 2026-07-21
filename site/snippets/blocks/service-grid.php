<?php /** @var \Kirby\Cms\Block $block */
$services = $block->services()->toPages();
if ($services->isEmpty() && ($s = page('services'))) $services = $s->children()->listed();
if ($services->isEmpty()) return;
?>
<section class="block-service-grid">
  <?php if ($block->heading()->isNotEmpty()): ?><div class="section__head"><h2 class="section__title"><?= esc($block->heading()) ?></h2></div><?php endif ?>
  <div class="grid grid--3">
    <?php foreach ($services as $service) snippet('partials/service-card', ['service' => $service]) ?>
  </div>
</section>
