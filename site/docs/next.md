---
title: "Next | Booster docs"
description: "What Booster does not do yet, and what it decided not to build."
url: https://booster.apps.wayne.edu/docs/next.html
llms: https://booster.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# Next [#](https://booster.apps.wayne.edu/docs/next.html#next)

This page lists what Booster does not do yet, and what it decided not to build. It is the only place future work lives; the other pages describe what ships today.

## Open [#](https://booster.apps.wayne.edu/docs/next.html#open)

- **The gate needs a real app.** The contract tests cover the bundled example apps. A full `run` against a real Wayne State application with the gate on has not been proven in CI yet; the proof site carries those runs.
- **The shared MySQL 5.6 database floor.** Which PHP and Laravel pairs Citadel's shared MySQL server allows waits on the platform owner (question 8 in OPEN_QUESTIONS.md). Until then each Laravel hop's notes tell the reviewer to check the database version, and Booster does not look at the database. Parity runs the upgraded app against MySQL 5.6 on your own computer, so that check can happen before a deploy (see "Try it on Citadel's twin first" in the Citadel guide).

## Decided not to build [#](https://booster.apps.wayne.edu/docs/next.html#decided-not-to-build)

- **An MCP server for Booster.** App owners drive upgrades from their own machines and CI; an MCP endpoint would add a surface to secure without a caller that needs it.
- **Automatic merge requests.** Booster edits a working copy and stops. Opening MRs on app repositories would make a tool that fixes code into one that ships it, and the person reading the diff is the point of the review threshold.

## Not built yet [#](https://booster.apps.wayne.edu/docs/next.html#not-built-yet)

- **Custom rules** beyond the CAS rename and the PHP 4 era `$a = &new Foo();` pre-pass. Apps sometimes need a rewrite Rector does not ship; the proof corpus found no other pattern worth a rule, so such rewrites stay manual until one shows up.
- **Pilot numbers on the current release.** The proof ran Booster over 95 Wayne State units on 2026-10-28 and again on 2026-11-01 (1.6.0); the aggregates are on the proof site, which needs an employee sign-in. A re-run on the current release is still open.
- **A signed-in `cas-to-sesame` run.** The hop (opt in with `--sesame`) has run on a real app's shape since 1.6.0: it swaps the package, writes `config/sesame.php` from the old config, moves the middleware under `auth.sesame`, drops `WayneCasable` from the user model, and moves `ReadOnlyAttributes` into `app/Models/Concerns`. Duo two-factor stays manual work, and a signed-in run of the swapped app is still open, because the proof sandbox cannot sign in.
