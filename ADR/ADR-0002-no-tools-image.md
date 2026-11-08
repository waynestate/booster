# ADR-0002: No prepared Rector tools image

Status: Accepted (2026-10-28)

## Context

- The original plan (row BOOSTER-16 in the coordination repo's research notes) asked for a prepared tools image:
  PHP 8.3 or higher with common extensions and pinned `rector/rector` plus `driftingly/rector-laravel`, the app's
  code mounted separately, so Rector could rewrite a mounted PHP 7.4 app without adding Rector to its
  `composer.json`.
- Booster already runs on PHP 8.3 or newer and already depends on Rector: `rector/rector` and
  `driftingly/rector-laravel` are runtime dependencies of `waynestate/booster`, and the phar bundles them.
- `docker/Dockerfile` exists for a different job. It is the target image, built on demand as
  `booster-target:<php>-<stamp>`, in which the app's own tests run on the destination PHP.

## Decision

1. There is no separate tools image. Rector runs from Booster's own vendor directory (or, when running from the
   phar, from a temporary copy of it) on the machine's PHP 8.3+, against the app checked out on disk.
2. Rector never comes from the app's `composer.json`: an app on PHP 7.4 can be rewritten by a Rector running on
   PHP 8.3+, and its manifest gains only the constraint changes Booster intends.
3. The only image Booster builds is the target image (`docker/Dockerfile`), used by the per-hop test gate. Rector
   rides in the booster's own fuel tank.

## Consequences

- One fewer image to pin, build and release. Rector upgrades ship with Booster releases and ride the phar, so the
  tool version and the hop recipes always agree.
- The machine that runs `booster run` needs PHP 8.3+ and, for the gate, a Docker daemon. Without a daemon the gate
  fails closed, and `--skip-gate` is the way out.
- The app's working tree stays clean of tooling: no Rector dependency, no Rector config, no vendor noise.

## Sources

- `src/Run/RectorRunner.php:14-22` (docblock: own vendor directory, phar temp copy)
- `docker/Dockerfile:1-2` (the target image, built on demand)
- `composer.json:19-20` (`rector/rector` and `driftingly/rector-laravel` as runtime deps)
- `README.md:66-67` (PHP 8.3 on the machine, Rector from Booster's own vendor)
- `MAINTAINING.md:11-17` (runtime dependencies; some hops run tools in Docker)
- Coordination repo, not part of this tree: `research/backlog-booster-rollcall.md` row BOOSTER-16, and
  `research/backlog-recheck-backlog-booster-rollcall-1107.md` (what was built instead)
