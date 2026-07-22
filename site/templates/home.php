<?php snippet('layouts/header') ?>

<?php /* ---------- Hero ---------- */ ?>
<section class="hero">
  <div class="container hero__inner">
    <div class="hero__copy">
      <?php if ($page->hero_eyebrow()->isNotEmpty()): ?>
        <p class="eyebrow"><span class="eyebrow__dot" aria-hidden="true"></span> <?= esc($page->hero_eyebrow()) ?></p>
      <?php endif ?>
      <h1 class="hero__title">
        <?= esc($page->hero_headline()) ?>
        <?php if ($page->hero_highlight()->isNotEmpty()): ?> <span class="hl hl--yellow"><?= esc($page->hero_highlight()) ?></span><?php endif ?>
      </h1>
      <?php if ($page->hero_text()->isNotEmpty()): ?><p class="hero__sub"><?= esc($page->hero_text()) ?></p><?php endif ?>
      <div class="hero__actions">
        <?php if ($page->hero_primary_label()->isNotEmpty()): ?>
          <a class="btn btn--secondary btn--lg" data-track="cta_click" data-track-label="hero-primary" href="<?= esc($page->hero_primary_link()->or(url('start-a-project'))) ?>"><?= esc($page->hero_primary_label()) ?></a>
        <?php endif ?>
        <?php if ($page->hero_secondary_label()->isNotEmpty()): ?>
          <a class="btn btn--ghost btn--lg" href="<?= esc($page->hero_secondary_link()->or(url('work'))) ?>"><?= esc($page->hero_secondary_label()) ?></a>
        <?php endif ?>
      </div>
      <?php if ($site->availability_enabled()->toBool(false) && $page->hero_availability()->isNotEmpty()): ?>
        <p class="hero__availability eyebrow"><span class="eyebrow__dot" aria-hidden="true"></span> <?= esc($page->hero_availability()) ?></p>
      <?php endif ?>
    </div>
    <?php if ($img = $page->hero_image()->toFile()): ?>
      <div class="hero__media"><?= $img->crop(760, 620)->html(['alt' => esc($img->alt()->or($page->hero_headline())), 'fetchpriority' => 'high']) ?></div>
    <?php else: ?>
      <?php /* No hero image: a brand object built in CSS — the "good website" Breakfast makes, in a browser window. Decorative. */ ?>
      <div class="hero__object" aria-hidden="true">
        <div class="egg egg--float"></div>
        <div class="browser">
          <div class="browser__bar">
            <span class="browser__dots"><i></i><i></i><i></i></span>
            <span class="browser__url">yourbusiness<b>.cymru</b></span>
          </div>
          <div class="browser__screen">
            <div class="mock__nav"><span class="mock__logo"></span><span class="mock__menu"><i></i><i></i><i></i></span></div>
            <div class="mock__hero">
              <span class="mock__eyebrow"></span>
              <span class="mock__line mock__line--xl"></span>
              <span class="mock__line mock__line--lg"></span>
              <span class="mock__btn"></span>
            </div>
            <div class="mock__cards"><span></span><span></span><span></span></div>
          </div>
        </div>
      </div>
    <?php endif ?>
  </div>
</section>

<?php /* ---------- Proposition ---------- */ ?>
<?php if ($page->proposition_enabled()->toBool(true)): ?>
<section class="section section--alt">
  <div class="container">
    <div class="section__head">
      <span class="kicker"><?= esc(t('breakfast.what', 'What we do')) ?></span>
      <h2 class="section__title"><?= esc($page->proposition_heading()) ?></h2>
      <?php if ($page->proposition_text()->isNotEmpty()): ?><p class="section__lead"><?= esc($page->proposition_text()) ?></p><?php endif ?>
    </div>
    <?php $points = $page->proposition_points()->toStructure(); ?>
    <?php if ($points->isNotEmpty()): ?>
      <div class="grid grid--3">
        <?php foreach ($points as $p): ?>
          <div class="card reveal"><h3 class="scard__title"><?= esc($p->title()) ?></h3><p class="pcard__summary"><?= esc($p->text()) ?></p></div>
        <?php endforeach ?>
      </div>
    <?php endif ?>
  </div>
</section>
<?php endif ?>

<?php /* ---------- Selected work ---------- */ ?>
<?php if ($page->work_enabled()->toBool(true)): ?>
<section class="section">
  <div class="container">
    <div class="section__head">
      <span class="kicker"><?= esc(t('breakfast.thework', 'The work')) ?></span>
      <h2 class="section__title"><?= esc($page->work_heading()) ?></h2>
      <?php if ($page->work_intro()->isNotEmpty()): ?><p class="section__lead"><?= esc($page->work_intro()) ?></p><?php endif ?>
    </div>
    <?php
      $projects = $page->work_projects()->toPages();
      if ($projects->isEmpty() && ($work = page('work'))) {
        $projects = $work->children()->listed()->filterBy('featured', true);
        if ($projects->isEmpty()) $projects = $work->children()->listed();
      }
      $projects = $projects->limit($page->work_count()->or(3)->toInt());
    ?>
    <div class="grid grid--3">
      <?php foreach ($projects as $project) snippet('partials/project-card', ['project' => $project]) ?>
    </div>
    <p style="margin-top:var(--s-8)"><a class="link-cta" href="<?= esc(url('work')) ?>"><?= esc(t('breakfast.allwork', 'See all our work')) ?> <span aria-hidden="true">→</span></a></p>
  </div>
</section>
<?php endif ?>

<?php /* ---------- Services ---------- */ ?>
<?php if ($page->services_enabled()->toBool(true)): ?>
<section class="section section--alt">
  <div class="container">
    <div class="section__head">
      <span class="kicker"><?= esc(t('breakfast.services', 'Services')) ?></span>
      <h2 class="section__title"><?= esc($page->services_heading()) ?></h2>
      <?php if ($page->services_intro()->isNotEmpty()): ?><p class="section__lead"><?= esc($page->services_intro()) ?></p><?php endif ?>
    </div>
    <?php
      $services = $page->services_list()->toPages();
      if ($services->isEmpty() && ($s = page('services'))) $services = $s->children()->listed();
    ?>
    <div class="grid grid--3">
      <?php foreach ($services as $service) snippet('partials/service-card', ['service' => $service]) ?>
    </div>
  </div>
</section>
<?php endif ?>

<?php /* ---------- Problems ---------- */ ?>
<?php if ($page->problems_enabled()->toBool(true) && $page->problems_items()->toStructure()->isNotEmpty()): ?>
<section class="section">
  <div class="container">
    <div class="section__head"><h2 class="section__title"><?= esc($page->problems_heading()) ?></h2></div>
    <div class="grid grid--2">
      <?php foreach ($page->problems_items()->toStructure() as $item): ?>
        <div class="card reveal"><h3 class="scard__title"><?= esc($item->title()) ?></h3><p class="pcard__summary"><?= esc($item->text()) ?></p></div>
      <?php endforeach ?>
    </div>
  </div>
</section>
<?php endif ?>

<?php /* ---------- Process ---------- */ ?>
<?php if ($page->process_enabled()->toBool(true) && $page->process_steps()->toStructure()->isNotEmpty()): ?>
<section class="section section--alt">
  <div class="container">
    <div class="section__head"><span class="kicker"><?= esc(t('breakfast.howitgoes', 'How it goes')) ?></span><h2 class="section__title"><?= esc($page->process_heading()) ?></h2></div>
    <ol class="timeline">
      <?php $n = 0; foreach ($page->process_steps()->toStructure() as $step): $n++; ?>
        <li class="timeline__item"><span class="timeline__num" aria-hidden="true"><?= $n ?></span><h3><?= esc($step->title()) ?></h3><p class="pcard__summary"><?= esc($step->text()) ?></p></li>
      <?php endforeach ?>
    </ol>
  </div>
</section>
<?php endif ?>

<?php /* ---------- Proof ---------- */ ?>
<?php if ($page->proof_enabled()->toBool(true)): ?>
<section class="section">
  <div class="container">
    <?php if ($page->proof_heading()->isNotEmpty()): ?><div class="section__head"><h2 class="section__title"><?= esc($page->proof_heading()) ?></h2></div><?php endif ?>
    <?php $testimonials = $page->testimonials()->toStructure(); ?>
    <?php if ($testimonials->isNotEmpty()): ?>
      <div class="grid grid--2">
        <?php foreach ($testimonials as $t): ?>
          <figure class="tcard reveal">
            <blockquote class="quote__text" style="font-size:1.3rem"><?= esc($t->quote()) ?></blockquote>
            <figcaption class="quote__cite">— <?= esc($t->name()) ?><?php if ($t->role()->isNotEmpty()): ?>, <?= esc($t->role()) ?><?php endif ?><?php if ($t->company()->isNotEmpty()): ?>, <?= esc($t->company()) ?><?php endif ?></figcaption>
          </figure>
        <?php endforeach ?>
      </div>
    <?php endif ?>
    <?php $logos = $page->logos()->toStructure(); ?>
    <?php if ($logos->isNotEmpty()): ?>
      <ul class="logos" style="margin-top:var(--s-12)">
        <?php foreach ($logos as $l): ?><li class="logos__item"><span class="logos__name"><?= esc($l->name()) ?></span><?php if ($l->category()->isNotEmpty()): ?><span class="logos__cat"><?= esc($l->category()) ?></span><?php endif ?></li><?php endforeach ?>
      </ul>
    <?php endif ?>
  </div>
</section>
<?php endif ?>

<?php /* ---------- Studio statement ---------- */ ?>
<?php if ($page->statement_enabled()->toBool(true) && $page->statement_text()->isNotEmpty()): ?>
<section class="section section--alt">
  <div class="container container--prose">
    <?php if ($page->statement_heading()->isNotEmpty()): ?><span class="kicker"><?= esc($page->statement_heading()) ?></span><?php endif ?>
    <p class="section__title" style="font-size:clamp(1.6rem,3vw,2.4rem);margin-top:var(--s-4)"><?= esc($page->statement_text()) ?></p>
    <p style="margin-top:var(--s-6)"><a class="link-cta" href="<?= esc(url('about')) ?>"><?= esc(t('breakfast.moreabout', 'More about the studio')) ?> <span aria-hidden="true">→</span></a></p>
  </div>
</section>
<?php endif ?>

<?php /* ---------- Journal ---------- */ ?>
<?php if ($page->journal_enabled()->toBool(true) && ($journal = page('journal'))): ?>
  <?php $latest = $journal->children()->listed()->sortBy('date', 'desc')->limit($page->journal_count()->or(3)->toInt()); ?>
  <?php if ($latest->isNotEmpty()): ?>
  <section class="section">
    <div class="container">
      <div class="section__head"><span class="kicker"><?= esc(t('breakfast.journal', 'Journal')) ?></span><h2 class="section__title"><?= esc($page->journal_heading()->or('From the journal')) ?></h2></div>
      <div class="grid grid--3">
        <?php foreach ($latest as $article) snippet('partials/article-card', ['article' => $article]) ?>
      </div>
    </div>
  </section>
  <?php endif ?>
<?php endif ?>

<?php /* ---------- Final CTA ---------- */ ?>
<?php if ($page->final_cta_enabled()->toBool(true)): ?>
<section class="section">
  <div class="container">
    <?php snippet('partials/cta-band', [
      'heading' => $page->final_cta_heading()->or($site->cta_heading()),
      'text'    => $page->final_cta_text()->or($site->cta_text()),
    ]) ?>
  </div>
</section>
<?php endif ?>

<?php snippet('layouts/footer') ?>
