<?php

use Breakfast\Platform\Teletext\Registry;

/**
 * @var \Kirby\Cms\Pages $related
 * @var string $heading
 */
if (!isset($related) || $related->isEmpty()) return;
?>
<section class="block-related" style="margin-top:var(--s-16)">
  <div class="section__head"><h2 class="section__title" style="font-size:1.8rem"><?= esc($heading ?? 'Related') ?></h2></div>
  <div class="tt-list">
    <?php foreach ($related->limit(3) as $p): ?>
      <?php
        $num = Registry::numberFor($p, $p->site());
        $isArticle = $p->intendedTemplate()->name() === 'article';
      ?>
      <a class="tt-list__row" href="<?= esc($p->url()) ?>">
        <span class="tt-list__num"><?= $num !== null ? esc($num) : '' ?></span>
        <span class="tt-list__body">
          <span class="tt-list__title"><?= esc($p->title()) ?></span>
          <span class="tt-list__meta"><?= esc($isArticle ? $p->date()->toDate('j M Y') : $p->client()->value()) ?></span>
        </span>
      </a>
    <?php endforeach ?>
  </div>
</section>
