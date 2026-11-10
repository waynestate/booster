---
title: Upgrading
description: What changes when you move Booster to a new release, and what an app an older Booster already moved needs.
---

# Upgrading

This page says what changes when you move Booster itself to a new release, newest first. Every section follows the
[Changelog](changelog.md). Moving Booster changes no app by itself: a new booster stage does not re-fly the rockets
already in orbit. An app that an older Booster already moved keeps what that Booster wrote, and the sections below say
when that needs a step of yours.

To take a new Booster, download the PHAR again (the same `curl` line as on [Install](install.md); its
`releases/latest` link always names the newest release), or run `git pull` and `composer install` in your clone. `booster --version` prints the version you have.

## 1.18.0

Released 2026-11-10. Housekeeping to keep pace with the stack. No hop was added or removed, no option changed and no
exit code changed.

### What you see

- **A newer SESAME starting point.** When an app has no vendor copy of SESAME's config, `cas-to-sesame` now writes
  SESAME 0.7.3's (it was 0.5.0's). The hop still requires `^0.7`, so an app that already ran it needs nothing.
- **Pinstripe 0.3.** Booster requires `waynestate/pinstripe` `^0.3` (was `^0.1.4`); the lint presets it reads are
  0.3.1's.
- **PROCTOR ^0.15** in Booster and its deploy layer: the newer checks only warn.

### An app that an older Booster already moved

Nothing to do.

## 1.17.0

Released 2026-11-09. Follow-ups to 1.16.0. No hop was added or removed, no option changed and no exit code changed.
Two environment variables are new, and both are off unless you set them.

### What you see

- **A Node pin that builds.** On an app whose `package.json` has a `build` script and a `package-lock.json`,
  `node-pin-24` now runs `npm ci` and `npm run build` in the `node:<major>` container before the tests, so a real run
  takes longer and needs Docker for that step too. A red build stops the run at that hop. With no build script the hop
  prints `No build script in package.json, so the front-end build was not checked.` and carries on as before.
- **A rerun that re-gates.** After a run that stopped at a red gate, the next run reads `booster-hop.json` and runs
  that hop's gate again: a `Re-gate: PASS` or `FAIL` line after the baseline when the hop left the plan, or a gate
  on the hop's own step when it is back with nothing to edit. Keep `booster-hop.json` between the two runs. The JSON
  report and `booster-hop.json` gain `regate` on such a run; a script that reads them by key keeps working.
- **Faster gates, if you ask.** `BOOSTER_COMPOSER_CACHE` and `BOOSTER_NPM_CACHE` name a Docker volume or a folder
  that every gate container shares (see [Configuration](reference/configuration.md)). The WSU stack's volumes are
  `stack-composer-cache` and `stack-npm-cache`.

### An app that an older Booster already moved

Nothing to do. An app that an older Booster already pinned keeps its pin, and its front end was never built on that
Node by Booster: run `npm ci` and `npm run build` on that Node once to be sure.

## 1.16.0

Released 2026-11-09. Lessons from a real Laravel app taken from PHP 8.3 to 8.5. No hop was added or removed and no
exit code changed. `plan` and `run` gain `--node`. The gate's target images are the same; five `booster init`
playbooks carry new notes, so `booster init --check` reports them stale until you run `booster init` again.

### What you see

- **A different Citadel deploy step.** A run whose lock change touched only dev packages, or an app with
  `scripts/release.sh`, or an app with no `deployments/` folder, gets no `deployments/0000_composer_install_*` script
  any more; the report's `Deploy:` line says why and, for the last case, what each server must run. When Booster
  does write the script, it installs the production packages and stops there: it no longer runs
  `php artisan migrate --force`.
- **A Node pin that matches the server.** An app with the Squire kit is pinned to the Node in its Citadel image
  (`NODE_IMAGE` in `.squire/base-fallback.Dockerfile`, 22 today), not to 26. Any other app still gets the newest Node
  the policy supports. `--node=<major>` picks one yourself.
- **New lines in reports.** `plan` and the dry run warn when `vendor/` is missing; `detect` says so too and adds
  `vendor_missing` to its JSON. A run that raised the PHP floor ends with the CI matrix entries and kit images that
  still name an older PHP (`floor_follow_ups` in JSON), and its `Deploy:` lines are in `deploy_notes`. A clean audit
  prints one line. A script that reads the JSON by key keeps working.
- **Two more progress lines.** Standard error gets `baseline: ...` lines before the first hop, and the closing total
  counts the baseline. A script that counts progress lines sees two more.
- **Local dates.** With no `date.timezone` in php.ini, or `UTC`, "today" and the deploy stamp follow the machine's time zone
  instead of UTC, so an evening run no longer plans for tomorrow.

### An app that an older Booster already moved

Booster 1.15.0 and earlier wrote `deployments/0000_composer_install_<stamp>` with `php artisan migrate --force` at
its end. Citadel runs deploy scripts once each, in name order, so that script migrates before every other script in
`deployments/`. If the app has not deployed it yet, look before you release:

- When the app's own deploy scripts migrate (Squire's `scripts/release.sh` does), delete Booster's script: they
  already install and migrate, in the order the app needs. That matters most for a Laravel app whose first release
  parks old tables before it migrates.
- Otherwise keep the script, and decide whether its migrate line belongs there or in the app's release flow.

An app that `node-pin-24` already pinned to Node 26 keeps that pin: a newer Booster never lowers one. When its
server runs an older Node (Citadel's PHP image ships 22), set `engines.node` in `package.json` and `.nvmrc` to that
major by hand; `npm ci` on the server stops warning `EBADENGINE`.

## 1.15.0

Released 2026-11-09. Lessons from a real 2012 raw PHP app taken from PHP 5.6 to 8.5. No hop was added or removed,
no option or exit code changed, and the gate's target images and the `booster init` playbooks are the same, so
`booster init --check` stays green.

### What you see

- **New lines in `detect` and `plan`.** `detect` prints a `wayne_auth` line and lists hand-copied libraries; `plan`
  lists dynamic properties on the step that crosses PHP 8.2. The JSON reports gain `wayne_auth` and
  `hand_copied_libraries` (detect) and `dynamic_properties` (plan). A script that reads them by key keeps working.
- **A Wayne Auth stop sign.** An app that still signs people in through Wayne Auth now sees `detect` and `plan` open
  with a stop sign. Move the app onto SESAME's Wayne Auth bridge before the first hop off PHP 5.6; the
  [recipes](recipes.md) link SESAME's guide.
- **Next lines you can paste.** The plan footer and a dry run's Next line carry the flags you typed, so the line runs
  the plan you just read.
- **`composer.lock` changes on every PHP hop.** Each PHP hop on an app with a lock now runs `composer update --lock`
  in its gate, so the lock's content hash follows the `require.php` the hop raised. Commit `composer.lock` with each
  hop.
- **Fewer dependency warnings.** The dependency check judges the releases the app's constraint allows, so a warning
  about a major line `composer update` would never pick is gone.
- **Tests wait for the vendored move.** With `--vendored` and a test command, the steps before `vendored-to-composer`
  run the syntax check and say "Tests deferred"; the baseline and every step from the move on run your tests.

### An app that `vendored-to-composer` already moved

Rector on Booster 1.14.0 or earlier rewrote `slim1-bridge.php` on the PHP hops after the move. That file still works.
It no longer matches the file Booster ships, so Booster treats it as the app's own code from now on, and Rector may
visit it again. Nothing to do.

## 1.14.0

Released 2026-11-09. Nothing to do: no hop, command, option, exit code or gate image changed. Booster's own CI moves
to the Preflight 0.5.0 components, Booster locks Pinstripe 0.1.5, and this page is new.

## 1.13.1

Released 2026-11-09. The stack sweep: the `cas-to-sesame` hop writes `waynestate/sesame:^0.7`, and the gate's target
image runs Composer 2.11.1. No command, option or exit code changed.

### What you see

- **A fresh image on the first gate.** For an app on PHP 7.2 or newer, the gate's target image now installs Composer
  2.11.1 (it was 2.10.3, which is no longer maintained). The images for PHP 5.6 and 7.0 build again with Debian's
  current archive keys. The recipe stamp changes with both, so the first `booster run` after the upgrade builds the
  image again, and that first gate takes a little longer. Images for older PHP keep Composer 2.2. Nothing to do.
- **A stale playbook.** The `cas-to-sesame` hop's notes name `waynestate/sesame:^0.7`, and the hop playbooks that
  `booster init` writes carry those notes. In an app where you ran `booster init` with an earlier Booster,
  `booster init --check` reports that playbook as stale until you run `booster init` again.

### An app that `cas-to-sesame` already moved

An app that ran the `cas-to-sesame` hop on Booster 1.13.0 or earlier stays on the SESAME line that Booster wrote:
`waynestate/sesame:^0.6` from Booster 1.12.0 and 1.13.0, `^0.5` from 1.11.0, and `^0.4` before that. SESAME is below
1.0, so a caret stops at the next minor: `composer update` never takes 0.7.0 on its own. A newer Booster does not plan
the hop again either, because the app no longer requires `cit-web-infra/wayne-cas`. The move to `^0.7` is yours, and
it is one line:

<!-- docs-test: skip run in your own app -->
```
composer require waynestate/sesame:^0.7
```

Then run the app's tests and sign in once on pre-production, as you would after any SESAME release.

What SESAME 0.7.0 changes: one new check. Its raw PHP session (`NativeSession`, which the Wayne Auth bridge also uses)
warns once per request when the session cookie lacks `HttpOnly`, lacks `Secure` on an https request, or has a
`SameSite` other than `Lax` or `Strict`; with `checkSessionSettings: true` that warning is an exception instead.
SESAME still never sets a cookie flag itself. An app that `cas-to-sesame` moved is a Laravel app, and the Laravel
adapter hands SESAME Laravel's own session, which writes its cookie from `config/session.php`, so the check never
runs there. SESAME 0.7.0 needs the same PHP (8.3 or newer) and the same packages as 0.6, so the `require` above moves
nothing else.

An app still on `^0.5` or `^0.4` also takes SESAME's earlier steps on the way: 0.5.0 binds each CAS sign-in to the
browser that started it, and the 0.6 releases fix the wayne-cas shim for Eloquent user models and for Laravel 13's
JSON sessions. SESAME's
[upgrading guide](https://sesame.apps.wayne.edu/docs/upgrading.html) has every step, one version at a time.

An app that never ran `cas-to-sesame` needs nothing: the next `booster run --sesame` writes `^0.7` itself.
