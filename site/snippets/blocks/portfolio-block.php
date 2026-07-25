<?php
/**
 * Renders one modular case-study block from a public snapshot. Only known types
 * render; content is public-safe and escaped. Media come pre-filtered to
 * approved public items. No arbitrary HTML/scripts are ever emitted.
 *
 * @var array<string,mixed> $b    block: type, content, options, media
 * @var callable            $img  (?array $media, string $class): string
 */
$e = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$type = (string) ($b['type'] ?? '');
$c = is_array($b['content'] ?? null) ? $b['content'] : [];
$media = is_array($b['media'] ?? null) ? $b['media'] : [];
$m0 = $media[0] ?? null;
$m1 = $media[1] ?? null;
$text = static fn (string $k): string => htmlspecialchars((string) ($c[$k] ?? ''), ENT_QUOTES, 'UTF-8');
?>
<?php switch ($type):
    case 'full_image':
    case 'browser_mock': ?>
    <figure class="pfblock pfblock--full reveal <?= $type === 'browser_mock' ? 'pfblock--browser' : '' ?>">
      <?= $img($m0, 'pfblock__img') ?>
      <?php if (!empty($c['caption'])): ?><figcaption class="pfblock__cap"><?= $text('caption') ?></figcaption><?php endif ?>
    </figure>
    <?php break; ?>

  <?php case 'mobile_mock': ?>
    <figure class="pfblock pfblock--mobile reveal"><span class="pfblock__phone"><?= $img($m0, 'pfblock__img') ?></span></figure>
    <?php break; ?>

  <?php case 'device_pair': ?>
    <div class="pfblock pfblock--pair reveal"><span class="pfblock__desktop"><?= $img($m0, 'pfblock__img') ?></span><span class="pfblock__phone"><?= $img($m1 ?? $m0, 'pfblock__img') ?></span></div>
    <?php break; ?>

  <?php case 'before_after': ?>
    <div class="pfblock pfblock--ba reveal">
      <figure><?= $img($m0, 'pfblock__img') ?><figcaption class="pfblock__cap">Before</figcaption></figure>
      <figure><?= $img($m1, 'pfblock__img') ?><figcaption class="pfblock__cap">After</figcaption></figure>
    </div>
    <?php break; ?>

  <?php case 'image_grid':
      case 'carousel': ?>
    <div class="pfblock pfblock--grid reveal"><?php foreach ($media as $m) {
        echo $img($m, 'pfblock__img');
    } ?></div>
    <?php break; ?>

  <?php case 'text_image': ?>
    <div class="pfblock pfblock--textimg reveal <?= (($c['flip'] ?? false)) ? 'is-flipped' : '' ?>">
      <div class="pfblock__text"><?php if (!empty($c['heading'])): ?><h2 class="case__h2"><?= $text('heading') ?></h2><?php endif ?><p><?= $text('text') ?></p></div>
      <div class="pfblock__media"><?= $img($m0, 'pfblock__img') ?></div>
    </div>
    <?php break; ?>

  <?php case 'challenge':
      case 'solution': ?>
    <section class="pfblock pfblock--prose reveal">
      <h2 class="case__h2"><?= $e($c['heading'] ?? ($type === 'challenge' ? 'The challenge' : 'The solution')) ?></h2>
      <p><?= $text('text') ?></p>
    </section>
    <?php break; ?>

  <?php case 'quote': ?>
    <figure class="pfblock pfblock--quote reveal"><blockquote class="quote__text"><?= $text('text') ?></blockquote><?php if (!empty($c['cite'])): ?><figcaption class="quote__cite">— <?= $text('cite') ?></figcaption><?php endif ?></figure>
    <?php break; ?>

  <?php case 'colour_panel': ?>
    <div class="pfblock pfblock--panel reveal" style="--panel:<?= $e(preg_match('/^#[0-9a-fA-F]{3,8}$/', (string) ($c['colour'] ?? '')) ? $c['colour'] : 'var(--secondary)') ?>">
      <p class="pfblock__paneltext"><?= $text('text') ?></p>
    </div>
    <?php break; ?>

  <?php case 'stats': ?>
    <div class="pfblock reveal"><div class="stats"><?php foreach (($c['items'] ?? []) as $s): ?><div class="stat"><div class="stat__value"><?= $e($s['value'] ?? '') ?></div><div class="stat__label"><?= $e($s['label'] ?? '') ?></div></div><?php endforeach ?></div></div>
    <?php break; ?>

  <?php case 'caption': ?>
    <p class="pfblock pfblock--caption reveal"><?= $text('text') ?></p>
    <?php break; ?>

  <?php case 'website_link': ?>
    <p class="pfblock reveal"><a class="btn btn--secondary" href="<?= $e(filter_var($c['url'] ?? '', FILTER_VALIDATE_URL) ?: '#') ?>" target="_blank" rel="noopener"><?= $e($c['label'] ?? 'Visit the site') ?></a></p>
    <?php break; ?>

  <?php case 'spacer': ?>
    <div class="pfblock" style="height:<?= (int) max(8, min(160, (int) ($c['height'] ?? 48))) ?>px" aria-hidden="true"></div>
    <?php break; ?>

  <?php default: ?>
    <?php if ($m0 !== null): ?><figure class="pfblock pfblock--full reveal"><?= $img($m0, 'pfblock__img') ?></figure><?php endif ?>
<?php endswitch ?>
