<?php

use Breakfast\Platform\Teletext\Registry;

snippet('layouts/header');
$ttNumber = Registry::numberFor($page, $site);
?>
<article class="section">
  <div class="container">
    <?php snippet('partials/breadcrumbs') ?>
    <header class="article__header">
      <?php if ($ttNumber !== null): ?><p class="kicker">P<?= esc($ttNumber) ?></p><?php endif ?>
      <h1 class="section__title"><?= esc($page->title()) ?></h1>
      <?php if ($page->excerpt()->isNotEmpty()): ?><p class="section__lead"><?= esc($page->excerpt()) ?></p><?php endif ?>
      <div class="article__meta">
        <?php if ($page->author()->isNotEmpty()): ?><span><?= esc($page->author()) ?></span><?php endif ?>
        <?php if ($page->date()->isNotEmpty()): ?><time datetime="<?= esc($page->date()->toDate('c')) ?>"><?= esc($page->date()->toDate('j F Y')) ?></time><?php endif ?>
        <span><?= $page->readingTime() ?> <?= esc(t('breakfast.minread', 'min read')) ?></span>
      </div>
    </header>

    <?php if ($cover = $page->cover()->toFile()): ?>
      <div class="article__cover container--prose" style="margin-inline:auto"><?= $cover->crop(1200, 675)->html(['alt' => esc($cover->alt()->or($page->title())), 'fetchpriority' => 'high']) ?></div>
    <?php endif ?>

    <div class="container--prose" style="margin-inline:auto">
      <?php if ($page->toc_enabled()->toBool(false)): ?>
        <?php snippet('partials/toc', ['page' => $page]) ?>
      <?php endif ?>
      <div class="blocks"><?= $page->body()->toBlocks() ?></div>

      <?php if ($page->tags()->isNotEmpty()): ?>
        <p style="margin-top:var(--s-12)"><?php foreach ($page->tags()->split() as $tag): ?><span class="tag"><?= esc($tag) ?></span> <?php endforeach ?></p>
      <?php endif ?>
    </div>

    <?php snippet('partials/related', ['related' => $page->related_articles()->toPages()->merge($page->related_projects()->toPages()), 'heading' => t('breakfast.readnext', 'Read next')]) ?>
  </div>
</article>
<section class="section"><div class="container">
  <?php snippet('partials/cta-band', ['heading' => $page->cta_heading()->or($site->cta_heading()), 'text' => $page->cta_text()->or($site->cta_text())]) ?>
</div></section>
<?php snippet('layouts/footer', ['softkeys' => [
    ['label' => 'Back',    'sub' => 'P600', 'href' => page('journal') ? page('journal')->url() : url('journal')],
    ['label' => 'Work',    'sub' => 'P200', 'href' => page('work') ? page('work')->url() : url('work')],
    ['label' => 'Start',   'sub' => 'P101', 'href' => url('start-a-project')],
    ['label' => 'Contact', 'sub' => 'P700', 'href' => url('contact')],
]]) ?>
