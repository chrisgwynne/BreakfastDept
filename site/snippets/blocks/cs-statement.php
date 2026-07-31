<?php
/** @var \Kirby\Cms\Block $block */
if ($block->text()->isEmpty()) { return; }
$align = $block->align()->value() === 'center' ? ' cs-statement--center' : '';
$kind = $block->kind()->value();
$labels = ['challenge' => 'The challenge', 'strategy' => 'The strategy', 'improvement' => 'The biggest improvement', 'lesson' => 'A project lesson'];
// Accent *starred* phrases; everything is escaped first, then <em> wraps the accent.
$safe = esc($block->text());
$safe = preg_replace('/\*(.+?)\*/', '<em>$1</em>', $safe);
?>
<section class="cs__inner reveal">
  <?php if (isset($labels[$kind])): ?><p class="cs-cap" style="margin-bottom:1rem"><?= esc($labels[$kind]) ?></p><?php endif ?>
  <p class="cs-statement<?= $align ?>"><?= $safe ?></p>
</section>
