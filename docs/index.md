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
modernizes code within the PHP version the app already runs.

## Where it fits in WELD

WELD is the Wayne State application stack. Booster is its upgrade layer.

| Part | Job |
|---|---|
| WayneUI (project 0) | The interface layer every other part builds on |
| CREED | The version policy (`policy.json`) Booster reads |
| PROCTOR | Measures an app against that policy; the gate runs it before the first hop and after the last |
| SESAME | Auth; Booster can swap `wayne-cas` for it |
| HELMET | The baseline runtime |
| Sourdough | The starters |
| Squire | The Citadel deploy kit; the layer that serves this repo's front page is one |
| Parity | Citadel on your computer: its PHP image, its proxy and MySQL 5.6, so an app that works here works on Citadel (0.1.0 coming) |
| Preflight | The CI templates |
| Pinstripe | The coding standard |
| Booster | This project: moves apps forward |
| Rollcall | The fleet census: which apps run what, and how close each is to end of life |
| Drawbridge | The one OpenID Connect front door for the Citadel |
| ADVISOR | The read-only MCP server that answers a coding agent's stack questions |
| WELD | The Laravel starter app with the rest of the stack already set up |

It works for all five permutations of the stack: raw PHP, raw PHP with a Vue SPA, Laravel with Blade, Laravel with
Blade and Vue islands, and Laravel with Inertia and Vue.

## Run it today

Two one-liners get Booster onto your machine:

<!-- docs-test: skip needs network -->
```
curl -L -o ~/bin/booster.phar https://github.com/waynestate/booster/releases/latest/download/booster.phar
```

or, from Packagist:

<!-- docs-test: skip installs software -->
```
composer create-project waynestate/booster booster ^1.0
```

Then the first command, run from inside the app you want to upgrade:

```bash run
booster detect
```

```output
path           .
app_type       laravel-blade
composer_json  yes
php            8.2
laravel        10
phpunit        10
vue            unknown
node           unknown
mix_file       no
wayne_cas      no
wayne_auth     no
wayneui_1      no
boost          no
helmet         no

vendor/ is not installed. Run composer install in the app before plan and run --dry-run: without it their previews miss edits.

Nothing was changed. This is what Booster read from composer.json and package.json; "php" is the lowest PHP version
composer.json allows, not what your server runs (if it is wrong, add --from-php=X.Y to plan).
Keys: composer_json = composer.json found, mix_file = webpack.mix.js found, wayne_cas = the cit-web-infra/wayne-cas package, wayne_auth = the old Wayne Auth sign-in in the app's own code, wayneui_1 = the cit-web-infra/wayne-ui package, boost = the laravel/boost package, helmet = the waynestate/helmet package.
Next: php bin/booster plan --path=.
```

Every route, per OS, is on [Install](install.md). Moving Booster itself to a new release, and what an app an older
Booster moved needs then, is on [Upgrading](upgrading.md).

## Guides

- [Quick start](guides/quick-start.md)
- [How do I...](recipes.md)
- [The scans](guides/scans.md)
- [The dependency check](guides/dependency-check.md)
- [Behavior changes](guides/behaviour-changes.md)
- [The baseline](guides/baseline.md)
- [A smoke test for WSU apps](guides/smoke-test.md)
- [What a hop does](guides/hops.md)
- [The replay gate](guides/replay-gate.md)
- [Hand-copied libraries to Composer](guides/framework-hops.md)
- [Strict session IDs](guides/strict-sessions.md)
- [Laravel hops and vendor/](guides/laravel-and-vendor.md)
- [Deploying on Citadel](guides/citadel.md)
