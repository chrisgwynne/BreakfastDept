<?php /** @var \Kirby\Cms\Block $block */
$url = $block->url()->esc();
if (!$url) return;
// Only allow known providers; output is an iframe to a validated embed URL.
$src = null;
if (preg_match('~youtube\.com/watch\?v=([\w-]+)~', $url, $m) || preg_match('~youtu\.be/([\w-]+)~', $url, $m)) {
  $src = 'https://www.youtube-nocookie.com/embed/' . $m[1];
} elseif (preg_match('~vimeo\.com/(\d+)~', $url, $m)) {
  $src = 'https://player.vimeo.com/video/' . $m[1];
}
if (!$src) return;
?>
<div class="embed"><iframe src="<?= esc($src) ?>" title="<?= esc($block->caption()->or('Video')) ?>" loading="lazy" allowfullscreen referrerpolicy="no-referrer"></iframe></div>
