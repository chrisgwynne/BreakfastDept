<?php

/**
 * The solid-blue page title bar — the defining structural device of every
 * information page. Use instead of an eyebrow+h1 pair.
 *
 * The title renders as a real heading element (h1/h2/h3) so every page
 * keeps a proper semantic heading structure for SEO/accessibility — pass
 * $as to control the level, and $id to give it an anchor (e.g. for an
 * aria-labelledby on the surrounding <section>). Pass $as = false when a
 * separate, real <h1> already exists elsewhere on the page (e.g. a bar
 * used only as a small category label above the real title).
 *
 * @var string $number  e.g. "P200"
 * @var string $title   e.g. "SELECTED WORK"
 * @var string|null $sub  e.g. "1/2" — optional subpage/progress counter
 * @var bool $tight  smaller variant for nested/repeated bars
 * @var string|false $as  heading tag: 'h1' (default), 'h2', 'h3', or false for no heading tag
 * @var string|null $id  id attribute on the title element
 */
$number = $number ?? '';
$title  = $title ?? '';
$sub    = $sub ?? null;
$tight  = $tight ?? false;
$as     = $as ?? 'h1';
$id     = $id ?? null;
$idAttr = $id !== null ? ' id="' . esc($id, 'attr') . '"' : '';
$allowedTags = ['h1', 'h2', 'h3'];
$tag = in_array($as, $allowedTags, true) ? $as : null;
?>
<div class="tt-bar<?= $tight ? ' tt-bar--tight' : '' ?>">
  <span class="tt-bar__num"><?= esc($number) ?></span>
  <?php if ($tag !== null): ?>
    <<?= $tag ?> class="tt-bar__title"<?= $idAttr ?>><?= esc($title) ?></<?= $tag ?>>
  <?php else: ?>
    <span class="tt-bar__title"<?= $idAttr ?>><?= esc($title) ?></span>
  <?php endif ?>
  <?php if ($sub !== null && $sub !== ''): ?><span class="tt-bar__sub"><?= esc($sub) ?></span><?php endif ?>
</div>
