---
title: Deploying on Citadel
description: The deployment script Booster writes when a real run changes composer.lock.
---

# Deploying on Citadel

This page covers the deployment script Booster writes for a real run. Next: [Checks](../checks.md).

When a real (not dry) run changes `composer.lock` and the app has a `deployments/` directory, Booster writes one
deployment script, `deployments/0000_composer_install_<YYYYMMDDHHMM>`:

```sh
#!/bin/sh
# Added by Booster: this upgrade changed composer.lock. Citadel runs each deployment script once, in name order,
# so a new name makes existing deployments install the new vendor/ before anything else runs.
set -e
composer install --no-dev --no-interaction --optimize-autoloader
php artisan migrate --force
```

Citadel runs every `deployments/` script exactly once, in name order, and the leading `0000` puts this one first. So
when the app next deploys, every existing deployment installs the upgraded `vendor/` before any other script runs. The
`php artisan migrate --force` line appears only for apps that have an `artisan` file; raw PHP apps get the `composer
install` alone. Commit the script with the rest of the upgrade. To drop it again, delete the named file.
