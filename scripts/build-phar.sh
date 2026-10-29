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
#   5. verifies it runs by printing Booster's version.
#
# Everything runs through Docker, so no PHP or Composer is needed on the host.
# Written for macOS's bash 3.2 and BSD userland, as well as Linux.
# The booster packs himself: one file, no EOL PHP, no Composer on the target.

set -euo pipefail

# The project root is the parent of the scripts/ directory this file lives in.
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

# Pinned tool images (the same ones the README's Docker sections use).
COMPOSER_IMAGE='composer:2.10.3'
PHP_IMAGE='php:8.4-cli'

# A unique temp dir for the no-dev build copy.
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

echo '==> Installing the build tool (Box) in tools/box...'
docker run --rm -v "$ROOT:/app" -w /app "$COMPOSER_IMAGE" composer install --working-dir=tools/box --no-interaction --no-progress

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
docker run --rm -v "$TMP:/app" -w /app "$COMPOSER_IMAGE" composer install --no-dev --no-interaction --no-progress

echo '==> Compiling the phar with Box...'
# Box's Composer compatibility check needs a composer executable, so run it
# from the composer image (which ships both PHP and composer).
docker run --rm -v "$ROOT:/app" -v "$TMP:/tmpcopy" -w /tmpcopy "$COMPOSER_IMAGE" php -d phar.readonly=0 /app/tools/box/vendor/bin/box compile

echo '==> Copying the built phar into build/...'
mkdir -p "$ROOT/build"
cp "$TMP/build/booster.phar" "$ROOT/build/booster.phar"

echo '==> Verifying the phar runs...'
docker run --rm -v "$ROOT:/app" -w /app "$PHP_IMAGE" php build/booster.phar --version
