/* Breakfast — case-study progressive enhancement.
   Loaded only on project pages. Everything degrades to a usable static layout
   without JS. Transform/opacity only, reduced-motion respected, no scroll
   hijacking, mobile simplified. */
(function () {
  "use strict";
  var root = document.querySelector(".cs");
  if (!root) { return; }

  var reduce = false;
  try { reduce = window.matchMedia("(prefers-reduced-motion: reduce)").matches; } catch (e) {}
  var narrow = false;
  try { narrow = window.matchMedia("(max-width: 760px)").matches; } catch (e) {}

  /* ---- Before / after slider ---- */
  document.querySelectorAll("[data-cs-ba]").forEach(function (el) {
    var range = el.querySelector("[data-cs-ba-range]");
    var after = el.querySelector("[data-cs-ba-after]");
    var handle = el.querySelector(".cs-baslider__handle");
    if (!range || !after) { return; }
    var apply = function () {
      var v = Math.max(0, Math.min(100, Number(range.value) || 50));
      after.style.width = v + "%";
      if (handle) { handle.style.left = v + "%"; }
    };
    range.addEventListener("input", apply);
    range.setAttribute("aria-valuetext", "Reveal position");
    apply();
  });

  /* ---- Full-page slow pan (skip on reduced motion / narrow) ---- */
  if (!reduce && !narrow && "IntersectionObserver" in window) {
    document.querySelectorAll("[data-cs-pan]").forEach(function (frame) {
      var img = frame.querySelector("img");
      if (!img) { return; }
      var active = false;
      var io = new IntersectionObserver(function (ents) {
        ents.forEach(function (en) { active = en.isIntersecting; });
      });
      io.observe(frame);
      var tick = function () {
        if (active) {
          var rect = frame.getBoundingClientRect();
          var vh = window.innerHeight || 1;
          var progress = 1 - (rect.top + rect.height / 2) / vh; // ~ -0.5..1.5
          progress = Math.max(0, Math.min(1, progress));
          var travel = Math.max(0, img.offsetHeight - frame.offsetHeight);
          img.style.transform = "translateY(" + (-progress * travel).toFixed(1) + "px)";
        }
        window.requestAnimationFrame(tick);
      };
      window.requestAnimationFrame(tick);
    });
  }

  /* ---- Parallax-lite: gentle, bounded drift on oversized media ---- */
  if (!reduce && !narrow && root.getAttribute("data-ad-anim") === "parallax-lite") {
    var pars = root.querySelectorAll(".cs-oversized .cs-surface, .cs-fullbleed figure");
    if (pars.length) {
      var onScroll = function () {
        var vh = window.innerHeight || 1;
        pars.forEach(function (p) {
          var rect = p.getBoundingClientRect();
          var center = rect.top + rect.height / 2;
          var off = (center / vh - 0.5) * -28; // capped ±14px
          p.style.transform = "translateY(" + off.toFixed(1) + "px)";
        });
        raf = 0;
      };
      var raf = 0;
      window.addEventListener("scroll", function () {
        if (!raf) { raf = window.requestAnimationFrame(onScroll); }
      }, { passive: true });
      onScroll();
    }
  }

  /* ---- Horizontal strip: arrow-key scrolling when focused ---- */
  document.querySelectorAll(".cs-strip").forEach(function (strip) {
    strip.addEventListener("keydown", function (e) {
      if (e.key !== "ArrowRight" && e.key !== "ArrowLeft") { return; }
      e.preventDefault();
      var step = strip.clientWidth * 0.8 * (e.key === "ArrowRight" ? 1 : -1);
      strip.scrollBy({ left: step, behavior: reduce ? "auto" : "smooth" });
    });
  });
})();
