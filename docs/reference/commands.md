---
title: Commands
description: Every Booster command and option, generated from the tool itself.
---

# Commands

This page lists every command and option Booster takes; the table is generated from the tool by the docs build. With no flags, `detect`, `plan` and `run` start from what Booster finds in the app and climb to the newest PHP and Laravel the policy supports; see [What a run does by default](../guides/quick-start.md#what-a-run-does-by-default).

<!-- generated:commands -->
| Command | What it does |
| --- | --- |
| `booster detect` | Say which of the five stack permutations an app is, and its PHP, Laravel and Vue versions. |
| `booster hops` | List every hop BOOSTER knows. |
| `booster init` | Write the coding-tab playbooks (.agents/skills). With --check, fail when they are stale. |
| `booster plan` | Show the ordered hops from the app to a supported version. Changes nothing. |
| `booster replay` | The replay gate on its own: init drafts booster-replay.json, record saves how the app answers now, check fails when an answer changed. |
| `booster run` | Run the plan: Rector, composer.json edits and a test gate per hop. Edits the working copy only. |
| `booster scan` | Advisory scans: show what will break on a PHP version, and what PHPStan says there. Changes nothing. |

### `booster detect`

Say which of the five stack permutations an app is, and its PHP, Laravel and Vue versions.

Usage: `booster detect [--path PATH] [--format FORMAT]`

| Option | Meaning | Default |
| --- | --- | --- |
| `--path=VALUE` | Path to the application. | `.` |
| `--format=VALUE` | Output format (text, json). | `text` |
| `--silent` | Do not output any message |  |

### `booster hops`

List every hop BOOSTER knows.

Usage: `booster hops [--format FORMAT]`

| Option | Meaning | Default |
| --- | --- | --- |
| `--format=VALUE` | Output format (text, json). | `text` |
| `--silent` | Do not output any message |  |

### `booster init`

Write the coding-tab playbooks (.agents/skills). With --check, fail when they are stale.

Usage: `booster init [--path PATH] [--dir DIR] [--check]`

| Option | Meaning | Default |
| --- | --- | --- |
| `--path=VALUE` | Path to the application. | `.` |
| `--dir=VALUE` | Skills directory (default: <path>/.agents/skills). |  |
| `--check` | Change nothing; exit 1 when a playbook is missing or out of date. |  |
| `--silent` | Do not output any message |  |

### `booster plan`

Show the ordered hops from the app to a supported version. Changes nothing.

Usage: `booster plan [--path PATH] [--format FORMAT] [--policy POLICY] [--today TODAY] [--goal GOAL] [--from-php FROM-PHP] [--to-php TO-PHP] [--from-laravel FROM-LARAVEL] [--to-laravel TO-LARAVEL] [--to-phpunit TO-PHPUNIT] [--with-node] [--sesame] [--wayneui] [--vendored] [--skip SKIP] [--no-package-check] [--until UNTIL] [--only ONLY] [--no-scan]`

| Option | Meaning | Default |
| --- | --- | --- |
| `--path=VALUE` | Path to the application. | `.` |
| `--format=VALUE` | Output format (text, json). | `text` |
| `--policy=VALUE` | Path to CREED's policy.json (default: BOOSTER_POLICY, then the waynestate/creed package). |  |
| `--today=VALUE` | Override today's date (YYYY-MM-DD). |  |
| `--goal=VALUE` | Shorthand such as php-74-to-84: sets --from-php and --to-php. |  |
| `--from-php=VALUE` | The PHP version the app runs on (major.minor). |  |
| `--to-php=VALUE` | Target PHP version (default: the newest the policy supports). |  |
| `--from-laravel=VALUE` | The Laravel major the app runs on. |  |
| `--to-laravel=VALUE` | Target Laravel major (default: the newest the policy supports). |  |
| `--to-phpunit=VALUE` | Target PHPUnit major (default: the newest that runs on the target PHP). |  |
| `--with-node` | Include the Node front-end hops (Node pin, Mix to Vite, Vite major, Tailwind, TypeScript, Vue) even when you passed a target version |  |
| `--sesame` | Include the wayne-cas to SESAME swap. |  |
| `--wayneui` | Include the WayneUI 1.x to 2.x move. |  |
| `--vendored` | Include the move of hand-copied Doctrine 2, Smarty 3 and Slim 1 out of vendor/ and onto Composer. |  |
| `--skip=VALUE` | Hop id to leave out (repeatable). |  |
| `--no-package-check` | Skip the Packagist dependency check (for offline use). |  |
| `--until=VALUE` | Cut the plan after this hop id: that step and everything before it stay. |  |
| `--only=VALUE` | Plan and run just this one hop id; the app must sit on the hop's starting versions. |  |
| `--no-scan` | Skip the advisory scans (PHPCompatibility and PHPStan). |  |
| `--silent` | Do not output any message |  |

### `booster replay`

The replay gate on its own: init drafts booster-replay.json, record saves how the app answers now, check fails when an answer changed.

Usage: `booster replay [--path PATH] [--format FORMAT] [--file FILE] [--recording RECORDING] [--php PHP] [--image IMAGE] [--out OUT] [--no-install] [--force] [--] <action>`

| Argument | Required | Meaning |
| --- | --- | --- |
| `action` | yes | init, record or check. |

| Option | Meaning | Default |
| --- | --- | --- |
| `--path=VALUE` | Path to the application. | `.` |
| `--format=VALUE` | Output format (text, json). | `text` |
| `--file=VALUE` | The request file, relative to the app or absolute. | `booster-replay.json` |
| `--recording=VALUE` | Where record saves the answers and check reads them, relative to the app or absolute. | `booster-replay.recording.json` |
| `--php=VALUE` | The PHP version to serve the app on (default: the version the app declares). |  |
| `--image=VALUE` | Serve the app in this Docker image instead of booster-target, in the same three forms as run --image. |  |
| `--out=VALUE` | Folder for the transcripts (default: a new booster-replay-<app>-<time> folder in the system temp folder). |  |
| `--no-install` | Do not run composer install first; use the vendor/ the app already has. |  |
| `--force` | init only: overwrite an existing request file. |  |
| `--silent` | Do not output any message |  |

### `booster run`

Run the plan: Rector, composer.json edits and a test gate per hop. Edits the working copy only.

Usage: `booster run [--path PATH] [--format FORMAT] [--policy POLICY] [--today TODAY] [--goal GOAL] [--from-php FROM-PHP] [--to-php TO-PHP] [--from-laravel FROM-LARAVEL] [--to-laravel TO-LARAVEL] [--to-phpunit TO-PHPUNIT] [--with-node] [--sesame] [--wayneui] [--vendored] [--skip SKIP] [--no-package-check] [--until UNTIL] [--only ONLY] [--dry-run] [--max-changes MAX-CHANGES] [--test-env TEST-ENV] [--memory-limit MEMORY-LIMIT] [--skip-gate] [--image IMAGE] [--allow-failing-baseline] [--allow-dirty] [--test-command TEST-COMMAND] [--proctor-command PROCTOR-COMMAND] [--proctor-advisory] [--replay [REPLAY]] [--replay-out REPLAY-OUT]`

| Option | Meaning | Default |
| --- | --- | --- |
| `--path=VALUE` | Path to the application. | `.` |
| `--format=VALUE` | Output format (text, json). | `text` |
| `--policy=VALUE` | Path to CREED's policy.json (default: BOOSTER_POLICY, then the waynestate/creed package). |  |
| `--today=VALUE` | Override today's date (YYYY-MM-DD). |  |
| `--goal=VALUE` | Shorthand such as php-74-to-84: sets --from-php and --to-php. |  |
| `--from-php=VALUE` | The PHP version the app runs on (major.minor). |  |
| `--to-php=VALUE` | Target PHP version (default: the newest the policy supports). |  |
| `--from-laravel=VALUE` | The Laravel major the app runs on. |  |
| `--to-laravel=VALUE` | Target Laravel major (default: the newest the policy supports). |  |
| `--to-phpunit=VALUE` | Target PHPUnit major (default: the newest that runs on the target PHP). |  |
| `--with-node` | Include the Node front-end hops (Node pin, Mix to Vite, Vite major, Tailwind, TypeScript, Vue) even when you passed a target version |  |
| `--sesame` | Include the wayne-cas to SESAME swap. |  |
| `--wayneui` | Include the WayneUI 1.x to 2.x move. |  |
| `--vendored` | Include the move of hand-copied Doctrine 2, Smarty 3 and Slim 1 out of vendor/ and onto Composer. |  |
| `--skip=VALUE` | Hop id to leave out (repeatable). |  |
| `--no-package-check` | Skip the Packagist dependency check (for offline use). |  |
| `--until=VALUE` | Cut the plan after this hop id: that step and everything before it stay. |  |
| `--only=VALUE` | Plan and run just this one hop id; the app must sit on the hop's starting versions. |  |
| `--dry-run` | Show the diffs Rector would make and change nothing. |  |
| `--max-changes=VALUE` | Hard limit on files changed per hop, with no prompt (0 = no limit). Without it, a hop over 200 files asks first, or stops when not interactive. |  |
| `--test-env=VALUE` | Environment variable (KEY=VALUE) passed to the baseline and every gate container with -e; repeat it. Values never appear in the report. |  |
| `--memory-limit=VALUE` | Memory limit for the Rector process (for example 2G), or -1 for no limit. | `2G` |
| `--skip-gate` | Do not run the tests in the target image after each hop, or the PROCTOR check at the end. |  |
| `--image=VALUE` | Run the gate in this Docker image instead of building booster-target (it needs php and composer on PATH): one name for every PHP version, a {php} template such as registry/php-apache:{php}, or a map such as 8.0=foo:1,8.1=bar:2. |  |
| `--allow-failing-baseline` | Continue even when the baseline (the app's tests on its current PHP) fails; later gate failures are labeled as pre-existing. |  |
| `--allow-dirty` | Run even when the working copy has uncommitted changes. |  |
| `--test-command=VALUE` | Command that runs the app tests inside the image (default: composer test, else vendor/bin/phpunit). |  |
| `--proctor-command=VALUE` | Extra command run in the image after the tests, for example a PROCTOR check. |  |
| `--proctor-advisory` | Report the PROCTOR result but do not fail the run on it. |  |
| `--replay=VALUE` | Also gate on request replay: record how the app answers before the first hop, replay after each hop, fail on a changed answer. Takes the request file (default: booster-replay.json). |  |
| `--replay-out=VALUE` | Folder for the replay transcripts (default: a new booster-replay-<app>-<time> folder in the system temp folder). |  |
| `--silent` | Do not output any message |  |

### `booster scan`

Advisory scans: show what will break on a PHP version, and what PHPStan says there. Changes nothing.

Usage: `booster scan [--path PATH] [--php PHP] [--removals] [--format FORMAT] [--phpstan]`

| Option | Meaning | Default |
| --- | --- | --- |
| `--path=VALUE` | Path to the application. | `.` |
| `--php=VALUE` | The PHP version to ask about (major.minor). |  |
| `--removals` | List uses of functions and constructs removed in PHP up to --php, instead of the PHPCompatibility scan. |  |
| `--format=VALUE` | Output format (text, json). | `text` |
| `--phpstan` | Also run the PHPStan scan (Pinstripe preset) at the same PHP version. |  |
| `--silent` | Do not output any message |  |

### Options every command accepts

| Option | Meaning | Default |
| --- | --- | --- |
| `-h`, `--help` | Display help for the given command. When no command is given display help for the list command |  |
| `-q`, `--quiet` | Only errors are displayed. All other output is suppressed |  |
| `-v|-vv|-vvv`, `--verbose` | Increase the verbosity of messages: 1 for normal output, 2 for more verbose output and 3 for debug |  |
| `-V`, `--version` | Display this application version |  |
| `--ansi` | Force (or disable --no-ansi) ANSI output |  |
| `--no-ansi` | Negate the "--ansi" option |  |
| `-n`, `--no-interaction` | Do not ask any interactive question |  |
<!-- /generated -->

Example: an app moving from Laravel 8 to 13 takes the climb in parts. `booster plan --path=../my-app --until=laravel-8-to-9` keeps every hop up to and including the move to Laravel 9, and `booster run` with the same flag applies exactly those hops.

## Exit codes

Exit codes: `0` pass, `1` the baseline failed, a hop was capped, errored or failed its gate, or the PROCTOR check
found new findings, `2` bad usage (bad option, unreadable policy, broken `composer.json`, a target the policy does
not know).
