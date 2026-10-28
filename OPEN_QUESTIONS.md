# BOOSTER: open questions

BOOSTER 0.1 is built and `composer ci` passes. What follows needs a person.

## Questions

| # | Question | Who can answer | Blocks | Default built |
|---|---|---|---|---|
| 1 | Where does BOOSTER read `policy.json` from once CREED is on Packagist? | CREED owner | Removing the bundled snapshot | `--policy`, then `BOOSTER_POLICY`, then `resources/policy.json`, a copy of CREED's file (schema_version 1). The snapshot goes stale; refresh it on each CREED release. |
| 2 | Should the bundled snapshot be refreshed by a script, or replaced by `require waynestate/creed`? | CREED owner, Pantry owner | The snapshot going stale | Manual copy. |
| 3 | What Composer vendor name will the packages publish under (`waynestate/booster`)? | Pantry owner | `composer require` for users | `waynestate/booster` in `composer.json`; install from the VCS repository until then. |
| 4 | PHP test matrix: 8.3, 8.4, 8.5 or 8.3 and 8.5 only? | Stack lead | Nothing | 8.3, 8.4 and 8.5, as decided. |
| 5 | Is PHPCompatibility wanted as an advisory signal? | Stack lead | A second scan for what Rector misses | Left out of 0.1. The design guide says advisory only. |
| 6 | Larastan or plain PHPStan for Laravel apps, and for which Laravel versions? | Stack lead, Pinstripe owner | A "find what Rector cannot fix" gate | Neither is run by BOOSTER; `--proctor-command` can carry any extra check. |
| 7 | Is the Vue 2 to 3 hop needed in 0.1, and how many Vue 2 apps still run? | App owners, fleet owner | An automated Vue hop | A playbook hop only (`vue-2-to-3`), planned automatically for Vue 2 apps. |
| 8 | Which PHP and Laravel pairs does the shared MySQL server (5.6 per the stack notes) allow? Laravel's current database floor is above 5.6. | Citadel platform owner | Safe Laravel hops on Citadel | Hop notes tell the reviewer to check the database floor. BOOSTER does not look at the database. |
| 9 | May BOOSTER ever open merge requests, and under what bot-branch, label and opt-out rules? | Stack lead, app owners | MR automation | BOOSTER never commits, pushes or opens merge requests. |
| 10 | Is `cas-to-sesame` wanted as an automatic step? It changes how people sign in. | SESAME owner, security | Planning it by default | Opt-in with `--sesame`. A warning prints when `wayne-cas` is detected. |
| 11 | Which package names identify the old CAS client (only `cit-web-infra/wayne-cas` is detected now)? | SESAME owner | Detecting forks and renamed copies | `cit-web-infra/wayne-cas`, classes under `WSU\WayneCas\`. |
| 12 | Which Composer release introduced the `config.policy` key the design guide mentions for `composer audit`? | CREED owner | Policy-driven audit settings | Not used. |
| 13 | A first measured Rector pilot on a few real apps: what file cap and what time budget are right? | Stack lead | Tuning `--max-changes` | 50 files per hop. |
| 14 | `composer audit` reports `symplify/rule-doc-generator-contracts` (a Rector dependency) as abandoned. Accept it? | Stack lead | `composer ci` | `config.audit.abandoned` is `report`: advisories still fail the build, an abandoned transitive does not. Revisit when Rector drops it. |
| 15 | Where does the GitHub PREFLIGHT reusable workflow get its release tag? | PREFLIGHT owner | Replacing the commit pin | Pinned to the same commit SHA as the siblings, with the same comment. |

## Missing configuration

| Item | Where it comes from | Placeholder used |
|---|---|---|
| `BOOSTER_POLICY` (optional) | CREED's released `policy.json` | Empty in `.env.example`; the bundled snapshot is used |
| Docker daemon on the machine that runs `booster run` | Developer machine or runner | None; without it the gate fails closed and `--skip-gate` is the way out |
| Network access to Docker Hub for `php:<version>-cli` and `composer:2.10.3` | Developer machine or runner | None; a mirror would need the `FROM` lines in `docker/Dockerfile` changed |
| PREFLIGHT components `@0.1.0` on GitLab, and the pinned PREFLIGHT workflow on GitHub | PREFLIGHT | As in the sibling projects |
| The app's `vendor/` for Laravel hops | `composer install` in the app | None |

BOOSTER holds no secrets and calls no API.

## Not built yet

- **PHAR build** (`ADR-0009`). 0.1 ships `vendor/bin/booster` and `bin/booster` only. A PHAR needs a packaging decision
  (box or similar) and a way to find `config/rector.php` and `docker/` inside it.
- **`booster mcp`**, the read-only tool server. Waiting on the shared MCP conventions.
- **PROCTOR as a built-in gate step.** PROCTOR is not on Packagist and needs `--policy`; `--proctor-command` runs it today.
- **PHPCompatibility and PHPStan-on-the-target-version** as advisory scans.
- **Real wayne-cas migration.** The recipe renames classes and swaps the requirement; it does not move
  `config/wayne-cas.php`, the user model trait methods or Duo settings, and it was not run against a real app.
- **Rector pilot numbers on real apps.** The examples prove the plumbing, not the hit rate.
- **Vue 2 to 3 automation** and **Inertia adapter checks** per Laravel hop.
- **Laravel Boost-aware prompts** in the playbooks.
- **Custom WSU Rector rules** beyond the CAS rename (for example the legacy patterns in the oldest apps).
- **Hops for Laravel older than 8 and PHP older than 5.6**, and PHP-hop checks of extension removals.
- **Merge-request automation** (bot branch, label, opt-out): deliberately absent.
- **The static site** (`site/`): an Opus agent builds it next; `site/brand/` is in place.
- **A real Docker gate run on a real app.** The gate is tested with a fake process runner, and the Dockerfile builds
  and runs (`booster-target:8.4`), but no full gate loop ran end to end.
