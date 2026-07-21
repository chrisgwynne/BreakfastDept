<?php
/** robots.txt — allow crawling of public content, disallow private areas. */
$isProduction = (bool) (kirby()->option('breakfast')['production'] ?? false);
?>
User-agent: *
<?php if ($isProduction): ?>
Allow: /
Disallow: /api/
Disallow: /admin/
Disallow: /*?*
<?php /* The admin (Panel) slug is intentionally NOT listed here: it is private
        and sends its own noindex headers. Listing it would only advertise it. */ ?>
<?php else: ?>
Disallow: /
<?php endif ?>

Sitemap: <?= esc(site()->url()) ?>/sitemap.xml
