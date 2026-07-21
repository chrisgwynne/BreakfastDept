<?php /** @var \Kirby\Cms\Block $block */
$projects = $block->projects()->toPages();
if ($projects->isEmpty() && ($work = page('work'))) {
  $projects = $work->children()->listed()->filterBy('featured', true);
  if ($projects->isEmpty()) $projects = $work->children()->listed()->limit(3);
}
if ($projects->isEmpty()) return;
?>
<section class="block-project-grid">
  <?php if ($block->heading()->isNotEmpty()): ?><div class="section__head"><h2 class="section__title"><?= esc($block->heading()) ?></h2></div><?php endif ?>
  <div class="grid grid--3">
    <?php foreach ($projects as $project) snippet('partials/project-card', ['project' => $project]) ?>
  </div>
</section>
