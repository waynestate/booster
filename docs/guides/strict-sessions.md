---
title: Strict session IDs for raw PHP apps
description: The session-strict-mode hop turns on session.use_strict_mode and session.use_only_cookies in a raw PHP app that calls session_start() itself, with one guarded line before each call.
---

# Strict session IDs for raw PHP apps

This page covers `session-strict-mode`: which apps it is for, the line it adds, what counts as already covered, and
what makes it refuse. Before this: [Hand-copied libraries to Composer](framework-hops.md). Next:
[Laravel hops and vendor/](laravel-and-vendor.md).

PHP ships with `session.use_strict_mode` off. With it off, PHP takes any session ID a browser sends, even one the
server never handed out, and starts a session under it. That is the door session fixation walks through: someone
plants an ID on a victim, waits for the victim to sign in, and then rides the same session. With strict mode on, PHP
throws an unknown ID away and issues a fresh one. `session.use_only_cookies` (on by default) keeps the ID out of URLs.

HELMET sets both for apps that boot it, and Laravel runs its own session store, so neither needs this hop. It is for
the older raw PHP apps that call plain `session_start()` and use none of the stack's packages.

## In two commands

<!-- docs-test: skip run on your own app -->
```sh
booster plan --path=<app> --strict-session            # the calls it would cover, or the stop signs that block it
booster run --path=<app> --strict-session --dry-run   # read the diff, then run again without --dry-run
```

Without `--strict-session` the plan still finds the calls and says so:

    The app starts PHP sessions without strict session IDs (3 session_start() calls in 2 files). Add --strict-session to plan the hop that turns them on.

`--only session-strict-mode` (with `--strict-session`) runs just this hop. The planner puts it first, on the PHP the
app runs today, so its gate proves this one change, and the hardening lands even when a later hop stalls.

## The line it adds

Before the statement that holds each `session_start()` call, Booster adds one comment and one guarded line, at the
same indent:

```php
// Booster: strict session IDs, set before the session starts (refuses IDs this server never issued).
if (session_status() === PHP_SESSION_NONE && !headers_sent()) { ini_set('session.use_strict_mode', '1'); ini_set('session.use_only_cookies', '1'); }
session_start();
```

The guard matters. PHP warns when a session setting changes while a session is active or after output has gone out,
and in both cases `session_start()` would not start a new session anyway, so the line simply steps aside. The hop
never adds a warning to a page.

"Before the statement" means the whole statement: a braceless `if (...) session_start();` gets the line above the
`if`, an `else session_start();` gets it above the `if` it belongs to, and a call inside a `match` arm gets it above
the `match`. A call that shares its line with other code (minified files, `<?php session_start(); ?>` in a template)
gets the guard inline, right before it. Booster reads the file with PHP's own tokenizer, skips comments, strings and
methods named `session_start`, and checks that every file it edits still parses.

## Why a line in the code, not a config file

- **Not `.user.ini`.** PHP reads `.user.ini` files only under CGI and FastCGI. Citadel runs PHP as an Apache module,
  which never reads one, so a `.user.ini` would look like a fix and do nothing.
- **Not `.htaccess`.** `php_flag` lines work under the Apache module but are a server error under PHP-FPM, and
  Booster's changes must keep working off Citadel too.
- **Not HELMET.** `helmet_boot()` sets both options and much more, but it also renames the session cookie, which signs
  everyone out once, and it adds headers. That is a bigger change than this hop promises, so an app that requires
  HELMET gets a MANUAL line suggesting it instead.

A php.ini change on Citadel would cover every app at once. That is a separate proposal, not this hop.

## Already covered, left alone

The hop changes nothing for:

- a Laravel app (the planner skips it and says why),
- an app that calls `helmet_boot()` or `SessionHardening::apply()`,
- an app whose root `.htaccess` or `.squire/php.ini` sets `session.use_strict_mode`,
- files under a folder whose own `.htaccess` sets it,
- a file that already names `session.use_strict_mode` (including the line this hop wrote),
- `vendor/` and `node_modules/`.

So a second run changes nothing. Any other `php.ini` in the repository (a Docker one, say) is noted, but Citadel's
PHP does not read it, so the hop still adds the lines.

## Stop signs

Strict mode changes how some apps hand out session IDs. When Booster finds one of these, it refuses, changes nothing,
and names each one:

| Stop sign | Why |
| --- | --- |
| `session_id($id)` with an argument | The app sets the ID itself; strict mode would replace an ID the server never issued |
| The `SID` constant | It carries the session ID in URLs, which cookie-only IDs drop |
| `session.use_trans_sid` on | Session IDs travel in URLs |
| `session.use_only_cookies` or `session.use_cookies` off | The app expects IDs to arrive some other way |
| `<?= session_start() ?>` | No statement can go inside an echo tag |

These are read from `ini_set()` calls, `session_start([...])` options, `.htaccess` and ini files. A value Booster
cannot read (a variable, say) counts as a stop sign. Settings that keep IDs in cookies, such as
`ini_set('session.use_trans_sid', false)`, are fine. After fixing or reviewing each one, set the two options by hand
where it is safe.

## By hand, after the run

- **Sign in on pre-production.** Sign in, use a page that keeps session data, and sign out. A session the server
  already holds keeps working; a browser that sends an ID the server never issued gets a fresh one.
- **Custom session handlers.** An app that calls `session_set_save_handler()` only gets strict mode when its handler
  implements `validateId()` (`SessionUpdateTimestampHandlerInterface`). PHP's own `SessionHandler` class does not.
  The hop names each handler as a MANUAL line.

Official notes: [session.use_strict_mode](https://www.php.net/manual/en/session.configuration.php#ini.session.use-strict-mode)
and [session management basics](https://www.php.net/manual/en/features.session.security.management.php).
