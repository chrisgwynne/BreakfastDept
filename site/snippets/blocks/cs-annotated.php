<?php
/** @var \Kirby\Cms\Block $block */
$img = $block->image()->toFile();
$notes = $block->notes()->toStructure();
if (!$img) { return; }
?>
<section class="cs__inner reveal">
  <figure class="cs-surface"><?= $img->crop(1600, 1000)->html(['alt' => esc($img->alt()->or('Annotated screenshot')), 'loading' => 'lazy']) ?></figure>
  <?php if ($notes->isNotEmpty()): ?>
    <ol class="cs-annot__notes" style="margin-top:1rem;display:grid;gap:.6rem">
      <?php $n = 0; foreach ($notes as $note): $n++; ?>
        <li class="cs-annot__note"><b><?= $n ?></b> <span><?= esc($note->label()) ?></span></li>
      <?php endforeach ?>
    </ol>
  <?php endif ?>
</section>
