<?php snippet('layouts/header') ?>
<section class="section">
  <div class="container">
    <?php snippet('partials/breadcrumbs') ?>
    <?php snippet('teletext/bar', ['number' => 'P300', 'title' => esc($page->heading()->or($page->title())), 'as' => 'h1']) ?>
    <?php if ($page->intro()->isNotEmpty()): ?><p class="section__lead"><?= esc($page->intro()) ?></p><?php endif ?>

    <div class="offer-grid" style="margin-top:var(--s-6)">
      <?php $offerNumber = 0; foreach ($page->children()->listed() as $service) {
          $offerNumber++;
          snippet('partials/service-card', ['service' => $service, 'offerNumber' => $offerNumber]);
      } ?>
    </div>

    <div class="tt-box" style="margin-top:var(--s-6)">
      <p class="tt-box__title">Not sure which fits?</p>
      <p class="tt-box__text">Choose "Not sure yet" on the enquiry form and describe what is getting in the way. You do not need to diagnose the website or write a finished brief first.</p>
      <a class="tt-box__link" data-track="cta_click" data-track-label="services-unsure" href="<?= esc(url('start-a-project')) ?>#form">Tell me what you need</a>
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
