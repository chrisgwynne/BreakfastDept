<?php
/**
 * Website review — a low-friction lead path for businesses with an existing
 * weak site. The offer is configurable via the site's review_mode setting
 * (disabled / informal / free / paid); nothing promises a free audit unless
 * that is the chosen mode.
 */
$mode = $site->review_mode()->or('informal')->value();
$notes = [
    'informal' => 'I’ll take an informal first look and send back a few honest thoughts — no charge and no sales pitch.',
    'free'     => 'I’ll do a free short review of your current site and send you the main things I’d change.',
    'paid'     => 'This is a paid, in-depth audit: a written report on your current site with clear priorities.',
];
$note = $notes[$mode] ?? $notes['informal'];
?>
<?php snippet('layouts/header') ?>
<section class="section" id="form">
  <div class="container">
    <?php snippet('partials/breadcrumbs') ?>
    <div class="grid grid--2" style="align-items:start;gap:var(--s-16)">
      <div>
        <div class="section__head" style="margin-bottom:var(--s-8)">
          <span class="kicker">P110<?php if ($page->kicker()->isNotEmpty()): ?> · <?= esc($page->kicker()) ?><?php endif ?></span>
          <h1 class="section__title"><?= esc($page->title()) ?></h1>
        </div>
        <?php if ($page->intro()->isNotEmpty()): ?><div class="blocks"><?= $page->intro()->toBlocks() ?></div><?php endif ?>
        <?php if ($mode !== 'disabled'): ?>
          <div class="card card--accent" style="margin-top:var(--s-8)">
            <p><strong><?= esc($note) ?></strong></p>
          </div>
        <?php endif ?>
      </div>
      <div>
        <?php if ($mode === 'disabled'): ?>
          <div class="card">
            <p><?= esc($page->closed_message()->or('Website reviews aren’t open right now. Drop me a line and I’ll still be glad to take a look when I can.')) ?></p>
            <p style="margin-top:var(--s-4)"><a class="btn btn--secondary" href="<?= esc(url('contact')) ?>">Get in touch</a></p>
          </div>
        <?php else: ?>
          <?php snippet('forms/review-form', ['page' => $page, 'result' => $result, 'old' => $old]) ?>
        <?php endif ?>
      </div>
    </div>
  </div>
</section>
<?php snippet('layouts/footer', ['softkeys' => [
    ['label' => 'Back',  'sub' => 'P100', 'href' => url('/')],
    ['label' => 'Start', 'sub' => 'P101', 'href' => url('start-a-project')],
    ['label' => 'Work',  'sub' => 'P200', 'href' => page('work') ? page('work')->url() : url('work')],
    ['label' => 'Contact', 'sub' => 'P700', 'href' => url('contact')],
]]) ?>
