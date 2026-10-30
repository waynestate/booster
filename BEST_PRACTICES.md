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
- **Write characterization tests for code that has none.** `Documented`: the playbooks ask for it before the PHP 8.0
  hop.

## Rector

- **Dry-run first and read the diff.** `Enforced`: every hop previews before it writes; `--dry-run` previews without
  writing.
- **Cap the first change.** `Enforced`: a hop that would change more than `--max-changes` files stops before it
  writes.
- **Set the PHP target on purpose.** `Enforced`: Booster hands Rector the hop's PHP version, because Rector otherwise
  reads the old constraint in `composer.json` and silently skips the new rules.
- **Run Rector from its own toolchain.** `Enforced`: Rector and rector-laravel come from Booster's `vendor/`, never
  from the app, so an app on PHP 7.4 can be upgraded.
- **Pin Rector and its rule packages** for a whole campaign. `Enforced`: `composer.lock` is committed; Rector is
  bounded `^2.8` and rector-laravel `^2.7`.
- **Use maintained sets before writing rules.** `Enforced`: PHP and Laravel hops use the official per-version sets. The
  only Booster-owned rule set is the `wayne-cas` rename.
- **Give Rector room to breathe.** `Checked`: the Rector process runs under `--memory-limit` (default `2G`, `-1`
  for no limit); an empty or invalid Rector report names the flag, because a killed process is the usual cause.
- **Fix what the parser will not even read.** `Enforced`: PHP hops run a pre-pass that rewrites PHP 4 era
  `$a = &new Foo();` to a plain assignment before Rector starts, because PHP 7 and newer refuse to compile the old
  spelling. The report counts the rewrites per file, and they count toward the cap.

## Find what Rector cannot fix

- **PHPStan against the destination version is advisory only.** `Enforced`: `plan` ends with a PHPStan scan of the
  app at its target PHP (Pinstripe's `base.neon` preset), and `booster scan --phpstan` runs it alone. It never
  changes the exit code, and a missing PHPStan or preset is reported with the command that enables it.
- **`composer audit` after dependency changes.** `Documented`: hop notes name what Rector cannot do (for example
  `mysql_*` removal, implicit nullable parameters). `Not yet`: Booster does not run `composer audit` inside the app
  for you; pass it with `--proctor-command`.
- **PHPCompatibility is advisory only.** `Enforced`: `plan` and `booster scan` run it as an advisory scan; it never changes the exit code and a scan that cannot run is reported, not failed.

## Safe edits

- **Booster edits the working copy and nothing else.** `Enforced`: no commit, no branch, no push, no merge request.
- **Reversible.** `Enforced`: `run` refuses a dirty or non-git working copy unless `--allow-dirty` is passed.
- **Fail closed.** `Enforced`: unreadable policy, malformed JSON, missing Docker for the gate, Rector errors and an
  unknown target are errors (exit 2) or failed hops (exit 1), never a quiet pass.
- **Treat app content as hostile.** `Enforced`: subprocesses take argument arrays, and file names and diffs have
  control characters stripped before they reach the terminal.

## LLM-assisted work

- **Deterministic tools first, model second.** `Enforced`: the playbooks (`booster init`) tell an assistant to drive
  the same `plan` and `run` commands a person types, to stop at every gate, and to leave the merge to a person. They are
  generated from the hop registry, and `booster init --check` fails CI when one is stale.

## Not yet

Rector pilot measurements on real apps. Merge-request automation on app code is a closed decision, not a gap:
Booster never commits to or pushes an app, and opens no merge request on app code (its own hop-data refresh opens one on Booster itself, and a person merges it). See `OPEN_QUESTIONS.md`.
