#!/usr/bin/env bash
#
# Builds build/booster.phar, a single-file PHAR of Booster, using Box.
#
# The phar lets Preflight and apps without Composer access run Booster as one
# file. The script:
#   1. installs the build tool (Box, pinned in tools/box) so the compiler exists,
#   2. copies the runtime sources into a temp dir and installs only the no-dev
#      (runtime) dependencies there,
#   3. runs Box against that temp copy, so the phar contains src/, config/,
#      docker/, resources/ and the runtime vendor deps only (no dev deps),
#   4. copies the built phar back into build/,
#   5. verifies it runs by printing Booster's version,
#   6. prints the phar's SHA-256 checksum.
#
# By default every step runs through Docker, so no PHP or Composer is needed on
# the host. Set BOOSTER_PHAR_NO_DOCKER=1 to run the same steps with the php and
# composer already on the PATH instead: the CI release jobs use this mode,
# because their runners may have no Docker daemon (they run inside the
# composer:2.10.3 image, which ships both). Both modes build the same phar.
# Written for macOS's bash 3.2 and BSD userland, as well as Linux.
# The booster packs himself: one file, no EOL PHP, no Composer on the target.

set -euo pipefail

# The project root is the parent of the scripts/ directory this file lives in.
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

# No-Docker mode: 1 when BOOSTER_PHAR_NO_DOCKER=1, else 0.
if [ "${BOOSTER_PHAR_NO_DOCKER:-0}" = '1' ]; then
    NO_DOCKER=1
    echo '==> No-Docker mode: using the php and composer on the PATH.'
else
    NO_DOCKER=0
fi

# Pinned tool images (the same ones the README's Docker sections use).
COMPOSER_IMAGE='composer:2.10.3'
PHP_IMAGE='php:8.4-cli'

# A unique temp dir for the no-dev build copy.
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

echo '==> Installing the build tool (Box) in tools/box...'
if [ "$NO_DOCKER" = '1' ]; then
    composer install --working-dir="$ROOT/tools/box" --no-interaction --no-progress
else
    docker run --rm -v "$ROOT:/app" -w /app "$COMPOSER_IMAGE" composer install --working-dir=tools/box --no-interaction --no-progress
fi

echo '==> Copying runtime sources into a temp build dir...'
cp -R "$ROOT/src" "$TMP/src"
cp -R "$ROOT/bin" "$TMP/bin"
cp -R "$ROOT/config" "$TMP/config"
cp -R "$ROOT/docker" "$TMP/docker"
cp -R "$ROOT/resources" "$TMP/resources"
cp "$ROOT/composer.json" "$TMP/composer.json"
cp "$ROOT/composer.lock" "$TMP/composer.lock"
cp "$ROOT/box.json.dist" "$TMP/box.json.dist"

echo '==> Installing runtime (no-dev) dependencies in the temp build dir...'
if [ "$NO_DOCKER" = '1' ]; then
    composer install --working-dir="$TMP" --no-dev --no-interaction --no-progress
else
    docker run --rm -v "$TMP:/app" -w /app "$COMPOSER_IMAGE" composer install --no-dev --no-interaction --no-progress
fi

echo '==> Compiling the phar with Box...'
# Box's Composer compatibility check needs a composer executable, so in Docker
# mode it runs from the composer image (which ships both PHP and composer).
# Box reads box.json.dist from the working directory, so both modes run it
# from the temp build dir.
if [ "$NO_DOCKER" = '1' ]; then
    (cd "$TMP" && php -d phar.readonly=0 "$ROOT/tools/box/vendor/bin/box" compile)
else
    docker run --rm -v "$ROOT:/app" -v "$TMP:/tmpcopy" -w /tmpcopy "$COMPOSER_IMAGE" php -d phar.readonly=0 /app/tools/box/vendor/bin/box compile
fi

echo '==> Copying the built phar into build/...'
mkdir -p "$ROOT/build"
cp "$TMP/build/booster.phar" "$ROOT/build/booster.phar"

echo '==> Verifying the phar runs...'
if [ "$NO_DOCKER" = '1' ]; then
    php build/booster.phar --version
else
    docker run --rm -v "$ROOT:/app" -w /app "$PHP_IMAGE" php build/booster.phar --version
fi

echo '==> Printing the checksum...'
# sha256sum is GNU coreutils, shasum is its BSD (macOS) twin.
if command -v sha256sum >/dev/null 2>&1; then
    sha256sum build/booster.phar
else
    shasum -a 256 build/booster.phar
fi
