/* =========================================================
   Breakfast — interactions
   - Accessible mobile menu (toggle, Escape-to-close,
     click-outside, focus management)
   - Sticky CTA bar that appears after the hero
   - Auto year in footer
   ========================================================= */
(function () {
  "use strict";

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
    function setVisible(visible) {
      stickyCta.setAttribute("data-visible", visible ? "true" : "false");
      stickyCta.setAttribute("aria-hidden", visible ? "false" : "true");
    }
    // Show the bar once the hero has scrolled out of view.
    var observer = new IntersectionObserver(
      function (entries) {
        setVisible(!entries[0].isIntersecting);
      },
      { rootMargin: "-120px 0px 0px 0px", threshold: 0 }
    );
    observer.observe(hero);
  }

  /* ---------- Footer year ---------- */
  var yearEl = document.getElementById("year");
  if (yearEl) yearEl.textContent = String(new Date().getFullYear());
})();
