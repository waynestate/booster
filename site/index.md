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

A booster shot keeps your immunity current. This one keeps your PHP current, and it stings less. Booster plans the hops from the version your app runs today to one the policy still supports, then gives them one dose at a time: [Rector](https://getrector.com) rewrites the code, Booster raises `composer.json`, and your tests must pass on the target PHP before the next hop starts.

- **One hop** per dose
- **50 files** cap per hop
- **0** commits made for you

[Watch a plan launch](https://booster.apps.wayne.edu/#record) [Install](https://booster.apps.wayne.edu/#install)

**Works alongside [WELD](https://weld.apps.wayne.edu/).** WELD and [Sourdough](https://sourdough.apps.wayne.edu/) start new apps; Booster moves the ones you already have to a supported PHP or Laravel, one hop at a time.

![Booster mark: a syringe that is also a rocket, gold flame below](https://booster.apps.wayne.edu/brand/booster-avatar-512.png)

- **Altitude**: PHP 8.5
- **Stage**: 6 of 6
- **Gate**: target-image tests

Dose by dose

## Every hop a dose. Every gate a stamp.

This is `booster plan` for the example apps that ship with Booster, written up the way your doctor would. Pick a patient, then launch.

Form B-01 · Version immunization record

### examples/laravel-blade

- **App type**: laravel-blade
- **Course**: PHP 8.2 → 8.5 · Laravel 10 → 13
- **Policy**: CREED snapshot
- **Next booster due**: 17 Mar 2028

1. 01 `laravel-10-to-11` Move a Laravel 10 app to Laravel 11. PHP 8.2 · Laravel 11 booster-target:8.2 Gate passed PHP 8.2 28 Oct 2026
2. 02 `laravel-11-to-12` Move a Laravel 11 app to Laravel 12. PHP 8.2 · Laravel 12 booster-target:8.2 Gate passed PHP 8.2 28 Oct 2026
3. 03 `php-82-to-83` Move PHP 8.2 code to PHP 8.3. PHP 8.3 · Laravel 12 booster-target:8.3 Gate passed PHP 8.3 28 Oct 2026
4. 04 `laravel-12-to-13` Move a Laravel 12 app to Laravel 13. PHP 8.3 · Laravel 13 booster-target:8.3 Gate passed PHP 8.3 28 Oct 2026
5. 05 `php-83-to-84` Move PHP 8.3 code to PHP 8.4. PHP 8.4 · Laravel 13 booster-target:8.4 Gate passed PHP 8.4 28 Oct 2026
6. 06 `php-84-to-85` Move PHP 8.4 code to PHP 8.5. PHP 8.5 · Laravel 13 booster-target:8.5 Gate passed PHP 8.5 28 Oct 2026

RESULT: PASS Review with `git diff`, run your own checks, then commit. Booster never commits or pushes.

The overlap

## Never on a pair Laravel doesn't support.

Booster interleaves the PHP and Laravel hops so the app sits on a supported pair after every dose: Laravel 10 on PHP 8.1 to 8.3, 11 on 8.2 to 8.4, 12 on 8.2 to 8.5, 13 on 8.3 or newer. A target pair that cannot exist is refused before anything runs.

Targets must still be supported by CREED's policy. Passing through PHP 8.1 is fine; stopping there is not:

```
$ bin/booster plan --path=examples/raw-php --to-php=8.1
Php 8.1 is past security support; pick a version the policy still supports.
exit 2
```

supported pair not a pair Laravel supports the six doses for Laravel 10 on PHP 8.2

Grid of Laravel versions 10 to 13 against PHP 8.1 to 8.5. The path starts at Laravel 10 on PHP 8.2, moves to Laravel 11 and then 12 on PHP 8.2, PHP 8.3, Laravel 13, then PHP 8.4 and PHP 8.5.

Inside one dose

## Four checks before the next stage fires.

1. T−4
   ### Dry run and cap
   Rector previews the hop. If it reports errors, or wants to change more files than `--max-changes` (50 by default), the run stops before anything is written.
2. T−3
   ### Rector for real
   Only that hop's rules, from Booster's own `vendor/`, with the hop's PHP target set on purpose. An app on PHP 7.4 is rewritten by a Rector running on 8.3 or newer.
3. T−2
   ### Raise the constraint
   The `php` or `laravel/framework` constraint in `composer.json` goes up, and `config.platform.php` moves with it. Booster never runs `composer update`.
4. T−1
   ### The gate
   Your tests run inside `booster-target:<php>`, built from `docker/Dockerfile` on the PHP the hop moves to. Red stops the run and leaves the changes for you to read; `git checkout .` undoes them.

Some hops are playbooks, not code: Rector does not read Vue, so `vue-2-to-3` lists the steps and the official guide, and a person does the work. The PHP 7.4 to 8.0 hop is flagged as the one that needs the most review time.

Five commands

## The whole kit.

Exit codes: **0** pass · **1** a hop was capped, errored or failed its gate · **2** bad usage or a target the policy does not support.

**detectWhich of the five stack shapes, and which versions**

$ bin/booster detect --path=examples/vue-spa path examples/vue-spa app_type raw-php-vue composer_json yes php 8.0 laravel unknown vue 2 wayne_cas no

**planThe ordered hops and the warnings. Changes nothing.**

$ bin/booster plan --path=examples/raw-php Booster plan for examples/raw-php app type: raw-php PHP: 7.4 -> 8.5 policy: bundled snapshot (today 2026-10-28) 1. php-74-to-80 \[target-image-tests\] Move PHP 7.4 code to PHP 8.0. Give this hop the most review time. 2. php-80-to-81 \[target-image-tests\] Move PHP 8.0 code to PHP 8.1. 3. php-81-to-82 \[target-image-tests\] Move PHP 8.1 code to PHP 8.2. 4. php-82-to-83 \[target-image-tests\] Move PHP 8.2 code to PHP 8.3. 5. php-83-to-84 \[target-image-tests\] Move PHP 8.3 code to PHP 8.4. 6. php-84-to-85 \[target-image-tests\] Move PHP 8.4 code to PHP 8.5.

**runRuns the plan; --dry-run previews every hop**

$ bin/booster run --path=examples/raw-php --to-php=8.2 --dry-run Booster dry run (nothing was changed) 1. php-74-to-80: PREVIEWED (1 files) Dry run: nothing was changed. --- src/Legacy.php - public function \_\_construct(string $name) + public function \_\_construct(private string $name) - return strpos($text, 'admin') !== false; + return str_contains($text, 'admin'); - return get_class($object); + return $object::class; 2. php-80-to-81: PREVIEWED (0 files) 3. php-81-to-82: PREVIEWED (0 files) RESULT: PASS

**hopsEvery hop Booster knows**

$ bin/booster hops php-56-to-70 Move PHP 5.6 code to PHP 7.0. php-70-to-71 Move PHP 7.0 code to PHP 7.1. … one PHP minor at a time … php-84-to-85 Move PHP 8.4 code to PHP 8.5. laravel-8-to-9 Move a Laravel 8 app to Laravel 9. … one Laravel major at a time … laravel-12-to-13 Move a Laravel 12 app to Laravel 13. cas-to-sesame Swap the wayne-cas package for SESAME using its compatibility classes (stub recipe). vue-2-to-3 Move a Vue 2 front end to Vue 3. \[playbook\]

**initWrites the coding-tab playbooks to .agents/skills**

$ bin/booster init --path=../my-app Wrote 19 playbooks, 19 changed. $ bin/booster init --path=../my-app --check # exits 1 when a playbook is stale, so CI notices

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

It's `waynestate/booster` on Packagist. Booster runs on PHP 8.3 or newer even when the app does not, so give it its own folder: clone it as above, or `composer create-project waynestate/booster booster ^0.1`.

For your coding agent

## Standing orders.

Prompts for the coding agent you already use. Copy one, or run it straight in OpenCode, Claude Code, Codex or ChatGPT. Every order starts with a plan that changes nothing, and none of them lets the agent commit for you.

### Get started with Booster

Install Booster beside the app, read its shape and versions, and bring back the plan. Not a single file changes.

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
3. Run bin/booster plan --path=../my-app and give me the ordered hops, the warnings and the PHPCompatibility findings.
4. Say which hops are Rector, which edit manifests and which are playbooks a person works through.

Constraints:
- Plan only. Do not run bin/booster run, not even with --dry-run, until I say so.
- Booster never commits, pushes or opens a merge request, and neither do you: a person reads the diff and merges it.
- Ask me before you guess: a version the manifest does not state, a target the policy refuses, or anything that touches CI or a deploy.

Output: the shape and versions, the plan as a numbered list, the warnings and scan findings, then your questions.
```

### Or write the order for your app's shape

### Upgrade a raw PHP app

PHP one minor at a time, a scan of what will break, a dry run, then the real run with the gate.

A prompt for your coding agent. Copy it as it stands:

```text
You are a coding agent upgrading a raw PHP app (no framework, no Vue) at Wayne State University with Booster, the upgrade tool of WELD, the Wayne State application stack.

Read first:
- https://booster.apps.wayne.edu/llms.txt, then the README in llms-full.txt beside it: Quick start, What a hop does and The baseline.
- ADVISOR, the stack's MCP server, if it is connected: upgrade_path for this app, and list_stack_rules with permutation raw-php. If it is not connected, say so and work from the pages.

Task:
1. With Booster cloned beside the app, run bin/booster detect --path=../my-app and confirm the shape is raw-php.
2. Run bin/booster plan --path=../my-app. Expect PHP hops only, one minor at a time.
3. Run bin/booster scan --path=../my-app --php=X.Y for the first hop's target and list what will break.
4. Run bin/booster run --path=../my-app --dry-run and summarise the diffs per hop.
5. Only when I say so: bin/booster run --path=../my-app. It needs a clean git working copy and a Docker daemon for the gate, which runs `composer test` (or vendor/bin/phpunit) in the target PHP image.

Constraints:
- One hop at a time; a red gate stops the run and leaves the changes for me to read (`git checkout .` undoes them). Never commit or push.
- Do not raise --max-changes past 50, or use --skip-gate, without asking me.
- Ask me before you guess: a red baseline, a capped hop, or a target the policy refuses.

Output: the plan, the scan findings, the dry-run summary per hop, then your questions.
```

### Upgrade a raw PHP app with a Vue front end

The PHP hops, the Node pin, and the Vue 2 to 3 playbook a person works through.

A prompt for your coding agent. Copy it as it stands:

```text
You are a coding agent upgrading a raw PHP app with a Vue single-page front end at Wayne State University with Booster, the upgrade tool of WELD, the Wayne State application stack.

Read first:
- https://booster.apps.wayne.edu/llms.txt, then the README in llms-full.txt beside it: Quick start and Hops Booster knows.
- ADVISOR, the stack's MCP server, if it is connected: upgrade_path for this app, policy_lookup for Node and Vue, and list_stack_rules with permutation raw-php-vue. If it is not connected, say so and work from the pages.
- package.json and its lock file.

Task:
1. With Booster cloned beside the app, run bin/booster detect --path=../my-app and confirm the shape is raw-php-vue, with the Vue and Node versions.
2. Run bin/booster plan --path=../my-app and sort the hops: PHP hops (Rector), node-pin-24 (sets engines.node and writes .nvmrc), and vue-2-to-3 if the app is on Vue 2.
3. Run bin/booster run --path=../my-app --dry-run and summarise the diffs.
4. For vue-2-to-3, list the playbook steps for this app. Rector does not read Vue, so Booster changes nothing there: a person does that work.

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
3. If composer.json requires wayne-cas, run the plan again with --sesame and say what the cas-to-sesame hop renames. It is a stub recipe today, so list what is left for a person.
4. Run bin/booster run --path=../my-app --dry-run and summarise the diffs per hop.

Constraints:
- Plan and dry run only, until I say so. Never commit or push.
- Booster never runs composer update; say which packages will need it after each hop.
- Ask me before you guess: a red baseline, a capped hop, or a package that blocks a Laravel major.

Output: the plan with the reason for its order, the SESAME notes if any, the dry-run summary, then your questions.
```

### Upgrade a Laravel app with Vue islands

The Laravel and PHP hops, the Node pin, and Mix to Vite when the plan lands on Laravel 9 or newer.

A prompt for your coding agent. Copy it as it stands:

```text
You are a coding agent upgrading a Laravel app with Blade views and Vue islands at Wayne State University with Booster, the upgrade tool of WELD, the Wayne State application stack.

Read first:
- https://booster.apps.wayne.edu/llms.txt, then the README in llms-full.txt beside it: Hops Booster knows (node-pin-24, mix-to-vite, vue-2-to-3) and Laravel hops and vendor.
- ADVISOR, the stack's MCP server, if it is connected: upgrade_path for this app, policy_lookup for Laravel and Node, and list_stack_rules with permutation laravel-blade-vue. If it is not connected, say so and work from the pages.
- package.json, its lock file, and webpack.mix.js if there is one.

Task:
1. Run `composer install` in the app, then, with Booster cloned beside it, bin/booster plan --path=../my-app.
2. If the plan has mix-to-vite, say whether this webpack.mix.js is the simple shape Booster converts (mix.js, .vue(), .sass(), .postCss(), .version()) or which call it will refuse by name. A Vue 2 front end takes vue-2-to-3 first.
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
2. Sort the hops: Laravel and PHP (Rector), node-pin-24, and vue-2-to-3 if the app is on Vue 2 (a playbook, not a code change).
3. For each Laravel hop, list what to check by hand on the Inertia side: Booster has no Inertia checks per Laravel hop yet.
4. Run bin/booster run --path=../my-app --dry-run and summarise the diffs per hop.

Constraints:
- Plan and dry run only, until I say so. Never commit or push.
- A Laravel hop without vendor is only a partial preview; say so if you see one marked incomplete.
- Ask me before you guess: an inertiajs package that blocks a Laravel major, or a red baseline.

Output: the plan sorted by kind, the Inertia checks per Laravel hop, the dry-run summary, then your questions.
```

The agent reads the same pages you do: [llms.txt](https://booster.apps.wayne.edu/llms.txt) lists them, [llms-full.txt](https://booster.apps.wayne.edu/llms-full.txt) holds them all in one file, and this page has a [Markdown twin](https://booster.apps.wayne.edu/index.md). Nothing here phones home; the buttons only copy text or open the tool you picked. Take as directed.

Contraindications

## What Booster will not do.

Side effects are limited to the diff in your working copy. Read it, then merge it yourself.

- ### Commit, push or open a merge request
  It edits the working copy and prints a report. A person reads the diff and merges it.
- ### Start on a dirty tree
  `run` refuses a working copy that is not under git or has uncommitted changes, so the upgrade diff stands alone. `--allow-dirty` if you must.
- ### Aim at an unsupported version
  An end-of-life target is refused; a security-only one comes with a warning. The dates come from CREED's `policy.json`, never from Booster's code.
- ### Run `composer update`
  It raises constraints. Resolving them is your call.
- ### Pass quietly
  An unreadable policy, broken JSON, missing Docker or a Rector error fails closed with exit 1 or 2.
- ### Trust your file names
  Subprocesses take argument arrays, and control characters are stripped from everything printed. It reads no secrets and makes no network calls of its own.

The care team

## Booster's place in WELD.

WELD is the Wayne State application stack. Booster is its upgrade layer, and it works for all five shapes: raw PHP, raw PHP with a Vue SPA, Laravel with Blade, Blade with Vue islands, and Inertia with Vue.

- [WayneUI](https://wayneui.apps.wayne.edu/)The interface layer under everything, this page included.
- [CREED](https://creed.apps.wayne.edu/)Writes the version policy Booster reads.
- [PROCTOR](https://proctor.apps.wayne.edu/)Measures an app against that policy; can ride in the gate.
- [Preflight](https://preflight.apps.wayne.edu/)The CI templates Booster's own checks run on.
- [SESAME](https://sesame.apps.wayne.edu/)Sign-in; `--sesame` moves wayne-cas apps to it.
- [HELMET](https://helmet.apps.wayne.edu/)The baseline runtime and its headers.
- [Pinstripe](https://pinstripe.apps.wayne.edu/)The coding standard Booster is held to.
- [Sourdough](https://sourdough.apps.wayne.edu/)Starters that are born current.
- [WELD](https://weld.apps.wayne.edu/)The whole stack, joined. Booster keeps it current.
- BoosterMoves apps forward, one dose at a time.You are here
- [Squire](https://squire.apps.wayne.edu/)Carries the release out the door.
- [Rollcall](https://rollcall.apps.wayne.edu/)Every app answers “present”, with its PHP version.
- [ADVISOR](https://advisor.apps.wayne.edu/)Ask what applies to your app, and what to do next.
- [Drawbridge](https://drawbridge.apps.wayne.edu/)The sign-in gateway, lowered on request.

Version 0.1

## Not yet given.

The plumbing is proven on the example apps; the hit rate on real apps is not measured yet. What is still to come, from [OPEN_QUESTIONS.md](https://github.com/waynestate/booster/blob/main/OPEN_QUESTIONS.md):

- Coming soon A published PHAR, so Booster is one file you download (you can build it from a checkout today)
- Coming soon `booster mcp`, a read-only tool server
- Coming soon Target-version PHPStan as an advisory scan (the PHPCompatibility scan is built)
- Coming soon Vue 2 to 3 beyond a playbook, and Inertia checks per Laravel hop
- Coming soon A full wayne-cas migration (today's recipe is a stub)
