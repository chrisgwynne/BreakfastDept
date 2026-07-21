<?php
/**
 * Loads a consent-dependent analytics provider (GA4) only AFTER consent.
 * The script is nonce'd to satisfy the CSP; no analytics runs until the visitor
 * has actively accepted. Cookieless providers never reach this snippet.
 *
 * @var string $nonce
 * @var \Breakfast\Platform\Analytics\Analytics $analytics
 */
$snippet = str_replace(['<script', '</script>'], '', $analytics->script($nonce));
?>
<script nonce="<?= esc($nonce) ?>">
(function () {
  function load() {
    if (window.__bfAnalytics) return;
    window.__bfAnalytics = true;
    <?= $snippet /* provider bootstrap; contains no user input */ ?>
  }
  var ok = null;
  try { ok = localStorage.getItem("bf-consent"); } catch (e) {}
  if (ok === "accept") load();
  document.addEventListener("bf:consent-granted", load);
})();
</script>
