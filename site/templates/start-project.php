<?php snippet('layouts/header') ?>
<section class="section" id="form">
  <div class="container">
    <?php snippet('partials/breadcrumbs') ?>
    <div class="section__head" style="max-width:46rem">
      <span class="kicker"><?= esc(t('breakfast.startproject', 'Start a project')) ?></span>
      <h1 class="section__title"><?= esc($page->title()) ?></h1>
    </div>
    <?php if ($page->intro()->isNotEmpty()): ?><div class="blocks" style="margin-bottom:var(--s-8)"><?= $page->intro()->toBlocks() ?></div><?php endif ?>
    <?php snippet('forms/project-form', ['page' => $page, 'result' => $result, 'old' => $old]) ?>
  </div>
</section>
<?php snippet('layouts/footer') ?>
