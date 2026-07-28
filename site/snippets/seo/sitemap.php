<?php
/** XML sitemap. Excludes drafts, unlisted, noindex and opted-out pages. */
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
<?php
  // The home page is unlisted by Kirby convention, so it is NOT in
  // ->index()->listed(). Emit it explicitly and first, with the highest
  // priority — otherwise the single most important URL is missing entirely.
  $home = site()->homePage();
?>
<?php if ($home && $home->content()->get('no_index')->toBool(false) === false): ?>
  <url>
    <loc><?= esc($home->url()) ?></loc>
    <lastmod><?= date('c', $home->modified()) ?></lastmod>
    <priority>1.0</priority>
  </url>
<?php endif ?>
<?php foreach (site()->index()->listed() as $item): ?>
  <?php
    if ($item->isHomePage()) {
        continue; // already emitted above
    }
    if ($item->content()->get('no_index')->toBool(false)) {
        continue;
    }
    if ($item->content()->get('sitemap_include')->isNotEmpty() && $item->content()->get('sitemap_include')->toBool(true) === false) {
        continue;
    }
  ?>
  <url>
    <loc><?= esc($item->url()) ?></loc>
    <lastmod><?= date('c', $item->modified()) ?></lastmod>
  </url>
<?php endforeach ?>
</urlset>
