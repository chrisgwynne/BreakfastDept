<?php snippet('layouts/header') ?>
<article class="section">
  <div class="container container--prose">
    <?php snippet('partials/breadcrumbs') ?>
    <h1 class="section__title"><?= esc($page->title()) ?></h1>
    <?php if ($page->updated()->isNotEmpty()): ?><p class="pcard__cat"><?= esc(t('breakfast.updated', 'Last updated')) ?>: <?= esc($page->updated()->toDate('j F Y')) ?></p><?php endif ?>
    <div class="blocks" style="margin-top:var(--s-8)"><?= $page->body()->toBlocks() ?></div>
  </div>
</article>
<?php snippet('layouts/footer') ?>
