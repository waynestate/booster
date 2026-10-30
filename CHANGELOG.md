# Changelog

All notable changes to Booster are recorded here. The format follows Keep a Changelog.

## [1.0.0] (not yet released)

### Added

- The PROCTOR policy gate: a baseline before the first hop and a final check after the last, so only findings new
  against the baseline block the run. The report groups New, Already present and Fixed; `--proctor-advisory` makes it
  report-only; the JSON report carries a `proctor` object with `status`, `blocking`, `new`, `already_present`, `fixed`
  and `baseline`.
- The PHPStan advisory scan at the destination PHP, using Pinstripe's `base.neon` preset: `plan` ends with it and
  `booster scan --phpstan` runs it alone. Findings never change the exit code; a scan that cannot run is reported with
  the command that enables it.
- The removals advisory: `data/removals.json` lists the functions and constructs removed in each PHP version,
  `booster scan --removals` lists uses up to `--php`, and each PHP hop in `plan` and `run` prints what was removed
  between its two versions. The scan is token-level and reads the app before the hop rewrites it.
- A pre-pass on PHP hops: PHP 4 era `$a = &new Foo();` becomes a plain assignment before Rector, because modern PHP
  refuses to compile the old spelling. The rewrites are listed per file under `Pre-pass fixes:`.
- `--memory-limit` for the Rector process (default `2G`, `-1` for no limit); an empty or invalid Rector report points
  at the flag.
- Hops for the oldest apps: `php-55-to-56`, `laravel-5.8-to-6`, `laravel-6-to-7` and `laravel-7-to-8`. The hop list is
  now 32.
- `cas-to-sesame` is a real migration, not a stub: it renames the `WSU\WayneCas\` classes to SESAME's compatibility
  namespace, swaps the Composer requirement for `waynestate/sesame ^0.2`, writes `config/sesame.php` from
  `config/wayne-cas.php` and rewrites models that use `WayneCasable` as a trait. Duo two-factor stays manual work.
- Vue 2 to Vue 3 as staged hops: `vue-26-to-27`, `vue-27-to-compat` and `vue-compat-codemod` (the official
  `vue-upgrade-tool`). The `vue-2-to-3` playbook stands in for all three; skipping it skips them.
- The `tailwind-3-to-4` hop: the official `@tailwindcss/upgrade` tool, then `tailwindcss` pinned to `^4.1`, the
  matching Vite or PostCSS plugin added, and `autoprefixer` and `postcss-import` removed.
- Inertia support in the Laravel hops: an app that carries `inertiajs/inertia-laravel` and `@inertiajs/vue3` gets both
  constraints raised toward the target major. Booster never adds a package the app does not have.
- Laravel Boost: `detect` and `plan` report apps that carry `laravel/boost`, and every hop playbook has a Boost
  section.
- The PHAR release: every version tag publishes `booster.phar` as a GitLab release asset and a GitHub release
  attachment. The build prints a SHA-256 checksum, and `scripts/build-phar.sh` has a no-Docker mode for CI.
- The hop data robot: `bin/hop-data-bot.php` runs in the scheduled pipeline and opens (or updates) a merge request on
  Booster when the refresh changes `data/versions.json`; a person reviews and merges.
- `waynestate/proctor ^0.2` as a runtime requirement.

### Changed

- The version policy is read straight from the `waynestate/creed` package (`^0.1`) in Booster's own `vendor/`, and the
  plan names it as `waynestate/creed <version>`. There is no bundled copy to refresh.
- `--skip-gate` also skips the PROCTOR check at the end.
- Exit code 1 now covers new PROCTOR findings.
- The Preflight components and the GitHub reusable workflow pin the `0.1.2` tag instead of a commit SHA.
- The deploy layer's Squire kit is refreshed to 0.3.4 (release options can live in `./.env.squire`, which is now
  git-ignored).
- The scan skip message and the README say how to approve the PHPCompatibility installer plugin.

### Removed

- The generated copy of CREED under `src/Creed/` (reader, `policy.json`, manifest) and the `composer creed-copy:check`
  script: the policy comes from the `waynestate/creed` package.

### Fixed

- Invalid UTF-8 in a Rector report is repaired instead of stopping the run; the report notes the repair.
- Rector parse errors keep their line numbers.
- `vue-compat-codemod` keeps unrecognised output lines whole and cuts long output on a character boundary.

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
