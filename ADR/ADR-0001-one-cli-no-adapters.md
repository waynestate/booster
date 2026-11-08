# ADR-0001: One CLI, no adapters, no artisan command

Status: Accepted (2026-10-28)

## Context

- The original plan for Booster (rows BOOSTER-6 and BOOSTER-7 in the coordination repo's research notes) asked for
  two adapters: a raw-PHP adapter (plain functions or a tiny front controller) and a Laravel adapter (a service
  provider plus `booster:*` artisan commands), following the stack's core-plus-adapters shape.
- In use, Booster is a tool that works on an app from the outside: it reads the app's manifest and configuration,
  detects which of the five stack shapes the app has, and plans and runs hops against it. Nothing inside the app
  needs to call into Booster.
- The app being upgraded may run on any PHP from 5.5 up, while Booster itself needs PHP 8.3 or newer. Code shipped
  inside the app (a service provider, an artisan command) would have to run on the old PHP first, which defeats the
  point of upgrading it.

## Decision

1. Booster ships exactly one entry point: the Symfony Console CLI `bin/booster`, also bundled as a single-file phar.
   There are no adapter classes, no service provider and no artisan command, and `composer.json` requires no
   Laravel package.
2. Every app shape uses the same commands (`detect`, `hops`, `init`, `plan`, `replay`, `run`, `scan`), pointed at
   the app with `--path`. The contract suite drives the example apps through that one CLI.
3. Booster never commits to or pushes an app: it edits the working tree and leaves review to the app's own process.

## Consequences

- One surface to build, test and release for all five shapes; no per-framework maintenance.
- The app's PHP floor never limits Booster: Rector and the scans run from Booster's own vendor tree on the machine's
  PHP 8.3+, so a 7.4 app upgrades without gaining anything it cannot run yet.
- There is no in-app integration (`php artisan booster:plan` does not exist). People and coding agents drive
  Booster from the outside, including through the six prompts on the front page.
- The stack's core-plus-adapters rule does not apply to Booster: it is a standalone tool, not a library an app
  embeds. Inferred: this is a deliberate exception to the stack shape, recorded here rather than in the stack ADR.

## Sources

- `composer.json:17` (php ^8.3), `composer.json:82-84` (the single bin)
- `README.md:66-67` (PHP 8.3 on the machine, Rector from Booster's own vendor)
- `MAINTAINING.md:9-13` (the CLI and the phar as the shipped form)
- `docs/reference/commands.md:11-19` (the command table, generated from the tool)
- `tests/ContractTest.php` (the contract suite over the example apps)
- `OPEN_QUESTIONS.md:18` (question 9: Booster never commits to or pushes an app)
- `site/index.html:878` (works for all five shapes)
- Coordination repo, not part of this tree: `research/backlog-booster-rollcall.md` rows BOOSTER-6 and BOOSTER-7,
  and `research/backlog-recheck-backlog-booster-rollcall-1107.md` (what was built instead)
