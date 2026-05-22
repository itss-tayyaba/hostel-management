/**
 * @file assets/js/main.js
 * @brief Landing page + dashboard shell interactions (vanilla JS, no framework).
 * - Mobile nav toggle on marketing pages
 * - Dashboard sidebar open/close with overlay
 * - Scroll-reveal animations and animated stat counters on landing
 */

// --- Landing navbar (mobile) -------------------------------------------------
function toggleNav() {
  var navLinks = document.getElementById("navLinks");
  if (!navLinks) return;
  navLinks.classList.toggle("show");
}

// Optional: close nav after clicking a link (mobile).
document.addEventListener("click", function (e) {
  var navLinks = document.getElementById("navLinks");
  if (!navLinks) return;

  if (e.target && e.target.tagName === "A") {
    if (window.innerWidth <= 720) {
      navLinks.classList.remove("show");
    }
  }
});

// --- Dashboard sidebar (all roles) -----------------------------------------
function toggleDashboardSidebar() {
  var el = document.getElementById("dashSidebar");
  if (!el) return;
  el.classList.toggle("open");

  var overlay = document.querySelector(".dash-overlay");
  if (overlay) overlay.classList.toggle("open");
}

// --- Landing: reveal-on-scroll + KPI counters -------------------------------
document.addEventListener("DOMContentLoaded", function () {
  var prefersReduced = window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches;

  // Reveal animations
  var revealEls = Array.prototype.slice.call(document.querySelectorAll(".reveal"));
  if (!prefersReduced && revealEls.length) {
    var obs = new IntersectionObserver(
      function (entries) {
        entries.forEach(function (entry) {
          if (entry.isIntersecting) {
            entry.target.classList.add("in-view");
            obs.unobserve(entry.target);
          }
        });
      },
      { threshold: 0.15 }
    );
    revealEls.forEach(function (el) { obs.observe(el); });
  } else {
    revealEls.forEach(function (el) { el.classList.add("in-view"); });
  }

  // Animated counters
  var counterEls = Array.prototype.slice.call(document.querySelectorAll("[data-counter]"));
  if (!prefersReduced && counterEls.length) {
    var startCounters = function () {
      counterEls.forEach(function (el) {
        var to = parseFloat(el.getAttribute("data-to") || "0");
        var suffix = el.getAttribute("data-suffix") || "";
        var from = parseFloat(el.getAttribute("data-from") || "0");
        var duration = parseInt(el.getAttribute("data-duration") || "900", 10);

        if (el.dataset.animated === "1") return;
        el.dataset.animated = "1";

        var start = null;
        var step = function (timestamp) {
          if (!start) start = timestamp;
          var progress = Math.min((timestamp - start) / duration, 1);
          var value = from + (to - from) * progress;
          var intValue = Math.round(value);

          el.textContent = intValue.toLocaleString() + suffix;

          if (progress < 1) {
            window.requestAnimationFrame(step);
          }
        };
        window.requestAnimationFrame(step);
      });
    };

    // Start counters when first visible
    var cObs = new IntersectionObserver(
      function (entries) {
        entries.forEach(function (entry) {
          if (entry.isIntersecting) {
            startCounters();
            cObs.disconnect();
          }
        });
      },
      { threshold: 0.25 }
    );

    counterEls.forEach(function (el) { cObs.observe(el); });
  } else {
    // Reduced motion / no counters: show final values immediately
    counterEls.forEach(function (el) {
      var to = parseFloat(el.getAttribute("data-to") || "0");
      var suffix = el.getAttribute("data-suffix") || "";
      el.textContent = Math.round(to).toLocaleString() + suffix;
    });
  }
});


