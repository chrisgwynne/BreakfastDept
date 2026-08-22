<?php snippet('layouts/header') ?>
<section class="section tt-egg-page"><div class="container">
  <?php snippet('teletext/bar', ['number' => 'P777', 'title' => 'JACKPOT', 'sub' => '1/1', 'as' => 'h1']) ?>
  <div class="tt-egg-grid">
    <div><p class="tt-egg-kicker">BREAKFAST ARCADE</p><p class="tt-egg-lead">Three matching symbols wins. The odds are excellent if you make the game.</p><p class="tt-egg-note">Press SPIN. Take the result seriously.</p></div>
    <div data-tt-jackpot class="tt-egg-panel tt-jackpot-panel"><div class="tt-jackpot-reels grid grid--3"><div data-tt-reel>EGG</div><div data-tt-reel>MUG</div><div data-tt-reel>SUN</div></div><button type="button" class="btn btn--secondary" data-tt-spin>SPIN</button><p class="tt-egg-result" data-tt-jackpot-result role="status" aria-live="polite"></p></div>
  </div>
  <div class="tt-egg-actions"><a class="btn btn--ghost" href="<?= esc(url('/')) ?>">P100 INDEX</a></div>
</div></section>
<?php snippet('layouts/footer') ?>
