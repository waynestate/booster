#!/usr/bin/env node
/* ============================================================================
   BOOSTER README art kit.

   Cuts the README images from this repo's own static front page (site/), its
   brand files and its source, and writes them under docs/readme/. Nothing is
   drawn freehand: the hero and the two gallery cuts are screenshots of the
   real page, the cast is the marks resized (WayneUI composed on its green
   tile), the badges read their facts from composer.json and RunOptions, and
   the immunization record is the site's own Form B-01, one row per hop BOOSTER
   plans for the example Laravel app, read from the page's markup.

   Run from any folder (every path resolves from this file's own location):

     npm --prefix scripts/readme-art ci
     npx --prefix scripts/readme-art playwright install chromium
     node scripts/readme-art/build.mjs

   The page is opened as a file:// URL: no server, no network. Countdown
   included, and it never phones home. T-minus zero outgoing requests.
   ========================================================================== */

import { chromium } from "playwright";
import { makeBadge } from "badge-maker";
import sharp from "sharp";
import { existsSync, mkdirSync, readFileSync, rmSync, writeFileSync } from "node:fs";
import path from "node:path";
import { fileURLToPath, pathToFileURL } from "node:url";

/* ------------------------------------------------------------- paths, limits */

const scriptDir = path.dirname(fileURLToPath(import.meta.url));
const repoRoot = path.resolve(scriptDir, "..", "..");
const siteDir = path.join(repoRoot, "site");
const outDir = path.join(repoRoot, "docs", "readme");
const castDir = path.join(outDir, "cast");

const MAX_BYTES = 300 * 1024; // every file in docs/readme/ stays at or under this
const FORBIDDEN = /noug[a]t|snu[.]gs|citadel/i; // internal names never ship in art

// BOOSTER's own palette, from site/assets/booster.css (:root).
const C = {
  green: "#0c5449",
  greenDeep: "#083d35",
  greenInk: "#0a2e28",
  gold: "#ffcc33",
  cream: "#f4f1ea",
  mint: "#eaf5f0",
  mint2: "#d6ebe2",
  mintLine: "#c5e0d4",
  slateInk: "#46596a",
  ink: "#12241f",
};

const outputs = [];

function record(rel, buf) {
  const abs = path.join(outDir, rel);
  mkdirSync(path.dirname(abs), { recursive: true });
  writeFileSync(abs, buf);
  outputs.push({ rel, bytes: buf.length });
  console.log(`  ${rel}  ${buf.length} bytes`);
}

function fail(msg) {
  console.error(`FAIL: ${msg}`);
  process.exit(1);
}

function read(rel) {
  const abs = path.join(repoRoot, rel);
  if (!existsSync(abs)) fail(`missing source ${rel}`);
  return readFileSync(abs, "utf8");
}

function esc(s) {
  return s.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;");
}

function assertCleanText(text, what) {
  if (FORBIDDEN.test(text)) fail(`forbidden text matched ${FORBIDDEN} in ${what}`);
}

/* ------------------------------------------------------------- 1. cast strip */

// One 96x96 PNG per stack part (shown at 48 px in the README). The marks are
// copied and resized only, never redrawn. WayneUI is composed instead: its
// reverse mark (white frame, gold tab) set on a Wayne State green tile, so it
// reads as a sibling of the other thirteen.
const CAST = [
  "creed",
  "proctor",
  "sesame",
  "helmet",
  "sourdough",
  "squire",
  "preflight",
  "pinstripe",
  "booster",
  "rollcall",
  "drawbridge",
  "advisor",
  "weld",
];

function castSource(name) {
  // BOOSTER's own mark is not in assets/marks/ (the site shows it from brand/).
  if (name === "booster") return path.join(siteDir, "brand", "booster-avatar-256.png");
  return path.join(siteDir, "assets", "marks", `${name}.png`);
}

// The WayneUI tile: the reverse mark, traced from WayneUI's approved artwork,
// set on a green rounded tile. The traced paths are placed unchanged: no edits
// to any d= value, fill, or the mark's own viewBox.
function wayneuiTileSvg() {
  const raw = readFileSync(path.join(scriptDir, "src", "wayneui-mark-reverse.svg"), "utf8");
  const open = raw.indexOf("<svg");
  const openEnd = raw.indexOf(">", open) + 1;
  const close = raw.lastIndexOf("</svg>");
  if (open < 0 || openEnd < 2 || close < openEnd) fail("wayneui-mark-reverse.svg: no <svg> element");
  // Everything between the opening tag and the final </svg>, verbatim (the
  // file's own line endings included, so the paths stay byte-identical).
  const inner = raw.slice(openEnd, close).replace(/^\r?\n/, "");
  return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512" width="512" height="512" role="img" aria-label="WayneUI">
  <rect width="512" height="512" rx="80" ry="80" fill="#0C5449"/>
  <svg x="31.3" y="30.3" width="449.4" height="451.4" viewBox="0 0 1980 1989">
${inner}</svg>
</svg>
`;
}

async function buildCast() {
  console.log("cast:");
  const tile = Buffer.from(wayneuiTileSvg(), "utf8");
  record("cast/wayneui.svg", tile);
  const tilePng = await sharp(tile, { density: 300 })
    .resize(96, 96, { fit: "contain", background: { r: 0, g: 0, b: 0, alpha: 0 } })
    .png({ palette: true, quality: 85, compressionLevel: 9 })
    .toBuffer();
  record("cast/wayneui.png", tilePng);
  const stale = path.join(castDir, "wayneui-dark.png");
  if (existsSync(stale)) rmSync(stale);
  for (const name of CAST) {
    const src = castSource(name);
    if (!existsSync(src)) fail(`cast needs ${path.relative(repoRoot, src)}`);
    const png = await sharp(readFileSync(src))
      .resize(96, 96, { fit: "contain", background: { r: 0, g: 0, b: 0, alpha: 0 } })
      .png({ palette: true, quality: 85, compressionLevel: 9 })
      .toBuffer();
    record(`cast/${name}.png`, png);
  }
}

/* ---------------------------------------------------------------- 2. badges */

// Facts are read, never typed: php and the licence from composer.json, and
// the per-hop file cap from RunOptions. A badge that lies is a dose that
// didn't take.
function buildBadges() {
  console.log("badges:");
  const composer = JSON.parse(read("composer.json"));
  const cap = read("src/Run/RunOptions.php").match(/public int \$maxChanges = (\d+)/)?.[1];
  const defs = [
    { key: "php", label: "php", message: composer.require?.php },
    { key: "license", label: "licence", message: composer.license },
    { key: "cap", label: "cap per hop", message: cap && `${cap} files` },
  ];
  for (const d of defs) {
    if (!d.message) fail(`no fact to badge for "${d.key}"`);
    const svg = makeBadge({
      label: d.label,
      message: d.message,
      labelColor: C.greenDeep,
      color: C.gold,
      style: "flat-square",
    });
    record(`badges/${d.key}.svg`, svg);
  }
}

/* ------------------------------------------------------------- 3. the page */

async function openPage(browser) {
  const context = await browser.newContext({
    viewport: { width: 1280, height: 900 },
    deviceScaleFactor: 2,
    reducedMotion: "reduce", // every dose already stamped, already in orbit
  });
  // file:// only: refuse anything that tries to leave the machine.
  await context.route(/^(https?|wss?):/, (route) => route.abort());
  const page = await context.newPage();
  await page.goto(pathToFileURL(path.join(siteDir, "index.html")).href, { waitUntil: "load" });
  await page.evaluate(() => document.fonts.ready);
  return { context, page };
}

async function assertClean(page, selector, what) {
  assertCleanText(await page.locator(selector).innerText(), what);
}

async function cutJpg(page, selector, rel, width = 1280) {
  const png = await page.locator(selector).screenshot();
  const jpg = await sharp(png).resize({ width }).jpeg({ quality: 82, mozjpeg: true }).toBuffer();
  record(rel, jpg);
}

/* ------------------------------------------------------------------ 4. hero */

async function buildHero(page) {
  console.log("hero:");
  await page.addStyleTag({ content: ".masthead { display: none !important; }" });
  await assertClean(page, ".hero", "hero");
  await cutJpg(page, ".hero", "hero.jpg");
}

/* ---------------------------------------------- 5. the immunization record */

// Everything the record needs comes off the page's first patient (the Laravel
// 10 example app): the form line, the title, the course, the next booster due,
// and one entry per dose row. The hop ids, summaries, pairs, gate images and
// stamp text are the page's own words.
async function readRecord(page) {
  return page.evaluate(() => {
    const t = (el, sel) => el.querySelector(sel).textContent.trim();
    const card = document.querySelector("article.card");
    const doses = [...card.querySelectorAll(".dose")].map((d) => ({
      no: t(d, ".dose__no"),
      hop: t(d, ".dose__hop code"),
      sum: t(d, ".dose__sum"),
      pair: t(d, ".dose__pair"),
      gate: t(d, ".dose__gate"),
      stampTop: t(d, ".stamp__top"),
      stampMid: t(d, ".stamp__mid"),
    }));
    const result = card.querySelector(".card__result");
    return {
      form: t(card, ".card__form"),
      title: t(card, ".card__title"),
      cols: [...card.querySelectorAll(".card__cols span")].map((s) => s.textContent.trim()),
      fields: [...card.querySelectorAll(".card__fields > div")].map((f) => ({ k: t(f, "dt"), v: t(f, "dd") })),
      doses,
      pass: result.querySelector(".result").textContent.trim(),
      note: result.textContent.replace(result.querySelector(".result").textContent, "").trim(),
      tagline: document.querySelector(".hero__tagline").textContent.trim(),
    };
  });
}

function buildRecordSvg(rec, fonts) {
  const W = 1200;
  const font = "'Helvetica Neue', Helvetica, Arial, sans-serif";
  const mono = "ui-monospace, SFMono-Regular, Menlo, Consolas, 'Liberation Mono', monospace";
  const disp = "'Barlow Condensed', 'Arial Narrow', Arial, sans-serif";

  const n = rec.doses.length;
  const rowH = 74;
  const headH = 150;
  const colY = 184;
  const rowsY = 202;
  const footY = rowsY + n * rowH;
  const H = footY + 96;

  // Timeline (percent of a 14 s loop): countdown, six stamps, result, hold, reset.
  const COUNT = ["T-3", "T-2", "T-1", "LIFT-OFF"];
  const cStep = 6.4; // 0.9 s each
  const s0 = COUNT.length * cStep + 1.5; // first stamp
  const sGap = 8.5;
  const stampAt = (i) => +(s0 + i * sGap).toFixed(2);
  const passAt = +(stampAt(n - 1) + 4.5).toFixed(2);
  const HOLD = 93;
  const END = 97;

  const css = [];
  const stampKf = (i) => {
    const a = stampAt(i);
    return `@keyframes st${i} { 0%, ${a}% { opacity: 0; transform: scale(2.1) rotate(-6deg); } ${(a + 1.4).toFixed(2)}% { opacity: 1; transform: scale(.94) rotate(1deg); } ${(a + 2.4).toFixed(2)}%, ${HOLD}% { opacity: 1; transform: scale(1) rotate(0deg); } ${END}%, 100% { opacity: 0; transform: scale(1) rotate(0deg); } }`;
  };
  const rowKf = (i) => {
    const a = stampAt(i);
    return `@keyframes rw${i} { 0%, ${Math.max(a - 0.5, 0).toFixed(2)}% { opacity: 0; } ${(a + 1.5).toFixed(2)}%, ${HOLD}% { opacity: 1; } ${END}%, 100% { opacity: 0; } }`;
  };
  const cdKf = COUNT.map((_, i) => {
    const a = +(i * cStep + 0.4).toFixed(2);
    const b = +((i + 1) * cStep).toFixed(2);
    return `@keyframes cd${i} { 0%, ${Math.max(a - 0.4, 0)}% { opacity: 0; transform: scale(1.5); } ${(a + 1).toFixed(2)}%, ${(b - 1.2).toFixed(2)}% { opacity: 1; transform: scale(1); } ${b}%, 100% { opacity: 0; transform: scale(.9); } }`;
  });
  const total = (COUNT.length * cStep).toFixed(2);

  css.push(`    .cover { animation: cover 14s linear infinite; }
    @keyframes cover { 0% { opacity: 1; } ${total}% { opacity: 1; } ${(+total + 2.5).toFixed(2)}%, 100% { opacity: 0; } }`);
  COUNT.forEach((_, i) => css.push(`    .cd${i} { animation: cd${i} 14s linear infinite; transform-box: fill-box; transform-origin: center; }`));
  css.push(...cdKf.map((k) => "    " + k));
  rec.doses.forEach((_, i) => {
    css.push(`    .st${i} { animation: st${i} 14s linear infinite; transform-box: fill-box; transform-origin: center; }`);
    css.push("    " + stampKf(i));
    css.push(`    .hl${i} { animation: rw${i} 14s linear infinite; }`);
    css.push("    " + rowKf(i));
  });
  css.push(`    .pass { animation: pass 14s linear infinite; transform-box: fill-box; transform-origin: center; }
    @keyframes pass { 0%, ${passAt}% { opacity: 0; transform: scale(1.3); } ${(passAt + 1.6).toFixed(2)}%, ${HOLD}% { opacity: 1; transform: scale(1); } ${END}%, 100% { opacity: 0; transform: scale(1); } }`);

  const fontFace = fonts
    .map((f) => `    @font-face { font-family: "Barlow Condensed"; font-weight: ${f.weight}; src: url(data:font/woff2;base64,${f.b64}) format("woff2"); }`)
    .join("\n");

  // Header fields, two columns.
  const fieldXs = [660, 900];
  const fields = rec.fields
    .map((f, i) => {
      const x = fieldXs[i % 2];
      const y = 56 + Math.floor(i / 2) * 50;
      return `    <text class="fk" x="${x}" y="${y}">${esc(f.k.toUpperCase())}</text>
    <text class="fv" x="${x}" y="${y + 21}">${esc(f.v)}</text>`;
    })
    .join("\n");

  const colXs = [86, 146, 600, 826, 1010];
  const cols = rec.cols
    .map((c, i) => `    <text class="ch" x="${i === 4 ? 1078 : colXs[i]}" y="${colY}"${i === 4 ? ' text-anchor="middle"' : ""}>${esc(c.toUpperCase())}</text>`)
    .join("\n");

  const holes = [];
  for (let y = 40; y < H - 20; y += 38) holes.push(`<circle cx="30" cy="${y}" r="6"/>`);

  const rows = rec.doses
    .map((d, i) => {
      const y = rowsY + i * rowH;
      const cy = y + rowH / 2;
      return `  <g class="row">
    <rect class="hl hl${i}" x="58" y="${y}" width="${W - 58}" height="${rowH}" fill="${C.mint}"/>
    <path class="rule" d="M58,${y + rowH} H${W}"/>
    <text class="no" x="${colXs[0]}" y="${cy + 8}">${esc(d.no)}</text>
    <text class="hop" x="${colXs[1]}" y="${cy - 3}">${esc(d.hop)}</text>
    <text class="sum" x="${colXs[1]}" y="${cy + 21}">${esc(d.sum)}</text>
    <text class="pair" x="${colXs[2]}" y="${cy + 6}">${esc(d.pair)}</text>
    <text class="gate" x="${colXs[3]}" y="${cy + 6}">${esc(d.gate)}</text>
    <g transform="translate(1078 ${cy}) rotate(-7)">
      <g class="stamp st${i}">
        <rect class="s-out" x="-66" y="-27" width="132" height="54" rx="5"/>
        <rect class="s-in" x="-62" y="-23" width="124" height="46" rx="3"/>
        <text class="s-top" x="0" y="-6" text-anchor="middle">${esc(d.stampTop.toUpperCase())}</text>
        <text class="s-mid" x="0" y="16" text-anchor="middle">${esc(d.stampMid.toUpperCase())}</text>
      </g>
    </g>
  </g>`;
    })
    .join("\n");

  const cover = COUNT.map(
    (c, i) =>
      `    <text class="cd cd${i}${i === COUNT.length - 1 ? " cd-go" : ""}" x="${W / 2}" y="${rowsY + (n * rowH) / 2 + 40}" text-anchor="middle">${esc(c)}</text>`,
  ).join("\n");

  const desc =
    `${rec.form}. ${rec.title}. A short countdown, then a stamp lands on each hop BOOSTER plans, one row at a time: ` +
    rec.doses.map((d) => `${d.hop} (${d.pair}, ${d.stampTop.toLowerCase()})`).join("; ") +
    `. ${rec.pass}. ${rec.note}`;

  return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${W} ${H}" role="img" aria-labelledby="rec-title rec-desc">
  <title id="rec-title">BOOSTER's immunization record: one stamp per planned hop</title>
  <desc id="rec-desc">${esc(desc)}</desc>
  <style>
${fontFace}
    .form { font-family: ${font}; font-size: 14px; font-weight: 700; letter-spacing: 1px; fill: ${C.slateInk}; }
    .title { font-family: ${disp}; font-weight: 800; font-size: 46px; fill: ${C.green}; letter-spacing: .5px; }
    .tag { font-family: ${disp}; font-weight: 600; font-size: 24px; fill: ${C.slateInk}; letter-spacing: 1px; }
    .fk { font-family: ${font}; font-size: 12px; font-weight: 700; letter-spacing: 1.2px; fill: ${C.slateInk}; }
    .fv { font-family: ${font}; font-size: 17px; font-weight: 700; fill: ${C.ink}; }
    .ch { font-family: ${font}; font-size: 12px; font-weight: 700; letter-spacing: 1.4px; fill: ${C.slateInk}; }
    .rule { stroke: ${C.mintLine}; stroke-width: 1.5; fill: none; }
    .no { font-family: ${disp}; font-weight: 800; font-size: 28px; fill: ${C.green}; }
    .hop { font-family: ${mono}; font-size: 22px; font-weight: 700; fill: ${C.greenInk}; }
    .sum { font-family: ${font}; font-size: 15px; fill: ${C.slateInk}; }
    .pair { font-family: ${font}; font-size: 17px; font-weight: 700; fill: ${C.ink}; }
    .gate { font-family: ${mono}; font-size: 15px; fill: ${C.slateInk}; }
    .s-out { fill: none; stroke: ${C.green}; stroke-width: 3; }
    .s-in { fill: none; stroke: ${C.green}; stroke-width: 1; }
    .s-top { font-family: ${disp}; font-weight: 800; font-size: 16px; letter-spacing: 1.6px; fill: ${C.green}; }
    .s-mid { font-family: ${disp}; font-weight: 800; font-size: 24px; letter-spacing: 1px; fill: ${C.green}; }
    .res-chip { fill: ${C.green}; }
    .res-t { font-family: ${disp}; font-weight: 800; font-size: 26px; letter-spacing: 1.4px; fill: ${C.gold}; }
    .note { font-family: ${font}; font-size: 15px; fill: ${C.ink}; }
    .cover-bg { fill: ${C.greenDeep}; }
    .cd { font-family: ${disp}; font-weight: 800; font-size: 150px; letter-spacing: 4px; fill: ${C.gold}; }
    .cd-go { font-size: 120px; }
    .holes circle { fill: ${C.mint2}; }

    /* The finished record is the resting state: with no animation (or reduced
       motion) every dose is stamped and the countdown is gone. */
    .cover, .cd { opacity: 0; }
    .hl { opacity: 1; }
    .stamp, .pass { opacity: 1; }
${css.join("\n")}

    @media (prefers-reduced-motion: reduce) {
      .cover, .cd, .stamp, .pass, .hl { animation: none !important; }
      .cover, .cd { opacity: 0; }
      .stamp, .pass, .hl { opacity: 1; transform: none; }
    }
  </style>

  <rect x="0" y="0" width="${W}" height="${H}" rx="22" fill="${C.cream}"/>
  <rect x="58" y="0" width="${W - 58}" height="${headH}" fill="${C.mint}"/>
  <path d="M58,${headH} H${W}" stroke="${C.green}" stroke-width="3"/>
  <path d="M58,0 V${H}" stroke="${C.mintLine}" stroke-width="1.5" stroke-dasharray="5 4"/>
  <g class="holes">${holes.join("")}</g>

  <text class="form" x="92" y="44">${esc(rec.form.toUpperCase())}</text>
  <text class="title" x="90" y="98">${esc(rec.title)}</text>
  <text class="tag" x="92" y="130">${esc(rec.tagline.replace(/\.$/, "").toUpperCase())}</text>
  <g>
${fields}
  </g>
${cols}

${rows}

  <g class="pass">
    <rect class="res-chip" x="92" y="${footY + 24}" width="196" height="42" rx="6"/>
    <text class="res-t" x="190" y="${footY + 54}" text-anchor="middle">${esc(rec.pass.toUpperCase())}</text>
    <text class="note" x="308" y="${footY + 51}">${esc(rec.note)}</text>
  </g>

  <g class="cover-wrap">
    <rect class="cover cover-bg" x="58" y="${headH}" width="${W - 58}" height="${H - headH}"/>
${cover}
  </g>
</svg>
`;
}

function fontB64(rel) {
  return readFileSync(path.join(siteDir, rel)).toString("base64");
}

async function buildRecord(page) {
  console.log("record:");
  const rec = await readRecord(page);
  if (rec.doses.length < 3) fail("the record card has fewer than three dose rows");
  // Every hop id must be a hop BOOSTER really plans: the ids are the page's
  // own words, and the registry must still build php- and laravel- hops.
  const reg = read("src/Hop/HopRegistry.php");
  for (const d of rec.doses) {
    const m = d.hop.match(/^(php|laravel)-(\d+)-to-(\d+)$/);
    if (!m) fail(`hop id ${d.hop} is not a php-/laravel- hop`);
    if (!reg.includes(m[1] + "-")) fail(`HopRegistry no longer builds ${m[1]} hops`);
  }
  assertCleanText(JSON.stringify(rec), "record text");
  const fonts = [
    { weight: 800, b64: fontB64("assets/fonts/barlow-condensed-latin-800-normal.woff2") },
    { weight: 600, b64: fontB64("assets/fonts/barlow-condensed-latin-600-normal.woff2") },
  ];
  const svg = buildRecordSvg(rec, fonts);
  assertCleanText(svg.replace(/data:[^)"]+/g, ""), "record.svg");
  record("record.svg", Buffer.from(svg, "utf8"));
}

/* -------------------------------------------------------------- 6. gallery */

// Two cuts of the real page: the record card as the visitor sees it, and the
// Laravel x PHP grid with the six-dose path through it. Both come from the
// finished (reduced-motion) state.
async function buildGallery(page) {
  console.log("gallery:");
  await assertClean(page, "article.card", "card");
  await cutJpg(page, ".record__body", "card.jpg");
  await assertClean(page, "#pairs", "pairs");
  await cutJpg(page, "#pairs", "pairs.jpg");
}

/* ---------------------------------------------------------------- 7. board */

async function buildBoard() {
  console.log("board:");
  const src = path.join(siteDir, "brand", "booster-board.png");
  if (!existsSync(src)) fail("site/brand/booster-board.png is missing");
  let q = 82;
  let jpg;
  do {
    jpg = await sharp(src).resize({ width: 1400 }).jpeg({ quality: q, mozjpeg: true }).toBuffer();
    q -= 6;
  } while (jpg.length > MAX_BYTES && q >= 60);
  record("board.jpg", jpg);
}

/* -------------------------------------------------------------------- main */

async function main() {
  await buildCast();
  buildBadges();
  await buildBoard();

  const browser = await chromium.launch();
  try {
    const { context, page } = await openPage(browser);
    await buildRecord(page);
    await buildGallery(page);
    await buildHero(page); // last: it hides the masthead
    await context.close();
  } finally {
    await browser.close();
  }

  let over = false;
  for (const { rel, bytes } of outputs) {
    if (bytes > MAX_BYTES) {
      console.error(`FAIL: ${rel} is ${bytes} bytes (limit ${MAX_BYTES})`);
      over = true;
    }
  }
  if (over) process.exit(1);
  console.log(`done: ${outputs.length} files, all at or under ${MAX_BYTES} bytes`);
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
