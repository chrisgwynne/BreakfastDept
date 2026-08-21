<?php snippet('layouts/header') ?>
<article class="section tt-page tt-about-page">
  <div class="container">
    <?php snippet('partials/breadcrumbs') ?>
    <?php snippet('teletext/bar', ['number' => 'P500', 'title' => esc($page->heading()->or($page->title())), 'as' => 'h1']) ?>
    <div class="tt-split">
      <div class="tt-split__main">
        <?php if ($page->intro()->isNotEmpty()): ?><div class="prose" style="font-size:1.1rem"><?= $page->intro()->toBlocks() ?></div><?php endif ?>
      </div>
      <div class="tt-split__aside">
        <?php snippet('teletext/pixel-art', ['motif' => 'mountains', 'size' => 'lg']) ?>
      </div>
    </div>

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

    <?php /* ---------- How Breakfast works (this is also P400 — see Registry) ---------- */ ?>
    <?php if ($page->how_text()->isNotEmpty()): ?>
      <div id="how" style="margin-top:var(--s-12);scroll-margin-top:calc(var(--header-h) + 20px)">
        <?php snippet('teletext/bar', ['number' => 'P400', 'title' => esc($page->how_heading()->or('How Breakfast works')), 'as' => 'h2']) ?>
      </div>
      <p class="section__lead" style="max-width:46rem"><?= esc($page->how_text()) ?></p>
    <?php endif ?>

    <?php /* ---------- Principles ---------- */ ?>
    <?php if ($page->principles()->toStructure()->isNotEmpty()): ?>
      <div style="margin-top:var(--s-12)"><?php snippet('teletext/bar', ['number' => '', 'title' => t('breakfast.about.principles', 'What Breakfast believes'), 'tight' => true, 'as' => 'h2']) ?></div>
      <div class="grid grid--2">
        <?php foreach ($page->principles()->toStructure() as $p): ?><div class="tt-box"><h3 class="scard__title"><?= esc($p->title()) ?></h3><p class="pcard__summary"><?= esc($p->text()) ?></p></div><?php endforeach ?>
      </div>
    <?php endif ?>

    <?php /* ---------- Who Breakfast is for ---------- */ ?>
    <?php if ($page->client_fit()->toStructure()->isNotEmpty() || $page->not_a_fit()->toStructure()->isNotEmpty()): ?>
      <div style="margin-top:var(--s-12)"><?php snippet('teletext/bar', ['number' => '', 'title' => t('breakfast.about.who', 'Who Breakfast is for'), 'tight' => true, 'as' => 'h2']) ?></div>
      <div class="grid grid--2">
        <?php if ($page->client_fit()->toStructure()->isNotEmpty()): ?>
          <div class="tt-box"><h3 class="scard__title"><span class="fit__mark fit__mark--yes" aria-hidden="true"></span> <?= esc(t('breakfast.about.fit', 'A good fit if…')) ?></h3>
            <ul class="fitlist"><?php foreach ($page->client_fit()->toStructure() as $i): ?><li><?= esc($i->text()) ?></li><?php endforeach ?></ul>
          </div>
        <?php endif ?>
        <?php if ($page->not_a_fit()->toStructure()->isNotEmpty()): ?>
          <div class="tt-box"><h3 class="scard__title"><span class="fit__mark fit__mark--no" aria-hidden="true"></span> <?= esc(t('breakfast.about.notfit', 'Probably not if…')) ?></h3>
            <ul class="fitlist fitlist--no"><?php foreach ($page->not_a_fit()->toStructure() as $i): ?><li><?= esc($i->text()) ?></li><?php endforeach ?></ul>
          </div>
        <?php endif ?>
      </div>
    <?php endif ?>
  </div>
</article>
<section class="section"><div class="container"><?php snippet('partials/cta-band') ?></div></section>
<?php snippet('layouts/footer', ['softkeys' => [
    ['label' => 'Back',    'sub' => 'P100', 'href' => url('/')],
    ['label' => 'Work',    'sub' => 'P200', 'href' => page('work') ? page('work')->url() : url('work')],
    ['label' => 'Journal', 'sub' => 'P600', 'href' => page('journal') ? page('journal')->url() : url('journal')],
    ['label' => 'Contact', 'sub' => 'P700', 'href' => url('contact')],
]]) ?>
