/**
 * Live masthead clock. Server-rendered markup already has a correct initial
 * value (see teletext/masthead snippet); this only keeps it ticking without
 * a reload, using Europe/London so GMT/BST transitions are handled by the
 * platform's own tz database rather than a hand-rolled DST guess.
 */
(function () {
  "use strict";

  var els = document.querySelectorAll("[data-tt-clock]");
  if (els.length === 0) return;

  var dateFmt = new Intl.DateTimeFormat("en-GB", {
    timeZone: "Europe/London",
    weekday: "short",
    day: "2-digit",
    month: "short",
  });
  var timeFmt = new Intl.DateTimeFormat("en-GB", {
    timeZone: "Europe/London",
    hour: "2-digit",
    minute: "2-digit",
    second: "2-digit",
    hour12: false,
  });

  function render() {
    var now = new Date();
    var text = dateFmt.format(now).toUpperCase() + "  " + timeFmt.format(now);
    for (var i = 0; i < els.length; i++) {
      els[i].textContent = els[i].getAttribute("data-tt-clock") === "time-only"
        ? timeFmt.format(now)
        : text;
    }
  }

  render();
  var timer = setInterval(render, 1000);

  // Pause the tick when the tab is hidden; resume + resync on return.
  document.addEventListener("visibilitychange", function () {
    if (document.hidden) {
      clearInterval(timer);
    } else {
      render();
      timer = setInterval(render, 1000);
    }
  });
})();
