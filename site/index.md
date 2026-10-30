---
title: "Booster: Boosts your versions."
description: "Booster plans and runs PHP and Laravel upgrades one hop at a time on Rector, gates every hop with your tests on the target PHP, and never commits or pushes for you."
url: https://booster.apps.wayne.edu/
llms: https://booster.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

PHP and Laravel upgrades · WELD stack

# BOOSTER

Boosts your versions.

A booster shot keeps your immunity current. This one keeps your PHP current, and it stings less. Booster works out the hops from the PHP or Laravel version your app runs today to one CREED still supports, and runs them one at a time. [Rector](https://getrector.com) rewrites the code and Booster raises the constraints in `composer.json`. Your tests have to pass on the new PHP before the next hop starts.

- **One hop** per dose
- **200 files** review threshold per hop
- **0** commits made for you

[Watch a plan launch](https://booster.apps.wayne.edu/#record) [Install](https://booster.apps.wayne.edu/#install)

**Works alongside [WELD](https://weld.apps.wayne.edu/).** WELD and [Sourdough](https://sourdough.apps.wayne.edu/) start new apps. Booster is for the apps you already have.

![Booster logo: a syringe that is also a rocket, gold flame below](https://booster.apps.wayne.edu/brand/booster-avatar-512.png)

- **Altitude**: PHP 8.5
- **Stage**: 6 of 6
- **Gate**: target-image tests

Dose by dose

## A stamp for each hop that passes.

These are Booster's plans for the example apps in `examples/`, written up like a vaccination card. Pick an app and press Run the plan.

Form B-01 · Version immunization record

### examples/laravel-blade

- **App type**: laravel-blade
- **Course**: PHP 8.2 → 8.5 · Laravel 10 → 13 · PHPUnit 10 → 13
- **Policy**: waynestate/creed 0.1.3
- **Next booster due**: 17 Mar 2028

1. 01 `phpunit-10-to-11` Move PHPUnit 10 tests to PHPUnit 11. PHP 8.2 · Laravel 10 booster-target:8.2 Gate passed PHP 8.2 28 Oct 2026
2. 02 `laravel-10-to-11` Move a Laravel 10 app to Laravel 11. PHP 8.2 · Laravel 11 booster-target:8.2 Gate passed PHP 8.2 28 Oct 2026
3. 03 `laravel-11-to-12` Move a Laravel 11 app to Laravel 12. PHP 8.2 · Laravel 12 booster-target:8.2 Gate passed PHP 8.2 28 Oct 2026
4. 04 `php-82-to-83` Move PHP 8.2 code to PHP 8.3. PHP 8.3 · Laravel 12 booster-target:8.3 Gate passed PHP 8.3 28 Oct 2026
5. 05 `phpunit-11-to-12` Move PHPUnit 11 tests to PHPUnit 12. PHP 8.3 · Laravel 12 booster-target:8.3 Gate passed PHP 8.3 28 Oct 2026
6. 06 `laravel-12-to-13` Move a Laravel 12 app to Laravel 13. PHP 8.3 · Laravel 13 booster-target:8.3 Gate passed PHP 8.3 28 Oct 2026
7. 07 `php-83-to-84` Move PHP 8.3 code to PHP 8.4. PHP 8.4 · Laravel 13 booster-target:8.4 Gate passed PHP 8.4 28 Oct 2026
8. 08 `phpunit-12-to-13` Move PHPUnit 12 tests to PHPUnit 13. PHP 8.4 · Laravel 13 booster-target:8.4 Gate passed PHP 8.4 28 Oct 2026
9. 09 `php-84-to-85` Move PHP 8.4 code to PHP 8.5. PHP 8.5 · Laravel 13 booster-target:8.5 Gate passed PHP 8.5 28 Oct 2026

RESULT: PASS Review with `git diff`, run your own checks, then commit. Booster never commits or pushes.

The overlap

## Never on a pair Laravel doesn't support.

Booster mixes the PHP and Laravel hops so the app is on a supported pair after every hop. Laravel 10 runs on PHP 8.1 to 8.3, 11 on 8.2 to 8.4, 12 on 8.2 to 8.5, 13 on 8.3 or newer. If you ask for a pair that can't exist, Booster refuses before anything runs.

The target also has to be one CREED's policy knows. A plan can pass through PHP 8.1 and even stop there, with a warning that says so:

```
$ bin/booster plan --path=examples/raw-php --to-php=8.1
BOOSTER plan for examples/raw-php
  app type: raw-php
  PHP: 7.4 -> 8.1
...
Warning: PHP 8.1 is past security support since 2025-12-31. Use it only as a stop on the way up.
exit 0
```

supported pair not a pair Laravel supports the Laravel and PHP hops for Laravel 10 on PHP 8.2

Grid of Laravel versions 10 to 13 against PHP 8.1 to 8.5. The path starts at Laravel 10 on PHP 8.2, moves to Laravel 11 and then 12 on PHP 8.2, PHP 8.3, Laravel 13, then PHP 8.4 and PHP 8.5.

Inside one dose

## Four steps before the next stage fires.

1. T−4
   ### Dry run and review
   Rector previews the hop. If it reports errors, the run stops before anything is written. So does a hop over the review threshold of 200 files: an interactive run shows the per-rule summary and asks first, and `--max-changes` sets a hard limit instead.
2. T−3
   ### Rector for real
   Rector runs with only that hop's rules, from Booster's own `vendor/`. That's how an app on PHP 7.4 gets rewritten by a Rector running on 8.3 or newer.
3. T−2
   ### Raise the constraint
   The `php` or `laravel/framework` constraint in `composer.json` goes up, and `config.platform.php` moves with it.
4. T−1
   ### The gate
   Before the first hop your tests run once on the app's current PHP as the baseline; a red one stops the run unless you pass `--allow-failing-baseline`. After each hop they run inside `booster-target:<php>`, built from `docker/Dockerfile` on the PHP the hop moves to. On Laravel and PHPUnit hops the gate refreshes `composer.lock` for the package the hop moved, so the lock lands on the new major version; if Packagist's security advisories block those releases, whether the install fails outright or Composer quietly locks a branch version where the hop asked for a tag, the gate writes a scoped temporary ignore, retries once, and tells you what it did. The plan's last step then fails until that entry is out of `composer.json`, so the upgrade never finishes with the unblock still in place. After the last hop PROCTOR checks the upgraded app against CREED's policy, blocking only on findings the upgrade introduced. If a red baseline names a missing Mix or Vite manifest, build the front-end assets first. A failure stops the run and leaves the changes for you to read. `git checkout .` undoes them.

Not every hop is Rector. `node-pin-24` pins Node 26 in `package.json` and writes `.nvmrc`; `mix-to-vite` converts a simple `webpack.mix.js` and moves the build to Vite. The Vue 2 to 3 move is staged: `vue-26-to-27`, `vue-27-to-compat` and `vue-compat-codemod`, with the `vue-2-to-3` playbook standing in for all three. Rector doesn't read Vue, so a person works those steps. The plan also flags PHP 7.4 to 8.0 as the hop that needs the most review.

The commands

## The whole kit.

There's also `scan`, which reports what will break on a given PHP (`--removals` for removed functions, `--phpstan` for static analysis) and always exits 0. Exit codes: **0** pass · **1** the baseline failed, or a hop was capped, errored or failed its gate · **2** bad usage or a target the policy does not know.

**detectWhich of the five stack shapes, and which versions**

$ bin/booster detect --path=examples/vue-spa path examples/vue-spa app_type raw-php-vue composer_json yes php 8.0 laravel unknown phpunit unknown vue 2 node unknown mix_file no wayne_cas no boost no

**planThe ordered hops, the warnings and the scans. Changes nothing.**

$ bin/booster plan --path=examples/raw-php --today=2026-10-29 BOOSTER plan for examples/raw-php app type: raw-php PHP: 7.4 -> 8.5 policy: waynestate/creed 0.1.3 (today 2026-10-29) 1. php-74-to-80 \[target-image-tests\] Move PHP 7.4 code to PHP 8.0. Give this hop the most review time. Removed between PHP 7.4 and PHP 8.0: none found. 2. php-80-to-81 \[target-image-tests\] Move PHP 8.0 code to PHP 8.1. Removed between PHP 8.0 and PHP 8.1: none found. 3. php-81-to-82 \[target-image-tests\] Move PHP 8.1 code to PHP 8.2. Removed between PHP 8.1 and PHP 8.2: none found. 4. php-82-to-83 \[target-image-tests\] Move PHP 8.2 code to PHP 8.3. Removed between PHP 8.2 and PHP 8.3: none found. 5. php-83-to-84 \[target-image-tests\] Move PHP 8.3 code to PHP 8.4. Removed between PHP 8.3 and PHP 8.4: none found. 6. php-84-to-85 \[target-image-tests\] Move PHP 8.4 code to PHP 8.5. Removed between PHP 8.4 and PHP 8.5: none found. Will break on PHP 8.0: no findings. PHPStan on PHP 8.5: 3 findings. src/Legacy.php:20 \[missingType.parameter\] Method Example\\Legacy::className() has parameter $object with no type specified. src/Legacy.php:22 \[return.type\] Method Example\\Legacy::className() should return string but returns class-string|false. src/Legacy.php:25 \[parameter.implicitlyNullable\] Deprecated in PHP 8.4: Parameter #1 $label (string) is implicitly nullable via default value null.

**runRuns the plan; --dry-run previews every hop**

$ bin/booster run --path=examples/raw-php --to-php=8.2 --dry-run BOOSTER dry run (nothing was changed) Baseline: SKIPPED (no gate in this run). 1. php-74-to-80: PREVIEWED (1 files) Largest risk hop: loose comparison of numbers and strings changed, many warnings became thrown errors, and arithmetic on arrays throws. Write or extend tests before applying this hop; only tests and review find behaviour changes here. --- src/Legacy.php --- Original +++ New - public function \_\_construct(string $name) + public function \_\_construct(private string $name) - return strpos($text, 'admin') !== false; + return str_contains($text, 'admin'); - return get_class($object); + return $object::class; 2. php-80-to-81: PREVIEWED (0 files) Dry run: nothing was changed. mysqli now throws on errors by default; PDO on MySQL returns native int and float types; htmlspecialchars() now escapes single quotes. Passing null to a non-nullable built-in parameter is deprecated: check the log after the run. 3. php-81-to-82: PREVIEWED (0 files) Dry run: nothing was changed. Unique files across hops: 1. Later hops were previewed against the original tree, so the same file can appear in several hops. RESULT: PASS Dry run: these changes were previewed, none were applied.

**hopsEvery hop Booster knows**

$ bin/booster hops php-55-to-56 Move PHP 5.5 code to PHP 5.6. php-56-to-70 Move PHP 5.6 code to PHP 7.0. php-70-to-71 Move PHP 7.0 code to PHP 7.1. … one PHP minor at a time … php-84-to-85 Move PHP 8.4 code to PHP 8.5. laravel-5.8-to-6 Move a Laravel 5.8 app to Laravel 6. … one Laravel major at a time … laravel-12-to-13 Move a Laravel 12 app to Laravel 13. phpunit-9-to-10 Move PHPUnit 9 tests to PHPUnit 10. … one PHPUnit major at a time … phpunit-12-to-13 Move PHPUnit 12 tests to PHPUnit 13. phpunit-to-9-manual Move PHPUnit to ^9 by hand: PHPUnit below 9 does not run on PHP 8, so later gates cannot run until it moves. \[playbook\] cas-to-sesame Swap the wayne-cas package for SESAME using its compatibility classes. node-pin-24 Pin the app to Node 26: set engines.node in package.json and write .nvmrc. mix-to-vite Move a simple Laravel Mix front end to Vite (simple webpack.mix.js files only). tailwind-3-to-4 Move a Tailwind CSS 3 front end to Tailwind CSS 4 with the official upgrade tool. vue-26-to-27 Move a Vue 2.6 front end to Vue 2.7, the last Vue 2 release, which carries the Vue 3 reactivity API. vue-27-to-compat Move a Vue 2.7 front end to Vue 3 through the @vue/compat build. vue-compat-codemod Run the vue-upgrade-tool codemod over the front end and keep every manual item it lists. vue-2-to-3 Move a Vue 2 front end to Vue 3 (playbook only; Rector does not read Vue). \[playbook\]

**initWrites the coding-tab playbooks to .agents/skills**

$ bin/booster init --path=../my-app Wrote 33 playbooks, 33 changed. $ bin/booster init --path=../my-app --check # exits 1 when a playbook is stale, so CI notices

Get the shot

## Install, then plan.

Booster needs PHP 8.3 or newer. The app it upgrades does not: an app on PHP 7.4 is fine. The gate in `run` starts Docker itself, so the machine needs a Docker daemon.

**Linux and macOS**

```
git clone https://github.com/waynestate/booster.git
cd booster && composer install
bin/booster detect --path=../my-app
bin/booster plan   --path=../my-app
bin/booster run    --path=../my-app --dry-run
bin/booster run    --path=../my-app
```

**Windows**

```
# PowerShell; no bash or WSL needed
git clone https://github.com/waynestate/booster.git
cd booster; composer install
php bin\booster detect --path=..\my-app
php bin\booster plan   --path=..\my-app
php bin\booster run    --path=..\my-app --dry-run
php bin\booster run    --path=..\my-app
```

**Docker, no PHP**

```
# inside the booster clone; the path after the second -v is your app
docker run --rm -v "${PWD}:/booster" -w /booster composer:2 composer install --no-interaction
docker run --rm -v "${PWD}:/booster" -v "/path/to/app:/app" -w /booster php:8.4-cli php bin/booster plan --path=/app
```

When Booster itself runs in a container, pass `--skip-gate` to `run` and run the tests yourself.

The one-shot form, for a forced image upgrade

`bin/booster run --path=../my-app --goal=php-74-to-84`

On Packagist

It's `waynestate/booster` on Packagist. Give it its own folder, not your app's `vendor/`, because it needs PHP 8.3 even when your app doesn't. Clone it as above, or run `composer create-project waynestate/booster booster ^1.0`.

Single file

Every version tag publishes `booster.phar` as a release asset on GitLab and GitHub. Run it with `php booster.phar`; every command works from the phar. Preflight and apps without Composer access use this form.

For your coding agent

## Standing orders.

Six prompts for whatever coding agent you use: one to get started, then one for each app shape. Copy one, or open it in OpenCode, Claude Code, Codex or ChatGPT. Each starts with a plan that changes nothing, and none of them lets the agent commit for you.

### Get started with Booster

Clones Booster next to your app, runs `detect` and `plan`, and reports back. Nothing in your app changes.

A prompt for your coding agent. Copy it as it stands:

```text
You are a coding agent planning a PHP and Laravel upgrade with Booster, for the app in this folder, at Wayne State University. Booster is the upgrade tool of WELD, the Wayne State application stack: it plans the hops from the versions the app runs today to ones CREED's version policy still supports, and runs them one at a time on Rector, with the app's own tests as the gate on each hop.

Read first:
- https://booster.apps.wayne.edu/llms.txt, the index of Booster's pages, each one as Markdown; llms-full.txt beside it holds the README with every command and option. Fetch what you need; do not work from memory.
- ADVISOR, the stack's MCP server, if it is connected: upgrade_path for this app, policy_lookup for PHP and Laravel, and read_project_docs for booster. If it is not connected, say so and work from the pages. How to add it: https://advisor.apps.wayne.edu/llms.txt
- This app's composer.json, and package.json if there is one.

Task:
1. Clone Booster into its own folder beside the app with `git clone https://github.com/waynestate/booster.git`, then run `composer install` in it. Booster needs PHP 8.3 or newer; the app does not. No PHP on the machine: use the Docker lines from the README.
2. Run bin/booster detect --path=../my-app (on Windows PowerShell, php bin\booster with ..\my-app) and say which of the five stack shapes the app is and which versions it runs.
3. Run bin/booster plan --path=../my-app and give me the ordered hops, the warnings, what will break on each hop's target PHP, and the PHPStan findings for the final target.
4. Say which hops are Rector, which edit manifests and which are playbooks a person works through.

Constraints:
- Plan only. Do not run bin/booster run, not even with --dry-run, until I say so.
- Booster never commits, pushes or opens a merge request, and neither do you: a person reads the diff and merges it.
- Ask me before you guess: a version the manifest does not state, a target the policy refuses, or anything that touches CI or a deploy.

Output: the shape and versions, the plan as a numbered list, the warnings and scan findings, then your questions.
```

### Or write the order for your app's shape

### Upgrade a raw PHP app

Plans the PHP hops, scans for what will break and does a dry run. The real run waits until you say so.

A prompt for your coding agent. Copy it as it stands:

```text
You are a coding agent upgrading a raw PHP app (no framework, no Vue) at Wayne State University with Booster, the upgrade tool of WELD, the Wayne State application stack.

Read first:
- https://booster.apps.wayne.edu/llms.txt, then the README in llms-full.txt beside it: Quick start, What a hop does and The baseline.
- ADVISOR, the stack's MCP server, if it is connected: upgrade_path for this app, and list_stack_rules with permutation raw-php. If it is not connected, say so and work from the pages.

Task:
1. With Booster cloned beside the app, run bin/booster detect --path=../my-app and confirm the shape is raw-php.
2. Run bin/booster plan --path=../my-app. Expect PHP hops only, one minor at a time.
3. Run bin/booster scan --path=../my-app --php=X.Y for the first hop's target and list what will break; add --removals for removed functions and --phpstan for static analysis.
4. Run bin/booster run --path=../my-app --dry-run and summarise the diffs per hop.
5. Only when I say so: bin/booster run --path=../my-app. It needs a clean git working copy and a Docker daemon for the gate, which runs `composer test` (or vendor/bin/phpunit) in the target PHP image.

Constraints:
- One hop at a time; a red gate stops the run and leaves the changes for me to read (`git checkout .` undoes them). Never commit or push.
- If a hop is over the review threshold, show me its per-rule summary before applying it. Do not use --max-changes=0 or --skip-gate without asking me.
- Ask me before you guess: a red baseline, a capped hop, or a target the policy refuses.

Output: the plan, the scan findings, the dry-run summary per hop, then your questions.
```

### Upgrade a raw PHP app with a Vue front end

Plans the PHP hops and the Node pin, and stages the Vue 2 to 3 move: the compat build, then the codemod, with a person doing the Vue work.

A prompt for your coding agent. Copy it as it stands:

```text
You are a coding agent upgrading a raw PHP app with a Vue single-page front end at Wayne State University with Booster, the upgrade tool of WELD, the Wayne State application stack.

Read first:
- https://booster.apps.wayne.edu/llms.txt, then the README in llms-full.txt beside it: Quick start and Hops Booster knows.
- ADVISOR, the stack's MCP server, if it is connected: upgrade_path for this app, policy_lookup for Node and Vue, and list_stack_rules with permutation raw-php-vue. If it is not connected, say so and work from the pages.
- package.json and its lock file.

Task:
1. With Booster cloned beside the app, run bin/booster detect --path=../my-app and confirm the shape is raw-php-vue, with the Vue and Node versions.
2. Run bin/booster plan --path=../my-app and sort the hops: PHP hops (Rector), node-pin-24 (pins Node 26 in package.json and writes .nvmrc), and the staged Vue hops if the app is on Vue 2: vue-26-to-27 when it is below 2.7, then vue-27-to-compat and vue-compat-codemod.
3. Run bin/booster run --path=../my-app --dry-run and summarise the diffs.
4. For the Vue hops, list the playbook steps for this app: the compat build, then the codemod, keeping every manual item the codemod lists. Rector does not read Vue, so Booster changes nothing there: a person does that work.

Constraints:
- Plan and dry run only, until I say so. Never commit or push.
- Node hops run npm in a node:26 container, so they need a Docker daemon but no Node on the machine.
- Ask me before you guess: a front end you cannot place, or a lock file that is missing.

Output: the plan sorted into PHP, Node and Vue, the dry-run summary, the Vue playbook steps, then your questions.
```

### Upgrade a Laravel app with Blade

Laravel and PHP hops in the order that keeps a supported pair at every step, and SESAME if the app still signs in with wayne-cas.

A prompt for your coding agent. Copy it as it stands:

```text
You are a coding agent upgrading a Laravel app with Blade views at Wayne State University with Booster, the upgrade tool of WELD, the Wayne State application stack.

Read first:
- https://booster.apps.wayne.edu/llms.txt, then the README in llms-full.txt beside it: Quick start, Laravel hops and vendor, and The baseline.
- ADVISOR, the stack's MCP server, if it is connected: upgrade_path for this app, policy_lookup for PHP and Laravel, and list_stack_rules with permutation laravel-blade. If it is not connected, say so and work from the pages.

Task:
1. Run `composer install` in the app first: rector-laravel needs Laravel's classes, and a Laravel hop without vendor is only a partial preview.
2. With Booster cloned beside the app, run bin/booster plan --path=../my-app and explain the order: it keeps the app on a PHP and Laravel pair Laravel supports at every step.
3. If composer.json requires wayne-cas, run the plan again with --sesame and say what the cas-to-sesame hop renames. Only the two-factor sign-in stays manual; list that for a person.
4. Run bin/booster run --path=../my-app --dry-run and summarise the diffs per hop.

Constraints:
- Plan and dry run only, until I say so. Never commit or push.
- Laravel and PHPUnit hops refresh composer.lock inside the gate (composer update -W for the package the hop moved), and a Composer-phase failure means the tests never ran. PHP hops keep a plain install: if a committed lock goes stale, say which packages need composer update.
- Ask me before you guess: a red baseline, a capped hop, or a package that blocks a Laravel major.

Output: the plan with the reason for its order, the SESAME notes if any, the dry-run summary, then your questions.
```

### Upgrade a Laravel app with Vue islands

The Laravel and PHP hops, the Node pin, and Mix to Vite when the plan lands on Laravel 9 or newer.

A prompt for your coding agent. Copy it as it stands:

```text
You are a coding agent upgrading a Laravel app with Blade views and Vue islands at Wayne State University with Booster, the upgrade tool of WELD, the Wayne State application stack.

Read first:
- https://booster.apps.wayne.edu/llms.txt, then the README in llms-full.txt beside it: Hops Booster knows (node-pin-24, mix-to-vite, vue-26-to-27, vue-27-to-compat, vue-compat-codemod) and Laravel hops and vendor.
- ADVISOR, the stack's MCP server, if it is connected: upgrade_path for this app, policy_lookup for Laravel and Node, and list_stack_rules with permutation laravel-blade-vue. If it is not connected, say so and work from the pages.
- package.json, its lock file, and webpack.mix.js if there is one.

Task:
1. Run `composer install` in the app, then, with Booster cloned beside it, bin/booster plan --path=../my-app.
2. If the plan has mix-to-vite, say whether this webpack.mix.js is the simple shape Booster converts (mix.js, .vue(), .sass(), .postCss(), .version(), and if (mix.inProduction()) { mix.version(); }) or which call it will refuse by name. A Vue 2 front end takes the staged Vue hops first: vue-26-to-27 when it is below 2.7, then vue-27-to-compat and vue-compat-codemod.
3. Run bin/booster run --path=../my-app --dry-run and summarise the diffs, Blade layouts included.
4. List any withoutMix() calls for me to rename to withoutVite() by hand.

Constraints:
- Plan and dry run only, until I say so. Never commit or push.
- The mix-to-vite gate runs npm ci and npm run build in a node:26 container before the tests, so it needs a Docker daemon.
- Ask me before you guess: a Mix config Booster refuses, or an asset no Mix entry covers.

Output: the plan, the Mix to Vite verdict, the dry-run summary, the manual renames, then your questions.
```

### Upgrade a Laravel app with Inertia

The Laravel and PHP hops and the Node pin, with the Inertia checks a person still does by hand.

A prompt for your coding agent. Copy it as it stands:

```text
You are a coding agent upgrading a Laravel app with Inertia and Vue at Wayne State University with Booster, the upgrade tool of WELD, the Wayne State application stack.

Read first:
- https://booster.apps.wayne.edu/llms.txt, then the README in llms-full.txt beside it: Quick start, Laravel hops and vendor, and Hops Booster knows.
- ADVISOR, the stack's MCP server, if it is connected: upgrade_path for this app, policy_lookup for Laravel, Node and Vue, and list_stack_rules with permutation laravel-inertia-vue. If it is not connected, say so and work from the pages.
- composer.json, package.json and its lock file.

Task:
1. Run `composer install` in the app, then, with Booster cloned beside it, bin/booster detect --path=../my-app and bin/booster plan --path=../my-app.
2. Sort the hops: Laravel and PHP (Rector), node-pin-24, and the staged Vue hops if the app is on Vue 2 (playbooks, not code changes): vue-26-to-27 when it is below 2.7, then vue-27-to-compat and vue-compat-codemod.
3. For each Laravel hop, list what to check by hand on the Inertia side: Booster has no Inertia checks per Laravel hop yet.
4. Run bin/booster run --path=../my-app --dry-run and summarise the diffs per hop.

Constraints:
- Plan and dry run only, until I say so. Never commit or push.
- A Laravel hop without vendor is only a partial preview; say so if you see one marked incomplete.
- Ask me before you guess: an inertiajs package that blocks a Laravel major, or a red baseline.

Output: the plan sorted by kind, the Inertia checks per Laravel hop, the dry-run summary, then your questions.
```

The agent reads the same pages you do: [llms.txt](https://booster.apps.wayne.edu/llms.txt) lists them, [llms-full.txt](https://booster.apps.wayne.edu/llms-full.txt) holds them all in one file, and this page has a [Markdown twin](https://booster.apps.wayne.edu/index.md). Nothing here phones home. The buttons only copy text or open the tool you picked.

Contraindications

## What Booster will not do.

Side effects are limited to the diff in your working copy. Read it, then merge it yourself.

- ### Commit, push or open a merge request
  It edits the working copy and prints a report. A person reads the diff and merges it.
- ### Start on a dirty tree
  `run` refuses a working copy that is not under git or has uncommitted changes, so the upgrade diff stands alone. Pass `--allow-dirty` if you must.
- ### Aim at an unsupported version
  An end-of-life target is refused; a security-only one comes with a warning. The dates come from CREED's `policy.json`, never from Booster's code.
- ### Resolve more than the hop moves
  Inside the gate it runs a scoped `composer update -W` for the package a Laravel or PHPUnit hop moved, so the lock follows the constraint. It never resolves anything else; you decide when to update the rest.
- ### Pass quietly
  An unreadable policy, broken JSON, missing Docker or a Rector error stops the run with exit 1 or 2.
- ### Trust your file names
  Subprocesses take argument arrays, and control characters are stripped from everything printed. It reads no secrets and makes no network calls of its own.

The care team

## Booster's place in WELD.

WELD is the Wayne State application stack. Booster is its upgrade layer, and it works for all five shapes: raw PHP, raw PHP with a Vue SPA, Laravel with Blade, Blade with Vue islands, and Inertia with Vue.

- [WayneUI](https://wayneui.apps.wayne.edu/)The interface layer under everything, this page included.
- [CREED](https://creed.apps.wayne.edu/)Writes the version policy Booster reads from the waynestate/creed package.
- [PROCTOR](https://proctor.apps.wayne.edu/)Measures an app against that policy; the gate runs it after the last hop, and `--proctor-command` overrides the check.
- [Preflight](https://preflight.apps.wayne.edu/)The CI templates Booster's own checks run on.
- [SESAME](https://sesame.apps.wayne.edu/)Sign-in; `--sesame` moves wayne-cas apps to it.
- [HELMET](https://helmet.apps.wayne.edu/)Security headers and session hardening.
- [Pinstripe](https://pinstripe.apps.wayne.edu/)The coding standard. Booster's PHPStan advisory scan runs against its raw preset.
- [Sourdough](https://sourdough.apps.wayne.edu/)Starts new raw PHP apps on current versions.
- [WELD](https://weld.apps.wayne.edu/)The Laravel and Vue starter, with the stack already installed.
- BoosterMoves apps forward, one dose at a time.You are here
- [Squire](https://squire.apps.wayne.edu/)Carries the release out the door.
- [Rollcall](https://rollcall.apps.wayne.edu/)Every app answers “present”, with its PHP version.
- [ADVISOR](https://advisor.apps.wayne.edu/)An MCP server your agent can ask about the stack.
- [Drawbridge](https://drawbridge.apps.wayne.edu/)The sign-in gateway, lowered on request.

Version 1.0

## Not yet given.

The five example apps prove the plumbing end to end, and the gate now ends with PROCTOR checking the result against CREED's policy. What 1.0 leaves open, from [OPEN_QUESTIONS.md](https://github.com/waynestate/booster/blob/main/OPEN_QUESTIONS.md):

- Not yet Custom WSU Rector rules beyond the CAS rename, and Rector pilot numbers on real apps
- Not yet A cas-to-sesame run against a real app; Duo two-factor stays manual work
- Decided No `booster mcp`: ADVISOR is the stack's read-only MCP server
- Decided No merge-request automation on app code; a person reads the diff and merges it
- Open The shared MySQL 5.6 database floor (question 8); the hop notes tell the reviewer to check it
