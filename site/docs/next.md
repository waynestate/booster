---
title: "Next | Booster docs"
description: "What Booster does not do yet, and what it decided not to build."
url: https://booster.apps.wayne.edu/docs/next.html
llms: https://booster.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# Next[#](https://booster.apps.wayne.edu/docs/next.html#next)

This page lists what Booster does not do yet, and what it decided not to build. It is the only place future work lives; the other pages describe what ships today.

## Open[#](https://booster.apps.wayne.edu/docs/next.html#open)

- **The gate needs a real app.** The contract tests cover the bundled example apps. A full `run` against a real Wayne State application with the gate on has not been proven in CI yet; the proof site carries those runs.

## Decided not to build[#](https://booster.apps.wayne.edu/docs/next.html#decided-not-to-build)

- **An MCP server for Booster.** App owners drive upgrades from their own machines and CI; an MCP endpoint would add a surface to secure without a caller that needs it.
- **Automatic merge requests.** Booster edits a working copy and stops. Opening MRs on app repositories would make a tool that fixes code into one that ships it, and the person reading the diff is the point of the review threshold.

## Not built yet[#](https://booster.apps.wayne.edu/docs/next.html#not-built-yet)

- **Custom rules.** Apps sometimes need a rewrite Rector does not ship. Booster has no way to register one yet; until it does, such rewrites stay manual.
- **Pilot numbers.** No pilot cohort has run yet, so there are no counts of apps upgraded or hops applied to publish.
- **A `cas-to-sesame` run against a real app.** The hop exists (opt in with `--sesame`) and renames the classes, swaps the Composer requirement, writes `config/sesame.php` from the old config and rewrites the model trait. Duo two-factor stays manual work, and the hop has not been run against a real app yet.
