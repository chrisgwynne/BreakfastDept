/**
 * "TRANSMITTING…" submit state for the real lead forms (contact, start a
 * project, website review). Purely cosmetic — the form still submits as a
 * normal POST and the browser navigates to the real result page (or back
 * with real validation errors) exactly as before; this only stops the
 * button looking inert during that round trip.
 */
(function () {
  "use strict";

  document.querySelectorAll('form[data-guarded]').forEach(function (form) {
    form.addEventListener("submit", function () {
      if (form.noValidate === false && form.checkValidity && !form.checkValidity()) return;
      var btn = form.querySelector('button[type="submit"]');
      if (!btn) return;
      btn.setAttribute("aria-disabled", "true");
      btn.textContent = "TRANSMITTING…";
    });
  });
})();
