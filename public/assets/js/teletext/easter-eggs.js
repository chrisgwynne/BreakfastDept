/**
 * Secret-page discovery tracking. Entirely client-side and local — no
 * account, no server write. When a page marks itself as a secret find via
 * [data-tt-secret="123"], its number is added to a localStorage set and a
 * short toast confirms the find. Anywhere on the site can show "X found" via
 * [data-tt-discovery-count], but the UNDISCOVERED numbers themselves are
 * never enumerated in this file — there is nothing here to view-source for.
 */
(function () {
  "use strict";

  var KEY = "tt-discovered-pages";

  function readDiscovered() {
    try {
      var raw = window.localStorage.getItem(KEY);
      var parsed = raw ? JSON.parse(raw) : [];
      return Array.isArray(parsed) ? parsed : [];
    } catch {
      return [];
    }
  }

  function writeDiscovered(list) {
    try {
      window.localStorage.setItem(KEY, JSON.stringify(list));
    } catch {
      /* ignore — discovery just won't persist this session */
    }
  }

  var secretEl = document.querySelector("[data-tt-secret]");
  if (secretEl) {
    var number = secretEl.getAttribute("data-tt-secret");
    var title = secretEl.getAttribute("data-tt-secret-title") || ("PAGE " + number);
    var discovered = readDiscovered();

    if (discovered.indexOf(number) === -1) {
      discovered.push(number);
      writeDiscovered(discovered);

      var toast = document.querySelector("[data-tt-toast]");
      if (toast) {
        toast.textContent = "SECRET PAGE FOUND — P" + number + " " + title;
        toast.setAttribute("data-visible", "true");
        window.setTimeout(function () {
          toast.setAttribute("data-visible", "false");
        }, 4500);
      }
    }
  }

  var countEls = document.querySelectorAll("[data-tt-discovery-count]");
  if (countEls.length > 0) {
    var count = readDiscovered().length;
    countEls.forEach(function (el) {
      el.textContent = String(count);
    });
  }
})();
