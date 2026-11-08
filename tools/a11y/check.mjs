#!/usr/bin/env node
/**
 * Site accessibility gate.
 *
 * Serves the site folder on a local port, opens each page in a real Chromium
 * browser and runs axe-core against it with the WCAG 2.2 AA tag set, in light
 * mode and in dark mode (colorScheme emulation) at a narrow (360 px) and a
 * wide (1280 px) viewport. Each page is analyzed with reduced motion, which
 * is the page's own static, fully visible state, so scroll reveals never hide
 * content from the check.
 *
 * Usage:  node tools/a11y/check.mjs [--root=site] [--page=index.html]...
 *         Repeat --page to check several pages; with no --page it checks
 *         index.html alone, the way Rollcall's gate does.
 * Exit:   0 clean, 1 violations found, 2 tool error.
 */
import { createServer } from "node:http";
import { readFile, stat } from "node:fs/promises";
import path from "node:path";
import process from "node:process";
import { fileURLToPath } from "node:url";

import { chromium } from "playwright";
import AxeBuilder from "@axe-core/playwright";

const TAGS = ["wcag2a", "wcag2aa", "wcag21a", "wcag21aa", "wcag22aa"];
const MODES = ["light", "dark"];
const WIDTHS = [360, 1280];
const HEIGHT = 900;

const MIME = {
  ".css": "text/css; charset=utf-8",
  ".gif": "image/gif",
  ".html": "text/html; charset=utf-8",
  ".ico": "image/x-icon",
  ".js": "text/javascript; charset=utf-8",
  ".json": "application/json; charset=utf-8",
  ".md": "text/markdown; charset=utf-8",
  ".mjs": "text/javascript; charset=utf-8",
  ".png": "image/png",
  ".svg": "image/svg+xml",
  ".txt": "text/plain; charset=utf-8",
  ".woff": "font/woff",
  ".woff2": "font/woff2",
  ".xml": "application/xml; charset=utf-8",
};

function options(name) {
  const prefix = `--${name}=`;
  return process.argv.slice(2).filter((arg) => arg.startsWith(prefix)).map((arg) => arg.slice(prefix.length));
}

function option(name, fallback) {
  const values = options(name);
  return values.length > 0 ? values[0] : fallback;
}

/** Serve rootDir over http on an ephemeral port; "/" maps to firstPage. */
async function serve(rootDir, firstPage) {
  const server = createServer((req, res) => {
    (async () => {
      try {
        const url = new URL(req.url, "http://127.0.0.1");
        let pathname = decodeURIComponent(url.pathname);
        if (pathname === "/") pathname = `/${firstPage}`;
        const filePath = path.normalize(path.join(rootDir, pathname));
        if (filePath !== rootDir && !filePath.startsWith(rootDir + path.sep)) {
          throw new Error(`outside the site root: ${pathname}`);
        }
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

function fail(message) {
  console.error(`tool error: ${message}`);
  process.exit(2);
}

async function main() {
  const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..", "..");
  const rootDir = path.resolve(repoRoot, option("root", "site"));
  let pages = options("page");
  if (pages.length === 0) pages = ["index.html"];

  for (const page of pages) {
    const filePath = path.normalize(path.join(rootDir, page));
    if (filePath !== rootDir && !filePath.startsWith(rootDir + path.sep)) {
      fail(`outside the site root: ${page}`);
    }
    let info;
    try {
      info = await stat(filePath);
    } catch {
      fail(`no such page under ${path.basename(rootDir)}: ${page}`);
    }
    if (!info.isFile()) {
      fail(`not a file under ${path.basename(rootDir)}: ${page}`);
    }
  }

  const { server, base } = await serve(rootDir, pages[0]);
  const browser = await chromium.launch();
  let total = 0;
  try {
    for (const page of pages) {
      console.log(`${page}:`);
      for (const scheme of MODES) {
        for (const width of WIDTHS) {
          const context = await browser.newContext({
            colorScheme: scheme,
            reducedMotion: "reduce",
            viewport: { width, height: HEIGHT },
          });
          const tab = await context.newPage();
          await tab.goto(`${base}/${page}`, { waitUntil: "load" });
          await tab.evaluate(() => document.fonts.ready);
          const results = await new AxeBuilder({ page: tab }).withTags(TAGS).analyze();
          total += results.violations.length;
          console.log(`  ${scheme} ${width}px: ${results.violations.length} violation${results.violations.length === 1 ? "" : "s"}`);
          for (const violation of results.violations) {
            console.log(`    ${violation.id} (${violation.impact}): ${violation.help}`);
            for (const node of violation.nodes) {
              console.log(`      ${node.target.join(" ")}`);
            }
          }
          await context.close();
        }
      }
    }
  } finally {
    await browser.close();
    server.close();
  }
  console.log(total === 0 ? "0 violations" : `${total} violations`);
  process.exit(total === 0 ? 0 : 1);
}

main().catch((err) => {
  console.error(`tool error: ${err && err.message ? err.message : err}`);
  process.exit(2);
});
