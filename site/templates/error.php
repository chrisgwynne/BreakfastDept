<?php snippet('layouts/header') ?>
<section class="section" style="border-bottom:0">
  <div class="container container--prose tt-egg">
    <?php snippet('teletext/bar', ['number' => 'P404', 'title' => 'Page Not Found', 'as' => 'h1']) ?>
    <p class="tt-system-message">THE PAGE YOU REQUESTED<br>IS NOT CURRENTLY BEING TRANSMITTED.</p>
    <?php if ($page->text()->isNotEmpty()): ?><p class="section__lead"><?= esc($page->text()) ?></p><?php endif ?>

    <div style="margin-top:var(--s-8)">
      <?php snippet('teletext/page-index', ['items' => [
          ['number' => '100', 'label' => 'Index',          'href' => url('/')],
          ['number' => '101', 'label' => 'Start a Project', 'href' => url('start-a-project')],
          ['number' => '200', 'label' => 'Our Work',        'href' => page('work') ? page('work')->url() : url('work')],
      ]]) ?>
    </div>
  </div>
</section>
<?php snippet('layouts/footer', ['softkeys' => [
    ['label' => 'Index', 'sub' => 'P100', 'href' => url('/')],
    ['label' => 'Start', 'sub' => 'P101', 'href' => url('start-a-project')],
    ['label' => 'Work',  'sub' => 'P200', 'href' => page('work') ? page('work')->url() : url('work')],
    ['label' => 'Contact', 'sub' => 'P700', 'href' => url('contact')],
]]) ?>
