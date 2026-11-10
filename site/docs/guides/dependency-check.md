---
title: "The dependency check | Booster docs"
description: "How Booster asks Packagist whether your direct dependencies can follow the plan."
url: https://booster.apps.wayne.edu/docs/guides/dependency-check.html
llms: https://booster.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# The dependency check[#](https://booster.apps.wayne.edu/docs/guides/dependency-check.html#the-dependency-check)

This page explains the Packagist check Booster runs before the hops print. Next: [Behavior changes](https://booster.apps.wayne.edu/docs/guides/behaviour-changes.html).

Before the hops print, `plan` (and `run`) asks Packagist about each direct third-party dependency in the app's `composer.json`: which stable releases does the app's own constraint allow, and can one of them still be installed at each hop's destination? A newer major the constraint never installs does not count: an app on `doctrine/orm ^2.20` is judged by 2.20, which runs on PHP 8.0, not by 3.x, which needs 8.1. With no usable constraint (a `dev-` branch, nothing stable in range) the newest stable release stands in. The warning names the newest release in range; the JSON's `newest` field stays the package's newest stable release. Only plain `require` and `require-dev` entries count; PHP, extensions (`ext-*`, `lib-*`), Laravel itself (`laravel/framework`, its `illuminate/*` components) and PHPUnit are Booster's own business, so they are skipped.

The answer is advisory, like the scans: a package that cannot follow the plan does not drop the hop. It adds a warning that names the package, the hop it blocks, and what to do about it:

```
acme/legacy-auth 2.3.0 supports Laravel up to 10 and PHP up to 8.3, so it blocks laravel-10-to-11. Replace or upgrade it first.
```

The warning names only the dimensions the blocked hop fails on: a Laravel ceiling alone, a PHP ceiling alone, or both together. A ceiling is printed as the newest version Booster plans that the constraint allows (a PHP constraint capping at 7.x reads "7.4", not "7"), and a constraint with no upper bound has no ceiling to name, so the warning names its floor instead (`needs PHP 8.2 or newer`).

A package whose newest release requires several `illuminate/*` components is judged by the loosest of those constraints, the one that allows the most Laravel versions. Abandoned packages get their own warning (`acme/old is abandoned (use acme/new instead).`). Packages the check cannot reach are reported as `Not checked: <package> (not on Packagist or unreachable).` or `(VCS repository).`, and the plan goes on. In JSON output every package lands under `package_checks`, with the fields `package`, `newest`, `blocks`, `abandoned` and `checked`.

The check fetches from `repo.packagist.org` and from any composer-type repository the app declares in `composer.json`; VCS repositories are never fetched. `--no-package-check` skips the whole check, for offline use.
