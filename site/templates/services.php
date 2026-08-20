<?php snippet('layouts/header') ?>
<section class="section">
  <div class="container">
    <?php snippet('partials/breadcrumbs') ?>
    <div class="section__head">
      <span class="kicker">P300 · <?= esc(t('breakfast.services', 'Services')) ?></span>
      <h1 class="section__title"><?= esc($page->heading()->or($page->title())) ?></h1>
      <?php if ($page->intro()->isNotEmpty()): ?><p class="section__lead"><?= esc($page->intro()) ?></p><?php endif ?>
    </div>
    <div class="offer-grid">
      <?php $offerNumber = 0; foreach ($page->children()->listed() as $service) {
          $offerNumber++;
          snippet('partials/service-card', ['service' => $service, 'offerNumber' => $offerNumber]);
      } ?>
    </div>
    <div class="offer-help card reveal">
      <div>
        <h2 class="scard__title">Not sure which fits?</h2>
        <p>Choose “Not sure yet” on the enquiry form and describe what is getting in the way. You do not need to diagnose the website or write a finished brief first.</p>
      </div>
      <a class="btn btn--secondary" data-track="cta_click" data-track-label="services-unsure" href="<?= esc(url('start-a-project')) ?>#form">Tell me what you need</a>
    </div>
  </div>
</section>
<section class="section"><div class="container"><?php snippet('partials/cta-band') ?></div></section>
<?php snippet('layouts/footer', ['softkeys' => [
    ['label' => 'Back',  'sub' => 'P100', 'href' => url('/')],
    ['label' => 'Work',  'sub' => 'P200', 'href' => page('work') ? page('work')->url() : url('work')],
    ['label' => 'Start', 'sub' => 'P101', 'href' => url('start-a-project')],
    ['label' => 'Contact', 'sub' => 'P700', 'href' => url('contact')],
]]) ?>
