<?php

use Breakfast\Platform\Teletext\Registry;

snippet('layouts/header');
$children = $page->children()->listed();
?>
<section class="section">
  <div class="container">
    <?php snippet('partials/breadcrumbs') ?>
    <div class="tt-split">
      <div class="tt-split__main">
        <?php snippet('teletext/bar', ['number' => 'P300', 'title' => esc($page->heading()->or($page->title()))]) ?>
        <?php if ($page->intro()->isNotEmpty()): ?><p class="section__lead"><?= esc($page->intro()) ?></p><?php endif ?>

        <div class="tt-list" style="margin-top:var(--s-4)">
          <?php foreach ($children as $service): ?>
            <?php $num = Registry::numberFor($service, $site); ?>
            <a class="tt-list__row" href="<?= esc($service->url()) ?>">
              <span class="tt-list__num"><?= $num !== null ? esc($num) : '' ?></span>
              <span class="tt-list__body">
                <span class="tt-list__title"><?= esc($service->short_name()->or($service->title())) ?></span>
                <?php if ($service->suitable_for()->isNotEmpty()): ?><span class="tt-list__meta"><?= esc($service->suitable_for()->excerptSafe(70)) ?></span><?php endif ?>
              </span>
            </a>
          <?php endforeach ?>
        </div>

        <div class="tt-box" style="margin-top:var(--s-6)">
          <p class="tt-box__title">Not sure which fits?</p>
          <p class="tt-box__text">Choose "Not sure yet" on the enquiry form and describe what is getting in the way. You do not need to diagnose the website or write a finished brief first.</p>
          <a class="tt-box__link" data-track="cta_click" data-track-label="services-unsure" href="<?= esc(url('start-a-project')) ?>#form">Tell me what you need</a>
        </div>
      </div>
      <div class="tt-split__aside">
        <?php snippet('teletext/pixel-art', ['motif' => 'monitor', 'size' => 'lg']) ?>
      </div>
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
