<?php

use Breakfast\Platform\Support\Runtime;

/**
 * Public case study — rendered from a published, public-safe snapshot ($pf).
 * No internal record, CRM link or unapproved media is ever in scope here.
 *
 * @var array<string,mixed>       $pf
 * @var string                    $canonical
 * @var list<array<string,mixed>> $related
 * @var array<string,mixed>|null  $nextwork
 */
$e = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$pf = $pf ?? [];
$related = $related ?? [];
$nextwork = $nextwork ?? null;
$isProd = (bool) (kirby()->option('breakfast')['production'] ?? false);
$nonce = Runtime::security($isProd)->nonce();
$cover = is_array($pf['cover'] ?? null) ? $pf['cover'] : null;
$storyLabels = [
    'client' => 'The client', 'problem' => 'The problem', 'objective' => 'The objective',
    'approach' => 'The approach', 'work' => 'The work', 'outcome' => 'The outcome',
    'client_response' => 'In their words', 'next' => 'What happened next',
];
$img = static function (?array $m, string $class) use ($e): string {
    if ($m === null) {
        return '<span class="' . $e($class) . ' pcard__mock" aria-hidden="true"></span>';
    }
    if (!empty($m['src'])) {
        $dim = !empty($m['width']) ? ' width="' . (int) $m['width'] . '" height="' . (int) ($m['height'] ?? 0) . '"' : '';
        return '<img class="' . $e($class) . '" src="' . $e($m['src']) . '" alt="' . $e($m['alt'] ?? '') . '" loading="lazy" decoding="async"' . $dim . '>';
    }
    return '<span class="' . $e($class) . ' pcard__mock" aria-hidden="true"></span>';
};
?>
<?php snippet('layouts/header') ?>

<article class="section casestudy" data-test="portfolio-case">
  <div class="container">
    <nav class="breadcrumbs" aria-label="Breadcrumb"><ol>
      <li><a href="<?= esc(url()) ?>">Home</a></li>
      <li aria-hidden="true">/</li>
      <li><a href="<?= esc(url('work')) ?>">Work</a></li>
      <li aria-hidden="true">/</li>
      <li><?= $e($pf['display_title'] ?? '') ?></li>
    </ol></nav>

    <header class="case__head reveal">
      <span class="kicker"><?= $e($pf['client_display_name'] ?? '') ?><?php if (!empty($pf['industry'])): ?> · <?= $e($pf['industry']) ?><?php endif ?></span>
      <h1 class="section__title"><?= $e($pf['display_title'] ?? '') ?></h1>
      <?php if (!empty($pf['summary'])): ?><p class="section__lead"><?= $e($pf['summary']) ?></p><?php endif ?>
      <div class="case__meta">
        <?php foreach ($pf['services'] as $s): ?><span class="tag"><?= $e($s['label'] ?? '') ?></span><?php endforeach ?>
        <?php if (!empty($pf['location'])): ?><span class="case__metaitem"><?= $e($pf['location']) ?></span><?php endif ?>
        <?php if (!empty($pf['completion_date'])): ?><span class="case__metaitem"><?= $e(substr((string) $pf['completion_date'], 0, 4)) ?></span><?php endif ?>
        <?php if (!empty($pf['website_url'])): ?><a class="link-cta link-cta--sm" href="<?= $e($pf['website_url']) ?>" target="_blank" rel="noopener"><?= $e($pf['link_label'] ?? 'Visit the site') ?> <span aria-hidden="true">↗</span></a><?php endif ?>
      </div>
    </header>

    <?php if ($cover !== null): ?>
      <div class="case__cover reveal" data-pf-accent="<?= $e($pf['accent'] ?? 'butter') ?>"><?= $img($cover, 'case__coverimg') ?></div>
    <?php endif ?>

    <?php /* Structured story sections. */ ?>
    <?php if (!empty($pf['story'])): ?>
      <div class="case__story">
        <?php foreach ($pf['story'] as $sec): ?>
          <section class="case__section reveal">
            <h2 class="case__h2"><?= $e($sec['heading'] ?: ($storyLabels[$sec['key']] ?? '')) ?></h2>
            <div class="case__body prose"><?= $sec['body'] /* sanitised on input */ ?></div>
          </section>
        <?php endforeach ?>
      </div>
    <?php endif ?>

    <?php /* Modular blocks. */ ?>
    <?php foreach ($pf['blocks'] as $b): snippet('blocks/portfolio-block', ['b' => $b, 'img' => $img]); endforeach ?>

    <?php /* Results. */ ?>
    <?php if (!empty($pf['results'])): ?>
      <div class="case__results reveal">
        <h2 class="case__h2">The difference it made</h2>
        <div class="stats">
          <?php foreach ($pf['results'] as $r): ?>
            <div class="stat"><?php if (!empty($r['value'])): ?><div class="stat__value"><?= $e($r['value']) ?></div><?php endif ?><div class="stat__label"><?= $e($r['label']) ?></div><?php if (!empty($r['note'])): ?><div class="stat__context"><?= $e($r['note']) ?></div><?php endif ?></div>
          <?php endforeach ?>
        </div>
      </div>
    <?php endif ?>

    <?php /* Testimonial (only present when approved). */ ?>
    <?php if (!empty($pf['testimonial'])): $t = $pf['testimonial']; ?>
      <figure class="case__quote reveal">
        <blockquote class="quote__text"><?= $e($t['quote']) ?></blockquote>
        <figcaption class="quote__cite">— <?= $e($t['name']) ?><?php if (!empty($t['role'])): ?>, <?= $e($t['role']) ?><?php endif ?><?php if (!empty($t['company'])): ?>, <?= $e($t['company']) ?><?php endif ?></figcaption>
      </figure>
    <?php endif ?>
  </div>

  <?php /* Next project. */ ?>
  <?php if ($nextwork !== null): ?>
    <a class="case__next reveal" href="<?= $e(url('work/' . ($nextwork['slug'] ?? ''))) ?>" data-test="portfolio-next">
      <span class="container case__next-inner">
        <span class="case__next-label">Next project</span>
        <span class="case__next-title"><?= $e($nextwork['client_display_name'] ?? '') ?> — <?= $e($nextwork['card_title'] ?? $nextwork['display_title'] ?? '') ?> <span aria-hidden="true">→</span></span>
      </span>
    </a>
  <?php endif ?>

  <?php /* Related work. */ ?>
  <?php if (!empty($related)): ?>
    <div class="container" style="margin-top:var(--s-20)">
      <div class="section__head reveal"><span class="kicker">More work</span><h2 class="section__title" style="font-size:1.8rem">Related projects</h2></div>
      <div class="grid grid--3">
        <?php foreach ($related as $rel) {
            snippet('partials/portfolio-card', ['pf' => $rel]);
        } ?>
      </div>
    </div>
  <?php endif ?>

  <div class="container" style="margin-top:var(--s-20)"><?php snippet('partials/cta-band') ?></div>
</article>

<?php /* Structured data: CreativeWork + BreadcrumbList (truthful, public-only). */ ?>
<script type="application/ld+json"<?= $nonce !== '' ? ' nonce="' . $e($nonce) . '"' : '' ?>>
<?= json_encode([
    '@context' => 'https://schema.org',
    '@graph' => [
        [
            '@type' => 'CreativeWork',
            'name' => (string) ($pf['display_title'] ?? ''),
            'headline' => (string) ($pf['case_study_headline'] ?? $pf['display_title'] ?? ''),
            'abstract' => (string) ($pf['summary'] ?? ''),
            'url' => $canonical ?? url('work'),
            'dateCreated' => $pf['completion_date'] ?? null,
            'creator' => ['@type' => 'Organization', 'name' => (string) $site->title()],
            'about' => (string) ($pf['industry'] ?? ''),
        ],
        [
            '@type' => 'BreadcrumbList',
            'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => url()],
                ['@type' => 'ListItem', 'position' => 2, 'name' => 'Work', 'item' => url('work')],
                ['@type' => 'ListItem', 'position' => 3, 'name' => (string) ($pf['display_title'] ?? ''), 'item' => $canonical ?? url('work')],
            ],
        ],
    ],
], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?>
</script>

<?php snippet('layouts/footer') ?>
