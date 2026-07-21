<?php /** @var \Kirby\Cms\Block $block */ ?>
<figure class="quote">
  <blockquote class="quote__text"><?= esc($block->text()) ?></blockquote>
  <?php if ($block->citation()->isNotEmpty()): ?>
    <figcaption class="quote__cite">— <?= esc($block->citation()) ?></figcaption>
  <?php endif ?>
</figure>
