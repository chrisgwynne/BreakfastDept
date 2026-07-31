<?php
/**
 * Case-study ending — distinct closes so no two case studies end the same way.
 * @var \Kirby\Cms\Page $page
 * @var string $variant  resolved ending variant (whitelisted upstream)
 */
$url     = $page->project_url();
$heroImg = $page->hero_image()->toFile();
$visit   = function () use ($url, $page): void {
    if ($url->isNotEmpty()): ?>
      <a class="cs__cta reveal" data-track="external_project_link" href="<?= esc($url) ?>" rel="noopener" target="_blank"><?= esc(t('breakfast.visit', 'Visit the site')) ?> ↗</a>
    <?php endif;
};
?>
<footer class="cs__end cs__end--<?= esc($variant) ?>">
<?php if ($variant === 'giant-shot' && $heroImg): ?>
  <div class="cs-fullbleed reveal"><?= $heroImg->crop(2200, 1100)->html(['alt' => esc($heroImg->alt()->or($page->title())), 'loading' => 'lazy']) ?></div>
  <div class="cs__inner" style="text-align:center;padding-block:var(--cs-space)"><?php $visit() ?></div>

<?php elseif ($variant === 'testimonial' && $page->testimonial_quote()->isNotEmpty()): ?>
  <div class="cs__inner" style="padding-block:var(--cs-space);text-align:center">
    <blockquote class="cs-statement cs-statement--center reveal"><?= esc($page->testimonial_quote()) ?></blockquote>
    <p class="cs-cap reveal" style="margin-top:1.5rem">— <?= esc($page->testimonial_name()) ?><?php if ($page->testimonial_role()->isNotEmpty()): ?>, <?= esc($page->testimonial_role()) ?><?php endif ?></p>
  </div>

<?php elseif ($variant === 'results' && $page->metrics()->toStructure()->isNotEmpty()): ?>
  <div class="cs__inner" style="padding-block:var(--cs-space)">
    <div class="cs-facts reveal">
      <?php foreach ($page->metrics()->toStructure() as $m): ?>
        <div class="cs-facts__item"><div class="v"><?= esc($m->value()) ?></div><div class="l"><?= esc($m->label()) ?></div></div>
      <?php endforeach ?>
    </div>
    <?php $visit() ?>
  </div>

<?php elseif ($variant === 'lessons' && $page->outcome()->isNotEmpty()): ?>
  <div class="cs__inner" style="padding-block:var(--cs-space)">
    <div class="cs-text cs-text--center reveal"><?= $page->outcome()->kt() ?></div>
    <div style="text-align:center"><?php $visit() ?></div>
  </div>

<?php elseif ($variant === 'mobile-row' && $page->gallery()->toFiles()->isNotEmpty()): ?>
  <div class="cs__inner" style="padding-block:var(--cs-space)">
    <div class="cs-strip reveal">
      <?php foreach ($page->gallery()->toFiles()->limit(5) as $img): ?><figure class="cs-surface"><?= $img->crop(600, 1000)->html(['alt' => esc($img->alt()), 'loading' => 'lazy']) ?></figure><?php endforeach ?>
    </div>
    <div style="text-align:center"><?php $visit() ?></div>
  </div>

<?php elseif ($variant === 'motif'): ?>
  <div class="cs-colourblock reveal" style="text-align:center">
    <div class="cs__inner">
      <p class="cs-statement cs-statement--center"><?= esc($page->title()) ?></p>
      <?php $visit() ?>
    </div>
  </div>

<?php else: /* visit (default) */ ?>
  <div class="cs__inner" style="text-align:center;padding-block:var(--cs-space)">
    <p class="cs-statement cs-statement--center reveal" style="margin-bottom:1rem"><?= esc($page->summary()->or($page->title())) ?></p>
    <?php $visit() ?>
  </div>
<?php endif ?>
</footer>
