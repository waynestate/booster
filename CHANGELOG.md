# Changelog

All notable changes to Booster are recorded here. The format follows Keep a Changelog.

## [Unreleased]

## [1.5.0] - 2026-10-31

Behaviour-change warnings, from the second real-use report: a hop can pass every gate and still change what users see.

### Added

- Behaviour changes to check: `booster plan`, the run report and `--format=json` (`behaviour_changes`) now name the
  silent changes a step brings: things that still pass every gate but behave differently, such as Carbon 3 reading
  timestamps as UTC, Laravel 8's Tailwind paginator, or a SweetAlert toast that now closes on a new key. Each entry
  shows where the app is affected (up to 5 `file:line` hits and a count), the setting that keeps the old behaviour
  when there is one, and what to check by hand. Advisory only: it never blocks or fails a step.
- `data/behaviour-changes.json`: 63 curated entries (Laravel 5.8 to 13 and Carbon 3, PHP 7.1 to 8.5, and package
  majors of sweet-alert, Guzzle and Sanctum), each with its source link. An entry fires only when the app's code,
  config or composer.json shows it is affected; three apply to every app crossing their step. Package entries print
  on the plan's last step, compared against the lock as it was before the run.

## [1.4.0] - 2026-10-30

Fixes from the second real-use report (two apps taken to PHP 7.4/Laravel 8 and PHP 8.5/Laravel 13 with SESAME).

### Added

- `--test-env KEY=VALUE` (repeatable) passes settings, such as a database host, to the baseline and every gate.
  Reports name the keys only, never the values.
- Citadel apps (a `deployments/` folder) get a once-only `deployments/0000_composer_install_<stamp>` script when a run
  changes `composer.lock`, because Citadel copies the old `vendor/` into each new deployment and never re-runs a
  deployment script that already ran. It adds `php artisan migrate --force` only when the app has `artisan`.
- The skill has an optional ADVISOR section with the real install lines.

### Changed

- `cas-to-sesame` leaves an app that signs people in. Before, the hop reported success on an app that would not boot.
  The hop now requires `waynestate/sesame:^0.3` and:
  - writes SESAME's own published config with two changes: the CAS root falls back to the app's old wayne-cas root
    (the environment and the playbook still win), and the wayne-cas compat user resolver is the default. The env
    lookups, `SESAME_DRIVER` and the `fake` block for local work are kept;
  - rewrites every model using wayne-cas's `WayneCasable` trait, in any form including Rector's fully qualified one,
    to implement SESAME's `WayneCasable` and use `ReadOnlyAttributes`, with the one `getReadOnlyAttributes` signature.
    A leftover trait use fails the step instead of passing silently, and stale `WSU\WayneCas` imports are removed;
  - moves `WayneAuthenticate` from the global `web` group to the app's web routes (`wayne.cas` alias);
  - points the `web` guard at SESAME's `sesame` driver and the users provider at `eloquent`, so `auth()->user()`
    works and the user is reloaded every request.
  - removes the old wayne-cas repository entry from `composer.json`, which pointed at a repository that needs a sign-in;
  - requires `guzzlehttp/guzzle` when the app has neither Guzzle nor `symfony/http-client`, since SESAME needs an HTTP
    client.
  Anything Booster cannot edit exactly becomes a manual step naming the file, and the report lists only changes it
  made.
- Packages Booster adds to `composer.json` are appended, keeping the app's order. They are sorted, in Composer's order,
  only when the app sets `config.sort-packages`.
- The weekly hop-data refresh treats a change of date alone as no change, so it opens a merge request only when the
  data changed.

### Fixed

- The hop-data robot crashed on its first GitLab API call. Booster now locks CREED 0.1.4, whose bot client is fixed.
- PROCTOR runs as the final target even when the plan has nothing left to do.
- The Pinstripe scan uses Pinstripe's `laravel.neon` (Larastan) on Laravel apps that have Larastan, and skips IDE
  helper files, so Laravel apps no longer get a wall of false "undefined method" findings.
- `laravel-8-to-9` removes `fideloper/proxy` (it does not allow Laravel 9) and moves a stock `TrustProxies` to
  `Illuminate\Http\Middleware\TrustProxies`; a customised one gets a manual note. The plan suggests HELMET when that hop
  is in it and the app has no HELMET.

## [1.3.0] - 2026-10-30

Fixes from the first real use of Booster on two Laravel apps (PHP 7.1 and 7.3, Laravel 5.8 and 8, CAS, oci8).

### Changed

- Laravel and PHPUnit hops now update `composer.lock`: the gate runs `composer update -W` for the packages the hop
  changed, inside the target image, and the new lock is part of the hop's diff. Before, the gate failed at
  `composer install` (Composer 2.10) or quietly tested the old framework. Dry runs never update anything.
- A failed `composer install` or `composer update` is reported as a Composer failure with its output, never as
  "phpunit exited 4", and a baseline whose install failed is no longer reported as failing tests.
- Plans with `--sesame` swap CAS for SESAME before `laravel-10-to-11`, as soon as the app is on PHP 8.3, because
  wayne-cas 2.x stops at Laravel 10.
- Apps on PHPUnit below 9 get a manual `phpunit-to-9-manual` step before the first PHP 8 hop, instead of a warning
  that skipped every PHPUnit hop.
- The app's current PHP comes from what `composer.lock` allows when that is newer than the `composer.json` floor;
  `detect` shows the lock's PHP range and says which package sets it.
- Rector never edits generated IDE helper files (`_ide_helper*.php`, `.phpstorm.meta.php`).
- A Laravel hop's dry run against a `vendor/` from an older Laravel says "can't preview yet" instead of ERROR.
- The undo hint also names the files a failed step created (`git clean -fd -- <paths>`), and only those.
- The SESAME report says what the swap actually wrote to each model.
- Laravel hops remove the imports their Rector rules left unused.

### Added

- `plan` checks each direct dependency's newest release (Packagist, and composer-type repositories the app lists)
  against every planned hop: it names the first hop a package blocks and flags abandoned packages.
  `--no-package-check` turns it off for offline use.
- `--until=<hop>` stops the plan after that hop; `--only=<hop>` runs a single hop.
- When Composer refuses tagged releases for known security advisories (Laravel 9 and 10 fall back to `9.x-dev`
  without an error on Composer 2.10), Booster adds a temporary, scoped `config.policy.advisories.ignore` entry,
  says so, and fails the final target while the entry is still there.
- Target images for PHP 7.1 to 8.0 build again: their Debian releases moved to archive.debian.org, and PHP 7.1 gets
  Composer 2.2 LTS.
- `--image` takes a `{php}` template (`registry/php-apache:{php}`) or a per-version map (`8.0=a:1,8.1=b:2`), so a
  PHP hop can gate on a different image from its baseline. The README covers arm64 Macs.
- A warning when the app's tests are only Laravel's stock examples, and a README section on a smoke test that works
  for apps behind CAS.

## [1.2.0] - 2026-10-30

### Changed

- The gate's target image carries the extension set Citadel's apps run with: intl, ldap, soap, zip, gd, pdo_mysql,
  mysqli, bcmath, pcntl, exif, redis, imagick and oci8 (before: zip, pdo_mysql, mysqli and bcmath). The extensions
  come from mlocati/php-extension-installer, pinned to 2.13.1 by digest; it also downloads Oracle Instant Client for
  oci8, so the first build takes a few minutes.
- Target image tags are stamped with a short hash of `docker/Dockerfile` (`booster-target:<php>-<stamp>`), so an
  edited recipe rebuilds the image instead of reusing a stale tag.

### Added

- `--image` for `run`: run the gate in a Docker image of your own instead of building `booster-target`. The image
  needs `php` and `composer` on `PATH`; Booster uses it when it is present locally and pulls it otherwise.
- `scripts/check-target-image.sh` and `scripts/check-target-image.ps1`: build the target image for each PHP version
  and verify its extension list.

## [1.1.0] - 2026-10-30

### Changed

- The 50-file cap per hop becomes a 200-file review threshold: an interactive run shows the per-rule summary and asks
  before applying a bigger hop, while non-interactive and JSON runs stop at the threshold. `--max-changes` is now an
  explicit hard limit that never prompts, and `0` lifts the limit entirely.

### Fixed

- The GitLab release job runs on any runner architecture: it calls the Releases API with curl from a multi-arch image
  instead of the deprecated release-cli.

## [1.0.0] - 2026-10-30

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
- mix-to-vite accepts if (mix.inProduction()) { mix.version(); } and drops it with a note; its refusal text lists
  the accepted calls.
- A failed baseline whose output mentions a Mix or Vite manifest now names the missing build artifact.

### Changed

- The version policy is read straight from the `waynestate/creed` package (`^0.1`) in Booster's own `vendor/`, and the
  plan names it as `waynestate/creed <version>`. There is no bundled copy to refresh.
- `--skip-gate` also skips the PROCTOR check at the end.
- Exit code 1 now covers new PROCTOR findings.
- The Preflight components and the GitHub reusable workflow pin the `0.1.2` tag instead of a commit SHA.
- The deploy layer's Squire kit is refreshed to 0.3.4 (release options can live in `./.env.squire`, which is now
  git-ignored).
- The scan skip message and the README say how to approve the PHPCompatibility installer plugin.
- Bound targets (--to-php and the rest) leave Node and Vue hops out unless --with-node is passed; the plan says
  so.

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
  `.postCss()`, `.version()` and `if (mix.inProduction()) { mix.version(); }` (dropped with a note, since Vite
  hashes production assets); anything else is refused by name, as is a Vue 2 front end (run `vue-2-to-3` first).
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
