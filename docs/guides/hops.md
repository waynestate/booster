---
title: What a hop does
description: What happens inside one hop, from the Rector dry run to the gate.
---

# What a hop does

This page walks through one hop step by step. The full list of hops is in [the reference](../reference/hops.md).

On PHP hops a pre-pass runs first: PHP 4 era `$a = &new Foo();` becomes a plain assignment, because PHP 7 and newer
refuse to compile the old spelling and Rector would never get a parse tree. The report lists the rewrites per file
under `Pre-pass fixes:`.

1. Rector runs as a dry run. If it reports errors, the run stops before anything is written. So does a hop that would
   change more than 200 files, unless an interactive run confirms it after showing the per-rule summary; an explicit
   `--max-changes` is a hard limit that never asks. An empty Rector report usually means the process ran out of memory,
   and the message points at `--memory-limit`.
2. Rector runs for real, with only that hop's rules.
3. `composer.json` is edited: the `php` or `laravel/framework` constraint is raised, and `config.platform.php` moves
   with it when the app already pins it. Booster never creates that key.
4. The gate runs the app's tests inside `booster-target:<php>-<stamp>`, a container built from `docker/Dockerfile`
   on the PHP the hop moves to. The stamp is a short hash of the recipe, so an edited `docker/Dockerfile` rebuilds
   the image instead of reusing a stale one. The image carries the extension set Citadel's apps run with: intl,
   ldap, soap, zip, gd, pdo_mysql, mysqli, bcmath, pcntl, exif, redis, imagick and oci8. The first build downloads
   Oracle Instant Client for oci8, so it takes a few minutes; later hops reuse the image. Pass `--image` to run the
   gate in your own image instead (below). A red gate stops the run and leaves the changes in place so you can read
   them (`git checkout .` undoes them). To check the image itself,
   `scripts/check-target-image.sh` (or its Windows twin `check-target-image.ps1`) builds it for each PHP version and
   verifies its extension list; it needs Docker and a few minutes per version, so it is not part of `composer ci`.
5. On Laravel and PHPUnit hops the gate refreshes the lock before the tests: `composer update -W` for the package
   the hop moved, so `composer.lock` lands on the new major version next to `composer.json`. Every other hop, and
   the baseline, run a plain `composer install`. The two phases report separately: a Composer failure names the
   Composer phase and Composer's own output, because the tests never ran.

Composer 2.10 refuses to load tagged releases Packagist has flagged with a security advisory, and hops that move
onto Laravel 9 or 10 most often meet that block. The refusal has two faces. The loud one fails the install phase
and names the advisories. The quiet one happens when the app allows dev stability: Composer does not fail at all,
it locks the branch instead, `9.x-dev` where the hop asks for `^9.0`. Booster reads the refreshed lock back and
treats a branch version behind a tagged range like the block. Either way it writes a temporary entry to
`config.policy.advisories.ignore` in `composer.json`: scoped to the package and the constraint Composer refused,
kept visible to `composer audit`, and carrying a reason PROCTOR can find and report until the app removes it. The
gate then retries once. If the retry still fails, the run stops with the unblock and the failure both in the
report, and the entry stays in `composer.json`.

The unblock is temporary by name, and Booster enforces that. When a gated step passes, Booster re-reads
`config.policy.advisories.ignore` and reports every entry whose reason carries its own prefix (`Booster temporary
unblock`; older runs wrote `BOOSTER temporary unblock`, which it still recognizes). A step that is not the plan's
last one applies with a warning; the plan's last step fails until the entry is out of `composer.json`, so the
final target never ships with the unblock still in place. Removing the entry is the app's job once it is off the
blocked versions.

The `--image` value names the image the baseline and every gate run in, and it takes three shapes:

- One name, used for the baseline and every gate: `--image myapp/gate:8.4`.
- A template whose `{php}` is replaced with the PHP version of that baseline or gate:
  `--image registry/php-apache:{php}` gates the 8.1 hop on `registry/php-apache:8.1` and the 8.2 hop on
  `registry/php-apache:8.2`.
- A map of PHP version to image, for a fleet whose images do not follow one pattern:
  `--image 8.0=legacy/php:7.4,8.1=registry/php-apache:8.1`. A map without the version a baseline or hop lands on
  stops the run before the first hop and names the fix; a template never misses.

Booster never builds a `--image` image: it must exist locally or be pullable, and each resolved image is pulled at
most once per run. On an arm64 Mac, Citadel's published images such as `citadel/php-apache:8.5r3` are amd64 only:
pass an arm64 build via `--image`, or set `DOCKER_DEFAULT_PLATFORM=linux/amd64` and let Docker emulate.
`booster-target` itself builds natively on arm64. And the old-PHP images (7.1 through 8.0) point apt at
archive.debian.org, so they get no security updates: they exist to run the app's tests on the way up, not to serve
traffic.

`run` refuses to start on a working copy that is not under git or has uncommitted changes, so the upgrade diff stands
alone. `--dry-run` has no such limit.

A dry run previews every hop against the original tree, so the same file can appear in several hops. The report ends
with the number of unique files and a reminder that nothing was applied. A Laravel hop whose app has no `vendor/`
cannot see the framework's classes, so its preview is marked incomplete and the report says to run `composer install`
first.

A run prints one line per hop to standard error while it works: the hop's position in the plan, its id, its status,
the number of files it changed and the seconds it took, and a final line totals the hops run, the stops and the whole
run. They go to standard error so the report on standard output is untouched and `--format json` stays valid JSON.

Three hops edit manifests instead of code. `node-pin-24` sets `engines.node` in `package.json` and writes `.nvmrc`,
then the app's usual gate applies. `mix-to-vite` converts a simple `webpack.mix.js` to Vite (see [the hop list](../reference/hops.md)) and asks for a
build gate, which runs `npm ci` and `npm run build` in the `node:26` container before the app's tests.
`tailwind-3-to-4` runs the official `@tailwindcss/upgrade` tool in the Node container, then pins `tailwindcss` to
`^4.1`, adds the matching Vite or PostCSS plugin, and removes `autoprefixer` and `postcss-import`, which Tailwind 4
folds in; plugins listed in `tailwind.config.js` and `@apply` inside Vue SFC `<style>` blocks stay manual work, and
the hop says so.

Vue 2 to Vue 3 is staged. `vue-26-to-27` moves the front end to the last Vue 2 release, which carries the Vue 3
reactivity API; `vue-27-to-compat` switches to the `@vue/compat` build; `vue-compat-codemod` runs the
`vue-upgrade-tool` codemod and keeps every manual item it lists. `vue-2-to-3` is the playbook that stands in for all
three: skipping it skips them, and a person finishes the job, because Rector does not read Vue.
