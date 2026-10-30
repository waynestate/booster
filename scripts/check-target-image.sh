#!/usr/bin/env bash
#
# Builds the gate image for each PHP version and checks that it carries all of
# Citadel's extensions, so an Oracle app does not fail the gate at
# `composer install` with "requires ext-oci8".
#
# For each version the script:
#   1. builds docker/Dockerfile with PHP_VERSION=<v>, tagged booster-target-check:<v>,
#   2. runs `php -m` in the image,
#   3. fails naming any of the 13 extensions missing from the output,
#   4. prints `OK <v>` when every extension is present.
#
# Usage: scripts/check-target-image.sh [php-version ...]   (default: 8.3 8.4 8.5)
#
# The first build downloads Oracle Instant Client for oci8, so allow a few
# minutes per version. This is not part of composer ci on purpose: it needs
# Docker and real build time. Written for macOS's bash 3.2 and BSD userland,
# as well as Linux.
# The gate must see what Citadel sees, extension for extension.

set -euo pipefail

# The project root is the parent of the scripts/ directory this file lives in.
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

VERSIONS=("$@")
if [ ${#VERSIONS[@]} -eq 0 ]; then
    VERSIONS=(8.3 8.4 8.5)
fi

# Citadel's extension set, copied exactly. php -m prints them one per line,
# in this case (pdo_mysql, oci8, ...), so the match is exact.
EXPECTED='intl ldap soap zip gd pdo_mysql mysqli bcmath pcntl exif redis imagick oci8'

for V in "${VERSIONS[@]}"; do
    echo "==> Building booster-target-check:$V ..."
    docker build --build-arg "PHP_VERSION=$V" -t "booster-target-check:$V" docker/

    MODULES="$(docker run --rm "booster-target-check:$V" php -m)"

    MISSING=''
    for EXT in $EXPECTED; do
        if ! printf '%s\n' "$MODULES" | grep -qx -- "$EXT"; then
            MISSING="$MISSING $EXT"
        fi
    done

    if [ -n "$MISSING" ]; then
        echo "FAIL $V: missing extensions:$MISSING" >&2
        exit 1
    fi

    echo "OK $V"
done
