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
