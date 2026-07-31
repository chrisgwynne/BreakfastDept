<?php
/** @var \Kirby\Cms\Block $block */
$before = $block->before()->toFile();
$after  = $block->after()->toFile();
if (!$before || !$after) { return; }
$labels = array_map('trim', explode(',', (string) $block->labels()->or('Before,After')));
$lb = esc($labels[0] ?? 'Before');
$la = esc($labels[1] ?? 'After');
?>
<section class="cs__inner reveal">
<?php if ($block->mode()->value() === 'slider'): ?>
  <div class="cs-baslider" data-cs-ba>
    <figure class="cs-baslider__before"><?= $before->crop(1400, 900)->html(['alt' => $lb . ' — previous design', 'loading' => 'lazy']) ?></figure>
    <figure class="cs-baslider__after" data-cs-ba-after><?= $after->crop(1400, 900)->html(['alt' => $la . ' — new design', 'loading' => 'lazy']) ?></figure>
    <span class="cs-baslider__handle" aria-hidden="true"></span>
    <input class="cs-baslider__range" type="range" min="0" max="100" value="50"
           aria-label="Reveal <?= $lb ?> versus <?= $la ?>" data-cs-ba-range>
  </div>
  <p class="cs-cap"><span class="cs-ba__label"><?= $lb ?></span> &nbsp;⟷&nbsp; <span class="cs-ba__label"><?= $la ?></span></p>
<?php else: ?>
  <div class="cs-ba">
    <figure><span class="cs-ba__label"><?= $lb ?></span><?= $before->crop(1000, 720)->html(['alt' => $lb . ' — previous design', 'loading' => 'lazy']) ?></figure>
    <figure><span class="cs-ba__label"><?= $la ?></span><?= $after->crop(1000, 720)->html(['alt' => $la . ' — new design', 'loading' => 'lazy']) ?></figure>
  </div>
<?php endif ?>
</section>
