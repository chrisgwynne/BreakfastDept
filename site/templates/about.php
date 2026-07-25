<?php snippet('layouts/header') ?>
<article class="section">
  <div class="container">
    <?php snippet('partials/breadcrumbs') ?>
    <header class="section__head" style="max-width:52rem">
      <span class="kicker"><?= esc(t('breakfast.about.kicker', 'About Breakfast')) ?></span>
      <h1 class="section__title"><?= esc($page->title()) ?></h1>
    </header>
    <?php if ($page->intro()->isNotEmpty()): ?><div class="prose" style="font-size:1.2rem"><?= $page->intro()->toBlocks() ?></div><?php endif ?>

    <?php /* ---------- The person behind Breakfast (polaroid) ---------- */ ?>
    <?php if ($page->founder_name()->isNotEmpty()): ?>
      <div class="founder reveal">
        <div class="founder__photo">
          <?php $photo = $page->founder_photo()->toFile(); ?>
          <figure class="polaroid">
            <?php if ($photo): ?>
              <?= $photo->crop(520, 520)->html(['alt' => esc($page->founder_name()), 'class' => 'polaroid__img']) ?>
            <?php else: ?>
              <span class="polaroid__img polaroid__placeholder" role="img" aria-label="<?= esc($page->founder_name()) ?>"><span class="polaroid__egg"></span></span>
            <?php endif ?>
            <?php if ($page->founder_caption()->isNotEmpty()): ?><figcaption class="polaroid__cap"><?= esc($page->founder_caption()) ?></figcaption><?php endif ?>
          </figure>
        </div>
        <div class="founder__copy">
          <h2 class="section__title" style="font-size:1.8rem"><?= esc($page->founder_name()) ?></h2>
          <?php if ($page->founder_role()->isNotEmpty()): ?><p class="founder__role"><?= esc($page->founder_role()) ?></p><?php endif ?>
          <?php if ($page->founder_bio()->isNotEmpty()): ?><p class="founder__bio"><?= esc($page->founder_bio()) ?></p><?php endif ?>
        </div>
      </div>
    <?php endif ?>

    <?php /* ---------- How Breakfast works ---------- */ ?>
    <?php if ($page->how_text()->isNotEmpty()): ?>
      <div class="section__head" style="margin-top:var(--s-16)"><h2 class="section__title" style="font-size:1.8rem"><?= esc($page->how_heading()->or('How Breakfast works')) ?></h2></div>
      <p class="section__lead" style="max-width:46rem"><?= esc($page->how_text()) ?></p>
    <?php endif ?>

    <?php /* ---------- Principles ---------- */ ?>
    <?php if ($page->principles()->toStructure()->isNotEmpty()): ?>
      <div class="section__head" style="margin-top:var(--s-16)"><h2 class="section__title" style="font-size:1.8rem"><?= esc(t('breakfast.about.principles', 'What Breakfast believes')) ?></h2></div>
      <div class="grid grid--2">
        <?php foreach ($page->principles()->toStructure() as $i => $p): ?><div class="card reveal" style="--i:<?= $i ?>"><h3 class="scard__title"><?= esc($p->title()) ?></h3><p class="pcard__summary"><?= esc($p->text()) ?></p></div><?php endforeach ?>
      </div>
    <?php endif ?>

    <?php /* ---------- Who Breakfast is for ---------- */ ?>
    <?php if ($page->client_fit()->toStructure()->isNotEmpty() || $page->not_a_fit()->toStructure()->isNotEmpty()): ?>
      <div class="section__head" style="margin-top:var(--s-16)"><h2 class="section__title" style="font-size:1.8rem"><?= esc(t('breakfast.about.who', 'Who Breakfast is for')) ?></h2></div>
      <div class="grid grid--2">
        <?php if ($page->client_fit()->toStructure()->isNotEmpty()): ?>
          <div class="card card--fit reveal"><h3 class="scard__title"><span class="fit__mark fit__mark--yes" aria-hidden="true"></span> <?= esc(t('breakfast.about.fit', 'A good fit if…')) ?></h3>
            <ul class="fitlist"><?php foreach ($page->client_fit()->toStructure() as $i): ?><li><?= esc($i->text()) ?></li><?php endforeach ?></ul>
          </div>
        <?php endif ?>
        <?php if ($page->not_a_fit()->toStructure()->isNotEmpty()): ?>
          <div class="card card--fit reveal"><h3 class="scard__title"><span class="fit__mark fit__mark--no" aria-hidden="true"></span> <?= esc(t('breakfast.about.notfit', 'Probably not if…')) ?></h3>
            <ul class="fitlist fitlist--no"><?php foreach ($page->not_a_fit()->toStructure() as $i): ?><li><?= esc($i->text()) ?></li><?php endforeach ?></ul>
          </div>
        <?php endif ?>
      </div>
    <?php endif ?>
  </div>
</article>
<section class="section"><div class="container"><?php snippet('partials/cta-band') ?></div></section>
<?php snippet('layouts/footer') ?>
