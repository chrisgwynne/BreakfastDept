<?php
/**
 * Service card for the Services index — built for decisions, not just a link:
 * summary + who it's for + a timescale cue, then a clear way in.
 *
 * @var \Kirby\Cms\Page $service
 */
$icons = ['new website' => 'globe', 'single page' => 'spark', 'online shop' => 'cart', 'website rescue' => 'wrench', 'ongoing support' => 'egg'];
$icon  = $icons[strtolower((string) $service->short_name()->or($service->title())->value())] ?? 'spark';
?>
<article class="scard reveal">
  <span class="scard__icon" aria-hidden="true"><?php snippet('partials/icon', ['name' => $icon]) ?></span>
  <h3 class="scard__title"><a href="<?= esc($service->url()) ?>"><?= esc($service->title()) ?></a></h3>
  <?php if ($service->summary()->isNotEmpty()): ?>
    <p class="scard__summary"><?= esc($service->summary()->excerptSafe(150)) ?></p>
  <?php endif ?>
  <?php if ($service->suitable_for()->isNotEmpty()): ?>
    <p class="scard__aud">For: <?= esc($service->suitable_for()->excerptSafe(72)) ?></p>
  <?php endif ?>
  <?php if ($service->timescale()->isNotEmpty()): ?>
    <p class="scard__meta"><span class="scard__meta-dot" aria-hidden="true"></span> <?= esc($service->timescale()->excerptSafe(48)) ?></p>
  <?php endif ?>
  <a class="link-cta" href="<?= esc($service->url()) ?>"><?= esc(t('breakfast.learnmore', 'What’s involved')) ?> <span aria-hidden="true">→</span></a>
</article>
