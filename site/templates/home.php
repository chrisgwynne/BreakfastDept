<?php

use Breakfast\Platform\Teletext\Registry;

$softkeys = [
    ['label' => 'Start',    'sub' => 'P101', 'href' => url('start-a-project')],
    ['label' => 'Our Work', 'sub' => 'P200', 'href' => page('work') ? page('work')->url() : url('work')],
    ['label' => 'Services', 'sub' => 'P300', 'href' => page('services') ? page('services')->url() : url('services')],
    ['label' => 'Contact',  'sub' => 'P700', 'href' => url('contact')],
];

snippet('layouts/header');
?>

<?php /* ============================== P100 — index ============================== */ ?>
<section class="section" aria-labelledby="hero-h">
  <div class="container">
    <?php if ($page->hero_eyebrow()->isNotEmpty()): ?>
      <p class="eyebrow"><span class="eyebrow__dot" aria-hidden="true"></span> <?= esc($page->hero_eyebrow()) ?></p>
    <?php endif ?>
    <h1 class="hero__title" id="hero-h" style="margin-top:var(--s-4)">
      <?= esc($page->hero_headline()->or($site->title())) ?>
      <?php if ($page->hero_highlight()->isNotEmpty()): ?> <span class="hl hl--yellow"><?= esc($page->hero_highlight()) ?></span><?php endif ?>
    </h1>
    <?php if ($page->hero_text()->isNotEmpty()): ?><p class="hero__sub"><?= esc($page->hero_text()) ?></p><?php endif ?>

    <div class="hero__actions" style="display:flex;flex-wrap:wrap;gap:var(--s-4);margin-top:var(--s-8)">
      <?php if ($page->hero_primary_label()->isNotEmpty()): ?>
        <a class="btn btn--primary btn--lg" data-track="cta_click" data-track-label="hero-primary" href="<?= esc($page->hero_primary_link()->or(url('start-a-project'))) ?>"><?= esc($page->hero_primary_label()) ?></a>
      <?php endif ?>
      <?php if ($page->hero_secondary_label()->isNotEmpty()): ?>
        <a class="btn btn--ghost btn--lg" href="<?= esc($page->hero_secondary_link()->or('/about#how')) ?>"><?= esc($page->hero_secondary_label()) ?></a>
      <?php endif ?>
    </div>
    <?php if ($page->hero_aside_label()->isNotEmpty()): ?>
      <p style="margin-top:var(--s-4)"><a class="link-cta" href="<?= esc($page->hero_aside_link()->or(url('contact'))) ?>"><?= esc($page->hero_aside_label()) ?></a></p>
    <?php endif ?>

    <?php /* ---------- The index: every P100 needs one ---------- */ ?>
    <div style="margin-top:var(--s-12)">
      <p class="kicker">Need a website? Start at 101.</p>
      <?php snippet('teletext/page-index', ['items' => [
          ['number' => '101', 'label' => 'Start a Project', 'href' => url('start-a-project')],
          ['number' => '200', 'label' => 'Our Work',        'href' => page('work') ? page('work')->url() : url('work')],
          ['number' => '300', 'label' => 'Services',        'href' => page('services') ? page('services')->url() : url('services')],
          ['number' => '400', 'label' => 'How It Works',    'href' => '/about#how'],
          ['number' => '500', 'label' => 'About Breakfast', 'href' => page('about') ? page('about')->url() : url('about')],
          ['number' => '600', 'label' => 'Journal',         'href' => page('journal') ? page('journal')->url() : url('journal')],
          ['number' => '700', 'label' => 'Contact',         'href' => url('contact')],
      ]]) ?>
    </div>

    <?php /* ---------- Current status: real, editable, never invented ---------- */ ?>
    <?php if ($site->availability_enabled()->toBool(false) && $page->hero_availability()->isNotEmpty()): ?>
      <div style="margin-top:var(--s-12);max-width:32rem">
        <p class="kicker">Current status</p>
        <?php snippet('teletext/status', ['rows' => [
            ['label' => 'Breakfast', 'value' => $page->hero_availability()->value(), 'dot' => 'green'],
        ]]) ?>
      </div>
    <?php endif ?>
  </div>
</section>

<?php /* ==================== P200 — selected work ==================== */ ?>
<?php $homeWork = ($wp = page('work')) ? $wp->children()->listed()->sortBy('featured', 'desc', 'date', 'desc')->limit(3) : null; ?>
<?php if ($homeWork && $homeWork->isNotEmpty()): ?>
<section class="section" aria-labelledby="work-h">
  <div class="container">
    <div class="section__head section__head--row">
      <div>
        <span class="kicker">P200 · <?= esc(t('breakfast.work', 'Selected work')) ?></span>
        <h2 class="section__title" id="work-h">Recent websites</h2>
      </div>
      <a class="link-cta" href="<?= esc(url('work')) ?>">See all work</a>
    </div>
    <div class="grid grid--3">
      <?php foreach ($homeWork as $project) {
          snippet('partials/project-card', ['project' => $project]);
      } ?>
    </div>
  </div>
</section>
<?php endif ?>

<?php /* ============================ P300 — services ============================ */ ?>
<?php $svc = $page->services_cards()->toStructure(); ?>
<?php if ($page->services_enabled()->toBool(true) && $svc->isNotEmpty()): ?>
<section class="section section--alt" aria-labelledby="svc-h">
  <div class="container">
    <div class="section__head">
      <span class="kicker">P300 · <?= esc(t('breakfast.services', 'Services')) ?></span>
      <h2 class="section__title" id="svc-h"><?= esc($page->services_heading()) ?></h2>
      <?php if ($page->services_intro()->isNotEmpty()): ?><p class="section__lead"><?= esc($page->services_intro()) ?></p><?php endif ?>
    </div>
    <div class="grid grid--3">
      <?php foreach ($svc as $s): ?>
        <?php $icon = ['website design & build' => 'globe', 'shopify online shop' => 'cart', 'website rescue & care' => 'wrench'][strtolower((string) $s->title()->value())] ?? 'spark'; ?>
        <a class="scard scard--link" href="<?= esc($s->link()->or(url('services'))) ?>">
          <span class="scard__icon" aria-hidden="true"><?php snippet('partials/icon', ['name' => $icon]) ?></span>
          <h3 class="scard__title"><?= esc($s->title()) ?></h3>
          <p class="scard__summary"><?= esc($s->text()) ?></p>
          <?php if ($s->audience()->isNotEmpty()): ?><p class="scard__aud"><?= esc($s->audience()) ?></p><?php endif ?>
          <span class="scard__go" aria-hidden="true">See this option</span>
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
    <div class="section__head">
      <span class="kicker"><?= esc(t('breakfast.why', 'Why Breakfast')) ?></span>
      <h2 class="section__title" id="why-h"><?= esc($page->why_heading()->or('Why Breakfast')) ?></h2>
    </div>
    <div class="grid grid--3">
      <?php foreach ($why as $i => $p): ?>
        <div class="card">
          <p class="kicker"><?= str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) ?></p>
          <h3 class="scard__title" style="margin-top:var(--s-2)"><?= esc($p->title()) ?></h3>
          <p class="scard__summary" style="margin-top:var(--s-2)"><?= esc($p->text()) ?></p>
        </div>
      <?php endforeach ?>
    </div>
  </div>
</section>
<?php endif ?>

<?php /* =========================== Diagnostic (P110 teaser) =========================== */ ?>
<?php $checks = $page->diagnostic_checks()->toStructure(); ?>
<?php if ($page->diagnostic_enabled()->toBool(true) && $checks->isNotEmpty()): ?>
<section class="section section--alt" aria-labelledby="diag-h">
  <div class="container">
    <div class="section__head">
      <span class="kicker">P110 · <?= esc(t('breakfast.check', 'A quick check')) ?></span>
      <h2 class="section__title" id="diag-h"><?= esc($page->diagnostic_heading()) ?></h2>
      <?php if ($page->diagnostic_intro()->isNotEmpty()): ?><p class="section__lead"><?= esc($page->diagnostic_intro()) ?></p><?php endif ?>
    </div>
    <div>
      <?php foreach ($checks as $c): ?>
        <details class="faq__item">
          <summary class="faq__q"><?= esc($c->question()) ?></summary>
          <p class="faq__a"><?= esc($c->detail()) ?></p>
        </details>
      <?php endforeach ?>
    </div>
    <div class="card" style="margin-top:var(--s-6);display:flex;align-items:center;justify-content:space-between;gap:var(--s-6);flex-wrap:wrap">
      <div>
        <h3 class="scard__title">Want a second opinion on your current site?</h3>
        <p class="scard__summary" style="margin-top:var(--s-2)">Send over the site you have now for an informal first look.</p>
      </div>
      <a class="btn btn--secondary" data-track="cta_click" data-track-label="home-diagnostic-website-review" href="<?= esc(url('website-review')) ?>#form">Get a website review</a>
    </div>
  </div>
</section>
<?php endif ?>

<?php /* ============================= P400 — process ============================ */ ?>
<?php $steps = $page->process_steps()->toStructure(); ?>
<?php if ($page->process_enabled()->toBool(true) && $steps->isNotEmpty()): ?>
<section class="section" id="how" aria-labelledby="proc-h">
  <div class="container">
    <div class="section__head">
      <span class="kicker">P400 · <?= esc(t('breakfast.howitgoes', 'How it goes')) ?></span>
      <h2 class="section__title" id="proc-h"><?= esc($page->process_heading()) ?></h2>
    </div>
    <ol class="timeline">
      <?php $n = 0; foreach ($steps as $step): $n++; ?>
        <li class="timeline__item">
          <span class="timeline__num" aria-hidden="true"><?= $n ?></span>
          <h3 class="scard__title"><?= esc($step->title()) ?></h3>
          <p class="scard__summary"><?= esc($step->text()) ?></p>
        </li>
      <?php endforeach ?>
    </ol>
  </div>
</section>
<?php endif ?>

<?php /* ========================= Client experience (P410) ======================== */ ?>
<?php $pts = $page->platform_points()->toStructure(); ?>
<?php if ($page->platform_enabled()->toBool(true) && $page->platform_heading()->isNotEmpty()): ?>
<section class="section section--ink" aria-labelledby="plat-h">
  <div class="container">
    <span class="kicker kicker--invert">P410 · <?= esc(t('breakfast.platform', 'The client experience')) ?></span>
    <h2 class="section__title" id="plat-h"><?= esc($page->platform_heading()) ?></h2>
    <?php if ($page->platform_text()->isNotEmpty()): ?><p class="section__lead section__lead--invert"><?= esc($page->platform_text()) ?></p><?php endif ?>
    <?php if ($pts->isNotEmpty()): ?>
      <div style="margin-top:var(--s-8);max-width:36rem">
        <?php snippet('teletext/status', ['rows' => array_map(
            static fn ($pt) => ['label' => $pt->text()->value(), 'value' => 'READY', 'dot' => 'green'],
            iterator_to_array($pts)
        )]) ?>
      </div>
    <?php endif ?>
  </div>
</section>
<?php endif ?>

<?php /* ============================= P600 — journal ============================ */ ?>
<?php if ($page->journal_enabled()->toBool(true) && ($journal = page('journal'))): ?>
  <?php $latest = $journal->children()->listed()->sortBy('date', 'desc')->limit($page->journal_count()->or(3)->toInt()); ?>
  <?php if ($latest->isNotEmpty()): ?>
  <section class="section" aria-labelledby="jrnl-h">
    <div class="container">
      <div class="section__head section__head--row">
        <div>
          <span class="kicker">P600 · <?= esc(t('breakfast.journal', 'Journal')) ?></span>
          <h2 class="section__title" id="jrnl-h"><?= esc($page->journal_heading()->or('From the journal')) ?></h2>
        </div>
        <a class="link-cta" href="<?= esc($journal->url()) ?>"><?= esc(t('breakfast.alljournal', 'All writing')) ?></a>
      </div>
      <nav class="tt-index" aria-label="Latest journal entries">
        <?php $jn = 600; foreach ($latest as $article): $jn++; ?>
          <a class="tt-index__row" href="<?= esc($article->url()) ?>">
            <span class="tt-index__num"><?= $jn ?></span>
            <span class="tt-index__label"><?= esc($article->title()) ?></span>
            <span class="tt-index__status">NEW</span>
          </a>
        <?php endforeach ?>
      </nav>
    </div>
  </section>
  <?php endif ?>
<?php endif ?>

<?php /* ============================ Final CTA (P101) =========================== */ ?>
<?php if ($page->final_cta_enabled()->toBool(true)): ?>
<section class="section">
  <div class="container">
    <div class="cta-band">
      <p class="kicker" style="color:var(--tt-black)">P101</p>
      <h2 class="cta-band__title"><?= esc($page->final_cta_heading()->or($site->cta_heading())) ?></h2>
      <p class="cta-band__text"><?= esc($page->final_cta_text()->or($site->cta_text())) ?></p>
      <div class="cta-band__actions">
        <a class="btn btn--primary btn--lg" data-track="cta_click" data-track-label="final-primary" href="<?= esc(url('start-a-project')) ?>">101 — Start a Project</a>
        <a class="btn btn--ghost btn--lg" href="<?= esc(url('contact')) ?>"><?= esc(t('breakfast.quickq', 'Ask a quick question')) ?></a>
      </div>
    </div>
  </div>
</section>
<?php endif ?>

<?php snippet('layouts/footer', ['softkeys' => $softkeys]) ?>
