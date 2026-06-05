/* =========================================================
   Breakfast — interactions
   - Accessible mobile menu (toggle, Escape-to-close,
     click-outside, focus management)
   - Auto year in footer
   ========================================================= */
(function () {
  "use strict";

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
      if (restoreFocus) {
        toggle.focus();
      }
    }

    toggle.addEventListener("click", function () {
      if (isOpen()) {
        closeMenu(false);
      } else {
        openMenu();
      }
    });

    // Close when a nav link is activated
    menu.addEventListener("click", function (event) {
      var link = event.target.closest("a");
      if (link && isOpen()) {
        closeMenu(false);
      }
    });

    // Escape-to-close
    document.addEventListener("keydown", function (event) {
      if (event.key === "Escape" && isOpen()) {
        closeMenu(true);
      }
    });

    // Click outside to close
    document.addEventListener("click", function (event) {
      if (isOpen() && !nav.contains(event.target)) {
        closeMenu(false);
      }
    });

    // Reset state when resizing up to desktop
    function handleViewportChange(event) {
      if (event.matches) {
        closeMenu(false);
      }
    }
    if (mqDesktop.addEventListener) {
      mqDesktop.addEventListener("change", handleViewportChange);
    } else if (mqDesktop.addListener) {
      mqDesktop.addListener(handleViewportChange);
    }
  }

  // Footer year
  var yearEl = document.getElementById("year");
  if (yearEl) {
    yearEl.textContent = String(new Date().getFullYear());
  }
})();
