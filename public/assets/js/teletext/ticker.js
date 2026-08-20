/**
 * Slow-rotating footer ticker. Messages come from the element's own
 * data-tt-messages (JSON array, editable in Kirby — see the footer snippet),
 * so copy stays in content, not hardcoded here. No marquee, no continuous
 * motion — one message swaps for the next every 8s, instantly.
 */
(function () {
  "use strict";

  var el = document.querySelector("[data-tt-ticker]");
  if (!el) return;

  var messages = [];
  try {
    messages = JSON.parse(el.getAttribute("data-tt-messages") || "[]");
  } catch (e) {
    messages = [];
  }
  if (messages.length < 2) return;

  var i = 0;
  var reduced = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
  if (reduced) return;

  setInterval(function () {
    i = (i + 1) % messages.length;
    el.textContent = messages[i];
  }, 8000);
})();
