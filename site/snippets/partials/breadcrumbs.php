<?php
/** Accessible breadcrumbs. Home is implied; skipped on the home page. */
if ($page->isHomePage()) return;
$trail = $page->parents()->flip()->add($page);
?>
<nav class="breadcrumbs" aria-label="Breadcrumb">
  <ol>
    <li><a href="<?= esc($site->url()) ?>"><?= esc(t('breakfast.home', 'Home')) ?></a> <span aria-hidden="true">/</span></li>
    <?php foreach ($trail as $crumb): ?>
      <li>
        <?php if ($crumb->is($page)): ?>
          <span aria-current="page"><?= esc($crumb->title()) ?></span>
        <?php else: ?>
          <a href="<?= esc($crumb->url()) ?>"><?= esc($crumb->title()) ?></a> <span aria-hidden="true">/</span>
        <?php endif ?>
      </li>
    <?php endforeach ?>
  </ol>
</nav>
