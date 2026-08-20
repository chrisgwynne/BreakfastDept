<?php

use Breakfast\Platform\Teletext\Registry;

snippet('layouts/header');

$articles = $page->children()->listed()->sortBy('date', 'desc');
$featured = $page->featured_article()->toPage();
// Category filters
$cats = [];
foreach ($articles as $a) { foreach ($a->categories()->split() as $c) { $cats[\Kirby\Toolkit\Str::slug($c)] = $c; } }
$paginated = $articles->paginate(9);
?>
<section class="section">
  <div class="container">
    <?php snippet('partials/breadcrumbs') ?>
    <?php snippet('teletext/bar', ['number' => 'P600', 'title' => esc($page->heading()->or($page->title())), 'sub' => $paginated->pagination()->page() . '/' . max(1, $paginated->pagination()->pages())]) ?>
    <?php if ($page->intro()->isNotEmpty()): ?><p class="section__lead"><?= esc($page->intro()) ?></p><?php endif ?>

    <?php if ($featured && $featured->isListed()): ?>
      <?php $fNum = Registry::numberFor($featured, $site); ?>
      <a class="tt-box tt-box--blue" href="<?= esc($featured->url()) ?>" style="display:block;margin-top:var(--s-4)">
        <p class="tt-box__title">Featured<?= $fNum !== null ? ' — P' . esc($fNum) : '' ?></p>
        <p class="tt-box__text" style="font-weight:800;text-transform:none;font-size:1.1rem;color:var(--tt-white)"><?= esc($featured->title()) ?></p>
        <?php if ($featured->excerpt()->isNotEmpty()): ?><p class="tt-box__text"><?= esc($featured->excerpt()->excerptSafe(140)) ?></p><?php endif ?>
        <span class="tt-box__link">Read</span>
      </a>
    <?php endif ?>

    <?php if ($cats): ?>
    <div class="filters" data-filters role="group" aria-label="<?= esc(t('breakfast.filter', 'Filter articles')) ?>" style="margin-top:var(--s-6)">
      <button class="filter" data-filter="all" aria-pressed="true"><?= esc(t('breakfast.all', 'All')) ?></button>
      <?php foreach ($cats as $slug => $label): ?><button class="filter" data-filter="<?= esc($slug) ?>" aria-pressed="false"><?= esc($label) ?></button><?php endforeach ?>
      <span class="sr-only" aria-live="polite" data-filter-count></span>
    </div>
    <?php endif ?>

    <div class="tt-list" style="margin-top:var(--s-4)">
      <?php foreach ($paginated as $article): ?>
        <?php
          $num = Registry::numberFor($article, $site);
          $tags = implode(' ', array_map(fn ($c) => \Kirby\Toolkit\Str::slug($c), $article->categories()->split()));
        ?>
        <a class="tt-list__row" href="<?= esc($article->url()) ?>" data-filter-item data-tags="<?= esc($tags) ?>">
          <span class="tt-list__num"><?= $num !== null ? esc($num) : '' ?></span>
          <span class="tt-list__body">
            <span class="tt-list__title"><?= esc($article->title()) ?></span>
            <span class="tt-list__meta"><?= esc($article->date()->toDate('j M Y')) ?><?php if ($article->categories()->isNotEmpty()): ?> · <?= esc($article->categories()->split()[0] ?? '') ?><?php endif ?></span>
          </span>
        </a>
      <?php endforeach ?>
    </div>

    <?php snippet('partials/pagination', ['pagination' => $paginated->pagination()]) ?>
  </div>
</section>
<?php snippet('layouts/footer', ['softkeys' => [
    ['label' => 'Back',   'sub' => 'P100', 'href' => url('/')],
    ['label' => 'About',  'sub' => 'P500', 'href' => page('about') ? page('about')->url() : url('about')],
    ['label' => 'Work',   'sub' => 'P200', 'href' => page('work') ? page('work')->url() : url('work')],
    ['label' => 'Contact', 'sub' => 'P700', 'href' => url('contact')],
]]) ?>
