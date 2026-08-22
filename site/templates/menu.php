<?php
snippet('layouts/header');
$menu = [
  ['FULL WELSH', '★★★★★'], ['BACON', '★★★★★'], ['HASH BROWNS', '★★★★★'],
  ['MUSHROOMS', 'DISCUSS'], ['COFFEE', 'ESSENTIAL'], ['A GOOD WEBSITE', 'ESSENTIAL'],
];
?>
<section class="section tt-egg-page"><div class="container">
  <?php snippet('teletext/bar', ['number' => 'P123', 'title' => 'BREAKFAST MENU', 'sub' => '1/1', 'as' => 'h1']) ?>
  <div class="tt-egg-grid">
    <div><p class="tt-egg-kicker">SECRET SERVICE MENU</p><p class="tt-egg-lead">Web design served all day. No substitutions. No hidden extras.</p></div>
    <div class="tt-egg-panel"><h2>ON THE PLATE</h2><?php foreach ($menu as [$item, $rating]): ?><div class="tt-egg-row"><span><?= esc($item) ?></span><b><?= esc($rating) ?></b></div><?php endforeach ?></div>
  </div>
  <div class="tt-egg-actions"><a class="btn btn--secondary" href="<?= esc(url('start-a-project')) ?>">P101 START A PROJECT</a><a class="btn btn--ghost" href="<?= esc(url('/')) ?>">P100 INDEX</a></div>
</div></section>
<?php snippet('layouts/footer') ?>
