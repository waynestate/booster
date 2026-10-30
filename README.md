<div align="center">
  <a href="site/index.html"><img src="docs/readme/hero.jpg" width="100%" alt="Booster's front page: the headline 'Boosts your versions.' beside a launch pad where the syringe-rocket mark sits above a PHP altimeter reading 8.5, stage 6 of 6, gate: target-image tests."></a>
</div>

# Booster

<p>
  <img src="docs/readme/badges/php.svg" alt="php ^8.3">
  <img src="docs/readme/badges/license.svg" alt="licence MIT">
  <img src="docs/readme/badges/threshold.svg" alt="review threshold: 200 files">
  <a href="https://github.com/waynestate/booster/actions/workflows/ci.yml"><img src="https://github.com/waynestate/booster/actions/workflows/ci.yml/badge.svg" alt="GitHub CI"></a>
</p>

**Boosts your versions.** One dose at a time, and every dose gets a stamp.

**Not to be confused with:** Booster moves an app to a newer PHP or framework version; Pinstripe's Rector set only modernises code within the PHP version the app already runs.

Checks: `composer ci` (the same command runs on GitLab and GitHub).

Development happens on Wayne State's GitLab; this repository is a release mirror. Changes land there first.

> A booster shot keeps your immunity current. This one keeps your PHP current, and it stings less.

Front page: [`site/index.html`](site/index.html), a static page you can open in a browser or serve from `site/`.
On Citadel it is served by the raw-PHP deploy layer in [`deploy/`](deploy/): a Squire kit whose front controller serves `site/` behind HELMET's security headers. For local development, keep serving `site/` directly.

Booster is WELD's upgrade tool. It plans the hops that take a WSU PHP app from the PHP or Laravel version it runs now
to one the version policy still supports, and it runs them one at a time: [Rector](https://getrector.com) rewrites the
code, Booster raises the constraints in `composer.json`, and the app's own tests must pass on the target PHP before the
next hop starts. It stands on `rector/rector` and `driftingly/rector-laravel` and adds only the ordering, the review
threshold, the gate, the advisory scans and a few WSU recipes.

Booster edits your working copy and prints a report. It never commits to, pushes or opens a merge request on your app (the only merge requests it opens are its own hop-data refresh on Booster itself): a person reads
the diff and merges it.

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

## Install

Booster needs PHP 8.3 or newer, but the app it upgrades does not: Rector runs from Booster's own `vendor/`, so an app
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

The `run` command's test gate starts Docker itself, so it needs a Docker daemon on the machine that runs Booster.
When Booster itself runs in a container, use `--skip-gate` and run the tests yourself. Node hops run `npm` in a
`node:26` container, so they need the same daemon but no Node installed on the machine.

## Single-file download

Booster also ships as a single-file PHAR, so Preflight and apps without Composer access can run it as one file. Every
version tag publishes it: the GitLab release carries `booster.phar` as an asset, and the GitHub release at
github.com/waynestate/booster carries the same file as an attachment. Download it from either release page and run it
with `php booster.phar`.

You can also build it from any commit, with the script for your OS (both need Docker, and no PHP or Composer on the
host):

Windows (PowerShell):

```
.\scripts\build-phar.ps1
```

Linux / macOS:

```
scripts/build-phar.sh
```

Each script writes `build/booster.phar` and verifies it by printing Booster's version.

Every command works from the phar. `detect`, `plan`, `hops` and `init` read only what is bundled inside it. `run`
copies Rector, its `config/rector.php` and `docker/` out of the phar into a temp directory first, because Rector and
`docker build` are child processes that cannot be pointed at a `phar://` path; the temp directory is removed when the
command exits.

## Quick start

```
booster detect --path=../my-app        # which of the five permutations, and which versions
booster plan   --path=../my-app        # the ordered hops to a supported version
booster scan   --path=../my-app --php=8.0   # what will break on that PHP; advisory, changes nothing
booster run    --path=../my-app --dry-run   # the diffs Rector would make; changes nothing
booster run    --path=../my-app        # apply the plan, one hop at a time, with a test gate
```

The one-command form for a forced image upgrade:

```
booster run --path=../my-app --goal=php-74-to-84
```

When the app carries a `composer.lock`, `detect` also reads the PHP range the locked packages allow: the highest
`require.php` floor and the lowest ceiling across `packages` and `packages-dev`, raised by a `platform-overrides.php`
or `config.platform.php` pin in `composer.json` when one is set. If that floor sits above the floor `composer.json`
claims, Booster plans from the lock's floor and prints a warning saying so; if the first PHP hop lands at or above
the lock's ceiling, a second warning notes that hop will need a `composer update` to keep the lock installable.
Without a lock, `composer.json` stays the sole source.

A plan for the bundled Laravel 10 example app on PHP 8.2 looks like this (the order keeps the app on a PHP and
Laravel pair Laravel supports at every step):

```
$ booster plan --path=examples/laravel-blade --today=2026-10-29

BOOSTER plan for examples/laravel-blade
  app type: laravel-blade
  PHP: 8.2 -> 8.5
  Laravel: 10 -> 13
  PHPUnit: 10 -> 13
  policy: waynestate/creed 0.1.3 (today 2026-10-29)

1. phpunit-10-to-11  [target-image-tests]
   Move PHPUnit 10 tests to PHPUnit 11.
2. laravel-10-to-11  [target-image-tests]
   Move a Laravel 10 app to Laravel 11.
3. laravel-11-to-12  [target-image-tests]
   Move a Laravel 11 app to Laravel 12.
4. php-82-to-83  [target-image-tests]
   Move PHP 8.2 code to PHP 8.3.
   Removed between PHP 8.2 and PHP 8.3: none found.
5. phpunit-11-to-12  [target-image-tests]
   Move PHPUnit 11 tests to PHPUnit 12.
6. laravel-12-to-13  [target-image-tests]
   Move a Laravel 12 app to Laravel 13.
7. php-83-to-84  [target-image-tests]
   Move PHP 8.3 code to PHP 8.4.
   Removed between PHP 8.3 and PHP 8.4: none found.
8. phpunit-12-to-13  [target-image-tests]
   Move PHPUnit 12 tests to PHPUnit 13.
9. php-84-to-85  [target-image-tests]
   Move PHP 8.4 code to PHP 8.5.
   Removed between PHP 8.4 and PHP 8.5: none found.

Will break on PHP 8.3: no findings.

PHPStan on PHP 8.5: 5 findings.
   app/Models/Post.php:7 [class.notFound] Class App\Models\Post extends unknown class Illuminate\Database\Eloquent\Model.
   app/Models/Post.php:9 [missingType.property] Property App\Models\Post::$casts has no type specified.
   tests/PostTest.php:18 [property.onlyWritten] Property App\Tests\PostTest::$repository is never read, only written.
   tests/PostTest.php:27 [staticMethod.deprecated] Call to deprecated method isType() of class PHPUnit\Framework\Assert:
https://github.com/sebastianbergmann/phpunit/issues/6052
   tests/PostTest.php:33 [method.alreadyNarrowedType] Call to method PHPUnit\Framework\Assert::assertNotNull() with PHPUnit\Framework\MockObject\Rule\AnyInvokedCount will always evaluate to true.
```

<div align="center">
  <img src="docs/readme/record.svg" width="100%" alt="Booster's immunization record for the Laravel 10 example app: after a short countdown, a Gate passed stamp lands on each Laravel and PHP hop in turn, laravel-10-to-11, laravel-11-to-12, php-82-to-83, laravel-12-to-13, php-83-to-84 and php-84-to-85, ending in RESULT: PASS.">
  <br><sub>The plan's Laravel and PHP hops, written up the way your doctor would: one stamp per hop, one hop at a time.</sub>
</div>

<details>
<summary><b>Chart review</b>: the record card and the PHP by Laravel grid, from the front page</summary>
<br>
<div align="center">
  <img src="docs/readme/card.jpg" width="100%" alt="The front page's Form B-01 record card for examples/laravel-blade, six hops from laravel-10-to-11 to php-84-to-85, each with its pair, gate image and a Gate passed stamp, ending in RESULT: PASS.">
  <br><br>
  <img src="docs/readme/pairs.jpg" width="100%" alt="The overlap grid of Laravel 10 to 13 against PHP 8.1 to 8.5. Unsupported pairs are hatched, and a numbered path 0 to 6 climbs from Laravel 10 on PHP 8.2 to Laravel 13 on PHP 8.5.">
</div>
</details>

## Commands

| Command | What it does |
|---|---|
| `booster detect` | Reads `composer.json` and `package.json`; prints the app type, PHP, Laravel, PHPUnit, Vue, Node and Boost |
| `booster plan` | Prints the ordered hops, the warnings (including which direct dependencies block which hops), a removals line per PHP hop, an advisory PHPCompatibility scan of the first PHP hop's target and a PHPStan scan at the destination PHP. Changes nothing. |
| `booster scan` | Advisory scans of one PHP version: PHPCompatibility by default, `--removals` for removed functions, `--phpstan` for static analysis. Exits 0 even with findings |
| `booster run` | Runs the plan. `--dry-run` previews every hop |
| `booster hops` | Lists every hop Booster knows |
| `booster init` | Writes the coding-tab playbooks to `.agents/skills`; `--check` exits 1 when they are stale |

Options shared by `plan` and `run`:

| Option | Meaning |
|---|---|
| `--path` | The app (default `.`) |
| `--format` | `text` (default) or `json` |
| `--policy` | CREED's `policy.json`. Default: `BOOSTER_POLICY`, then the copy in the `waynestate/creed` package |
| `--today` | Override today's date (`YYYY-MM-DD`) so runs are repeatable |
| `--goal` | Shorthand such as `php-74-to-84` (sets `--from-php` and `--to-php`) |
| `--from-php`, `--from-laravel` | Say what the app runs on when the manifest does not |
| `--to-php`, `--to-laravel`, `--to-phpunit` | The target. Default: the newest version the policy supports today (PHPUnit: the newest that runs on the target PHP) |
| `--sesame` | Include the `wayne-cas` to SESAME swap |
| `--skip` | A hop id to leave out (repeat it) |
| `--no-package-check` | Skip the Packagist dependency check (for offline use) |
| `--until` | Cut the plan after this hop id: that step and everything before it stay, and the plan's targets move to where it lands |
| `--only` | Plan and run just this one hop id. The app must sit on the hop's starting versions, and `--until` and `--only` are never combined |
| `--with-node` | include the Node front-end hops (Node pin, Mix to Vite, Tailwind, Vue) even when you passed a target version. Without it, any of `--goal`, `--to-php`, `--to-laravel`, `--to-phpunit`, `--from-php` or `--from-laravel` leaves those hops out, and the plan prints: "Node front-end hops were left out because you passed a target version: <hop ids>. Add --with-node to include them." |

Example: an app moving from Laravel 8 to 13 takes the climb in parts. `booster plan --path=../my-app --until=laravel-8-to-9` keeps every hop up to and including the move to Laravel 9, and `booster run` with the same flag applies exactly those hops.

Options for `plan`:

| Option | Meaning |
|---|---|
| `--no-scan` | Skip the advisory scans (PHPCompatibility and PHPStan) |

Options for `scan`:

| Option | Meaning |
|---|---|
| `--path` | The app (default `.`) |
| `--php` | Required. The PHP version to ask about (`major.minor`, one Booster knows) |
| `--removals` | List uses of functions and constructs removed in PHP up to `--php`, instead of the PHPCompatibility scan |
| `--phpstan` | Also run the PHPStan scan (Pinstripe preset) at the same PHP version |
| `--format` | `text` (default) or `json` |

Options for `run`:

| Option | Meaning |
|---|---|
| `--dry-run` | Show the diffs; change nothing; skip git, the baseline and the gate. A Laravel hop without `vendor/` is marked incomplete |
| `--max-changes` | Hard limit on files changed per hop, with no prompt (`0` lifts the limit). Without it, a hop over the review threshold of 200 files asks first when interactive, or stops |
| `--memory-limit` | Memory limit for the Rector process (for example `2G`), or `-1` for no limit (default `2G`) |
| `--skip-gate` | Do not run the tests in the target image after each hop, or the PROCTOR check at the end (skips the baseline too) |
| `--image` | Run the gate in this Docker image instead of building `booster-target` (it needs `php` and `composer` on `PATH`): one name, a `{php}` template or a version map (below) |
| `--allow-failing-baseline` | Continue when the baseline fails; later gate failures are labelled as pre-existing |
| `--allow-dirty` | Run even when the working copy has uncommitted changes |
| `--test-command` | What runs the app's tests in the image (default `composer test`, else `vendor/bin/phpunit`) |
| `--test-env` | Environment variable (`KEY=VALUE`) passed to the baseline and every gate container with `-e`; repeat it. The report names the keys only, never the values |
| `--proctor-command` | An extra command run in the image after the tests, for example a PROCTOR check |
| `--proctor-advisory` | Report the PROCTOR result but do not fail the run on it. Applied automatically when the run stops before the final target (`--until`, `--only`, or a target past security support) |

Exit codes: `0` pass, `1` the baseline failed, a hop was capped, errored or failed its gate, or the PROCTOR check
found new findings, `2` bad usage (bad option, unreadable policy, broken `composer.json`, a target the policy does
not know).

### The PHPCompatibility scan

`plan` ends with an advisory scan: PHP_CodeSniffer runs the PHPCompatibility standard over the app and asks what will
break on the PHP version the first PHP hop lands on. The findings are hints for the person doing the upgrade, never a
gate: they do not change the exit code, and a scan that cannot run (missing tooling, a crash) is reported as "could
not run" while the plan still prints in full. `--no-scan` skips both advisory scans.

The scan is optional. Booster looks for a PHP_CodeSniffer that carries the PHPCompatibility standard, trying the app's
own `vendor/bin/phpcs`, then Booster's `vendor/bin/phpcs`, then `phpcs` on the PATH. When none of them has the
standard, the scan reports a skip and the plan goes on. To turn it on, install the packages into the app:

```
composer require --dev phpcompatibility/php-compatibility:"^10.0@alpha"
```

Before the require line, approve the installer plugin once:

```
composer config allow-plugins.dealerdirect/phpcodesniffer-composer-installer true
```

Composer asks for approval of `dealerdirect/phpcodesniffer-composer-installer`, the plugin that registers the
PHPCompatibility standard with PHP_CodeSniffer. Without the approval, Composer blocks the plugin, the standard never
registers, and the scan skips with a message that names this fix. The command writes `allow-plugins` into the app's
own `composer.json`. That is an app-side change the person who wants the scan chooses to make; Booster never edits
the app's `composer.json`.

The scan ignores `vendor/`, `node_modules/` and `storage/`, because those hold third-party code and generated files,
not the app's. It reads the app only and changes nothing. `booster scan --path=../my-app --php=8.0` runs the same scan
on its own, for any PHP version Booster knows, and exits 0 even when it finds a wall of removed functions.

### The PHPStan scan

`plan` ends with a second advisory scan: PHPStan runs over the app at the destination PHP version, using Pinstripe's
`base.neon` preset. The findings are hints for the person doing the upgrade, never a gate: they do not change the
exit code, and a scan that cannot run is reported with the fix that enables it, while the plan still prints in full.
`--no-scan` skips it.

The scan is optional. Booster looks for a PHPStan binary, trying the app's own `vendor/bin/phpstan`, then Booster's
`vendor/bin/phpstan`, then `phpstan` on the PATH, and for the preset it tries the app's vendor tree and Booster's.
When either is missing, the report names the fix:

```
composer require --dev phpstan/phpstan waynestate/pinstripe
```

It ignores `vendor/`, `node_modules/` and `storage/`, reads the app only and changes nothing. `booster scan
--path=../my-app --php=8.5 --phpstan` runs the same scan on its own, for any PHP version Booster knows, and exits 0
even when it finds a wall of missing types.

### The dependency check

Before the hops print, `plan` (and `run`) asks Packagist about each direct third-party dependency in the app's
`composer.json`: what is its newest stable release, and can that release still be installed at each hop's
destination? Only plain `require` and `require-dev` entries count; PHP, extensions (`ext-*`, `lib-*`), Laravel
itself (`laravel/framework`, its `illuminate/*` components) and PHPUnit are Booster's own business, so they are
skipped.

The answer is advisory, like the scans: a package that cannot follow the plan does not drop the hop. It adds a
warning that names the package, the hop it blocks, and what to do about it:

```
acme/legacy-auth 2.3.0 supports Laravel up to 10 and PHP up to 8.3, so it blocks laravel-10-to-11. Replace or upgrade it first.
```

The warning names only the dimensions the blocked hop fails on: a Laravel ceiling alone, a PHP ceiling alone, or both
together. A ceiling is printed as the newest version Booster plans that the constraint allows (a PHP constraint capping
at 7.x reads "7.4", not "7"), and a constraint with no upper bound has no ceiling to name, so the warning names its
floor instead (`needs PHP 8.2 or newer`).

A package whose newest release requires several `illuminate/*` components is judged by the loosest of those
constraints, the one that allows the most Laravel versions. Abandoned packages get their own warning (`acme/old is
abandoned (use acme/new instead).`). Packages the check cannot reach are reported as `Not checked: <package>
(offline).` or `(VCS repository).`, and the plan goes on. In JSON output every package lands under
`package_checks`, with the fields `package`, `newest`, `blocks`, `abandoned` and `checked`.

The check fetches from `repo.packagist.org` and from any composer-type repository the app declares in
`composer.json`; VCS repositories are never fetched. `--no-package-check` skips the whole check, for offline use.

### The baseline

Before the first hop, `run` runs the app's own tests on the PHP the app is on now, in the same `booster-target:<php>`
image the gate uses. The point is to tell "Booster broke it" from "it was already broken": a red baseline means the
suite was red before any change, so a later red gate is not proof of a bad hop. The baseline is recorded in the report
(`passed`, `failed` or `skipped`, with the exit code and the last 20 lines of output).

- A red baseline stops the run before the first hop, with a non-zero exit. `--allow-failing-baseline` lets it go on;
  each later gate failure is then labelled "the baseline was already failing" in the text and JSON output.
- `--skip-gate` and `--dry-run` skip the baseline (reported as `skipped`), so a dry run never starts Docker.
- A Mix app's `public/mix-manifest.json` is a build artifact, not committed source: a fresh clone has no manifest, so
  the suite can be red before any hop. Build the assets before the baseline (the app's usual `npm ci && npm run
  build`), or pass `--allow-failing-baseline` knowing why.

### A smoke test for WSU apps

Laravel's stock tests do not fit a WSU app. `tests/Feature/ExampleTest.php` asks for `/` and expects 200, but on a
real app the front page sits behind CAS, so an unauthenticated request is a redirect to the sign-in URL, never a
200. The stock suite also assumes an `APP_URL` that points somewhere real and an `APP_KEY` that exists; a fresh
checkout has neither, so the suite is red before any change, and even a green run of it proves nothing about the
upgrade. When Booster sees only the two stock example tests, it says so next to the baseline:

    The app's tests are only Laravel's stock examples, so the gate cannot prove anything. See "A smoke test for WSU apps" in the README.

Write one small test that proves the app boots and its front door still works. It needs a few environment values in
`phpunit.xml`, because the test environment starts empty:

```xml
<php>
    <env name="APP_ENV" value="testing"/>
    <env name="APP_KEY" value="base64:c21va2UtdGVzdC1rZXkwMDAwMDAwMDAwMDAwMDAwMDA="/>
    <env name="APP_URL" value="http://localhost"/>
</php>
```

`APP_KEY` can be any throwaway 32-byte key (`php artisan key:generate --show`); it never leaves the test
environment. If the app touches the database while booting, point the tests at an in-memory SQLite database:

```xml
    <env name="DB_CONNECTION" value="sqlite"/>
    <env name="DB_DATABASE" value=":memory:"/>
```

And the test itself, in `tests/Feature/SmokeTest.php`:

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;

final class SmokeTest extends TestCase
{
    public function test_a_guest_is_sent_to_the_cas_login(): void
    {
        $response = $this->get('/');

        $response->assertRedirect();
        $this->assertStringContainsString('/login', (string) $response->headers->get('Location'));
    }
}
```

That is the whole point: the app boots, encryption and sessions work, and the front door still sends guests to CAS.
It passes on the old PHP and must still pass on the new one, which is what the gate is for.

Those values live in `phpunit.xml`, committed with the app. When you would rather not commit them, pass them on the
command line instead; Booster forwards each one to the baseline and every gate container with `-e`, and the report
names the keys only, never the values:

    booster run --path=<app> --test-env DB_CONNECTION=sqlite --test-env DB_DATABASE=:memory:

### What a hop does

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
unblock`; older runs wrote `BOOSTER temporary unblock`, which it still recognises). A step that is not the plan's
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

Three hops edit manifests instead of code. `node-pin-24` sets `engines.node` in `package.json` and writes `.nvmrc`,
then the app's usual gate applies. `mix-to-vite` converts a simple `webpack.mix.js` to Vite (below) and asks for a
build gate, which runs `npm ci` and `npm run build` in the `node:26` container before the app's tests.
`tailwind-3-to-4` runs the official `@tailwindcss/upgrade` tool in the Node container, then pins `tailwindcss` to
`^4.1`, adds the matching Vite or PostCSS plugin, and removes `autoprefixer` and `postcss-import`, which Tailwind 4
folds in; plugins listed in `tailwind.config.js` and `@apply` inside Vue SFC `<style>` blocks stay manual work, and
the hop says so.

Vue 2 to Vue 3 is staged. `vue-26-to-27` moves the front end to the last Vue 2 release, which carries the Vue 3
reactivity API; `vue-27-to-compat` switches to the `@vue/compat` build; `vue-compat-codemod` runs the
`vue-upgrade-tool` codemod and keeps every manual item it lists. `vue-2-to-3` is the playbook that stands in for all
three: skipping it skips them, and a person finishes the job, because Rector does not read Vue.

### Hops Booster knows

Thirty-three hops: PHP one minor at a time from 5.5 to 8.5, Laravel one major at a time from 5.8 to 13 and PHPUnit one
major at a time from 9 to 13, each list read from `data/versions.json` and trimmed to the majors whose Rector ruleset
is installed ([Hop data](#hop-data)). An app whose PHPUnit is below 9 gets `phpunit-to-9-manual`, a manual move to
`^9.5` placed before the first hop that targets PHP 8.0 (PHPUnit 7 and 8 do not run on PHP 8), and the regular
PHPUnit hops then take over from 9. Then the recipes: `cas-to-sesame` (renames the `wayne-cas` classes to SESAME's
compatibility classes and swaps the Composer requirement; planned with `--sesame`, and left out with a warning when
the plan ends below PHP 8.3, which SESAME needs; it lands just before the hop from Laravel 10 to 11, and while PHP is
still below 8.3 the PHP hops go first); `node-pin-24` (planned for any app with a `package.json` that does
not already floor Node at the hop data's target, 26 today: sets `engines.node` to `>=26` and writes `.nvmrc`, ahead
of the front-end hops); `mix-to-vite` (planned when the app has a `webpack.mix.js` and the plan lands on Laravel 9 or
newer, after `node-pin-24`: writes `vite.config.js`, swaps `laravel-mix` for `vite` and `laravel-vite-plugin` in
`package.json`, renames `MIX_` keys to `VITE_` in `.env.example`, rewrites Blade `mix()` script and link tags into one
`@vite([...])` line per layout when every referenced asset maps to a Mix entry, and deletes `webpack.mix.js`; the
simple shape only: `mix.js`, `.vue()`, `.sass()`, `.postCss()`, `.version()` and `if (mix.inProduction()) {
mix.version(); }` (dropped with a note, since Vite hashes production assets), anything else is refused by name, a
Vue 2 front end must take `vue-2-to-3` first, and `withoutMix()` calls are listed for a manual rename to
`withoutVite()`); `tailwind-3-to-4` (planned when `package.json` carries `tailwindcss` below 4, after `node-pin-24`
and `mix-to-vite`: runs the official `@tailwindcss/upgrade` tool, pins `tailwindcss` to `^4.1`, adds the matching
Vite or PostCSS plugin and removes `autoprefixer` and `postcss-import`); and the Vue 2 to Vue 3 stage,
`vue-26-to-27`, `vue-27-to-compat` and `vue-compat-codemod`, with the `vue-2-to-3` playbook standing in for all three.
`booster hops` prints them all.

Booster plans any target the policy knows. A target past security support is allowed as a stop on the way up, with a
plan warning that names the date its security support ended. A version the policy does not list at all is still
refused by name.

## Configuration

| Variable | Meaning | Default |
|---|---|---|
| `BOOSTER_POLICY` | Path to CREED's `policy.json` | the copy in the `waynestate/creed` package |

Booster reads the policy straight from the `waynestate/creed` package in its own `vendor/`, so there is no copy to
drift: `composer update waynestate/creed` is the whole refresh. `--policy` and `BOOSTER_POLICY` still override it for
one run.

`.env.example` holds the placeholder. Booster reads no secrets, makes no network calls of its own and talks to no
Citadel host. Everything it prints from an app (file names, diffs) has control characters stripped.

When Rector runs, Booster passes it a few `BOOSTER_*` variables of its own (the app path, the sets, the PHP target).
They are internal; do not set them.

## Laravel hops and `vendor/`

rector-laravel needs Laravel's classes to recognise a model or a controller. For a Laravel hop Booster loads the app's
own `vendor/autoload.php` when it exists, so run `composer install` in the app first. PHP hops never load the app's
autoloader. An app with no `vendor/` still gets a plan and the hops that do not need Laravel's classes.

For an Inertia/Vue app, each Laravel hop also raises the Inertia constraints the app already carries:
`inertiajs/inertia-laravel` and `@inertiajs/vue3` move to the constraint the hop data pairs with the target major
(`^2.0` for Laravel 11 and newer). Booster never adds a package the app does not have, and below Laravel 11 it pins
nothing and says so, because the hop data carries no constraint there.

## Hop data

`data/versions.json` holds the version facts Booster plans against: the PHP, Laravel and PHPUnit chains, the PHP range
each Laravel major supports, the PHPUnit floor each Laravel major ships, the Node floor the `node-pin-24` hop writes,
and the fixed Tailwind pin and Inertia constraint tables the front-end hops use. The file ships inside the PHAR. A new
major appears in the file as soon as its feed says so; Booster offers a hop for it only once Rector ships a ruleset
for it, and a plan toward a major without one stops there with a warning instead of failing.

`bin/refresh-hop-data.php` rewrites the file from the public feeds (Packagist for PHPUnit and Laravel, endoflife.date
for Laravel's supported PHP ranges, npm for the Vite packages) plus CREED's policy for what counts as supported:

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
a merge request on Booster itself with the new file, and a person merges it. [MAINTAINING.md](MAINTAINING.md) covers
the token the robot needs.

## Deploying on Citadel

When a real (not dry) run changes `composer.lock` and the app has a `deployments/` directory, Booster writes one
deployment script, `deployments/0000_composer_install_<YYYYMMDDHHMM>`:

```sh
#!/bin/sh
# Added by Booster: this upgrade changed composer.lock. Citadel runs each deployment script once, in name order,
# so a new name makes existing deployments install the new vendor/ before anything else runs.
set -e
composer install --no-dev --no-interaction --optimize-autoloader
php artisan migrate --force
```

Citadel runs every `deployments/` script exactly once, in name order, and the leading `0000` puts this one first. So
when the app next deploys, every existing deployment installs the upgraded `vendor/` before any other script runs. The
`php artisan migrate --force` line appears only for apps that have an `artisan` file; raw PHP apps get the `composer
install` alone. Commit the script with the rest of the upgrade. To drop it again, delete the named file.

## Checks

Maintainers: how to build, test and release this project, and where its data comes from, is in [MAINTAINING.md](MAINTAINING.md).

```
composer ci
```

runs `composer validate --strict`, PHPUnit, Pint, PHPStan (Pinstripe's raw preset, inlined; `waynestate/pinstripe` is
a dev requirement and supplies the preset the advisory scan uses), `composer audit`, the hop data check and the
agent-ready check. Nothing needs PHP on the host:

```
docker run --rm -v "${PWD}:/app" -w /app composer:2.10.3 sh -c "composer install --no-interaction && composer ci"
```

`contract/expectations.json` says what Booster must detect, plan and change for each example app in `examples/`;
`tests/ContractTest.php` enforces it with the real Rector, so a Rector release that changes a hop shows up in CI.

## Proof

Proof runs against real Wayne State applications are on a private site, [proof.apps.wayne.edu/booster](https://proof.apps.wayne.edu/booster/), behind a Wayne State employee sign-in.

Proven how: the first full run with the gate on took a Laravel 12 app from PHP 8.2 to 8.4 in two hops, each gated by
the app's 197-test suite, and it is written up in [research/booster-e2e-gate.md](research/booster-e2e-gate.md).

## Status

Version 1.0. See [CHANGELOG.md](CHANGELOG.md) for what changed, [OPEN_QUESTIONS.md](OPEN_QUESTIONS.md) for what stays
open, and [BEST_PRACTICES.md](BEST_PRACTICES.md) for the rules Booster follows.

## Licence

MIT, Wayne State University, Enterprise Applications.

<details>
<summary><b>The brand board</b>: the syringe that is also a rocket, lockups, palette and type</summary>
<br>
<div align="center"><img src="docs/readme/board.jpg" width="100%" alt="Booster's brand board: the syringe-rocket mark on a green tile, lockups, the palette of Wayne State green, gold and cream, Barlow Condensed type, and favicon sizes."></div>
</details>

<div align="center"><b>The whole course</b><br><sub>WayneUI and the stack. Each one is on its own schedule; this one keeps the schedule.</sub></div>

<div align="center">
  <a href="https://github.com/waynestate/wayne-ui" title="WayneUI"><img src="docs/readme/cast/wayneui.png" width="40" alt="WayneUI"></a>
  <a href="https://github.com/waynestate/creed" title="CREED"><img src="docs/readme/cast/creed.png" width="40" alt="CREED"></a>
  <a href="https://github.com/waynestate/proctor" title="PROCTOR"><img src="docs/readme/cast/proctor.png" width="40" alt="PROCTOR"></a>
  <a href="https://github.com/waynestate/sesame" title="SESAME"><img src="docs/readme/cast/sesame.png" width="40" alt="SESAME"></a>
  <a href="https://github.com/waynestate/helmet" title="HELMET"><img src="docs/readme/cast/helmet.png" width="40" alt="HELMET"></a>
  <a href="https://github.com/waynestate/sourdough" title="Sourdough"><img src="docs/readme/cast/sourdough.png" width="40" alt="Sourdough"></a>
  <a href="https://github.com/waynestate/squire" title="Squire"><img src="docs/readme/cast/squire.png" width="40" alt="Squire"></a>
  <a href="https://github.com/waynestate/preflight" title="Preflight"><img src="docs/readme/cast/preflight.png" width="40" alt="Preflight"></a>
  <a href="https://github.com/waynestate/pinstripe" title="Pinstripe"><img src="docs/readme/cast/pinstripe.png" width="40" alt="Pinstripe"></a>
  <a href="https://github.com/waynestate/booster" title="Booster"><img src="docs/readme/cast/booster.png" width="40" alt="Booster"></a>
  <a href="https://github.com/waynestate/rollcall" title="Rollcall"><img src="docs/readme/cast/rollcall.png" width="40" alt="Rollcall"></a>
  <a href="https://github.com/waynestate/drawbridge" title="Drawbridge"><img src="docs/readme/cast/drawbridge.png" width="40" alt="Drawbridge"></a>
  <a href="https://github.com/waynestate/advisor" title="ADVISOR"><img src="docs/readme/cast/advisor.png" width="40" alt="ADVISOR"></a>
  <a href="https://github.com/waynestate/weld" title="WELD"><img src="docs/readme/cast/weld.png" width="40" alt="WELD"></a>
</div>

<div align="center"><sub>Images are regenerated from the front page by <a href="scripts/readme-art/README.md"><code>scripts/readme-art</code></a>. Please hold still; this will only sting a little.</sub></div>
