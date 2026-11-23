---
title: CakePHP and Slim apps onto Laravel
description: Booster 1.21 moves a CakePHP 5 or Slim 4 app onto Laravel 13 one unit at a time (the strangler), with sign-in on SESAME first and the replay as the gate for every run.
---

# CakePHP and Slim apps onto Laravel

This page covers `cakephp-to-laravel` and `slim-to-laravel`, the two faces of one hop that moves a CakePHP or Slim
app onto Laravel 13 without a rewrite. Before this: [CakePHP and Slim apps to PHP 8.5](cakephp-and-slim.md). Next:
[Strict session IDs for raw PHP apps](strict-sessions.md).

CakePHP and Slim are being phased out at WSU (D-124). The upgrade hops keep an app supported; this hop retires the
framework. It is the strangler fig pattern: Laravel grows around the old app, one route or form at a time, until
nothing is left to strangle. The old app keeps answering every request it has not handed over yet, so the app ships
after every run, not after a big-bang rewrite.

## Before you start

The hop is opt-in, and it is always the last step of a plan. It needs:

- **The framework's latest major.** CakePHP 5 or Slim 4. Run the upgrade hops first (`booster plan` shows them).
- **PHP 8.3 or newer.** SESAME and Laravel 13 both need it.
- **A committed, clean tree.** The mount moves the whole app, so `--allow-dirty` does not apply to this hop.
- **A usable replay.** `booster-replay.json` with no todo items left, and a baseline that is not one redirect
  everywhere or all errors. Draft it with `booster replay init`; [the replay gate](replay-gate.md) has the details.
  The replay is the only thing that can say a moved page still answers the same, so there is no strangler without it.
- **No LTI.** SESAME has no LTI driver yet, so an LTI tool's Laravel half could not sign anyone in.

<!-- docs-test: skip run on your own app -->
```
booster plan --path=../my-app --to-laravel
```

A bare `--to-laravel` (no major) is the opt-in; `--to-laravel=13` still only sets a Laravel app's target. `--only
slim-to-laravel` (or `cakephp-to-laravel`) plans the hop alone.

## Run 1: Step 0 and the mount

<!-- docs-test: skip run on your own app -->
```
booster run --path=../my-app --only slim-to-laravel --replay
```

Run 1 does two things and stops:

1. **Step 0, sign-in on SESAME.** The old app's sign-in moves onto SESAME first, so both halves can share one
   sign-in and one logout. It is gated by the replay as the app is served today; the sign-in hand-off now goes to CAS,
   and the gate accepts exactly that change on the paths Step 0 names. An app that already signs in through SESAME
   skips the change. Both halves require `waynestate/sesame` `^0.11`: 0.10.0 lost the signed-in identity on
   Laravel's JSON sessions, so a logout on one half did not end the other. On a Laravel app with JSON sessions, users
   signed in under 0.10.0 are signed out once after the upgrade and sign in again (usually silently, through SSO).
2. **The mount.** Laravel 13 goes at the app root (the same app `sourdough new --laravel` makes). The old Composer
   root moves, unchanged, to `legacy/` with its own `vendor/`. `public/index.php` becomes a small dispatcher that sends
   every request to legacy unless the route table, `bootstrap/booster-moved.php`, says Laravel has it. The Laravel half
   signs in under `/_laravel/login`. The replay checks the mounted app against the untouched app's answers
   (`--serve-changed`, since the app is now served another way). The root `composer.json` lists the old app's Composer
   `repositories` too (a relative path or artifact repository now points into `legacy/`), so both halves find the same packages.

Only when the mount passes its gate does Booster write the ledger, `booster-strangler.json`. A mount that fails leaves
its changes in place for you to read, and no ledger: undo it with `git checkout -- . && git clean -fd` and run again
once the cause is fixed.

Commit run 1, then deploy it together with the one Citadel change it needs: the app's public directory becomes
`public` (the report lists it under MANUAL).

## Later runs: one unit at a time

<!-- docs-test: skip run on your own app -->
```
booster run --path=../my-app --only slim-to-laravel --replay --moves 1
booster run --path=../my-app --only slim-to-laravel --replay --route "GET /users/{id}"
```

A **unit** is one route, or one form (its GET and its POST move together). The ledger lists every unit with its state:
`legacy`, `laravel` (Booster moved it), `hand` (a person ported it) or `failed` (it failed a gate). Units move in a set
order: stateless pages first, then pages, then writes, then the ones that need sign-in.

`--moves N` moves the next N units still in legacy (1 by default); `--route "<id>"` moves just that one, and is also
how to retry a failed unit. Booster writes the Laravel route and controller, adds the unit to the route table, and
(when the move added a package to the Laravel half's `composer.json`) runs `composer update -W` for it in the gate
image; a failed update marks the unit `failed` with Composer's first error line and the run stops before the gate.
Then it runs the gate with the moved units answering from Laravel: the syntax check over both halves, the old app's own tests
from inside `legacy/`, then the replay. A green run marks the units `laravel`. A red one marks them `failed`, leaves
the changes in place, and says how to undo them. A failed unit waits for a person: the next `--moves` run skips it.

Each run is one diff to review and commit. Booster never commits.

A moved route keeps the old answer's headers. The first move sets HELMET's `scope` to `web` in `config/helmet.php`,
and every moved route skips HELMET's SecurityHeaders, InjectRibbon and RequestForgeryGuard, so a moved page carries
no CSP or X-Frame-Options the old app did not send (a nonce CSP would also block its inline scripts). The Laravel
half's own pages keep all three. A MANUAL line says so; once the units are on Laravel, drop the skips one route at a
time and check each page under HELMET's CSP. A hand-ported route needs the same skip until then.

When a package a move or Step 0 adds needs a shared package (such as `psr/http-message` 2) that a locked package
holds at its old major, the gate's `composer update -W` fails on it; Booster then names those locked packages once
more, inside `composer.json`'s constraints, and says which in the report.

A moved CakePHP unit that reads the database uses the old app's own connection. At request time, inside the Laravel
process, `CakeDatabase` reads the `default` Datasource from `legacy/config/app.php` and `legacy/config/app_local.php`
and opens it as the Laravel connection `cake`. That is the app reading its own config, the way the old half does on
every request. Booster never reads those values when it moves a unit, and they never reach a report or a log. When
the Laravel half gets its own `DB_*` settings, point the moved models at them and drop the bridge (a MANUAL line says
so).

After Step 0 on an app with a form sign-in, `src/Sesame/UserResolver.php` looks up the row the form used to find
(for example `Users` by `email`) by the CAS username, so `getIdentifier()` still returns that row's id and policies
and `user_id` writes keep working. A person with no row signs in with a username only and no id. Make sure that
field holds what CAS sends (the AccessID). The mount gives the Laravel half's resolver, `app/Sesame/StranglerUserResolver.php`,
the same lookup on the legacy database, once a moved unit has brought `CakeDatabase`, so a moved page sees the same
id as the legacy one.

### A unit Booster cannot move

When a unit uses something Booster cannot translate exactly, it refuses that unit, names the file and line, and moves
on to the next one. Port it by hand in the Laravel half (`routes/web.php` and a controller), then flip it:

<!-- docs-test: skip run on your own app -->
```
booster run --path=../my-app --only slim-to-laravel --replay --hand --route "FORM /articles/add"
```

Booster checks that `php artisan route:list` has every method of the unit at its URI, adds it to the route table, marks
it `hand` and gates it like any other move.

## Finish

<!-- docs-test: skip run on your own app -->
```
booster run --path=../my-app --only slim-to-laravel --replay --finish
```

Once no unit is left in legacy, `--finish` deletes `legacy/`, the route table and the install hook, puts back
Laravel's stock `public/index.php`, moves `/login` to Laravel, and gates the result against the replay
(`--serve-changed`). When it passes, the ledger goes too: the app is a plain Laravel app, and the next `booster plan`
plans it like one. While units are left, `--finish` refuses and lists them.

`--finish` also refuses, naming the units, while moved Slim code still needs the old app at run time: a
Slim-hosted unit (it runs a copy of the legacy front controller inside its Laravel route), or an app whose moved
units run in container `app` mode (the copied legacy setup boots per request for its services and app-wide
middleware). Port those services to a Laravel provider and that middleware to Laravel middleware first.

Before `legacy/` goes, the old docroot's static files (robots.txt, images, css) are copied into `public/`, where the
dispatcher served them from; a file `public/` already has with other content is a MANUAL line. When the finish gate
still fails with every unit on Laravel, the difference is usually the old app's own fallback: what it answered for
paths no unit owns (its not-found or method-not-allowed page) and for a trailing-slash path Laravel matches as the
route without it. Port that to Laravel (`Route::fallback()`, the exception handler) and run `--finish` again.

## While the move is in progress

An app with a ledger plans only the strangler: every other hop waits for `--finish`, and the plan says so. To patch the
old app meanwhile (a security fix, a PHP hop), run Booster on it alone with `--path=../my-app/legacy`.

## The report

Every strangler run reports, in text and in the JSON report's `strangler` section:

- the units moved this run, each with its replay line;
- the units still in legacy, with failed ones marked;
- every refusal, with file and line; a refusal several units share prints once with the units it names, and when every
  refused unit runs the same file (a CakePHP base controller's `initialize()`), a MANUAL line says to port that file first;
- the MANUAL checklist, grouped: sign-in, mount, route moves, finish;
- for Slim, the Slim-hosted units still in `routes/web.php` and their `SLIM-HOSTED:` lines, in their own section (`strangler.slim_hosted` in JSON);
- the next command to run.

## Stop signs

Each one means nothing changed: not on the framework's latest major, PHP under 8.3, a dirty tree, `legacy/` there
without a ledger (a mount that did not pass), a Laravel app already at the root (`artisan`, `bootstrap/app.php` or
`routes/web.php`), no usable replay, and an LTI app. A Slim skeleton's own `app/` folder is not one: it moves into
`legacy/` with the rest of the old app.
