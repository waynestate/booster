---
title: "Checks | Booster docs"
description: "How Booster is built, tested and released, and where its proof lives."
url: https://booster.apps.wayne.edu/docs/checks.html
llms: https://booster.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# Checks[#](https://booster.apps.wayne.edu/docs/checks.html#checks)

This page is for maintainers: how Booster is checked, and where its proof lives. Next: [Next](https://booster.apps.wayne.edu/docs/next.html) for what is not built yet.

Maintainers: how to build, test and release this project, and where its data comes from, is in [MAINTAINING.md](https://github.com/waynestate/booster/blob/main/MAINTAINING.md).

Checks: `composer ci` (the same command runs on GitLab and GitHub).

Development happens on Wayne State's GitLab; this repository is a release mirror. Changes land there first.

```
composer ci
```

runs `composer validate --strict`, PHPUnit, Pint, PHPStan (Pinstripe's raw preset, inlined; `waynestate/pinstripe` ships with Booster and supplies the preset the advisory scan uses), `composer audit`, the hop data check and the agent-ready check. Nothing needs PHP on the host:

```
docker run --rm -v "${PWD}:/app" -w /app composer:2.10.3 sh -c "composer install --no-interaction && composer ci"
```

`contract/expectations.json` says what Booster must detect, plan and change for each example app in `examples/`; `tests/ContractTest.php` enforces it with the real Rector, so a Rector release that changes a hop shows up in CI.

## Proof[#](https://booster.apps.wayne.edu/docs/checks.html#proof)

Proof runs against real Wayne State applications are on a private site, [proof.apps.wayne.edu/booster](https://proof.apps.wayne.edu/booster/), behind a Wayne State employee sign-in.

Proven how: the first full run with the gate on took a Laravel 12 app from PHP 8.2 to 8.4 in two hops, each gated by the app's 197-test suite, and it is written up in [research/booster-e2e-gate.md](https://github.com/waynestate/booster/blob/main/research/booster-e2e-gate.md).

## Status[#](https://booster.apps.wayne.edu/docs/checks.html#status)

See [CHANGELOG.md](https://github.com/waynestate/booster/blob/main/CHANGELOG.md) for what changed, [OPEN_QUESTIONS.md](https://github.com/waynestate/booster/blob/main/OPEN_QUESTIONS.md) for what stays open, and [BEST_PRACTICES.md](https://github.com/waynestate/booster/blob/main/BEST_PRACTICES.md) for the rules Booster follows.
