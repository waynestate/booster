---
title: "Behavior changes | Booster docs"
description: "The silent changes between versions that Rector does not rewrite, one block per hop."
url: https://booster.apps.wayne.edu/docs/guides/behaviour-changes.html
llms: https://booster.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# Behavior changes[#](https://booster.apps.wayne.edu/docs/guides/behaviour-changes.html#behavior-changes)

This page explains the behavior-change blocks `plan` and `run` print, one per PHP and Laravel hop. Next: [The baseline](https://booster.apps.wayne.edu/docs/guides/baseline.html).

Some changes between versions do not raise an error: a function that still exists now means something else, a config key that was optional is required, a default that flipped. Rector rewrites what it knows; the silent changes need a human eye. So `plan` and `run` carry a curated list of them, one block per PHP and Laravel hop, plus the package rows on the last step, where the lock lands. A booster should know its own payload.

```
Behavior changes to check:
  Config key needed (config/app.php)
    check: Add the key.
  Minimum PHP raised
    check: Check the runtime.
```

Each entry says what changed (`label`), what keeps the old behavior when there is anything (`keep old:`), what to verify (`check:`), and where the app touches it: `file:line` hits for code, the file for config and package rows, no location for rows that apply everywhere. It is advisory, like the scans: it never changes the exit code, and nothing prints when nothing matches. In JSON output the entries land under `behaviour_changes`, per step, with the fields `id`, `label`, `keep_old`, `check`, `source`, `hits` and `total`.

The list lives in `data/behaviour-changes.json`, bundled with the phar. An entry names the hop it applies to (`php-80-to-81`, `laravel-10-to-11`, or `package:vendor/name@2` for a floor a package raises), how to find it (`detect`: a `function`, `method`, `class`, `property`, string `token`, blade snippet, config `file` and `key`, composer `package`, or `always`), and a `source` link that confirms the change:

```json
{
  "id": "laravel-11-config-key",
  "hop": "laravel-10-to-11",
  "detect": { "kind": "config", "file": "config/app.php", "key": "cipher" },
  "label": "Config key needed",
  "keep_old": null,
  "check": "Add the key.",
  "source": "https://laravel.com/docs/11.x/upgrading"
}
```

Only confirmed changes ship, each with its source link, and at most three `always` rows per release, so the block stays a checklist rather than a pamphlet. To add an entry, append it to the data file and let the loader's errors tell you what is missing.
