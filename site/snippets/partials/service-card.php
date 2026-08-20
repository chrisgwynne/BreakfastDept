<?php

use Breakfast\Platform\Teletext\Registry;

/**
 * Service card for the Services index — built for decisions, not just a link:
 * summary + fit + concrete inclusions, then a direct enquiry path.
 *
 * @var \Kirby\Cms\Page $service
 * @var int $offerNumber
 */
$icons = ['new website' => 'globe', 'single page' => 'spark', 'online shop' => 'cart', 'website rescue' => 'wrench', 'ongoing support' => 'egg'];
$icon  = $icons[strtolower((string) $service->short_name()->or($service->title())->value())] ?? 'spark';
$offerNumber = $offerNumber ?? 1;
$ttNumber = Registry::numberFor($service, $service->site());
$enquiryUrl = url('start-a-project') . '?service=' . rawurlencode($service->slug()) . '#form';
?>
<article class="scard scard--offer reveal">
  <div class="scard__top">
    <span class="scard__icon" aria-hidden="true"><?php snippet('partials/icon', ['name' => $icon]) ?></span>
    <span class="scard__package"><?= $ttNumber !== null ? 'P' . esc($ttNumber) : 'Option ' . str_pad((string) $offerNumber, 2, '0', STR_PAD_LEFT) ?></span>
  </div>
  <h2 class="scard__title"><a href="<?= esc($service->url()) ?>"><?= esc($service->title()) ?></a></h2>
  <?php if ($service->summary()->isNotEmpty()): ?>
    <p class="scard__summary"><?= esc($service->summary()) ?></p>
  <?php endif ?>
  <?php if ($service->suitable_for()->isNotEmpty()): ?>
    <p class="scard__aud"><strong>Best for:</strong> <?= esc($service->suitable_for()->excerptSafe(130)) ?></p>
  <?php endif ?>
  <?php $deliverables = $service->deliverables()->toStructure()->limit(3); if ($deliverables->isNotEmpty()): ?>
    <div class="scard__includes">
      <h3>What’s included</h3>
      <ul>
        <?php foreach ($deliverables as $deliverable): ?><li><?= esc($deliverable->text()) ?></li><?php endforeach ?>
      </ul>
    </div>
  <?php endif ?>
  <div class="scard__actions">
    <a class="btn btn--secondary" data-track="cta_click" data-track-label="service-<?= esc($service->slug()) ?>" href="<?= esc($enquiryUrl) ?>">Ask about this option</a>
    <a class="link-cta" href="<?= esc($service->url()) ?>"><?= esc(t('breakfast.learnmore', 'See how it works')) ?> <span aria-hidden="true">→</span></a>
  </div>
</article>
