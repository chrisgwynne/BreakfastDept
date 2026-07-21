<?php /** @var \Kirby\Cms\Block $block */
$level = $block->level()->or('h2')->value();
$tag = in_array($level, ['h2', 'h3', 'h4'], true) ? $level : 'h2';
$id = \Kirby\Toolkit\Str::slug((string) $block->text());
?>
<<?= $tag ?> id="<?= esc($id) ?>" class="block-heading"><?= esc($block->text()) ?></<?= $tag ?>>
