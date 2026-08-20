<?php

use Breakfast\Platform\Teletext\Registry;

snippet('layouts/header');

$projects = $page->children()->listed()->sortBy('featured', 'desc', 'date', 'desc');
// Build filter set from services + industries.
$services = [];
$industries = [];
foreach ($projects as $p) {
    foreach ($p->services()->toPages() as $s) { $services[$s->slug()] = $s->title()->value(); }
    foreach ($p->industries()->split() as $ind) { $industries[\Kirby\Toolkit\Str::slug($ind)] = $ind; }
}
?>

<section class="section">
  <div class="container">
    <?php snippet('partials/breadcrumbs') ?>
    <?php snippet('teletext/bar', ['number' => 'P200', 'title' => $page->title()->value(), 'sub' => $projects->count() . ' listed', 'as' => 'h1']) ?>
    <?php if ($page->intro()->isNotEmpty()): ?><p class="section__lead"><?= esc($page->intro()) ?></p><?php endif ?>

    <?php if ($page->filters_enabled()->toBool(true) && ($services || $industries)): ?>
    <div class="filters" data-filters role="group" aria-label="<?= esc(t('breakfast.filter', 'Filter work')) ?>" style="margin-top:var(--s-4)">
      <button class="filter" data-filter="all" aria-pressed="true"><?= esc(t('breakfast.all', 'All')) ?></button>
      <?php foreach ($services as $slug => $label): ?>
        <button class="filter" data-filter="<?= esc($slug) ?>" aria-pressed="false"><?= esc($label) ?></button>
      <?php endforeach ?>
      <?php foreach ($industries as $slug => $label): ?>
        <button class="filter" data-filter="<?= esc($slug) ?>" aria-pressed="false"><?= esc($label) ?></button>
      <?php endforeach ?>
      <span class="sr-only" aria-live="polite" data-filter-count></span>
    </div>
    <?php endif ?>

    <?php if ($projects->isEmpty()): ?>
      <p class="form-status"><?= esc(t('breakfast.crm.empty', 'Nothing here yet.')) ?></p>
    <?php else: ?>
      <div class="tt-list" style="margin-top:var(--s-4)">
        <?php foreach ($projects as $project): ?>
          <?php
            $num = Registry::numberFor($project, $site);
            $thumb = $project->card_image()->toFile() ?? $project->hero_image()->toFile();
            $isConcept = $project->project_status()->value() === 'concept';
            $tags = [];
            foreach ($project->services()->toPages() as $s) { $tags[] = $s->slug(); }
            foreach ($project->industries()->split() as $ind) { $tags[] = \Kirby\Toolkit\Str::slug($ind); }
          ?>
          <a class="tt-list__row" href="<?= esc($project->url()) ?>" data-filter-item data-tags="<?= esc(implode(' ', $tags)) ?>">
            <span class="tt-list__num"><?= $num !== null ? esc($num) : '' ?></span>
            <span class="tt-list__body">
              <span class="tt-list__title"><?= esc($project->title()) ?></span>
              <span class="tt-list__meta"><?= esc($project->client()->or($project->services()->toPages()->first()?->title())) ?><?php if ($project->industries()->isNotEmpty()): ?> · <?= esc($project->industries()->split()[0] ?? '') ?><?php endif ?></span>
              <span class="tt-list__status"><?= $isConcept ? 'CONCEPT' : 'LIVE' ?></span>
            </span>
            <?php if ($thumb): ?><?= $thumb->crop(160, 100)->html(['alt' => '', 'loading' => 'lazy', 'class' => 'tt-list__thumb']) ?><?php endif ?>
          </a>
        <?php endforeach ?>
      </div>
    <?php endif ?>
  </div>
</section>

<section class="section"><div class="container"><?php snippet('partials/cta-band') ?></div></section>
<?php snippet('layouts/footer', ['softkeys' => [
    ['label' => 'Back',     'sub' => 'P100', 'href' => url('/')],
    ['label' => 'Services', 'sub' => 'P300', 'href' => page('services') ? page('services')->url() : url('services')],
    ['label' => 'Start',    'sub' => 'P101', 'href' => url('start-a-project')],
    ['label' => 'Contact',  'sub' => 'P700', 'href' => url('contact')],
]]) ?>
