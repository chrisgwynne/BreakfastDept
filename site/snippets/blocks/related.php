<?php /** @var \Kirby\Cms\Block $block */
$pages = $block->pages()->toPages();
if ($pages->isEmpty()) return;
?>
<section class="block-related">
  <?php if ($block->heading()->isNotEmpty()): ?><div class="section__head"><h2 class="section__title"><?= esc($block->heading()) ?></h2></div><?php endif ?>
  <div class="grid grid--3">
    <?php foreach ($pages as $p): ?>
      <a class="card" href="<?= esc($p->url()) ?>"><h3 class="scard__title"><?= esc($p->title()) ?></h3><?php if ($p->summary()->or($p->excerpt())->isNotEmpty()): ?><p class="pcard__summary"><?= esc($p->summary()->or($p->excerpt())->excerptSafe(120)) ?></p><?php endif ?></a>
    <?php endforeach ?>
  </div>
</section>
