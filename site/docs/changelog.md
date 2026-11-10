---
title: "Changelog | Booster docs"
description: "All notable changes to this project."
url: https://booster.apps.wayne.edu/docs/changelog.html
llms: https://booster.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# Changelog [#](https://booster.apps.wayne.edu/docs/changelog.html#changelog)

All notable changes to Booster are recorded here. The format follows Keep a Changelog.

## \[Unreleased\] [#](https://booster.apps.wayne.edu/docs/changelog.html#unreleased)

## \[1.18.0\] - 2026-11-10 [#](https://booster.apps.wayne.edu/docs/changelog.html#1-18-0-2026-11-10)

- Changed: `scripts/check-scans-bundled.sh` carries the `# proctor-ignore script-pair:` marker: it runs only inside the composer:2 container, so PROCTOR's `script-pair` reports it as an info note, not a missing twin.
- Changed: requires PROCTOR ^0.15 (locked 0.15.0, was ^0.12), and so does the deploy layer: 0.13 checks the release cooldown against the CREED policy and 0.14 adds `script-pair`; both only warn, nothing breaks.
- Changed: requires PROCTOR ^0.12 (locked 0.12.0, was ^0.11), and so does the deploy layer.
- Changed: the deploy kit is Squire 0.10.7 (was 0.10.6): it ships a `.gitattributes` that pins line endings, so the deploy layer clears PROCTOR's line-ending warnings.
- Changed: the GitHub workflow calls Preflight 0.5.0 by its commit (was 0.1.4's), now that GitHub's copy of Preflight has the tag; MAINTAINING.md quotes the CREED constraint as it is (^0.1.8).
- Changed: the docs, agent-ready and deploy kits are Squire 0.10.6 (was 0.10.5): the docs builder and agent-ready generator see every file on Docker Desktop mounts.
- Site: `assets/stack-env.js` knows Parity, so a pre-production page links Parity's pre-production site and a dev page links its local `site/` folder. Until now Parity, of all parts, was the one link that ignored its environment.
- Changed: housekeeping to keep pace with the stack. The `waynestate/pinstripe` requirement moves from `^0.1.4` to `^0.3` (the lock resolves to 0.3.1), the bundled SESAME config that `cas-to-sesame` falls back on when an app has no vendor copy is now SESAME 0.7.3's (it was 0.5.0's; the hop still writes `^0.7`, which reaches it), and the Where it fits tables in the README and the docs overview now show the full WELD cast.

## \[1.17.0\] - 2026-11-09 [#](https://booster.apps.wayne.edu/docs/changelog.html#1-17-0-2026-11-09)

Follow-ups to 1.16.0: download caches for the gate, a rerun that re-gates the hop that stopped, and a Node pin that proves the front end still builds.

- Added: an opt-in Composer download cache for the gate. Every gate container started with an empty cache, so a real Laravel app's run downloaded every package once for the baseline and again for each of its four hops. Set `BOOSTER_COMPOSER_CACHE` to a Docker volume name (the WSU stack's is `stack-composer-cache`) or a host folder, and Booster mounts it at `/tmp/composer-cache` as `COMPOSER_CACHE_DIR` in the baseline and every gate. On Linux and macOS, where the gate runs as your own user, a volume gets a folder of its own (`booster-uid-<uid>`, made once per run by a short root container) so Composer can write to it; the rest of the volume is left alone. A folder that cannot be created or written stops the run with the variable named. Unset, nothing changes. Guide: "Configuration", `.env.example`.
- Fixed: a rerun after a stopped hop runs that hop's gate again instead of skipping it. A hop that stops at a red gate keeps its edits; the person fixes the cause, commits and runs again, and Booster plans from the app as it is, so the stopped hop had either left the plan (its target was reached) or came back with nothing to edit, which read as "nothing to do, so the gate was skipped". For a PHP hop the baseline on the new PHP covered the tests, but a front-end hop's build never ran again: a Vite hop whose build went red and was fixed by hand went on to the next hop unbuilt. Booster now reads the stopped hop from the last run's `booster-hop.json`. When the hop left the plan, its build gate (for a hop that builds the front end) runs right after the baseline, shown as `Re-gate: PASS` or `FAIL`; a red one stops the run before step 1. When the hop is back with nothing to edit, its step runs its build and test gates against the committed lock instead of skipping them. A dry run and `--skip-gate` never re-gate. The JSON report and `booster-hop.json` gain `regate` on such a rerun, so a red re-gate is re-gated again on the run after it. Recipe 14, "After a stop", says how.
- Added: the `node-pin-24` hop proves the front end still builds on the Node it pins. It used to change `engines.node` and `.nvmrc` and run only the PHP tests, so a front end that no longer built on the new Node passed the hop unnoticed, and the next front-end hop or the next deploy found out instead. When `package.json` has a `build` script and a `package-lock.json`, the hop now runs `npm ci` and `npm run build` in the `node:<pinned>` container before the tests; a red build stops the run at that hop with the pin in place, and the rerun builds it again (the re-gate above). With no build script, or a build script and no lock (`npm ci` needs one), the hop says so plainly and runs the tests as before. A dry run says which it will be, and `--skip-gate` names the build to run by hand. A `--replay` run that pins Node builds the front end before it records, like the other front-end hops.
- Added: an opt-in npm download cache for the Node containers, the same way as Composer's. Set `BOOSTER_NPM_CACHE` to a Docker volume name (the WSU stack's is `stack-npm-cache`) or a host folder, and Booster mounts it at `/root/.npm` in every Node container: the build gate, the pin hop's build and the front-end hops' npm steps. Those containers run as root, so a volume suits them better than a folder. Unset, nothing changes. Guide: "Configuration", `.env.example`.

## \[1.16.0\] - 2026-11-09 [#](https://booster.apps.wayne.edu/docs/changelog.html#1-16-0-2026-11-09)

Lessons from a real Laravel app that Booster took from PHP 8.3 to 8.5: the code edits were right, and the gaps were in what Booster wrote or left alone around the code.

- Fixed: the Citadel deploy script never migrates, and Booster writes it only when it is needed. A run that changed `composer.lock` used to add `deployments/0000_composer_install_<stamp>`, which ran `composer install --no-dev` and then `php artisan migrate --force`. Citadel runs deploy scripts in name order, so it migrated ahead of the app's own deploy scripts: on a Laravel app whose first release parks old tables before it migrates, the migration would have landed in the old tables. Now Booster compares the production packages (`packages` in the lock, not `packages-dev`) before and after the run: when only dev packages moved, it writes nothing and says why; when the app has `scripts/release.sh` (Squire's kit installs on every release), it writes nothing and says so; when the app has no `deployments/` folder, it writes nothing and says what every server must run on the next release; otherwise it writes an install-only script and leaves migrations to the app's own deploy scripts. The JSON report gains `deploy_notes`. Guide: "Deploying on Citadel".
- Added: a loud warning when `vendor/` is missing. Without it, Rector and PHPStan cannot see the classes the app's packages provide, so `plan` and `run --dry-run` showed less than half the edits on a real Laravel app (10 files instead of 21) and PHPStan's count was mostly noise. `plan` and the dry run now open with a warning, `detect` prints a line and sets `vendor_missing` in its JSON, and the PHPStan scan's hint says to install instead of pointing at Larastan. A real run is not affected: its baseline installs `vendor/` before the first hop. An app that requires no packages gets no warning.
- Added: when a run raises the app's PHP floor, the report lists every place that still names an older PHP, with its file and line: CI matrix entries (Preflight's `php_versions`, a GitHub workflow's `php:` matrix, a `PHP_VERSIONS` list) and PHP images (`php:8.3-cli` in CI, `citadel/php-apache:8.4r5` in the Squire kit). Booster never edits CI or deploy files, so it lists them; a matrix entry below the floor fails on the first push. A dry run lists them too, from the floor its last PHP step would set. The JSON report gains `floor_follow_ups`.
- Added: `--node=<major>` on `plan` and `run` picks the Node the `node-pin-24` hop floors the app at (22, 24 or 26 today; the policy must still support it), and implies `--with-node`. Without it, Booster takes the Node the app's Citadel image ships, read from `NODE_IMAGE` in the Squire kit's `.squire/base-fallback.Dockerfile`, and otherwise the newest Node the policy supports. The plan's step line reads `node-pin-24 (pins Node 22)` and its summary says where the major came from. A real Laravel app was pinned to Node 26 while Citadel's PHP image ships Node 22, so every `npm ci` on the server warned `EBADENGINE`.
- Fixed: the PDO_PGSQL behavior change (DSN credentials take priority, PHP 8.4) fired on every Laravel app, pointing at the MySQL SSL lines of a stock `config/database.php`. Behavior data gains a `new` detect kind that matches only a connection being made (`new PDO(...)`, `new \PDO(...)`, `PDO::connect(...)`), and the row uses it.
- Fixed: dates and the deploy script's stamp follow the machine's time zone when php.ini names none, or UTC (the line many setups add only to silence PHP's warning). Such a PHP runs on UTC, so an evening run in Detroit planned for "today 2026-11-10". Booster now reads `TZ`, `/etc/localtime`, `/etc/timezone` and then ICU; any other zone in php.ini wins, and with no answer PHP keeps the zone it has.
- Changed: a clean `composer audit` after a hop says `Composer audit: clean (no advisories, no abandoned packages).`, so an audited hop no longer reads like one that was never audited next to a hop that printed "skipped".
- Changed: the progress on standard error prints a line as the baseline starts and one as it ends, and the closing total counts the baseline. The first run on a machine builds the gate image, which took minutes with no output before the first hop line.
- Changed: a dry run whose Next line leaves out `--test-env` says so and names the keys (never the values), so a copied line does not quietly drop the env.
- Changed: the PHP 8.5 hop's note says Rector moves PDO driver constants (`PDO::MYSQL_ATTR_SSL_CA` to `Pdo\Mysql::ATTR_SSL_CA`) inside `PHP_VERSION_ID` guards too and leaves the guard in place; the PHPUnit hops ask for `phpunit --migrate-configuration` only when PHPUnit warns about a deprecated schema. Five `booster init` playbooks carry the new notes.
- Docs: README status, the front page masthead and its Version label say 1.16; the Real output page is captured again with 1.16.0.

## \[1.15.0\] - 2026-11-09 [#](https://booster.apps.wayne.edu/docs/changelog.html#1-15-0-2026-11-09)

Lessons from a real 2012 raw PHP app that Booster took from PHP 5.6 to 8.5, with its hand-copied Doctrine, Smarty and Slim moved onto Composer.

- Added: a Wayne Auth stop sign. An app that still signs people in through the old Wayne Auth service (the `WayneAuth` class copied into the app, the `validUser` cookie, the `sso_host` setting) loses its sign-in on the first hop off PHP 5.6, because PHP 7 and later verify the service's TLS certificate. `detect` gains a `wayne_auth` line and JSON key, and both `detect` and `plan` open with a stop sign that names up to three of the lines and points at SESAME's Wayne Auth bridge guide. An app already on the bridge gets none.
- Added: `detect` lists hand-copied libraries (Doctrine 2, Smarty 3 and Slim 1 committed into a vendor folder with no Composer behind them) with their versions and folders, says Booster can move them, and its Next line adds `--vendored`. The JSON report gains `hand_copied_libraries`.
- Added: dynamic properties. The plan step that crosses PHP 8.2 lists every `$this->x` the app's own classes assign without declaring (`file:line Class::$prop`), or says how many classes it read and found none, and `run` repeats them on that step as MANUAL lines. A class whose parent or trait is outside the app is named on a "not checked" line. Booster never changes them. `plan --format=json` gains `dynamic_properties`. Guide: "Dynamic properties" in the scans guide.
- Added: a replay baseline whose answers are all the same redirect (every request sent to sign-in) says the replay proves only that the app reaches its sign-in check, and points at the replay guide's new "Pages behind sign-in" section.
- Fixed: Composer's audit exits 2 for abandoned packages and 3 for those plus advisories. Booster read any exit above 1 as "could not run", so an app whose libraries had just moved to Composer got that line on every hop with the report right there. A JSON report now wins for exits up to 3, and abandoned packages print as findings.
- Fixed: the plan footer and a dry run's Next line keep the flags the run needs (`--from-php`, `--vendored`, `--today` and the rest of the plan's; the dry run adds the gate's, such as `--replay` and `--test-command`). They dropped every flag, so a 2012 app was told to type a run that planned something else or nothing. `--test-env` stays out, because its values may be credentials. `detect` on an app with no PHP version to read prints a `plan` line with `--from-php=5.6` and says to change the value (it printed a line that exited 2).
- Fixed: a dry run of `vendored-to-composer` says "Dry run: would write composer.json:" (it said "would wrote"), and the lines under it no longer repeat "would".
- Fixed: the dependency check judges the releases the app's own constraint allows, not only the newest release on Packagist. An app whose constraint allows only an ORM's 2.x line was told that the newest 3.x release blocks the PHP 8.0 hop, a release `composer update` would never pick. A step is blocked only when no allowed release fits it, and a constraint the check cannot read (`*`, a dev branch) still judges the newest stable release.
- Fixed: compiled Smarty templates in a hand copy's cache folder are a cache, not code: `vendored-to-composer` skips them and adds one MANUAL line per folder saying to delete them and keep the folder out of git, and the SMARTY_DIR stop sign says when the folder it found is a cache.
- Fixed: Rector leaves `slim1-bridge.php` alone while it is the file Booster wrote, so every PHP hop after `vendored-to-composer` no longer rewrites (and churns) Booster's own bridge.
- Changed: every PHP hop on an app with `composer.lock` refreshes the lock's content hash in its gate (`composer update --lock`), because each hop raises `require.php` and Composer warns on a stale hash. It used to happen only when `config.platform.php` was pinned. A hop whose locked packages refuse the new PHP still runs `composer update -W` for those packages instead.
- Changed: with `--vendored`, the steps before the move to Composer run the syntax check instead of your test command, as the replay already waited, because the hand-copied libraries may not load on that PHP yet. The report says "Tests deferred to step N (vendored-to-composer)". Your tests run on the baseline and from the move on.
- Docs: "A smoke test for WSU apps" gains a raw PHP app section: one plain script, run with `--test-command`, that proves the bootstrap and the libraries load. The framework hops guide says why the replay and the tests wait for the vendored move, recipes name the Wayne Auth stop sign and SESAME's bridge, and the README, quick start and front page printouts match `detect` and `plan` as they print today.

## \[1.14.0\] - 2026-11-09 [#](https://booster.apps.wayne.edu/docs/changelog.html#1-14-0-2026-11-09)

- Docs: a new Upgrading page (`docs/upgrading.md`, in the docs menu and linked from the overview) says what changes when you move Booster to a new release, newest first: nothing to do for 1.14.0, and for 1.13.1 the fresh gate image, the stale `cas-to-sesame` playbook, and the one `composer require waynestate/sesame:^0.7` line an app that an earlier Booster moved to SESAME needs, with what SESAME 0.7.0 changes (nothing, for a Laravel app).
- Docs: README status, the front page masthead and its Version label say 1.14; the Real output page and the docs pages stamp 1.14.0. No hop changed since 1.13.1, so the captured plans are the same.
- Changed: Booster locks Pinstripe 0.1.5 (was 0.1.4), whose package metadata names git.wayne.edu, so `composer.lock` no longer carries an internal hostname in Pinstripe's support links. No other package moved.
- Changed: CI pins the Preflight components to 0.5.0 (was 0.4.0). The composer-audit include drops its `image` input and runs 0.5.0's default, Composer 2.11.1 by its multi-arch list digest (it ran a floating `composer:2` digest), and the proctor job now keeps PROCTOR's JSON report as `proctor.json` in its artifact for ROLLCALL. The vendored a11y checker is byte for byte the same at 0.5.0, so its pin test, the a11y scripts, the README and MAINTAINING name the new tag. The GitHub workflow stays on 0.1.4's commit, since the GitHub mirror has no 0.5.0.

## \[1.13.1\] - 2026-11-09 [#](https://booster.apps.wayne.edu/docs/changelog.html#1-13-1-2026-11-09)

- Fixed: a hand-copied library's version is also read from the `@version x.y.z` tag in its main file's docblock when the code does not state it (a `const VERSION` or `SMARTY_VERSION` still wins). A real app's Slim 1.6.0 copy says its version only there, so `vendored-to-composer` raised a wrong stop sign ("holds Slim of an unknown version") and left the hop out. A version-control keyword such as Doctrine's `@version $Revision$` still leaves the version unknown. The test fixture's Slim now ships as the real copy does, docblock only.
- Fixed: a PHP hop no longer stops on a lock that cannot install on its PHP. Found on a real 2012 app: `vendored-to-composer` resolved the first `composer.lock` on PHP 7.4, where Slim 4's `psr/container` 2.0 holds `symfony/service-contracts` at v1.1.2 (php ^7.1.3), so the 7.4 to 8.0 hop's plain `composer install` refused the lock every time. A PHP hop now reads the lock first and, when locked packages' own `require.php` leaves out its PHP, the gate runs `composer update -W` for just those packages, inside `composer.json`'s constraints; the report says which (`The gate refreshed composer.lock (composer update -W symfony/service-contracts): the lock held symfony/service-contracts v1.1.2, which needs php ^7.1.3, not PHP 8.0.`), a dry run says `would run:` it, and a hop with no other change still runs its gate for it. On that app's `composer.json` the 8.0 hop moves two packages and nothing refuses 8.1 to 8.5 after it. Booster still never creates `config.platform.php`: Composer picks the newest release each PHP allows, so no pin finds one lock for 7.4 through 8.5 on its own. The plan's lock-ceiling warning says the hop's gate does the update (it said the hop "will need composer update").
- Fixed: a rerun after a stopped PHP hop says what to run. The stopped hop's `composer.json` edit stays, so `--from-php` with the old PHP could never install, and the report only said to fix `composer.json` and `composer.lock` or pass `--allow-failing-baseline`. Now, when the baseline's install fails because `composer.json` already requires a newer `php` than the run starts from, it says so and to run again without `--from-php` (the baseline on the PHP `composer.json` names then checks the stopped hop's work); when a locked package refuses the baseline's PHP, it names the `composer update -W` to run. The JSON baseline gains `advice` for those two cases only. Recipe 14, "After a stop", says how to rerun.
- Fixed: the gate's target images for PHP 5.6 and 7.0 (Debian stretch) build again. The keys stretch ships with have expired, so apt refused every package and a PHP 5.6 app's first hop stopped at "Could not build booster-target:5.6". The archive also signs stretch with newer keys, so the recipe now copies Debian's current archive keys (buster to trixie) into the images for archived releases (jessie, stretch, buster, bullseye), from `debian:bullseye-slim` and `debian:trixie-slim` pinned by their multi-arch list digests, and apt verifies every signature as before. Buster and bullseye images (PHP 7.1 to 8.0) get the same keys, so they keep building after the keys they ship with expire. Jessie (PHP 5.5) stays the one release whose packages install unsigned, because its archive is signed only by expired keys; that setting is now removed once the last package is in, so it no longer stays in the finished image. Images for current releases keep their own keys and sources untouched; the staged key files are deleted in every image. The recipe's stamp changes, so the next `run` builds fresh images.
- Changed: `scripts/check-target-image.sh` and `.ps1` build with the Composer Booster passes (2.2 below PHP 7.2), check that Composer runs in the image, and on stretch, buster and bullseye run `apt-get update` in the image and fail when apt cannot verify the archive. A new unit test (`TargetImageRecipeTest`) pins the recipe's rules in `composer ci`: every archived release handled, current keys for archived releases only, pinned key images, unsigned packages on jessie alone and only while the image builds, and the file the jessie block writes is the one the last step removes. `check-target-image.ps1` requires PowerShell 7.3 or later, because older versions strip the double quotes in the apt probe on the way to `docker`.
- Changed: Composer 2.11.1 (was 2.10.3, no longer maintained), pinned by its multi-arch list digest, in the `wayneui-copies`, `composer-ci`, `refresh-hop-data` and `phar` CI jobs, the PHAR build scripts, the Docker lines in the README, the install, checks and MAINTAINING pages and the front page, and the gate's target image for PHP 7.2 and up (builds for older PHP keep Composer 2.2). 2.11 fixes GHSA-c968-33g3-wh7p and GHSA-fm2w-xgf2-f9fh. The target image's recipe stamp changes with it, so the next `run` builds a fresh image.
- Changed: the deploy kit is Squire 0.10.2 (was 0.10.1): the kit's `php.ini` sets `session.cookie_samesite = Lax`, the fallback base names its images in full (`docker.io/library/...`) with Composer as a build argument, and the portable image drops `node_modules/` after the build. `squire init --check` passes on `deploy/`.
- Changed: the `cas-to-sesame` hop writes `waynestate/sesame:^0.7` (was `^0.6`) and its MANUAL note names the same constraint. SESAME 0.7.0's one new behavior is a cookie flag warning in its raw PHP session, which a Laravel app (every wayne-cas app) does not use; an app hopped by an earlier Booster takes SESAME's 0.7.0 upgrade step.
- Changed: the `proctor` CI job on `deploy/` blocks the pipeline (it was allowed to fail); PROCTOR 0.11.1 reports a pass there, with warnings and no failures. MAINTAINING says so.
- Docs: MAINTAINING names the maintainer as a role (the stack maintainer), reached through an issue on git.wayne.edu, and the CI cache comment says why the key is fixed without an internal decision number.
- Docs: the sample plans in the README, the quick start and the front page, the README's record card and the front page's Policy line name CREED 0.1.8, the policy Booster locks (they said 0.1.7).
- Docs: `.env.example` says an unset `BOOSTER_POLICY` uses the policy.json in the waynestate/creed package (it said a snapshot bundled with Booster, which Booster no longer ships).
- Changed: Booster requires CREED ^0.1.8 (locked 0.1.8, was ^0.1 at 0.1.7), PROCTOR ^0.11 (locked 0.11.1, was 0.11.0) and Pinstripe ^0.1.4 (locked 0.1.4, was ^0.1 at 0.1.1). The deploy layer requires HELMET ^1.3 (locked 1.3.0, was ^1.0 at 1.0.1), CREED ^0.1.8 (locked 0.1.8, was 0.1.7) and PROCTOR ^0.11 (locked 0.11.1, was 0.11.0).

## \[1.13.0\] - 2026-11-09 [#](https://booster.apps.wayne.edu/docs/changelog.html#1-13-0-2026-11-09)

- Changed: the deploy kit is Squire 0.10.1 (was 0.10.0): `.squire/base-fallback.Dockerfile` copies Composer 2.11.1 (was 2.10.3, no longer maintained); every other kit file was already current. PROCTOR moves to ^0.11 (locked 0.11.0, was ^0.8 at 0.8.0) for Booster's own check gate and for the deploy layer.
- Docs: truth pass. The PowerShell PHAR lines in the README and the install page lost their backslashes (`"$HOME\bin\booster.phar"` and `C:\path\to\your-app` printed as control characters) and are whole again. A target past security support is a warned stop on the way up, not refused, in BEST_PRACTICES (now `Checked`), the quick start, the README and the front page. The README, the configuration page and the front page name Booster's own read-only network calls (the Packagist dependency check and the wayne-cas repository probe) instead of saying it makes none. The `composer ci` lists name the Real output and docs site checks; MAINTAINING names the scan tools and Pinstripe among the runtime dependencies, the Preflight a11y component, and the proof re-capture every release needs. The front page says PROCTOR runs before the first hop and after the last, and that `--proctor-command` adds a check rather than replacing it; its Next list and the docs Next page match OPEN_QUESTIONS (the pilot re-run, the signed-in SESAME run, the MySQL 5.6 question). The docs overview lists the replay gate, hand-copied libraries and strict session guides. The sample plan in the README and the quick start shows the two new Laravel 13 behavior rows, as a fresh `plan` prints them.
- Changed: the deploy kit is Squire 0.10.0 (was 0.7.1): release.sh keeps Composer and npm download caches in the app folder and the gate takes SQUIRE_PHP_RUNTIME; the compose file, health.php and .env.squire.example follow the template, and the portable image files are added.
- Docs: the front page and docs pages follow the reader's dark mode: the front page (and the generated proof page) gains the record room after hours the docs already had, a graphite desk with faint mint graph paper, cream type and gold still the flame, while the never list, the install slab and the footer stay deep green. Every pair meets WCAG AA, the browser bar color follows the scheme, and the only new light is a gold burn under the rocket that breathes while it lifts (a still glow when motion is reduced). The docs theme now says it shares that dark mode with the front page.
- Docs: "Run it today": the README opens with the four lines from nothing to a dry run (the PHAR, one folder above the app so `run` still sees a clean working copy), the PHAR route gains its Windows PowerShell lines in the README, the install page and the front page, and the front page's Install button and heading say "Run it today". The front page printouts (detect, plan, run, hops, init) are re-taken from main, the `--path` note says a leading `~` works (it does), the Docker tab pins `composer:2.10.3` like the README, and the quick start, recipes and README say a Laravel 10 app on PHP 8.1 with PHPUnit 10 plans 10 hops, as `plan` prints today. On a phone the masthead's "Run it today" button stays on one line.
- Added: the `session-strict-mode` hop, planned with the new `--strict-session` option, turns on `session.use_strict_mode` and `session.use_only_cookies` in a raw PHP app that calls plain `session_start()`. PHP ships with strict mode off, so it accepts session IDs the server never issued. Before the statement that holds each call, the hop adds one guarded `ini_set()` line that runs only while no session is active and no output has gone out, so it never adds a warning. It is a line in the code because Citadel's PHP runs as an Apache module and never reads `.user.ini`, and `.htaccess` PHP flags break under PHP-FPM. The planner puts it first, on the PHP the app runs today, with its own gate. It skips Laravel apps, apps that boot HELMET, and files or folders that already set strict mode, so a second run changes nothing. It refuses and changes nothing on a stop sign: `session_id($id)`, the `SID` constant, `use_trans_sid` on, or cookies off. Custom session handlers without `validateId()` are listed as MANUAL lines. Without the flag, the plan says how many calls it found. `booster init --check` reports the playbooks as stale until `booster init` runs again. Guide: `docs/guides/strict-sessions.md`.
- Added: a run whose gate proves little (the built-in `php -l` check for an app with no tests, or only Laravel's stock example tests) and that does not pass `--replay` says so under the baseline, with the `booster replay init` command that drafts the request file (or just `--replay`, when `booster-replay.json` is already there) and the step most likely to change an answer, the PHP 8.0 hop; the JSON report gains `replay_suggestion`. Advisory: it never changes the exit code. The PHP 8.0 hop's notes and playbook now name the replay gate next to extra tests, so `booster init --check` reports that playbook as stale until `booster init` runs again.
- Docs: BEST_PRACTICES catches up with the code: characterization tests are `Checked` by the replay gate (`booster run --replay`), and "Pin Rector" names the `rector validate-config` check (in `run` and in CI) and the hop record's rule-package versions. The Next page says what the pilot runs and the `cas-to-sesame` hop already shipped, as OPEN_QUESTIONS does.
- Added: the Laravel 12 to 13 hop's behavior-changes block gains three rows from the upgrade guide: a lone `array_last()` call (the polyfill row only looked for `array_first()`), and an app that leaves its session cookie name or cache prefix to the framework fallback, whose names change in 13 (everyone is signed out once, the cache starts cold). Set `SESSION_COOKIE` and `CACHE_PREFIX` to today's values to keep them.
- Added: every hop playbook from `booster init` says what an AI coding tool does when a gate fails: work from the real output and the files it names, fix one cause per try, never loosen a test or add `--skip-gate`, and stop after 3 tries at one failure to hand the hop back to a person. Run `booster init` again to pick it up (`booster init --check` reports the old playbooks as stale).
- Changed: CI pins the Preflight components to 0.4.0 (the proctor job clones the full history, so the override-approver check can read git blame).
- Docs: OPEN_QUESTIONS and the GitHub workflow comment name the pins in use (Preflight 0.3.1 on GitLab, 0.1.4's commit on GitHub).
- Changed: the deploy layer runs Squire's kit 0.7.1 (was 0.6.0): `.squire/php.ini` no longer sets the deprecated `session.sid_*` lines, web requests get a `disable_functions` list and Apache hardening (`.squire/php-web.ini`, `.squire/apache-hardening.conf`), and opcache gets production settings with `SQUIRE_OPCACHE_VALIDATE` as the one switch.

## \[1.12.0\] - 2026-11-08 [#](https://booster.apps.wayne.edu/docs/changelog.html#1-12-0-2026-11-08)

- Changed: the cas-to-sesame hop writes waynestate/sesame ^0.6 (was ^0.5), the current SESAME line; 0.6.0 has no breaking change for an app that keeps its driver.
- Changed: the front page deploy layer requires HELMET ^1.0 (lock at 1.0.1, was 0.4.0), like the rest of the stack; its shared front controller already runs on HELMET 1.0 in Squire, Sourdough and Pinstripe.
- Fixed: the front page stops hiding sideways overflow behind a page-wide `overflow-x: clip` on `html` and `body`, so the 320 px reflow check (WCAG 1.4.10) can see real overflow; the masthead nav now wraps, right aligned, between roughly 640 and 840 px instead of running past the viewport.
- Changed: the local a11y scripts run Preflight's own checker (vendored from 0.3.1 and pinned by a test), so they check what CI checks: axe-core 4.14.0, 320 and 1280 px, and the reflow check.

## \[1.11.0\] - 2026-11-08 [#](https://booster.apps.wayne.edu/docs/changelog.html#1-11-0-2026-11-08)

- Added: CI runs Preflight's a11y component (0.3.1) on site/, every page in light and dark at 320 and 1280 px with the WCAG 1.4.10 reflow check, blocking.
- Changed: CI pins the Preflight components to 0.3.1 (fixed cache keys, and caches saved even when a job fails; D-072).
- Fixed: the hop record test checked finished_at \<= started_at (arguments swapped), so it failed whenever a run crossed a second boundary.
- Changed: CI saves its Composer and npm caches even when a job fails, so a retry after a dropped download fetches only what is missing (D-072).
- Changed: CI caches Composer and npm downloads under a fixed key, so a lock change downloads only what changed (D-072).
- Fixed: docs and comments caught up with the code: the Rector pilot's measured runs, the Preflight 0.3.0 pins, Node 26 in two comments, and the full GitLab pipeline list.
- Fixed: the docs menu closes with Escape and hands focus back to its button (docs kit synced from Squire).
- Changed: the pipeline pins every Preflight CI component to 0.3.0 ([preflight@0.3.0](mailto:preflight@0.3.0)); component image inputs are plain image names, since Preflight adds the Dependency Proxy prefix itself.

### Added [#](https://booster.apps.wayne.edu/docs/changelog.html#added)

- Added: a real `booster run` writes `booster-hop.json` at the app root (start and end time, from and to versions, Rector and rule-package versions, each hop's status and rules) for ROLLCALL to read; dry runs write nothing (BOOSTER-24).
- Added: real .vue fixtures under tests/fixtures/vue-compat/ and a Docker-gated test that runs vue-upgrade-tool on them (BOOSTER-21).
- Added: Booster checks the Rector config with rector validate-config before a Rector hop changes anything, and the run report records the Rector version.

### Changed [#](https://booster.apps.wayne.edu/docs/changelog.html#changed)

- Changed: the dev dependency on PROCTOR moves to ^0.8 (was ^0.3, locked 0.8.0), and the deploy layer requires PROCTOR ^0.8 (locked 0.8.0).
- Docs: the README's Commands section gains the stack's "Which command when" table, copied word for word from WELD's docs: `booster plan`, `booster replay check`, `booster init --check`, `proctor check` and the other look-alikes, with what each checks, when to run it and where it runs (D-064).
- Docs: `ADR/` and `DECISIONS.md` record Booster's shape decisions (one CLI, no tools image, PROCTOR from the host, the PNG mark).
- Changed: the GitLab pipeline pins the Preflight components to 0.2.0 (was 0.1.4).
- The front page's deploy layer (`deploy/`) runs Squire's kit 0.6.0 (was 0.5.0): `scripts/release.sh` asks PROCTOR's deploy gate for a go or no-go on each release (a report, not a stop, unless `SQUIRE_PROCTOR=enforce`), and `.env.squire.example` lists the new setting. The layer's pins move to HELMET `^0.4` (locked 0.4.0) and PROCTOR `^0.7` (locked 0.7.0).
- Changed: the `cas-to-sesame` step requires SESAME `^0.5` (SESAME 0.5.0 is out), and the SESAME config Booster falls back on when the app has no vendor copy is SESAME 0.5.0's: it gains `cas.require_pending_login` and `gateway.jwks_file`. The step's notes say Laravel apps need no code change for 0.5's sign-in binding and that Laravel 13 resolvers must implement `RefreshesUser`.
- CI caches Composer downloads per lock file (`.composer-cache`, keyed on `composer.lock` and `deploy/composer.lock`), so a pipeline stops fetching every package from Packagist on every run. Images already come through the Dependency Proxy.
- The lock moves to CREED 0.1.7 (was 0.1.4), the policy every plan reads. The Real output page is captured again (`build-proof.php --capture`), so its plans name `waynestate/creed 0.1.7`; the plan samples in the README, the quick start and the front page match a fresh run.
- Docs: source comments, messages and art titles write Booster as a word, not in capitals; wordmarks keep their display type.

### Fixed [#](https://booster.apps.wayne.edu/docs/changelog.html#fixed)

- Fixed: the docs kit's copy button no longer covers the first line of code when it says Copied or Failed: over the code it keeps its resting width and shows a check or a cross when the word does not fit (docs kit synced from Squire).
- Fixed: the header button on the front page and the proof page says Run it today, as it does on the docs pages (it said Install).
- Fixed: the README art build hides the sticky masthead before the gallery cuts, so `card.jpg` shows the whole record card.
- The README art kit rebuilds `record.svg` again: it accepts package hops such as `phpunit-10-to-11` on the record card and still fails on an id Booster does not plan.
- The audit allows one abandoned package, `symplify/rule-doc-generator-contracts`, which `driftingly/rector-laravel` 2.7.3 still requires: composer.json ignores it and `policy-overrides.json` backs the ignore with a reason and a 2027-02-07 expiry, so PROCTOR flags it again if it outlives the approval.
- The pipeline's PROCTOR jobs no longer pass `--policy`, which PROCTOR 0.7 refuses in CI; PROCTOR checks against the CREED policy it bundles.
- The front page's "Next" label reads Version 1.10 (it said 1.8, beside a 1.10 masthead), and its cast says Sourdough starts new apps in all five shapes, not raw PHP only.

## \[1.10.0\] - 2026-11-07 [#](https://booster.apps.wayne.edu/docs/changelog.html#1-10-0-2026-11-07)

### Added [#](https://booster.apps.wayne.edu/docs/changelog.html#added-1)

- The first framework hop, `vendored-to-composer`, planned with the new `--vendored` option: it moves an app's hand-copied Doctrine 2, Smarty 3 and Slim 1 out of `vendor/` and onto the maintained Composer releases that keep the same API (`doctrine/orm ^2.20` with `doctrine/annotations ^1.14` and `symfony/cache`, `smarty/smarty ^4.5`, `slim/slim ^4.15` with `slim/psr7`). It deletes the copies, writes or extends `composer.json`, points each file's `require` of a copy at `vendor/autoload.php` and drops the 2012 bootstrap lines (`Setup::registerAutoloadDirectory()`, `define('SMARTY_DIR')`). A Slim 1 app keeps its routes through `slim1-bridge.php`, written at the app root and loaded by Composer's classmap: Slim 1.6's API (`:param` routes and conditions, first match wins, `render()` through the app's `Slim_View`, `halt()`, `redirect()`, the 404, 405 and 500 pages byte for byte) on Slim 4's router. The planner places the hop at the first PHP every release runs on (7.4 with Slim, 7.1 without); anything the hop cannot rewrite exactly (Doctrine's `ClassLoader`, Slim hooks, cookies, middleware or regex routes, a Smarty 2 copy) is a stop sign that leaves the hop out with the file and line. With `--replay`, steps before the hop defer their replay, since the old copies may not run on the new PHP. Libraries without a recipe and nested copies stay where they are and are listed as MANUAL lines. Guide: `docs/guides/framework-hops.md`.
- The site accessibility gate: `scripts/a11y.sh` (Linux, macOS, Git Bash) and `scripts/a11y.ps1` (Windows PowerShell) run axe-core in Playwright (WCAG 2.2 AA, light and dark, 360 and 1280 px) in Docker over the front page and every page under `site/docs/`; `tools/a11y/check.mjs` takes repeated `--page` values.
- An advisory `composer audit` after composer hops: when a hop changes the app's `composer.json` or `composer.lock`, `run` executes `composer audit --format=json --no-interaction` in the app and lists advisories (package, advisory id or CVE, affected versions, title) and abandoned packages in the text and JSON reports, with counts. Advisory only: it never changes the exit code, a clean audit stays silent, a hop that changed no composer file skips it and says so in one line, and an audit that cannot run is reported with the command to run by hand. Source: `src/Scan/ComposerAuditScan.php`; tests: `tests/ComposerAuditHopTest.php`, `tests/ComposerAuditScanTest.php`. Guide: `docs/guides/scans.md`.

### Changed [#](https://booster.apps.wayne.edu/docs/changelog.html#changed-1)

- The front page's deploy layer (`deploy/`) runs Squire's kit 0.5.0 (was 0.3.4): `oracle-check.php` arrives, `scripts/release.sh`, `scripts/parity.ps1` and `.squire/base-fallback.Dockerfile` take the new templates, and `docker-compose.squire.yml`, which Renovate had edited, is Squire's template again, so `squire init --check` passes. `site/assets/stack-env.js` takes the stack's one copy, with bindingless catch blocks.
- The canonical repository is git.wayne.edu (ADR-0021); docs, links and package metadata no longer point at the archived nougat copy.

### Fixed [#](https://booster.apps.wayne.edu/docs/changelog.html#fixed-1)

- The gate and the baseline skip the Composer phase for an app with no `composer.json`: before, `composer install` failed on the missing file and the run stopped at the baseline ("composer install failed") before the syntax check ever ran, so an app that never met Composer could not be upgraded at all.
- PHP hops on an app that pins `config.platform.php` (every Sourdough app, WELD included) no longer fail their own gate: `composer.lock` kept the old pin in its `platform-overrides`, so `composer install` refused the raised `php` constraint ("your php version (8.3.0; overridden via config.platform)"). The gate now runs `composer update --lock` for those hops, which moves no package and rewrites the lock's hash and overrides; apps without the pin keep the plain install. Found by running Booster on WELD, 2026-11-06.
- `php-83-to-84` now moves a `config.platform.php` pin to 8.4.1, not 8.4.0: PHP 8.4.0 was tagged but never released, so packages floor at 8.4.1 (PHPUnit 13, Symfony 8) and a pin of 8.4.0 stopped `phpunit-12-to-13` from resolving on WELD. Every other PHP hop still pins x.y.0.
- PHPUnit hops now raise Pest with PHPUnit on a Pest app: Pest's majors pair one to one with PHPUnit's (Pest 4 holds PHPUnit at 12, Pest 5 runs on 13), so `phpunit-12-to-13` could never resolve on a Pest 4 app such as WELD. A new `phpunit_companion_pins` table in `data/versions.json` (carried through the refresh like `laravel_companion_pins`) pins `pestphp/pest` and `pestphp/pest-plugin-laravel` to ^2.0, ^3.0, ^4.0 and ^5.0 for PHPUnit 10 to 13, only where the app already lists them; the gate's `composer update -W` refreshes them with PHPUnit.
- `run --replay` on a plan with a build-gated hop now builds the front end (`npm ci`, `npm run build`) before it records the baseline. Before, the baseline served an unbuilt front end (on WELD, the test suite's stub manifest), the first front-end hop built it, and every page failed the replay on its asset tags. A front end that does not build before any change stops the run before the first hop, like any other unusable baseline.
- `typescript-major` now raises `prettier-plugin-organize-imports` to ^4.2 when the app lists an older one (every Sourdough Inertia app lists ^4.1.0): releases before 4.2 ask for vue-tsc ^2 as a peer, so the hop's own install only warned and the next hop's `npm install` (`inertia-2-to-3`) failed with ERESOLVE. The pin lives in a new `typescript_companion_pins` map in `data/versions.json`, carried through the refresh; a package the app does not list is never added.
- The `asset-hash` replay normalizer now hides Vite's hashes (`app-L1fqnEWe.js`, `AppHeader-DK7UwdK-.css`: eight base64url characters after a dash), not only hex ones; plain names such as `app-settings.js` stay as they are.
- `inertia-2-to-3` now runs the build gate (`npm ci`, `npm run build`) after its `npm install` and before the composer gate. Before, nothing built the Inertia 3 client, so a front end that no longer compiled passed the hop and the gate and replay served the old Inertia 2 build. `--skip-gate` skips the build with the composer gate.
- `run --replay` across `inertia-2-to-3` failed every Inertia page on a sound upgrade: Inertia 3 drops `clearHistory` and `encryptHistory` when false, adds `sharedProps` and (locally) a devtools id, and the rebuilt client split a shared chunk into a new `modulepreload` hint. Two new normalizer presets hide exactly those parts, `inertia` (the page object's asset version and those protocol fields; component, props and url stay compared) and `preload-hints`, and `booster replay init` now lists `asset-hash` and `preload-hints` for a Vite app and `inertia` for an Inertia app.
- The dependency check: a package whose newest release cannot install at the plan's starting version (a floor above it, or a ceiling below it) now gets its own finding that names where the plan starts, instead of blocking the first hop in the plan (such as `node-pin-24`, which moves nothing in PHP). Only a hop whose own destination violates the constraint is blamed.
- `laravel-12-to-13`: the hop now raises the companion packages its target major pairs with (for Laravel 13, laravel/tinker to ^3.0) alongside laravel/framework, so a stock Laravel 12 app no longer blocks on tinker ^2. A companion is raised only where the app already lists it in require or require-dev and its constraint floors below the pin's floor; absent packages are never added, and the gate's composer update refreshes the listed companions together with the framework. The pins live in a new `laravel_companion_pins` table in `data/versions.json`, which the refresh carries through per major like the other fixed tables.

## \[1.9.0\] - 2026-11-06 [#](https://booster.apps.wayne.edu/docs/changelog.html#1-9-0-2026-11-06)

### Security [#](https://booster.apps.wayne.edu/docs/changelog.html#security)

- The front-page deploy layer moves to Symfony 7.4.23 (symfony/http-foundation and symfony/yaml advisories, including CVE-2026-118514).

### Added [#](https://booster.apps.wayne.edu/docs/changelog.html#added-2)

- The `vite-major` hop: for an app whose `package.json` lists `vite` below the hop data's Vite major, it pins `vite` to the hop data's version wherever it sits, pins `laravel-vite-plugin` and `@vitejs/plugin-vue` to the hop data's versions when they are already listed, runs `npm install` in the Node container and asks for a build gate. The planner offers it after `node-pin-24` and ahead of `tailwind-3-to-4`, and never alongside `mix-to-vite`, which installs the new Vite itself. It reuses the existing `vite_version`, `laravel_vite_plugin_version` and `vue_plugin_version` keys in `data/versions.json`.
- A new hop, `typescript-major`: for an app whose `typescript` constraint in `package.json` floors below major 6, it pins `typescript` to `^6.0` wherever it sits, and `vue-tsc` to `^3.0` when the app lists it, then runs the build gate (`npm ci`, `npm run build`) and the tests. Offered after `node-pin-24`, `mix-to-vite` and `tailwind-3-to-4`; running the type check and fixing what the new major flags stay manual work, and the hop says so.
- The replay gate, off unless asked for: `booster run --replay[=FILE]` records how the app answers a list of requests (`booster-replay.json`) on its current PHP before the first hop, replays them after every hop's tests, and fails the hop on a changed answer, with the first difference and the transcripts' paths. The app is served by PHP's built-in server in the gate image with no network; fixture services get a private internal network. New commands: `booster replay init` (drafts the file from Laravel's route list, Slim-style routes or PHP entry points, with `todo` items), `booster replay record` and `booster replay check`. Normalizer presets (`csrf`, `session-id`, `timestamps`, `asset-hash`) and custom patterns hide volatile parts. A fixture notes app in the tests proves the pass, the PHP 8 string-comparison failure and the normalizers; `tests/ReplayDockerTest.php` runs it for real (`BOOSTER_DOCKER_TESTS=1`). Guide: `docs/guides/replay-gate.md`.
- Vendored libraries: `plan` and `run` find the libraries copied into `vendor/` (a name and version from its `composer.json` or `VERSION` file, or `version unknown`) and list each with the Composer package that replaces it. The plan text and the run report get a block for them, and the JSON gains `vendored_libraries` and `from_php` (the PHP version the run starts from, when it differs from the app's own).
- An `inertia-2-to-3` hop for apps that require `inertiajs/inertia-laravel` or carry `@inertiajs/vue3` below 3, planned last when the plan lands on Laravel 11 or newer on PHP 8.2 or newer: it pins `inertiajs/inertia-laravel` to `^3.0` and `@inertiajs/vue3` to `^3.0`, runs the composer and npm installs, and lists the Inertia 3 breaking changes (Axios out of the core, `qs` and `lodash-es` no longer bundled, renamed events, `router.cancelAll()`, the restructured config, ESM only, ES2022) as manual lines.

### Changed [#](https://booster.apps.wayne.edu/docs/changelog.html#changed-2)

- Docs theme: the docs read as the front page's own. Dark mode is no longer a deep green page: it is the record room after hours (graphite ground, cream type, pale mint links and titles, gold for focus and the current page), with every kit dark token set by the theme. The hero's graph paper lies under the top of each page and fades out before the reading; the footer is the front page's deep green band, with the version stamped on it like a dose that passed its gate (the stamp lands once, only without reduced motion); pager cards wear the record card's green and gold top edge; wide tables shade the edge that scrolls.

### Fixed [#](https://booster.apps.wayne.edu/docs/changelog.html#fixed-2)

- `tailwind-3-to-4` on a real app: the upgrade tool is pinned to one exact release (`4.3.3`) instead of `^4.1`, because 4.3.4 needs a compiler and Python to install and npx failed silently in the slim Node image. The tool now runs with `--force`, since its clean-tree check needs git, which that image lacks, and earlier hops have already edited the tree. When the tool fails having printed only npm notices, the step says it likely never installed and gives the `docker run` line to try it by hand.
- `tailwind-3-to-4`, `typescript-major` and `inertia-2-to-3` on a real app: after BOOSTER edits `package.json` it now runs `npm install` in the Node container, as `vite-major` does, so `package-lock.json` matches the new pins. Before, the build gate's `npm ci` (or, for Inertia, the hop's own `npm ci`) refused the stale lock and the hop failed on a sound change. The lock sync runs with `--skip-gate` too.
- `tailwind-3-to-4` on a real app: the hop added `@tailwindcss/vite` but never wired it, so an app that fed Tailwind 3 through an inline `css.postcss` block in `vite.config` (the Laravel Vue starter kit of early 2025) failed its build on the removed `autoprefixer`. The hop now moves that exact shape to the `tailwindcss()` plugin; any other Vite config that still imports `tailwindcss` or `autoprefixer` gets a MANUAL line naming the file.
- Front-end hops on a real app: when the hop's `npm install` has to override a peer dependency (npm only warns, then refuses the next plain `npm install` of the same tree), the step now says which package asks for the old version and that it needs raising. Found when `typescript-major` raised `vue-tsc` past an older Prettier plugin's peer range.
- `inertia-2-to-3`: `php artisan view:clear` is now its own MANUAL line, saying why: on a real app, compiled Blade views kept printing the Inertia 2 page data and the Inertia 3 client showed a blank page until they were cleared. The hop's summary note now says `npm install`, which is what it runs.
- The gate container sets `COMPOSER_PROCESS_TIMEOUT=0`, so a slow `composer test` is no longer cut off by Composer's own 300 second limit (a real app's baseline failed that way); the gate's one-hour limit governs.

## \[1.8.3\] - 2026-11-04 [#](https://booster.apps.wayne.edu/docs/changelog.html#1-8-3-2026-11-04)

### Changed [#](https://booster.apps.wayne.edu/docs/changelog.html#changed-3)

- Docs: a new "How do I..." page (`docs/recipes.md`) with 14 recipes: look first, go all the way, PHP only, a specific Laravel, one hop at a time, dry run, backend only, skipping a hop, big apps, tests and the gate, offline and dirty trees, SESAME and WayneUI, other dates and policies, and what to do after a stop. Linked from the docs menu, the front page of the docs, the quick start and the README.
- Docs: the quick start and README gain "What a run does by default": what `detect`, `plan` and `run` do with no flags, the hop-count rule (one hop per PHP minor, Laravel major and PHPUnit major), the extras that come by default or only on request, what a run does per hop, and the ways to stop early. The command reference points to it.

### Fixed [#](https://booster.apps.wayne.edu/docs/changelog.html#fixed-3)

- The second Booster run on a machine no longer dies on hop 1 with `"phar:///tmp/booster-phar-.../phpstan.phar/.../standard_10.stub" is not a file`. The phar unpacks into a new temp directory each run and removes it at exit, but Rector's PHPStan cached its container (and the PHPStan scan its result cache) in the shared temp directory, with absolute paths into the first run's deleted copy. Every Rector cache, PHPStan container, PHPStan scan `tmpDir` and the child processes' `TMPDIR` now live in a per-run scratch directory (`booster-run-<random>`) that is removed at exit, so no run reads another's cache. Tests prove each path is per-run and that the real Rector writes its container there.
- The `php-74-to-80` hop no longer turns a loose `switch` into a strict `match`. Rector's `ChangeSwitchToMatchRector` rewrote `switch ($get['order'][0]['column'])` with `case 1:` into `match (...) { 1 => ... }`, which never matches the string `"1"` from a query string, so every request silently fell to `default`. The rule is skipped for every hop; a test runs the real Rector on a string subject and asserts no `match` appears.
- The gate image builds for PHP 5.5 and 5.6 (Debian jessie): the archive.debian.org rewrite now covers jessie, reads the codename from `VERSION` when `VERSION_CODENAME` is missing, maps `httpredir.debian.org` and a bare `security.debian.org` to the archive, and lets apt use the archive whose signing keys have expired.
- When the gate image cannot be built, `booster run` exits non-zero and says so: "Could not build booster-target:- (docker build exit N), so the gate cannot run", with the ways out (`--image`, `--skip-gate`) and the last 40 lines of the build output instead of BuildKit's whole log.

## \[1.8.2\] - 2026-11-04 [#](https://booster.apps.wayne.edu/docs/changelog.html#1-8-2-2026-11-04)

### Added [#](https://booster.apps.wayne.edu/docs/changelog.html#added-3)

- The Real output page, `site/proof.html`: Booster's own plan and dry-run output on each example app, captured from the real tool, linked from the front page, with a Markdown twin and entries in `llms.txt` and `llms-full.txt`. `composer proof` rebuilds it, `composer proof:capture` re-captures it, and `composer ci` fails when it is stale.

### Changed [#](https://booster.apps.wayne.edu/docs/changelog.html#changed-4)

- The docs pages wear Booster's own look instead of the generic builder one: the front page's masthead and main menu (Docs marked as the current item, and a gold "Run it today" pill on every page that lands on the install one-liners), Barlow Condensed titles with the hero's gold underline, the install section's green slab for commands, the greenbar printout for output, Booster's palette in light mode and a derived deep-green dark mode. It comes from the docs builder's new `theme` key (`docs/_header.html` and `docs/theme/booster.css`); the builder and kit come from Squire's theme-hook branch, which also fixes two kit bugs: the copy button said "Copy failed" after every successful copy, and code blocks that scroll sideways could not be reached by keyboard.
- OPEN_QUESTIONS: the "Not built yet" list now says what shipped (the `&new` pre-pass in 1.0.0, the real-app `cas-to-sesame` hop in 1.6.0, the proof runs of 2026-10-28 and 2026-11-01) and what is still open.
- The front page main menu now links to the docs site (`docs/`), between the Agents and Proof links.

### Fixed [#](https://booster.apps.wayne.edu/docs/changelog.html#fixed-4)

- `booster run` (dry or real) no longer stops a hop with "Rector reported errors" and `PHPStan\PhpDocParser\Ast\Type\CallableTypeNode::__construct(): Argument #1 ($identifier) must be of type ... IdentifierTypeNode, ... FullyQualifiedNameNode given` on any app that has a closure (an `array_map(function ...)`, a route closure). 1.8.0 and 1.8.1 locked Rector 2.8.1 next to PHPStan 2.3.4, and the two load different copies of phpdoc-parser in one process. The lock now pairs Rector 2.8.4 with PHPStan 2.3.5, a new test runs the real Rector (not the fake runner) on a tiny app with a closure so `composer ci` fails if the pair splits again, and Renovate updates the two packages together.
- The Real output page's Markdown twin link points at proof.md, so the agent-ready check passes.

## \[1.8.1\] - 2026-11-03 [#](https://booster.apps.wayne.edu/docs/changelog.html#1-8-1-2026-11-03)

### Changed [#](https://booster.apps.wayne.edu/docs/changelog.html#changed-5)

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

### Added [#](https://booster.apps.wayne.edu/docs/changelog.html#added-4)

- The documentation site: `site/docs/` is built from `docs/` by `bin/docs-site.php` (`composer docs`), the built pages are committed, and `composer docs:check` (part of `composer ci`) fails when they drift from the sources.
- Every shell block in `docs/` is tagged for the examples runner: `booster detect` and the quick start's `booster plan` run against `tests/docs-fixture/` with their output committed beside them, and the rest carry a `docs-test: skip` reason (needs network, needs Docker, installs software, run on your own app).

## \[1.8.0\] - 2026-11-03 [#](https://booster.apps.wayne.edu/docs/changelog.html#1-8-0-2026-11-03)

### Changed [#](https://booster.apps.wayne.edu/docs/changelog.html#changed-6)

- Changed: docs and package metadata name nougat as the canonical repository, with git.wayne.edu and GitHub as read-only mirrors, until git.wayne.edu has CI runners.
- The advisory scans bundle their tools: phpstan/phpstan, squizlabs/php_codesniffer, phpcompatibility/php-compatibility and waynestate/pinstripe moved from require-dev to require, so plan and scan run them from the PHAR or a clone without installing anything into the app. When Booster runs its own vendor/bin/phpcs it points PHP_CodeSniffer at the bundled standard with --runtime-set installed_paths; the skip messages now say to reinstall Booster's dependencies instead of editing the app's composer.json.
- The plan and scan text reports speak plainly: an automatic step no longer carries the internal `[target-image-tests]` label and instead says "After this step your tests run in Docker." after its summary; a step you do by hand is marked `[manual: you do this one by hand]`; the advisory scan line reads "PHPCompatibility check for PHP X: N errors, M warnings." with singular counts ("1 error", "1 warning") instead of "Will break on PHP X"; and packages the dependency check cannot reach are reported as "not on Packagist or unreachable" instead of "offline". JSON output is unchanged.
- User-facing text uses US spelling (behavior, color, license and so on); command and JSON key names are unchanged.

### Added [#](https://booster.apps.wayne.edu/docs/changelog.html#added-5)

- `booster run` prints one line per hop to standard error while it works: the hop's position in the plan, its id, its status, the number of files it changed and the seconds it took, and a closing line totals the hops run, the stops and the whole run. The lines go to standard error so the report on standard output is unchanged and `--format json` stays valid JSON.
- `--path` accepts a leading `~` for your home folder: `~` and `~/...` (or `~\...` on Windows) are rewritten from `HOME`, or `USERPROFILE` on Windows, because the shell does not expand a tilde after `=` or inside quotes. `~user/...` stays as typed.
- A test keeps the reported version, the newest CHANGELOG heading, the README and the front page in step, and the tag build refuses a phar that reports a different version than its tag.

### Changed [#](https://booster.apps.wayne.edu/docs/changelog.html#changed-7)

- The README and the front page install docs put the single-file PHAR first, downloaded straight from the GitHub release (`releases/latest/download/booster.phar`), and make the clone route second. The Linux, macOS and WSL route opens with `sudo apt install php-cli php-xml php-mbstring php-zip php-curl unzip git composer`, because a stock Ubuntu `php-cli` lacks the XML extension Composer's dev dependencies need. The Windows tab no longer claims "no bash or WSL needed"; it says PHP and Composer must be present instead. The Docker-only route states plainly that it can run `detect`, `plan`, `scan` and `run --dry-run`, and that a real `run` needs the single-file or clone route.
- Changed: CI pulls its Docker Hub images through the GitLab Dependency Proxy (the group's CI_DEPENDENCY_PROXY_GROUP_IMAGE_PREFIX), so pipelines no longer hit Docker Hub's anonymous pull limit.
- When the app requires `cit-web-infra/wayne-cas`, has no `vendor/`, is already on PHP 8.3 or newer, and the repository composer.json names for it cannot be reached, `cas-to-sesame` is now planned as the first step instead of waiting for the Laravel 10 to 11 hop, because the Laravel hops need composer to install and composer is blocked by that repository. The plan explains this in a warning. With the repository reachable, or with `vendor/` already installed, the plan is unchanged.
- An app with no tests of its own is now gated by a built-in syntax check instead of dying on a missing `vendor/bin/phpunit`: when composer.json has neither a `test` script nor a PHPUnit config file or dependency, the baseline and every gate run `php -l` over every PHP file outside `vendor/` and `node_modules/`. The report says what ran: the text report names the syntax check and once explains that it proves the code loads on the new PHP, not that it works, and the JSON baseline gains a `gate` field (`tests`, `syntax` or `custom`). A red baseline now says a PHP file does not parse before any change. Apps with tests are unchanged; `--test-command` still wins and is named as yours.
- The text report speaks plainly: PHPStan findings are labeled "code quality hints, not upgrade blockers" and the plan shows only the first five (`booster scan --phpstan` lists them all); a dry run ends `RESULT: PASS (previewed, no tests ran)`; a step that touched one file says "(1 file)"; and when every PROCTOR finding was already there before the upgrade the line reads "no new findings ... (advisory)" instead of FAIL. JSON output is unchanged.
- A dry-run step that has no edits to make now says "No edits needed for this step (dry run)." instead of "Dry run: nothing was changed.", which read like a failure.
- Every text report now ends with a short plain-English note: what happened, whether anything was changed, and the exact next command with your `--path` filled in (`detect`, `plan`, `scan`, `hops`, `init` and `run`, dry or real; a failed real run names the step it stopped at). `--format json` output is unchanged.

### Fixed [#](https://booster.apps.wayne.edu/docs/changelog.html#fixed-5)

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

## \[1.7.0\] - 2026-11-01 [#](https://booster.apps.wayne.edu/docs/changelog.html#1-7-0-2026-11-01)

WayneUI joins the stack: a hop for the 1.x to 2.x move, and ten more behavior-change warnings.

### Added [#](https://booster.apps.wayne.edu/docs/changelog.html#added-6)

- The `wayneui-1-to-2` hop, opt-in with `--wayneui`, for apps that require `cit-web-infra/wayne-ui`. It runs after the last Laravel hop and before the Vue hops and only when the plan reaches Laravel 11 or later (WayneUI 2.x supports 11 and newer); a plan that ends lower warns and leaves the hop out, and an app that uses WayneUI 1.x without the flag gets a nudge.
- The hop rewrites only the one-to-one items of WayneUI's `MIGRATING.md`, about 57 percent of the mapping: Bootstrap 3 class tokens in class attributes (whole tokens only), Font Awesome icons without size modifiers, five brand colors in CSS and Blade, the Breadcrumbs renames and three `config/wayne-ui.php` keys. It swaps the Composer requirement to `waynestate/wayne-ui` `^2.0`.
- Everything else is listed in the report as `file:line` manual items: layout and component markup that has no one-to-one class, `Breadcrumbs::push(` (its argument order changed, a silent break), `navActive` prefix semantics, jQuery and Bootstrap script hooks, and the old publish tags.
- The hop never deletes forked `resources/views/vendor/wayne-ui` or `public/vendor/wayne-ui`, never edits a `repositories` credential, and says that rotating a deploy token is a person's job.
- Three more behaviour-change rows: Sanctum 4 stops loading its migrations, and yajra/laravel-oci8 adds the case-insensitive NLS defaults at 8.1.0 and drops them at 9.0.0.
- Seven doctrine/dbal rows: getDatabase() on Oracle, BIGINT as int, update and delete with empty criteria, nested savepoints, MySQL foreign keys by engine, BinaryType as string, and Table::getColumns() as a list.

## \[1.6.0\] - 2026-11-01 [#](https://booster.apps.wayne.edu/docs/changelog.html#1-6-0-2026-11-01)

The cas-to-sesame hop now finishes what a person had to do by hand on a real app.

### Changed [#](https://booster.apps.wayne.edu/docs/changelog.html#changed-8)

- `cas-to-sesame` drops the `wayne-cas` path and vcs repositories from `composer.json`, so the moved package no longer lingers as a repository entry.
- `cas-to-sesame` writes `config/sesame.php` from the SESAME defaults when the app has no `wayne-cas` config to carry over.
- `cas-to-sesame` removes `config/wayne-cas.php` when nothing in the app reads its keys; when something still does, the report lists the readers as manual work.
- `cas-to-sesame` flags dead `DUO_*` settings left in the app's configuration.
- The README documents the full cas-to-sesame recipe.

## \[1.5.0\] - 2026-10-31 [#](https://booster.apps.wayne.edu/docs/changelog.html#1-5-0-2026-10-31)

Behavior-change warnings, from the second real-use report: a hop can pass every gate and still change what users see.

### Added [#](https://booster.apps.wayne.edu/docs/changelog.html#added-7)

- Behavior changes to check: `booster plan`, the run report and `--format=json` (`behaviour_changes`) now name the silent changes a step brings: things that still pass every gate but behave differently, such as Carbon 3 reading timestamps as UTC, Laravel 8's Tailwind paginator, or a SweetAlert toast that now closes on a new key. Each entry shows where the app is affected (up to 5 `file:line` hits and a count), the setting that keeps the old behaviour when there is one, and what to check by hand. Advisory only: it never blocks or fails a step.
- `data/behaviour-changes.json`: 63 curated entries (Laravel 5.8 to 13 and Carbon 3, PHP 7.1 to 8.5, and package majors of sweet-alert, Guzzle and Sanctum), each with its source link. An entry fires only when the app's code, config or composer.json shows it is affected; three apply to every app crossing their step. Package entries print on the plan's last step, compared against the lock as it was before the run.

## \[1.4.0\] - 2026-10-30 [#](https://booster.apps.wayne.edu/docs/changelog.html#1-4-0-2026-10-30)

Fixes from the second real-use report (two apps taken to PHP 7.4/Laravel 8 and PHP 8.5/Laravel 13 with SESAME).

### Added [#](https://booster.apps.wayne.edu/docs/changelog.html#added-8)

- `--test-env KEY=VALUE` (repeatable) passes settings, such as a database host, to the baseline and every gate. Reports name the keys only, never the values.
- Citadel apps (a `deployments/` folder) get a once-only `deployments/0000_composer_install_<stamp>` script when a run changes `composer.lock`, because Citadel copies the old `vendor/` into each new deployment and never re-runs a deployment script that already ran. It adds `php artisan migrate --force` only when the app has `artisan`.
- The skill has an optional ADVISOR section with the real install lines.

### Changed [#](https://booster.apps.wayne.edu/docs/changelog.html#changed-9)

- `cas-to-sesame` leaves an app that signs people in. Before, the hop reported success on an app that would not boot. The hop now requires `waynestate/sesame:^0.3` and:
  - writes SESAME's own published config with two changes: the CAS root falls back to the app's old wayne-cas root (the environment and the playbook still win), and the wayne-cas compat user resolver is the default. The env lookups, `SESAME_DRIVER` and the `fake` block for local work are kept;
  - rewrites every model using wayne-cas's `WayneCasable` trait, in any form including Rector's fully qualified one, to implement SESAME's `WayneCasable` and use `ReadOnlyAttributes`, with the one `getReadOnlyAttributes` signature. A leftover trait use fails the step instead of passing silently, and stale `WSU\WayneCas` imports are removed;
  - moves `WayneAuthenticate` from the global `web` group to the app's web routes (`wayne.cas` alias);
  - points the `web` guard at SESAME's `sesame` driver and the users provider at `eloquent`, so `auth()->user()` works and the user is reloaded every request.
  - removes the old wayne-cas repository entry from `composer.json`, which pointed at a repository that needs a sign-in;
  - requires `guzzlehttp/guzzle` when the app has neither Guzzle nor `symfony/http-client`, since SESAME needs an HTTP client. Anything Booster cannot edit exactly becomes a manual step naming the file, and the report lists only changes it made.
- Packages Booster adds to `composer.json` are appended, keeping the app's order. They are sorted, in Composer's order, only when the app sets `config.sort-packages`.
- The weekly hop-data refresh treats a change of date alone as no change, so it opens a merge request only when the data changed.

### Fixed [#](https://booster.apps.wayne.edu/docs/changelog.html#fixed-6)

- The hop-data robot crashed on its first GitLab API call. Booster now locks CREED 0.1.4, whose bot client is fixed.
- PROCTOR runs as the final target even when the plan has nothing left to do.
- The Pinstripe scan uses Pinstripe's `laravel.neon` (Larastan) on Laravel apps that have Larastan, and skips IDE helper files, so Laravel apps no longer get a wall of false "undefined method" findings.
- `laravel-8-to-9` removes `fideloper/proxy` (it does not allow Laravel 9) and moves a stock `TrustProxies` to `Illuminate\Http\Middleware\TrustProxies`; a customized one gets a manual note. The plan suggests HELMET when that hop is in it and the app has no HELMET.

## \[1.3.0\] - 2026-10-30 [#](https://booster.apps.wayne.edu/docs/changelog.html#1-3-0-2026-10-30)

Fixes from the first real use of Booster on two Laravel apps (PHP 7.1 and 7.3, Laravel 5.8 and 8, CAS, oci8).

### Changed [#](https://booster.apps.wayne.edu/docs/changelog.html#changed-10)

- Laravel and PHPUnit hops now update `composer.lock`: the gate runs `composer update -W` for the packages the hop changed, inside the target image, and the new lock is part of the hop's diff. Before, the gate failed at `composer install` (Composer 2.10) or quietly tested the old framework. Dry runs never update anything.
- A failed `composer install` or `composer update` is reported as a Composer failure with its output, never as "phpunit exited 4", and a baseline whose install failed is no longer reported as failing tests.
- Plans with `--sesame` swap CAS for SESAME before `laravel-10-to-11`, as soon as the app is on PHP 8.3, because wayne-cas 2.x stops at Laravel 10.
- Apps on PHPUnit below 9 get a manual `phpunit-to-9-manual` step before the first PHP 8 hop, instead of a warning that skipped every PHPUnit hop.
- The app's current PHP comes from what `composer.lock` allows when that is newer than the `composer.json` floor; `detect` shows the lock's PHP range and says which package sets it.
- Rector never edits generated IDE helper files (`_ide_helper*.php`, `.phpstorm.meta.php`).
- A Laravel hop's dry run against a `vendor/` from an older Laravel says "can't preview yet" instead of ERROR.
- The undo hint also names the files a failed step created (`git clean -fd -- <paths>`), and only those.
- The SESAME report says what the swap actually wrote to each model.
- Laravel hops remove the imports their Rector rules left unused.

### Added [#](https://booster.apps.wayne.edu/docs/changelog.html#added-9)

- `plan` checks each direct dependency's newest release (Packagist, and composer-type repositories the app lists) against every planned hop: it names the first hop a package blocks and flags abandoned packages. `--no-package-check` turns it off for offline use.
- `--until=<hop>` stops the plan after that hop; `--only=<hop>` runs a single hop.
- When Composer refuses tagged releases for known security advisories (Laravel 9 and 10 fall back to `9.x-dev` without an error on Composer 2.10), Booster adds a temporary, scoped `config.policy.advisories.ignore` entry, says so, and fails the final target while the entry is still there.
- Target images for PHP 7.1 to 8.0 build again: their Debian releases moved to archive.debian.org, and PHP 7.1 gets Composer 2.2 LTS.
- `--image` takes a `{php}` template (`registry/php-apache:{php}`) or a per-version map (`8.0=a:1,8.1=b:2`), so a PHP hop can gate on a different image from its baseline. The README covers arm64 Macs.
- A warning when the app's tests are only Laravel's stock examples, and a README section on a smoke test that works for apps behind CAS.

## \[1.2.0\] - 2026-10-30 [#](https://booster.apps.wayne.edu/docs/changelog.html#1-2-0-2026-10-30)

### Changed [#](https://booster.apps.wayne.edu/docs/changelog.html#changed-11)

- The gate's target image carries the extension set Citadel's apps run with: intl, ldap, soap, zip, gd, pdo_mysql, mysqli, bcmath, pcntl, exif, redis, imagick and oci8 (before: zip, pdo_mysql, mysqli and bcmath). The extensions come from mlocati/php-extension-installer, pinned to 2.13.1 by digest; it also downloads Oracle Instant Client for oci8, so the first build takes a few minutes.
- Target image tags are stamped with a short hash of `docker/Dockerfile` (`booster-target:<php>-<stamp>`), so an edited recipe rebuilds the image instead of reusing a stale tag.

### Added [#](https://booster.apps.wayne.edu/docs/changelog.html#added-10)

- `--image` for `run`: run the gate in a Docker image of your own instead of building `booster-target`. The image needs `php` and `composer` on `PATH`; Booster uses it when it is present locally and pulls it otherwise.
- `scripts/check-target-image.sh` and `scripts/check-target-image.ps1`: build the target image for each PHP version and verify its extension list.

## \[1.1.0\] - 2026-10-30 [#](https://booster.apps.wayne.edu/docs/changelog.html#1-1-0-2026-10-30)

### Changed [#](https://booster.apps.wayne.edu/docs/changelog.html#changed-12)

- The 50-file cap per hop becomes a 200-file review threshold: an interactive run shows the per-rule summary and asks before applying a bigger hop, while non-interactive and JSON runs stop at the threshold. `--max-changes` is now an explicit hard limit that never prompts, and `0` lifts the limit entirely.

### Fixed [#](https://booster.apps.wayne.edu/docs/changelog.html#fixed-7)

- The GitLab release job runs on any runner architecture: it calls the Releases API with curl from a multi-arch image instead of the deprecated release-cli.

## \[1.0.0\] - 2026-10-30 [#](https://booster.apps.wayne.edu/docs/changelog.html#1-0-0-2026-10-30)

### Added [#](https://booster.apps.wayne.edu/docs/changelog.html#added-11)

- The PROCTOR policy gate: a baseline before the first hop and a final check after the last, so only findings new against the baseline block the run. The report groups New, Already present and Fixed; `--proctor-advisory` makes it report-only; the JSON report carries a `proctor` object with `status`, `blocking`, `new`, `already_present`, `fixed` and `baseline`.
- The PHPStan advisory scan at the destination PHP, using Pinstripe's `base.neon` preset: `plan` ends with it and `booster scan --phpstan` runs it alone. Findings never change the exit code; a scan that cannot run is reported with the command that enables it.
- The removals advisory: `data/removals.json` lists the functions and constructs removed in each PHP version, `booster scan --removals` lists uses up to `--php`, and each PHP hop in `plan` and `run` prints what was removed between its two versions. The scan is token-level and reads the app before the hop rewrites it.
- A pre-pass on PHP hops: PHP 4 era `$a = &new Foo();` becomes a plain assignment before Rector, because modern PHP refuses to compile the old spelling. The rewrites are listed per file under `Pre-pass fixes:`.
- `--memory-limit` for the Rector process (default `2G`, `-1` for no limit); an empty or invalid Rector report points at the flag.
- Hops for the oldest apps: `php-55-to-56`, `laravel-5.8-to-6`, `laravel-6-to-7` and `laravel-7-to-8`. The hop list is now 32.
- `cas-to-sesame` is a real migration, not a stub: it renames the `WSU\WayneCas\` classes to SESAME's compatibility namespace, swaps the Composer requirement for `waynestate/sesame ^0.2`, writes `config/sesame.php` from `config/wayne-cas.php` and rewrites models that use `WayneCasable` as a trait. Duo two-factor stays manual work.
- Vue 2 to Vue 3 as staged hops: `vue-26-to-27`, `vue-27-to-compat` and `vue-compat-codemod` (the official `vue-upgrade-tool`). The `vue-2-to-3` playbook stands in for all three; skipping it skips them.
- The `tailwind-3-to-4` hop: the official `@tailwindcss/upgrade` tool, then `tailwindcss` pinned to `^4.1`, the matching Vite or PostCSS plugin added, and `autoprefixer` and `postcss-import` removed.
- Inertia support in the Laravel hops: an app that carries `inertiajs/inertia-laravel` and `@inertiajs/vue3` gets both constraints raised toward the target major. Booster never adds a package the app does not have.
- Laravel Boost: `detect` and `plan` report apps that carry `laravel/boost`, and every hop playbook has a Boost section.
- The PHAR release: every version tag publishes `booster.phar` as a GitLab release asset and a GitHub release attachment. The build prints a SHA-256 checksum, and `scripts/build-phar.sh` has a no-Docker mode for CI.
- The hop data robot: `bin/hop-data-bot.php` runs in the scheduled pipeline and opens (or updates) a merge request on Booster when the refresh changes `data/versions.json`; a person reviews and merges.
- `waynestate/proctor ^0.2` as a runtime requirement.
- mix-to-vite accepts if (mix.inProduction()) { mix.version(); } and drops it with a note; its refusal text lists the accepted calls.
- A failed baseline whose output mentions a Mix or Vite manifest now names the missing build artifact.

### Changed [#](https://booster.apps.wayne.edu/docs/changelog.html#changed-13)

- The version policy is read straight from the `waynestate/creed` package (`^0.1`) in Booster's own `vendor/`, and the plan names it as `waynestate/creed <version>`. There is no bundled copy to refresh.
- `--skip-gate` also skips the PROCTOR check at the end.
- Exit code 1 now covers new PROCTOR findings.
- The Preflight components and the GitHub reusable workflow pin the `0.1.2` tag instead of a commit SHA.
- The deploy layer's Squire kit is refreshed to 0.3.4 (release options can live in `./.env.squire`, which is now git-ignored).
- The scan skip message and the README say how to approve the PHPCompatibility installer plugin.
- Bound targets (--to-php and the rest) leave Node and Vue hops out unless --with-node is passed; the plan says so.

### Removed [#](https://booster.apps.wayne.edu/docs/changelog.html#removed)

- The generated copy of CREED under `src/Creed/` (reader, `policy.json`, manifest) and the `composer creed-copy:check` script: the policy comes from the `waynestate/creed` package.

### Fixed [#](https://booster.apps.wayne.edu/docs/changelog.html#fixed-8)

- Invalid UTF-8 in a Rector report is repaired instead of stopping the run; the report notes the repair.
- Rector parse errors keep their line numbers.
- `vue-compat-codemod` keeps unrecognised output lines whole and cuts long output on a character boundary.

## \[0.1.3\] - 2026-10-29 [#](https://booster.apps.wayne.edu/docs/changelog.html#0-1-3-2026-10-29)

### Added [#](https://booster.apps.wayne.edu/docs/changelog.html#added-12)

- Added: agent-ready site (prompt blocks, Markdown twins, llms.txt), from Squire's kit.

### Fixed [#](https://booster.apps.wayne.edu/docs/changelog.html#fixed-9)

- Fixed: stale capability claims in the site and README.

### Changed [#](https://booster.apps.wayne.edu/docs/changelog.html#changed-14)

- Changed: the proof page moves to a private site that needs a Wayne State sign-in.
- Changed: project names written as words except acronyms.

## \[0.1.2\] - 2026-10-29 [#](https://booster.apps.wayne.edu/docs/changelog.html#0-1-2-2026-10-29)

- CREED reader re-exported from CREED 0.1.2: versions older than the oldest listed line are now end of life instead of refused.

## \[0.1.1\] - 2026-10-29 [#](https://booster.apps.wayne.edu/docs/changelog.html#0-1-1-2026-10-29)

### Added [#](https://booster.apps.wayne.edu/docs/changelog.html#added-13)

- `data/versions.json`, the hop data file (shipped in the PHAR), and `bin/refresh-hop-data.php`, which rewrites it from the Packagist, endoflife.date and npm feeds plus CREED's policy. `composer ci` ends with the offline `--check` (shape validation against the bundled policy, then a recompute from the frozen feeds in `tests/fixtures/hop-feeds` as of the file's `generated_on` date); `--online` compares against the live feeds without writing the file.
- A scheduled GitLab pipeline job that runs the refresh against the live feeds and fails when `data/versions.json` would change, so a feed that moved while the file slept is visible until someone commits the refreshed file.
- A plan warning when the target major has no Rector ruleset yet: BOOSTER stops short of the target instead of failing.

### Changed [#](https://booster.apps.wayne.edu/docs/changelog.html#changed-15)

- The PHP, Laravel and PHPUnit hop lists now come from `data/versions.json`; a major whose Rector ruleset is not installed gets no hop.
- The `node-pin-24` hop now floors Node at the hop data's target (26 today, following the policy's newest supported major) and runs its npm work in the matching `node:` container.

## \[0.1.0\] - 2026-10-29 [#](https://booster.apps.wayne.edu/docs/changelog.html#0-1-0-2026-10-29)

### Added [#](https://booster.apps.wayne.edu/docs/changelog.html#added-14)

- `booster plan`, `run`, `hops`, `detect` and `init` commands (also `vendor/bin/booster`).
- App-type detection for the five stack permutations from `composer.json` and `package.json`.
- A hop registry: PHP one minor at a time from 5.6 to 8.5, Laravel one major at a time from 8 to 13, a `wayne-cas` to SESAME stub recipe, and a Vue 2 to 3 playbook.
- PHPUnit hops, one major at a time from 9 to 13, planned when the app's `phpunit/phpunit` constraint is detected and the target PHP can run them; a `--to-phpunit` target option and a `PHPUnit:` line in the plan.
- A planner that interleaves PHP, Laravel and PHPUnit hops inside Laravel's supported PHP range and refuses unsupported targets.
- Policy loading from `--policy`, `BOOSTER_POLICY`, or a bundled snapshot of CREED's `policy.json`.
- A generated copy of CREED's policy reader under `src/Creed/` (namespace `Waynestate\Booster\Creed`), written by CREED's `bin/export.php` and verified by `composer creed-copy:check`; BOOSTER's own reader is gone.
- A Rector runner on `rector/rector` 2.8 and `driftingly/rector-laravel` 2.7 with a per-run file cap, a PHP target override and a synthetic `installed.json`.
- A `composer.json` editor for PHP, Laravel and SESAME constraints.
- A target-image test gate (`docker/Dockerfile`, `booster-target:<php>`).
- A recorded baseline test run before the first hop: the app's suite on its current PHP, so a later red gate can be told apart from a suite that was already red. A red baseline stops the run unless `--allow-failing-baseline` is passed, in which case later gate failures are labeled as pre-existing.
- Coding-tab playbooks generated into `.agents/skills`, with `booster init --check`.
- A contract (`contract/expectations.json`) and example apps for all five permutations.
- GitLab and GitHub CI that both call `composer ci`.
- A plan warning when the target is newer than CREED's policy (treated as current).
- A `nothing-to-do` status for a hop that changes no files and skips the gate.
- An incomplete-preview marker for a Laravel hop whose app has no `vendor/`, plus a unique-files summary and a closing line in the dry-run report.
- A `node-pin-24` hop for apps with a `package.json`: it sets `engines.node` to `>=24` and writes `.nvmrc`, is planned after the PHP hops and ahead of the front-end playbooks, and skips itself when the app already floors Node at 24 or newer. The npm work runs in a `node:24` container, so the machine needs Docker but no Node; a hop may also ask for a build gate (`npm ci` and `npm run build` in that container).
- A `mix-to-vite` hop for apps with a `webpack.mix.js`, planned after `node-pin-24` when the plan lands on Laravel 9 or newer: it writes `vite.config.js`, swaps `laravel-mix` for `vite` and `laravel-vite-plugin` in `package.json` (plus `@vitejs/plugin-vue` when the Mix file compiled components), renames `MIX_` keys to `VITE_` in `.env.example`, rewrites Blade `mix()` script and link tags into one `@vite([...])` line per layout when every referenced asset maps to a Mix entry, and deletes `webpack.mix.js`. Only the simple shape converts: `mix.js`, `.vue()`, `.sass()`, `.postCss()`, `.version()` and `if (mix.inProduction()) { mix.version(); }` (dropped with a note, since Vite hashes production assets); anything else is refused by name, as is a Vue 2 front end (run `vue-2-to-3` first). Unmapped references, bare `mix()` calls and `withoutMix()` sites are left alone and listed as manual notes. The gate runs `npm ci`, `npm run build` and a `public/build/manifest.json` check in the `node:24` container, then the app's tests.
- `booster detect` now reports the Node floor from `engines.node`.
- An advisory PHPCompatibility scan (`phpcompatibility/php-compatibility` 10.0.0-alpha2 on `squizlabs/php_codesniffer` 3.13): `plan` shows "Will break on PHP X.Y" for the first PHP hop's target, and a new `booster scan --path=... --php=X.Y [--format=json]` command runs it on demand. Findings never change exit codes; a scan that cannot run is reported as "could not run" without failing the plan. The scan ignores `vendor/`, `node_modules/` and `storage/`; `plan --no-scan` skips it.

### Changed [#](https://booster.apps.wayne.edu/docs/changelog.html#changed-16)

- The PHPCompatibility packages (`phpcompatibility/php-compatibility`, `squizlabs/php_codesniffer`) moved from `require` to `require-dev` and `suggest`: the advisory scan is now optional, and BOOSTER reports a skip when no installed PHP_CodeSniffer carries the PHPCompatibility standard.
