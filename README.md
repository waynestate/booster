# BOOSTER

[![GitHub CI](https://github.com/waynestate/booster/actions/workflows/ci.yml/badge.svg)](https://github.com/waynestate/booster/actions/workflows/ci.yml)

Checks: `composer ci` (the same command runs on GitLab and GitHub).

> A booster shot keeps your immunity current. This one keeps your PHP current, and it stings less.

Front page: [`site/index.html`](site/index.html), a static page you can open in a browser or serve from `site/`.

BOOSTER is WELD's upgrade tool. It plans the hops that take a WSU PHP app from the PHP or Laravel version it runs now
to one the version policy still supports, and it runs them one at a time: [Rector](https://getrector.com) rewrites the
code, BOOSTER raises the constraints in `composer.json`, and the app's own tests must pass on the target PHP before the
next hop starts. It stands on `rector/rector` and `driftingly/rector-laravel` and adds only the ordering, the cap, the
gate and a few WSU recipes.

BOOSTER edits your working copy and prints a report. It never commits, pushes or opens a merge request: a person reads
the diff and merges it.

## Where it fits in WELD

WELD is the Wayne State application stack. BOOSTER is its upgrade layer.

| Part | Job |
|---|---|
| WayneUI (project 0) | The interface layer every other part builds on |
| CREED | The version policy (`policy.json`) BOOSTER reads |
| PROCTOR | Measures an app against that policy; BOOSTER can run it as part of the gate |
| PREFLIGHT | The CI templates |
| SESAME | Auth; BOOSTER can swap `wayne-cas` for it |
| HELMET | The baseline runtime |
| Pinstripe | The coding standard |
| Sourdough | The starters |
| BOOSTER | This project: moves apps forward |

It works for all five permutations of the stack: raw PHP, raw PHP with a Vue SPA, Laravel with Blade, Laravel with
Blade and Vue islands, and Laravel with Inertia and Vue.

## Install

BOOSTER needs PHP 8.3 or newer, but the app it upgrades does not: Rector runs from BOOSTER's own `vendor/`, so an app
on PHP 7.4 is rewritten by a Rector that runs on 8.3 or newer.

Linux and macOS:

```
git clone https://github.com/waynestate/booster.git
cd booster && composer install
bin/booster --version
```

Windows (PowerShell, no bash or WSL needed):

```
git clone https://github.com/waynestate/booster.git
cd booster; composer install
php vendor\bin\booster --version
```

No PHP on the machine? Run it in Docker (the path after `-v` is where your app lives):

```
docker run --rm -v "${PWD}:/booster" -w /booster composer:2 composer install --no-interaction
docker run --rm -v "${PWD}:/booster" -v "C:\path\to\app:/app" -w /booster php:8.4-cli php bin/booster plan --path=/app
```

The `run` command's test gate starts Docker itself, so it needs a Docker daemon on the machine that runs BOOSTER.
When BOOSTER itself runs in a container, use `--skip-gate` and run the tests yourself.

## Single-file download

BOOSTER can also be built as a single-file PHAR, so PREFLIGHT and apps without Composer access can run it as one
file. Build it with the script for your OS (both need Docker, and no PHP or Composer on the host):

Windows (PowerShell):

```
.\scripts\build-phar.ps1
```

Linux / macOS:

```
scripts/build-phar.sh
```

Each script writes `build/booster.phar` and verifies it by printing BOOSTER's version. The phar is not published
yet — there is no release step — so build it from a checkout rather than downloading it.

Every command works from the phar. `detect`, `plan`, `hops` and `init` read only what is bundled inside it. `run`
copies Rector, its `config/rector.php` and `docker/` out of the phar into a temp directory first, because Rector and
`docker build` are child processes that cannot be pointed at a `phar://` path; the temp directory is removed when the
command exits.

## Quick start

```
booster detect --path=../my-app        # which of the five permutations, and which versions
booster plan   --path=../my-app        # the ordered hops to a supported version
booster run    --path=../my-app --dry-run   # the diffs Rector would make; changes nothing
booster run    --path=../my-app        # apply the plan, one hop at a time, with a test gate
```

The one-command form for a forced image upgrade:

```
booster run --path=../my-app --goal=php-74-to-84
```

A plan for a Laravel 10 app on PHP 8.2 looks like this (the order keeps the app on a PHP and Laravel pair Laravel
supports at every step):

```
1. laravel-10-to-11  [target-image-tests]
2. laravel-11-to-12  [target-image-tests]
3. php-82-to-83      [target-image-tests]
4. laravel-12-to-13  [target-image-tests]
5. php-83-to-84      [target-image-tests]
6. php-84-to-85      [target-image-tests]
```

## Commands

| Command | What it does |
|---|---|
| `booster detect` | Reads `composer.json` and `package.json`; prints the app type, PHP, Laravel and Vue versions |
| `booster plan` | Prints the ordered hops and the warnings. Changes nothing. |
| `booster run` | Runs the plan. `--dry-run` previews every hop |
| `booster hops` | Lists every hop BOOSTER knows |
| `booster init` | Writes the coding-tab playbooks to `.agents/skills`; `--check` exits 1 when they are stale |

Options shared by `plan` and `run`:

| Option | Meaning |
|---|---|
| `--path` | The app (default `.`) |
| `--format` | `text` (default) or `json` |
| `--policy` | CREED's `policy.json`. Default: `BOOSTER_POLICY`, then the bundled snapshot |
| `--today` | Override today's date (`YYYY-MM-DD`) so runs are repeatable |
| `--goal` | Shorthand such as `php-74-to-84` (sets `--from-php` and `--to-php`) |
| `--from-php`, `--from-laravel` | Say what the app runs on when the manifest does not |
| `--to-php`, `--to-laravel` | The target. Default: the newest version the policy supports today |
| `--sesame` | Include the `wayne-cas` to SESAME swap |
| `--skip` | A hop id to leave out (repeat it) |

Options for `run`:

| Option | Meaning |
|---|---|
| `--dry-run` | Show the diffs; change nothing; skip git, the baseline and the gate |
| `--max-changes` | Stop a hop that would change more than this many files (default 50) |
| `--skip-gate` | Do not run the tests in the target image (skips the baseline too) |
| `--allow-failing-baseline` | Continue when the baseline fails; later gate failures are labelled as pre-existing |
| `--allow-dirty` | Run even when the working copy has uncommitted changes |
| `--test-command` | What runs the app's tests in the image (default `composer test`, else `vendor/bin/phpunit`) |
| `--proctor-command` | An extra command run after the tests, for example a PROCTOR check |

Exit codes: `0` pass, `1` the baseline failed or a hop was capped, errored or failed its gate, `2` bad usage (bad
option, unreadable policy, broken `composer.json`, a target the policy does not support).

### The baseline

Before the first hop, `run` runs the app's own tests on the PHP the app is on now, in the same `booster-target:<php>`
image the gate uses. The point is to tell "BOOSTER broke it" from "it was already broken": a red baseline means the
suite was red before any change, so a later red gate is not proof of a bad hop. The baseline is recorded in the report
(`passed`, `failed` or `skipped`, with the exit code and the last 20 lines of output).

- A red baseline stops the run before the first hop, with a non-zero exit. `--allow-failing-baseline` lets it go on;
  each later gate failure is then labelled "the baseline was already failing" in the text and JSON output.
- `--skip-gate` and `--dry-run` skip the baseline (reported as `skipped`), so a dry run never starts Docker.

### What a hop does

1. Rector runs as a dry run. If it reports errors, or wants to change more files than `--max-changes`, the run stops
   before anything is written.
2. Rector runs for real, with only that hop's rules.
3. `composer.json` is edited: the `php` or `laravel/framework` constraint is raised, and `config.platform.php` moves
   with it. BOOSTER never runs `composer update`.
4. The gate runs the app's tests inside `booster-target:<php>`, a container built from `docker/Dockerfile` on the PHP
   the hop moves to. A red gate stops the run and leaves the changes in place so you can read them
   (`git checkout .` undoes them).

`run` refuses to start on a working copy that is not under git or has uncommitted changes, so the upgrade diff stands
alone. `--dry-run` has no such limit.

Some hops are playbooks only (`vue-2-to-3`): Rector does not read Vue, so BOOSTER lists the steps and the official
guide, and a person does the work.

### Hops BOOSTER knows

PHP one minor at a time from 5.6 to 8.5; Laravel one major at a time from 8 to 13; `cas-to-sesame` (a stub recipe that
renames the `wayne-cas` classes to SESAME's compatibility classes and swaps the Composer requirement); and
`vue-2-to-3` (playbook). `booster hops` prints them all.

BOOSTER plans only what the policy supports as a target. Moving to PHP 8.1 is refused because 8.1 is past security
support; passing through it on the way to a newer version is fine.

## Configuration

| Variable | Meaning | Default |
|---|---|---|
| `BOOSTER_POLICY` | Path to CREED's `policy.json` | the snapshot in `resources/policy.json` |

`.env.example` holds the placeholder. BOOSTER reads no secrets, makes no network calls of its own and talks to no
Citadel host. Everything it prints from an app (file names, diffs) has control characters stripped.

When Rector runs, BOOSTER passes it a few `BOOSTER_*` variables of its own (the app path, the sets, the PHP target).
They are internal; do not set them.

## Laravel hops and `vendor/`

rector-laravel needs Laravel's classes to recognise a model or a controller. For a Laravel hop BOOSTER loads the app's
own `vendor/autoload.php` when it exists, so run `composer install` in the app first. PHP hops never load the app's
autoloader. An app with no `vendor/` still gets a plan and the hops that do not need Laravel's classes.

## Checks

```
composer ci
```

runs `composer validate --strict`, PHPUnit, Pint, PHPStan (Pinstripe's raw preset, inlined until `waynestate/pinstripe`
is on Packagist) and `composer audit`. Nothing needs PHP on the host:

```
docker run --rm -v "${PWD}:/app" -w /app composer:2.10.3 sh -c "composer install --no-interaction && composer ci"
```

`contract/expectations.json` says what BOOSTER must detect, plan and change for each example app in `examples/`;
`tests/ContractTest.php` enforces it with the real Rector, so a Rector release that changes a hop shows up in CI.

## Status

Version 0.1. See [OPEN_QUESTIONS.md](OPEN_QUESTIONS.md) for what a person must answer and what is not built yet, and
[BEST_PRACTICES.md](BEST_PRACTICES.md) for the rules BOOSTER follows.

## Licence

MIT, Wayne State University, Enterprise Applications.
