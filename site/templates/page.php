<?php snippet('layouts/header') ?>
<section class="section">
  <div class="container">
    <?php snippet('partials/breadcrumbs') ?>
    <div class="section__head"><h1 class="section__title"><?= esc($page->title()) ?></h1></div>
    <div class="blocks"><?= $page->body()->toBlocks() ?></div>
  </div>
</section>
<?php snippet('layouts/footer') ?>
