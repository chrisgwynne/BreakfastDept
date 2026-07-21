<?php
/** Accessible pagination. @var \Kirby\Cms\Pagination $pagination */
if (!isset($pagination) || $pagination->pages() <= 1) return;
?>
<nav class="pagination" aria-label="Pagination">
  <?php if ($pagination->hasPrevPage()): ?>
    <a rel="prev" href="<?= esc($pagination->prevPageURL()) ?>" aria-label="<?= esc(t('breakfast.prev', 'Previous page')) ?>">←</a>
  <?php else: ?>
    <span aria-hidden="true" style="opacity:.4">←</span>
  <?php endif ?>
  <?php foreach ($pagination->range(5) as $p): ?>
    <?php if ($p === $pagination->page()): ?>
      <span aria-current="page"><?= $p ?></span>
    <?php else: ?>
      <a href="<?= esc($pagination->pageURL($p)) ?>"><?= $p ?></a>
    <?php endif ?>
  <?php endforeach ?>
  <?php if ($pagination->hasNextPage()): ?>
    <a rel="next" href="<?= esc($pagination->nextPageURL()) ?>" aria-label="<?= esc(t('breakfast.next', 'Next page')) ?>">→</a>
  <?php else: ?>
    <span aria-hidden="true" style="opacity:.4">→</span>
  <?php endif ?>
</nav>
