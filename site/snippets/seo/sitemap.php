<?php
/** XML sitemap. Excludes drafts, unlisted, noindex and opted-out pages. */
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
<?php foreach (site()->index()->listed() as $item): ?>
  <?php
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
    <?php if ($item->isHomePage()): ?><priority>1.0</priority><?php endif ?>
  </url>
<?php endforeach ?>
</urlset>
