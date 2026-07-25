<?php
/**
 * Public portfolio card — renders from a published snapshot payload (public-safe
 * data only). Prioritises the visual, then client/project name, a concise
 * outcome, a service label and the link.
 *
 * @var array<string,mixed> $pf   snapshot payload
 */
$e = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$slug = (string) ($pf['slug'] ?? '');
$cover = is_array($pf['cover'] ?? null) ? $pf['cover'] : null;
$src = $cover['src'] ?? null;
$alt = $cover['alt'] ?? '';
$service = $pf['services'][0]['label'] ?? ($pf['project_type'] ?? '');
?>
<a class="pcard reveal worklink" href="<?= $e(url('work/' . $slug)) ?>" data-test="portfolio-card">
  <span class="pcard__media">
    <?php if ($src): ?>
      <img src="<?= $e($src) ?>" alt="<?= $e($alt) ?>" loading="lazy" decoding="async"<?php if (!empty($cover['width'])): ?> width="<?= (int) $cover['width'] ?>" height="<?= (int) ($cover['height'] ?? 0) ?>"<?php endif ?>>
    <?php else: ?>
      <span class="pcard__mock" aria-hidden="true"><span class="pcard__mock-bar"></span><span class="pcard__mock-line"></span><span class="pcard__mock-line pcard__mock-line--sm"></span></span>
    <?php endif ?>
  </span>
  <span class="pcard__body">
    <?php if (!empty($service)): ?><span class="pcard__cat"><?= $e($service) ?></span><?php endif ?>
    <span class="pcard__name"><?= $e($pf['client_display_name'] ?? '') ?><?php if (!empty($pf['display_title'])): ?> — <?= $e($pf['card_title'] ?? $pf['display_title']) ?><?php endif ?></span>
    <?php if (!empty($pf['summary'])): ?><span class="pcard__summary"><?= $e($pf['summary']) ?></span><?php endif ?>
    <span class="pcard__link link-cta">View project <span aria-hidden="true">→</span></span>
  </span>
</a>
