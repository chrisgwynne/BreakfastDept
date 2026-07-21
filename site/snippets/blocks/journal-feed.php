<?php /** @var \Kirby\Cms\Block $block */
$limit = $block->count()->or(3)->toInt();
$journal = page('journal');
if (!$journal) return;
$articles = $journal->children()->listed()->sortBy('date', 'desc')->limit($limit);
if ($articles->isEmpty()) return;
?>
<section class="block-journal">
  <?php if ($block->heading()->isNotEmpty()): ?><div class="section__head"><h2 class="section__title"><?= esc($block->heading()) ?></h2></div><?php endif ?>
  <div class="grid grid--3">
    <?php foreach ($articles as $article) snippet('partials/article-card', ['article' => $article]) ?>
  </div>
</section>
