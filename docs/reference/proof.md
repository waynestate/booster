---
title: Proof
description: Proof runs against real Wayne State applications are on the private site proof.apps.wayne.edu/booster behind a Wayne State employee sign-in; the Real output page is public.
---

# Proof

This page says how Booster is proven: what `composer ci` checks, where the proof against real Wayne State applications lives, and how the Real output page is refreshed.

## Last run

| Check | Command | Result |
| --- | --- | --- |
| Manifest | `composer validate --strict` | run `composer ci` to refresh |
| Tests | `phpunit` | run `composer ci` to refresh |
| Style | `pint --test` | run `composer ci` to refresh |
| Static analysis | `phpstan analyse --memory-limit=1G` | run `composer ci` to refresh |
| Audit | `composer audit` | run `composer ci` to refresh |
| Hop data freshness | `php bin/refresh-hop-data.php --check` | run `composer ci` to refresh |
| Agent-ready files | `php bin/agent-ready.php --check` | run `composer ci` to refresh |
| Real output page | `php bin/build-proof.php --check` | run `composer ci` to refresh |
| Docs site | `php bin/docs-site.php --check` | run `composer ci` to refresh |

That is every step of `composer ci` in [composer.json](https://github.com/waynestate/booster/blob/main/composer.json), in order. The table carries no date, commit or count, because none of them is measured here: run `composer ci` to refresh it. Nothing needs PHP on the host; the [Checks](../checks.md) page has the Docker command.

## The Real output page

The [Real output page](https://booster.apps.wayne.edu/proof.html) is public. It shows `booster plan` and `booster run --dry-run` on each example app, captured from the real tool. To refresh it:

```
composer proof:capture
composer proof
```

`composer proof:capture` re-runs Booster on every example app and re-captures `site/proof-data/`; it needs Docker. `composer proof` rebuilds `site/proof.html` from the capture. `composer ci` runs `php bin/build-proof.php --check` and fails on a stale page or a version mismatch with `site/proof-data/meta.json`.

## Proof against real Wayne State applications

Proof runs against real Wayne State applications are on a private site, [proof.apps.wayne.edu/booster](https://proof.apps.wayne.edu/booster/), behind a Wayne State employee sign-in. Two written-up runs stand behind the hops:

- The first full run with the gate on took a Laravel 12 app from PHP 8.2 to 8.4 in two hops, each gated by the app's 197-test suite; it is written up in [research/booster-e2e-gate.md](https://github.com/waynestate/booster/blob/main/research/booster-e2e-gate.md).
- The front-end hops (`vite-major`, `tailwind-3-to-4`, `typescript-major`, `inertia-2-to-3`) were proven on 2026-11-06 on a Laravel 12 app generated from the public Laravel Vue starter kit, with every gate on: the first pass found four bugs, each fixed with a test, and the upgraded app then built, passed its tests on PHP 8.5 and registered a user in a browser. The private proof site has the hop-by-hop table.

## Accessibility and secret scanning

- **Accessibility.** The site's accessibility gate is Preflight's own checker, vendored into `tools/a11y/check.mjs` from Preflight 0.13.0 and pinned by `tests/A11yCheckerPinTest.php`, so a local run checks exactly what CI checks: axe-core 4.14.1 in a real Chromium, every page under `site/` in light and dark at 320 and 1280 px, plus the WCAG 1.4.10 reflow check, all at WCAG 2.2 AA. It runs in Docker, with no host browser: `sh scripts/a11y.sh` on Linux, macOS and Git Bash, or `scripts\a11y.ps1` on Windows PowerShell.
- **Secret scanning.** Every release runs a secrets scan of the history and checks that nothing internal (server names, people, app names, IDs) is in the tree. Booster itself reads no secrets and talks to no Citadel host.

## Related

- [Checks](../checks.md)
- [Commands](commands.md)
- [Configuration](configuration.md)
