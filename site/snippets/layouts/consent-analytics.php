<?php
/**
 * Loads a consent-dependent analytics provider (GA4) only AFTER consent.
 * The script is nonce'd to satisfy the CSP; no analytics runs until the visitor
 * has actively accepted. Cookieless providers never reach this snippet.
 *
 * @var string $nonce
 * @var \Breakfast\Platform\Analytics\Analytics $analytics
 */
$measurementId = json_encode(
    $analytics->site(),
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR
);
?>
<script nonce="<?= esc($nonce) ?>">
(function () {
  function load() {
    if (window.__bfAnalytics) return;
    var id = <?= $measurementId ?>;
    if (!/^G-[A-Z0-9]+$/.test(id)) return;
    window.__bfAnalytics = true;
    var script = document.createElement("script");
    script.async = true;
    script.src = "https://www.googletagmanager.com/gtag/js?id=" + encodeURIComponent(id);
    document.head.appendChild(script);
    window.dataLayer = window.dataLayer || [];
    window.gtag = function () { window.dataLayer.push(arguments); };
    window.gtag("js", new Date());
    window.gtag("config", id, {anonymize_ip: true});
  }
  var ok = null;
  try { ok = localStorage.getItem("bf-consent"); } catch (e) {}
  if (ok === "accept") load();
  document.addEventListener("bf:consent-granted", load);
})();
</script>
