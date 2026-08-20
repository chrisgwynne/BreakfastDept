<?php

/** P123 — Breakfast Menu. A playful easter egg; discovered by typing 123. */

snippet('layouts/header');

$menu = [
    ['item' => 'Full Welsh',    'rating' => '★★★★★'],
    ['item' => 'Bacon',         'rating' => '★★★★★'],
    ['item' => 'Hash browns',   'rating' => '★★★★★★'],
    ['item' => 'Mushrooms',     'rating' => 'Discussed internally'],
    ['item' => 'Coffee',        'rating' => 'Essential'],
    ['item' => 'A good website', 'rating' => 'Also essential'],
];
?>
<section class="section" style="border-bottom:0">
  <div class="container tt-egg">
    <p class="kicker">P123</p>
    <h1 class="hero__title tt-egg__title">BREAKFAST MENU</h1>
    <div class="tt-status-list" style="margin-top:var(--s-8)">
      <?php foreach ($menu as $row): ?>
        <div class="tt-status-row">
          <span class="tt-status-row__label"><?= esc($row['item']) ?></span>
          <span class="tt-status-row__value" style="color:var(--tt-yellow)"><?= esc($row['rating']) ?></span>
        </div>
      <?php endforeach ?>
    </div>
    <p class="section__lead" style="margin-top:var(--s-12)">Web design served all day.</p>
    <p style="margin-top:var(--s-8)"><a class="btn btn--primary btn--lg" href="<?= esc(url('start-a-project')) ?>">101 — Start a Project</a></p>
  </div>
</section>
<?php snippet('layouts/footer') ?>
