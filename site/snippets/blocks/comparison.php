<?php /** @var \Kirby\Cms\Block $block */
$before = $block->before()->toFile();
$after = $block->after()->toFile();
if (!$before || !$after) return;
?>
<div class="comparison">
  <figure><?= $before->crop(700, 500)->html(['alt' => esc($block->beforelabel()->or('Before')), 'loading' => 'lazy']) ?><figcaption><?= esc($block->beforelabel()->or('before')) ?></figcaption></figure>
  <figure><?= $after->crop(700, 500)->html(['alt' => esc($block->afterlabel()->or('After')), 'loading' => 'lazy']) ?><figcaption><?= esc($block->afterlabel()->or('after')) ?></figcaption></figure>
</div>
