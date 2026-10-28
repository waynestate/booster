# BOOSTER README art kit

Cuts the README images under `docs/readme/` from this repo's own front page
(`site/`), brand files and source. Nothing is drawn freehand: the hero and the
two gallery cuts are screenshots of the real page, the cast is the marks
resized, the badges read their facts from `composer.json` and
`src/Run/RunOptions.php`, and the record card is the site's own Form B-01 for
the Laravel 10 example app, one row per hop.

## Run

Windows PowerShell and Linux/macOS sh, side by side:

| Windows PowerShell | Linux / macOS sh |
|---|---|
| `npm --prefix scripts/readme-art ci` | `npm --prefix scripts/readme-art ci` |
| `npx --prefix scripts/readme-art playwright install chromium` | `npx --prefix scripts/readme-art playwright install chromium` |
| `node scripts/readme-art/build.mjs` | `node scripts/readme-art/build.mjs` |

The script resolves every path from its own location, so it works from any
folder on any OS. It opens `site/index.html` as a `file://` URL, aborts any
request that tries to leave the machine, and is deterministic: same inputs,
same outputs.

## What it makes

- `docs/readme/hero.jpg`: the hero and its launch pad, with the masthead hidden.
- `docs/readme/record.svg`: the immunization record. A short countdown, then a
  stamp lands on each planned hop, one row at a time. Hop ids, summaries,
  pairs, gate images and stamp text are read from the page's record card; the
  build fails if the card changes shape or a hop id is not one the registry
  builds. Under `prefers-reduced-motion` it is the finished record, standing
  still.
- `docs/readme/card.jpg` and `pairs.jpg`: the record card and the Laravel by
  PHP grid, cut from the page.
- `docs/readme/board.jpg`: the brand board, re-encoded.
- `docs/readme/cast/*.png`: one 96x96 mark per stack part, WayneUI on its
  green tile first.
- `docs/readme/badges/*.svg`: `php`, `licence` and `cap per hop`, made locally
  by `badge-maker`.

Every file must stay at or under 300 KB, and no captured text may name an
internal host; the script prints each size and exits 1 if either rule breaks.

If a stamp ever lands on the wrong row, fix the hop, not the stamp pad. It
stings less that way.
