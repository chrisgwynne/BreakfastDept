<?php snippet('layouts/header') ?>

<section class="section">
  <div class="container">
    <?php snippet('partials/breadcrumbs') ?>
    <div class="section__head">
      <span class="kicker">P200 · <?= esc(t('breakfast.thework', 'The work')) ?></span>
      <h1 class="section__title"><?= esc($page->title()) ?></h1>
      <?php if ($page->intro()->isNotEmpty()): ?><p class="section__lead"><?= esc($page->intro()) ?></p><?php endif ?>
    </div>

    <?php
      $projects = $page->children()->listed()->sortBy('featured', 'desc', 'date', 'desc');
      // Build filter set from services + industries.
      $services = [];
      $industries = [];
      foreach ($projects as $p) {
        foreach ($p->services()->toPages() as $s) { $services[$s->slug()] = $s->title()->value(); }
        foreach ($p->industries()->split() as $ind) { $industries[\Kirby\Toolkit\Str::slug($ind)] = $ind; }
      }
    ?>

    <?php if ($page->filters_enabled()->toBool(true) && ($services || $industries)): ?>
    <div class="filters" data-filters role="group" aria-label="<?= esc(t('breakfast.filter', 'Filter work')) ?>">
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
      <div class="grid grid--3">
        <?php foreach ($projects as $project) snippet('partials/project-card', ['project' => $project]) ?>
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
