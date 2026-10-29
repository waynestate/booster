# MAINTAINING: refuelling the rocket

How to build, test, release and keep BOOSTER current. BOOSTER plans and runs upgrade hops (PHP and Laravel versions,
PHPUnit, Node pins, Mix to Vite, old CAS clients to SESAME) on top of Rector, using CREED's policy to know where an app
must land. Rockets need fuel, and this one runs on fresh version data and working recipes.

## What BOOSTER is and what it depends on

- `waynestate/booster` on Packagist, with the CLI `bin/booster`. An optional single-file phar (`build/booster.phar`)
  is built locally and not published yet.
- Runtime dependencies: `rector/rector`, `driftingly/rector-laravel`, `symfony/console`, `symfony/process`,
  `composer/semver`. No other stack package at runtime. Box (for the phar) lives in `tools/box/`, outside the main
  lock file.
- **Its policy comes from CREED.** `src/Creed/` is a generated copy of CREED's reader and `policy.json`. Never
  hand-edit it. `BOOSTER_POLICY` points BOOSTER at another `policy.json` at runtime.
- Some hops run tools in Docker (for example Node in `node:<lts>`), so the upgrade gate needs a Docker daemon.

## Where it lives

Development happens on Wayne State's GitLab. This GitHub repository is a read-only release mirror: `main` and tags are
copied here automatically, and changes, issues and merge requests go to GitLab first. Nobody pushes to GitHub
directly.

## Build and test

| Task | Windows (PowerShell) | Linux and macOS |
|---|---|---|
| Install | `composer install` | `composer install` |
| All checks (what CI runs) | `composer ci` | `composer ci` |
| Build the phar (Docker only) | `.\scripts\build-phar.ps1` | `scripts/build-phar.sh` |
| Check the CREED copy | `$env:CREED_PATH = "..\creed"; composer creed-copy:check` | `CREED_PATH=../creed composer creed-copy:check` |

With only Docker:

```
docker run --rm -v "${PWD}:/app" -w /app composer:2.10.3 sh -c "composer install --no-interaction && composer ci"
```

`composer ci` runs `composer validate --strict`, PHPUnit, Pint, PHPStan and `composer audit`. It does not run the
CREED copy check, which needs a CREED checkout. The phar scripts install Box in `tools/box`, build from a clean copy
with `--no-dev`, and finish with `php build/booster.phar --version`.

## CI

- **GitLab:** PREFLIGHT's `php-test` (PHP 8.3, 8.4, 8.5) and `composer-audit` components, plus a `composer-ci` job.
- **GitHub:** `.github/workflows/ci.yml` calls PREFLIGHT's reusable workflow with `composer ci`.
- Green means `composer ci` passed on all three PHP versions on both.

## Refreshing the CREED copy

Whenever CREED's policy or reader changes:

```
php ../creed/bin/export.php --namespace='Waynestate\Booster\Creed' --to=src/Creed
```

Then the copy check and `composer ci`, and commit with the CREED commit in the message. When a new Laravel or PHP
line appears in CREED, check whether BOOSTER needs a new hop for it.

## Adding a hop

- Each hop has a test with a before and after fixture, and refuses inputs it cannot handle safely (for example a Mix
  file that is not simple).
- A hop never runs on a dirty working tree, and a red baseline stops `run`.
- Where a change cannot be automated safely, ship a playbook instead of a hop.

## Releasing

1. Move the lines under `## [Unreleased]` in `CHANGELOG.md` (Keep a Changelog) to a new `## [X.Y.Z] - YYYY-MM-DD`
   heading.
2. `composer ci` and the CREED copy check green locally, then both pipelines green for that commit.
3. Run a secrets scan of the history and check that nothing internal (server names, people, app names, IDs) is in the
   tree.
4. Tag `X.Y.Z` (no `v`) on GitLab. The mirror carries the tag to GitHub, and Packagist picks it up from there.
5. A release needs a maintainer's approval. Never move or delete a published tag.

## The front page

`site/` is the static front page, for `https://booster.apps.wayne.edu/` on Citadel, served through a small raw-PHP
`deploy/` layer generated with SQUIRE.

## Who maintains it

Adam Perkins, Wayne State University Enterprise Applications. Internal runbook (accounts, tokens, mirrors): ask
Enterprise Applications.
