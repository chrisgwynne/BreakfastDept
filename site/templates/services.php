<?php

use Breakfast\Platform\Teletext\Registry;

snippet('layouts/header');
$services = $page->children()->listed();
?>
<section class="section tt-page" aria-labelledby="services-heading">
  <div class="container">
    <?php snippet('teletext/bar', ['number' => 'P300', 'title' => 'SERVICES', 'sub' => '1/1', 'as' => 'h1', 'id' => 'services-heading']) ?>
    <?php if ($page->intro()->isNotEmpty()): ?><p class="tt-page__intro"><?= esc($page->intro()) ?></p><?php endif ?>

    <div class="tt-page__panel">
      <div class="tt-page__panel-head"><span>PAGE</span><span>STARTING POINT</span><span>STATUS</span></div>
      <?php foreach ($services as $service): $number = Registry::numberFor($service, $site); ?>
        <a class="tt-page__row" href="<?= esc($service->url()) ?>">
          <b><?= $number !== null ? 'P' . esc($number) : '' ?></b>
          <span><strong><?= esc($service->title()) ?></strong><small><?= esc($service->summary()->or($service->description())) ?></small></span>
          <em>READY</em>
        </a>
      <?php endforeach ?>
    </div>

    <div class="tt-page__notice">
      <strong>NOT SURE WHICH PAGE?</strong>
      <span>Choose “Not sure yet” on the enquiry form and describe what is getting in the way.</span>
      <a href="<?= esc(url('start-a-project')) ?>#form">START AT P101 ›</a>
    </div>
  </div>
</section>
<?php snippet('layouts/footer', ['softkeys' => [
    ['label' => 'Index',  'sub' => 'P100', 'href' => url('/')],
    ['label' => 'Work',   'sub' => 'P200', 'href' => page('work') ? page('work')->url() : url('work')],
    ['label' => 'Start',  'sub' => 'P101', 'href' => url('start-a-project')],
    ['label' => 'Contact','sub' => 'P700', 'href' => url('contact')],
]]) ?>
