<?php snippet('layouts/header') ?>
<article class="section">
  <div class="container">
    <?php snippet('partials/breadcrumbs') ?>
    <header class="section__head" style="max-width:52rem">
      <h1 class="section__title"><?= esc($page->title()) ?></h1>
    </header>
    <?php if ($page->intro()->isNotEmpty()): ?><div class="blocks"><?= $page->intro()->toBlocks() ?></div><?php endif ?>

    <?php if ($page->principles()->toStructure()->isNotEmpty()): ?>
      <div class="section__head" style="margin-top:var(--s-16)"><h2 class="section__title" style="font-size:1.8rem"><?= esc(t('breakfast.about.principles', 'What we believe')) ?></h2></div>
      <div class="grid grid--3">
        <?php foreach ($page->principles()->toStructure() as $p): ?><div class="card"><h3 class="scard__title"><?= esc($p->title()) ?></h3><p class="pcard__summary"><?= esc($p->text()) ?></p></div><?php endforeach ?>
      </div>
    <?php endif ?>

    <?php if ($page->team()->toStructure()->isNotEmpty()): ?>
      <div class="section__head" style="margin-top:var(--s-16)"><h2 class="section__title" style="font-size:1.8rem"><?= esc(t('breakfast.about.team', 'Who you’ll work with')) ?></h2></div>
      <div class="grid grid--3">
        <?php foreach ($page->team()->toStructure() as $m): $photo = $m->photo()->toFile(); ?>
          <div class="card">
            <?php if ($photo): ?><?= $photo->crop(500, 500)->html(['alt' => esc($m->name()), 'style' => 'border-radius:var(--radius-sm);margin-bottom:var(--s-4)']) ?><?php endif ?>
            <h3 class="scard__title"><?= esc($m->name()) ?></h3>
            <?php if ($m->role()->isNotEmpty()): ?><p class="pcard__cat"><?= esc($m->role()) ?></p><?php endif ?>
            <?php if ($m->bio()->isNotEmpty()): ?><p class="pcard__summary"><?= esc($m->bio()) ?></p><?php endif ?>
          </div>
        <?php endforeach ?>
      </div>
    <?php endif ?>

    <div class="grid grid--2" style="margin-top:var(--s-16)">
      <?php if ($page->client_fit()->toStructure()->isNotEmpty()): ?>
        <div class="card"><h2 class="scard__title"><?= esc(t('breakfast.about.fit', 'A good fit if…')) ?></h2>
          <ul class="prose" style="list-style:disc;padding-left:var(--s-6)"><?php foreach ($page->client_fit()->toStructure() as $i): ?><li><?= esc($i->text()) ?></li><?php endforeach ?></ul>
        </div>
      <?php endif ?>
      <?php if ($page->not_a_fit()->toStructure()->isNotEmpty()): ?>
        <div class="card"><h2 class="scard__title"><?= esc(t('breakfast.about.notfit', 'Probably not for you if…')) ?></h2>
          <ul class="prose" style="list-style:disc;padding-left:var(--s-6)"><?php foreach ($page->not_a_fit()->toStructure() as $i): ?><li><?= esc($i->text()) ?></li><?php endforeach ?></ul>
        </div>
      <?php endif ?>
    </div>

    <?php if ($page->timeline()->toStructure()->isNotEmpty()): ?>
      <div class="section__head" style="margin-top:var(--s-16)"><h2 class="section__title" style="font-size:1.8rem"><?= esc(t('breakfast.about.timeline', 'A short history')) ?></h2></div>
      <ol class="prose">
        <?php foreach ($page->timeline()->toStructure() as $t): ?><li><strong><?= esc($t->year()) ?> — <?= esc($t->title()) ?>.</strong> <?= esc($t->text()) ?></li><?php endforeach ?>
      </ol>
    <?php endif ?>
  </div>
</article>
<section class="section"><div class="container"><?php snippet('partials/cta-band') ?></div></section>
<?php snippet('layouts/footer') ?>
