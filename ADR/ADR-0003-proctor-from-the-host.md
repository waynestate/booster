# ADR-0003: PROCTOR runs from the host toolchain

Status: Accepted (2026-10-29)

## Context

- The original plan (row BOOSTER-15 in the coordination repo's research notes) asked for a target-image runner that
  ran the app's tests plus PROCTOR inside the image.
- PROCTOR measures an app against CREED's policy. Running it inside the target container would mean installing
  `waynestate/proctor` into every app being measured, or carrying it in the target image, which is built from a
  plain `php:<version>-cli` base.
- Booster already depends on `waynestate/proctor`, and the policy it checks comes from the `waynestate/creed`
  package Booster installs. Measuring from the host reuses both.

## Decision

1. PROCTOR runs from Booster's own vendor directory on the host (PHP 8.3+), with `--php-runtime` set to the version
   being measured: a baseline before the first hop on the starting PHP, and a final check after the last hop on the
   destination PHP.
2. `ProctorComparison` compares the two runs and blocks only on findings new against the baseline. A tripped
   baseline falls back to blocking on any failure, and `--proctor-advisory` makes the gate report-only.
3. The target-image runner keeps its narrower job: run the app's own tests in the `booster-target` image after each
   hop. An optional `--proctor-command` can still run extra commands, such as a PROCTOR check, inside the image
   after the tests.

How they fit together today: the container gate answers "do the app's tests pass on the destination PHP" after each
hop, and the host gate answers "did the upgrade introduce new policy findings" once, at the end. Both are skipped by
`--skip-gate`, and exit code 1 covers new PROCTOR findings.

## Consequences

- No PROCTOR install in the app and no PROCTOR in the target image: an app on any PHP can be measured by a PROCTOR
  running on PHP 8.3+.
- The host must already run PHP 8.3+, which Booster requires anyway.
- Pre-existing findings never block an upgrade; only what the upgrade introduces does.

## Sources

- `src/Run/ProctorRunner.php:12-20` (docblock: own vendor directory, the final gate), `:40-50` (the exact
  `proctor check` invocation on the host PHP)
- `src/Run/HopExecutor.php:207,259-262,285-329` (baseline, final gate, comparison)
- `src/Run/TargetImageRunner.php:10-18` (docblock: the container test gate)
- `docs/reference/commands.md:139,144-145,182` (`--skip-gate`, `--proctor-command`, `--proctor-advisory`, exit codes)
- `CHANGELOG.md:336-337` (the policy gate: baseline before the first hop, final after the last)
- `composer.json:26` (`waynestate/proctor` as a runtime dep)
- Coordination repo, not part of this tree: `research/backlog-booster-rollcall.md` row BOOSTER-15, and
  `research/backlog-recheck-backlog-booster-rollcall-1107.md` (what was built instead)
