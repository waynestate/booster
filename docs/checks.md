---
title: Checks
description: How Booster is built, tested and released, and where its proof lives.
---

# Checks

This page is for maintainers: how Booster is checked, and where its proof lives. Next: [Next](next.md) for what is not built yet.

Maintainers: how to build, test and release this project, and where its data comes from, is in
[MAINTAINING.md](https://github.com/waynestate/booster/blob/main/MAINTAINING.md).

Checks: `composer ci` (the same command runs on GitLab and GitHub).

Development happens on Wayne State's GitLab; this repository is a release mirror. Changes land there first.

<!-- docs-test: skip needs network -->
```
composer ci
```

runs `composer validate --strict`, PHPUnit, Pint, PHPStan (Pinstripe's raw preset, inlined; `waynestate/pinstripe`
ships with Booster and supplies the preset the advisory scan uses), `composer audit`, the hop data check and the
agent-ready check. Nothing needs PHP on the host:

<!-- docs-test: skip needs Docker -->
```
docker run --rm -v "${PWD}:/app" -w /app composer:2.10.3 sh -c "composer install --no-interaction && composer ci"
```

`contract/expectations.json` says what Booster must detect, plan and change for each example app in `examples/`;
`tests/ContractTest.php` enforces it with the real Rector, so a Rector release that changes a hop shows up in CI.

## Proof

Proof runs against real Wayne State applications are on a private site,
[proof.apps.wayne.edu/booster](https://proof.apps.wayne.edu/booster/), behind a Wayne State employee sign-in. The
[Real output page](https://booster.apps.wayne.edu/proof.html) shows Booster's own plan and dry-run output on each
example app, and `composer ci` keeps it current.

Proven how: the first full run with the gate on took a Laravel 12 app from PHP 8.2 to 8.4 in two hops, each gated by
the app's 197-test suite, and it is written up in
[research/booster-e2e-gate.md](https://github.com/waynestate/booster/blob/main/research/booster-e2e-gate.md).

The front-end hops (`vite-major`, `tailwind-3-to-4`, `typescript-major`, `inertia-2-to-3`) were proven on 2026-11-06
on a Laravel 12 app generated from the public Laravel Vue starter kit, with every gate on: the first pass found four
bugs, each fixed with a test, and the upgraded app then built, passed its tests on PHP 8.5 and registered a user in a
browser. The private proof site has the hop-by-hop table.

The same day, the whole chain ran on a copy of WELD's own site app with every gate and the replay gate on: PHP 8.3
to 8.5, PHPUnit 12 to 13 with Pest 4 to 5, Node 26, Vite 8, TypeScript 6 and Inertia 3, seven hops, ten recorded
pages matching after each one. That run found eight more bugs, each fixed with a test and listed in the changelog.

## Status

See [CHANGELOG.md](https://github.com/waynestate/booster/blob/main/CHANGELOG.md) for what changed,
[OPEN_QUESTIONS.md](https://github.com/waynestate/booster/blob/main/OPEN_QUESTIONS.md) for what stays open, and
[BEST_PRACTICES.md](https://github.com/waynestate/booster/blob/main/BEST_PRACTICES.md) for the rules Booster follows.
