<?php

use Breakfast\Platform\Teletext\Registry;

snippet('layouts/header');
$ttNumber = Registry::numberFor($page, $site);
?>
<article class="section">
  <div class="container">
    <?php snippet('partials/breadcrumbs') ?>
    <?php snippet('teletext/bar', ['number' => $ttNumber !== null ? 'P' . $ttNumber : '', 'title' => esc($page->heading()->or($page->title())), 'as' => 'h1']) ?>
    <?php if ($page->summary()->isNotEmpty()): ?><p class="section__lead" style="max-width:52rem"><?= esc($page->summary()) ?></p><?php endif ?>

    <?php if ($page->introduction()->isNotEmpty()): ?>
      <div class="blocks container--prose" style="margin-inline:0"><?= $page->introduction()->toBlocks() ?></div>
    <?php endif ?>

    <div class="grid grid--2" style="margin-top:var(--s-12)">
      <?php if ($page->suitable_for()->isNotEmpty()): ?>
        <div class="tt-box"><h2 class="scard__title"><?= esc(t('breakfast.service.suitable', 'Who it’s for')) ?></h2><div class="prose"><?= $page->suitable_for()->kt() ?></div></div>
      <?php endif ?>
      <?php if ($page->problems()->toStructure()->isNotEmpty()): ?>
        <div class="tt-box"><h2 class="scard__title"><?= esc(t('breakfast.service.problems', 'Problems it solves')) ?></h2>
          <ul class="prose" style="list-style:disc;padding-left:var(--s-6)"><?php foreach ($page->problems()->toStructure() as $p): ?><li><?= esc($p->text()) ?></li><?php endforeach ?></ul>
        </div>
      <?php endif ?>
    </div>

    <?php if ($page->deliverables()->toStructure()->isNotEmpty()): ?>
      <div class="section__head" style="margin-top:var(--s-12)"><h2 class="section__title" style="font-size:1.8rem"><?= esc(t('breakfast.service.deliverables', 'What you get')) ?></h2></div>
      <div class="grid grid--3">
        <?php foreach ($page->deliverables()->toStructure() as $d): ?><div class="tt-box"><p><?= esc($d->text()) ?></p></div><?php endforeach ?>
      </div>
    <?php endif ?>

    <?php if ($page->process()->toStructure()->isNotEmpty()): ?>
      <div class="section__head" style="margin-top:var(--s-12)"><h2 class="section__title" style="font-size:1.8rem"><?= esc(t('breakfast.howitgoes', 'How it goes')) ?></h2></div>
      <ol class="timeline">
        <?php $n = 0; foreach ($page->process()->toStructure() as $step): $n++; ?>
          <li class="timeline__item"><span class="timeline__num" aria-hidden="true"><?= $n ?></span><h3><?= esc($step->title()) ?></h3><p class="pcard__summary"><?= esc($step->text()) ?></p></li>
        <?php endforeach ?>
      </ol>
    <?php endif ?>

    <div class="grid grid--2" style="margin-top:var(--s-12)">
      <?php if ($page->pricing_guidance()->isNotEmpty()): ?>
        <div class="tt-box"><h2 class="scard__title"><?= esc(t('breakfast.service.pricing', 'What it costs')) ?></h2><div class="prose"><?= $page->pricing_guidance()->kt() ?></div></div>
      <?php endif ?>
      <?php if ($page->timescale()->isNotEmpty()): ?>
        <div class="tt-box"><h2 class="scard__title"><?= esc(t('breakfast.service.timescale', 'How long it takes')) ?></h2><p><?= esc($page->timescale()) ?></p></div>
      <?php endif ?>
    </div>

    <?php if ($page->faqs()->toStructure()->isNotEmpty()): ?>
      <div class="section__head" style="margin-top:var(--s-12)"><h2 class="section__title" style="font-size:1.8rem"><?= esc(t('breakfast.faq', 'Common questions')) ?></h2></div>
      <?php foreach ($page->faqs()->toStructure() as $faq): ?>
        <details class="faq__item"><summary class="faq__q"><?= esc($faq->question()) ?></summary><div class="faq__a"><?= $faq->answer()->kt() ?></div></details>
      <?php endforeach ?>
    <?php endif ?>

    <?php /* Flat-file portfolio work tagged with this service (project 'services' field). */ ?>
    <?php
      $serviceWork = ($wp = page('work'))
          ? $wp->children()->listed()->filterBy('services', $page->uuid()->toString(), ',')->sortBy('featured', 'desc', 'date', 'desc')->limit(3)
          : null;
    ?>
    <?php if ($serviceWork && $serviceWork->isNotEmpty()): ?>
      <div class="section__head" style="margin-top:var(--s-16)"><h2 class="section__title" style="font-size:1.8rem"><?= esc(t('breakfast.service.work', 'Work using this service')) ?></h2></div>
      <div class="tt-list">
        <?php foreach ($serviceWork as $project): ?>
          <?php $wNum = Registry::numberFor($project, $site); ?>
          <a class="tt-list__row" href="<?= esc($project->url()) ?>">
            <span class="tt-list__num"><?= $wNum !== null ? esc($wNum) : '' ?></span>
            <span class="tt-list__body">
              <span class="tt-list__title"><?= esc($project->title()) ?></span>
              <span class="tt-list__meta"><?= esc($project->client()) ?></span>
            </span>
          </a>
        <?php endforeach ?>
      </div>
    <?php endif ?>
  </div>
</article>

<section class="section"><div class="container">
  <?php snippet('partials/cta-band', ['heading' => $page->cta_heading()->or($site->cta_heading()), 'text' => $page->cta_text()->or($site->cta_text())]) ?>
</div></section>
<?php snippet('layouts/footer', ['softkeys' => [
    ['label' => 'Back',     'sub' => 'P300', 'href' => page('services') ? page('services')->url() : url('services')],
    ['label' => 'Ask',      'sub' => 'P101', 'href' => url('start-a-project') . '?service=' . rawurlencode($page->slug()) . '#form'],
    ['label' => 'Our Work', 'sub' => 'P200', 'href' => page('work') ? page('work')->url() : url('work')],
    ['label' => 'Contact',  'sub' => 'P700', 'href' => url('contact')],
]]) ?>
