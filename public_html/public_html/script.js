/* =========================================================
   Breakfast — interactions
   - Accessible mobile menu (toggle, Escape-to-close,
     click-outside, focus management)
   - Sticky CTA bar that appears after the hero
   - Subtle scroll-reveal (progressive enhancement)
   - Auto year in footer
   ========================================================= */
(function () {
  "use strict";

  // Mark that JS is available so reveal styles only apply when we can undo them.
  document.documentElement.classList.add("js");

  /* ---------- Mobile menu ---------- */
  var nav = document.querySelector(".nav");
  var toggle = document.getElementById("navToggle");
  var menu = document.getElementById("navMenu");

  if (nav && toggle && menu) {
    var mqDesktop = window.matchMedia("(min-width: 821px)");

    function isOpen() {
      return nav.getAttribute("data-open") === "true";
    }
    function openMenu() {
      nav.setAttribute("data-open", "true");
      toggle.setAttribute("aria-expanded", "true");
    }
    function closeMenu(restoreFocus) {
      nav.setAttribute("data-open", "false");
      toggle.setAttribute("aria-expanded", "false");
      if (restoreFocus) toggle.focus();
    }

    toggle.addEventListener("click", function () {
      isOpen() ? closeMenu(false) : openMenu();
    });
    menu.addEventListener("click", function (event) {
      var link = event.target.closest("a");
      if (link && isOpen()) closeMenu(false);
    });
    document.addEventListener("keydown", function (event) {
      if (event.key === "Escape" && isOpen()) closeMenu(true);
    });
    document.addEventListener("click", function (event) {
      if (isOpen() && !nav.contains(event.target)) closeMenu(false);
    });
    function handleViewportChange(event) {
      if (event.matches) closeMenu(false);
    }
    if (mqDesktop.addEventListener) {
      mqDesktop.addEventListener("change", handleViewportChange);
    } else if (mqDesktop.addListener) {
      mqDesktop.addListener(handleViewportChange);
    }
  }

  /* ---------- Sticky CTA ---------- */
  var stickyCta = document.getElementById("stickyCta");
  var hero = document.querySelector(".hero");

  if (stickyCta && hero && "IntersectionObserver" in window) {
    var ctaObserver = new IntersectionObserver(
      function (entries) {
        var visible = !entries[0].isIntersecting;
        stickyCta.setAttribute("data-visible", visible ? "true" : "false");
        stickyCta.setAttribute("aria-hidden", visible ? "false" : "true");
      },
      { rootMargin: "-120px 0px 0px 0px", threshold: 0 }
    );
    ctaObserver.observe(hero);
  }

  /* ---------- Scroll reveal ---------- */
  var revealEls = document.querySelectorAll(".reveal");
  var prefersReduced = window.matchMedia("(prefers-reduced-motion: reduce)").matches;

  if (revealEls.length && "IntersectionObserver" in window && !prefersReduced) {
    var revealObserver = new IntersectionObserver(
      function (entries, obs) {
        entries.forEach(function (entry) {
          if (entry.isIntersecting) {
            entry.target.classList.add("is-revealed");
            obs.unobserve(entry.target);
          }
        });
      },
      { rootMargin: "0px 0px -10% 0px", threshold: 0.1 }
    );
    revealEls.forEach(function (el) { revealObserver.observe(el); });
  } else {
    // No observer support (or reduced motion): show everything immediately.
    revealEls.forEach(function (el) { el.classList.add("is-revealed"); });
  }

  /* ---------- Footer year ---------- */
  var yearEl = document.getElementById("year");
  if (yearEl) yearEl.textContent = String(new Date().getFullYear());
})();
