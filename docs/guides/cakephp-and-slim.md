---
title: CakePHP and Slim apps to PHP 8.5
description: Booster 1.20 moves a CakePHP 4 app to CakePHP 5.4 and a Slim 3 app to Slim 4.15, with the PHP hops to 8.5 around them, the replay as the real gate and a MANUAL list for what only a person can do.
---

# CakePHP and Slim apps to PHP 8.5

This page covers the three framework hops for CakePHP and Slim apps: the commands, what each hop changes, and the
MANUAL list each one prints. Before this: [Hand-copied libraries to Composer](framework-hops.md). Next:
[Strict session IDs for raw PHP apps](strict-sessions.md).

Booster 1.20 takes a CakePHP 4 app to CakePHP 5.4 and a Slim 3 app to Slim 4.15, and moves PHP to 8.5 around those
steps. The frameworks hold PHP back: CakePHP 4.5 stops at PHP 8.3 and Slim 3.12 at 7.3, so the PHP hops wait for the
framework hop that lets them go on. PHP 8.6 is out; Booster's 8.6 hops follow once CakePHP and Slim support it.

CakePHP and Slim are being phased out at WSU (D-124): these hops keep existing apps supported; new apps start on raw PHP or Laravel (Sourdough or WELD).

<!-- docs-test: skip run on your own app -->
```
composer global require waynestate/booster:^1.20
booster detect --path=../my-app
booster plan   --path=../my-app --to-php 8.5
booster run    --path=../my-app --replay --to-php 8.5
```

`detect` names the framework and its major.minor (`framework cakephp 4.5` or `framework slim 3.12`), read from
`composer.lock`. `plan` puts the framework hops between the PHP hops, and `run` applies them one at a time, each gated
by the app's tests and the replay. Pass `--to-php 8.5` to both: it keeps the plan and the run on the same target.
`--to-cakephp` and `--to-slim` pick the framework line (the newest by default; `--to-cakephp 4` stops on 4.6).

**Point Booster at the folder with `composer.json`.** Plenty of Slim apps keep it under `src/`, next to the code, not
at the repository root. Booster reads the framework from that file and refuses a hop without it, so give it that folder:

<!-- docs-test: skip run on your own app -->
```
booster plan --path=../my-app/src --to-php 8.5
```

`--replay` matters here. The tests a CakePHP or Slim app ships with (often the skeleton's, or none) prove very little
about a framework move, so the replay is the real gate for both frameworks. Run `booster replay init --path=../my-app`
first and read the draft (see [The replay gate](replay-gate.md)). For Slim it lists the route calls it finds, as
written: a route inside a group shows up without the group's prefix, so add the prefix by hand and keep at least one
request per group, which is what catches a group that lost its prefix. CakePHP routes declared with `connect()` are not
drafted, so check the draft against `bin/cake routes` and add the pages that matter.

## What a CakePHP 4 app gets

Two hops, `cakephp-4-to-4.6` then `cakephp-4.6-to-5`, with the PHP hops between them:

1. `cakephp-4-to-4.6` runs CakePHP's own upgrade rules for 4.1 through 4.5 (`cakephp/upgrade`, in a container, over a
   copy of the app, so a rule set that fails halfway leaves the app as it was), then requires `cakephp/cakephp ^4.6`
   and the matching versions of the CakePHP plugins the app already lists. It goes first, on the PHP the app runs
   today, when that is 7.4 or newer; an app below 7.4 gets the PHP hops to 7.4 first, then this one.
2. PHP moves to 8.2, the first PHP CakePHP 5.4 runs on.
3. `cakephp-4.6-to-5` runs the 5.0 through 5.4 rules (with Chronos 3 and Migrations), renames `.ctp` templates to
   `.php` under `templates/` and moves the locales, moves AuthComponent to the Authentication plugin, swaps the
   Oracle driver if the app has it, and requires CakePHP 5.4 with the plugins that match.
4. PHP moves on to 8.5.

A CakePHP 4.3 app on PHP 8.1 therefore plans `cakephp-4-to-4.6`, `php-81-to-82`, `cakephp-4.6-to-5`, then
`php-82-to-83`, `php-83-to-84` and `php-84-to-85`. The same app on PHP 7.3 plans `php-73-to-74` first, then
`cakephp-4-to-4.6`, then the same course. Stop on 4.6 with `--to-cakephp 4`: it still runs on PHP 8.5, but 4.x is end
of life, so treat it as a rest stop, not a home. A target PHP below 8.2 leaves the CakePHP 5 step out with a warning,
`--skip cakephp-4.6-to-5` leaves it out too, and the PHP hops then stay within what 4.6 runs on.

**Fix deprecations first.** Run your tests with `error_reporting(E_ALL)` on CakePHP 4.6 and fix every deprecation
notice before the 5 step. A deprecation on 4.6 is CakePHP 5 knocking politely; on 5 it stops knocking. Booster tells
you; it does not fix them for you.

**The sign-in move.** The 5 step moves the like-for-like AuthComponent calls (`$this->Auth->user('x')` reads the
identity, `setUser()` becomes `setIdentity()`, `logout()` and `allow()` keep their names on `$this->Authentication`),
swaps `loadComponent('Auth')` for `loadComponent('Authentication.Authentication')`, requires
`cakephp/authentication ^3.0`, and wires `src/Application.php`: the provider interface, the plugin, the middleware
after routing, and a service with the Session authenticator whose guests go to the old `loginAction`.

**What Booster does not do (the MANUAL list).** Each run prints these as `MANUAL:` lines, with the file and the count
where there is one:

- On the 4.6 step: the reminder to fix every deprecation with `E_ALL` before the 5 step.
- On both steps: `View::loadHelper()` calls the rules could not type (it is `addHelper()` since 4.5).
- Sign-in that is not a plain session: `isAuthorized()` (move it to Authorization plugin policies or plain controller
  code), and any Auth config the plugin has no like-for-like for (`authenticate` with a custom authenticator such as
  CAS, an SSO cookie or an LTI launch, `authorize`, `loginRedirect`, `logoutRedirect`, `authError`; `loginAction` is
  carried over as the redirect). Also an `'Auth'` entry with config in a `$components` list, any other
  `$this->Auth->...()` call, what the action does with `logout()`'s return value (now the redirect URL or null), and
  `allow()` calls that passed one name or none, since `allowUnauthenticated()` takes an array.
- `src/Application.php` that Booster could not wire: no such file or class, one that already provides an
  authentication service (check that it loads the Session authenticator), no `bootstrap()` or `middleware()`, no
  routing middleware, or no `loginAction` found (the redirect is the placeholder `/login`).
- Code no rule moves: `RequestHandlerComponent` (declare `viewClasses()` instead), `->withType()` calls,
  `Cake\Mailer\Email` (use `Mailer`), `Cake\I18n\Time` and the mutable `Cake\I18n\Date` (use `DateTime` or the
  immutable `Date`, and check code that changed a date in place), and any `.ctp` template left over.
- `cakedc/cakephp-oracle-driver` has no CakePHP 5 release. The 5 step swaps it for `ioigoume/cakephp5-oracle-driver`
  and renames its namespace; check that it covers the Oracle calls your app makes.
- App bugs the move exposes are reported, never fixed: the hop moves the framework, not the app's own logic.

## What a Slim 3 app gets

One hop, `slim-3-to-4`, at the lowest PHP Slim 4.15 runs on (7.4: first when the app is already there, else right after
the PHP hop that lands on 7.4), then the PHP hops to 8.5. `--to-slim 3.13` is refused: Booster moves Slim 3 apps to Slim 4.15.

- `new \Slim\App([...])` becomes `LegacyApp::create([...])` over your existing Pimple container, which keeps every
  `$container['x']`, `$c->x` and `$c->get('x')` working, and `LegacyApp::finish($app)` goes in just before
  `$app->run()`. No PHP-DI rewrite. The three glue classes (`LegacyContainer`, `LegacyDoublePass`, `LegacyApp`) are
  written into a `Legacy` folder under the app's PSR-4 root, so they are your code from then on.
- `finish()` adds what Slim 3 did by itself, from the same settings: `displayErrorDetails` goes to the error
  middleware, `outputBuffering` to the output-buffering middleware, `addContentLengthHeader` to the content-length
  middleware, `routerCacheFile` to the route cache. The routing middleware runs before your own, so the route is
  resolved before your middleware sees the request. Body parsing is added, because Slim 3 parsed JSON and XML itself.
- Every `add()` middleware keeps its `($request, $response, $next)` shape through the `LegacyDoublePass` adapter.
  Move each one to PSR-15 when you next touch it, and delete the adapter when nothing uses it.
- Route groups: `function () use ($app)` becomes a callable that takes the group, so prefixes survive, and a route
  adder typed `\Slim\App` that a group calls is widened to take the group too.
- `$request->getAttribute('route')` becomes `RouteContext::fromRequest($request)->getRoute()`.
- `composer.json` gets `slim/slim ^4.15`, `pimple/pimple ^3` and `slim/psr7 ^1.7`, plus `slim/twig-view ^3.4` and
  `twig/twig ^3.8` when the app already lists them.

**What Booster does not do (the MANUAL list).** Each run prints these as `MANUAL:` lines, with the file and line:

- Route exceptions now reach Slim's error middleware before your own PHP error or exception handler. Register your
  handler with the error middleware if you want the old page and logging. This line is always printed.
- The `errorHandler`, `phpErrorHandler`, `notFoundHandler` and `notAllowedHandler` container keys (Slim 4 never reads
  them), settings Slim 4 has no equivalent for, and `$settings->get('k')`, now a plain array read.
- `pathFor()`, `urlFor()` and `relativePathFor()` calls (build the URL with the route parser), and Twig templates that
  call `path_for(` or `base_url(` (Twig-View 3 has `url_for`).
- `getAttribute('routeInfo')`, `subRequest()`, `setArgument()` calls to check, `Slim\Http\Environment`, the
  container's `router`, `request`, `response` and `environment` entries, and code in outer middleware that reads the
  route before routing ran.
- A group callable that uses `$this`, or calls `$app` without `use ($app)`, a route adder typed `\Slim\App` that was
  not widened, and middleware added by its container name.
- `LegacyApp::finish($app)` where Booster could not place it, and a glue file that already exists and differs from the
  one the hop writes.

## What has no hop yet

CakePHP 3 and Slim 2 (and anything older): `booster plan` says Booster has no hop for them yet and keeps PHP where the
framework still runs, instead of planning a break. A Slim 4 app below 4.15 is held the same way: `slim-3-to-4` moves
Slim 3 only, so the plan says to move Slim to 4.15 by hand. Moving a CakePHP or Slim app to Laravel is coming next; nothing in
this release does it.

See also: [What a hop does](hops.md), [The replay gate](replay-gate.md), and every hop's notes in
[Hops](../reference/hops.md).
