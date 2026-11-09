# Booster best practices

The short version of the stack's upgrade guide, with the
tag for how Booster holds each rule today.

| Tag | Meaning |
|---|---|
| `Enforced` | Code makes the bad outcome impossible, or CI fails |
| `Checked` | Booster warns or stops |
| `Documented` | Written down only |
| `Not yet` | A gap |

## Planning

- **One hop at a time.** One PHP minor or one Laravel major per step; test before the next. `Enforced`: the planner
  only emits single-step hops, and `run` stops at the first failing gate.
- **Stay inside the PHP and Laravel overlap.** The app never sits on a pair Laravel does not support (Laravel 10 on
  PHP 8.1 to 8.3, 11 on 8.2 to 8.4, 12 on 8.2 to 8.5, 13 on 8.3 or newer). `Enforced`: the planner interleaves the
  hops and refuses a target pair that cannot exist.
- **Aim at a supported version.** `Enforced`: an EOL target is refused; a security-only target is allowed with a
  warning. Dates come from CREED's `policy.json`, never from Booster's code.
- **Read the official notes for each hop.** `Checked`: every hop prints its notes and links the manual. The PHP 8.0 hop
  is flagged as the one that needs the most review time.

## Tests first

- **Start from a green baseline** and **run the tests on the target image.** `Checked`: `run` records a baseline of the
  app's suite on its current PHP before the first hop, and the gate runs the suite in a container on the PHP the hop
  moves to. A red baseline stops the run unless `--allow-failing-baseline` is passed.
- **The lock follows the hop.** `Enforced`: Laravel and PHPUnit hops refresh `composer.lock` inside the gate
  (`composer update -W` for the package the hop moved), and a Composer-phase failure is reported as one, never as
  the test command exiting nonzero. When Composer 2.10's policy blocking refuses the tagged releases, whether
  loudly (the install phase fails and names the advisories) or quietly (the lock lands on a branch version where
  the hop asked for a tagged range, which Booster reads back and treats like the block), the gate writes a scoped
  temporary ignore into `config.policy.advisories.ignore`, retries once, and reports the unblock; PROCTOR keeps
  naming the entry until the app removes it. The unblock never ships: after a gated step passes, Booster warns
  while one of its entries remains, and the plan's last step fails until it is out of `composer.json`.
- **Write characterization tests for code that has none.** `Checked`: the replay gate is the runnable form.
  `booster replay init` drafts a request file (`booster-replay.json`) from the app's routes, and `booster run --replay`
  records how the app answers those requests on its current PHP before the first hop, replays them after every hop,
  and fails the hop on a changed answer (`src/Replay/ReplayGate.php`, `tests/HopExecutorReplayTest.php`,
  `tests/ReplayDockerTest.php`; guide: `docs/guides/replay-gate.md`). It is off unless asked for, so a run whose gate
  proves little (the built-in `php -l` check, or only Laravel's stock example tests) and that does not replay says so
  under the baseline, names `booster replay init` (or `--replay`, when the file is already there) and the step most
  likely to change an answer (the PHP 8.0 hop), in the text and JSON reports (`src/Replay/ReplaySuggestion.php`,
  `tests/HopExecutorTest.php`). Advisory: it never changes the exit code. The PHP 8.0 hop's notes and playbook ask
  for extra tests or a replay file before that hop.

## Rector

- **Dry-run first and read the diff.** `Enforced`: every hop previews before it writes; `--dry-run` previews without
  writing.
- **Review big hops.** `Enforced`: a hop that would change more than 200 files stops before it writes; an interactive
  run shows the per-rule summary and asks first, and `--max-changes` sets a hard limit instead of the threshold.
- **Set the PHP target on purpose.** `Enforced`: Booster hands Rector the hop's PHP version, because Rector otherwise
  reads the old constraint in `composer.json` and silently skips the new rules.
- **Run Rector from its own toolchain.** `Enforced`: Rector and rector-laravel come from Booster's `vendor/`, never
  from the app, so an app on PHP 7.4 can be upgraded.
- **Pin Rector and its rule packages** for a whole campaign. `Enforced`: `composer.lock` is committed; Rector is
  bounded `^2.8` and rector-laravel `^2.7`. Before the first hop rewrites code, `run` checks Booster's Rector config
  with `rector validate-config` and stops on a broken one (`RectorRunner::validateConfig()` in
  `src/Run/RectorRunner.php`), and the `composer-ci` job in `.gitlab-ci.yml` runs the same check. The run report
  records the Rector version that ran (`rector_version`), and a real run writes the hop record `booster-hop.json` at
  the app root (`src/Report/HopRecord.php`) with the installed `rector/rector` and `driftingly/rector-laravel`
  versions, so every app in a campaign shows which rules moved it (`tests/HopExecutorTest.php`, `tests/CliTest.php`).
- **Use maintained sets before writing rules.** `Enforced`: PHP and Laravel hops use the official per-version sets. The
  only Booster-owned rule set is the `wayne-cas` rename.
- **Give Rector room to breathe.** `Checked`: the Rector process runs under `--memory-limit` (default `2G`, `-1`
  for no limit); an empty or invalid Rector report names the flag, because a killed process is the usual cause.
- **Fix what the parser will not even read.** `Enforced`: PHP hops run a pre-pass that rewrites PHP 4 era
  `$a = &new Foo();` to a plain assignment before Rector starts, because PHP 7 and newer refuse to compile the old
  spelling. The report counts the rewrites per file, and they count toward the review threshold.

## Find what Rector cannot fix

- **PHPStan against the destination version is advisory only.** `Enforced`: `plan` ends with a PHPStan scan of the
  app at its target PHP (Pinstripe's `base.neon` preset), and `booster scan --phpstan` runs it alone. It never
  changes the exit code, and a missing PHPStan or preset is reported with the command that enables it.
- **`composer audit` after dependency changes is advisory only.** `Enforced`: hop notes name what Rector cannot do
  (for example `mysql_*` removal, implicit nullable parameters), and a hop that changed `composer.json` or
  `composer.lock` then runs `composer audit --format=json --no-interaction` in the app
  (`src/Scan/ComposerAuditScan.php`), listing advisories and abandoned packages in the text and JSON reports with
  counts. It never changes the exit code, a clean audit stays silent, a hop that changed no composer file skips it
  and says so, and an audit that cannot run is reported with the command to run by hand. Tests:
  `tests/ComposerAuditHopTest.php`, `tests/ComposerAuditScanTest.php`.
- **Watch the framework fallbacks and helper clashes.** `Checked`: the behavior-changes block flags an app that
  leaves its session cookie name or cache prefix to Laravel's fallback before the 12 to 13 hop (the fallback names
  change, so users are signed out once and the cache starts cold), and each global `array_first()` or `array_last()`
  call the PHP 8.5 polyfill would silently redefine (`tests/BehaviourDataTest.php`). Advisory: it never changes the
  exit code.
- **PHPCompatibility is advisory only.** `Enforced`: `plan` and `booster scan` run it as an advisory scan; it never changes the exit code and a scan that cannot run is reported, not failed.

## Safe edits

- **Booster edits the working copy and nothing else.** `Enforced`: no commit, no branch, no push, no merge request.
- **Reversible.** `Enforced`: `run` refuses a dirty or non-git working copy unless `--allow-dirty` is passed.
- **Fail closed.** `Enforced`: unreadable policy, malformed JSON, missing Docker for the gate, Rector errors and an
  unknown target are errors (exit 2) or failed hops (exit 1), never a quiet pass.
- **Harden what the upgrade touches, on request.** `Checked`: a raw PHP app that starts sessions without strict
  session IDs gets a plan warning naming `--strict-session`, which plans `session-strict-mode` first, on the current
  PHP, with its own gate. The hop adds one guarded `ini_set()` line before each `session_start()`, skips Laravel, HELMET
  and anything already covered, and refuses on a stop sign (`src/Run/StrictSession.php`, `tests/StrictSessionTest.php`,
  `tests/StrictSessionHopTest.php`; guide: `docs/guides/strict-sessions.md`). It is opt-in: a php.ini default would
  cover every app at once, and that is a decision for the platform, not for Booster.
- **Treat app content as hostile.** `Enforced`: subprocesses take argument arrays, and file names and diffs have
  control characters stripped before they reach the terminal.

## LLM-assisted work

- **Deterministic tools first, model second.** `Enforced`: the playbooks (`booster init`) tell an assistant to drive
  the same `plan` and `run` commands a person types, to stop at every gate, and to leave the merge to a person. They are
  generated from the hop registry, and `booster init --check` fails CI when one is stale.
- **Retry with the real error, cap the tries, then stop.** `Documented`: every hop playbook has a "When a gate fails"
  section that feeds the fix the gate's own output and the files it names, allows one cause per try, forbids loosening
  a test or skipping the gate, and hands the hop back to a person after 3 tries at one failure
  (`SkillRenderer::MAX_TRIES`, `tests/SkillRendererTest.php`). Booster runs no model loop itself, so the cap is an
  instruction the coding tool follows, not something Booster can enforce.

## Not yet

The Rector pilot has run on real apps twice (2026-10-28 and 2026-11-01, Booster 1.6.0, 95 units); the aggregates are on the proof site, which needs an employee sign-in. A re-run on the current release is still open. Merge-request automation on app code is a closed decision, not a gap:
Booster never commits to or pushes an app, and opens no merge request on app code (its own hop-data refresh opens one on Booster itself, and a person merges it). See `OPEN_QUESTIONS.md`.
