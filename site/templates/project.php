<?php

use Breakfast\Platform\Teletext\Registry;
use Breakfast\Platform\Content\ArtDirection;

snippet('layouts/header');

$ttNumber = Registry::numberFor($page, $site);
$blocks = $page->body()->toBlocks();
$art = ArtDirection::resolve([
    'preset' => $page->ad_preset()->value(),
    'accent' => $page->ad_accent()->value(),
    'secondary' => $page->ad_secondary()->value(),
    'bg' => $page->ad_bg()->value(),
    'text' => $page->ad_text()->value(),
    'display' => $page->ad_display()->value(),
    'body' => $page->ad_body()->value(),
    'density' => $page->ad_density()->value(),
    'corner' => $page->ad_corner()->value(),
    'border' => $page->ad_border()->value(),
    'image_scale' => $page->ad_image_scale()->value(),
    'rotation' => $page->ad_rotation()->value(),
    'caption' => $page->ad_caption()->value(),
    'pullquote' => $page->ad_pullquote()->value(),
    'animation' => $page->ad_animation()->value(),
    'transition' => $page->ad_transition()->value(),
    'motif' => $page->ad_motif()->value(),
    'hero' => $page->ad_hero()->value(),
    'ending' => $page->ad_ending()->value(),
]);
$hasArtDirection = $page->ad_preset()->isNotEmpty() && $blocks->count() > 0;
$heroImg = $page->hero_image()->toFile();
$isConcept = $page->project_status()->value() === 'concept';
$story = [];
foreach ([
    'challenge' => 'THE CHALLENGE',
    'approach' => 'THE APPROACH',
    'strategy' => 'STRATEGY',
    'design_explanation' => 'DESIGN',
    'build_explanation' => 'BUILD',
    'outcome' => 'THE OUTCOME',
] as $field => $label) {
    if ($page->$field()->isNotEmpty()) $story[] = [$field, $label];
}
?>

<?php if ($hasArtDirection): ?>
<article class="cs" style="<?= esc(ArtDirection::styleAttr($art['vars']), 'attr') ?>" <?= ArtDirection::dataAttrs($art['data']) ?> aria-labelledby="case-study-heading">
  <?php snippet('case-study/hero', ['page' => $page, 'variant' => $art['data']['hero']]) ?>
  <div class="cs__body" id="case-study-heading"><?= $blocks ?></div>
  <?php snippet('case-study/ending', ['page' => $page, 'variant' => $art['data']['ending']]) ?>
</article>
<?php else: ?>

<article class="tt-project" aria-labelledby="project-heading">
  <div class="container">
    <?php snippet('teletext/bar', ['number' => $ttNumber !== null ? 'P' . $ttNumber : '', 'title' => 'PROJECT REPORT', 'sub' => '1/—', 'as' => 'h1', 'id' => 'project-heading']) ?>
    <div class="tt-project__hero">
      <div>
        <p class="tt-project__kicker"><?= esc($page->client()->or($page->project_status())) ?><?php if ($page->industries()->isNotEmpty()): ?> · <?= esc($page->industries()->split()[0] ?? '') ?><?php endif ?></p>
        <h2 class="tt-project__title"><?= esc($page->title()) ?></h2>
        <?php if ($page->summary()->isNotEmpty()): ?><p class="tt-project__summary"><?= esc($page->summary()) ?></p><?php endif ?>
        <?php if ($isConcept): ?><p class="tt-project__note">CONCEPT — A WORKED EXAMPLE</p><?php elseif ($page->confidential()->toBool(false)): ?><p class="tt-project__note">DETAILS ANONYMISED AT CLIENT REQUEST</p><?php endif ?>
      </div>
      <?php if ($heroImg): ?><figure class="tt-project__hero-image"><?= $heroImg->crop(608, 300)->html(['alt' => esc($heroImg->alt()->or($page->title())), 'fetchpriority' => 'high']) ?></figure><?php endif ?>
    </div>

    <?php if ($story): ?>
    <div class="tt-project__story">
      <?php foreach ($story as $i => [$field, $label]): ?>
      <section class="tt-project__section">
        <h2><b><?= str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) ?></b><?= esc($label) ?></h2>
        <div class="tt-project__prose"><?= $page->$field()->kt() ?></div>
      </section>
      <?php endforeach ?>
    </div>
    <?php endif ?>

    <?php if ($page->metrics()->toStructure()->isNotEmpty()): ?>
    <section class="tt-project__section">
      <h2><b>++</b>RESULTS</h2>
      <div class="tt-project__facts">
        <?php foreach ($page->metrics()->toStructure() as $m): ?><div><strong><?= esc($m->value()) ?></strong><span><?= esc($m->label()) ?></span><?php if ($m->context()->isNotEmpty()): ?><small><?= esc($m->context()) ?></small><?php endif ?></div><?php endforeach ?>
      </div>
    </section>
    <?php endif ?>

    <?php if ($page->testimonial_quote()->isNotEmpty()): ?>
    <blockquote class="tt-project__quote">“<?= esc($page->testimonial_quote()) ?>”<cite>— <?= esc($page->testimonial_name()) ?><?php if ($page->testimonial_role()->isNotEmpty()): ?>, <?= esc($page->testimonial_role()) ?><?php endif ?></cite></blockquote>
    <?php endif ?>

    <?php if ($page->gallery()->toFiles()->isNotEmpty()): ?>
    <section class="tt-project__section">
      <h2><b>++</b>SCREENSHOTS</h2>
      <div class="tt-project__gallery"><?php foreach ($page->gallery()->toFiles() as $img): ?><?= $img->crop(296, 190)->html(['alt' => esc($img->alt()->or($page->title())), 'loading' => 'lazy']) ?><?php endforeach ?></div>
    </section>
    <?php endif ?>

    <?php if ($page->technology()->isNotEmpty() || $page->credits()->toStructure()->isNotEmpty()): ?>
    <section class="tt-project__section tt-project__meta">
      <?php if ($page->technology()->isNotEmpty()): ?><div><h2><b>++</b>BUILT WITH</h2><p><?= esc(implode(' · ', $page->technology()->split())) ?></p></div><?php endif ?>
      <?php if ($page->credits()->toStructure()->isNotEmpty()): ?><div><h2><b>++</b>CREDITS</h2><ul><?php foreach ($page->credits()->toStructure() as $c): ?><li><?= esc($c->role()) ?>: <?= esc($c->name()) ?></li><?php endforeach ?></ul></div><?php endif ?>
    </section>
    <?php endif ?>

    <div class="tt-project__end">
      <?php if ($page->project_url()->isNotEmpty()): ?><a class="btn btn--secondary" href="<?= esc($page->project_url()) ?>" target="_blank" rel="noopener">VISIT THE SITE ↗</a><?php endif ?>
      <p><?= esc($page->outcome()->or($page->summary())->or('A considered piece of work, transmitted in full.')) ?></p>
    </div>
  </div>
</article>

<?php if ($page->related_projects()->toPages()->isNotEmpty()): ?><section class="section"><div class="container"><?php snippet('partials/related', ['related' => $page->related_projects()->toPages(), 'heading' => t('breakfast.relatedprojects', 'More work')]) ?></div></section><?php endif ?>
<section class="section"><div class="container"><?php snippet('partials/cta-band') ?></div></section>
<?php endif ?>
<?php snippet('layouts/footer', ['softkeys' => [
    ['label' => 'Back', 'sub' => 'P200', 'href' => page('work') ? page('work')->url() : url('work')],
    ['label' => 'Project', 'sub' => $ttNumber !== null ? 'P' . $ttNumber : '', 'href' => $page->url()],
    ['label' => 'Next', 'sub' => '', 'href' => ($n = $page->nextListed()) ? $n->url() : url('work')],
    ['label' => 'Contact', 'sub' => 'P700', 'href' => url('contact')],
]]) ?>
