<?php
/**
 * @var \Kirby\Cms\Pages $related
 * @var string $heading
 */
if (!isset($related) || $related->isEmpty()) return;
?>
<section class="block-related" style="margin-top:var(--s-16)">
  <div class="section__head"><h2 class="section__title" style="font-size:1.8rem"><?= esc($heading ?? 'Related') ?></h2></div>
  <div class="grid grid--3">
    <?php foreach ($related->limit(3) as $p): ?>
      <?php if ($p->intendedTemplate()->name() === 'article'): snippet('partials/article-card', ['article' => $p]); else: snippet('partials/project-card', ['project' => $p]); endif ?>
    <?php endforeach ?>
  </div>
</section>
