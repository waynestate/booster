---
title: "Deploying on Citadel | Booster docs"
description: "What a real run tells a Citadel app about its next deploy, and the install script it writes when one is needed."
url: https://booster.apps.wayne.edu/docs/guides/citadel.html
llms: https://booster.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# Deploying on Citadel[#](https://booster.apps.wayne.edu/docs/guides/citadel.html#deploying-on-citadel)

This page covers what a real run says about the next deploy, and the deployment script it writes when one is needed. Next: [Checks](https://booster.apps.wayne.edu/docs/checks.html).

When a real (not dry) run moves the app's **production** packages in `composer.lock`, Booster works out what the next deploy needs and says so at the end of the report, in a `Deploy:` line or an `Added deployments/...` line:

| The app | What Booster does |
| --- | --- |
| Only dev packages moved (a PHPUnit major, say) | Nothing to write: `composer install --no-dev` gets the same `vendor/`. The report says so. |
| Has `scripts/release.sh` that runs `composer install` (Squire's deploy kit) | Nothing to write: the release script installs on every release. The report says so. |
| Has a `deployments/` folder and no such release script | Writes `deployments/0000_composer_install_<YYYYMMDDHHMM>` (below). |
| Has neither | Writes nothing, and the report says what every server must run on its next release. |

The script it writes installs and does nothing else:

```sh
#!/bin/sh
# Added by Booster: this upgrade changed the production packages in composer.lock. Citadel runs each deployment
# script once, in name order, so a new name makes existing deployments install the new vendor/ first.
# It only installs: migrations and other release steps stay with the app's own deploy scripts.
set -e
composer install --no-dev --no-interaction --optimize-autoloader
```

Citadel runs every `deployments/` script exactly once, in name order, and the leading `0000` puts this one first. So when the app next deploys, every existing deployment installs the upgraded `vendor/` before any other script runs. That is also why it never migrates: it runs ahead of the app's own hooks, and an app whose first release must do something before it migrates (a Laravel app whose first release parks old tables, say) would break if anything migrated first. Migrations stay with your own deploy scripts; for a Laravel app the report adds a line saying to run `php artisan migrate --force` after the deploy if none of them does. Commit the script with the rest of the upgrade. To drop it again, delete the named file. The stamp is local time.
