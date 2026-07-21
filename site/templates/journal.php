<?php snippet('layouts/header') ?>
<section class="section">
  <div class="container">
    <?php snippet('partials/breadcrumbs') ?>
    <div class="section__head">
      <span class="kicker"><?= esc(t('breakfast.journal', 'Journal')) ?></span>
      <h1 class="section__title"><?= esc($page->title()) ?></h1>
      <?php if ($page->intro()->isNotEmpty()): ?><p class="section__lead"><?= esc($page->intro()) ?></p><?php endif ?>
    </div>

    <?php
      $articles = $page->children()->listed()->sortBy('date', 'desc');
      $featured = $page->featured_article()->toPage();
      // Category filters
      $cats = [];
      foreach ($articles as $a) { foreach ($a->categories()->split() as $c) { $cats[\Kirby\Toolkit\Str::slug($c)] = $c; } }
    ?>

    <?php if ($featured && $featured->isListed()): ?>
      <div style="margin-bottom:var(--s-12)">
        <p class="kicker"><?= esc(t('breakfast.featured', 'Featured')) ?></p>
        <?php snippet('partials/article-card', ['article' => $featured]) ?>
      </div>
    <?php endif ?>

    <?php if ($cats): ?>
    <div class="filters" data-filters role="group" aria-label="<?= esc(t('breakfast.filter', 'Filter articles')) ?>">
      <button class="filter" data-filter="all" aria-pressed="true"><?= esc(t('breakfast.all', 'All')) ?></button>
      <?php foreach ($cats as $slug => $label): ?><button class="filter" data-filter="<?= esc($slug) ?>" aria-pressed="false"><?= esc($label) ?></button><?php endforeach ?>
      <span class="sr-only" aria-live="polite" data-filter-count></span>
    </div>
    <?php endif ?>

    <?php $paginated = $articles->paginate(9); ?>
    <div class="grid grid--3">
      <?php foreach ($paginated as $article) snippet('partials/article-card', ['article' => $article]) ?>
    </div>

    <?php snippet('partials/pagination', ['pagination' => $paginated->pagination()]) ?>
  </div>
</section>
<?php snippet('layouts/footer') ?>
