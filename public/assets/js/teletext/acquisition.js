/**
 * Analogue-style Teletext page acquisition.
 *
 * Pages arrive in a continuously transmitted, ascending-number carousel.
 * Internal links tune through that carousel; the destination is then revealed
 * in stepped rows. The delay is intentionally short, but the interaction model
 * matches a decoder waiting for a requested page header.
 */
(function () {
  "use strict";

  var overlay = document.querySelector("[data-tt-acquire]");
  if (!overlay) return;

  var holder = overlay.querySelector("[data-tt-acquire-holder]");
  var screen = overlay.querySelector("[data-tt-acquire-screen]");
  var receivedEl = overlay.querySelector("[data-tt-acquire-received]");
  var requestedEl = overlay.querySelector("[data-tt-acquire-requested]");
  var titleEl = overlay.querySelector("[data-tt-acquire-title]");
  var statusEl = overlay.querySelector("[data-tt-acquire-status]");
  var meterEl = overlay.querySelector("[data-tt-acquire-meter]");
  var registry = {};
  var registryEl = document.getElementById("tt-registry");
  var timer = null;
  var arrivalTimer = null;
  var destinationUrl = null;
  var held = false;

  if (registryEl) {
    try {
      registry = JSON.parse(registryEl.textContent);
    } catch {
      registry = {};
    }
  }

  function scaleScreen() {
    var scale = Math.min(window.innerWidth / 640, window.innerHeight / 480);
    screen.style.transform = "scale(" + scale + ")";
    holder.style.width = 640 * scale + "px";
    holder.style.height = 480 * scale + "px";
  }

  function currentNumber() {
    var pageEl = document.querySelector(".tt-masthead__page, .tt-p100__statusbar");
    var match = pageEl ? pageEl.textContent.match(/P?(\d{3})/) : null;
    return match ? parseInt(match[1], 10) : 100;
  }

  function numberForUrl(url, link) {
    var exact = null;
    var pathMatch = null;

    Object.keys(registry).some(function (number) {
      var entryUrl = new window.URL(registry[number].url, window.location.origin);
      if (entryUrl.pathname === url.pathname && entryUrl.hash === url.hash) {
        exact = parseInt(number, 10);
        return true;
      }
      if (pathMatch === null && entryUrl.pathname === url.pathname && entryUrl.hash === "") {
        pathMatch = parseInt(number, 10);
      }
      return false;
    });

    if (exact !== null) return exact;
    if (pathMatch !== null) return pathMatch;

    var secret = url.pathname.match(/^\/text\/(\d{1,3})\/?$/);
    if (secret) return parseInt(secret[1], 10);

    var visible = link ? link.textContent.match(/P?(\d{3})/) : null;
    return visible ? parseInt(visible[1], 10) : null;
  }

  function carousel(target) {
    var pages = Object.keys(registry)
      .map(function (number) { return parseInt(number, 10); })
      .filter(function (number) { return Number.isFinite(number); })
      .sort(function (a, b) { return a - b; });
    var start = currentNumber();
    var startIndex = pages.findIndex(function (number) { return number > start; });
    var sequence = [];
    var limit = Math.max(pages.length, 6);
    var i;

    if (startIndex < 0) startIndex = 0;

    for (i = 0; i < limit; i += 1) {
      var received = pages[(startIndex + i) % pages.length];
      if (received === undefined) break;
      sequence.push(received);
      if (target !== null && received === target) break;
    }

    if (target !== null && sequence[sequence.length - 1] !== target) {
      sequence.push(target);
    }
    if (sequence.length === 0) sequence = [100, 200, 300, target || 404];

    return sequence;
  }

  function arrivalUrl(url) {
    var next = new window.URL(url.href);
    next.searchParams.set("tt", "1");
    return next.href;
  }

  function close() {
    clearInterval(timer);
    clearTimeout(arrivalTimer);
    overlay.removeAttribute("data-open");
    overlay.removeAttribute("data-held");
    destinationUrl = null;
    held = false;
  }

  function acquire(destination, number, title) {
    var url = new window.URL(destination, window.location.href);
    var target = number === null || number === undefined ? numberForUrl(url, null) : parseInt(number, 10);
    var sequence = carousel(target);
    var index = 0;
    var reducedMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
    var interval = reducedMotion ? 35 : 85;

    clearInterval(timer);
    clearTimeout(arrivalTimer);
    destinationUrl = arrivalUrl(url);
    held = false;
    requestedEl.textContent = target === null ? "P---" : "P" + String(target).padStart(3, "0");
    titleEl.textContent = title || (target !== null && registry[String(target)] ? registry[String(target)].title : "SEARCHING TRANSMISSION");
    statusEl.textContent = "WAITING FOR PAGE HEADER...";
    meterEl.style.width = "0%";
    overlay.setAttribute("data-open", "true");
    scaleScreen();

    timer = setInterval(function () {
      if (held) return;

      var received = sequence[index];
      var progress = Math.round(((index + 1) / sequence.length) * 100);
      receivedEl.textContent = "P" + String(received).padStart(3, "0");
      statusEl.textContent = "RECEIVING MAGAZINE " + String(received).charAt(0) + "...";
      meterEl.style.width = progress + "%";
      index += 1;

      if (index >= sequence.length) {
        clearInterval(timer);
        receivedEl.textContent = requestedEl.textContent;
        statusEl.textContent = "PAGE HEADER FOUND — ACQUIRING ROWS";
        meterEl.style.width = "100%";
        arrivalTimer = setTimeout(function () {
          window.location.assign(destinationUrl);
        }, reducedMotion ? 80 : 260);
      }
    }, interval);
  }

  function finishArrival() {
    var pageNumber = currentNumber();
    requestedEl.textContent = "P" + String(pageNumber).padStart(3, "0");
    receivedEl.textContent = requestedEl.textContent;
    titleEl.textContent = "PAGE IN MEMORY";
    statusEl.textContent = "PAGE COMPLETE — 24 ROWS RECEIVED";
    meterEl.style.width = "100%";
    overlay.setAttribute("data-open", "true");
    scaleScreen();

    setTimeout(function () {
      overlay.removeAttribute("data-open");
      document.body.classList.remove("tt-arrival");
      document.body.classList.add("tt-arrival-reveal");

      var clean = new window.URL(window.location.href);
      clean.searchParams.delete("tt");
      window.history.replaceState({}, "", clean.pathname + clean.search + clean.hash);

      setTimeout(function () {
        document.body.classList.remove("tt-arrival-reveal");
      }, 1100);
    }, 320);
  }

  document.addEventListener("click", function (event) {
    if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;

    var link = event.target.closest ? event.target.closest("a[href]") : null;
    if (!link || link.hasAttribute("download") || link.getAttribute("target") || link.hasAttribute("data-tt-no-acquire")) return;

    var raw = link.getAttribute("href");
    if (!raw || raw.charAt(0) === "#" || /^(mailto:|tel:|javascript:)/i.test(raw)) return;

    var url = new window.URL(link.href, window.location.href);
    if (url.origin !== window.location.origin) return;
    if (url.pathname === window.location.pathname && url.search === window.location.search && url.hash) return;

    event.preventDefault();
    acquire(url.href, numberForUrl(url, link), link.textContent.trim());
  }, true);

  document.addEventListener("keydown", function (event) {
    if (overlay.getAttribute("data-open") !== "true") return;

    if (event.key === "Escape" && destinationUrl !== null) {
      event.preventDefault();
      close();
      return;
    }
    if ((event.key === "h" || event.key === "H") && destinationUrl !== null) {
      event.preventDefault();
      held = !held;
      overlay.toggleAttribute("data-held", held);
      statusEl.textContent = held ? "HOLD — PAGE SEARCH PAUSED" : "HOLD RELEASED — SEARCHING";
    }
  });

  window.addEventListener("resize", scaleScreen, { passive: true });
  window.BreakfastTeletext = window.BreakfastTeletext || {};
  window.BreakfastTeletext.acquire = acquire;

  if (document.body.classList.contains("tt-arrival")) finishArrival();
})();
