<?php
/**
 * Layered composition — a bounded, art-directed arrangement of screenshots,
 * crops, frames, shapes, annotations and captions. The stored JSON is ALWAYS
 * re-sanitised here (never trusted), so a hand-edited content file cannot smuggle
 * raw CSS/coordinates past the bounds. Positioning is a 12×8 grid; scale,
 * rotation and opacity are clamped. The canvas is overflow-contained, so a
 * composition can never escape its section or cause horizontal overflow.
 *
 * @var \Kirby\Cms\Block $block
 */
use Breakfast\Platform\Composition\CompositionSanitizer;

$raw = trim((string) $block->data());
$decoded = $raw !== '' ? json_decode($raw, true) : null;
if (!is_array($decoded)) {
    return;
}
$comp = CompositionSanitizer::sanitize($decoded);
if ($comp['layers'] === []) {
    return;
}
$page = $block->parent();
$ar   = str_replace(':', '/', $comp['aspect']);
// Annotations whose text must also live in the reading order (accessibility).
$notes = [];
?>
<section class="cs__inner reveal">
  <div class="cs-comp" style="--comp-ar:<?= esc($ar, 'attr') ?>">
    <?php foreach ($comp['layers'] as $i => $l):
        $vars = CompositionSanitizer::layerVars($l, $comp['responsive']['tablet'][$l['id']] ?? [], $comp['responsive']['mobile'][$l['id']] ?? []);
        $hide = [];
        if (!($l['visible']['desktop'] ?? true)) { $hide[] = 'hd'; }
        if (!($l['visible']['tablet'] ?? true)) { $hide[] = 'ht'; }
        if (!($l['visible']['mobile'] ?? true)) { $hide[] = 'hm'; }
        $cls = 'cs-comp__layer cs-comp__layer--' . $l['type']
            . ' shadow-' . $l['shadow'] . ' border-' . $l['border']
            . ($l['frame'] !== 'none' ? ' frame-' . $l['frame'] : '')
            . ($hide ? ' ' . implode(' ', $hide) : '');
        $file = $l['image'] !== '' && $page ? $page->image($l['image']) : null;
    ?>
      <div class="<?= esc($cls, 'attr') ?>" style="<?= esc($vars, 'attr') ?>;--i:<?= (int) $l['reveal'] ?>">
        <?php if (in_array($l['type'], ['desktop-shot', 'mobile-shot', 'browser-frame', 'crop'], true) && $file): ?>
          <?php if ($l['frame'] === 'browser'): ?><span class="cs-comp__chrome" aria-hidden="true"></span><?php endif ?>
          <?= $file->crop(1400, 900)->html(['alt' => esc($file->alt()->or('Screenshot')), 'loading' => 'lazy']) ?>
        <?php elseif ($l['type'] === 'caption'): ?>
          <p class="cs-comp__caption"><?= esc($l['text']) ?></p>
        <?php elseif ($l['type'] === 'annotation'): ?>
          <?php $notes[] = $l['text']; $n = count($notes); ?>
          <span class="cs-comp__marker" aria-hidden="true"><?= $n ?></span>
        <?php elseif ($l['type'] === 'shape'): ?>
          <span class="cs-comp__shape cs-comp__shape--<?= esc($l['shape'], 'attr') ?>" aria-hidden="true"></span>
        <?php endif ?>
      </div>
    <?php endforeach ?>
  </div>
  <?php if ($block->caption()->isNotEmpty()): ?><p class="cs-cap"><?= esc($block->caption()) ?></p><?php endif ?>
  <?php if ($notes !== []): /* annotation text in the reading order for a11y */ ?>
    <ol class="cs-comp__notes">
      <?php foreach ($notes as $ni => $t): ?><li><b><?= $ni + 1 ?></b> <span><?= esc($t) ?></span></li><?php endforeach ?>
    </ol>
  <?php endif ?>
</section>
