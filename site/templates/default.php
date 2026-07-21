<?php snippet('layouts/header') ?>
<section class="section">
  <div class="container container--prose">
    <?php snippet('partials/breadcrumbs') ?>
    <h1 class="section__title"><?= esc($page->title()) ?></h1>
    <div class="blocks" style="margin-top:var(--s-8)"><?php if ($page->body()->isNotEmpty()) echo $page->body()->toBlocks(); elseif ($page->text()->isNotEmpty()) echo $page->text()->kt(); ?></div>
  </div>
</section>
<?php snippet('layouts/footer') ?>
