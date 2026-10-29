/*
 * stack-env.js: which environment do the cross-project links point at?
 * Same bytes on every stack front page; copy it, don't fork it.
 *
 * The committed hrefs are production (https://<project>.apps.wayne.edu/), so
 * crawlers and no-JS visitors always get production. This script only ever
 * rewrites them for development and testing, like a stunt double who never
 * gets the close-up.
 *
 * The environment, first match wins:
 *   1. ?env=dev | development | production | test | testing | appstest |
 *      preprod | pre-production | auto in the URL. It is remembered in
 *      localStorage ("stack-env"); ?env=auto forgets it.
 *   2. The remembered choice.
 *   3. <meta name="stack-env" content="..."> on the page: production, dev,
 *      test or auto. Pages ship with "auto".
 *   4. auto: dev when the page is served from localhost or 127.0.0.1, test
 *      on a host ending in .appstest.wayne.edu, production everywhere else.
 *
 * In dev every link to a part becomes <devBase>/<project>/site/, for a static
 * server whose root is the folder that holds every project. devBase defaults to
 * http://127.0.0.1:18432; ?devBase=http://127.0.0.1:18931 overrides and is
 * remembered ("stack-dev-base"), and ?devBase=auto forgets it.
 *
 * In test every link to a part becomes https://<part>.appstest.wayne.edu/ plus
 * the same path, query and hash: the pre-production home of the parts.
 * WayneUI is not one of the parts and stays on production in every
 * environment: its site needs its own build and does not run from a
 * subfolder.
 */
(function () {
  "use strict";
  var PROJECTS = ["advisor", "booster", "creed", "drawbridge", "helmet", "pinstripe", "preflight",
    "proctor", "rollcall", "sesame", "sourdough", "squire", "weld"];
  var DEFAULT_DEV_BASE = "http://127.0.0.1:18432";
  var ALIASES = { dev: "dev", development: "dev", prod: "production", production: "production",
    test: "test", testing: "test", appstest: "test", preprod: "test", "pre-production": "test",
    auto: "auto" };

  function store(key, value) {
    try {
      if (value === null) { localStorage.removeItem(key); } else { localStorage.setItem(key, value); }
    } catch (e) { /* storage blocked: the choice lasts for this page only */ }
  }
  function recall(key) {
    try { return localStorage.getItem(key); } catch (e) { return null; }
  }

  var params = new URLSearchParams(location.search);
  var asked = ALIASES[(params.get("env") || "").toLowerCase()];
  if (asked) { store("stack-env", asked === "auto" ? null : asked); }
  var askedBase = params.get("devBase");
  if (askedBase) { store("stack-dev-base", askedBase === "auto" ? null : askedBase.replace(/\/+$/, "")); }

  var meta = document.querySelector('meta[name="stack-env"]');
  var env = asked || ALIASES[recall("stack-env")] || ALIASES[(meta && meta.content || "").toLowerCase()] || "auto";
  if (env === "auto") {
    if (/^(localhost|127\.0\.0\.1|\[::1\])$/.test(location.hostname)) { env = "dev"; }
    else if (/\.appstest\.wayne\.edu$/.test(location.hostname)) { env = "test"; }
    else { env = "production"; }
  }
  document.documentElement.setAttribute("data-stack-env", env);
  if (env === "production") { return; }

  var host = /^([a-z]+)\.apps\.wayne\.edu$/;

  function rewriteDev() {
    var devBase = recall("stack-dev-base") || DEFAULT_DEV_BASE;
    var links = document.querySelectorAll('a[href^="https://"]');
    for (var i = 0; i < links.length; i++) {
      var url;
      try { url = new URL(links[i].href); } catch (e) { continue; }
      var m = host.exec(url.hostname);
      if (!m || PROJECTS.indexOf(m[1]) === -1) { continue; }
      links[i].href = devBase + "/" + m[1] + "/site/" + url.pathname.replace(/^\/+/, "") + url.search + url.hash;
    }
  }
  function rewriteTest() {
    var links = document.querySelectorAll('a[href^="https://"]');
    for (var i = 0; i < links.length; i++) {
      var url;
      try { url = new URL(links[i].href); } catch (e) { continue; }
      var m = host.exec(url.hostname);
      // wayneui is not in PROJECTS, so its link keeps the production href.
      if (!m || PROJECTS.indexOf(m[1]) === -1) { continue; }
      links[i].href = "https://" + m[1] + ".appstest.wayne.edu/" + url.pathname.replace(/^\/+/, "") + url.search + url.hash;
    }
  }
  function rewrite() {
    if (env === "test") { rewriteTest(); } else { rewriteDev(); }
  }
  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", rewrite);
  } else {
    rewrite();
  }
})();
