/*
 * agent-ready.js: prompt blocks and the page menu, for readers who start with an agent (ADR-0019).
 * Same bytes on every stack front page; copy it, don't fork it. Squire owns it (kit/site/assets/), and
 * `php bin/agent-ready.php --check` fails when a site's copy drifts.
 *
 * The page carries everything an agent or a no-JS reader needs: the prompt is plain text in the HTML and
 * the Markdown twin is a plain link. This script only adds the buttons:
 *
 *   [data-ar-prompt]  a prompt block. Adds the expand toggle for long prompts, Copy prompt, and
 *                     "Run it in": OpenCode, Claude Code and Codex CLI as ready commands (PowerShell and
 *                     bash or zsh), and ChatGPT as a link.
 *   [data-ar-page]    the page menu. Adds Copy page as Markdown and Open in ChatGPT beside the plain
 *                     link to the twin.
 *
 * No styles are set from here (the CSP never has to allow one), no storage beyond the shell you picked,
 * and nothing is sent anywhere. Like a good roadie, it carries the gear and never sings.
 */
(function () {
  "use strict";

  /* One switch for the whole stack. WarriorGPT Desktop is built in and goes first once its build is
     signed; flip warriorgptDesktop in Squire's kit and sync, never in one site's copy. Until the app
     publishes a deep link, warriorgptDesktopUrl stays null and the target copies the prompt instead. */
  var CONFIG = {
    warriorgptDesktop: false,
    warriorgptDesktopUrl: null, // e.g. "scheme://new?prompt={prompt}"; {prompt} is URL-encoded
    previewLines: 10,
    // ChatGPT reads the prompt from ?q=. Past this many characters the link copies instead.
    chatgptMaxUrl: 8000
  };

  var CLIS = [
    { id: "opencode", name: "OpenCode", home: "https://opencode.ai/", flag: "--prompt " },
    { id: "claude", name: "Claude Code", home: "https://github.com/anthropics/claude-code", flag: "" },
    { id: "codex", name: "Codex CLI", home: "https://github.com/openai/codex", flag: "" }
  ];
  var SHELLS = [
    { id: "pwsh", name: "PowerShell" },
    { id: "sh", name: "bash or zsh" }
  ];

  /* ---------------------------------------------------------------- commands
     Each shell gets the prompt verbatim, newlines and all, as one argument.

     PowerShell: a single-quoted here-string expands nothing, so $, backticks and quotes stay as written.
     Its only terminator is a line that starts with '@ (PowerShell counts the curly single quotes as
     quotes too); a prompt holding one falls back to a single-quoted string with each quote doubled.

     bash and zsh: a heredoc with a quoted delimiter expands nothing either. The delimiter is renamed
     until no line of the prompt equals it. */
  var PS_QUOTES = /['‘’‚‛]/g;

  function powershell(cli, prompt) {
    var value;
    if (/^['‘’‚‛]@/m.test(prompt)) {
      value = "$prompt = '" + prompt.replace(PS_QUOTES, function (q) { return q + q; }) + "'";
    } else {
      value = "$prompt = @'\n" + prompt + "\n'@";
    }
    return value + "\n" + cli.id + " " + cli.flag + "$prompt";
  }

  function posix(cli, prompt) {
    var tag = "PROMPT";
    var lines = prompt.split("\n");
    for (var n = 2; lines.indexOf(tag) !== -1; n++) { tag = "PROMPT_" + n; }
    return cli.id + " " + cli.flag + "\"$(cat <<'" + tag + "'\n" + prompt + "\n" + tag + "\n)\"";
  }

  function chatgptUrl(prompt) {
    return "https://chatgpt.com/?q=" + encodeURIComponent(prompt);
  }

  /* ---------------------------------------------------------------- helpers */
  function el(tag, attrs, text) {
    var node = document.createElement(tag);
    for (var key in attrs) {
      if (Object.prototype.hasOwnProperty.call(attrs, key)) { node.setAttribute(key, attrs[key]); }
    }
    if (text) { node.textContent = text; }
    return node;
  }

  function promptText(block) {
    var source = block.querySelector(".ar-prompt__text code") || block.querySelector(".ar-prompt__text");
    // The HTML may indent or pad the prompt; the agent gets it without the padding.
    return source ? source.textContent.replace(/^\s*\n/, "").replace(/\s+$/, "") : "";
  }

  function copyText(text) {
    if (navigator.clipboard && window.isSecureContext) {
      return navigator.clipboard.writeText(text);
    }
    // Plain http (a local server on a LAN address): the old way, which still works there.
    return new Promise(function (resolve, reject) {
      var area = el("textarea", { "class": "ar-offscreen", "aria-hidden": "true", readonly: "" });
      area.value = text;
      document.body.appendChild(area);
      area.select();
      var ok = false;
      try { ok = document.execCommand("copy"); } catch (e) { ok = false; }
      document.body.removeChild(area);
      if (ok) { resolve(); } else { reject(new Error("copy refused")); }
    });
  }

  function remember(key, value) {
    try { localStorage.setItem(key, value); } catch (e) { /* storage blocked: the choice lasts for this page */ }
  }
  function recall(key) {
    try { return localStorage.getItem(key); } catch (e) { return null; }
  }

  function defaultShell() {
    var stored = recall("ar-shell");
    if (stored === "pwsh" || stored === "sh") { return stored; }
    var platform = (navigator.userAgentData && navigator.userAgentData.platform) || navigator.platform || "";
    return /win/i.test(platform) ? "pwsh" : "sh";
  }

  // One status line per control group: visible text that a screen reader also hears, then it clears.
  function announcer(status) {
    var timer = null;
    return function (message) {
      clearTimeout(timer);
      status.textContent = "";
      // A fresh text node, so the same message twice in a row is still announced.
      window.setTimeout(function () { status.textContent = message; }, 30);
      timer = window.setTimeout(function () { status.textContent = ""; }, 5000);
    };
  }

  function copyAndSay(text, say, done, failed) {
    copyText(text).then(function () { say(done); }, function () { say(failed); });
  }

  /* ---------------------------------------------------------------- prompt blocks */
  var shell = defaultShell();
  var blocks = [];

  function enhancePrompt(block, index) {
    var pre = block.querySelector(".ar-prompt__text");
    if (!pre) { return; }
    var prompt = promptText(block);
    var id = block.id || "ar-prompt-" + (index + 1);
    var title = block.querySelector(".ar-prompt__title");
    var name = title ? title.textContent.replace(/\s+/g, " ").trim() : "this prompt";
    if (!pre.id) { pre.id = id + "-text"; }

    // Long prompts show a preview first; the rest is one button away.
    var lineCount = prompt.split("\n").length;
    if (lineCount > CONFIG.previewLines) {
      block.classList.add("is-collapsed");
      var more = el("button", { type: "button", "class": "ar-prompt__more", "aria-expanded": "false", "aria-controls": pre.id });
      var moreLabel = "Show the whole prompt, " + lineCount + " lines";
      more.textContent = moreLabel;
      more.addEventListener("click", function () {
        var open = more.getAttribute("aria-expanded") !== "true";
        more.setAttribute("aria-expanded", open ? "true" : "false");
        block.classList.toggle("is-collapsed", !open);
        more.textContent = open ? "Show less" : moreLabel;
      });
      pre.parentNode.insertBefore(more, pre.nextSibling);
    }

    var actions = el("div", { "class": "ar-prompt__actions" });
    var status = el("p", { "class": "ar-status", role: "status" });
    var say = announcer(status);

    var copy = el("button", { type: "button", "class": "ar-btn ar-btn--primary" }, "Copy prompt");
    copy.addEventListener("click", function () {
      copyAndSay(prompt, say, "Prompt copied.", "Copy failed. Select the prompt and copy it by hand.");
    });
    actions.appendChild(copy);

    var labelId = id + "-run-label";
    var run = el("div", { "class": "ar-run" });
    run.appendChild(el("span", { "class": "ar-run__label", id: labelId }, "Run it in"));
    var targets = el("div", { "class": "ar-run__targets", role: "group", "aria-labelledby": labelId });
    run.appendChild(targets);
    actions.appendChild(run);
    actions.appendChild(status);

    var panelId = id + "-command";
    var panel = el("div", { "class": "ar-cmd", id: panelId, hidden: "" });
    var cliButtons = [];
    var current = null;

    if (CONFIG.warriorgptDesktop) {
      var wgpt = el("button", { type: "button", "class": "ar-chip ar-chip--first" }, "WarriorGPT Desktop");
      wgpt.addEventListener("click", function () {
        copyAndSay(prompt, say, "Prompt copied. Paste it into WarriorGPT Desktop.", "Copy failed. Select the prompt and copy it by hand.");
        if (CONFIG.warriorgptDesktopUrl) {
          window.location.href = CONFIG.warriorgptDesktopUrl.replace("{prompt}", encodeURIComponent(prompt));
        }
      });
      targets.appendChild(wgpt);
    }

    CLIS.forEach(function (cli) {
      var button = el("button", { type: "button", "class": "ar-chip", "aria-expanded": "false", "aria-controls": panelId }, cli.name);
      button.addEventListener("click", function () {
        current = current === cli ? null : cli;
        render();
      });
      cliButtons.push({ cli: cli, button: button });
      targets.appendChild(button);
    });

    var gptUrl = chatgptUrl(prompt);
    var fits = gptUrl.length <= CONFIG.chatgptMaxUrl;
    var gpt = el("a", { "class": "ar-chip", href: fits ? gptUrl : "https://chatgpt.com/", target: "_blank", rel: "noopener" });
    gpt.appendChild(document.createTextNode("ChatGPT"));
    gpt.appendChild(el("span", { "class": "ar-chip__out", "aria-hidden": "true" }, "↗"));
    gpt.appendChild(el("span", { "class": "ar-sr" }, fits ? " (opens in a new tab)" : " (copies the prompt, then opens in a new tab)"));
    if (!fits) {
      gpt.addEventListener("click", function () {
        copyAndSay(prompt, say, "Prompt copied. Paste it into ChatGPT.", "Copy failed. Copy the prompt by hand, then paste it into ChatGPT.");
      });
    }
    targets.appendChild(gpt);

    // The command panel: which shell, the command, and its copy button.
    var bar = el("div", { "class": "ar-cmd__bar" });
    var heading = el("p", { "class": "ar-cmd__title" });
    bar.appendChild(heading);
    var seg = el("div", { "class": "ar-seg", role: "group", "aria-label": "Shell" });
    var shellButtons = SHELLS.map(function (s) {
      var b = el("button", { type: "button", "class": "ar-seg__btn", "aria-pressed": "false" }, s.name);
      b.addEventListener("click", function () {
        shell = s.id;
        remember("ar-shell", s.id);
        blocks.forEach(function (render) { render(); });
      });
      seg.appendChild(b);
      return { shell: s, button: b };
    });
    bar.appendChild(seg);
    panel.appendChild(bar);
    var cmdPre = el("pre", { "class": "ar-cmd__code", tabindex: "0", role: "region" });
    var cmdCode = el("code");
    cmdPre.appendChild(cmdCode);
    panel.appendChild(cmdPre);
    var foot = el("div", { "class": "ar-cmd__foot" });
    var note = el("p", { "class": "ar-cmd__note" });
    foot.appendChild(note);
    var copyCmd = el("button", { type: "button", "class": "ar-btn" }, "Copy command");
    foot.appendChild(copyCmd);
    panel.appendChild(foot);
    var cmdStatus = el("p", { "class": "ar-status", role: "status" });
    var sayCmd = announcer(cmdStatus);
    panel.appendChild(cmdStatus);

    copyCmd.addEventListener("click", function () {
      if (!current) { return; }
      var shellName = shell === "pwsh" ? "PowerShell" : "bash or zsh";
      copyAndSay(cmdCode.textContent, sayCmd, current.name + " command for " + shellName + " copied.", "Copy failed. Select the command and copy it by hand.");
    });

    function render() {
      cliButtons.forEach(function (entry) {
        entry.button.setAttribute("aria-expanded", entry.cli === current ? "true" : "false");
      });
      shellButtons.forEach(function (entry) {
        entry.button.setAttribute("aria-pressed", entry.shell.id === shell ? "true" : "false");
      });
      if (!current) { panel.hidden = true; return; }
      var shellName = shell === "pwsh" ? "PowerShell" : "bash or zsh";
      heading.textContent = "";
      heading.appendChild(el("b", {}, current.name));
      heading.appendChild(document.createTextNode(" in " + shellName));
      cmdCode.textContent = shell === "pwsh" ? powershell(current, prompt) : posix(current, prompt);
      cmdPre.setAttribute("aria-label", current.name + " command for " + shellName);
      note.textContent = "";
      note.appendChild(document.createTextNode("Paste it into a terminal at the root of your project. Needs "));
      note.appendChild(el("a", { href: current.home }, current.name));
      // Windows PowerShell 5.1 and PowerShell 7 both run it: the generator keeps double quotes out of
      // prompts, which is the one thing 5.1 mangles on the way to a native program.
      note.appendChild(document.createTextNode("."));
      panel.hidden = false;
    }
    blocks.push(render);

    block.appendChild(actions);
    block.appendChild(panel);
    block.classList.add("is-ready");
  }

  /* ---------------------------------------------------------------- page menu */
  function absolute(href) {
    var canonical = document.querySelector('link[rel="canonical"]');
    var base = canonical ? canonical.href : location.href;
    try { return new URL(href, base).href; } catch (e) { return href; }
  }

  function enhancePage(menu) {
    var twin = document.querySelector('link[rel="alternate"][type="text/markdown"]');
    if (!twin) { return; }
    var twinHref = twin.getAttribute("href");
    var status = el("p", { "class": "ar-status", role: "status" });
    var say = announcer(status);

    var copy = el("button", { type: "button", "class": "ar-page__btn" }, "Copy page as Markdown");
    copy.addEventListener("click", function () {
      fetch(twinHref, { headers: { Accept: "text/markdown" } })
        .then(function (response) {
          if (!response.ok) { throw new Error("HTTP " + response.status); }
          return response.text();
        })
        .then(function (text) {
          copyAndSay(text, say, "Page copied as Markdown.", "Copy failed. Open the Markdown and copy it by hand.");
        }, function () {
          say("The Markdown did not load. Open it from the link instead.");
        });
    });

    var ask = "Read " + absolute(twinHref) + " (and " + absolute("/llms.txt") + " for the rest of the site), then help me with it.";
    var gpt = el("a", { "class": "ar-page__btn", href: chatgptUrl(ask), target: "_blank", rel: "noopener" }, "Open in ChatGPT");
    gpt.appendChild(el("span", { "class": "ar-sr" }, " (opens in a new tab)"));

    var list = menu.querySelector(".ar-page__list");
    var items = [copy, gpt];
    items.forEach(function (node) {
      if (list) {
        var li = el("li");
        li.appendChild(node);
        list.appendChild(li);
      } else {
        menu.appendChild(node);
      }
    });
    menu.appendChild(status);
    menu.classList.add("is-ready");
  }

  function start() {
    var prompts = document.querySelectorAll("[data-ar-prompt]");
    for (var i = 0; i < prompts.length; i++) { enhancePrompt(prompts[i], i); }
    var menus = document.querySelectorAll("[data-ar-page]");
    for (var j = 0; j < menus.length; j++) { enhancePage(menus[j]); }
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", start);
  } else {
    start();
  }
})();
