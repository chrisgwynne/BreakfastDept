<?php snippet('layouts/header') ?>
<section class="section" id="form">
  <div class="container">
    <?php snippet('partials/breadcrumbs') ?>
    <?php snippet('teletext/bar', ['number' => 'P700', 'title' => esc($page->heading()->or($page->title())), 'as' => 'h1']) ?>
    <div class="grid grid--2" style="align-items:start;gap:var(--s-16);margin-top:var(--s-4)">
      <div>
        <?php if ($page->intro()->isNotEmpty()): ?><div class="prose"><?= $page->intro()->toBlocks() ?></div><?php endif ?>

        <?php /* Route the right enquiries to the right place. */ ?>
        <div class="tt-box" style="margin-top:var(--s-6)">
          <p class="tt-box__title">Starting a whole new website or project?</p>
          <p class="tt-box__text">The Start a Project form asks the right questions up front, so you get a more useful reply, faster. This page is best for quick questions and everything else.</p>
          <a class="tt-box__link" href="<?= esc(url('start-a-project')) ?>"><?= esc(t('breakfast.startproject', 'Start a project')) ?></a>
        </div>
        <div class="tt-box" style="margin-top:var(--s-6)">
          <?php if ($site->email()->isNotEmpty()): ?><p><strong><?= esc(t('breakfast.email', 'Email')) ?>:</strong> <a href="mailto:<?= esc($site->email()) ?>"><?= esc($site->email()) ?></a></p><?php endif ?>
          <?php if ($site->phone()->isNotEmpty()): ?><p><strong><?= esc(t('breakfast.phone', 'Phone')) ?>:</strong> <a href="tel:<?= esc(preg_replace('/\s+/', '', $site->phone()->value())) ?>"><?= esc($site->phone()) ?></a></p><?php endif ?>
          <?php if ($site->availability_enabled()->toBool(false) && $site->availability_text()->isNotEmpty()): ?><p><?= esc($site->availability_text()) ?></p><?php endif ?>
          <?php
            $geoLine = $site->geo_wording()->or('Based in ' . $site->county()->or($site->country())->value());
          ?>
          <?php if ($site->areas_served()->isNotEmpty()): ?>
            <p style="margin-top:var(--s-4)"><strong><?= esc($geoLine) ?>.</strong><br>
            <span class="muted">Working with businesses across <?= esc($site->areas_served()->value()) ?>.</span></p>
          <?php endif ?>
        </div>
      </div>
      <div>
        <?php snippet('forms/contact-form', ['page' => $page, 'result' => $result, 'old' => $old]) ?>
      </div>
    </div>
  </div>
</section>
<?php snippet('layouts/footer', ['softkeys' => [
    ['label' => 'Back',  'sub' => 'P100', 'href' => url('/')],
    ['label' => 'Start', 'sub' => 'P101', 'href' => url('start-a-project')],
    ['label' => 'Work',  'sub' => 'P200', 'href' => page('work') ? page('work')->url() : url('work')],
    ['label' => 'About', 'sub' => 'P500', 'href' => page('about') ? page('about')->url() : url('about')],
]]) ?>
