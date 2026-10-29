#!/usr/bin/env bash
# deployments/001-first-release.sh: the one-time hook (SQUIRE S9).
#
# Citadel runs each file in deployments/ once per file name for the life of the app, as the `citadel` user, when the
# container starts, before Apache. A failing hook is retried up to three times and then marks the deployment
# FAILING, so everything here must be safe to run twice (S10). Apache is not running yet, so the /up smoke test is
# off here and only the Quick Update post-script runs it.
#
# Put first-release work in the marked block: seeding, a one-off import, creating a directory. The repeatable work
# lives in scripts/release.sh, which this hook calls so a first release is also a complete release.
set -euo pipefail

echo "[squire] first-release hook starting"
cd "$(dirname "$0")/.."

[ -f ./scripts/release.sh ] || {
    echo "[squire] FAILED: scripts/release.sh is missing; run 'vendor/bin/squire init'" >&2
    exit 1
}

# --- one-time work for this app goes here ------------------------------------------------------------------------

# --- end of one-time work ------------------------------------------------------------------------------------------

SQUIRE_SMOKE=0 bash ./scripts/release.sh
echo "[squire] first-release hook finished"
