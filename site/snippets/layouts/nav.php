<?php
/** Primary navigation. Labels + links come from site settings (editable). */
$nav = $site->nav()->toStructure();
?>
<header class="site-header">
  <div class="container header__inner">
    <a class="logo" href="<?= esc($site->url()) ?>" aria-label="<?= esc($site->title()) ?> home">
      <span class="logo__word"><?= esc($site->title()->or('Breakfast')) ?></span>
      <span class="logo__egg" aria-hidden="true"><span class="logo__white"><span class="logo__yolk"></span></span></span>
    </a>

    <nav class="nav" data-nav aria-label="Primary">
      <button class="nav__toggle" data-nav-toggle aria-expanded="false" aria-controls="nav-menu">
        <span><?= esc(t('breakfast.menu', 'Menu')) ?></span>
        <span class="nav__toggle-bars" aria-hidden="true"><span></span><span></span><span></span></span>
      </button>
      <ul class="nav__menu" id="nav-menu">
        <?php /* "Work" appears only once there is genuinely published portfolio work. */ ?>
        <?php $hasWork = breakfast()->portfolio()->publicList(1) !== []; ?>
        <?php if ($hasWork): ?>
          <li><a class="nav__link" href="<?= esc(url('work')) ?>"<?= $page->slug() === 'work' ? ' aria-current="page"' : '' ?>><?= esc(t('breakfast.work', 'Work')) ?></a></li>
        <?php endif ?>
        <?php foreach ($nav as $item): ?>
          <?php
            $target = $item->link()->toPage() ?? null;
            $href   = $target ? $target->url() : $item->link()->value();
            $current = $target && ($target->is($page) || $target->isAncestorOf($page)) ? ' aria-current="page"' : '';
          ?>
          <li><a class="nav__link" href="<?= esc($href) ?>"<?= $current ?>><?= esc($item->label()) ?></a></li>
        <?php endforeach ?>
        <?php if ($site->cta_primary_label()->isNotEmpty()): ?>
          <li><a class="btn btn--primary nav__cta" data-track="cta_click" data-track-label="nav" href="<?= esc($site->cta_primary_link()->or(url('start-a-project'))) ?>"><?= esc($site->cta_primary_label()) ?></a></li>
        <?php endif ?>
      </ul>
    </nav>
  </div>
</header>
