<?php snippet('layouts/header') ?>
<section class="section">
  <div class="container">
    <?php snippet('partials/breadcrumbs') ?>
    <div class="section__head">
      <span class="kicker"><?= esc(t('breakfast.services', 'Services')) ?></span>
      <h1 class="section__title"><?= esc($page->title()) ?></h1>
      <?php if ($page->intro()->isNotEmpty()): ?><p class="section__lead"><?= esc($page->intro()) ?></p><?php endif ?>
    </div>
    <div class="grid grid--3">
      <?php foreach ($page->children()->listed() as $service) snippet('partials/service-card', ['service' => $service]) ?>
    </div>
  </div>
</section>
<section class="section"><div class="container"><?php snippet('partials/cta-band') ?></div></section>
<?php snippet('layouts/footer') ?>
