<?php
/**
 * Project preview card. Never renders editor-entered HTML — only structured
 * fields, escaped. Uses responsive Kirby image generation.
 * @var \Kirby\Cms\Page $project
 */
$card = $project->card_image()->toFile() ?? $project->hero_image()->toFile();
$services = $project->services()->toPages();
$isConcept = $project->project_status()->value() === 'concept';
$tags = [];
foreach ($services as $s) { $tags[] = $s->slug(); }
foreach ($project->industries()->split() as $ind) { $tags[] = \Kirby\Toolkit\Str::slug($ind); }
?>
<article class="pcard reveal" data-filter-item data-tags="<?= esc(implode(' ', $tags)) ?>">
  <a class="pcard__media" href="<?= esc($project->url()) ?>" aria-label="<?= esc($project->title()) ?>">
    <?php if ($isConcept): ?><span class="pcard__badge">Concept website</span><?php endif ?>
    <?php if ($card): ?>
      <?= $card->crop(900, 560)->html(['alt' => esc($card->alt()->or($project->title())), 'loading' => 'lazy', 'sizes' => '(max-width: 640px) 100vw, 33vw']) ?>
    <?php endif ?>
  </a>
  <div class="pcard__body">
    <p class="pcard__cat"><?= esc($project->client()->or($services->first()?->title())) ?><?php if ($project->industries()->isNotEmpty()): ?> · <?= esc($project->industries()->split()[0] ?? '') ?><?php endif ?></p>
    <h3 class="pcard__name"><a href="<?= esc($project->url()) ?>"><?= esc($project->title()) ?></a></h3>
    <?php if ($project->summary()->isNotEmpty()): ?>
      <p class="pcard__summary"><?= esc($project->summary()->excerptSafe(140)) ?></p>
    <?php endif ?>
    <?php if ($project->outcome()->isNotEmpty()): ?>
      <p class="pcard__outcome"><?= esc($project->outcome()->excerptSafe(90)) ?></p>
    <?php endif ?>
    <a class="link-cta pcard__link" href="<?= esc($project->url()) ?>"><?= $isConcept ? 'See the concept' : esc(t('breakfast.readcase', 'Read the case study')) ?> <span aria-hidden="true">→</span></a>
  </div>
</article>
