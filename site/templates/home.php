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

<?php /* ============================== P100 — the index screen ============================== */ ?>
<section class="section" aria-labelledby="hero-h" style="padding-block:var(--s-8) var(--s-6)">
  <div class="container">
    <div class="tt-split">
      <div class="tt-split__main">
        <p class="tt-tagline"><?= esc($page->hero_eyebrow()->or($site->title())) ?></p>
        <h1 class="tt-h1"><?= esc($site->title()->or('Breakfast')) ?></h1>
        <p class="tt-h1-copy" id="hero-h">
          <?= esc($page->hero_headline()->or($site->title())) ?>
          <?php if ($page->hero_highlight()->isNotEmpty()): ?> <span class="tt-accent"><?= esc($page->hero_highlight()) ?></span><?php endif ?>
        </p>
        <?php if ($page->hero_text()->isNotEmpty()): ?><p class="tt-intro"><?= esc($page->hero_text()) ?></p><?php endif ?>

        <hr class="tt-rule">

        <?php snippet('teletext/page-index', ['items' => [
            ['number' => '101', 'label' => 'Start a Project', 'href' => url('start-a-project')],
            ['number' => '200', 'label' => 'Our Work',        'href' => page('work') ? page('work')->url() : url('work')],
            ['number' => '300', 'label' => 'Services',        'href' => page('services') ? page('services')->url() : url('services')],
            ['number' => '400', 'label' => 'How It Works',    'href' => '/about#how'],
            ['number' => '500', 'label' => 'About Breakfast', 'href' => page('about') ? page('about')->url() : url('about')],
            ['number' => '600', 'label' => 'Journal',         'href' => page('journal') ? page('journal')->url() : url('journal')],
            ['number' => '700', 'label' => 'Contact',         'href' => url('contact')],
        ]]) ?>

        <?php if ($page->hero_aside_label()->isNotEmpty()): ?>
          <p style="margin-top:var(--s-4)"><a class="link-cta" href="<?= esc($page->hero_aside_link()->or(url('contact'))) ?>"><?= esc($page->hero_aside_label()) ?></a></p>
        <?php endif ?>
      </div>

      <div class="tt-split__aside">
        <?php snippet('teletext/pixel-art', ['motif' => 'sunrise', 'size' => 'lg']) ?>

        <?php if ($site->availability_enabled()->toBool(false) && $page->hero_availability()->isNotEmpty()): ?>
          <div class="tt-box">
            <p class="tt-box__title">Current status</p>
            <?php snippet('teletext/status', ['rows' => [
                ['label' => 'Breakfast', 'value' => $page->hero_availability()->value(), 'dot' => 'green'],
            ]]) ?>
          </div>
        <?php endif ?>

        <?php $latestOne = ($jp = page('journal')) ? $jp->children()->listed()->sortBy('date', 'desc')->first() : null; ?>
        <?php if ($latestOne): ?>
          <?php $latestNum = Registry::numberFor($latestOne, $site); ?>
          <a class="tt-box tt-box--blue" href="<?= esc($latestOne->url()) ?>" style="display:block">
            <p class="tt-box__title">Latest <?= $latestNum !== null ? '— P' . esc($latestNum) : '' ?></p>
            <p class="tt-box__text"><?= esc($latestOne->title()) ?></p>
            <span class="tt-box__link">Read</span>
          </a>
        <?php endif ?>
      </div>
    </div>
  </div>
</section>

<?php /* ==================== P200 — selected work ==================== */ ?>
<?php $homeWork = ($wp = page('work')) ? $wp->children()->listed()->sortBy('featured', 'desc', 'date', 'desc')->limit(4) : null; ?>
<?php if ($homeWork && $homeWork->isNotEmpty()): ?>
<section class="section" aria-labelledby="work-h">
  <div class="container">
    <?php snippet('teletext/bar', ['number' => 'P200', 'title' => t('breakfast.work', 'Selected work'), 'sub' => 'See all →']) ?>
    <div class="tt-list">
      <?php foreach ($homeWork as $project): ?>
        <?php
          $num = Registry::numberFor($project, $site);
          $thumb = $project->card_image()->toFile() ?? $project->hero_image()->toFile();
          $isConcept = $project->project_status()->value() === 'concept';
        ?>
        <a class="tt-list__row" href="<?= esc($project->url()) ?>">
          <span class="tt-list__num"><?= $num !== null ? esc($num) : '' ?></span>
          <span class="tt-list__body">
            <span class="tt-list__title"><?= esc($project->title()) ?></span>
            <span class="tt-list__meta"><?= esc($project->client()->or($project->services()->toPages()->first()?->title())) ?></span>
            <span class="tt-list__status"><?= $isConcept ? 'CONCEPT' : 'LIVE' ?></span>
          </span>
          <?php if ($thumb): ?><?= $thumb->crop(160, 100)->html(['alt' => '', 'loading' => 'lazy', 'class' => 'tt-list__thumb']) ?><?php endif ?>
        </a>
      <?php endforeach ?>
    </div>
    <p style="margin-top:var(--s-4)"><a class="link-cta" href="<?= esc(url('work')) ?>">See all work</a></p>
  </div>
</section>
<?php endif ?>

<?php /* ============================ P300 — services ============================ */ ?>
<?php $svc = $page->services_cards()->toStructure(); ?>
<?php if ($page->services_enabled()->toBool(true) && $svc->isNotEmpty()): ?>
<section class="section" aria-labelledby="svc-h">
  <div class="container">
    <?php snippet('teletext/bar', ['number' => 'P300', 'title' => esc($page->services_heading()->or('Services'))]) ?>
    <?php if ($page->services_intro()->isNotEmpty()): ?><p class="section__lead"><?= esc($page->services_intro()) ?></p><?php endif ?>
    <div class="tt-list" style="margin-top:var(--s-4)">
      <?php $sn = 300; foreach ($svc as $s): $sn++; ?>
        <a class="tt-list__row" href="<?= esc($s->link()->or(url('services'))) ?>">
          <span class="tt-list__num"><?= $sn ?></span>
          <span class="tt-list__body">
            <span class="tt-list__title"><?= esc($s->title()) ?></span>
            <span class="tt-list__meta"><?= esc($s->audience()) ?></span>
          </span>
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
    <?php snippet('teletext/bar', ['number' => '', 'title' => esc($page->why_heading()->or('Why Breakfast')), 'tight' => true]) ?>
    <ol class="timeline">
      <?php foreach ($why as $i => $p): ?>
        <li class="timeline__item">
          <span class="timeline__num" aria-hidden="true"><?= str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) ?></span>
          <h3 class="scard__title"><?= esc($p->title()) ?></h3>
          <p class="scard__summary"><?= esc($p->text()) ?></p>
        </li>
      <?php endforeach ?>
    </ol>
  </div>
</section>
<?php endif ?>

<?php /* =========================== Diagnostic (P110 teaser) =========================== */ ?>
<?php $checks = $page->diagnostic_checks()->toStructure(); ?>
<?php if ($page->diagnostic_enabled()->toBool(true) && $checks->isNotEmpty()): ?>
<section class="section" aria-labelledby="diag-h">
  <div class="container">
    <?php snippet('teletext/bar', ['number' => 'P110', 'title' => esc($page->diagnostic_heading()->or('A quick check'))]) ?>
    <?php if ($page->diagnostic_intro()->isNotEmpty()): ?><p class="section__lead"><?= esc($page->diagnostic_intro()) ?></p><?php endif ?>
    <div style="margin-top:var(--s-4)">
      <?php foreach ($checks as $c): ?>
        <details class="faq__item">
          <summary class="faq__q"><?= esc($c->question()) ?></summary>
          <p class="faq__a"><?= esc($c->detail()) ?></p>
        </details>
      <?php endforeach ?>
    </div>
    <div class="tt-box" style="margin-top:var(--s-6)">
      <p class="tt-box__title">Want a second opinion on your current site?</p>
      <p class="tt-box__text">Send over the site you have now for an informal first look.</p>
      <a class="tt-box__link" data-track="cta_click" data-track-label="home-diagnostic-website-review" href="<?= esc(url('website-review')) ?>#form">Get a website review</a>
    </div>
  </div>
</section>
<?php endif ?>

<?php /* ============================= P400 — process ============================ */ ?>
<?php $steps = $page->process_steps()->toStructure(); ?>
<?php if ($page->process_enabled()->toBool(true) && $steps->isNotEmpty()): ?>
<section class="section" id="how" aria-labelledby="proc-h">
  <div class="container">
    <?php snippet('teletext/bar', ['number' => 'P400', 'title' => esc($page->process_heading()->or('How it goes'))]) ?>
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
<section class="section" aria-labelledby="plat-h">
  <div class="container">
    <?php snippet('teletext/bar', ['number' => 'P410', 'title' => esc($page->platform_heading())]) ?>
    <?php if ($page->platform_text()->isNotEmpty()): ?><p class="section__lead"><?= esc($page->platform_text()) ?></p><?php endif ?>
    <?php if ($pts->isNotEmpty()): ?>
      <div style="margin-top:var(--s-6);max-width:36rem">
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
      <?php snippet('teletext/bar', ['number' => 'P600', 'title' => esc($page->journal_heading()->or('Journal')), 'sub' => 'All writing →']) ?>
      <nav class="tt-index" aria-label="Latest journal entries">
        <?php foreach ($latest as $article): ?>
          <?php $an = Registry::numberFor($article, $site); ?>
          <a class="tt-index__row" href="<?= esc($article->url()) ?>">
            <span class="tt-index__num"><?= $an !== null ? esc($an) : '' ?></span>
            <span class="tt-index__label"><?= esc($article->title()) ?></span>
            <span class="tt-index__status"><?= esc($article->date()->toDate('j M')) ?></span>
          </a>
        <?php endforeach ?>
      </nav>
    </div>
  </section>
  <?php endif ?>
<?php endif ?>

<?php /* ============================ Final CTA (P101) =========================== */ ?>
<?php if ($page->final_cta_enabled()->toBool(true)): ?>
<section class="section" style="border-bottom:0">
  <div class="container">
    <?php snippet('teletext/bar', ['number' => 'P101', 'title' => 'Start a Project']) ?>
    <div class="tt-box tt-box--blue">
      <p class="tt-box__title"><?= esc($page->final_cta_heading()->or($site->cta_heading())) ?></p>
      <p class="tt-box__text"><?= esc($page->final_cta_text()->or($site->cta_text())) ?></p>
      <p style="margin-top:var(--s-4);display:flex;gap:var(--s-6);flex-wrap:wrap">
        <a class="tt-box__link" data-track="cta_click" data-track-label="final-primary" href="<?= esc(url('start-a-project')) ?>">101 — Start a project</a>
        <a class="tt-box__link" href="<?= esc(url('contact')) ?>"><?= esc(t('breakfast.quickq', 'Ask a quick question')) ?></a>
      </p>
    </div>
  </div>
</section>
<?php endif ?>

<?php snippet('layouts/footer', ['softkeys' => $softkeys]) ?>
