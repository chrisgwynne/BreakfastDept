<?php
/**
 * Reusable final CTA band. Falls back to global site CTA settings.
 * @var \Kirby\Content\Field|string|null $heading
 * @var \Kirby\Content\Field|string|null $text
 */
$heading = $heading ?? $site->cta_heading();
$text    = $text ?? $site->cta_text();
$primaryLabel = $site->cta_primary_label()->or('Start a project');
$primaryLink  = $primaryLink ?? $site->cta_primary_link()->or(url('start-a-project'));
$secondaryLabel = $site->cta_secondary_label();
$secondaryLink  = $site->cta_secondary_link()->or(url('contact'));
$trackLabel = $trackLabel ?? 'cta-band';
?>
<div class="cta-band reveal">
  <h2 class="cta-band__title"><?= esc($heading) ?></h2>
  <?php if ((string) $text !== ''): ?><p class="cta-band__text"><?= esc($text) ?></p><?php endif ?>
  <div class="cta-band__actions">
    <a class="btn btn--primary btn--lg" data-track="cta_click" data-track-label="<?= esc($trackLabel, 'attr') ?>" href="<?= esc($primaryLink) ?>"><?= esc($primaryLabel) ?></a>
    <?php if ((string) $secondaryLabel !== ''): ?>
      <a class="btn btn--ghost btn--lg" href="<?= esc($secondaryLink) ?>"><?= esc($secondaryLabel) ?></a>
    <?php endif ?>
  </div>
</div>
