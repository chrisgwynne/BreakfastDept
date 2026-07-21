<?php
/**
 * Table of contents built from heading blocks in the article body.
 * @var \Kirby\Cms\Page $page
 */
$headings = [];
foreach ($page->body()->toBlocks() as $block) {
    if ($block->type() === 'heading') {
        $text = (string) $block->text();
        if ($text !== '') {
            $headings[] = ['id' => \Kirby\Toolkit\Str::slug($text), 'text' => $text];
        }
    }
}
if ($headings === []) return;
?>
<nav class="toc" aria-label="<?= esc(t('breakfast.toc', 'On this page')) ?>">
  <strong><?= esc(t('breakfast.toc', 'On this page')) ?></strong>
  <ol style="margin-top:var(--s-2);list-style:decimal;padding-left:var(--s-6)">
    <?php foreach ($headings as $h): ?>
      <li><a href="#<?= esc($h['id']) ?>"><?= esc($h['text']) ?></a></li>
    <?php endforeach ?>
  </ol>
</nav>
