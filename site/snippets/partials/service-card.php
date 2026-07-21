<?php
/** @var \Kirby\Cms\Page $service */
?>
<article class="scard reveal">
  <span class="scard__icon" aria-hidden="true">
    <svg viewBox="0 0 24 24"><path d="M4 5h16v11H4zM8 20h8M12 16v4"/></svg>
  </span>
  <h3 class="scard__title"><a href="<?= esc($service->url()) ?>"><?= esc($service->title()) ?></a></h3>
  <?php if ($service->summary()->isNotEmpty()): ?>
    <p class="pcard__summary"><?= esc($service->summary()->excerptSafe(160)) ?></p>
  <?php endif ?>
  <a class="link-cta" href="<?= esc($service->url()) ?>"><?= esc(t('breakfast.learnmore', 'Learn more')) ?> <span aria-hidden="true">→</span></a>
</article>
