<?php

use Breakfast\Platform\Seo\StructuredData;
use Breakfast\Platform\Support\Runtime;

/**
 * Document shell + <head> + site header. Paired with layouts/footer.php.
 * Presentation + template logic only — all copy comes from Kirby.
 */

$isProduction = (bool) kirby()->option('breakfast')['production'] ?? false;
$security     = Runtime::security($isProduction);
$nonce        = $security->nonce();
$analytics    = $site->analytics();

// Set real security headers before any output is flushed (Kirby buffers the
// render). Guarded so multi-render/CLI contexts don't warn.
if (headers_sent() === false) {
    foreach ($security->headers($analytics->cspConnect(), $analytics->cspScript()) as $name => $value) {
        header($name . ': ' . $value);
    }
}

$meta      = $page->seoMeta();
// Auto cache-bust: the stylesheet is served immutable, so tie the version to the
// file's modified time. Every deploy changes it, so visitors always fetch the
// current CSS instead of a stale cached copy. Falls back to a fixed version.
$assetVer  = (string) (@filemtime($kirby->root('assets') . '/css/teletext/components.css') ?: '1');
$ogImage   = $meta->ogImage();
$structured = new StructuredData($site);

// Breadcrumbs (skip home).
$crumbs = [];
foreach ($page->parents()->flip() as $parent) {
    $crumbs[] = ['name' => $parent->title()->value(), 'url' => $parent->url()];
}
if ($page->isHomePage() === false) {
    array_unshift($crumbs, ['name' => $site->title()->value(), 'url' => $site->url()]);
    $crumbs[] = ['name' => $page->title()->value(), 'url' => $page->url()];
}
?>
<!doctype html>
<html lang="<?= kirby()->language()?->code() ?? 'en' ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= esc($meta->title()) ?></title>
  <meta name="description" content="<?= esc($meta->description()) ?>">
  <meta name="google-site-verification" content="730QrZJoEiHIgYNfi3FKRh0Wk1ZJrm_WqHmKtiuA4yM" />
  <link rel="canonical" href="<?= esc($meta->canonical()) ?>">
  <meta name="robots" content="<?= esc($meta->robots()) ?>">

  <meta property="og:type" content="<?= esc($meta->ogType()) ?>">
  <meta property="og:title" content="<?= esc($page->content()->get('social_title')->or($meta->title())) ?>">
  <meta property="og:description" content="<?= esc($page->content()->get('social_description')->or($meta->description())) ?>">
  <meta property="og:url" content="<?= esc($page->url()) ?>">
  <meta property="og:site_name" content="<?= esc($site->title()) ?>">
  <meta name="twitter:title" content="<?= esc($page->content()->get('social_title')->or($meta->title())) ?>">
  <meta name="twitter:description" content="<?= esc($page->content()->get('social_description')->or($meta->description())) ?>">
  <?php if ($ogImage): ?>
  <meta property="og:image" content="<?= esc($ogImage) ?>">
  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:image" content="<?= esc($ogImage) ?>">
  <?php else: ?>
  <meta name="twitter:card" content="summary">
  <?php endif ?>

  <meta name="theme-color" content="#050505">
  <link rel="icon" href="/favicon.ico" sizes="16x16 32x32 48x48">
  <link rel="icon" href="/favicon.svg" type="image/svg+xml">
  <link rel="apple-touch-icon" href="/apple-touch-icon.png">
  <link rel="manifest" href="/site.webmanifest">
  <link rel="alternate" type="application/rss+xml" title="<?= esc($site->title()) ?> — Journal" href="<?= esc($site->url()) ?>/journal/feed.rss">
  <?php /* Preload the body font so text paints without waiting for CSS to be parsed
          first. Only Inter (body) is preloaded — the mono + handwriting faces are
          decorative and font-display:swap covers them. crossorigin is required
          even same-origin because fonts are always fetched in CORS mode. */ ?>
  <link rel="preload" href="<?= esc(url('assets/fonts/pixel-operator-mono-bold.ttf')) ?>" as="font" type="font/ttf" crossorigin>
  <link rel="stylesheet" href="<?= esc(url('assets/css/teletext/tokens.css')) ?>?v=<?= $assetVer ?>">
  <link rel="stylesheet" href="<?= esc(url('assets/css/teletext/layout.css')) ?>?v=<?= $assetVer ?>">
  <?php /* The bespoke, per-project art-directed case-study system (case-study.css)
          is kept as-is and loaded only on project pages — it already reads the
          shared --text/--font/--mono/motion tokens above, which now resolve to
          the Teletext palette, so it inherits the new system automatically
          while keeping its sophisticated per-project layout engine intact. */ ?>
  <?php if ($page->intendedTemplate()->name() === 'project'): ?>
  <?php $csVer = (string) (@filemtime($kirby->root('assets') . '/css/case-study.css') ?: $assetVer); ?>
  <link rel="stylesheet" href="<?= esc(url('assets/css/case-study.css')) ?>?v=<?= $csVer ?>">
  <?php endif ?>
  <?php /* Components load last so the site-wide Teletext transmission rules
          remain authoritative over optional project art direction. */ ?>
  <link rel="stylesheet" href="<?= esc(url('assets/css/teletext/components.css')) ?>?v=<?= $assetVer ?>">

  <?= StructuredData::toScript($structured->business()) ?>
  <?= StructuredData::toScript($structured->website()) ?>
  <?php if (count($crumbs) > 1): ?>
  <?= StructuredData::toScript($structured->breadcrumbs($crumbs)) ?>
  <?php endif ?>
  <?php if ($page->intendedTemplate()->name() === 'article'): ?>
  <?= StructuredData::toScript($structured->article($page)) ?>
  <?php elseif ($page->intendedTemplate()->name() === 'project'): ?>
  <?= StructuredData::toScript($structured->creativeWork($page)) ?>
  <?php endif ?>

  <?php /* Analytics: cookieless providers load immediately; GA4 waits for consent (see footer). */ ?>
  <?php if ($analytics->enabled() && $analytics->requiresConsent() === false): ?>
    <?= $analytics->script($nonce) ?>
  <?php endif ?>
</head>
<body class="page-<?= esc($page->intendedTemplate()->name(), 'attr') ?><?= get('tt') === '1' ? ' tt-arrival' : '' ?>">
  <a class="skip-link" href="#main"><?= esc(t('breakfast.skip', 'Skip to main content')) ?></a>

  <?php if ($page->isHomePage() === false): ?>
  <div class="tt-page-viewport">
    <div class="tt-page-holder" data-tt-page-holder>
      <div class="tt-page-stage" data-tt-page-stage>
  <?php endif ?>

  <?php if ($site->announcement_enabled()->toBool() && $site->announcement_text()->isNotEmpty()): ?>
  <div class="announcement" role="region" aria-label="Announcement"><?= esc($site->announcement_text()) ?></div>
  <?php endif ?>

  <?php snippet('layouts/nav') ?>

  <main id="main" class="tt-transmission">
