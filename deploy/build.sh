#!/bin/sh
# build.sh: image-level tooling only (Squire S1).
#
# Citadel runs this file at image build time, as root, with `/bin/sh` (dash on Debian, so no bashisms), in a
# directory that holds this one file. The rest of the app is NOT here yet. So: system packages and tools go in this
# file, and `composer install` / `npm ci` go in scripts/release.sh, where the lock files exist.
#
# A non-zero exit fails the image build, and on an Update the old container keeps serving (S2).
# Citadel can reuse a cached build layer even after you edit this file. After a change, read the build log and
# confirm the new version ran (S6). The marker below says which one it was.
#
# Knights wear plate armour for the same reason this script is boring: everything exposed gets a layer of protection.
set -eu
# `pipefail` is not POSIX; use it when this shell has it (S3).
# shellcheck disable=SC3040
(set -o pipefail) 2>/dev/null && set -o pipefail

SQUIRE_BUILD_REVISION="1"

step() {
    printf '[squire-build] ==> %s\n' "$*"
}

fail() {
    printf '[squire-build] FAILED: %s\n' "$*" >&2
    exit 1
}

# Install system packages. Pin versions here if an exact version matters to you.
apt_install() {
    DEBIAN_FRONTEND=noninteractive apt-get update
    DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends "$@"
    rm -rf /var/lib/apt/lists/*
}

# Download a file and refuse it unless its SHA-256 matches. Never pipe a download into a shell (S4).
# usage: fetch_verified <url> <sha256> <destination>
fetch_verified() {
    [ "$#" -eq 3 ] || fail "fetch_verified needs <url> <sha256> <destination>"
    curl --fail --silent --show-error --location --output "$3" "$1"
    printf '%s  %s\n' "$2" "$3" | sha256sum --check --status || fail "checksum mismatch for $1"
}

step "build.sh revision ${SQUIRE_BUILD_REVISION} starting"

step "check the environment"
[ "$(id -u)" -eq 0 ] || fail "build.sh runs as root at image build; got uid $(id -u)"
# The app source is absent at build time. If it is here, someone changed how Citadel builds; stop and look.
if [ -f composer.json ] || [ -f package.json ]; then
    fail "app source found next to build.sh; dependencies belong in scripts/release.sh"
fi
# The images already carry Node 22 and corepack (S7). Check before installing a second copy.
if command -v node >/dev/null 2>&1; then
    step "image already has node $(node --version); not installing it"
fi
step "php $(php -r 'echo PHP_VERSION;')"

step "image tooling"
# Add system packages or verified downloads for this app below. Examples (leave them commented until needed):
#
#   apt_install ghostscript
#   fetch_verified "https://example.invalid/tool.tar.gz" "<sha256>" /tmp/tool.tar.gz
#
# Keep secrets out of this file and out of image layers (S5).

step "build.sh finished"
