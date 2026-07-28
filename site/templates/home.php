<?php snippet('layouts/header') ?>

<?php /* ============================== Hero ============================== */ ?>
<section class="hero" data-hero>
  <div class="container hero__inner">
    <div class="hero__copy">
      <?php if ($page->hero_eyebrow()->isNotEmpty()): ?>
        <p class="eyebrow hero__seq" data-seq="1"><span class="eyebrow__dot" aria-hidden="true"></span> <?= esc($page->hero_eyebrow()) ?></p>
      <?php endif ?>
      <h1 class="hero__title hero__seq" data-seq="2">
        <?= esc($page->hero_headline()) ?>
        <?php if ($page->hero_highlight()->isNotEmpty()): ?> <span class="hl hl--yellow"><?= esc($page->hero_highlight()) ?></span><?php endif ?>
      </h1>
      <?php if ($page->hero_text()->isNotEmpty()): ?><p class="hero__sub hero__seq" data-seq="3"><?= esc($page->hero_text()) ?></p><?php endif ?>
      <div class="hero__actions hero__seq" data-seq="4">
        <?php if ($page->hero_primary_label()->isNotEmpty()): ?>
          <a class="btn btn--secondary btn--lg" data-track="cta_click" data-track-label="hero-primary" href="<?= esc($page->hero_primary_link()->or(url('start-a-project'))) ?>"><?= esc($page->hero_primary_label()) ?></a>
        <?php endif ?>
        <?php if ($page->hero_secondary_label()->isNotEmpty()): ?>
          <a class="btn btn--ghost btn--lg" href="<?= esc($page->hero_secondary_link()->or('#process')) ?>"><?= esc($page->hero_secondary_label()) ?></a>
        <?php endif ?>
      </div>
      <?php if ($page->hero_aside_label()->isNotEmpty()): ?>
        <p class="hero__aside hero__seq" data-seq="5"><a class="link-cta link-cta--sm" href="<?= esc($page->hero_aside_link()->or(url('contact'))) ?>"><?= esc($page->hero_aside_label()) ?> <span aria-hidden="true">→</span></a></p>
      <?php endif ?>
      <?php if ($site->availability_enabled()->toBool(false) && $page->hero_availability()->isNotEmpty()): ?>
        <p class="hero__availability eyebrow hero__seq" data-seq="6"><span class="eyebrow__dot" aria-hidden="true"></span> <?= esc($page->hero_availability()) ?></p>
      <?php endif ?>
    </div>
    <?php if ($img = $page->hero_image()->toFile()): ?>
      <div class="hero__media hero__seq" data-seq="4"><?= $img->crop(760, 620)->html(['alt' => esc($img->alt()->or($page->hero_headline())), 'fetchpriority' => 'high']) ?></div>
    <?php else: ?>
      <?php /* No hero image: a brand object built in CSS — the "good website" Breakfast makes, layered browser + phone. Decorative. */ ?>
      <div class="hero__object hero__seq" data-seq="4" data-tilt aria-hidden="true">
        <div class="egg egg--float"></div>
        <div class="browser browser--back"></div>
        <div class="browser browser--front">
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
        <div class="phone">
          <div class="phone__screen">
            <span class="phone__bar"></span>
            <span class="mock__line mock__line--lg"></span>
            <span class="mock__line"></span>
            <span class="phone__btn"></span>
          </div>
        </div>
      </div>
    <?php endif ?>
  </div>
</section>

<?php /* ==================== Selected work (flat-file portfolio) ==================== */ ?>
<?php $homeWork = ($wp = page('work')) ? $wp->children()->listed()->sortBy('featured', 'desc', 'date', 'desc')->limit(3) : null; ?>
<?php if ($homeWork && $homeWork->isNotEmpty()): ?>
<section class="section" aria-labelledby="work-h">
  <div class="container">
    <div class="section__head section__head--row reveal">
      <div>
        <span class="kicker"><?= esc(t('breakfast.work', 'Selected work')) ?></span>
        <h2 class="section__title" id="work-h">Recent websites</h2>
      </div>
      <a class="link-cta" href="<?= esc(url('work')) ?>">See all work <span aria-hidden="true">→</span></a>
    </div>
    <div class="homework">
      <?php foreach ($homeWork as $project) {
          snippet('partials/project-card', ['project' => $project]);
      } ?>
    </div>
  </div>
</section>
<?php endif ?>

<?php /* ============================ Services ============================ */ ?>
<?php $svc = $page->services_cards()->toStructure(); ?>
<?php if ($page->services_enabled()->toBool(true) && $svc->isNotEmpty()): ?>
<section class="section section--alt" aria-labelledby="svc-h">
  <div class="container">
    <div class="section__head reveal">
      <span class="kicker"><?= esc(t('breakfast.services', 'Services')) ?></span>
      <h2 class="section__title" id="svc-h"><?= esc($page->services_heading()) ?></h2>
      <?php if ($page->services_intro()->isNotEmpty()): ?><p class="section__lead"><?= esc($page->services_intro()) ?></p><?php endif ?>
    </div>
    <div class="grid grid--3">
      <?php foreach ($svc as $i => $s): ?>
        <?php $icon = ['websites' => 'globe', 'online shops' => 'cart', 'rescue & ongoing support' => 'wrench'][strtolower((string) $s->title()->value())] ?? 'spark'; ?>
        <a class="scard scard--link reveal" style="--i:<?= $i ?>" href="<?= esc($s->link()->or(url('services'))) ?>">
          <span class="scard__icon" aria-hidden="true"><?php snippet('partials/icon', ['name' => $icon]) ?></span>
          <h3 class="scard__title"><?= esc($s->title()) ?></h3>
          <p class="scard__summary"><?= esc($s->text()) ?></p>
          <?php if ($s->audience()->isNotEmpty()): ?><p class="scard__aud"><?= esc($s->audience()) ?></p><?php endif ?>
          <span class="scard__go" aria-hidden="true">See what's involved →</span>
        </a>
      <?php endforeach ?>
    </div>
  </div>
</section>
<?php endif ?>

<?php /* ========================== Why Breakfast ========================= */ ?>
<?php $why = $page->why_points()->toStructure(); ?>
<?php if ($page->why_enabled()->toBool(true) && $why->isNotEmpty()): ?>
<section class="section" aria-labelledby="why-h">
  <div class="container">
    <div class="section__head reveal">
      <span class="kicker"><?= esc(t('breakfast.why', 'Why Breakfast')) ?></span>
      <h2 class="section__title" id="why-h"><?= esc($page->why_heading()->or('Why Breakfast')) ?></h2>
    </div>
    <div class="why">
      <?php foreach ($why as $i => $p): ?>
        <div class="why__item reveal" style="--i:<?= $i ?>">
          <span class="why__num" aria-hidden="true"><?= str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) ?></span>
          <h3 class="why__title"><?= esc($p->title()) ?></h3>
          <p class="why__text"><?= esc($p->text()) ?></p>
        </div>
      <?php endforeach ?>
    </div>
  </div>
</section>
<?php endif ?>

<?php /* =========================== Diagnostic =========================== */ ?>
<?php $checks = $page->diagnostic_checks()->toStructure(); ?>
<?php if ($page->diagnostic_enabled()->toBool(true) && $checks->isNotEmpty()): ?>
<section class="section section--alt" aria-labelledby="diag-h">
  <div class="container">
    <div class="section__head reveal">
      <span class="kicker"><?= esc(t('breakfast.check', 'A quick check')) ?></span>
      <h2 class="section__title" id="diag-h"><?= esc($page->diagnostic_heading()) ?></h2>
      <?php if ($page->diagnostic_intro()->isNotEmpty()): ?><p class="section__lead"><?= esc($page->diagnostic_intro()) ?></p><?php endif ?>
    </div>
    <div class="grid grid--2 checks">
      <?php foreach ($checks as $i => $c): ?>
        <details class="check reveal" style="--i:<?= $i ?>">
          <summary class="check__q">
            <span class="check__mark" aria-hidden="true"></span>
            <span class="check__qtext"><?= esc($c->question()) ?></span>
          </summary>
          <p class="check__a"><?= esc($c->detail()) ?></p>
        </details>
      <?php endforeach ?>
    </div>
  </div>
</section>
<?php endif ?>

<?php /* ============================= Process ============================ */ ?>
<?php $steps = $page->process_steps()->toStructure(); ?>
<?php if ($page->process_enabled()->toBool(true) && $steps->isNotEmpty()): ?>
<section class="section" id="process" aria-labelledby="proc-h">
  <div class="container">
    <div class="section__head reveal">
      <span class="kicker"><?= esc(t('breakfast.howitgoes', 'How it goes')) ?></span>
      <h2 class="section__title" id="proc-h"><?= esc($page->process_heading()) ?></h2>
    </div>
    <ol class="steps" data-process>
      <span class="steps__line" aria-hidden="true"><span class="steps__line-fill" data-process-fill></span></span>
      <?php $n = 0; foreach ($steps as $step): $n++; ?>
        <li class="steps__item reveal" style="--i:<?= $n - 1 ?>" data-process-item>
          <span class="steps__num" aria-hidden="true"><?= $n ?></span>
          <h3 class="steps__title"><?= esc($step->title()) ?></h3>
          <p class="steps__text"><?= esc($step->text()) ?></p>
        </li>
      <?php endforeach ?>
    </ol>
  </div>
</section>
<?php endif ?>

<?php /* ========================= Client platform ======================== */ ?>
<?php $pts = $page->platform_points()->toStructure(); ?>
<?php if ($page->platform_enabled()->toBool(true) && $page->platform_heading()->isNotEmpty()): ?>
<section class="section section--ink" aria-labelledby="plat-h">
  <div class="container platform">
    <div class="platform__copy reveal">
      <span class="kicker kicker--invert"><?= esc(t('breakfast.platform', 'The client experience')) ?></span>
      <h2 class="section__title" id="plat-h"><?= esc($page->platform_heading()) ?></h2>
      <?php if ($page->platform_text()->isNotEmpty()): ?><p class="section__lead section__lead--invert"><?= esc($page->platform_text()) ?></p><?php endif ?>
      <?php if ($pts->isNotEmpty()): ?>
        <ul class="platform__list">
          <?php foreach ($pts as $pt): ?>
            <li><span class="platform__tick" aria-hidden="true"></span><?= esc($pt->text()) ?></li>
          <?php endforeach ?>
        </ul>
      <?php endif ?>
    </div>
    <div class="platform__visual reveal" aria-hidden="true">
      <div class="portal">
        <div class="portal__top"><span class="portal__egg"></span><span class="portal__title">Your project</span><span class="portal__pill">On track</span></div>
        <div class="portal__bar"><span class="portal__bar-fill"></span></div>
        <div class="portal__rows">
          <span class="portal__row"><i class="portal__ico portal__ico--ok"></i> Design preview ready to review</span>
          <span class="portal__row"><i class="portal__ico"></i> Homepage copy — awaiting your approval</span>
          <span class="portal__row"><i class="portal__ico portal__ico--ok"></i> Proposal accepted</span>
          <span class="portal__row"><i class="portal__ico"></i> Invoice 001 — sent</span>
        </div>
      </div>
    </div>
  </div>
</section>
<?php endif ?>

<?php /* ============================= Journal ============================ */ ?>
<?php if ($page->journal_enabled()->toBool(true) && ($journal = page('journal'))): ?>
  <?php $latest = $journal->children()->listed()->sortBy('date', 'desc')->limit($page->journal_count()->or(3)->toInt()); ?>
  <?php if ($latest->isNotEmpty()): ?>
  <section class="section" aria-labelledby="jrnl-h">
    <div class="container">
      <div class="section__head section__head--row reveal">
        <div>
          <span class="kicker"><?= esc(t('breakfast.journal', 'Journal')) ?></span>
          <h2 class="section__title" id="jrnl-h"><?= esc($page->journal_heading()->or('From the journal')) ?></h2>
        </div>
        <a class="link-cta" href="<?= esc($journal->url()) ?>"><?= esc(t('breakfast.alljournal', 'All writing')) ?> <span aria-hidden="true">→</span></a>
      </div>
      <ul class="jlist">
        <?php foreach ($latest as $article): ?>
          <li class="jlist__item reveal">
            <a class="jlist__link" href="<?= esc($article->url()) ?>">
              <span class="jlist__title"><?= esc($article->title()) ?></span>
              <?php if ($article->summary()->isNotEmpty()): ?><span class="jlist__sum"><?= esc($article->summary()) ?></span><?php endif ?>
              <span class="jlist__go" aria-hidden="true">Read →</span>
            </a>
          </li>
        <?php endforeach ?>
      </ul>
    </div>
  </section>
  <?php endif ?>
<?php endif ?>

<?php /* ============================ Final CTA =========================== */ ?>
<?php if ($page->final_cta_enabled()->toBool(true)): ?>
<section class="section">
  <div class="container">
    <div class="cta-band cta-band--rich reveal">
      <span class="cta-band__egg" aria-hidden="true"></span>
      <h2 class="cta-band__title"><?= esc($page->final_cta_heading()->or($site->cta_heading())) ?></h2>
      <p class="cta-band__text"><?= esc($page->final_cta_text()->or($site->cta_text())) ?></p>
      <div class="cta-band__actions">
        <a class="btn btn--primary btn--lg" data-track="cta_click" data-track-label="final-primary" href="<?= esc(url('start-a-project')) ?>"><?= esc(t('breakfast.startproject', 'Start a project')) ?></a>
        <a class="btn btn--ghost btn--lg" href="<?= esc(url('contact')) ?>"><?= esc(t('breakfast.quickq', 'Ask a quick question')) ?></a>
      </div>
    </div>
  </div>
</section>
<?php endif ?>

<?php snippet('layouts/footer') ?>
