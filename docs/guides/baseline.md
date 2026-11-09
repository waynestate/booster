---
title: The baseline
description: The test run that separates a bad hop from a suite that was already red.
---

# The baseline

This page explains the baseline `run` takes before the first hop. Next: [A smoke test for WSU apps](smoke-test.md).

Before the first hop, `run` runs the app's own tests (or the built-in `php -l` syntax check when the app has no
tests) on the PHP the app is on now, in the same `booster-target:<php>` image the gate uses. The point is to tell
"Booster broke it" from "it was already broken": a red baseline means the suite was red before any change, so a
later red gate is not proof of a bad hop. The baseline is recorded in the report (`passed`, `failed` or `skipped`,
with the exit code and the last 20 lines of output).

- A red baseline stops the run before the first hop, with a non-zero exit. `--allow-failing-baseline` lets it go on;
  each later gate failure is then labeled "the baseline was already failing" in the text and JSON output.
- `--skip-gate` and `--dry-run` skip the baseline (reported as `skipped`), so a dry run never starts Docker.
- When the baseline's `composer install` fails on the PHP version, the report says which of two causes it was and
  what to run, instead of the general advice: `composer.json` already requires a newer `php` than `--from-php` (where
  a run stands after it stopped at a PHP hop; run again without `--from-php`), or a locked package's own
  `require.php` leaves that PHP out (the report names the `composer update -W` to run). The JSON baseline carries the
  same text as `advice`.
- A Mix app's `public/mix-manifest.json` is a build artifact, not committed source: a fresh clone has no manifest, so
  the suite can be red before any hop. Build the assets before the baseline (the app's usual `npm ci && npm run
  build`), or pass `--allow-failing-baseline` knowing why.
- When the app has no tests of its own, the baseline and every gate run a built-in check instead: every PHP file
  outside `vendor/` and `node_modules/` must still parse on the target PHP (`php -l`). That proves the code loads on
  the new PHP, not that it works: click through the app before you ship. Pass `--test-command` to run something else,
  or add a smoke test ([A smoke test for WSU apps](smoke-test.md)) to get the real gate back.
  Without `--replay`, the run also says so under the baseline and names `booster replay init`, because [the replay gate](replay-gate.md)
  records what the pages answer and fails a hop that changes one.
