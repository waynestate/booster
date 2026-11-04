# Changelog

All notable changes to Booster are recorded here. The format follows Keep a Changelog.

## [Unreleased]

## [1.8.3] - 2026-11-04

### Changed

- Docs: a new "How do I..." page (`docs/recipes.md`) with 14 recipes: look first, go all the way, PHP only, a specific Laravel, one hop at a time, dry run, backend only, skipping a hop, big apps, tests and the gate, offline and dirty trees, SESAME and WayneUI, other dates and policies, and what to do after a stop. Linked from the docs menu, the front page of the docs, the quick start and the README.
- Docs: the quick start and README gain "What a run does by default": what `detect`, `plan` and `run` do with no flags, the hop-count rule (one hop per PHP minor, Laravel major and PHPUnit major), the extras that come by default or only on request, what a run does per hop, and the ways to stop early. The command reference points to it.

### Fixed

- The second Booster run on a machine no longer dies on hop 1 with `"phar:///tmp/booster-phar-.../phpstan.phar/.../standard_10.stub" is not a file`. The phar unpacks into a new temp directory each run and removes it at exit, but Rector's PHPStan cached its container (and the PHPStan scan its result cache) in the shared temp directory, with absolute paths into the first run's deleted copy. Every Rector cache, PHPStan container, PHPStan scan `tmpDir` and the child processes' `TMPDIR` now live in a per-run scratch directory (`booster-run-<random>`) that is removed at exit, so no run reads another's cache. Tests prove each path is per-run and that the real Rector writes its container there.
- The `php-74-to-80` hop no longer turns a loose `switch` into a strict `match`. Rector's `ChangeSwitchToMatchRector` rewrote `switch ($get['order'][0]['column'])` with `case 1:` into `match (...) { 1 => ... }`, which never matches the string `"1"` from a query string, so every request silently fell to `default`. The rule is skipped for every hop; a test runs the real Rector on a string subject and asserts no `match` appears.
- The gate image builds for PHP 5.5 and 5.6 (Debian jessie): the archive.debian.org rewrite now covers jessie, reads the codename from `VERSION` when `VERSION_CODENAME` is missing, maps `httpredir.debian.org` and a bare `security.debian.org` to the archive, and lets apt use the archive whose signing keys have expired.
- When the gate image cannot be built, `booster run` exits non-zero and says so: "Could not build booster-target:<php>-<stamp> (docker build exit N), so the gate cannot run", with the ways out (`--image`, `--skip-gate`) and the last 40 lines of the build output instead of BuildKit's whole log.

## [1.8.2] - 2026-11-04

### Added

- The Real output page, `site/proof.html`: Booster's own plan and dry-run output on each example app, captured from the real tool, linked from the front page, with a Markdown twin and entries in `llms.txt` and `llms-full.txt`. `composer proof` rebuilds it, `composer proof:capture` re-captures it, and `composer ci` fails when it is stale.

### Changed

- The docs pages wear Booster's own look instead of the generic builder one: the front page's masthead and main menu (Docs marked as the current item, and a gold "Run it today" pill on every page that lands on the install one-liners), Barlow Condensed titles with the hero's gold underline, the install section's green slab for commands, the greenbar printout for output, Booster's palette in light mode and a derived deep-green dark mode. It comes from the docs builder's new `theme` key (`docs/_header.html` and `docs/theme/booster.css`); the builder and kit come from Squire's theme-hook branch, which also fixes two kit bugs: the copy button said "Copy failed" after every successful copy, and code blocks that scroll sideways could not be reached by keyboard.
- OPEN_QUESTIONS: the "Not built yet" list now says what shipped (the `&new` pre-pass in 1.0.0, the real-app `cas-to-sesame` hop in 1.6.0, the proof runs of 2026-10-28 and 2026-11-01) and what is still open.
- The front page main menu now links to the docs site (`docs/`), between the Agents and Proof links.

### Fixed

- `booster run` (dry or real) no longer stops a hop with "Rector reported errors" and `PHPStan\PhpDocParser\Ast\Type\CallableTypeNode::__construct(): Argument #1 ($identifier) must be of type ... IdentifierTypeNode, ... FullyQualifiedNameNode given` on any app that has a closure (an `array_map(function ...)`, a route closure). 1.8.0 and 1.8.1 locked Rector 2.8.1 next to PHPStan 2.3.4, and the two load different copies of phpdoc-parser in one process. The lock now pairs Rector 2.8.4 with PHPStan 2.3.5, a new test runs the real Rector (not the fake runner) on a tiny app with a closure so `composer ci` fails if the pair splits again, and Renovate updates the two packages together.
- The Real output page's Markdown twin link points at proof.md, so the agent-ready check passes.

## [1.8.1] - 2026-11-03

### Changed

- Synced Squire's docs builder and agent-ready kit (`bin/docs-site.php`, `bin/agent-ready.php`, `site/assets/agent-ready.js`): table cells strip Symfony console style tags, and the kit JS uses bindingless catch blocks. The command reference and docs site are rebuilt.
- The PHPCompatibility check no longer calls `oci_*()` functions "removed since PHP 8.4" for an app that declares `ext-oci8` in `require`, `require-dev` or `config.platform`: PHP 8.4 moved oci8 out of the PHP download and into PECL, and such an app installs the PECL extension on purpose. Other findings are unchanged.
- The `cas-to-sesame` step now requires SESAME `^0.4` (SESAME 0.4.0 is out; a `^0.3` constraint would have stayed on 0.3.x), and the SESAME config Booster falls back on when the app has no vendor copy is SESAME 0.4.0's: it gains `gateway.public_keys`, `oauth2.require_at_jwt` and `log`. The step's own edits are unchanged; SESAME 0.4.0 only added optional settings.
- A dry-run step with no edits prints "General notes for this step (nothing found in your app, just good to know):" before the hop's version notes, so they no longer read like findings in your app.
- `booster init` says why it writes one more playbook than there are steps ("one for each step Booster knows, plus a general one"), and the quick start lists it as an optional line.
- Changed: the front-page deploy layer pins HELMET ^0.3, PROCTOR ^0.4 and CREED ^0.1 (lock: HELMET 0.3.0, PROCTOR 0.4.0, CREED 0.1.6).
- Changed: CI pins Preflight 0.1.4 (GitLab component includes and the GitHub reusable workflow at its tag commit); the deploy layer sets minimum-stability to stable.
- `booster --help` and `booster -h` print Booster's own overview (one plain sentence, then the command list) instead of the help of the internal `list` command; `booster plan --help` is unchanged.
- The plan's closing line no longer says steps are "marked automatic" (nothing was ever printed that way): it reads "Booster does every numbered step above when you run it." and names the real `[manual: you do this one by hand]` marker.
- A skipped baseline explains itself: a real run first runs your tests as they are now (the baseline), then again after every step (the gate).
- `booster detect` ends with a `Keys:` line saying what each yes/no key means (`wayne_cas`, `boost`, `helmet`, `mix_file`, `wayneui_1`, `composer_json`).
- The Laravel hop note and its playbook no longer mention a "shared database server" or "database floor": they say to check an old database server's version against the minimum Laravel needs. Only the text of `notes` changed in JSON output.
- Docs: the Docker-only route starts with the clone step and pins `composer:2.10.3`; the quick start says a real `run` needs git and Docker (the `php:8.4-cli` image has no git); the install page agrees with the README that `--path` accepts `~`; a note explains that Windows PowerShell prints Booster's progress lines in red although they are not errors; the scan example uses `--php=<target>` instead of a fixed `--php=8.0`.
- Docs: the front-page samples show waynestate/creed 0.1.6.
- Tests: phpunit skips tests/docs-fixture, which only feeds the docs examples.

### Added

- The documentation site: `site/docs/` is built from `docs/` by `bin/docs-site.php` (`composer docs`), the built pages are committed, and `composer docs:check` (part of `composer ci`) fails when they drift from the sources.
- Every shell block in `docs/` is tagged for the examples runner: `booster detect` and the quick start's `booster plan` run against `tests/docs-fixture/` with their output committed beside them, and the rest carry a `docs-test: skip` reason (needs network, needs Docker, installs software, run on your own app).

## [1.8.0] - 2026-11-03

### Changed
- Changed: docs and package metadata name nougat as the canonical repository, with git.wayne.edu and GitHub as read-only mirrors, until git.wayne.edu has CI runners.
- The advisory scans bundle their tools: phpstan/phpstan, squizlabs/php_codesniffer, phpcompatibility/php-compatibility and waynestate/pinstripe moved from require-dev to require, so plan and scan run them from the PHAR or a clone without installing anything into the app. When Booster runs its own vendor/bin/phpcs it points PHP_CodeSniffer at the bundled standard with --runtime-set installed_paths; the skip messages now say to reinstall Booster's dependencies instead of editing the app's composer.json.
- The plan and scan text reports speak plainly: an automatic step no longer carries the internal `[target-image-tests]` label and instead says "After this step your tests run in Docker." after its summary; a step you do by hand is marked `[manual: you do this one by hand]`; the advisory scan line reads "PHPCompatibility check for PHP X: N errors, M warnings." with singular counts ("1 error", "1 warning") instead of "Will break on PHP X"; and packages the dependency check cannot reach are reported as "not on Packagist or unreachable" instead of "offline". JSON output is unchanged.
- User-facing text uses US spelling (behavior, color, license and so on); command and JSON key names are unchanged.

### Added

- `booster run` prints one line per hop to standard error while it works: the hop's position in the plan, its id, its status, the number of files it changed and the seconds it took, and a closing line totals the hops run, the stops and the whole run. The lines go to standard error so the report on standard output is unchanged and `--format json` stays valid JSON.
- `--path` accepts a leading `~` for your home folder: `~` and `~/...` (or `~\...` on Windows) are rewritten from `HOME`, or `USERPROFILE` on Windows, because the shell does not expand a tilde after `=` or inside quotes. `~user/...` stays as typed.
- A test keeps the reported version, the newest CHANGELOG heading, the README and the front page in step, and the tag build refuses a phar that reports a different version than its tag.

### Changed

- The README and the front page install docs put the single-file PHAR first, downloaded straight from the GitHub release (`releases/latest/download/booster.phar`), and make the clone route second. The Linux, macOS and WSL route opens with `sudo apt install php-cli php-xml php-mbstring php-zip php-curl unzip git composer`, because a stock Ubuntu `php-cli` lacks the XML extension Composer's dev dependencies need. The Windows tab no longer claims "no bash or WSL needed"; it says PHP and Composer must be present instead. The Docker-only route states plainly that it can run `detect`, `plan`, `scan` and `run --dry-run`, and that a real `run` needs the single-file or clone route.
- Changed: CI pulls its Docker Hub images through the GitLab Dependency Proxy (the group's CI_DEPENDENCY_PROXY_GROUP_IMAGE_PREFIX), so pipelines no longer hit Docker Hub's anonymous pull limit.
- When the app requires `cit-web-infra/wayne-cas`, has no `vendor/`, is already on PHP 8.3 or newer, and the repository composer.json names for it cannot be reached, `cas-to-sesame` is now planned as the first step instead of waiting for the Laravel 10 to 11 hop, because the Laravel hops need composer to install and composer is blocked by that repository. The plan explains this in a warning. With the repository reachable, or with `vendor/` already installed, the plan is unchanged.
- An app with no tests of its own is now gated by a built-in syntax check instead of dying on a missing `vendor/bin/phpunit`: when composer.json has neither a `test` script nor a PHPUnit config file or dependency, the baseline and every gate run `php -l` over every PHP file outside `vendor/` and `node_modules/`. The report says what ran: the text report names the syntax check and once explains that it proves the code loads on the new PHP, not that it works, and the JSON baseline gains a `gate` field (`tests`, `syntax` or `custom`). A red baseline now says a PHP file does not parse before any change. Apps with tests are unchanged; `--test-command` still wins and is named as yours.

- The text report speaks plainly: PHPStan findings are labeled "code quality hints, not upgrade blockers" and the plan shows only the first five (`booster scan --phpstan` lists them all); a dry run ends `RESULT: PASS (previewed, no tests ran)`; a step that touched one file says "(1 file)"; and when every PROCTOR finding was already there before the upgrade the line reads "no new findings ... (advisory)" instead of FAIL. JSON output is unchanged.
- A dry-run step that has no edits to make now says "No edits needed for this step (dry run)." instead of "Dry run: nothing was changed.", which read like a failure.

- Every text report now ends with a short plain-English note: what happened, whether anything was changed, and the exact next command with your `--path` filled in (`detect`, `plan`, `scan`, `hops`, `init` and `run`, dry or real; a failed real run names the step it stopped at). `--format json` output is unchanged.

### Fixed

- The single-file PHAR now carries the launchers for its bundled PHPStan and PHP_CodeSniffer, so `scan` and the plan no longer print "could not run" when started from the PHAR.
- The package-block warning now says "needs PHP X or newer" when the package's PHP floor is above the hop's target, instead of naming a cap that is not the real reason the step fails.
- Booster's own scan tools (Pinstripe, PHPCompatibility, PHP_CodeSniffer, PHPStan and its rules, and the CodeSniffer installer plugin) are no longer checked as upgrade blockers when an app has them installed.
- The PHPCompatibility scan drops the `Internal.NoCodeFound` message, which fires on plain HTML include files that carry no PHP code.
- The `tailwind-3-to-4` hop ran `npx -y ^4.1`, which names no package; it now runs `npx -y @tailwindcss/upgrade@^4.1`.
- Rector now skips every vendor/ and node_modules/ directory at any depth, not only the app root, and the report names the nested ones it left alone. A vendored library with a parse error no longer stops a hop.
- A PHP file that is not valid UTF-8 no longer crashes Rector's report: Booster runs Rector without it and lists it as manual work. If Rector still dies on malformed UTF-8 the error now says so instead of pointing at memory.
- `composer ci` no longer dies at Composer's 300 second process timeout: `config.process-timeout` is 900.
- A Rector "Class X should be known at this point" error on a legacy file no longer stops the hop: Booster reruns it once without those files, applies the rest, and lists the files as manual work.
- `bin/booster` is committed as executable, so `bin/booster --version` works on a plain clone (Linux, macOS, WSL); a test keeps it that way.
- A PHP hop adds `require.php` when `composer.json` has none, so PROCTOR no longer flags the upgraded app for a missing PHP constraint. (The lock file's hash is refreshed by the next `composer update`.)

## [1.7.0] - 2026-11-01

WayneUI joins the stack: a hop for the 1.x to 2.x move, and ten more behavior-change warnings.

### Added

- The `wayneui-1-to-2` hop, opt-in with `--wayneui`, for apps that require `cit-web-infra/wayne-ui`. It runs after the last Laravel hop and only when the plan reaches Laravel 11 or later (WayneUI 2.x supports 11 and newer); a plan that ends lower warns and leaves the hop out, and an app that uses WayneUI 1.x without the flag gets a nudge.
- The hop rewrites only the one-to-one items of WayneUI's `MIGRATING.md`, about 57 percent of the mapping: Bootstrap 3 class tokens in class attributes (whole tokens only), Font Awesome icons without size modifiers, five brand colors in CSS and Blade, the Breadcrumbs renames and three `config/wayne-ui.php` keys. It swaps the Composer requirement to `waynestate/wayne-ui` `^2.0`.
- Everything else is listed in the report as `file:line` manual items: layout and component markup that has no one-to-one class, `Breadcrumbs::push(` (its argument order changed, a silent break), `navActive` prefix semantics, jQuery and Bootstrap script hooks, and the old publish tags.
- The hop never deletes forked `resources/views/vendor/wayne-ui` or `public/vendor/wayne-ui`, never edits a `repositories` credential, and says that rotating a deploy token is a person's job.
- Three more behaviour-change rows: Sanctum 4 stops loading its migrations, and yajra/laravel-oci8 adds the case-insensitive NLS defaults at 8.1.0 and drops them at 9.0.0.
- Seven doctrine/dbal rows: getDatabase() on Oracle, BIGINT as int, update and delete with empty criteria, nested savepoints, MySQL foreign keys by engine, BinaryType as string, and Table::getColumns() as a list.

## [1.6.0] - 2026-11-01

The cas-to-sesame hop now finishes what a person had to do by hand on a real app.

### Changed

- `cas-to-sesame` drops the `wayne-cas` path and vcs repositories from `composer.json`, so the moved package no longer lingers as a repository entry.
- `cas-to-sesame` writes `config/sesame.php` from the SESAME defaults when the app has no `wayne-cas` config to carry over.
- `cas-to-sesame` removes `config/wayne-cas.php` when nothing in the app reads its keys; when something still does, the report lists the readers as manual work.
- `cas-to-sesame` flags dead `DUO_*` settings left in the app's configuration.
- The README documents the full cas-to-sesame recipe.

## [1.5.0] - 2026-10-31

Behavior-change warnings, from the second real-use report: a hop can pass every gate and still change what users see.

### Added

- Behavior changes to check: `booster plan`, the run report and `--format=json` (`behaviour_changes`) now name the
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
  `Illuminate\Http\Middleware\TrustProxies`; a customized one gets a manual note. The plan suggests HELMET when that hop
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
  passed, in which case later gate failures are labeled as pre-existing.
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
