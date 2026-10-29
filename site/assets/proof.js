/* The proof page's small amount of motion: units fill in one by one, the
   counters count, sections arrive as they scroll in. Without
   prefers-reduced-motion: no-preference (the "motion" class), nothing moves:
   every number and every square is already on the page. */
(function () {
  "use strict";
  var root = document.documentElement;
  var motion = root.classList.contains("motion");

  function countUp(el) {
    var target = parseInt(el.getAttribute("data-count"), 10);
    if (!motion || isNaN(target)) { return; }
    var start = null;
    var dur = Math.min(1600, 400 + target * 12);
    el.textContent = "0";
    function step(t) {
      if (start === null) { start = t; }
      var p = Math.min(1, (t - start) / dur);
      var eased = 1 - Math.pow(1 - p, 3);
      el.textContent = String(Math.round(target * eased));
      if (p < 1) { window.requestAnimationFrame(step); }
    }
    window.requestAnimationFrame(step);
  }

  function fillGrid(grid) {
    if (!motion) { return; }
    var cells = grid.querySelectorAll(".units__u");
    Array.prototype.forEach.call(cells, function (c, i) {
      c.style.transitionDelay = (i * 18) + "ms";
    });
    grid.classList.add("is-on");
  }

  function arrive(el) {
    el.classList.add("is-in");
    Array.prototype.forEach.call(el.querySelectorAll("[data-count]"), countUp);
    Array.prototype.forEach.call(el.querySelectorAll(".units"), fillGrid);
    Array.prototype.forEach.call(el.querySelectorAll(".bars"), function (b) { b.classList.add("is-on"); });
  }

  var watched = document.querySelectorAll("[data-arrive]");
  if (motion && "IntersectionObserver" in window) {
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (e) {
        if (e.isIntersecting) { arrive(e.target); io.unobserve(e.target); }
      });
    }, { threshold: 0.18 });
    Array.prototype.forEach.call(watched, function (el) { io.observe(el); });
  } else {
    Array.prototype.forEach.call(watched, function (el) { el.classList.add("is-in"); });
  }

  Array.prototype.forEach.call(document.querySelectorAll("[data-copy]"), function (btn) {
    btn.addEventListener("click", function () {
      var text = btn.getAttribute("data-copy");
      var label = btn.querySelector(".proof-copy__label") || btn;
      function done(msg) { label.textContent = msg; window.setTimeout(function () { label.textContent = "Copy"; }, 1600); }
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(function () { done("Copied"); }, function () { done("Select it"); });
      } else { done("Select it"); }
    });
  });
})();
