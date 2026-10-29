# Changelog

All notable changes to BOOSTER are recorded here. The format follows Keep a Changelog.

## [Unreleased]

### Changed

- Changed: the scan skip message and the README say how to approve the PHPCompatibility installer plugin.

## [0.1.3] - 2026-10-29

### Added

- Added: agent-ready site (prompt blocks, Markdown twins, llms.txt), from Squire's kit.

### Fixed

- Fixed: stale capability claims in the site and README.

### Changed

- Changed: the proof page moves to a private site that needs a Wayne State sign-in.
- Changed: project names written as words except acronyms.

## [0.1.2] - 2026-10-29

- CREED reader re-exported from CREED 0.1.2: versions older than the oldest listed line are now end of life instead of refused.

## [0.1.1] - 2026-10-29

### Added

- `data/versions.json`, the hop data file (shipped in the PHAR), and `bin/refresh-hop-data.php`, which rewrites it
  from the Packagist, endoflife.date and npm feeds plus CREED's policy. `composer ci` ends with the offline `--check`
  (shape validation against the bundled policy, then a recompute from the frozen feeds in `tests/fixtures/hop-feeds`
  as of the file's `generated_on` date); `--online` compares against the live feeds without writing the file.
- A scheduled GitLab pipeline job that runs the refresh against the live feeds and fails when `data/versions.json`
  would change, so a feed that moved while the file slept is visible until someone commits the refreshed file.
- A plan warning when the target major has no Rector ruleset yet: BOOSTER stops short of the target instead of
  failing.

### Changed

- The PHP, Laravel and PHPUnit hop lists now come from `data/versions.json`; a major whose Rector ruleset is not
  installed gets no hop.
- The `node-pin-24` hop now floors Node at the hop data's target (26 today, following the policy's newest supported
  major) and runs its npm work in the matching `node:` container.

## [0.1.0] - 2026-10-29

### Added

- `booster plan`, `run`, `hops`, `detect` and `init` commands (also `vendor/bin/booster`).
- App-type detection for the five stack permutations from `composer.json` and `package.json`.
- A hop registry: PHP one minor at a time from 5.6 to 8.5, Laravel one major at a time from 8 to 13, a `wayne-cas` to
  SESAME stub recipe, and a Vue 2 to 3 playbook.
- PHPUnit hops, one major at a time from 9 to 13, planned when the app's `phpunit/phpunit` constraint is detected and
  the target PHP can run them; a `--to-phpunit` target option and a `PHPUnit:` line in the plan.
- A planner that interleaves PHP, Laravel and PHPUnit hops inside Laravel's supported PHP range and refuses
  unsupported targets.
- Policy loading from `--policy`, `BOOSTER_POLICY`, or a bundled snapshot of CREED's `policy.json`.
- A generated copy of CREED's policy reader under `src/Creed/` (namespace `Waynestate\Booster\Creed`), written by
  CREED's `bin/export.php` and verified by `composer creed-copy:check`; BOOSTER's own reader is gone.
- A Rector runner on `rector/rector` 2.8 and `driftingly/rector-laravel` 2.7 with a per-run file cap, a PHP target
  override and a synthetic `installed.json`.
- A `composer.json` editor for PHP, Laravel and SESAME constraints.
- A target-image test gate (`docker/Dockerfile`, `booster-target:<php>`).
- A recorded baseline test run before the first hop: the app's suite on its current PHP, so a later red gate can be
  told apart from a suite that was already red. A red baseline stops the run unless `--allow-failing-baseline` is
  passed, in which case later gate failures are labelled as pre-existing.
- Coding-tab playbooks generated into `.agents/skills`, with `booster init --check`.
- A contract (`contract/expectations.json`) and example apps for all five permutations.
- GitLab and GitHub CI that both call `composer ci`.
- A plan warning when the target is newer than CREED's policy (treated as current).
- A `nothing-to-do` status for a hop that changes no files and skips the gate.
- An incomplete-preview marker for a Laravel hop whose app has no `vendor/`, plus a unique-files summary and a
  closing line in the dry-run report.
- A `node-pin-24` hop for apps with a `package.json`: it sets `engines.node` to `>=24` and writes `.nvmrc`, is planned
  after the PHP hops and ahead of the front-end playbooks, and skips itself when the app already floors Node at 24 or
  newer. The npm work runs in a `node:24` container, so the machine needs Docker but no Node; a hop may also ask for a
  build gate (`npm ci` and `npm run build` in that container).
- A `mix-to-vite` hop for apps with a `webpack.mix.js`, planned after `node-pin-24` when the plan lands on Laravel 9 or
  newer: it writes `vite.config.js`, swaps `laravel-mix` for `vite` and `laravel-vite-plugin` in `package.json` (plus
  `@vitejs/plugin-vue` when the Mix file compiled components), renames `MIX_` keys to `VITE_` in `.env.example`,
  rewrites Blade `mix()` script and link tags into one `@vite([...])` line per layout when every referenced asset maps
  to a Mix entry, and deletes `webpack.mix.js`. Only the simple shape converts: `mix.js`, `.vue()`, `.sass()`,
  `.postCss()` and `.version()`; anything else is refused by name, as is a Vue 2 front end (run `vue-2-to-3` first).
  Unmapped references, bare `mix()` calls and `withoutMix()` sites are left alone and listed as manual notes. The gate
  runs `npm ci`, `npm run build` and a `public/build/manifest.json` check in the `node:24` container, then the app's
  tests.
- `booster detect` now reports the Node floor from `engines.node`.
- An advisory PHPCompatibility scan (`phpcompatibility/php-compatibility` 10.0.0-alpha2 on
  `squizlabs/php_codesniffer` 3.13): `plan` shows "Will break on PHP X.Y" for the first PHP hop's target, and a new
  `booster scan --path=... --php=X.Y [--format=json]` command runs it on demand. Findings never change exit codes; a
  scan that cannot run is reported as "could not run" without failing the plan. The scan ignores `vendor/`,
  `node_modules/` and `storage/`; `plan --no-scan` skips it.

### Changed

- The PHPCompatibility packages (`phpcompatibility/php-compatibility`, `squizlabs/php_codesniffer`) moved from `require`
  to `require-dev` and `suggest`: the advisory scan is now optional, and BOOSTER reports a skip when no installed
  PHP_CodeSniffer carries the PHPCompatibility standard.
