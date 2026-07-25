<?php snippet('layouts/header') ?>
<section class="section">
  <div class="container">
    <div class="section__head reveal" style="max-width:46rem">
      <span class="kicker"><?= esc(t('breakfast.work', 'Selected work')) ?></span>
      <h1 class="section__title">Websites Breakfast is proud of</h1>
      <p class="section__lead">A selection of clear, fast websites built for small businesses across North Wales. Each one started with the same question: what does a visitor most need to do?</p>
    </div>
    <?php $pfItems = $pfItems ?? []; ?>
    <?php if ($pfItems === []): ?>
      <div class="card card--accent reveal" data-test="portfolio-empty" style="max-width:44rem">
        <h2 class="scard__title" style="font-size:1.2rem">The portfolio is being built.</h2>
        <p style="margin-top:var(--s-2);color:var(--muted)">Client case studies are added here as projects launch. In the meantime, take a look at how Breakfast works.</p>
        <p style="margin-top:var(--s-4)"><a class="btn btn--secondary" href="<?= esc(url('start-a-project')) ?>">Start a project</a></p>
      </div>
    <?php else: ?>
      <div class="grid grid--2 worklist" data-test="portfolio-list">
        <?php foreach ($pfItems as $it) {
            snippet('partials/portfolio-card', ['pf' => $it]);
        } ?>
      </div>
    <?php endif ?>
  </div>
</section>
<section class="section"><div class="container"><?php snippet('partials/cta-band') ?></div></section>
<?php snippet('layouts/footer') ?>
