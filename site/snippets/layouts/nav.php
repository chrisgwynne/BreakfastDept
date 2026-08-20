<?php

use Breakfast\Platform\Teletext\Registry;

/** Masthead (page number / brand / live clock) + primary navigation. */
$nav = $site->nav()->toStructure();
$currentNumber = Registry::displayNumberFor($page, $site);
$now = new DateTime('now', new DateTimeZone('Europe/London'));
?>
<div class="tt-masthead">
  <div class="container tt-masthead__inner" style="display:flex;align-items:center;justify-content:space-between;gap:var(--s-4)">
    <span class="tt-masthead__page"><?= $currentNumber !== null ? 'P' . esc($currentNumber) : 'P—' ?></span>
    <span class="tt-masthead__brand">BREAKFAST TEXT</span>
    <span class="tt-masthead__clock" data-tt-clock data-live="true"><?= esc(strtoupper($now->format('D d M'))) ?>&nbsp;&nbsp;<?= esc($now->format('H:i:s')) ?></span>
  </div>
</div>
<header class="site-header">
  <div class="container header__inner">
    <a class="logo" href="<?= esc($site->url()) ?>" aria-label="<?= esc($site->title()) ?> home — P100">
      <span class="logo__word"><?= esc($site->title()->or('Breakfast')) ?></span>
    </a>

    <nav class="nav" data-nav aria-label="Primary">
      <button class="nav__toggle" data-nav-toggle aria-expanded="false" aria-controls="nav-menu">
        <span><?= esc(t('breakfast.menu', 'Menu')) ?></span>
        <span class="nav__toggle-bars" aria-hidden="true"><span></span><span></span><span></span></span>
      </button>
      <ul class="nav__menu" id="nav-menu">
        <?php /* "Work" appears once the flat-file work section has a listed project. */ ?>
        <?php $workPage = page('work'); ?>
        <?php if ($workPage && $workPage->children()->listed()->isNotEmpty()): ?>
          <li><a class="nav__link" data-num="200" href="<?= esc($workPage->url()) ?>"<?= $page->slug() === 'work' ? ' aria-current="page"' : '' ?>><?= esc(t('breakfast.work', 'Work')) ?></a></li>
        <?php endif ?>
        <?php foreach ($nav as $item): ?>
          <?php
            $target = $item->link()->toPage() ?? null;
            $href   = $target ? $target->url() : $item->link()->value();
            $current = $target && ($target->is($page) || $target->isAncestorOf($page)) ? ' aria-current="page"' : '';
            $itemNumber = $target ? Registry::numberFor($target, $site) : null;
          ?>
          <li><a class="nav__link" data-num="<?= $itemNumber !== null ? esc($itemNumber) : '' ?>" href="<?= esc($href) ?>"<?= $current ?>><?= esc($item->label()) ?></a></li>
        <?php endforeach ?>
        <?php if ($site->cta_primary_label()->isNotEmpty()): ?>
          <li><a class="btn btn--primary nav__cta" data-track="cta_click" data-track-label="nav" href="<?= esc($site->cta_primary_link()->or(url('start-a-project'))) ?>"><?= esc($site->cta_primary_label()) ?></a></li>
        <?php endif ?>
      </ul>
    </nav>
  </div>
</header>
