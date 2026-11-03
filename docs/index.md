---
title: Booster
description: What Booster is, and the first command to run on your app today.
---

# Booster

This page says what Booster is and where to go next. Install it on [Install](install.md), then follow [Quick start](guides/quick-start.md).

**Boosts your versions.** One dose at a time, and every dose gets a stamp.

> A booster shot keeps your immunity current. This one keeps your PHP current, and it stings less.

Booster is WELD's upgrade tool. It plans the hops that take a WSU PHP app from the PHP or Laravel version it runs now
to one the version policy still supports, and it runs them one at a time: [Rector](https://getrector.com) rewrites the
code, Booster raises the constraints in `composer.json`, and the app's own tests must pass on the target PHP before the
next hop starts. It stands on `rector/rector` and `driftingly/rector-laravel` and adds only the ordering, the review
threshold, the gate, the advisory scans and a few WSU recipes.

Booster edits your working copy and prints a report. It never commits to, pushes or opens a merge request on your app
(the only merge requests it opens are its own hop-data refresh on Booster itself): a person reads the diff and merges it.

**Not to be confused with:** Booster moves an app to a newer PHP or framework version; Pinstripe's Rector set only
modernises code within the PHP version the app already runs.

## Where it fits in WELD

WELD is the Wayne State application stack. Booster is its upgrade layer.

| Part | Job |
|---|---|
| WayneUI (project 0) | The interface layer every other part builds on |
| CREED | The version policy (`policy.json`) Booster reads |
| PROCTOR | Measures an app against that policy; the gate runs it before the first hop and after the last |
| Preflight | The CI templates |
| SESAME | Auth; Booster can swap `wayne-cas` for it |
| HELMET | The baseline runtime |
| Pinstripe | The coding standard |
| Sourdough | The starters |
| Booster | This project: moves apps forward |

It works for all five permutations of the stack: raw PHP, raw PHP with a Vue SPA, Laravel with Blade, Laravel with
Blade and Vue islands, and Laravel with Inertia and Vue.

## Run it today

Two one-liners get Booster onto your machine:

```
curl -L -o ~/bin/booster.phar https://github.com/waynestate/booster/releases/latest/download/booster.phar
```

or, from Packagist:

```
composer create-project waynestate/booster booster ^1.0
```

Then the first command, run from inside the app you want to upgrade:

```
booster detect
```

Every route, per OS, is on [Install](install.md).

## Guides

- [Quick start](guides/quick-start.md)
- [The scans](guides/scans.md)
- [The dependency check](guides/dependency-check.md)
- [Behavior changes](guides/behaviour-changes.md)
- [The baseline](guides/baseline.md)
- [A smoke test for WSU apps](guides/smoke-test.md)
- [What a hop does](guides/hops.md)
- [Laravel hops and vendor/](guides/laravel-and-vendor.md)
- [Deploying on Citadel](guides/citadel.md)
