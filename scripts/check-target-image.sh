#!/usr/bin/env bash
#
# Builds the gate image for each PHP version and checks that it carries all of
# Citadel's extensions, so an Oracle app does not fail the gate at
# `composer install` with "requires ext-oci8".
#
# For each version the script:
#   1. builds docker/Dockerfile with PHP_VERSION=<v>, tagged booster-target-check:<v>,
#      with the Composer Booster itself passes (2.2 below PHP 7.2),
#   2. runs `php -m` in the image,
#   3. fails naming any of the 13 extensions missing from the output,
#   4. fails when Composer does not run in the image,
#   5. on an archived Debian release other than jessie, runs `apt-get update` in
#      the image and fails when apt cannot verify the archive's signatures
#      (jessie's archive is signed only by expired keys, so it is skipped),
#   6. prints `OK <v>` when all of that holds.
#
# Usage: scripts/check-target-image.sh [php-version ...]   (default: 8.3 8.4 8.5)
# The old images: scripts/check-target-image.sh 5.5 5.6 7.0 7.1 7.2 7.3 7.4 8.0
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

# What Booster passes (TargetImageRunner): Composer 2.3 and later need PHP
# 7.2.5, so builds below 7.2 get the 2.2 line; the rest keep the recipe default.
composer_for() {
    case "$1" in
        5.*|7.0|7.1) echo '--build-arg COMPOSER_VERSION=2.2' ;;
        *) echo '' ;;
    esac
}

# Runs in the image: archived releases other than jessie must verify their
# archive. apt prints a GPG error or "not signed" when it cannot.
APT_PROBE='. /etc/os-release
codename="${VERSION_CODENAME:-$(printf "%s" "${VERSION:-}" | sed -n "s/.*(\([a-z]*\)).*/\1/p")}"
case "$codename" in
    stretch|buster|bullseye) out="$(apt-get update 2>&1)" || { printf "%s\n" "$out"; exit 1; }
        if printf "%s\n" "$out" | grep -E "GPG error|not signed|NO_PUBKEY|EXPKEYSIG" >&2; then exit 1; fi
        rm -rf /var/lib/apt/lists/*; echo "apt verified $codename" ;;
    jessie) echo "apt not checked on jessie (its archive keys have all expired)" ;;
    *) echo "apt untouched on $codename" ;;
esac'

for V in "${VERSIONS[@]}"; do
    echo "==> Building booster-target-check:$V ..."
    # shellcheck disable=SC2046 # composer_for prints zero or two words on purpose
    docker build --build-arg "PHP_VERSION=$V" $(composer_for "$V") -t "booster-target-check:$V" docker/

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

    if ! docker run --rm "booster-target-check:$V" composer --version; then
        echo "FAIL $V: composer does not run in the image" >&2
        exit 1
    fi

    if ! docker run --rm "booster-target-check:$V" sh -c "$APT_PROBE"; then
        echo "FAIL $V: apt cannot verify the Debian archive in the image" >&2
        exit 1
    fi

    echo "OK $V"
done
