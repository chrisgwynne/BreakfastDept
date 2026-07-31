<?php
/**
 * Case-study hero — six genuinely distinct compositions.
 * @var \Kirby\Cms\Page $page
 * @var string $variant  resolved hero variant (whitelisted upstream)
 */
$heroImg  = $page->hero_image()->toFile();
$gallery  = $page->gallery()->toFiles();
$isConcept = $page->project_status()->value() === 'concept';
$kicker   = trim(($isConcept ? 'Concept · ' : '') . $page->client()->value());
$ind      = $page->industries()->isNotEmpty() ? ($page->industries()->split()[0] ?? '') : '';

$titleBlock = function () use ($page, $kicker, $ind, $isConcept): void { ?>
  <p class="cs__kicker reveal"><?= esc($kicker) ?><?php if ($ind): ?> · <?= esc($ind) ?><?php endif ?></p>
  <h1 class="cs__title reveal"><?= esc($page->title()) ?></h1>
  <?php if ($page->summary()->isNotEmpty()): ?><p class="cs__lead reveal"><?= esc($page->summary()) ?></p><?php endif ?>
  <?php if ($isConcept): ?><span class="cs__tag reveal">Concept — a worked example, not a client project</span>
  <?php elseif ($page->confidential()->toBool(false)): ?><span class="cs__tag reveal"><?= esc(t('breakfast.confidential', 'Details anonymised at the client’s request')) ?></span><?php endif ?>
<?php };

// Collect up to four images for multi-image heroes (gallery first, hero as lead).
$shots = [];
if ($heroImg) { $shots[] = $heroImg; }
foreach ($gallery as $g) { if (count($shots) >= 4) { break; } $shots[] = $g; }
?>
<header class="cs__hero cs__hero--<?= esc($variant) ?>">
<?php if ($variant === 'cinematic' && $heroImg): ?>
  <div class="cs__hero-media"><?= $heroImg->crop(2000, 1200)->html(['alt' => esc($heroImg->alt()->or($page->title())), 'fetchpriority' => 'high']) ?></div>
  <div class="cs__hero-copy"><?php $titleBlock() ?></div>

<?php elseif ($variant === 'layered-devices'): ?>
  <div class="cs__inner">
    <div class="cs__hero-copy"><?php $titleBlock() ?></div>
    <div class="cs__devices">
      <?php if ($heroImg): ?><figure class="cs-surface reveal"><?= $heroImg->crop(1400, 900)->html(['alt' => esc($heroImg->alt()->or($page->title())), 'fetchpriority' => 'high']) ?></figure><?php endif ?>
      <?php if (isset($shots[1])): ?><figure class="cs__device-mobile reveal" style="width:34%;margin-left:auto;margin-top:-14%;transform:rotate(var(--cs-rot))"><?= $shots[1]->crop(600, 1000)->html(['alt' => esc($shots[1]->alt()->or('Mobile view'))]) ?></figure><?php endif ?>
    </div>
  </div>

<?php elseif ($variant === 'collage'): ?>
  <div class="cs__inner">
    <?php $titleBlock() ?>
    <?php if (count($shots)): ?>
    <div class="cs__collage" aria-hidden="false">
      <?php foreach (array_slice($shots, 0, 4) as $s): ?><figure class="cs-surface reveal"><?= $s->crop(900, 640)->html(['alt' => esc($s->alt()->or($page->title())), 'loading' => 'lazy']) ?></figure><?php endforeach ?>
    </div>
    <?php endif ?>
  </div>

<?php elseif ($variant === 'split'): ?>
  <div class="cs__inner">
    <div class="cs__hero-copy"><?php $titleBlock() ?></div>
    <?php if ($heroImg): ?><figure class="cs__hero-media reveal"><?= $heroImg->crop(1200, 1400)->html(['alt' => esc($heroImg->alt()->or($page->title())), 'fetchpriority' => 'high']) ?></figure><?php endif ?>
  </div>

<?php elseif ($variant === 'minimal'): ?>
  <div class="cs__inner">
    <?php $titleBlock() ?>
    <?php if ($heroImg): ?><figure class="cs__hero-media reveal"><?= $heroImg->crop(1800, 1000)->html(['alt' => esc($heroImg->alt()->or($page->title())), 'fetchpriority' => 'high']) ?></figure><?php endif ?>
  </div>

<?php else: /* editorial (default) */ ?>
  <div class="cs__inner">
    <?php $titleBlock() ?>
    <?php if ($heroImg): ?><figure class="cs__hero-media cs-surface reveal"><?= $heroImg->crop(1600, 900)->html(['alt' => esc($heroImg->alt()->or($page->title())), 'fetchpriority' => 'high']) ?></figure><?php endif ?>
  </div>
<?php endif ?>
</header>
