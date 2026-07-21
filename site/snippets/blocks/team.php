<?php /** @var \Kirby\Cms\Block $block */
$members = $block->members()->toStructure();
if ($members->isEmpty()) return;
?>
<section class="block-team grid grid--3">
  <?php foreach ($members as $m): $photo = $m->photo()->toFile(); ?>
    <div class="card">
      <?php if ($photo): ?><?= $photo->crop(400, 400)->html(['alt' => esc($m->name()), 'loading' => 'lazy', 'style' => 'border-radius:var(--radius-sm);margin-bottom:var(--s-4)']) ?><?php endif ?>
      <h3 class="scard__title"><?= esc($m->name()) ?></h3>
      <?php if ($m->role()->isNotEmpty()): ?><p class="pcard__cat"><?= esc($m->role()) ?></p><?php endif ?>
      <?php if ($m->bio()->isNotEmpty()): ?><p class="pcard__summary"><?= esc($m->bio()) ?></p><?php endif ?>
    </div>
  <?php endforeach ?>
</section>
