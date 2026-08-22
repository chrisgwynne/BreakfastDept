<?php
snippet('layouts/header');
$rows = [
 ['Web design','ONLINE','green'], ['Coffee','ONLINE','green'], ['Kirby','ONLINE','green'],
 ['North Wales','ONLINE','green'], ['Bad websites','DETECTED','yellow'], ['Comic Sans','CONTAINED','green'], ['Client projects','RUNNING','green'],
];
?>
<section class="section tt-egg-page"><div class="container">
  <?php snippet('teletext/bar', ['number' => 'P900', 'title' => 'SYSTEM STATUS', 'sub' => '1/1', 'as' => 'h1']) ?>
  <div class="tt-egg-grid"><div><p class="tt-egg-kicker">BREAKFAST SERVICE MONITOR</p><p class="tt-egg-lead">A cosmetic status board. No infrastructure is exposed here. Everything important is human-readable.</p></div><div class="tt-egg-panel"><h2>LIVE CHANNELS</h2><?php foreach ($rows as [$label, $value, $colour]): ?><div class="tt-egg-row"><span><i class="tt-status-dot tt-status-dot--<?= esc($colour) ?>"></i><?= esc($label) ?></span><b><?= esc($value) ?></b></div><?php endforeach ?></div></div>
  <div class="tt-egg-actions"><a class="btn btn--ghost" href="<?= esc(url('/')) ?>">P100 INDEX</a></div>
</div></section>
<?php snippet('layouts/footer') ?>
