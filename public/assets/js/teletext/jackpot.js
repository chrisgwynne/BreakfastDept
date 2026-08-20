/**
 * P777 — a tiny Teletext fruit-machine. Three original Breakfast symbols;
 * three matches is the jackpot. No real prize, no server round-trip —
 * this is a discovery reward, not a promotion mechanism.
 */
(function () {
  "use strict";

  var root = document.querySelector("[data-tt-jackpot]");
  if (!root) return;

  var reels = root.querySelectorAll("[data-tt-reel]");
  var spinBtn = root.querySelector("[data-tt-spin]");
  var resultEl = root.querySelector("[data-tt-jackpot-result]");
  var symbols = ["EGG", "MUG", "MON", "SUN"];

  function spin() {
    spinBtn.setAttribute("aria-disabled", "true");
    var picks = reels.length ? [] : [];
    var ticks = 0;
    var interval = setInterval(function () {
      picks = [];
      reels.forEach(function (reel) {
        var pick = symbols[Math.floor(Math.random() * symbols.length)];
        reel.textContent = pick;
        picks.push(pick);
      });
      ticks++;
      if (ticks > 12) {
        clearInterval(interval);
        spinBtn.removeAttribute("aria-disabled");
        var won = picks.every(function (p) { return p === picks[0]; });
        resultEl.textContent = won
          ? "JACKPOT — YOU HAVE WON THE SATISFACTION OF FINDING P777."
          : "NO MATCH. TRY AGAIN.";
      }
    }, 80);
  }

  if (spinBtn) spinBtn.addEventListener("click", spin);
})();
