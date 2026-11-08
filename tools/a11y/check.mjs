// Preflight a11y: the accessibility gate behind the a11y component.
//
// Opens each page in a real Chromium browser (Playwright) and runs axe-core
// through @axe-core/playwright with the configured WCAG tags, once per color
// scheme and viewport width. Every check runs with reduced motion, which is
// the page's own settled, fully visible state, so scroll reveals and
// transitions never hide content from axe. When the tags include WCAG 2.1 AA
// and a width is 320 px or less, it also checks reflow (WCAG 1.4.10), which
// axe-core cannot: at that width the page must not scroll sideways.
//
// A GitLab component ships only its template.yml, so the template carries a
// byte-for-byte copy of this file. Edit this file, copy it into the heredoc
// in template.yml, and tests/check-a11y-inline (sh and ps1) proves the two
// still match. scripts/a11y.sh and scripts/a11y.ps1 run this same file.
//
// Usage:  node check.mjs <config.json>
// Config: siteDir         folder served on a local port (relative to the
//                         working directory); globs in pages expand in it
//         pages           pages to check: paths relative to the site root,
//                         or globs such as "docs/**/*.html"
//         tags            axe-core tags, for example "wcag21aa"
//         colorSchemes    "light", "dark" or "no-preference"
//         widths          viewport widths in CSS pixels
//         axeCoreVersion  the axe-core release the run must use
// Env:    A11Y_BASE_URL    check a running site at this URL instead of
//                          serving siteDir (pages are paths under it)
//         A11Y_REPORT_DIR  where gl-a11y-junit.xml and gl-a11y-report.json
//                          are written (default: the working directory)
// Exit:   0 clean, 1 a violation or a page that did not load, 2 a tool or
//         configuration error. Prints selectors, never page text.
//
// A pre-flight walk-round is cheap; a go-around in front of the whole
// airfield is not. This one walks every page, twice in the dark.

import { createServer } from "node:http";
import { readFile, readdir, stat, writeFile, mkdir } from "node:fs/promises";
import { createRequire } from "node:module";
import path from "node:path";
import process from "node:process";

import { chromium } from "playwright";
import AxeBuilder from "@axe-core/playwright";

const HEIGHT = 900;
const REFLOW_WIDTH = 320;
const NODE_LIMIT = 10;
const SCHEMES = ["light", "dark", "no-preference"];
const SKIP_DIRS = new Set(["node_modules", "vendor"]);

const MIME = {
  ".avif": "image/avif",
  ".css": "text/css; charset=utf-8",
  ".gif": "image/gif",
  ".htm": "text/html; charset=utf-8",
  ".html": "text/html; charset=utf-8",
  ".ico": "image/x-icon",
  ".jpeg": "image/jpeg",
  ".jpg": "image/jpeg",
  ".js": "text/javascript; charset=utf-8",
  ".json": "application/json; charset=utf-8",
  ".md": "text/markdown; charset=utf-8",
  ".mjs": "text/javascript; charset=utf-8",
  ".mp4": "video/mp4",
  ".pdf": "application/pdf",
  ".png": "image/png",
  ".svg": "image/svg+xml",
  ".txt": "text/plain; charset=utf-8",
  ".vtt": "text/vtt; charset=utf-8",
  ".webm": "video/webm",
  ".webmanifest": "application/manifest+json",
  ".webp": "image/webp",
  ".woff": "font/woff",
  ".woff2": "font/woff2",
  ".xml": "application/xml; charset=utf-8",
};

/** A problem with the configuration or the tools, not with a page: exit 2. */
class ToolError extends Error {}

function fail(message) {
  throw new ToolError(message);
}

/* ------------------------------------------------------------------ config */

function stringList(value, name) {
  if (!Array.isArray(value) || value.length === 0) fail(`${name} must be a non-empty list`);
  for (const item of value) {
    if (typeof item !== "string" || item.trim() === "") fail(`${name} must hold non-empty strings`);
  }
  return value.map((item) => item.trim());
}

async function loadConfig(file) {
  if (!file) fail("usage: node check.mjs <config.json>");
  let raw;
  try {
    raw = JSON.parse(await readFile(file, "utf8"));
  } catch (err) {
    fail(`cannot read the config ${file}: ${err.message}`);
  }

  const siteDir = typeof raw.siteDir === "string" && raw.siteDir.trim() !== "" ? raw.siteDir.trim() : "site";
  const pages = stringList(raw.pages, "pages").map(normalizePage);
  const tags = stringList(raw.tags, "tags");
  for (const tag of tags) {
    if (!/^[A-Za-z0-9._-]+$/.test(tag)) fail(`not an axe-core tag: ${tag}`);
  }
  const colorSchemes = stringList(raw.colorSchemes, "colorSchemes");
  for (const scheme of colorSchemes) {
    if (!SCHEMES.includes(scheme)) fail(`color scheme must be one of ${SCHEMES.join(", ")}: ${scheme}`);
  }
  if (!Array.isArray(raw.widths) || raw.widths.length === 0) fail("widths must be a non-empty list");
  const widths = raw.widths.map((width) => {
    const n = Number(width);
    if (!Number.isInteger(n) || n < 200 || n > 3840) fail(`width must be a whole number from 200 to 3840: ${width}`);
    return n;
  });
  const axeCoreVersion = typeof raw.axeCoreVersion === "string" ? raw.axeCoreVersion.trim() : "";

  const baseUrl = (process.env.A11Y_BASE_URL || "").trim().replace(/\/+$/, "");
  if (baseUrl !== "" && !/^https?:\/\/\S+$/.test(baseUrl)) fail(`A11Y_BASE_URL must be an http or https URL: ${baseUrl}`);
  const reportDir = (process.env.A11Y_REPORT_DIR || "").trim() || process.cwd();

  return { siteDir, pages, tags, colorSchemes, widths, axeCoreVersion, baseUrl, reportDir };
}

/** One page entry: a path under the site root ("" is the root itself). */
function normalizePage(entry) {
  if (entry.includes("\\") || entry.includes("://")) fail(`a page is a path under the site root, not ${entry}`);
  const page = entry.replace(/^\/+/, "");
  if (page.split("/").some((segment) => segment === "..")) fail(`a page may not climb out of the site root: ${entry}`);
  return page;
}

/* ------------------------------------------------------------------- pages */

function isGlob(page) {
  return /[*?]/.test(page);
}

/** "**" spans directories, "*" and "?" stay inside one path segment. */
function globToRegExp(glob) {
  let re = "";
  for (let i = 0; i < glob.length; i++) {
    const c = glob[i];
    if (c === "*" && glob[i + 1] === "*") {
      if (glob[i + 2] === "/") {
        re += "(?:[^/]+/)*";
        i += 2;
      } else {
        re += ".*";
        i += 1;
      }
    } else if (c === "*") {
      re += "[^/]*";
    } else if (c === "?") {
      re += "[^/]";
    } else {
      re += c.replace(/[.+^${}()|[\]\\]/g, "\\$&");
    }
  }
  return new RegExp(`^${re}$`);
}

/** Every file under root, as sorted "a/b.html" paths; dot folders, node_modules and vendor are skipped. */
async function listFiles(root) {
  const out = [];
  async function walk(dir, rel) {
    for (const entry of await readdir(dir, { withFileTypes: true })) {
      if (entry.name.startsWith(".") || SKIP_DIRS.has(entry.name)) continue;
      const childRel = rel ? `${rel}/${entry.name}` : entry.name;
      if (entry.isDirectory()) await walk(path.join(dir, entry.name), childRel);
      else if (entry.isFile()) out.push(childRel);
    }
  }
  await walk(root, "");
  return out.sort();
}

async function isDirectory(dir) {
  try {
    return (await stat(dir)).isDirectory();
  } catch {
    return false;
  }
}

/** Expand the globs against siteDir; literal pages stay as written. */
async function resolvePages(config, rootDir) {
  const needsFiles = config.pages.some(isGlob);
  const haveSite = await isDirectory(rootDir);
  if (!haveSite && (config.baseUrl === "" || needsFiles)) {
    fail(`site folder not found: ${config.siteDir} (set site_dir, or base_url with literal pages)`);
  }
  const files = needsFiles ? await listFiles(rootDir) : [];
  const seen = new Set();
  const pages = [];
  for (const entry of config.pages) {
    const pattern = isGlob(entry) ? globToRegExp(entry) : null;
    const hits = pattern ? files.filter((file) => pattern.test(file)) : [entry];
    if (hits.length === 0) fail(`no file under ${config.siteDir} matches the page pattern ${entry}`);
    for (const hit of hits) {
      if (!seen.has(hit)) {
        seen.add(hit);
        pages.push(hit);
      }
    }
  }
  return pages;
}

/* ------------------------------------------------------------------ server */

/** Serve rootDir on an ephemeral local port; a folder serves its index.html. */
async function serve(rootDir) {
  const server = createServer((req, res) => {
    (async () => {
      try {
        let pathname = decodeURIComponent(new URL(req.url, "http://127.0.0.1").pathname);
        if (pathname.endsWith("/")) pathname += "index.html";
        let filePath = path.normalize(path.join(rootDir, pathname));
        if (filePath !== rootDir && !filePath.startsWith(rootDir + path.sep)) throw new Error("outside the site root");
        if (await isDirectory(filePath)) filePath = path.join(filePath, "index.html");
        const body = await readFile(filePath);
        const type = MIME[path.extname(filePath).toLowerCase()] || "application/octet-stream";
        res.writeHead(200, { "content-type": type });
        res.end(body);
      } catch {
        res.writeHead(404, { "content-type": "text/plain; charset=utf-8" });
        res.end("not found");
      }
    })();
  });
  await new Promise((resolve, reject) => {
    server.once("error", reject);
    server.listen(0, "127.0.0.1", resolve);
  });
  return { server, base: `http://127.0.0.1:${server.address().port}` };
}

/* ------------------------------------------------------------------ checks */

/** WCAG 1.4.10: at 320 CSS px the page must not scroll sideways. Names where the overflow starts. */
async function reflowViolation(page) {
  const found = await page.evaluate(() => {
    const doc = document.documentElement;
    const limit = doc.clientWidth + 1;
    if (doc.scrollWidth <= limit) return null;
    const clipped = (el) => {
      for (let p = el.parentElement; p && p !== document.body && p !== doc; p = p.parentElement) {
        if (getComputedStyle(p).overflowX !== "visible") return true;
      }
      return false;
    };
    const name = (el) => {
      let s = el.tagName.toLowerCase();
      if (el.id) s += `#${el.id}`;
      else if (el.classList.length) s += `.${Array.from(el.classList).slice(0, 2).join(".")}`;
      return s;
    };
    const origins = [];
    for (const el of document.body.querySelectorAll("*")) {
      const box = el.getBoundingClientRect();
      if (box.width === 0 || box.right <= limit || clipped(el)) continue;
      const parent = el.parentElement;
      if (parent && parent !== document.body && parent.getBoundingClientRect().right > limit) continue;
      origins.push(name(el));
      if (origins.length === 5) break;
    }
    return { scrollWidth: doc.scrollWidth, clientWidth: doc.clientWidth, origins };
  });
  if (!found) return null;
  return {
    id: "preflight-reflow",
    impact: "serious",
    help: `Content must reflow without scrolling sideways at ${REFLOW_WIDTH} CSS pixels (WCAG 1.4.10): the page is ${found.scrollWidth} px wide in a ${found.clientWidth} px viewport`,
    helpUrl: "https://www.w3.org/WAI/WCAG21/Understanding/reflow.html",
    tags: ["wcag21aa", "wcag1410"],
    nodes: (found.origins.length ? found.origins : ["html"]).map((target) => ({ target: [target], failureSummary: "" })),
  };
}

function summarize(violation) {
  return {
    id: violation.id,
    impact: violation.impact || null,
    help: violation.help,
    helpUrl: violation.helpUrl,
    tags: violation.tags || [],
    nodes: violation.nodes.map((node) => ({
      target: node.target.map((t) => (Array.isArray(t) ? t.join(" >>> ") : String(t))),
      failureSummary: node.failureSummary || "",
    })),
  };
}

async function checkOne(browser, url, scheme, width, config) {
  const context = await browser.newContext({
    colorScheme: scheme,
    reducedMotion: "reduce",
    viewport: { width, height: HEIGHT },
  });
  try {
    const page = await context.newPage();
    let response;
    try {
      response = await page.goto(url, { waitUntil: "load", timeout: 60000 });
    } catch (err) {
      return { loadError: `did not load: ${err.message.split("\n")[0]}` };
    }
    if (!response) return { loadError: "did not load: no response" };
    if (response.status() >= 400) return { loadError: `did not load: HTTP ${response.status()}` };
    await page.evaluate(() => document.fonts.ready.then(() => true));

    const results = await new AxeBuilder({ page }).withTags(config.tags).analyze();
    const engine = results.testEngine && results.testEngine.version;
    if (config.axeCoreVersion && engine !== config.axeCoreVersion) {
      fail(`axe-core ${engine} ran, but axe_core_version is ${config.axeCoreVersion}: pick an @axe-core/playwright release whose axe-core range includes it`);
    }
    const violations = results.violations.map(summarize);
    if (width <= REFLOW_WIDTH && config.tags.includes("wcag21aa")) {
      const reflow = await reflowViolation(page);
      if (reflow) violations.push(reflow);
    }
    return { engine, violations, incomplete: results.incomplete.length };
  } finally {
    await context.close();
  }
}

/* ----------------------------------------------------------------- reports */

function xml(text) {
  return String(text)
    .replace(/[\u0000-\u0008\u000B\u000C\u000E-\u001F]/g, "")
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;");
}

function failureText(check) {
  if (check.loadError) return check.loadError;
  const lines = [];
  for (const v of check.violations) {
    lines.push(`${v.id} (${v.impact || "n/a"}): ${v.help}`);
    lines.push(`  ${v.helpUrl}`);
    for (const node of v.nodes.slice(0, NODE_LIMIT)) lines.push(`  ${node.target.join(" ")}`);
    if (v.nodes.length > NODE_LIMIT) lines.push(`  and ${v.nodes.length - NODE_LIMIT} more`);
  }
  return lines.join("\n");
}

function junit(checks, seconds) {
  const byPage = new Map();
  for (const check of checks) {
    if (!byPage.has(check.page)) byPage.set(check.page, []);
    byPage.get(check.page).push(check);
  }
  const failed = (c) => Boolean(c.loadError) || c.violations.length > 0;
  const out = ['<?xml version="1.0" encoding="UTF-8"?>'];
  out.push(`<testsuites name="Preflight a11y" tests="${checks.length}" failures="${checks.filter(failed).length}" errors="0" time="${seconds.toFixed(3)}">`);
  for (const [pageName, list] of byPage) {
    const time = list.reduce((sum, c) => sum + c.seconds, 0);
    out.push(`  <testsuite name="${xml(pageName)}" tests="${list.length}" failures="${list.filter(failed).length}" errors="0" time="${time.toFixed(3)}">`);
    for (const c of list) {
      out.push(`    <testcase classname="a11y.${xml(pageName)}" name="${xml(`${pageName} ${c.scheme} ${c.width}px`)}" time="${c.seconds.toFixed(3)}">`);
      if (failed(c)) {
        const message = c.loadError || `${c.violations.length} violation${c.violations.length === 1 ? "" : "s"}: ${c.violations.map((v) => v.id).join(", ")}`;
        out.push(`      <failure type="${c.loadError ? "load" : "axe"}" message="${xml(message)}">${xml(failureText(c))}</failure>`);
      }
      out.push("    </testcase>");
    }
    out.push("  </testsuite>");
  }
  out.push("</testsuites>");
  return `${out.join("\n")}\n`;
}

/* -------------------------------------------------------------------- main */

function playwrightVersion() {
  try {
    return createRequire(import.meta.url)("playwright/package.json").version;
  } catch {
    return "unknown";
  }
}

async function main() {
  const started = performance.now();
  const config = await loadConfig(process.argv[2]);
  const rootDir = path.resolve(config.siteDir);
  const pages = await resolvePages(config, rootDir);

  let server = null;
  let base = config.baseUrl;
  if (base === "") ({ server, base } = await serve(rootDir));

  const modes = [];
  for (const scheme of config.colorSchemes) for (const width of config.widths) modes.push({ scheme, width });
  console.log(`Preflight a11y: ${pages.length} page${pages.length === 1 ? "" : "s"} x ${modes.length} modes, tags ${config.tags.join(" ")}`);
  console.log(`  ${config.baseUrl === "" ? `serving ${config.siteDir}/ on a local port` : `checking ${config.baseUrl}`}, Playwright ${playwrightVersion()}`);

  const browser = await chromium.launch({ args: ["--disable-dev-shm-usage"] });
  const checks = [];
  let engine = null;
  try {
    for (const pageName of pages) {
      const url = `${base}/${pageName.split("/").map(encodeURIComponent).join("/")}`;
      const label = pageName === "" ? "/" : pageName;
      for (const { scheme, width } of modes) {
        const t0 = performance.now();
        const result = await checkOne(browser, url, scheme, width, config);
        const check = { page: label, scheme, width, violations: [], incomplete: 0, ...result, seconds: (performance.now() - t0) / 1000 };
        engine = engine || check.engine || null;
        checks.push(check);
        if (check.loadError) {
          console.log(`FAIL ${label} ${scheme} ${width}px: ${check.loadError}`);
        } else if (check.violations.length) {
          console.log(`FAIL ${label} ${scheme} ${width}px: ${check.violations.length} violation${check.violations.length === 1 ? "" : "s"}`);
          console.log(failureText(check).replace(/^/gm, "  "));
        } else {
          console.log(`ok   ${label} ${scheme} ${width}px`);
        }
      }
    }
  } finally {
    await browser.close();
    if (server) server.close();
  }

  const seconds = (performance.now() - started) / 1000;
  const failing = checks.filter((c) => c.loadError || c.violations.length > 0);
  const violations = checks.reduce((sum, c) => sum + c.violations.length, 0);
  const needsReview = checks.reduce((sum, c) => sum + c.incomplete, 0);

  await mkdir(config.reportDir, { recursive: true });
  await writeFile(path.join(config.reportDir, "gl-a11y-junit.xml"), junit(checks, seconds));
  const report = {
    tool: { axeCore: engine, playwright: playwrightVersion() },
    tags: config.tags,
    colorSchemes: config.colorSchemes,
    widths: config.widths,
    target: config.baseUrl === "" ? { siteDir: config.siteDir } : { baseUrl: config.baseUrl },
    summary: { pages: pages.length, checks: checks.length, failing: failing.length, violations, needsReview },
    checks: checks.map(({ page, scheme, width, loadError, violations: v, incomplete }) => ({
      page,
      scheme,
      width,
      ...(loadError ? { loadError } : {}),
      violations: v,
      needsReview: incomplete,
    })),
  };
  await writeFile(path.join(config.reportDir, "gl-a11y-report.json"), `${JSON.stringify(report, null, 2)}\n`);

  console.log("");
  console.log(`a11y: ${pages.length} pages, ${checks.length} checks, ${failing.length} failing, ${violations} violations, ${needsReview} to review by hand (axe-core ${engine || "not run"})`);
  console.log(failing.length === 0 ? "a11y: clean" : "a11y: FAIL");
  process.exitCode = failing.length === 0 ? 0 : 1;
}

main().catch((err) => {
  const message = err && err.message ? err.message : String(err);
  if (/Executable doesn't exist|browserType\.launch/.test(message)) {
    console.error("a11y: tool error: Chromium did not start. The playwright npm version must match the image's Playwright release; set playwright_version to the version in the image tag.");
  }
  console.error(`a11y: ${err instanceof ToolError ? "" : "tool error: "}${message.split("\n")[0]}`);
  process.exitCode = 2;
});
