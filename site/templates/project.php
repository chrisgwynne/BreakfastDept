<?php snippet('layouts/header') ?>

<article class="section">
  <div class="container">
    <?php snippet('partials/breadcrumbs') ?>

    <header class="section__head" style="max-width:52rem">
      <span class="kicker"><?= esc($page->client()) ?><?php if ($page->industries()->isNotEmpty()): ?> · <?= esc($page->industries()->split()[0] ?? '') ?><?php endif ?></span>
      <h1 class="section__title"><?= esc($page->title()) ?></h1>
      <?php if ($page->summary()->isNotEmpty()): ?><p class="section__lead"><?= esc($page->summary()) ?></p><?php endif ?>
      <?php if ($page->confidential()->toBool(false)): ?><p class="tag"><?= esc(t('breakfast.confidential', 'Details anonymised at the client’s request')) ?></p><?php endif ?>
    </header>

    <?php if ($hero = $page->hero_image()->toFile()): ?>
      <div class="article__cover"><?= $hero->crop(1600, 900)->html(['alt' => esc($hero->alt()->or($page->title())), 'fetchpriority' => 'high']) ?></div>
    <?php endif ?>

    <?php /* Before / after */ ?>
    <?php $before = $page->before_image()->toFile(); $after = $page->after_image()->toFile(); ?>
    <?php if ($before && $after): ?>
      <div class="comparison" style="margin-bottom:var(--s-12)">
        <figure><?= $before->crop(700, 500)->html(['alt' => 'Before', 'loading' => 'lazy']) ?><figcaption>before</figcaption></figure>
        <figure><?= $after->crop(700, 500)->html(['alt' => 'After', 'loading' => 'lazy']) ?><figcaption>after</figcaption></figure>
      </div>
    <?php endif ?>

    <div class="columns">
      <div class="prose">
        <?php foreach (['challenge' => 'The challenge', 'approach' => 'Our approach', 'strategy' => 'Strategy'] as $field => $label): ?>
          <?php if ($page->$field()->isNotEmpty()): ?>
            <h2><?= esc(t('breakfast.project.' . $field, $label)) ?></h2>
            <?= $page->$field()->kt() ?>
          <?php endif ?>
        <?php endforeach ?>
      </div>
      <div class="prose">
        <?php foreach (['design_explanation' => 'Design', 'build_explanation' => 'Build', 'outcome' => 'The outcome'] as $field => $label): ?>
          <?php if ($page->$field()->isNotEmpty()): ?>
            <h2><?= esc(t('breakfast.project.' . $field, $label)) ?></h2>
            <?= $page->$field()->kt() ?>
          <?php endif ?>
        <?php endforeach ?>
      </div>
    </div>

    <?php /* Metrics */ ?>
    <?php $metrics = $page->metrics()->toStructure(); ?>
    <?php if ($metrics->isNotEmpty()): ?>
      <div class="stats" style="margin-top:var(--s-12)">
        <?php foreach ($metrics as $m): ?>
          <div class="stat">
            <div class="stat__value"><?= esc($m->value()) ?></div>
            <div class="stat__label"><?= esc($m->label()) ?></div>
            <?php if ($m->context()->isNotEmpty()): ?><p class="stat__context"><?= esc($m->context()) ?></p><?php endif ?>
          </div>
        <?php endforeach ?>
      </div>
    <?php endif ?>

    <?php /* Testimonial */ ?>
    <?php if ($page->testimonial_quote()->isNotEmpty()): ?>
      <figure class="quote" style="margin-top:var(--s-12)">
        <blockquote class="quote__text"><?= esc($page->testimonial_quote()) ?></blockquote>
        <figcaption class="quote__cite">— <?= esc($page->testimonial_name()) ?><?php if ($page->testimonial_role()->isNotEmpty()): ?>, <?= esc($page->testimonial_role()) ?><?php endif ?></figcaption>
      </figure>
    <?php endif ?>

    <?php /* Flexible body blocks */ ?>
    <?php if ($page->body()->isNotEmpty()): ?>
      <div class="blocks" style="margin-top:var(--s-12)"><?= $page->body()->toBlocks() ?></div>
    <?php endif ?>

    <?php /* Gallery */ ?>
    <?php if ($page->gallery()->toFiles()->isNotEmpty()): ?>
      <div class="gallery" style="margin-top:var(--s-12)">
        <?php foreach ($page->gallery()->toFiles() as $img): ?><?= $img->crop(600, 450)->html(['alt' => esc($img->alt()), 'loading' => 'lazy']) ?><?php endforeach ?>
      </div>
    <?php endif ?>

    <?php /* Credits + tech + link */ ?>
    <div class="grid grid--2" style="margin-top:var(--s-12)">
      <?php if ($page->credits()->toStructure()->isNotEmpty()): ?>
        <div class="card"><h3 class="scard__title"><?= esc(t('breakfast.credits', 'Credits')) ?></h3>
          <ul><?php foreach ($page->credits()->toStructure() as $c): ?><li><strong><?= esc($c->role()) ?>:</strong> <?= esc($c->name()) ?></li><?php endforeach ?></ul>
        </div>
      <?php endif ?>
      <?php if ($page->technology()->isNotEmpty() || $page->project_url()->isNotEmpty()): ?>
        <div class="card">
          <?php if ($page->technology()->isNotEmpty()): ?><h3 class="scard__title"><?= esc(t('breakfast.tech', 'Built with')) ?></h3><p><?php foreach ($page->technology()->split() as $t): ?><span class="tag"><?= esc($t) ?></span> <?php endforeach ?></p><?php endif ?>
          <?php if ($page->project_url()->isNotEmpty()): ?><p style="margin-top:var(--s-4)"><a class="btn btn--ghost" data-track="external_project_link" href="<?= esc($page->project_url()) ?>" rel="noopener" target="_blank"><?= esc(t('breakfast.visit', 'Visit the site')) ?> ↗</a></p><?php endif ?>
        </div>
      <?php endif ?>
    </div>

    <?php snippet('partials/related', ['related' => $page->related_projects()->toPages(), 'heading' => t('breakfast.relatedprojects', 'More work')]) ?>
  </div>
</article>

<section class="section"><div class="container"><?php snippet('partials/cta-band') ?></div></section>
<?php snippet('layouts/footer') ?>
