<?php

/**
 * The solid-blue page title bar — the defining structural device of every
 * information page. Use instead of an eyebrow+h1 pair.
 *
 * @var string $number  e.g. "P200"
 * @var string $title   e.g. "SELECTED WORK"
 * @var string|null $sub  e.g. "1/2" — optional subpage/progress counter
 * @var bool $tight  smaller variant for nested/repeated bars
 */
$number = $number ?? '';
$title  = $title ?? '';
$sub    = $sub ?? null;
$tight  = $tight ?? false;
?>
<div class="tt-bar<?= $tight ? ' tt-bar--tight' : '' ?>">
  <span class="tt-bar__num"><?= esc($number) ?></span>
  <span class="tt-bar__title"><?= esc($title) ?></span>
  <?php if ($sub !== null && $sub !== ''): ?><span class="tt-bar__sub"><?= esc($sub) ?></span><?php endif ?>
</div>
