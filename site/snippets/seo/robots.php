<?php
/** robots.txt — allow crawling of public content, disallow private areas. */
?>
User-agent: *
Allow: /
Disallow: /api/
Disallow: /admin/
Disallow: /breakfast-admin/
Disallow: /kirby/
Disallow: /site/
Disallow: /content/
Disallow: /vendor/
Disallow: /storage/
Disallow: /*?*
<?php /* The admin (Panel) slug is intentionally NOT listed here: it is private
        and sends its own noindex headers. Listing it would only advertise it. */ ?>

Sitemap: <?= esc(site()->url()) ?>/sitemap.xml
