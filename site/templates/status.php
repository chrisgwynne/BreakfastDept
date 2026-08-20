<?php

/**
 * P900 — Breakfast System. A playful status board. Every row here is
 * cosmetic copy — never real infrastructure, versions, or environment data.
 * See docs/security.md if this template is ever extended.
 */
snippet('layouts/header');

$rows = [
    ['label' => 'Web design',      'value' => 'Online',    'dot' => 'green'],
    ['label' => 'Coffee',          'value' => 'Online',    'dot' => 'green'],
    ['label' => 'Kirby',           'value' => 'Online',    'dot' => 'green'],
    ['label' => 'North Wales',     'value' => 'Online',    'dot' => 'green'],
    ['label' => 'Bad websites',    'value' => 'Detected',  'dot' => 'yellow'],
    ['label' => 'Comic Sans',      'value' => 'Contained', 'dot' => 'green'],
    ['label' => 'Client projects', 'value' => 'Running',   'dot' => 'green'],
];
?>
<section class="section" style="border-bottom:0">
  <div class="container tt-egg">
    <p class="kicker">P900</p>
    <h1 class="hero__title tt-egg__title">BREAKFAST SYSTEM</h1>
    <div style="margin-top:var(--s-8)"><?php snippet('teletext/status', ['rows' => $rows]) ?></div>
    <p style="margin-top:var(--s-12)"><a class="btn btn--primary btn--lg" href="<?= esc(url('/')) ?>">P100 — Index</a></p>
  </div>
</section>
<?php snippet('layouts/footer') ?>
