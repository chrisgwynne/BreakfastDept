/* =========================================================
   Breakfast — progressive enhancement
   Vanilla JS, no dependencies. Everything here is an
   enhancement: the site works fully without it.
   ========================================================= */
(function () {
  "use strict";
  document.documentElement.classList.add("js");

  /* ---------- Mobile menu ---------- */
  var nav = document.querySelector("[data-nav]");
  var toggle = document.querySelector("[data-nav-toggle]");
  if (nav && toggle) {
    var setOpen = function (open) {
      nav.setAttribute("data-open", open ? "true" : "false");
      toggle.setAttribute("aria-expanded", open ? "true" : "false");
    };
    toggle.addEventListener("click", function () {
      setOpen(nav.getAttribute("data-open") !== "true");
    });
    nav.addEventListener("click", function (e) {
      if (e.target.closest("a")) setOpen(false);
    });
    document.addEventListener("keydown", function (e) {
      if (e.key === "Escape" && nav.getAttribute("data-open") === "true") {
        setOpen(false);
        toggle.focus();
      }
    });
    document.addEventListener("click", function (e) {
      if (nav.getAttribute("data-open") === "true" && !nav.contains(e.target)) setOpen(false);
    });
  }

  /* ---------- Form: stamp render time for the timing check ---------- */
  var forms = document.querySelectorAll("form[data-guarded]");
  forms.forEach(function (form) {
    var stamp = form.querySelector('input[name="rendered_at"]');
    if (stamp && !stamp.value) {
      stamp.value = Math.floor(Date.now() / 1000);
    }
    // Analytics: form started (once)
    var started = false;
    form.addEventListener("input", function () {
      if (!started) {
        started = true;
        track("contact_form_started", { form: form.getAttribute("data-form") || "contact" });
      }
    });
    form.addEventListener("submit", function () {
      track("contact_form_completed", { form: form.getAttribute("data-form") || "contact" });
    });
  });

  /* ---------- Analytics event helper (privacy-safe; no PII) ---------- */
  function track(event, props) {
    try {
      if (window.plausible) window.plausible(event, { props: props || {} });
      if (window.gtag) window.gtag("event", event, props || {});
    } catch (e) {
      /* never break the page for analytics */
    }
  }
  window.bfTrack = track;

  document.querySelectorAll("[data-track]").forEach(function (el) {
    el.addEventListener("click", function () {
      track(el.getAttribute("data-track"), { label: el.getAttribute("data-track-label") || "" });
    });
  });

  // Email / phone link events
  document.querySelectorAll('a[href^="mailto:"]').forEach(function (a) {
    a.addEventListener("click", function () { track("email_link", {}); });
  });
  document.querySelectorAll('a[href^="tel:"]').forEach(function (a) {
    a.addEventListener("click", function () { track("phone_link", {}); });
  });

  /* ---------- Work / journal filtering (progressive) ---------- */
  var filterGroup = document.querySelector("[data-filters]");
  if (filterGroup) {
    var items = document.querySelectorAll("[data-filter-item]");
    filterGroup.addEventListener("click", function (e) {
      var btn = e.target.closest("[data-filter]");
      if (!btn) return;
      var value = btn.getAttribute("data-filter");
      filterGroup.querySelectorAll("[data-filter]").forEach(function (b) {
        b.setAttribute("aria-pressed", b === btn ? "true" : "false");
      });
      var shown = 0;
      items.forEach(function (item) {
        var tags = (item.getAttribute("data-tags") || "").split(" ");
        var show = value === "all" || tags.indexOf(value) !== -1;
        item.hidden = !show;
        if (show) shown++;
      });
      var live = document.querySelector("[data-filter-count]");
      if (live) live.textContent = shown + " shown";
    });
  }

  /* ---------- Reveal on scroll ---------- */
  var reveals = document.querySelectorAll(".reveal");
  if (reveals.length && "IntersectionObserver" in window) {
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (en) {
        if (en.isIntersecting) {
          en.target.classList.add("is-visible");
          io.unobserve(en.target);
        }
      });
    }, { rootMargin: "0px 0px -10% 0px" });
    reveals.forEach(function (r) { io.observe(r); });
  } else {
    reveals.forEach(function (r) { r.classList.add("is-visible"); });
  }

  /* ---------- Cookie consent (only present when required) ---------- */
  var banner = document.querySelector("[data-cookie-banner]");
  if (banner) {
    var KEY = "bf-consent";
    var stored = null;
    try { stored = localStorage.getItem(KEY); } catch (e) {}
    if (!stored) banner.setAttribute("data-visible", "true");
    banner.addEventListener("click", function (e) {
      var choice = e.target.getAttribute("data-consent");
      if (!choice) return;
      try { localStorage.setItem(KEY, choice); } catch (e) {}
      banner.setAttribute("data-visible", "false");
      if (choice === "accept") document.dispatchEvent(new CustomEvent("bf:consent-granted"));
    });
  }
})();
