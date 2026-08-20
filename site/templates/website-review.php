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
    <?php snippet('teletext/bar', ['number' => 'P110', 'title' => esc($page->title()) . ($page->kicker()->isNotEmpty() ? ' — ' . esc($page->kicker()) : ''), 'as' => 'h1']) ?>
    <div class="grid grid--2" style="align-items:start;gap:var(--s-16);margin-top:var(--s-4)">
      <div>
        <?php if ($page->intro()->isNotEmpty()): ?><div class="blocks"><?= $page->intro()->toBlocks() ?></div><?php endif ?>
        <?php if ($mode !== 'disabled'): ?>
          <div class="tt-box" style="margin-top:var(--s-6)">
            <p class="tt-box__text" style="text-transform:none;font-weight:700;color:var(--tt-white)"><?= esc($note) ?></p>
          </div>
        <?php endif ?>
      </div>
      <div>
        <?php if ($mode === 'disabled'): ?>
          <div class="tt-box">
            <p class="tt-box__text"><?= esc($page->closed_message()->or('Website reviews aren’t open right now. Drop me a line and I’ll still be glad to take a look when I can.')) ?></p>
            <a class="tt-box__link" href="<?= esc(url('contact')) ?>">Get in touch</a>
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
