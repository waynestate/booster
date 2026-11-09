#!/bin/sh
# Site accessibility gate: Preflight's a11y checker (vendored in
# tools/a11y/check.mjs from Preflight 0.4.0, pinned by tests/A11yCheckerPinTest.php),
# run in Docker so no host browser is needed. The same image, tags, color
# schemes and widths as the a11y-site CI job, so a page that passes here
# passes CI. Linux and macOS; on Windows use scripts/a11y.ps1, or this script
# from Git Bash (the MSYSTEM block below handles its path conversion).
#
# The repo is mounted read-only; the checker's packages live in a named
# volume (npm installs once and later runs reuse them) and npm's download
# cache in stack-npm-cache. The reports (gl-a11y-junit.xml, gl-a11y-report.json)
# land in build/a11y (gitignored). Exit 0 clean, 1 a violation or a page that
# did not load, 2 a tool or configuration error.
set -eu

cd "$(dirname "$0")/.."

# The read-only repo mount cannot create the named volume's mount point.
mkdir -p tools/a11y/node_modules

# Git Bash on Windows rewrites absolute-looking arguments for native programs
# (docker would turn -w /work into a Windows path). Turn that off and pass the
# Windows form of the repo path instead.
if [ -n "${MSYSTEM:-}" ]; then
  export MSYS_NO_PATHCONV=1
  REPO="$(pwd -W)"
else
  REPO="$PWD"
fi

# The a11y component's image default, digest included (templates/a11y/template.yml
# of Preflight 0.4.0). Its tag sets the playwright npm version, so the package
# and the image's browsers always match.
IMAGE="mcr.microsoft.com/playwright:v1.65.0-noble@sha256:6e3fb709361521eb27161606e6da6d2674e5e89fa16516cb37d6fdd91372273d"
PW=$(printf '%s\n' "$IMAGE" | sed -n 's/^.*playwright:v\([0-9][0-9]*\.[0-9][0-9]*\.[0-9][0-9]*\).*$/\1/p')

# The a11y-site CI job's inputs, as JSON for the checker.
CONFIG='{"siteDir": "site", "pages": ["**/*.html"], "tags": ["wcag2a", "wcag2aa", "wcag21a", "wcag21aa", "wcag22aa"], "colorSchemes": ["light", "dark"], "widths": [320, 1280], "axeCoreVersion": "4.14.0"}'

REPORT_DIR="build/a11y"
mkdir -p "$REPORT_DIR"
if [ -n "${MSYSTEM:-}" ]; then
  REPORT_HOST="$(cd "$REPORT_DIR" && pwd -W)"
else
  REPORT_HOST="$(cd "$REPORT_DIR" && pwd)"
fi

# The checker reads its inputs from a JSON file next to its reports.
printf '%s\n' "$CONFIG" > "$REPORT_DIR/config.json"

# Install the checker's packages when they are missing or when the installed
# playwright stops matching the image tag; the installed version is stamped in
# node_modules/.a11y-pw-stamp after each install. Exact versions from
# tools/a11y/package.json, no install scripts: the image already carries
# Chromium for this playwright release. The inner code carries no quotes and
# no backslashes: PowerShell 5.1 mangles both when it builds docker's command
# line, and the .ps1 twin passes this very text through.
INNER='if [ ! -f tools/a11y/node_modules/.a11y-pw-stamp ] || [ $(cat tools/a11y/node_modules/.a11y-pw-stamp) != $A11Y_PW ]; then (cd tools/a11y && npm ci --ignore-scripts --no-audit --no-fund && printf %s $A11Y_PW > node_modules/.a11y-pw-stamp); fi
A11Y_REPORT_DIR=/report node tools/a11y/check.mjs /report/config.json'

docker run --rm \
  -v "${REPO}:/work:ro" \
  -v "${REPORT_HOST}:/report" \
  -v booster-a11y-node-modules:/work/tools/a11y/node_modules \
  -v stack-npm-cache:/npm-cache \
  -e npm_config_cache=/npm-cache \
  -e "A11Y_PW=$PW" \
  -w /work \
  "$IMAGE" \
  sh -c "$INNER"
