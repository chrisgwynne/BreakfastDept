<?php snippet('layouts/header') ?>
<section class="section" style="border-bottom:0">
  <div class="container container--prose tt-egg" style="text-align:center">
    <p class="kicker" style="justify-content:center">P404</p>
    <h1 class="hero__title" style="margin-top:var(--s-4);font-size:clamp(2rem,6vw,3.4rem)">PAGE NOT FOUND</h1>
    <p class="hero__sub" style="margin-inline:auto;text-align:center">
      <?= esc($page->heading()->or("The page you requested is not currently being transmitted.")) ?>
    </p>
    <?php if ($page->text()->isNotEmpty()): ?><p class="section__lead" style="margin-inline:auto"><?= esc($page->text()) ?></p><?php endif ?>

    <div style="margin-top:var(--s-8);text-align:left;max-width:26rem;margin-inline:auto">
      <p class="kicker">Possible causes</p>
      <ul class="prose" style="margin-top:var(--s-3)">
        <li>Wrong page number</li>
        <li>An old link</li>
        <li>Internet gremlins</li>
      </ul>
    </div>

    <div style="margin-top:var(--s-12);max-width:26rem;margin-inline:auto;text-align:left">
      <?php snippet('teletext/page-index', ['items' => [
          ['number' => '100', 'label' => 'Index',           'href' => url('/')],
          ['number' => '101', 'label' => 'Start a Project',  'href' => url('start-a-project')],
          ['number' => '200', 'label' => 'Our Work',         'href' => page('work') ? page('work')->url() : url('work')],
      ]]) ?>
    </div>

    <p style="margin-top:var(--s-8)"><a class="btn btn--primary btn--lg" href="<?= esc($page->cta_link()->or($site->url())) ?>"><?= esc($page->cta_label()->or('Back to home')) ?></a></p>
  </div>
</section>
<?php snippet('layouts/footer') ?>
