<?php snippet('layouts/header') ?>
<section class="section" style="border-bottom:0">
  <div class="container tt-egg" style="text-align:center">
    <p class="kicker" style="justify-content:center">P777</p>
    <h1 class="hero__title tt-egg__title">JACKPOT</h1>
    <p class="section__lead" style="margin-inline:auto">Three matching symbols wins.</p>

    <div data-tt-jackpot style="margin-top:var(--s-8)">
      <div class="grid grid--3" style="max-width:24rem;margin-inline:auto;gap:var(--s-3)">
        <div class="card" style="text-align:center;font-family:var(--mono);font-weight:700;font-size:1.2rem" data-tt-reel>EGG</div>
        <div class="card" style="text-align:center;font-family:var(--mono);font-weight:700;font-size:1.2rem" data-tt-reel>MUG</div>
        <div class="card" style="text-align:center;font-family:var(--mono);font-weight:700;font-size:1.2rem" data-tt-reel>SUN</div>
      </div>
      <p style="margin-top:var(--s-6)"><button type="button" class="btn btn--primary btn--lg" data-tt-spin>Spin</button></p>
      <p class="form-status" data-tt-jackpot-result style="margin-top:var(--s-6);max-width:28rem;margin-inline:auto" role="status" aria-live="polite"></p>
    </div>

    <p style="margin-top:var(--s-12)"><a class="btn btn--ghost" href="<?= esc(url('start-a-project')) ?>">101 — Start a Project</a></p>
  </div>
</section>
<?php snippet('layouts/footer') ?>
