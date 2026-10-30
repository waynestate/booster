# Booster: open questions

Booster 1.0 is built and `composer ci` passes. Fourteen of the fifteen questions below are closed; question 8 stays
open until the Citadel platform owner answers.

## Questions

| # | Question | Who can answer | Blocks | Answer | Status |
|---|---|---|---|---|---|
| 1 | Where does Booster read `policy.json` from now that CREED is on Packagist (`waynestate/creed`)? | CREED owner | Removing the bundled copy | `--policy`, then `BOOSTER_POLICY`, then the copy in the `waynestate/creed` package. | Closed: `require waynestate/creed ^0.1`; there is no bundled copy left to go stale. |
| 2 | Should the bundled copy be refreshed by a script, or replaced by `require waynestate/creed`? | CREED owner | The copy going stale | Replaced: `composer update waynestate/creed` is the whole refresh. | Closed: the package requirement won. |
| 3 | What Composer vendor name will the packages publish under (`waynestate/booster`)? | Stack lead | `composer require` for users | `waynestate/booster` is on Packagist. The README still installs from a clone, since Booster runs from its own folder. | Closed: `waynestate/booster`. |
| 4 | PHP test matrix: 8.3, 8.4, 8.5 or 8.3 and 8.5 only? | Stack lead | Nothing | 8.3, 8.4 and 8.5. | Closed: all three. |
| 5 | Is PHPCompatibility wanted as an advisory signal? | Stack lead | A second scan for what Rector misses | Built as an advisory scan: `plan` ends with it and `booster scan` runs it alone. It never changes the exit code. | Closed: yes, advisory only. |
| 6 | Larastan or plain PHPStan for Laravel apps, and for which Laravel versions? | Stack lead, Pinstripe owner | A "find what Rector cannot fix" gate | Plain PHPStan at the destination PHP, with Pinstripe's preset, as an advisory scan. | Closed: plain PHPStan, advisory. |
| 7 | Is the Vue 2 to 3 hop needed in 0.1, and how many Vue 2 apps still run? | App owners, fleet owner | An automated Vue hop | Staged hops, planned automatically for Vue 2 apps: `vue-26-to-27`, `vue-27-to-compat`, `vue-compat-codemod`, with the `vue-2-to-3` playbook standing in for all three. | Closed: staged. |
| 8 | Which PHP and Laravel pairs does the shared MySQL server (5.6 per the stack notes) allow? Laravel's current database floor is above 5.6. | Citadel platform owner | Safe Laravel hops on Citadel | Hop notes tell the reviewer to check the database floor. Booster does not look at the database. | Open. |
| 9 | May Booster ever open merge requests, and under what bot-branch, label and opt-out rules? | Stack lead, app owners | MR automation | No merge requests on app code: Booster never commits to or pushes an app. Its own scheduled hop-data refresh opens a merge request on Booster itself, and a person merges it. | Closed: deliberately absent. |
| 10 | Is `cas-to-sesame` wanted as an automatic step? It changes how people sign in. | SESAME owner, security | Planning it by default | Opt-in with `--sesame`; a warning prints when `wayne-cas` is detected without the flag. | Closed: opt-in. |
| 11 | Which package names identify the old CAS client (only `cit-web-infra/wayne-cas` is detected now)? | SESAME owner | Detecting forks and renamed copies | `cit-web-infra/wayne-cas`, classes under `WSU\WayneCas\`. | Closed: that one. |
| 12 | Which Composer release introduced the `config.policy` key the design guide mentions for `composer audit`? | CREED owner | Policy-driven audit settings | Not used. | Closed: not used. |
| 13 | A first measured Rector pilot on a few real apps: what file cap and what time budget are right? | Stack lead | Tuning `--max-changes` | 50 files per hop. | Closed: 50-file cap. |
| 14 | `composer audit` reports `symplify/rule-doc-generator-contracts` (a Rector dependency) as abandoned. Accept it? | Stack lead | `composer ci` | `config.audit.abandoned` is `report`: advisories still fail the build, an abandoned transitive does not. Revisit when Rector drops it. | Closed: report. |
| 15 | Where does the GitHub Preflight reusable workflow get its release tag? | Preflight owner | Replacing the commit pin | Preflight publishes release tags now; the GitLab components and the GitHub workflow pin `@0.1.2`. | Closed: tag pin. |

## Missing configuration

| Item | Where it comes from | Placeholder used |
|---|---|---|
| `BOOSTER_POLICY` (optional) | CREED's released `policy.json` | Empty in `.env.example`; the copy in the `waynestate/creed` package is used |
| Docker daemon on the machine that runs `booster run` | Developer machine or runner | None; without it the gate fails closed and `--skip-gate` is the way out |
| Network access to Docker Hub for `php:<version>-cli` and `composer:2.10.3` | Developer machine or runner | None; a mirror would need the `FROM` lines in `docker/Dockerfile` changed |
| Preflight components `@0.1.2` on GitLab, and the reusable workflow pinned to `@0.1.2` on GitHub | Preflight | As in the sibling projects |
| The app's `vendor/` for Laravel hops | `composer install` in the app | None |

Booster holds no secrets and calls no API.

## Decided and not building

- **`booster mcp`**, the read-only tool server: ADVISOR is the stack's read-only MCP server, so Booster does not
  grow its own.
- **Merge-request automation** (bot branch, label, opt-out): deliberately absent (question 9).

## Not built yet

- **Custom WSU Rector rules** beyond the CAS rename (for example the legacy patterns in the oldest apps).
- **Rector pilot numbers on real apps.** The examples prove the plumbing, not the hit rate.
- **A `cas-to-sesame` run against a real app.** The hop renames the classes, swaps the Composer requirement, writes
  `config/sesame.php` from the old config and rewrites the model trait; Duo two-factor stays manual work, and the hop
  has not been run against a real app yet.
