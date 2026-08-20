<?php

use Breakfast\Platform\Content\ArtDirection;
use Breakfast\Platform\Teletext\Registry;

snippet('layouts/header');

$ttNumber = Registry::numberFor($page, $site);

/* Resolve the curated art-direction settings into safe tokens + data-attrs.
   Every value is whitelisted inside ArtDirection; nothing user-entered is
   interpolated into CSS or attributes. */
$adFields = [];
foreach (['preset', 'accent', 'secondary', 'bg', 'text', 'display', 'body', 'corner',
          'border', 'density', 'image_scale', 'rotation', 'caption', 'pullquote',
          'animation', 'transition', 'motif', 'hero', 'ending'] as $k) {
    $adFields[$k] = $page->content()->get('ad_' . $k)->value();
}
$ad = ArtDirection::resolve($adFields);
$style = ArtDirection::styleAttr($ad['vars']);

/* Legacy narrative fields still drive the story; the flexible `body` blocks
   carry the bespoke art direction. */
$story = [];
foreach (['challenge' => 'The challenge', 'approach' => 'The approach', 'strategy' => 'Strategy',
          'design_explanation' => 'Design', 'build_explanation' => 'Build', 'outcome' => 'The outcome'] as $f => $label) {
    if ($page->$f()->isNotEmpty()) {
        $story[] = ['label' => t('breakfast.project.' . $f, $label), 'field' => $f];
    }
}
$metrics = $page->metrics()->toStructure();
?>
<article class="cs" <?= ArtDirection::dataAttrs($ad['data']) ?><?= $style !== '' ? ' style="' . esc($style, 'attr') . '"' : '' ?>>
  <div class="cs__inner cs__crumbs">
    <?php if ($ttNumber !== null): ?><p class="kicker">P<?= esc($ttNumber) ?></p><?php endif ?>
    <?php snippet('partials/breadcrumbs') ?>
  </div>

  <?php snippet('case-study/hero', ['page' => $page, 'variant' => $ad['data']['hero']]) ?>

  <div class="cs__body">
    <?php /* Narrative: numbered story beats, art-directed. */ ?>
    <?php foreach ($story as $i => $s): ?>
      <section class="cs__inner cs-numbered reveal" style="--i:<?= $i ?>">
        <div class="cs-numbered__n" aria-hidden="true"><?= str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) ?></div>
        <div class="cs-text">
          <h2><?= esc($s['label']) ?></h2>
          <?= $page->{$s['field']}()->kt() ?>
        </div>
      </section>
    <?php endforeach ?>

    <?php /* Metrics → project-fact band. */ ?>
    <?php if ($metrics->isNotEmpty()): ?>
      <section class="cs__inner reveal">
        <div class="cs-facts">
          <?php foreach ($metrics as $m): ?>
            <div class="cs-facts__item">
              <div class="v"><?= esc($m->value()) ?></div>
              <div class="l"><?= esc($m->label()) ?></div>
              <?php if ($m->context()->isNotEmpty()): ?><p class="cs-cap"><?= esc($m->context()) ?></p><?php endif ?>
            </div>
          <?php endforeach ?>
        </div>
      </section>
    <?php endif ?>

    <?php /* Testimonial → art-directed pull quote (respects [data-ad-pullquote]). */ ?>
    <?php if ($page->testimonial_quote()->isNotEmpty()): ?>
      <section class="cs__inner reveal">
        <figure class="cs-quote">
          <blockquote class="cs-quote__text"><?= esc($page->testimonial_quote()) ?></blockquote>
          <figcaption class="cs-quote__cite">— <?= esc($page->testimonial_name()) ?><?php if ($page->testimonial_role()->isNotEmpty()): ?>, <?= esc($page->testimonial_role()) ?><?php endif ?></figcaption>
        </figure>
      </section>
    <?php endif ?>

    <?php /* Flexible art-directed body blocks — the bespoke layouts live here. */ ?>
    <?php if ($page->body()->isNotEmpty()): ?>
      <?= $page->body()->toBlocks() ?>
    <?php endif ?>

    <?php /* Gallery. */ ?>
    <?php if ($page->gallery()->toFiles()->isNotEmpty()): ?>
      <section class="cs__inner reveal">
        <div class="cs-strip">
          <?php foreach ($page->gallery()->toFiles() as $img): ?><figure class="cs-surface"><?= $img->crop(900, 640)->html(['alt' => esc($img->alt()), 'loading' => 'lazy']) ?></figure><?php endforeach ?>
        </div>
      </section>
    <?php endif ?>

    <?php /* Credits + tech. */ ?>
    <?php if ($page->credits()->toStructure()->isNotEmpty() || $page->technology()->isNotEmpty()): ?>
      <section class="cs__inner reveal">
        <div class="cs-facts" style="align-items:start">
          <?php if ($page->technology()->isNotEmpty()): ?>
            <div class="cs-facts__item">
              <div class="l"><?= esc(t('breakfast.tech', 'Built with')) ?></div>
              <p style="margin-top:.5rem"><?php foreach ($page->technology()->split() as $t): ?><span class="cs__tag" style="margin:.15rem .3rem .15rem 0"><?= esc($t) ?></span><?php endforeach ?></p>
            </div>
          <?php endif ?>
          <?php if ($page->credits()->toStructure()->isNotEmpty()): ?>
            <div class="cs-facts__item">
              <div class="l"><?= esc(t('breakfast.credits', 'Credits')) ?></div>
              <ul style="margin-top:.5rem"><?php foreach ($page->credits()->toStructure() as $c): ?><li><strong><?= esc($c->role()) ?>:</strong> <?= esc($c->name()) ?></li><?php endforeach ?></ul>
            </div>
          <?php endif ?>
        </div>
      </section>
    <?php endif ?>
  </div>

  <?php snippet('case-study/ending', ['page' => $page, 'variant' => $ad['data']['ending']]) ?>
</article>

<?php /* Site-wide consistency: more work + CTA band stay so a case study never
        feels like a dead end, but the ending variant above is the real close. */ ?>
<?php if ($page->related_projects()->toPages()->isNotEmpty()): ?>
<section class="section"><div class="container"><?php snippet('partials/related', ['related' => $page->related_projects()->toPages(), 'heading' => t('breakfast.relatedprojects', 'More work')]) ?></div></section>
<?php endif ?>
<section class="section"><div class="container"><?php snippet('partials/cta-band') ?></div></section>
<?php snippet('layouts/footer', ['softkeys' => [
    ['label' => 'Back',    'sub' => 'P200', 'href' => page('work') ? page('work')->url() : url('work')],
    ['label' => 'Project', 'sub' => $ttNumber !== null ? 'P' . $ttNumber : '', 'href' => $page->url()],
    ['label' => 'Next',    'sub' => '',     'href' => ($n = $page->nextListed()) ? $n->url() : url('work')],
    ['label' => 'Contact', 'sub' => 'P700', 'href' => url('contact')],
]]) ?>
