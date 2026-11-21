---
title: "Upgrading | Booster docs"
description: "What changes when you move Booster to a new release, and what an app an older Booster already moved needs."
url: https://booster.apps.wayne.edu/docs/upgrading.html
llms: https://booster.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# Upgrading [#](https://booster.apps.wayne.edu/docs/upgrading.html#upgrading)

This page says what changes when you move Booster itself to a new release, newest first. Every section follows the [Changelog](https://booster.apps.wayne.edu/docs/changelog.html). Moving Booster changes no app by itself: a new booster stage does not re-fly the rockets already in orbit. An app that an older Booster already moved keeps what that Booster wrote, and the sections below say when that needs a step of yours.

To take a new Booster, download the PHAR again (the same `curl` line as on [Install](https://booster.apps.wayne.edu/docs/install.html); its `releases/latest` link always names the newest release), or run `git pull` and `composer install` in your clone. `booster --version` prints the version you have.

## 1.21.0 [#](https://booster.apps.wayne.edu/docs/upgrading.html#1-21-0)

Not released yet: this section follows the Changelog's Unreleased list. A CakePHP 5 or Slim 4 app can move onto Laravel 13 one unit at a time (the strangler). Two hops were added and none removed; `plan` and `run` take a bare `--to-laravel`, and `run` gains `--moves`, `--route`, `--hand` and `--finish`. No exit code changed.

### What you see [#](https://booster.apps.wayne.edu/docs/upgrading.html#what-you-see)

- **The strangler, opt-in.** `cakephp-to-laravel` and `slim-to-laravel` are planned only with a bare `--to-laravel` (or `--only` naming one), always last, and only for an app on CakePHP 5 or Slim 4 and PHP 8.3 or newer. Without the flag, no plan changes. [The guide](https://booster.apps.wayne.edu/docs/guides/cakephp-and-slim-to-laravel.html) walks through run 1, the later runs and the finish.
- **`--to-laravel` takes its value optionally.** `--to-laravel=13` and `--to-laravel 13` still set a Laravel app's target; only the bare flag is new.
- **A ledger at the app root.** A mounted app carries `booster-strangler.json`. Commit it with each run. While it is there, `booster plan` plans only the strangler and says so; patch the old app meanwhile with `--path=<app>/legacy`.
- **A strangler section in the report**, in text and in the JSON report (`strangler`): units moved and left, refusals with file and line, and the MANUAL lines grouped by sign-in, mount, route moves and finish.
- **Request files can say more.** Writes gain `"csrf": true`, and `compare` gains `ignore_cookies`, `preset` (`"strangler"`) and `silent_sign_in`. `replay init` now drafts a GET of each write's form page right before it. Existing request files and recordings keep working unchanged: no fingerprint moves, and the replay container only gains `BOOSTER_REPLAY=1`.
- **The mount sets the PHP line once for both halves.** The root `composer.json` asks for the same PHP as `legacy/` (never lower) and its `config.platform.php` pin moves up with it, so one PHP runs both halves.
- **Moved routes keep the legacy headers.** The first move scopes HELMET to `web`, and each moved route skips HELMET's security headers, the ribbon and the forgery guard, so a moved page answers with the headers the legacy page sent. Port those to Laravel when the unit is yours.
- **A strangler gate may move a package the app requires.** When SESAME or a moved unit needs a shared package at a new major and a locked package holds it, the gate names the holders in another `composer update`; when a package the app requires itself still holds it, the gate raises that package one major, keeps the raise only when your check passes, and says so in a MANUAL line. Read that line before you commit.
- **Slim-hosted units and the finish.** A Slim unit that needs Slim's own dispatch (route middleware, a closure's `use` variables) is Slim-hosted, listed in its own report section (`strangler.slim_hosted` in JSON). `--finish` refuses while any moved unit still depends on `legacy/`: a Slim-hosted unit, or one whose adapter runs the legacy app's own setup per request. Port the services to a Laravel provider and the app-wide middleware to Laravel middleware first. A finish copies the legacy docroot's static files (robots.txt and the like) into `public/`.

### An app that an older Booster already moved [#](https://booster.apps.wayne.edu/docs/upgrading.html#an-app-that-an-older-booster-already-moved)

Nothing to do. An app on CakePHP 5 or Slim 4 that 1.20 moved there is exactly where the strangler starts; draft its replay first if it has none.

## 1.20.0 [#](https://booster.apps.wayne.edu/docs/upgrading.html#1-20-0)

Released 2026-11-20. CakePHP 4 apps move to CakePHP 5.4 and Slim 3 apps to Slim 4.15, with the PHP hops to 8.5 around them. Three hops were added and none removed; `plan` and `run` gain `--to-cakephp` and `--to-slim`, `replay check` gains `--serve-changed` and `replay record` gains `--allow-failing-baseline`. No exit code changed, but a run whose replay baseline is all errors now stops before the first hop (see below).

### What you see [#](https://booster.apps.wayne.edu/docs/upgrading.html#what-you-see-1)

- **Three framework hops**, planned between the PHP hops for a CakePHP or Slim app:
  - `cakephp-4-to-4.6` runs CakePHP's own upgrade rules for 4.1 through 4.5 and requires `cakephp/cakephp ^4.6`. It goes first, on the PHP the app runs today, when that is 7.4 or newer; an app below 7.4 gets the PHP hops to 7.4 first, then this one.
  - `cakephp-4.6-to-5` runs the 5.0 through 5.4 rules, moves AuthComponent to the Authentication plugin and swaps the Oracle driver if the app has it. It is planned once PHP reaches 8.2, the first PHP CakePHP 5.4 runs on.
  - `slim-3-to-4` moves a Slim 3 app's bootstrap, routes and middleware to Slim 4.15 over its own Pimple container. It is planned at PHP 7.4, the lowest Slim 4.15 runs on: first when the app is already there, else right after the PHP hop that lands on 7.4.
  The PHP hops then go on to 8.5, and `plan` keeps a CakePHP or Slim app's PHP within what its framework line runs on, and says so, instead of planning a PHP the framework cannot run.
- **The Slim hop runs on PHP 7.4.** It pins `slim/psr7 ^1.6`, the line that still installs there, and writes its glue classes in PHP 7.4 syntax. Code typed with Slim 3's `Slim\Http\Request` and `Response` moves onto `slim/http`. An app with no PSR-4 `autoload` entry for the glue (the official Slim 3 skeleton has only `autoload-dev`) gets a stop sign from `plan`: add one, run `composer dump-autoload` and commit before the run.
- **Install `vendor/` before a CakePHP dry run.** CakePHP's rules read the app's own `vendor/` (mounted read-only), so run `composer install` in the app before `run --dry-run`; an app with no `vendor/` gets a stop sign that says so. A real run's baseline installs it for you.
- **`--to-cakephp` and `--to-slim`** pick the target framework line. A major such as `5` resolves to that line's newest release (`5.4`), `--to-cakephp 4` stops on 4.6, and the default is the newest line Booster supports. A JSON plan carries the resolved line as `framework_target`.
- **`detect` names the framework** and its major.minor (`framework cakephp 4.5`), read from `composer.lock`, else `composer.json`.
- **The MANUAL lists.** Each framework hop prints `MANUAL:` lines for what only a person can do; the guide [CakePHP and Slim apps to PHP 8.5](https://booster.apps.wayne.edu/docs/guides/cakephp-and-slim.html) lists them, with the commands and what each hop changes. One is new to every CakePHP app: CakePHP 4.3 turned some `Cake\Network\Socket` properties into methods (`connected` is `isConnected()`, `encrypted` is `isEncrypted()`), and the upstream rule for them needs a Rector class that no longer exists, so the 4.6 step lists them as a `MANUAL:` line instead of rewriting them.
- **A replay baseline of errors stops the run.** When every recorded answer is a PHP fatal or parse error, a 5xx the request file does not expect, or the same error page for every path, the run stops before the first hop instead of "passing" after each one. Make the app answer on fake data first, or pass `--allow-failing-baseline` (on `run`, and now on `replay record`) to record it anyway with a warning. A baseline with only some error answers, or a fatal in PHP's log, gets a warning that names them.
- **`replay init` knows CakePHP and Slim groups.** It drafts a CakePHP app from `config/routes.php` and the controller actions its `fallbacks()` reach, served from `webroot/index.php`, and Slim routes with their group prefixes. Run `replay init` again (after moving the old draft aside) on an app whose draft an older Booster wrote.
- **A run refused before its first hop writes no `booster-hop.json`**, so it no longer overwrites the record of a stopped hop, and it names the `composer.lock` and `vendor/` its baseline's composer install left behind.
- **`replay check --serve-changed`** compares answers across a change in how the app is served (docroot, front controller, setup), as long as the requests are the same. It is groundwork for the Laravel move.
- **The new engine plumbing is internal.** Framework hops name their engine, and CakePHP's own upgrade tool runs in a container, pinned to a commit, so its Rector never meets the app's or Booster's. There is nothing to configure.
- **Point `--path` at `composer.json`.** Plenty of Slim apps keep `composer.json` under `src/`, not at the repository root. Booster reads the framework from that file, so pass `--path=../my-app/src` for those apps.
- **New and changed playbooks.** `booster init` writes a playbook for each of the three hops, and the overview playbook names them, so in an app where you ran `booster init` with an earlier Booster, `booster init --check` reports the playbooks as stale until you run `booster init` again.
- **The CakePHP 5 step does more of the move.** It makes the CakePHP 5 skeleton's own moves (CakePHP's global functions in `config/bootstrap.php`, `phpunit.xml` in the PHPUnit 10 shape, `RequestHandler` out with a `viewClasses()` where the routes need one, the log and cache renames, `WebExceptionRenderer` for the removed `ExceptionRenderer`), keeps a CakePHP 4 app on PHPUnit 9 until this step and then moves it to 11.5, moves bootstrap-ui, cakephp-csvview and cakephp-codesniffer to their CakePHP 5 majors, and keeps sign-in answering as AuthComponent did (the guest actions `allow()` opened, the old `loginAction` open to guests, the same redirect and flash). The replay switches on a `cakephp` normalizer from this step, which hides only the extra `rel="shortcut icon"` link CakePHP 4's `meta('icon')` printed.
- **The Slim hop keeps Slim 3's runtime.** The container's `environment`, `request` and `response` entries and its four handler keys work as on Slim 3, a missing route or wrong method gets Slim 3's own 404 or 405 page, Slim 3 test harnesses run through the generated `LegacyEnvironment` and `LegacyHttp`, `Slim\Container` type hints move to `LegacyContainer`, Twig 1 and 2's underscore class names move to Twig 3's, and `dflydev/fig-cookies` goes to `^3.0`. The MANUAL lines for the handler keys, the container entries and the error handler are gone.
- **Locks that break on the new PHP, and unblocks that outlive their step.** A PHP hop updates a locked release that allows the new PHP but misbehaves on it (Twig below 2.8 on 7.4). On the final target, Booster tries once to drop a temporary advisory unblock an earlier step needed, with `composer update -W` for those packages, and keeps the change only when the gate passes again without it.

### What Booster will not do [#](https://booster.apps.wayne.edu/docs/upgrading.html#what-booster-will-not-do)

Each of these is a `MANUAL:` line in the run's output, with the file (and the line or count where there is one). The full lists are in [CakePHP and Slim apps to PHP 8.5](https://booster.apps.wayne.edu/docs/guides/cakephp-and-slim.html). New in 1.20.0:

- CakePHP 4.6 step: the `Cake\Network\Socket` properties CakePHP 4.3 turned into methods (see above).
- CakePHP 5 step, sign-in: an `authError` that is not a plain string (Booster flashes no message then), a custom Auth `flash` config (Booster uses AuthComponent's default flash options), a `loginAction` that is not a plain controller and action array or whose controller is missing, a login controller whose `initialize()` does not call `parent::initialize()`, and `allow()` called with a variable.
- CakePHP 5 step, other code: what an action does with `logout()`'s return value, the Oracle driver swap to check, a check that the controllers that serialize still answer `.json` and `.xml` after Booster added `viewClasses()`, `->withType()` calls and `View::loadHelper()` calls. Commented-out code is no longer reported.
- Slim hop: `Slim\Container` or `Slim\Http\Environment` imported in a group `use` statement (import the generated `LegacyContainer` or `LegacyEnvironment` in its own), Slim 3-only `Slim\Http` classes (`Headers`, `Body`, `RequestBody`, `Stream`, `UploadedFile`, `Cookies`, `new Request(...)`), and a Twig underscore class name Rector's map does not know.

### Not in this release [#](https://booster.apps.wayne.edu/docs/upgrading.html#not-in-this-release)

- Moving a CakePHP or Slim app to Laravel (Phase 2). It comes next.
- PHP 8.6 hops for CakePHP and Slim apps. They wait for CakePHP and Slim to support PHP 8.6.
- CakePHP 3 and Slim 2 (and anything older) have no hop yet: `plan` says so and keeps PHP where the framework still runs.

## 1.19.0 [#](https://booster.apps.wayne.edu/docs/upgrading.html#1-19-0)

Released 2026-11-14. The `cas-to-sesame` hop lands on SESAME's native names and sets SESAME `^0.8`. No hop was added or removed, no option changed and no exit code changed.

### What you see [#](https://booster.apps.wayne.edu/docs/upgrading.html#what-you-see-2)

- **Native names for `cas-to-sesame`.** The hop now lands on SESAME's native names instead of the deprecated `Waynestate\Sesame\WayneCas` shim and writes `waynestate/sesame:^0.8` (it wrote `^0.7`): `config/sesame.php` defaults to `ModelUserResolver`, the web routes run under `auth.sesame`, any `wayne.cas` alias registration is removed, user models drop `WayneCasable`, and a model's `ReadOnlyAttributes` trait moves to `app/Models/Concerns` (Booster writes the trait file from SESAME 0.7.5 when it is absent).
- **Two WayneUI 2 behaviour rows.** A plan for an app on WayneUI 2 (`package:cit-web-infra/wayne-ui@2`) now lists two behaviour-change rows, `wayneui-bootstrap-data-hooks` and `wayneui-nav-active-prefix`, taken from WayneUI's MIGRATING guide.
- **PROCTOR ^0.16** in Booster and its deploy layer (was `^0.15`, which does not allow 0.16): the `ci-pipelines` check no longer asks for a GitHub workflow. Booster also requires Pinstripe `^0.4`.
- **The deploy kit is Squire 0.11.1** (was 0.10.8): the release script fetches PROCTOR when `--no-dev` left it out and installs the frontend from a `yarn.lock`, the HAProxy pin is current, the fallback base follows the app's PHP line, and the parity scripts carry the deprecation note.

### An app that an older Booster already moved [#](https://booster.apps.wayne.edu/docs/upgrading.html#an-app-that-an-older-booster-already-moved-1)

An app an older Booster moved with `cas-to-sesame` keeps `waynestate/sesame:^0.7` and the `Waynestate\Sesame\WayneCas` shim, which SESAME 0.8.0 removed. The caret stops below 0.8, so the app stays on the 0.7 line until its owner moves off the shim. The hop will not plan it again, because the app no longer requires `cit-web-infra/wayne-cas`; the move is the hop's own edits, by hand: `config/sesame.php` defaults to `ModelUserResolver`, the web routes run under `auth.sesame`, any `wayne.cas` alias registration goes, `WayneCasable` drops from the user models, and the model's `ReadOnlyAttributes` trait moves to `app/Models/Concerns`. Then `composer require waynestate/sesame:^0.8` and run the app's tests, as after any SESAME release.

## 1.18.0 [#](https://booster.apps.wayne.edu/docs/upgrading.html#1-18-0)

Released 2026-11-10. Housekeeping to keep pace with the stack. No hop was added or removed, no option changed and no exit code changed.

### What you see [#](https://booster.apps.wayne.edu/docs/upgrading.html#what-you-see-3)

- **A newer SESAME starting point.** When an app has no vendor copy of SESAME's config, `cas-to-sesame` now writes SESAME 0.7.3's (it was 0.5.0's). The hop still requires `^0.7`, so an app that already ran it needs nothing.
- **Pinstripe 0.3.** Booster requires `waynestate/pinstripe` `^0.3` (was `^0.1.4`); the lint presets it reads are 0.3.1's.
- **PROCTOR ^0.15** in Booster and its deploy layer: the newer checks only warn.

### An app that an older Booster already moved [#](https://booster.apps.wayne.edu/docs/upgrading.html#an-app-that-an-older-booster-already-moved-2)

Nothing to do.

## 1.17.0 [#](https://booster.apps.wayne.edu/docs/upgrading.html#1-17-0)

Released 2026-11-09. Follow-ups to 1.16.0. No hop was added or removed, no option changed and no exit code changed. Two environment variables are new, and both are off unless you set them.

### What you see [#](https://booster.apps.wayne.edu/docs/upgrading.html#what-you-see-4)

- **A Node pin that builds.** On an app whose `package.json` has a `build` script and a `package-lock.json`, `node-pin-24` now runs `npm ci` and `npm run build` in the `node:<major>` container before the tests, so a real run takes longer and needs Docker for that step too. A red build stops the run at that hop. With no build script the hop prints `No build script in package.json, so the front-end build was not checked.` and carries on as before.
- **A rerun that re-gates.** After a run that stopped at a red gate, the next run reads `booster-hop.json` and runs that hop's gate again: a `Re-gate: PASS` or `FAIL` line after the baseline when the hop left the plan, or a gate on the hop's own step when it is back with nothing to edit. Keep `booster-hop.json` between the two runs. The JSON report and `booster-hop.json` gain `regate` on such a run; a script that reads them by key keeps working.
- **Faster gates, if you ask.** `BOOSTER_COMPOSER_CACHE` and `BOOSTER_NPM_CACHE` name a Docker volume or a folder that every gate container shares (see [Configuration](https://booster.apps.wayne.edu/docs/reference/configuration.html)). The WSU stack's volumes are `stack-composer-cache` and `stack-npm-cache`.

### An app that an older Booster already moved [#](https://booster.apps.wayne.edu/docs/upgrading.html#an-app-that-an-older-booster-already-moved-3)

Nothing to do. An app that an older Booster already pinned keeps its pin, and its front end was never built on that Node by Booster: run `npm ci` and `npm run build` on that Node once to be sure.

## 1.16.0 [#](https://booster.apps.wayne.edu/docs/upgrading.html#1-16-0)

Released 2026-11-09. Lessons from a real Laravel app taken from PHP 8.3 to 8.5. No hop was added or removed and no exit code changed. `plan` and `run` gain `--node`. The gate's target images are the same; five `booster init` playbooks carry new notes, so `booster init --check` reports them stale until you run `booster init` again.

### What you see [#](https://booster.apps.wayne.edu/docs/upgrading.html#what-you-see-5)

- **A different Citadel deploy step.** A run whose lock change touched only dev packages, or an app with `scripts/release.sh`, or an app with no `deployments/` folder, gets no `deployments/0000_composer_install_*` script any more; the report's `Deploy:` line says why and, for the last case, what each server must run. When Booster does write the script, it installs the production packages and stops there: it no longer runs `php artisan migrate --force`.
- **A Node pin that matches the server.** An app with the Squire kit is pinned to the Node in its Citadel image (`NODE_IMAGE` in `.squire/base-fallback.Dockerfile`, 22 today), not to 26. Any other app still gets the newest Node the policy supports. `--node=<major>` picks one yourself.
- **New lines in reports.** `plan` and the dry run warn when `vendor/` is missing; `detect` says so too and adds `vendor_missing` to its JSON. A run that raised the PHP floor ends with the CI matrix entries and kit images that still name an older PHP (`floor_follow_ups` in JSON), and its `Deploy:` lines are in `deploy_notes`. A clean audit prints one line. A script that reads the JSON by key keeps working.
- **Two more progress lines.** Standard error gets `baseline: ...` lines before the first hop, and the closing total counts the baseline. A script that counts progress lines sees two more.
- **Local dates.** With no `date.timezone` in php.ini, or `UTC`, "today" and the deploy stamp follow the machine's time zone instead of UTC, so an evening run no longer plans for tomorrow.

### An app that an older Booster already moved [#](https://booster.apps.wayne.edu/docs/upgrading.html#an-app-that-an-older-booster-already-moved-4)

Booster 1.15.0 and earlier wrote `deployments/0000_composer_install_<stamp>` with `php artisan migrate --force` at its end. Citadel runs deploy scripts once each, in name order, so that script migrates before every other script in `deployments/`. If the app has not deployed it yet, look before you release:

- When the app's own deploy scripts migrate (Squire's `scripts/release.sh` does), delete Booster's script: they already install and migrate, in the order the app needs. That matters most for a Laravel app whose first release parks old tables before it migrates.
- Otherwise keep the script, and decide whether its migrate line belongs there or in the app's release flow.

An app that `node-pin-24` already pinned to Node 26 keeps that pin: a newer Booster never lowers one. When its server runs an older Node (Citadel's PHP image ships 22), set `engines.node` in `package.json` and `.nvmrc` to that major by hand; `npm ci` on the server stops warning `EBADENGINE`.

## 1.15.0 [#](https://booster.apps.wayne.edu/docs/upgrading.html#1-15-0)

Released 2026-11-09. Lessons from a real 2012 raw PHP app taken from PHP 5.6 to 8.5. No hop was added or removed, no option or exit code changed, and the gate's target images and the `booster init` playbooks are the same, so `booster init --check` stays green.

### What you see [#](https://booster.apps.wayne.edu/docs/upgrading.html#what-you-see-6)

- **New lines in `detect` and `plan`.** `detect` prints a `wayne_auth` line and lists hand-copied libraries; `plan` lists dynamic properties on the step that crosses PHP 8.2. The JSON reports gain `wayne_auth` and `hand_copied_libraries` (detect) and `dynamic_properties` (plan). A script that reads them by key keeps working.
- **A Wayne Auth stop sign.** An app that still signs people in through Wayne Auth now sees `detect` and `plan` open with a stop sign. Move the app onto SESAME's Wayne Auth bridge before the first hop off PHP 5.6; the [recipes](https://booster.apps.wayne.edu/docs/recipes.html) link SESAME's guide.
- **Next lines you can paste.** The plan footer and a dry run's Next line carry the flags you typed, so the line runs the plan you just read.
- **`composer.lock` changes on every PHP hop.** Each PHP hop on an app with a lock now runs `composer update --lock` in its gate, so the lock's content hash follows the `require.php` the hop raised. Commit `composer.lock` with each hop.
- **Fewer dependency warnings.** The dependency check judges the releases the app's constraint allows, so a warning about a major line `composer update` would never pick is gone.
- **Tests wait for the vendored move.** With `--vendored` and a test command, the steps before `vendored-to-composer` run the syntax check and say "Tests deferred"; the baseline and every step from the move on run your tests.

### An app that `vendored-to-composer` already moved [#](https://booster.apps.wayne.edu/docs/upgrading.html#an-app-that-vendored-to-composer-already-moved)

Rector on Booster 1.14.0 or earlier rewrote `slim1-bridge.php` on the PHP hops after the move. That file still works. It no longer matches the file Booster ships, so Booster treats it as the app's own code from now on, and Rector may visit it again. Nothing to do.

## 1.14.0 [#](https://booster.apps.wayne.edu/docs/upgrading.html#1-14-0)

Released 2026-11-09. Nothing to do: no hop, command, option, exit code or gate image changed. Booster's own CI moves to the Preflight 0.5.0 components, Booster locks Pinstripe 0.1.5, and this page is new.

## 1.13.1 [#](https://booster.apps.wayne.edu/docs/upgrading.html#1-13-1)

Released 2026-11-09. The stack sweep: the `cas-to-sesame` hop writes `waynestate/sesame:^0.7`, and the gate's target image runs Composer 2.11.1. No command, option or exit code changed.

### What you see [#](https://booster.apps.wayne.edu/docs/upgrading.html#what-you-see-7)

- **A fresh image on the first gate.** For an app on PHP 7.2 or newer, the gate's target image now installs Composer 2.11.1 (it was 2.10.3, which is no longer maintained). The images for PHP 5.6 and 7.0 build again with Debian's current archive keys. The recipe stamp changes with both, so the first `booster run` after the upgrade builds the image again, and that first gate takes a little longer. Images for older PHP keep Composer 2.2. Nothing to do.
- **A stale playbook.** The `cas-to-sesame` hop's notes name `waynestate/sesame:^0.7`, and the hop playbooks that `booster init` writes carry those notes. In an app where you ran `booster init` with an earlier Booster, `booster init --check` reports that playbook as stale until you run `booster init` again.

### An app that `cas-to-sesame` already moved [#](https://booster.apps.wayne.edu/docs/upgrading.html#an-app-that-cas-to-sesame-already-moved)

An app that ran the `cas-to-sesame` hop on Booster 1.13.0 or earlier stays on the SESAME line that Booster wrote: `waynestate/sesame:^0.6` from Booster 1.12.0 and 1.13.0, `^0.5` from 1.11.0, and `^0.4` before that. SESAME is below 1.0, so a caret stops at the next minor: `composer update` never takes 0.7.0 on its own. A newer Booster does not plan the hop again either, because the app no longer requires `cit-web-infra/wayne-cas`. The move to `^0.7` is yours, and it is one line:

```
composer require waynestate/sesame:^0.7
```

Then run the app's tests and sign in once on pre-production, as you would after any SESAME release.

What SESAME 0.7.0 changes: one new check. Its raw PHP session (`NativeSession`, which the Wayne Auth bridge also uses) warns once per request when the session cookie lacks `HttpOnly`, lacks `Secure` on an https request, or has a `SameSite` other than `Lax` or `Strict`; with `checkSessionSettings: true` that warning is an exception instead. SESAME still never sets a cookie flag itself. An app that `cas-to-sesame` moved is a Laravel app, and the Laravel adapter hands SESAME Laravel's own session, which writes its cookie from `config/session.php`, so the check never runs there. SESAME 0.7.0 needs the same PHP (8.3 or newer) and the same packages as 0.6, so the `require` above moves nothing else.

An app still on `^0.5` or `^0.4` also takes SESAME's earlier steps on the way: 0.5.0 binds each CAS sign-in to the browser that started it, and the 0.6 releases fix the wayne-cas shim for Eloquent user models and for Laravel 13's JSON sessions. SESAME's [upgrading guide](https://sesame.apps.wayne.edu/docs/upgrading.html) has every step, one version at a time.

An app that never ran `cas-to-sesame` needs nothing: the next `booster run --sesame` writes `^0.7` itself.
