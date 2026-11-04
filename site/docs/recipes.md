---
title: "How do I... | Booster docs"
description: "Recipes for the situations people actually ask about, with the exact command, what Booster does, and when not to use it."
url: https://booster.apps.wayne.edu/docs/recipes.html
llms: https://booster.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# How do I...[#](https://booster.apps.wayne.edu/docs/recipes.html#how-do-i)

This page answers "how do I use Booster for X?" one situation at a time. Each recipe has the command, what Booster will do, and when to reach for something else. For the default behavior (no flags at all), read [What a run does by default](https://booster.apps.wayne.edu/docs/guides/quick-start.html#what-a-run-does-by-default) first. For every option, see [Commands](https://booster.apps.wayne.edu/docs/reference/commands.html). Replace `../my-app` with your app's path.

Two things hold for every recipe:

- `plan` and `run` take the same options, so you can try any flag on `plan` first. `plan` changes nothing.
- A real `run` needs the app under git with a clean working copy, plus Docker for the test gate. A dry run needs neither.

## 1. Just look first[#](https://booster.apps.wayne.edu/docs/recipes.html#1-just-look-first)

You want to know what the app is on and what moving it would take, before anything changes.

```
booster detect --path=../my-app
booster plan   --path=../my-app
booster plan   --path=../my-app --format=json
```

`detect` reads `composer.json`, `composer.lock` and `package.json` and prints the app type, PHP, Laravel, PHPUnit, Vue, Node and Boost. `plan` prints the numbered hops, the warnings and the advisory scans. `--format=json` gives the same plan as JSON for scripts. None of the three changes a file.

Not for: finding what will break on a given PHP. That is `booster scan --php=<target>`, also advisory.

## 2. Go all the way to current[#](https://booster.apps.wayne.edu/docs/recipes.html#2-go-all-the-way-to-current)

You want the newest PHP and Laravel CREED's policy supports, and you are happy to take it a hop at a time.

```
booster run --path=../my-app
```

With no flags Booster plans one hop per PHP minor, Laravel major and PHPUnit major up to the policy's newest, plus the front-end Node hops. It runs them in order, tests the app after each one, and stops at the first red gate. It never commits. A Laravel 10 app on PHP 8.1 plans 11 hops; a PHP 5.5 app with no framework plans 12 hops to PHP 8.5. The long version is in [What a run does by default](https://booster.apps.wayne.edu/docs/guides/quick-start.html#what-a-run-does-by-default).

Not for: an app with no tests and no one to click through it afterward. The gate then only proves every file still parses, which is a start and not a finish (see recipe 10).

## 3. Only get off an old PHP, or reach a specific PHP[#](https://booster.apps.wayne.edu/docs/recipes.html#3-only-get-off-an-old-php-or-reach-a-specific-php)

You want PHP 8.4, say, and you would rather not change anything else.

```
booster run --path=../my-app --to-php=8.4
booster run --path=../my-app --goal=php-74-to-84
```

`--goal=php-74-to-84` is shorthand for `--from-php=7.4 --to-php=8.4`; use it (or `--from-php`) when `composer.json` has no usable `php` constraint.

What happens depends on the app:

- **No framework:** you get the PHP hops from the current minor to the target, plus a PHPUnit step wherever PHP allows one. Nothing else moves.
- **Laravel app:** passing only `--to-php` does not freeze Laravel. The Laravel target still defaults to the newest the policy supports, so Booster plans the Laravel hops too, or stops with an error such as "Laravel 13 does not run on PHP 8.2" when that newest Laravel cannot run on the PHP you asked for. To move PHP and leave Laravel alone, name the Laravel you are on:

```
booster run --path=../my-app --to-php=8.3 --to-laravel=10
```

Booster checks the pair against what Laravel supports and refuses one it does not (for example, a PHP the Laravel you are on cannot run on). Passing any target also leaves the Node front-end hops out, with a warning (recipe 7).

Not for: getting a Laravel app current. Laravel and PHP limit each other, so the interleaved default plan is the safe path.

## 4. Reach a specific Laravel major[#](https://booster.apps.wayne.edu/docs/recipes.html#4-reach-a-specific-laravel-major)

```
booster plan --path=../my-app --to-laravel=12
booster run  --path=../my-app --to-laravel=12
```

Booster plans the Laravel hops up to that major and adds the PHP hops that Laravel needs along the way; the PHP target is still the policy's newest unless you also pass `--to-php`, and a pair Laravel does not support is an error. PHPUnit follows the newest major that runs on the target PHP, raised to at least the floor your target Laravel ships with. A target older than the app is an error.

Not for: stopping at a Laravel major that is past security support as a destination. Booster warns and treats it as a stop on the way up.

## 5. One hop at a time, for small reviewable merge requests[#](https://booster.apps.wayne.edu/docs/recipes.html#5-one-hop-at-a-time-for-small-reviewable-merge-requests)

You want each hop as its own commit, so a reviewer reads one diff at a time.

```
booster plan --path=../my-app                  # note the id of hop 1
booster run  --path=../my-app --only=<hop id>  # run that one hop
git -C ../my-app diff                          # review it
git -C ../my-app add -A && git -C ../my-app commit -m "<hop id>"   # commit; the tree is clean again
```

Then go round again: `plan` now starts from the new versions, so its first hop is the next one. `--only` must name the first hop of the current plan, because the app has to sit on that hop's starting versions; Booster says so plainly otherwise. `--until=<hop id>` is the coarser tool: it keeps that hop and every hop before it, which suits "do the PHP part today, Laravel next sprint". `--only` and `--until` cannot be combined.

Not for: hops that depend on each other more than you expect. Hops are ordered for a reason; a bigger batch with `--until` is fine when the hops in it are boring.

## 6. Preview without changing anything[#](https://booster.apps.wayne.edu/docs/recipes.html#6-preview-without-changing-anything)

```
booster run --path=../my-app --dry-run
```

Rector shows the diffs it would make, hop by hop, and nothing is written. No git or Docker is needed, and the baseline and gate are skipped. The limit: a Laravel hop needs the app's own `vendor/` to see Laravel's classes. With no `vendor/`, that hop's preview is marked incomplete (run `composer install` first). With a `vendor/` from an older Laravel, a later Laravel hop reports "Can't preview" because it needs the earlier hops applied. Preview one hop at a time with `--only`, or apply the earlier hops for real and dry-run again.

Not for: proving the app still works. A preview never runs your tests.

## 7. Backend only, or front end too[#](https://booster.apps.wayne.edu/docs/recipes.html#7-backend-only-or-front-end-too)

Node front-end hops (the Node pin, Mix to Vite, Tailwind, the Vue steps) are in the plan only when you pass no target at all. Two ways to leave them out:

```
booster run --path=../my-app --to-php=8.5          # any target drops them, with a warning
booster run --path=../my-app --skip=mix-to-vite    # or drop them by id
```

`plan` lists the left-out ids in its warning. To keep them while passing a target, add `--with-node`:

```
booster run --path=../my-app --to-php=8.5 --with-node
```

The ids are `node-pin-24`, `mix-to-vite`, `tailwind-3-to-4` and the Vue steps; `booster hops` lists every id. Skipping `vue-2-to-3` skips all the Vue steps.

Not for: skipping the Node hops and then keeping a Mix build around forever. That is a choice, not a plan.

## 8. Skip a hop you will do by hand[#](https://booster.apps.wayne.edu/docs/recipes.html#8-skip-a-hop-you-will-do-by-hand)

```
booster run --path=../my-app --skip=<hop id>
```

`--skip` can be repeated. The hop leaves the plan and Booster plans the rest around it; an unknown id is an error that tells you so. Some hops are marked `[manual: you do this one by hand]`: those are playbooks, and Booster does not do them for you. Do any hop you skip before the hops that depend on it, or the later gates will tell you.

Not for: skipping to dodge a red gate. Fix the cause, or use recipe 14.

## 9. A big app[#](https://booster.apps.wayne.edu/docs/recipes.html#9-a-big-app)

A hop that would change more than 200 files stops to ask you first. In a terminal you see the per-rule summary and a prompt; with `--no-interaction`, in CI, or with `--format=json`, the run stops at that hop instead of asking nobody.

```
booster run --path=../my-app --max-changes=500   # apply a hop up to 500 files, no prompt
booster run --path=../my-app --max-changes=0     # no limit
booster run --path=../my-app --memory-limit=4G   # more memory for Rector (default 2G, -1 for none)
```

`--max-changes` is a hard limit per hop: a hop over it changes nothing and says so. Read the summary before you raise it.

Not for: lifting the limit everywhere to make the prompt go away. The prompt is the review.

## 10. Tests and the gate[#](https://booster.apps.wayne.edu/docs/recipes.html#10-tests-and-the-gate)

The gate is your app's tests, run in the target PHP's Docker image after every hop. Options for every kind of app:

```
booster run --path=../my-app --allow-failing-baseline
booster run --path=../my-app --test-command="vendor/bin/phpunit tests/Unit"
booster run --path=../my-app --test-env=DB_CONNECTION=sqlite --test-env=APP_ENV=testing
booster run --path=../my-app --image=my-registry/php-apache:{php}
booster run --path=../my-app --skip-gate
```

- **No tests:** the gate runs a built-in `php -l` check on every PHP file outside `vendor/` and `node_modules/`. It proves the code parses, not that it works. [A smoke test for WSU apps](https://booster.apps.wayne.edu/docs/guides/smoke-test.html) gets you a real gate.
- **Tests already red:** the baseline (your tests on the current PHP) stops the run before hop 1. `--allow-failing-baseline` goes on anyway and labels later failures as pre-existing. See [The baseline](https://booster.apps.wayne.edu/docs/guides/baseline.html).
- **Custom test command:** `--test-command` replaces the default (`composer test`, else `vendor/bin/phpunit`, else the syntax check).
- **Environment for the tests:** `--test-env KEY=VALUE`, repeatable. The report names the keys only, never the values.
- **Your own image:** `--image` takes one image name, a `{php}` template, or a map such as `8.1=foo:1,8.2=bar:2`; the image needs `php` and `composer` on `PATH`, and must cover every PHP in the plan.
- **Skip the gate:** `--skip-gate` skips the baseline, the per-hop tests and the PROCTOR check.

Not for: `--skip-gate` on a real upgrade. The gate is how you learn which hop broke the app. Use it only when Docker truly cannot run, and then run the tests yourself after each hop.

## 11. Offline, or a dirty working copy[#](https://booster.apps.wayne.edu/docs/recipes.html#11-offline-or-a-dirty-working-copy)

```
booster plan --path=../my-app --no-package-check
booster run  --path=../my-app --allow-dirty
```

`--no-package-check` skips the Packagist dependency check, so `plan` works with no network (you lose the report of which direct dependencies block which hops). `--allow-dirty` lets a real run start with uncommitted changes. The default refuses so that the upgrade diff stands alone and `git checkout .` can undo it; with `--allow-dirty` that undo would also throw away your own edits, so commit or stash if you can.

Not for: running on an app that is not in git at all. Booster wants the change to be reversible; put the app under git first.

## 12. Move off wayne-cas to SESAME, or WayneUI 1 to 2[#](https://booster.apps.wayne.edu/docs/recipes.html#12-move-off-wayne-cas-to-sesame-or-wayneui-1-to-2)

Both moves are opt-in:

```
booster plan --path=../my-app --sesame
booster plan --path=../my-app --wayneui
booster run  --path=../my-app --sesame --wayneui
```

Without the flag, `plan` only warns that your app uses `wayne-cas` or WayneUI 1.x and tells you the flag to add. `--sesame` needs the app to require `cit-web-infra/wayne-cas` and a plan that reaches PHP 8.3; it lands just before the Laravel 10 to 11 hop. `--wayneui` needs the app to require `cit-web-infra/wayne-ui` and a plan that reaches Laravel 11. If the plan ends below those, the move is left out with a warning, and Booster says why.

## 13. Plan for a future date, or a different policy[#](https://booster.apps.wayne.edu/docs/recipes.html#13-plan-for-a-future-date-or-a-different-policy)

```
booster plan --path=../my-app --today=2027-06-01
booster plan --path=../my-app --policy=/path/to/policy.json
```

The default target is "the newest the policy supports on the plan's date". `--today` pins the date (format `YYYY-MM-DD`), so a plan is repeatable and you can see what a later date would ask of you. `--policy`, or the `BOOSTER_POLICY` environment variable, points at a different CREED `policy.json`; the default is the copy in the `waynestate/creed` package.

Not for: a real run on a faked date. Dates are for planning; run on the real one.

## 14. After a stop[#](https://booster.apps.wayne.edu/docs/recipes.html#14-after-a-stop)

A run stops at the first hop that fails (a red gate, a hop over the file cap, a Composer error) and reports the rest as "not run". The report names the hop, the phase (Composer or tests), and the last lines of output. The failed hop's changes stay in place so you can read them; `git checkout .` undoes them.

The usual loop:

1. Read the report for the failing hop.
2. Fix the cause: the code Rector could not fix, a package that blocks the hop, a test that depends on old behavior. `plan` shows which direct dependencies block which hops.
3. Commit the fix on its own, so it is reviewable.
4. Run again. Booster reads `composer.json` fresh each time, so it plans from the versions the app is on now and the finished hops are not repeated.

If you would rather throw the failed hop away, `git checkout .`, fix the cause, and run again. To get the hop's edits and keep working in the same tree, pass `--allow-dirty`.

Not for: re-running again and again hoping for a different answer. Booster is deterministic; the gate will be just as red.
