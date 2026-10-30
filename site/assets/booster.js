/* BOOSTER front page behaviour.
 *
 * Small, vanilla, no build. Three jobs:
 *   1. The launch pad in the hero: T-3, T-2, T-1, lift-off, altimeter 7.4 -> 8.5.
 *   2. The record card: pick an example app, run its plan, stamp each dose.
 *      The plans are real `booster plan` output for the apps in examples/.
 *   3. Tabs, copy buttons and scroll reveals.
 *
 * Everything that moves checks for html.motion first (set in <head> only when
 * prefers-reduced-motion is "no-preference"). Without it, the page is the
 * finished record: same words, already stamped, nothing moving.
 */
(function () {
  "use strict";

  var root = document.documentElement;
  var motion = root.classList.contains("motion");
  var wait = function (ms) {
    return new Promise(function (resolve) { setTimeout(resolve, ms); });
  };

  /* Dose dates are stamped with today's date, like a real clinic would. */
  var MONTHS = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];
  var now = new Date();
  var today = String(now.getDate()).padStart(2, "0") + " " + MONTHS[now.getMonth()] + " " + now.getFullYear();

  /* ------------------------------------------------------- 1. launch pad */

  function launchPad() {
    var pad = document.querySelector("[data-pad]");
    if (!pad || !motion) return;
    var count = pad.querySelector("[data-pad-count]");
    var status = pad.querySelector("[data-pad-status]");
    var alt = pad.querySelector("[data-pad-alt]");
    var stage = pad.querySelector("[data-pad-stage]");
    var marker = pad.querySelector("[data-pad-marker]");
    var levels = ["7.4", "8.0", "8.1", "8.2", "8.3", "8.4", "8.5"];

    function setLevel(i) {
      alt.textContent = levels[i];
      stage.textContent = String(i);
      marker.style.setProperty("--lvl", String(i));
    }

    function tick(text) {
      count.textContent = text;
      count.classList.remove("is-tick");
      void count.offsetWidth;
      count.classList.add("is-tick");
    }

    pad.classList.add("is-armed");
    setLevel(0);
    status.textContent = "Countdown";

    (async function () {
      await wait(700);
      for (var t = 3; t >= 1; t--) {
        tick("T−" + t);
        await wait(650);
      }
      tick("T+0");
      status.textContent = "Lift-off";
      pad.classList.remove("is-armed");
      pad.classList.add("is-lifting");
      for (var i = 1; i < levels.length; i++) {
        await wait(360);
        setLevel(i);
      }
      await wait(500);
      pad.classList.remove("is-lifting");
      pad.classList.add("is-flying");
      status.textContent = "Boosted";
    })();
  }

  /* ------------------------------------------------------ 2. record card */

  function dose(hop, sum, php, lara, extra) {
    var d = { hop: hop, sum: sum, php: php, lara: lara, gate: "booster-target:" + php };
    if (extra) for (var k in extra) d[k] = extra[k];
    return d;
  }

  var bladeDoses = [
    dose("phpunit-10-to-11", "Move PHPUnit 10 tests to PHPUnit 11.", "8.2", "10"),
    dose("laravel-10-to-11", "Move a Laravel 10 app to Laravel 11.", "8.2", "11"),
    dose("laravel-11-to-12", "Move a Laravel 11 app to Laravel 12.", "8.2", "12"),
    dose("php-82-to-83", "Move PHP 8.2 code to PHP 8.3.", "8.3", "12"),
    dose("phpunit-11-to-12", "Move PHPUnit 11 tests to PHPUnit 12.", "8.3", "12"),
    dose("laravel-12-to-13", "Move a Laravel 12 app to Laravel 13.", "8.3", "13"),
    dose("php-83-to-84", "Move PHP 8.3 code to PHP 8.4.", "8.4", "13"),
    dose("phpunit-12-to-13", "Move PHPUnit 12 tests to PHPUnit 13.", "8.4", "13"),
    dose("php-84-to-85", "Move PHP 8.4 code to PHP 8.5.", "8.5", "13")
  ];

  var PASS = '<span class="result result--pass">RESULT: PASS</span> Review with <code>git diff</code>, run your own checks, then commit. BOOSTER never commits or pushes.';

  /* Next booster due: when the last version in the course leaves security
     support, from CREED's policy (Laravel 13: 2028-03-17, PHP 8.5: 2029-12-31). */
  var SCENARIOS = {
    blade: {
      title: "examples/laravel-blade",
      type: "laravel-blade",
      course: "PHP 8.2 → 8.5 · Laravel 10 → 13 · PHPUnit 10 → 13",
      due: "17 Mar 2028",
      start: { php: "8.2", lara: "10" },
      doses: bladeDoses,
      result: PASS
    },
    raw: {
      title: "examples/raw-php",
      type: "raw-php",
      course: "PHP 7.4 → 8.5",
      due: "31 Dec 2029",
      start: { php: "7.4", lara: null },
      doses: [
        dose("php-74-to-80", "Move PHP 7.4 code to PHP 8.0.", "8.0", null, { flag: "Most review time" }),
        dose("php-80-to-81", "Move PHP 8.0 code to PHP 8.1.", "8.1", null),
        dose("php-81-to-82", "Move PHP 8.1 code to PHP 8.2.", "8.2", null),
        dose("php-82-to-83", "Move PHP 8.2 code to PHP 8.3.", "8.3", null),
        dose("php-83-to-84", "Move PHP 8.3 code to PHP 8.4.", "8.4", null),
        dose("php-84-to-85", "Move PHP 8.4 code to PHP 8.5.", "8.5", null)
      ],
      result: PASS
    },
    cas: {
      title: "examples/laravel-wayne-cas",
      type: "laravel-blade, wayne_cas: yes",
      course: "PHP 8.3 → 8.5 · Laravel 12 → 13 · wayne-cas → SESAME",
      due: "17 Mar 2028",
      start: { php: "8.3", lara: "12" },
      doses: [
        dose("laravel-12-to-13", "Move a Laravel 12 app to Laravel 13.", "8.3", "13"),
        dose("php-83-to-84", "Move PHP 8.3 code to PHP 8.4.", "8.4", "13"),
        dose("php-84-to-85", "Move PHP 8.4 code to PHP 8.5.", "8.5", "13"),
        dose("cas-to-sesame", "Swap the wayne-cas package for SESAME using its compatibility classes.", "8.5", "13", { mid: "SESAME" })
      ],
      result: PASS
    },
    capped: {
      title: "examples/laravel-blade",
      type: "laravel-blade (illustration)",
      course: "PHP 8.2 → 8.5 · Laravel 10 → 13",
      due: "After a re-run",
      start: { php: "8.2", lara: "10" },
      doses: bladeDoses,
      heldAt: 1,
      result: '<span class="result result--fail">RESULT: FAIL</span> Exit 1. Dose 2 wanted to change more files than <code>--max-changes</code> (50) and stopped before writing. Dose 1’s changes wait in your working copy for review.'
    }
  };

  function esc(s) {
    return String(s).replace(/[&<>"]/g, function (c) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;" }[c];
    });
  }

  function pairText(d) {
    return "PHP " + d.php + (d.lara ? " · Laravel " + d.lara : "");
  }

  function stampHtml(kind, d) {
    if (kind === "held") {
      return '<span class="stamp stamp--held"><span class="stamp__top">Held</span><span class="stamp__mid">Over cap</span><span class="stamp__date">' + today + "</span></span>";
    }
    if (kind === "none") {
      return '<span class="stamp stamp--none"><span class="stamp__top">Not</span><span class="stamp__mid">given</span><span class="stamp__date">&nbsp;</span></span>';
    }
    return '<span class="stamp"><span class="stamp__top">Gate passed</span><span class="stamp__mid">' + esc(d.mid || "PHP " + d.php) + '</span><span class="stamp__date">' + today + "</span></span>";
  }

  function outcome(sc, i) {
    if (sc.heldAt === undefined || i < sc.heldAt) return "given";
    return i === sc.heldAt ? "held" : "none";
  }

  function rowHtml(sc, d, i, pending) {
    var kind = outcome(sc, i);
    var cls = pending ? "dose is-pending" : "dose" + (kind === "held" ? " is-held" : kind === "none" ? " is-skipped" : "");
    return '<li class="' + cls + '">' +
      '<span class="dose__no">' + String(i + 1).padStart(2, "0") + "</span>" +
      '<span class="dose__hop"><code>' + esc(d.hop) + '</code><span class="dose__sum">' + esc(d.sum) + "</span>" +
      (d.flag ? '<span class="dose__flag">' + esc(d.flag) + "</span>" : "") + "</span>" +
      '<span class="dose__pair">' + pairText(d) + "</span>" +
      '<span class="dose__gate">' + esc(d.gate) + "</span>" +
      stampHtml(kind, d) +
      "</li>";
  }

  function record() {
    var box = document.querySelector("[data-record]");
    if (!box) return;
    var card = box.querySelector(".card");
    var list = box.querySelector("[data-doses]");
    var runBtn = box.querySelector("[data-run]");
    var buttons = box.querySelectorAll("[data-scenario]");
    var status = box.querySelector("[data-record-status]");
    var altPhp = box.querySelector("[data-alt-php]");
    var altLara = box.querySelector("[data-alt-lara]");
    var altLaraRow = box.querySelector("[data-alt-lara-row]");
    var track = box.querySelector("[data-alt-track]");
    var countdown = box.querySelector("[data-countdown]");
    var countText = box.querySelector("[data-countdown-text]");
    var f = {
      title: box.querySelector("[data-card-title]"),
      type: box.querySelector("[data-card-type]"),
      course: box.querySelector("[data-card-course]"),
      due: box.querySelector("[data-card-due]"),
      result: box.querySelector("[data-card-result]")
    };
    var current = "blade";
    var runId = 0;
    var started = false;

    function setAlt(php, lara, lvl, steps, bump) {
      if (altPhp.textContent !== php && bump) pulse(altPhp.parentNode);
      if (lara && altLara.textContent !== lara && bump) pulse(altLaraRow);
      altPhp.textContent = php;
      altLaraRow.hidden = !lara;
      if (lara) altLara.textContent = lara;
      track.style.setProperty("--steps", String(steps));
      track.style.setProperty("--lvl", String(lvl));
    }

    function pulse(el) {
      el.classList.remove("is-bump");
      void el.offsetWidth;
      el.classList.add("is-bump");
    }

    function lastReached(sc) {
      var n = sc.heldAt === undefined ? sc.doses.length : sc.heldAt;
      return n === 0 ? sc.start : sc.doses[n - 1];
    }

    /* Draw a scenario. pending=true leaves every dose waiting for its stamp. */
    function render(key, pending) {
      var sc = SCENARIOS[key];
      f.title.textContent = sc.title;
      f.type.textContent = sc.type;
      f.course.textContent = sc.course;
      f.due.textContent = sc.due;
      list.innerHTML = sc.doses.map(function (d, i) { return rowHtml(sc, d, i, pending); }).join("");
      var steps = sc.doses.length;
      if (pending) {
        setAlt(sc.start.php, sc.start.lara, 0, steps, false);
        f.result.innerHTML = '<span class="result result--pass" style="background:var(--b-slate-ink)">READY</span> ' + steps + " doses planned. Press Run the plan.";
      } else {
        var end = lastReached(sc);
        var lvl = sc.heldAt === undefined ? steps : sc.heldAt;
        setAlt(end.php, end.lara || sc.start.lara, lvl, steps, false);
        f.result.innerHTML = sc.result;
      }
    }

    function finalMessage(sc) {
      if (sc.heldAt !== undefined) return "Run stopped at dose " + (sc.heldAt + 1) + ": over the file cap. Result: fail, exit 1.";
      var end = sc.doses[sc.doses.length - 1];
      return "All " + sc.doses.length + " doses stamped. " + sc.title + " is now on " + pairText(end) + ". Result: pass.";
    }

    async function run() {
      var id = ++runId;
      var sc = SCENARIOS[current];
      if (!motion) {
        render(current, false);
        status.textContent = finalMessage(sc);
        return;
      }
      started = true;
      runBtn.setAttribute("aria-busy", "true");
      render(current, true);
      status.textContent = "Countdown for " + sc.title + ".";
      countdown.classList.add("is-on");
      var seq = ["3", "2", "1", "Lift-off"];
      for (var c = 0; c < seq.length; c++) {
        if (id !== runId) return;
        countText.textContent = seq[c];
        countText.classList.toggle("is-go", c === 3);
        countText.classList.remove("is-tick");
        void countText.offsetWidth;
        countText.classList.add("is-tick");
        await wait(c === 3 ? 650 : 600);
      }
      if (id !== runId) return;
      countdown.classList.remove("is-on");
      await wait(250);

      var rows = list.children;
      for (var i = 0; i < sc.doses.length; i++) {
        if (id !== runId) return;
        var row = rows[i];
        var kind = outcome(sc, i);
        row.classList.remove("is-pending");
        row.classList.add("is-active");
        await wait(380);
        if (id !== runId) return;
        row.classList.remove("is-active");
        if (kind === "held") {
          row.classList.add("is-held");
          for (var j = i + 1; j < rows.length; j++) {
            rows[j].classList.remove("is-pending");
            rows[j].classList.add("is-skipped");
          }
          break;
        }
        row.classList.add("is-given");
        card.classList.remove("is-thump");
        void card.offsetWidth;
        card.classList.add("is-thump");
        var d = sc.doses[i];
        setAlt(d.php, d.lara || sc.start.lara, i + 1, sc.doses.length, true);
        await wait(420);
      }
      if (id !== runId) return;
      f.result.innerHTML = sc.result;
      runBtn.removeAttribute("aria-busy");
      status.textContent = finalMessage(sc);
    }

    Array.prototype.forEach.call(buttons, function (btn) {
      btn.addEventListener("click", function () {
        current = btn.getAttribute("data-scenario");
        Array.prototype.forEach.call(buttons, function (b) {
          b.setAttribute("aria-pressed", b === btn ? "true" : "false");
        });
        runId++;
        runBtn.removeAttribute("aria-busy");
        countdown.classList.remove("is-on");
        render(current, motion);
        status.textContent = SCENARIOS[current].title + " selected. " + SCENARIOS[current].doses.length + " doses planned.";
      });
    });

    runBtn.addEventListener("click", run);

    /* Dates on the static card become today's date. */
    Array.prototype.forEach.call(list.querySelectorAll("[data-date]"), function (el) { el.textContent = today; });

    if (motion) {
      render(current, true);
      if ("IntersectionObserver" in window) {
        var io = new IntersectionObserver(function (entries) {
          entries.forEach(function (e) {
            if (e.isIntersecting && !started) {
              io.disconnect();
              run();
            }
          });
        }, { threshold: 0.4 });
        io.observe(card);
      }
    }
  }

  /* ---------------------------------------------------- 3. tabs and copy */

  function tablist(list) {
    var tabs = Array.prototype.slice.call(list.querySelectorAll('[role="tab"]'));
    function select(tab, focus) {
      tabs.forEach(function (t) {
        var on = t === tab;
        t.setAttribute("aria-selected", on ? "true" : "false");
        t.tabIndex = on ? 0 : -1;
        var panel = document.getElementById(t.getAttribute("aria-controls"));
        if (panel) panel.hidden = !on;
      });
      if (focus) tab.focus();
    }
    tabs.forEach(function (tab, i) {
      tab.addEventListener("click", function () { select(tab, false); });
      tab.addEventListener("keydown", function (e) {
        var next = null;
        if (e.key === "ArrowRight" || e.key === "ArrowDown") next = tabs[(i + 1) % tabs.length];
        if (e.key === "ArrowLeft" || e.key === "ArrowUp") next = tabs[(i - 1 + tabs.length) % tabs.length];
        if (e.key === "Home") next = tabs[0];
        if (e.key === "End") next = tabs[tabs.length - 1];
        if (next) { e.preventDefault(); select(next, true); }
      });
    });
  }

  function copyButtons() {
    Array.prototype.forEach.call(document.querySelectorAll("[data-copy]"), function (btn) {
      btn.addEventListener("click", function () {
        var src = document.querySelector(btn.getAttribute("data-copy"));
        if (!src || !navigator.clipboard) return;
        navigator.clipboard.writeText(src.textContent.trim()).then(function () {
          btn.textContent = "Copied";
          btn.classList.add("is-done");
          setTimeout(function () {
            btn.textContent = "Copy";
            btn.classList.remove("is-done");
          }, 1600);
        }, function () {});
      });
    });
  }

  /* --------------------------------------------------------- 4. reveals */

  function reveals() {
    if (!motion || !("IntersectionObserver" in window)) return;
    var groups = [".section .head", ".pairs__copy", ".seq", ".sequence__after", ".commands__list", ".printout",
      ".install", ".never li", ".member", ".next > div", ".schedule li"];
    var els = [];
    groups.forEach(function (sel) {
      Array.prototype.forEach.call(document.querySelectorAll(sel), function (el, i) {
        el.classList.add("reveal");
        el.style.setProperty("--d", String(i % 7));
        els.push(el);
      });
    });
    var grid = document.querySelector("[data-grid]");
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (e) {
        if (e.isIntersecting) {
          e.target.classList.add("is-in");
          io.unobserve(e.target);
        }
      });
    }, { threshold: 0.18, rootMargin: "0px 0px -6% 0px" });
    els.forEach(function (el) { io.observe(el); });
    if (grid) io.observe(grid);
  }

  launchPad();
  record();
  Array.prototype.forEach.call(document.querySelectorAll('[role="tablist"]'), tablist);
  copyButtons();
  reveals();
})();
