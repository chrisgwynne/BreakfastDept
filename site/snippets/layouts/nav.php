<?php

use Breakfast\Platform\Teletext\Registry;

/** Compact broadcast masthead used by every transmitted page. */
$currentNumber = Registry::displayNumberFor($page, $site);
$now = new DateTime('now', new DateTimeZone('Europe/London'));
?>
<header class="site-header">
  <div class="tt-masthead">
    <span class="tt-masthead__page"><?= $currentNumber !== null ? 'P' . esc($currentNumber) . '/01' : 'P---/01' ?></span>
    <span class="tt-masthead__brand"><?= esc($site->title()->or('Breakfast')) ?></span>
    <span class="tt-masthead__clock"><b><?= $currentNumber !== null ? esc($currentNumber) : '---' ?></b>&nbsp;<?= esc(strtoupper($now->format('d M'))) ?>&nbsp;<time data-tt-clock data-live="true"><?= esc($now->format('H:i:s')) ?></time></span>
  </div>
</header>

<div class="tt-site-brandbar" aria-label="Current Breakfast Text page">
  <strong>BREAKFAST TEXT</strong>
  <span><?= esc(strtoupper($page->title())) ?></span>
  <b><?= $currentNumber !== null ? 'P' . esc($currentNumber) : 'P---' ?></b>
</div>
