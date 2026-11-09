---
title: Hand-copied libraries to Composer
description: The vendored-to-composer hop moves an app's hand-copied Doctrine 2, Smarty 3 and Slim 1 out of vendor/ and onto their maintained Composer releases, with the replay gate as proof.
---

# Hand-copied libraries to Composer

This page covers the first framework hop, `vendored-to-composer`: what it moves, what it writes, what makes it refuse,
and how the replay gate proves the app still answers the same. Before this: [The replay gate](replay-gate.md). Next:
[Strict session IDs for raw PHP apps](strict-sessions.md).

Some apps never met Composer. In 2012 a real WSU app copied Doctrine 2.2, Smarty 3.1 and Slim 1.6 straight into
`vendor/`, loaded them with `require` lines, and ran happily until PHP moved on. The PHP hops cannot help it: Booster
never edits a library's files, and a 2012 copy of Doctrine crashes on PHP 7.4 before the app's own code runs. So this
hop moves the app instead. The hand copies leave, `composer.json` asks for the maintained release of the same library,
and the lines that loaded the copies load Composer's autoloader. Every other hop lifts the engine; this one finally
gets the parts from a dealer instead of the junkyard.

## In two commands

<!-- docs-test: skip run on your own app -->
```sh
booster plan --path=<app> --from-php=5.6 --vendored          # where the move fits, or the stop signs that block it
booster run --path=<app> --from-php=5.6 --vendored --replay  # move it, with the replay as proof
```

Without `--vendored` the plan still finds the copies and says so:

    The app hand-copied Doctrine, Slim and Smarty into vendor/. Add --vendored to plan the move to Composer.

Draft the replay file first (`booster replay init`, see [The replay gate](replay-gate.md)). The drafter reads Slim 1
routes (`$app->get('/batches/:id', ...)`), so an old Slim app gets a useful first draft.

## The recipes

Each recipe goes to the nearest maintained release that keeps the API the app already calls, so the app's own code
does not change. Booster checked these against Packagist on 2026-11-06.

| Copy under `vendor/` | Moves to | Needs PHP | The app's code |
| --- | --- | --- | --- |
| Doctrine 2.x (`vendor/Doctrine`) | `doctrine/orm ^2.20`, `doctrine/annotations ^1.14`, `symfony/cache` | 7.1 | unchanged: ORM 2.20 still has `Setup`, `EntityManager::create()` and docblock annotations |
| Smarty 3.x (`vendor/Smarty`) | `smarty/smarty ^4.5` | 7.1 | unchanged: Smarty 4 keeps the `Smarty` class and its API |
| Slim 1.x (`vendor/Slim`) | `slim/slim ^4.15`, `slim/psr7 ^1.6`, plus `slim1-bridge.php` | 7.4 | unchanged: the bridge keeps `new Slim()`, `$app->get()`, `render()`, `redirect()` and friends |

The planner puts the hop at the first PHP every recipe's release runs on: right after the PHP hop that lands on 7.4
when the app has Slim 1, on 7.1 when it has only Doctrine and Smarty, and first when the app is already there. A plan
that ends below that leaves the hop out with a warning.

## What the hop changes

- Deletes each moved copy (`vendor/Doctrine/`, `vendor/Smarty/`, `vendor/Slim/`), and `vendor/` itself when nothing
  else is left in it. That is the only thing it does to `vendor/`: it never edits a library file.
- Writes `composer.json` (or adds to the app's own): the packages above, `php` at the hop's PHP when the app named
  none, and `autoload.classmap` for the bridge. A package the app already requires keeps its constraint.
- In each app file, the first `require` or `include` of a moved copy becomes `require_once` of `vendor/autoload.php`
  with the same path prefix, so `dirname(__FILE__) . '/../vendor/Smarty/Smarty.class.php'` becomes
  `dirname(__FILE__) . '/../vendor/autoload.php'`. Later requires of the copies go, and so do the 2012 bootstrap lines
  that pointed at them: `Setup::registerAutoloadDirectory(...)`, `define('SMARTY_DIR', ...)` and
  `require SMARTY_DIR . 'Smarty.class.php'`. Line endings stay as they were.
- For Slim 1, writes `slim1-bridge.php` at the app root (next section).

The gate then runs `composer install` on the hop's PHP inside the target image, which resolves the packages and
writes the app's first `composer.lock`; an app that already had a lock gets `composer update -W` for the new packages
only. Then it runs the app's tests, or the syntax check for an app with none, and the replay.

That lock is right for the hop's PHP and no newer. On 7.4, Slim 4's `psr/container` 2.0 holds
`symfony/service-contracts` at v1.1.2, which needs PHP below 8.0, so a plain `composer install` on 8.0 refuses the
lock. Each later PHP hop therefore reads the lock first and updates just the packages that refuse its PHP (here
`composer update -W symfony/service-contracts` on the 8.0 hop, which also lifts `symfony/deprecation-contracts`),
inside `composer.json`'s constraints. On a real app's `composer.json` that update moved two packages, and nothing in
the lock refused 8.1 through 8.5 after it. One lock for every PHP from 7.4 to the target is not on offer: Composer picks the newest release each
PHP allows, so no `config.platform.php` pin finds that lock on its own, and Booster never creates that key.

## The Slim 1 bridge

Slim 4 is not Slim 1: different classes, PSR-7 requests, a different router. Rewriting every route by hand is how a
framework move eats a semester. Instead the hop writes `slim1-bridge.php`, about 1,200 lines ported from Slim
1.6.7 (MIT), that keeps Slim 1's classes and behavior and hands the routing and the sending to Slim 4:

- `:param` patterns and conditions, the first registered route wins, parameters url-decoded once and passed in
  pattern order, echoed output as the body, `Content-Type: text/html` by default;
- the 404, 405 (with `Allow`) and 500 pages byte for byte, the 301 to a trailing slash, `_METHOD` and
  `X-HTTP-Method-Override`;
- `render()` through the app's own `Slim_View` subclass, `halt()`, `stop()`, `redirect()`, `notFound()`, `error()`,
  `urlFor()`, `getMode()`, `configureMode()`, route middleware, and the app's own properties on `$app`.

It does not turn a PHP notice into an error page the way Slim 1 did in development mode: on a new PHP every
deprecation would become a 500, which is a strange way to celebrate an upgrade. The bridge is the app's code from
then on. Move one route at a time to plain Slim 4 (or to Laravel) with the replay green after each, and delete the
file when nothing calls `new Slim()` any more.

## Stop signs

The hop rewrites a few exact line shapes and nothing else. Anything that touches a moved library in another way is a
stop sign: the plan leaves the hop out and names the file and line, and a run that finds one refuses the step and
changes nothing.

- Doctrine's own `ClassLoader` (ORM 2.20 no longer ships it; move the app's own classes to Composer's autoload first),
  or a `registerAutoloadDirectory` call spread over several lines.
- `SMARTY_DIR` used for anything but loading Smarty, or a path into a copy that is not a one-line `require`.
- The Slim 1 API the bridge does not carry: hooks, application middleware (`Slim_Middleware`), `flash()`, cookies
  through the app or the response, `etag()`, `lastModified()`, `expires()`, `pass()`, `Slim::getInstance()`, and a route
  pattern with regular-expression characters, which Slim 4's router reads differently.
- A copy whose major the recipe does not cover (Smarty 2, Doctrine 1, Slim 2), an existing `slim1-bridge.php`, or a
  `composer.json` that is not valid JSON.

A commented-out call is not a stop sign. Booster reads the line, not the vibe.

## What stays

- Libraries without a recipe (PHPMailer, Swift Mailer, Twig, Monolog, anything Booster does not recognize) stay where
  they are, still loaded the way they always were, and the hop lists each one as a MANUAL line.
- Copies in a nested `vendor/` (say `admin/vendor/Smarty`) stay too: `composer.json` goes at the app root, so only the
  root's `vendor/` can be pointed at Composer.

## Why the replay waits

With `--replay`, the baseline is recorded on the app's current PHP as always. The PHP hops before
`vendored-to-composer` still run their test gate, but their replay is deferred, because the app still loads its 2012
copies and those may not run on the new PHP; that is the problem the hop exists to fix. From the hop on, every step is
compared against the baseline:

    Replay deferred to step 6 (vendored-to-composer): the app still loads its hand-copied libraries, which may not run on PHP 7.4. The answers are compared once Composer's releases are in.

A real app's replay found two Doctrine changes no test asked about: `DISTINCT(alias.field)` in DQL reads as an
expression in ORM 2.20, and an entity whose id holds a date needs a custom column type. The hop lists both as MANUAL
lines, so you know where to look when the replay says an answer changed.

## After the hop

- Commit `composer.json`, `composer.lock` and, for Slim, `slim1-bridge.php`. Citadel never runs `composer install`, so
  commit `vendor/` on the deploy branch or let Booster's `deployments/` script install it (see
  [Deploying on Citadel](citadel.md)).
- ORM 2.20 is the last line that reads docblock annotations, and `doctrine/annotations` 1.x is abandoned. ORM 3 (PHP
  8.1 or newer, attributes instead of annotations) is the next move, by hand for now. Smarty 5 likewise, once the
  templates stop using PHP functions as modifiers.
- The lock was resolved on the hop's PHP, and later PHP hops move only the packages that refuse theirs. Run
  `composer update` once the app lands on its target to pick up the newest releases that PHP allows.
