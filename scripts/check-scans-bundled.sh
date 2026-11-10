#!/bin/sh
# proctor-ignore script-pair: runs only inside the composer:2 container (see the docker run line below)
#
# Proves the advisory scans bundle their tools, so a scan needs no change to
# the app, from the PHAR or from a clone:
#   1. refreshes composer.lock and installs the dependencies (a targeted
#      composer update, because composer update --lock does not move packages
#      between the lock's packages and packages-dev sections),
#   2. fails unless composer.json's require holds all four scan packages
#      (phpstan/phpstan, squizlabs/php_codesniffer,
#      phpcompatibility/php-compatibility, waynestate/pinstripe) and
#      require-dev holds none of them,
#   3. runs both scans of examples/raw-php at PHP 8.0 and fails when either
#      output says "could not run",
#   4. runs the scan unit tests.
#
# Runs inside the composer:2 image from the repo root:
#   docker run --rm -v "${PWD}:/app" -w /app composer:2 sh scripts/check-scans-bundled.sh

set -eu

cd "$(dirname "$0")/.."

composer update --with-all-dependencies phpcompatibility/php-compatibility squizlabs/php_codesniffer waynestate/pinstripe --no-interaction --ignore-platform-reqs
composer install --no-interaction --ignore-platform-reqs

php -r '
$manifest = json_decode((string) file_get_contents("composer.json"), true);
$packages = ["phpstan/phpstan", "squizlabs/php_codesniffer", "phpcompatibility/php-compatibility", "waynestate/pinstripe"];
$require = $manifest["require"] ?? [];
$requireDev = $manifest["require-dev"] ?? [];
foreach ($packages as $package) {
    if (!array_key_exists($package, $require)) {
        fwrite(STDERR, "check-scans-bundled: composer.json require is missing " . $package . "\n");
        exit(1);
    }
    if (array_key_exists($package, $requireDev)) {
        fwrite(STDERR, "check-scans-bundled: composer.json require-dev still lists " . $package . "\n");
        exit(1);
    }
}
'

echo '==> booster scan (PHPCompatibility)'
out_compat=$(php bin/booster scan --path=examples/raw-php --php=8.0)
printf '%s\n' "$out_compat"
if printf '%s\n' "$out_compat" | grep -q 'could not run'; then
    echo 'check-scans-bundled: the PHPCompatibility scan could not run' >&2
    exit 1
fi

echo '==> booster scan (PHPCompatibility + PHPStan)'
out_phpstan=$(php bin/booster scan --path=examples/raw-php --php=8.0 --phpstan)
printf '%s\n' "$out_phpstan"
if printf '%s\n' "$out_phpstan" | grep -q 'could not run'; then
    echo 'check-scans-bundled: the PHPStan scan could not run' >&2
    exit 1
fi

echo '==> scan unit tests'
vendor/bin/phpunit tests/PhpCompatScanTest.php tests/PhpStanScanTest.php
