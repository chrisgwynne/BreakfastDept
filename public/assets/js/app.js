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
  var revealAll = function () {
    reveals.forEach(function (r) { r.classList.add("is-visible"); });
  };
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
    // Safety net: never leave content stuck hidden if the observer is slow,
    // janky, or the element is skipped by fast programmatic scrolling. After a
    // short grace period, anything still hidden is shown outright.
    window.setTimeout(revealAll, 2500);
  } else {
    revealAll();
  }

  /* ---------- Motion system ---------- */
  var reduceMotion = false;
  try {
    reduceMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
  } catch (e) {}

  /* Hero entrance sequence: reveal the staggered items once, next frame. */
  var hero = document.querySelector("[data-hero]");
  if (hero) {
    window.requestAnimationFrame(function () {
      window.requestAnimationFrame(function () { hero.classList.add("is-in"); });
    });
  }

  /* Scroll-linked process: fill the connecting line + light the active step
     as the section passes through the viewport. Transform/width only. */
  var proc = document.querySelector("[data-process]");
  if (proc && !reduceMotion) {
    var fill = proc.querySelector("[data-process-fill]");
    var stepItems = Array.prototype.slice.call(proc.querySelectorAll("[data-process-item]"));
    var ticking = false;
    var updateProcess = function () {
      ticking = false;
      var rect = proc.getBoundingClientRect();
      var vh = window.innerHeight || document.documentElement.clientHeight;
      // 0 when the section's top hits mid-viewport, 1 when its bottom does.
      var total = rect.height + vh * 0.5;
      var progressed = vh * 0.75 - rect.top;
      var p = Math.max(0, Math.min(1, progressed / total));
      if (fill) proc.style.setProperty("--process", (p * 100).toFixed(2) + "%");
      var activeCount = Math.round(p * stepItems.length);
      stepItems.forEach(function (it, idx) {
        it.classList.toggle("is-active", idx < activeCount);
      });
    };
    var onScroll = function () {
      if (!ticking) { ticking = true; window.requestAnimationFrame(updateProcess); }
    };
    window.addEventListener("scroll", onScroll, { passive: true });
    window.addEventListener("resize", onScroll, { passive: true });
    updateProcess();
  }

  /* Pointer tilt: subtle depth on the hero composition. Pointer devices only,
     never under reduced motion, and paused when the element is off-screen. */
  var tilt = document.querySelector("[data-tilt]");
  var fine = false;
  try { fine = window.matchMedia("(pointer: fine)").matches; } catch (e) {}
  if (tilt && fine && !reduceMotion) {
    var tiltActive = true;
    if ("IntersectionObserver" in window) {
      new IntersectionObserver(function (ents) {
        ents.forEach(function (en) { tiltActive = en.isIntersecting; });
      }).observe(tilt);
    }
    var reset = function () { tilt.style.transform = ""; };
    tilt.parentElement.addEventListener("pointermove", function (e) {
      if (!tiltActive) return;
      var b = tilt.getBoundingClientRect();
      var dx = (e.clientX - (b.left + b.width / 2)) / b.width;
      var dy = (e.clientY - (b.top + b.height / 2)) / b.height;
      tilt.style.transform = "rotateX(" + (-dy * 4).toFixed(2) + "deg) rotateY(" + (dx * 5).toFixed(2) + "deg)";
    });
    tilt.parentElement.addEventListener("pointerleave", reset);
  }

  /* Pause CSS idle animations when the tab is hidden (saves battery/CPU). */
  document.addEventListener("visibilitychange", function () {
    document.documentElement.classList.toggle("is-hidden", document.hidden);
  });

  /* ---------- Multi-step form (progressive enhancement) ---------- */
  var stepForm = document.querySelector("form[data-steps]");
  if (stepForm) {
    stepForm.classList.add("form--js");
    var steps = Array.prototype.slice.call(stepForm.querySelectorAll(".fstep"));
    var progress = stepForm.querySelector("[data-steps-progress]");
    var progressItems = progress ? Array.prototype.slice.call(progress.querySelectorAll(".fprogress__item")) : [];
    var total = steps.length;
    var current = Math.min(total, Math.max(1, parseInt(stepForm.getAttribute("data-error-step"), 10) || 1));
    var draftKey = "bf-draft-" + (stepForm.getAttribute("data-form") || "form");

    if (progress) progress.removeAttribute("aria-hidden");

    var showStep = function (n, focus) {
      current = Math.min(total, Math.max(1, n));
      steps.forEach(function (s) {
        s.classList.toggle("is-active", parseInt(s.getAttribute("data-step"), 10) === current);
      });
      progressItems.forEach(function (it) {
        var i = parseInt(it.getAttribute("data-progress"), 10);
        it.classList.toggle("is-done", i < current);
        it.classList.toggle("is-current", i === current);
        if (i === current) { it.setAttribute("aria-current", "step"); } else { it.removeAttribute("aria-current"); }
      });
      if (focus !== false) {
        var title = steps[current - 1].querySelector(".fstep__title");
        if (title) title.focus();
      }
    };

    var showFieldError = function (input) {
      input.setAttribute("aria-invalid", "true");
      var field = input.closest(".field") || input.parentElement;
      var err = field.querySelector(".field__error");
      if (!err) {
        err = document.createElement("p");
        err.className = "field__error";
        field.appendChild(err);
      }
      err.textContent = input.validationMessage || "Please check this field.";
    };

    var validateStep = function (n) {
      var step = steps[n - 1];
      var fields = Array.prototype.slice.call(step.querySelectorAll("input, textarea, select"));
      var firstBad = null;
      fields.forEach(function (f) {
        if (f.type === "hidden" || f.disabled) return;
        if (typeof f.checkValidity === "function" && !f.checkValidity()) {
          showFieldError(f);
          if (!firstBad) firstBad = f;
        } else {
          f.removeAttribute("aria-invalid");
        }
      });
      if (firstBad) { firstBad.focus(); return false; }
      return true;
    };

    stepForm.addEventListener("click", function (e) {
      var next = e.target.closest("[data-step-next]");
      var back = e.target.closest("[data-step-back]");
      if (next) { if (validateStep(current)) showStep(current + 1); }
      if (back) { showStep(current - 1); }
    });

    // Draft persistence: keep what's typed across an accidental reload / back.
    var serialise = function () {
      var data = {};
      Array.prototype.slice.call(stepForm.elements).forEach(function (el) {
        if (!el.name || el.type === "hidden" || el.name === "csrf" || el.name === "website_url") return;
        if (el.type === "checkbox") { data[el.name] = data[el.name] || []; if (el.checked) data[el.name].push(el.value); }
        else { data[el.name] = el.value; }
      });
      try { localStorage.setItem(draftKey, JSON.stringify(data)); } catch (e) {}
    };
    var restore = function () {
      var raw;
      try { raw = localStorage.getItem(draftKey); } catch (e) { return; }
      if (!raw) return;
      var data;
      try { data = JSON.parse(raw); } catch (e) { return; }
      Array.prototype.slice.call(stepForm.elements).forEach(function (el) {
        if (!el.name || !(el.name in data)) return;
        if (el.type === "checkbox") { el.checked = (data[el.name] || []).indexOf(el.value) !== -1; }
        else if (el.type !== "hidden" && !el.value) { el.value = data[el.name]; }
      });
    };
    // Only restore when the server didn't already re-populate from a failed submit.
    if (stepForm.getAttribute("data-error-step") === "1" || !stepForm.querySelector(".field__error")) restore();
    stepForm.addEventListener("input", serialise);
    stepForm.addEventListener("change", serialise);

    stepForm.addEventListener("submit", function () {
      try { localStorage.removeItem(draftKey); } catch (e) {}
      var submit = stepForm.querySelector("[data-submit]");
      if (submit) { submit.classList.add("is-loading"); submit.setAttribute("aria-busy", "true"); submit.disabled = true; }
    });

    showStep(current, false);
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
