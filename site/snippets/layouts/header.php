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
$assetVer  = '6';
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

  <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'%3E%3Ccircle cx='50' cy='50' r='42' fill='%23FBFBF9' stroke='%231C293C' stroke-width='6'/%3E%3Ccircle cx='50' cy='50' r='16' fill='%23FDC800'/%3E%3C/svg%3E">
  <link rel="alternate" type="application/rss+xml" title="<?= esc($site->title()) ?> — Journal" href="<?= esc($site->url()) ?>/journal/feed.rss">
  <link rel="stylesheet" href="<?= esc(url('assets/css/app.css')) ?>?v=<?= $assetVer ?>">

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
<body>
  <a class="skip-link" href="#main"><?= esc(t('breakfast.skip', 'Skip to main content')) ?></a>

  <?php if ($site->announcement_enabled()->toBool() && $site->announcement_text()->isNotEmpty()): ?>
  <div class="announcement" role="region" aria-label="Announcement"><?= esc($site->announcement_text()) ?></div>
  <?php endif ?>

  <?php snippet('layouts/nav') ?>

  <main id="main">
