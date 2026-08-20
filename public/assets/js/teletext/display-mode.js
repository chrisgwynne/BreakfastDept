/**
 * CLEAN / CRT display toggle. Purely cosmetic (scanline overlay in CSS);
 * choice is remembered locally and never sent anywhere. Defaults to clean,
 * and reduced-motion users never see the (motion-free) overlay's flicker
 * variants because those are separately gated in CSS.
 */
(function () {
  "use strict";

  var KEY = "tt-display-mode";
  var root = document.documentElement;
  var buttons = document.querySelectorAll("[data-tt-display]");
  if (buttons.length === 0) return;

  function apply(mode) {
    root.setAttribute("data-display", mode);
    buttons.forEach(function (btn) {
      btn.setAttribute("aria-pressed", String(btn.getAttribute("data-tt-display") === mode));
    });
  }

  var stored = "clean";
  try {
    stored = window.localStorage.getItem(KEY) || "clean";
  } catch {
    stored = "clean";
  }
  apply(stored);

  buttons.forEach(function (btn) {
    btn.addEventListener("click", function () {
      var mode = btn.getAttribute("data-tt-display");
      apply(mode);
      try {
        window.localStorage.setItem(KEY, mode);
      } catch {
        /* localStorage unavailable (private mode etc.) — mode still applies this visit. */
      }
    });
  });
})();
