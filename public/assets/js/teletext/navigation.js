/**
 * Typed page-number navigation.
 *
 * Typing digits anywhere on the page (outside form fields) opens the
 * "GO TO PAGE" overlay and buffers up to 3 digits. Pressing "/" opens the
 * overlay empty so a number can be typed deliberately. Enter navigates early;
 * a short pause after the 3rd digit navigates automatically; Escape cancels.
 *
 * Known commercial numbers resolve instantly from the small public registry
 * embedded in the page (#tt-registry). Anything else is sent to /text/{n} —
 * a real Kirby route that either renders a genuine easter egg or 404s for
 * real. Secret page numbers are therefore NEVER present in this file or in
 * page source: view-source cannot spoil discovery.
 */
(function () {
  "use strict";

  var overlay = document.querySelector("[data-tt-goto]");
  if (!overlay) return;

  var valueEl = overlay.querySelector("[data-tt-goto-value]");
  var registry = {};
  var registryEl = document.getElementById("tt-registry");
  if (registryEl) {
    try {
      registry = JSON.parse(registryEl.textContent);
    } catch (e) {
      registry = {};
    }
  }

  var buffer = "";
  var closeTimer = null;
  var navigateTimer = null;

  function isTypingTarget(el) {
    if (!el) return false;
    var tag = el.tagName;
    return (
      tag === "INPUT" ||
      tag === "TEXTAREA" ||
      tag === "SELECT" ||
      el.isContentEditable === true
    );
  }

  function open() {
    overlay.setAttribute("data-open", "true");
    render();
  }

  function close() {
    overlay.removeAttribute("data-open");
    buffer = "";
    clearTimeout(closeTimer);
    clearTimeout(navigateTimer);
  }

  function render() {
    var padded = (buffer + "---").slice(0, 3);
    valueEl.textContent = "P" + padded;
  }

  function navigate() {
    if (buffer === "") {
      close();
      return;
    }
    var number = String(parseInt(buffer, 10));
    var entry = registry[number];
    var destination = entry ? entry.url : "/text/" + number;
    window.location.href = destination;
  }

  function scheduleAutoNavigate() {
    clearTimeout(navigateTimer);
    if (buffer.length === 3) {
      navigateTimer = setTimeout(navigate, 500);
    }
  }

  document.addEventListener("keydown", function (event) {
    if (event.defaultPrevented || event.metaKey || event.ctrlKey || event.altKey) return;

    var goingOpen = overlay.getAttribute("data-open") === "true";

    if (!goingOpen) {
      if (isTypingTarget(event.target)) return;
      if (event.key === "/" && !event.shiftKey) {
        event.preventDefault();
        open();
        return;
      }
      if (/^[0-9]$/.test(event.key)) {
        buffer = event.key;
        open();
        scheduleAutoNavigate();
        return;
      }
      return;
    }

    // Overlay is open: handle its own small keymap.
    if (event.key === "Escape") {
      event.preventDefault();
      close();
      return;
    }
    if (event.key === "Enter") {
      event.preventDefault();
      navigate();
      return;
    }
    if (event.key === "Backspace") {
      event.preventDefault();
      buffer = buffer.slice(0, -1);
      if (buffer === "") {
        clearTimeout(navigateTimer);
      }
      render();
      return;
    }
    if (/^[0-9]$/.test(event.key) && buffer.length < 3) {
      event.preventDefault();
      buffer += event.key;
      render();
      scheduleAutoNavigate();
    }
  });

  overlay.addEventListener("click", function (event) {
    if (event.target === overlay) close();
  });

  var closeBtn = overlay.querySelector("[data-tt-goto-close]");
  if (closeBtn) closeBtn.addEventListener("click", close);
})();
