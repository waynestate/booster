# Booster end-to-end gate run

Date: 2026-10-30. Machine: a Windows developer box with Docker Desktop (WSL2 backend).
Branch: `dl/bst-e2e-gate`. Mode: unattended delegated worker.

## Verdict

Boosters first full end-to-end run with the gate ON passed. A public scratch
app (Laravel 12, PHP 8.2, PHPUnit 11, 197 tests) moved from PHP 8.2 to PHP 8.4
in two hops, and each hop proved its target by running the app's own test suite
inside a `booster-target:<php>` container. Before that, the same pipeline
stopped itself correctly when the fresh-clone baseline was red, and completed
green once the missing build artifact was supplied. Nothing in the run required
a workaround in Booster code; every stop-and-go behaviour matched the design.

## What the run proves

- `booster detect` reads a real app and classifies it (`laravel-blade`).
- `booster plan` produces a complete, ordered plan from the bundled CREED
  policy (waynestate/creed 0.1.3, dated 2026-10-30).
- `booster run --dry-run` previews diffs without touching the tree.
- `booster run` enforces a green baseline before the first hop, applies each
  hop on the working copy, and gates every hop on the app's real test suite in
  the target PHP image.
- A red baseline stops the whole run (exit 1, every step `NOT_RUN`) instead of
  upgrading on top of broken tests.
- PROCTOR ran a baseline and a final pass; pre-existing findings were labelled
  and did not block.
- The working copy ends with exactly the changes the hops claimed: six files,
  eight lines, and `"php": "^8.4"` in composer.json.

## Infrastructure

All of it is disposable and rebuilt with `docker build`:

| Name | Role |
| --- | --- |
| `booster-e2e-helper:latest` | Outer runner: PHP 8.5.12, Composer 2.10.3, docker CLI, git. Runs `bin/booster`. |
| `booster-target:8.2`, `booster-target:8.4` (and `:8.3`) | Gate images, built from `docker/Dockerfile` plus `pdo_sqlite` and `sqlite3` extensions. |
| volume `booster-vendor-bst-e2e-gate` | Boosters own vendor tree, mounted at `/booster/vendor` so the helper image needs no network. |
| volume `booster-e2e-scratch` | Archive copy of the scratch app. |
| VM path `/scratch-app` | Live run copy of the scratch app on the Docker VM root filesystem (see below). |

The stock `docker/Dockerfile` does not install `pdo_sqlite` or `sqlite3`. The
scratch app's suite does not touch a database, so the stock image would have
worked, but the gate images were extended anyway so a database-backed app could
not fail on a missing extension rather than on its own code.

## The scratch app

A public Wayne State application, cloned fresh, used as a throwaway upgrade
target:

- Type: Laravel 12, Blade views, no Vue, no Mix build committed.
- Constraints: `php: ^8.2`, `laravel/framework: ^12.0`, `phpunit/phpunit: ^11.0`.
- Suite: 197 tests. No `test` composer script, so the gate default
  (`vendor/bin/phpunit`) applied.
- `.env` carried a valid `APP_KEY`; nothing else about it matters here.
- Git HEAD before and after the run: `f928c758d3be2957f9ee89b41d210d123f7d6d67`.

## Nested Docker: how the gate reaches the app

The gate runs `docker run` from inside the helper container, so the app path
must be visible to the Docker daemon, not merely to the container. Three
lessons, in the order they were learned:

1. `HopExecutor::execute()` realpaths the app path
   (`src/Run/HopExecutor.php:65`). A bare named-volume name passed as `--path`
   therefore becomes an absolute path inside the helper container, which the
   daemon cannot see. detect and plan tolerate this (they read the path
   directly); a real run does not.
2. The WSL2 VM's `/mnt/c` mirror of the Windows tree was partial and stale
   during the session: the worktree was absent and the scratch directory came
   back empty. It is not a dependable mount source from the VM.
3. The working arrangement: seed a real directory on the VM root filesystem,
   `/scratch-app`, from the archive volume, then bind-mount it at the same
   path into the helper container (`-v /scratch-app:/scratch-app`) and into
   every gate container (`-v /scratch-app:/app`). One physical tree, visible
   to both sides of the socket.

Seed and sync commands (alpine one-shots, one at a time):

```sh
docker run --rm -v booster-e2e-scratch:/src -v /scratch-app:/dst alpine:3 \
  sh -c 'cp -a /src/. /dst/; echo CP_EXIT=$?'
docker run --rm -v /scratch-app:/src -v booster-e2e-scratch:/dst alpine:3 \
  sh -c 'cp -a /src/. /dst/; echo SYNC_EXIT=$?'
```

## Run log

### Detect

```
path booster-e2e-scratch
app_type laravel-blade
composer_json yes
php 8.2
laravel 12
phpunit 11
vue unknown
node unknown
mix_file yes
wayne_cas no
boost no
```

### Plan

```
BOOSTER plan for booster-e2e-scratch
 app type: laravel-blade
 PHP: 8.2 -> 8.5
 Laravel: 12 -> 13
 PHPUnit: 11 -> 13
 policy: waynestate/creed 0.1.3 (today 2026-10-30)
1. php-82-to-83 [target-image-tests]
2. phpunit-11-to-12 [target-image-tests]
3. laravel-12-to-13 [target-image-tests]
4. php-83-to-84 [target-image-tests]
5. phpunit-12-to-13 [target-image-tests]
6. php-84-to-85 [target-image-tests]
7. node-pin-24 [target-image-tests] Pin app to Node 26: set engines.node in package.json and write .nvmrc.
8. mix-to-vite [target-image-tests] Move simple Laravel Mix front end to Vite (simple webpack.mix.js files only).
9. tailwind-3-to-4 [target-image-tests] Move a Tailwind CSS 3 front end to Tailwind CSS 4 with the official upgrade tool.
Will break on PHP 8.3: no findings.
PHPStan scan: could not run
   phpstan exited 1 without a JSON report.
   Invalid configuration:
   Service '0870': Class or interface 'PHPStan\Rules\Deprecations\DeprecatedScopeHelper' not found.
```

### Dry run

Bounded to the same targets as the real run
(`--to-php=8.4 --to-laravel=12 --to-phpunit=11`):

```
BOOSTER dry run (nothing was changed)
Baseline: SKIPPED (no gate in this run).
1. php-82-to-83: PREVIEWED (0 files)
   Dry run: nothing was changed.
2. php-83-to-84: PREVIEWED (5 files)
   Implicitly nullable parameters must be explicitly marked as such, the usages of fputcsv(), fgetcsv() and str_getcsv() want explicit escape argument.
   --- app/Repositories/EventRepository.php ...
```

Two things the dry run surfaced that the plan does not show:

- The node hops (7 to 9 in the full plan) are offered regardless of the
  `--to-*` bounds; `--skip` is the only lever that removes them.
- `mix-to-vite` refused this app in preview: unsupported Mix call
  `inProduction`. `tailwind-3-to-4` would have driven
  `npx @tailwindcss/upgrade` and then normalised package.json.

### First real run: red baseline, clean stop

Command:

```
php bin/booster run --path=/scratch-app \
  --to-php=8.4 --to-laravel=12 --to-phpunit=11 \
  --skip=node-pin-24 --skip=mix-to-vite --skip=tailwind-3-to-4
```

Result:

```
Baseline: FAIL on PHP 8.2 (exit 2).
Tests: 197, Assertions: 490, Errors: 8.
1. php-82-to-83: NOT-RUN
2. php-83-to-84: NOT-RUN
RESULT: FAIL
```

All eight errors were the same view exception:

```
Illuminate\View\ViewException: Mix manifest not found at: /app/public/mix-manifest.json
```

Feature tests render the main layout, which calls `mix('_resources/css/main.css')`
and `mix('_resources/js/main.js')`. The manifest is a build artifact that
`.gitignore` line 22 keeps out of the repository, so a fresh clone is red
before Booster touches anything. The correct response, and what happened: no
hop started, exit 1.

### The fix

A two-entry `public/mix-manifest.json` mapping each asset to itself (the
compiled files exist in the repo):

```json
{
    "/_resources/css/main.css": "/_resources/css/main.css",
    "/_resources/js/main.js": "/_resources/js/main.js"
}
```

The file is gitignored, so the tree stayed clean. Direct baseline re-check on
PHP 8.2: `OK (197 tests, 724 assertions)`.

### Second real run: green

Same command as before:

```
BOOSTER run
Baseline: PASS on PHP 8.2 (tests green before any change).
1. php-82-to-83: APPLIED (0 files)
   Set php to ^8.3 in require.
   Gate passed: tests are green on PHP 8.3.
2. php-83-to-84: APPLIED (5 files)
   Set php to ^8.4 in require.
   Gate passed: tests are green on PHP 8.4.
   Implicitly nullable parameters must be explicitly marked as such, the usages of fputcsv(), fgetcsv() and str_getcsv() want explicit escape argument.
   changed app/Repositories/EventRepository.php
   changed contracts/Repositories/EventRepositoryContract.php
   changed styleguide/Repositories/EventRepository.php
   changed tests/TestCase.php
   changed tests/Unit/Http/Middleware/DataTest.php
PROCTOR: FAIL on PHP 8.4 (0 new, 19 already present).
Already present (not blocking): [ci-pipelines] ... , [composer-audit] ...
RESULT: PASS
```

Exit code 0.

### Post-run evidence

- `git status --porcelain`: six modified files (the five above plus
  `composer.json`).
- `git diff --stat`: `6 files changed, 8 insertions(+), 8 deletions(-)`.
- `composer.json` line 12: `"php": "^8.4"`.
- HEAD unchanged: `f928c758d3be2957f9ee89b41d210d123f7d6d67`.
- Final independent check on PHP 8.4: `OK (197 tests, 725 assertions)`.
- The run copy was synced back to the archive volume afterwards.

## Findings

Recorded, not fixed, because this task proved the pipeline rather than changed
it.

1. **Fresh clones of Mix-based apps start red.** The manifest is a build
   artifact, gitignored by design, yet the feature suite needs it. Options for
   a later task: document a bootstrap step, commit a minimal manifest for
   apps whose assets are checked in, or make the gate's baseline report name
   the missing artifact explicitly. As it stands, the stop is correct and the
   message is accurate.
2. **Node hops ignore the `--to-*` bounds.** `node-pin-24`, `mix-to-vite` and
   `tailwind-3-to-4` appear in every plan for an app with a front end, and
   only `--skip` removes them. Bounding them (for example, skip automatically
   when `package.json` is absent or the user pinned PHP-only targets) would
   make `--to-php` mean what it says.
3. **`mix-to-vite` refuses real-world Mix configs early.** The scratch app's
   `webpack.mix.js` calls `mix.inProduction(...)`, which the hop does not
   support. The refusal is honest and happens at preview time, but "simple
   webpack.mix.js files only" deserves a checklist of the calls it accepts.
4. **Plan-time PHPStan scan is advisory-only and can die quietly.** The app's
   `phpstan.neon` references
   `PHPStan\Rules\Deprecations\DeprecatedScopeHelper`, which the installed
   PHPStan does not provide, so the scan exited 1 with no JSON report. The
   plan completed and labelled the failure; a run never depends on it.
5. **PROCTOR noise on an offline mirror.** Nineteen findings, all already
   present before the first hop, none blocking. Most were composer-audit
   advisories the offline mirror could not resolve to a status, plus the
   expected missing-CI-pipeline checks. The new-versus-already-present split
   worked exactly as designed, which is the point.
6. **`config.platform.php` is updated, never created.** ComposerEditor only
   rewrites the key when it already exists. The scratch app had none, so the
   diff carries the `require.php` change alone. An app pinned through
   `config.platform.php` gets both lines moved; an app without it gets one.
7. **CRLF warnings in the diff.** The worktree was cloned on Windows, so
   `git diff` prints CRLF normalization warnings for the five Rector-touched
   files. Cosmetic; the `.gitattributes` contract (`* text=auto eol=lf`)
   governs what ships.
8. **Assertion counts drifted by one** between the direct baseline re-check
   (724) and the post-run check (725). Both runs were fully green; the suite
   evidently contains a data-dependent assertion. Noted so nobody mistakes it
   for a regression signal.

## Scope decisions

- The run was bounded to `--to-php=8.4 --to-laravel=12 --to-phpunit=11`, so
  exactly the two PHP hops executed. The full plan (PHP 8.5, Laravel 13,
  PHPUnit 13) was captured for the record but not chased: each extra hop is
  another gate cycle, and the proof is the mechanism, not the distance.
- The three node hops were `--skip`ped. Their gates need npm network access
  and a buildable front end; the dry-run preview already showed what each
  would do, including the `mix-to-vite` refusal.
- `--allow-failing-baseline` was deliberately not used. The red-baseline stop
  is part of what this run exists to prove.

## Reproducing

One Docker job at a time; the helper image and target images must exist first.
Paths shown are relative to the checkout root.

```sh
# 1. Seed the live run copy from the archive volume.
docker run --rm -v booster-e2e-scratch:/src -v /scratch-app:/dst alpine:3 \
  sh -c 'cp -a /src/. /dst/; echo CP_EXIT=$?'

# 2. Run Booster in the helper container, with the socket for the gates.
docker run --rm \
  -v "${PWD}:/booster" \
  -v booster-vendor-bst-e2e-gate:/booster/vendor \
  -v /scratch-app:/scratch-app \
  -v /var/run/docker.sock:/var/run/docker.sock \
  -w /booster booster-e2e-helper:latest \
  sh -c 'php bin/booster run --path=/scratch-app --to-php=8.4 --to-laravel=12 --to-phpunit=11 --skip=node-pin-24 --skip=mix-to-vite --skip=tailwind-3-to-4; echo RUN_EXIT=$?'

# 3. Sync the result back to the archive volume.
docker run --rm -v /scratch-app:/src -v booster-e2e-scratch:/dst alpine:3 \
  sh -c 'cp -a /src/. /dst/; echo SYNC_EXIT=$?'
```

To reset the scratch app between runs: `git checkout . && git clean -fdx`
inside `/scratch-app` (the gitignored manifest survives and is wanted).

## Companion test

This task also pins, in `tests/HopExecutorTest.php`, that the removals
advisory scan runs before the hop rewrites the tree. The test plants a file
using `create_function()` (removed in PHP 8.0) in the raw-PHP example, runs
the `php-74-to-80` hop with a fake process runner whose apply phase deletes
that file (simulating a rule that drops dead code), and asserts the step's
messages still carry the advisory naming the function and the line. If the
scan ever moves after the rewrite, the file is gone, the total is zero, and
the assertion fails.
