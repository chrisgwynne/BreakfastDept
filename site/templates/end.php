<?php snippet('layouts/header') ?>
<section class="section tt-egg-page"><div class="container">
  <?php snippet('teletext/bar', ['number' => 'P999', 'title' => 'END OF TRANSMISSION', 'sub' => '1/1', 'as' => 'h1']) ?>
  <div class="tt-egg-grid"><div><p class="tt-egg-kicker">YOU FOUND THE EDGE</p><h2 class="tt-egg-title">CONGRATULATIONS.</h2><p class="tt-egg-lead">There is nothing after this page. Which is a relief, honestly.</p></div><div class="tt-egg-panel"><h2>START AGAIN</h2><?php snippet('teletext/page-index', ['items' => [['number' => '100', 'label' => 'THE INDEX', 'href' => url('/')], ['number' => '101', 'label' => 'BUILD SOMETHING', 'href' => url('start-a-project')], ['number' => '123', 'label' => 'BREAKFAST MENU', 'href' => url('/text/123')]]]) ?></div></div>
</div></section>
<?php snippet('layouts/footer') ?>
