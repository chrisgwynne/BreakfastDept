<?php

/**
 * A numbered index list — the core Teletext navigation primitive. Used on
 * P100 (the main menu) and any other page that wants a "pick a number" list.
 *
 * @var array<int,array{number:int|string,label:string,href:string,status?:string}> $items
 */
$items = $items ?? [];
?>
<nav class="tt-index" aria-label="Page index">
  <?php foreach ($items as $item): ?>
    <a class="tt-index__row" href="<?= esc($item['href']) ?>">
      <span class="tt-index__num"><?= esc($item['number']) ?></span>
      <span class="tt-index__label"><?= esc($item['label']) ?></span>
      <?php if (!empty($item['status'])): ?><span class="tt-index__status"><?= esc($item['status']) ?></span><?php endif ?>
    </a>
  <?php endforeach ?>
</nav>
