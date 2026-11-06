---
title: Laravel hops and vendor/
description: Why Laravel hops need the app's vendor folder, and where the hop data comes from.
---

# Laravel hops and vendor/

This page covers what Laravel hops need from the app, and where the hop data comes from. Next: [Deploying on Citadel](citadel.md).

rector-laravel needs Laravel's classes to recognize a model or a controller. For a Laravel hop Booster loads the app's
own `vendor/autoload.php` when it exists, so run `composer install` in the app first. PHP hops never load the app's
autoloader. An app with no `vendor/` still gets a plan and the hops that do not need Laravel's classes.

For an Inertia/Vue app, each Laravel hop also raises the Inertia constraints the app already carries:
`inertiajs/inertia-laravel` and `@inertiajs/vue3` move to the constraint the hop data pairs with the target major
(`^2.0` for Laravel 11 and newer). Booster never adds a package the app does not have, and below Laravel 11 it pins
nothing and says so, because the hop data carries no constraint there.

## Hop data

`data/versions.json` holds the version facts Booster plans against: the PHP, Laravel and PHPUnit chains, the PHP range
each Laravel major supports, the PHPUnit floor each Laravel major ships, the Node floor the `node-pin-24` hop writes,
the Vite major and plugin pins the `vite-major` hop uses, and the fixed Tailwind, TypeScript and vue-tsc pins and
the Inertia constraint tables the front-end hops use. The file ships inside the PHAR. A new
major appears in the file as soon as its feed says so; Booster offers a hop for it only once Rector ships a ruleset
for it, and a plan toward a major without one stops there with a warning instead of failing.

`bin/refresh-hop-data.php` rewrites the file from the public feeds (Packagist for PHPUnit and Laravel, endoflife.date
for Laravel's supported PHP ranges, npm for the Vite packages) plus CREED's policy for what counts as supported:

<!-- docs-test: skip needs network -->
```
php bin/refresh-hop-data.php             # fetch the feeds, rewrite the file, print what changed
php bin/refresh-hop-data.php --check     # offline CI check: validate the shape, then compare the frozen feeds
php bin/refresh-hop-data.php --online    # compare against the live feeds without writing the file
php bin/refresh-hop-data.php --offline tests/fixtures/hop-feeds --today 2026-10-29 --dry-run
```

The refresh is deterministic for a given set of feeds and date, and it never touches the recipes: writing a playbook
for a new hop stays human work. `composer ci` ends with the offline `--check`: it validates the file's shape against
the bundled policy, then recomputes it from the frozen feeds in `tests/fixtures/hop-feeds` as of the file's
`generated_on` date, so the check itself needs no network. A scheduled pipeline runs the hop data robot,
`bin/hop-data-bot.php`, against the live feeds; when a feed moved while the file slept, the robot opens (or updates)
a merge request on Booster itself with the new file, and a person merges it.
[MAINTAINING.md](https://github.com/waynestate/booster/blob/main/MAINTAINING.md) covers the token the robot needs.
