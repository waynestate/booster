# Changelog

All notable changes to BOOSTER are recorded here. The format follows Keep a Changelog.

## [Unreleased]

### Added

- `booster plan`, `run`, `hops`, `detect` and `init` commands (also `vendor/bin/booster`).
- App-type detection for the five stack permutations from `composer.json` and `package.json`.
- A hop registry: PHP one minor at a time from 5.6 to 8.5, Laravel one major at a time from 8 to 13, a `wayne-cas` to
  SESAME stub recipe, and a Vue 2 to 3 playbook.
- A planner that interleaves PHP and Laravel hops inside Laravel's supported PHP range and refuses unsupported targets.
- Policy loading from `--policy`, `BOOSTER_POLICY`, or a bundled snapshot of CREED's `policy.json`.
- A Rector runner on `rector/rector` 2.8 and `driftingly/rector-laravel` 2.7 with a per-run file cap, a PHP target
  override and a synthetic `installed.json`.
- A `composer.json` editor for PHP, Laravel and SESAME constraints.
- A target-image test gate (`docker/Dockerfile`, `booster-target:<php>`).
- Coding-tab playbooks generated into `.agents/skills`, with `booster init --check`.
- A contract (`contract/expectations.json`) and example apps for all five permutations.
- GitLab and GitHub CI that both call `composer ci`.
