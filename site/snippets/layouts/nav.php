<?php

use Breakfast\Platform\Teletext\Registry;

/**
 * The masthead — page number / brand / live clock. This is the ENTIRE
 * persistent chrome; there is deliberately no logo+menu bar underneath it.
 * Navigation is the in-page index, typed page numbers, and the soft-key
 * strip in the footer — the authentic Teletext navigation model, not a
 * conventional site nav bar wearing Teletext colours.
 */
$currentNumber = Registry::displayNumberFor($page, $site);
$now = new DateTime('now', new DateTimeZone('Europe/London'));
?>
<header class="site-header">
  <div class="tt-masthead">
    <span class="tt-masthead__page"><?= $currentNumber !== null ? 'P' . esc($currentNumber) : 'P—' ?></span>
    <span class="tt-masthead__brand"><?= esc($site->title()->or('Breakfast')) ?> TEXT</span>
    <span class="tt-masthead__clock" data-tt-clock data-live="true"><?= esc(strtoupper($now->format('D d M'))) ?>&nbsp;&nbsp;<?= esc($now->format('H:i:s')) ?></span>
  </div>
</header>
