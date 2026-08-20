<?php

use Breakfast\Platform\Teletext\Registry;

/** @var \Kirby\Cms\Page $article */
$cover = $article->cover()->toFile();
$ttNumber = Registry::numberFor($article, $article->site());
?>
<article class="pcard reveal" data-filter-item data-tags="<?= esc(implode(' ', array_map(fn ($c) => \Kirby\Toolkit\Str::slug($c), $article->categories()->split()))) ?>">
  <?php if ($cover): ?>
    <a class="pcard__media" href="<?= esc($article->url()) ?>"><?= $cover->crop(900, 560)->html(['alt' => esc($cover->alt()->or($article->title())), 'loading' => 'lazy']) ?></a>
  <?php endif ?>
  <div class="pcard__body">
    <p class="pcard__cat">
      <?php if ($ttNumber !== null): ?>P<?= esc($ttNumber) ?> · <?php endif ?>
      <?php if ($article->date()->isNotEmpty()): ?><?= esc($article->date()->toDate('j M Y')) ?><?php endif ?>
      <?php if ($article->categories()->isNotEmpty()): ?> · <?= esc($article->categories()->split()[0] ?? '') ?><?php endif ?>
    </p>
    <h3 class="pcard__name"><a href="<?= esc($article->url()) ?>"><?= esc($article->title()) ?></a></h3>
    <?php if ($article->excerpt()->isNotEmpty()): ?><p class="pcard__summary"><?= esc($article->excerpt()->excerptSafe(140)) ?></p><?php endif ?>
    <a class="link-cta pcard__link" href="<?= esc($article->url()) ?>"><?= esc(t('breakfast.readmore', 'Read the article')) ?> <span aria-hidden="true">→</span></a>
  </div>
</article>
