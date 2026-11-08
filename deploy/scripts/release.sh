#!/usr/bin/env bash
# scripts/release.sh: the idempotent release script (Squire S9 to S23).
#
# Runs from the first-release hook (deployments/001-first-release.sh) and from the Quick Update post-script.
# Safe to run twice: every step repeats with the same result (S10).
#
#   Quick Update post-script in the Citadel dashboard:  user `citadel`, script `./scripts/release.sh`
#
# Steps: preconditions, composer install from the lock file without dev packages, composer audit (report),
# PROCTOR's deploy gate, frontend install and build, Laravel migrate and optimize, an Oracle connection check (report
# only), and a smoke test of /up.
# PROCTOR runs `proctor gate` (or `proctor check` on a PROCTOR without the gate): a go or no-go for this commit,
# ratcheted against the verdict of the last release that went on, so only a new failure blocks. The verdict is kept in
# SQUIRE_PROCTOR_RECORD_DIR. By default a no-go is a report here, never a stop: a failing post-script cannot undo a
# Quick Update, because the new commit is already live (S20). With SQUIRE_PROCTOR=enforce a no-go stops the release
# before the frontend build and the migrations: the first release never comes up healthy, and a Quick Update keeps the
# new commit checked out, so fix the findings and re-run. The squire holds the gate; the proctor says who may pass.
#
# Configuration is environment variables, all optional, or the same names in ./.env.squire in the app root. On Citadel
# add that file as a configuration file (the configuration-files step after the wizard; the wizard has no environment
# field) and keep it out of git. A variable already in the environment wins over the file. See .env.squire.example.
#   SQUIRE_APP_DIR=                  app root (default: the parent of this script's directory)
#   SQUIRE_FRAMEWORK=auto            auto | laravel | raw
#   SQUIRE_ALLOW_ROOT=0              1 allows running as root (S19)
#   SQUIRE_REQUIRE_ENV=auto          1 requires a .env file, 0 does not (auto: yes for Laravel)
#   SQUIRE_WRITABLE_DIRS=            directories to create (default: storage and bootstrap/cache for Laravel)
#   SQUIRE_COMPOSER_AUDIT=report     report | fail | skip
#   SQUIRE_NPM=auto                  auto | skip
#   SQUIRE_NPM_IGNORE_SCRIPTS=1      1 runs the installer with --ignore-scripts (S57)
#   SQUIRE_NPM_BUILD_SCRIPT=build    the package.json script to run after install
#   SQUIRE_MIGRATE=1                 Laravel: run `migrate --force`
#   SQUIRE_ARTISAN_OPTIMIZE=1        Laravel: run `optimize` after the environment is in place (S16)
#   SQUIRE_PROCTOR=auto              auto | skip | enforce (report only by default; enforce stops the release on a no-go)
#   SQUIRE_PROCTOR_BIN=              path to proctor (default: vendor/bin/proctor, then `proctor` on PATH)
#   SQUIRE_PROCTOR_POLICY=           path to the CREED policy.json (when unset: the one CREED ships, if installed; otherwise PROCTOR's own default)
#   SQUIRE_PROCTOR_RECORD_DIR=storage/squire  where the gate's verdict (proctor-gate.json) and baseline are kept
#   SQUIRE_SMOKE=1                   1 requests the health URL at the end and fails if it is not 200 (S21)
#   SQUIRE_SMOKE_URL=                full URL (default: http://127.0.0.1:80/up for Laravel, /up.php for raw PHP)
#   SQUIRE_SMOKE_RETRIES=5           attempts
#   SQUIRE_SMOKE_DELAY=2             seconds between attempts
#
# A release script that is not idempotent is just a rehearsal that happens to ship.
set -euo pipefail
set -o errtrace

SQUIRE_STEP_NO=0
SQUIRE_STEP_NAME="start"
SQUIRE_STARTED_AT="$(date +%s)"

step() {
    SQUIRE_STEP_NO=$((SQUIRE_STEP_NO + 1))
    SQUIRE_STEP_NAME="$*"
    printf '[squire] ==> step %d: %s\n' "$SQUIRE_STEP_NO" "$SQUIRE_STEP_NAME"
}

note() {
    printf '[squire]     %s\n' "$*"
}

warn() {
    printf '[squire] WARNING: %s\n' "$*" >&2
}

fail() {
    printf '[squire] FAILED at step %d (%s): %s\n' "$SQUIRE_STEP_NO" "$SQUIRE_STEP_NAME" "$*" >&2
    exit 1
}

on_error() {
    printf '[squire] FAILED at step %d (%s): exit code %s, line %s\n' \
        "$SQUIRE_STEP_NO" "$SQUIRE_STEP_NAME" "$1" "$2" >&2
}
trap 'on_error $? $LINENO' ERR

# --- settings file --------------------------------------------------------------------------------------------------

# Citadel has no environment field, so release options reach this script as a configuration file it writes into the
# source tree (./.env.squire, kept out of git). The file is parsed, never sourced: KEY=VALUE lines only, no expansion,
# no command substitution, and only the release options below are taken. A value already in the environment wins.
squire_load_settings() {
    [ -f "$1" ] || return 0
    while IFS= read -r line || [ -n "$line" ]; do
        line="${line%"$(printf '\r')"}"
        case "$line" in
            '' | '#'* | *=*) ;;
            *) continue ;;
        esac
        key="${line%%=*}"
        value="${line#*=}"
        case "$key" in
            SQUIRE_FRAMEWORK | SQUIRE_ALLOW_ROOT | SQUIRE_REQUIRE_ENV | SQUIRE_WRITABLE_DIRS | \
                SQUIRE_COMPOSER_AUDIT | SQUIRE_NPM | SQUIRE_NPM_IGNORE_SCRIPTS | SQUIRE_NPM_BUILD_SCRIPT | \
                SQUIRE_MIGRATE | SQUIRE_ARTISAN_OPTIMIZE | SQUIRE_PROCTOR | SQUIRE_PROCTOR_BIN | \
                SQUIRE_PROCTOR_POLICY | SQUIRE_PROCTOR_RECORD_DIR | SQUIRE_SMOKE | SQUIRE_SMOKE_URL | SQUIRE_SMOKE_RETRIES | SQUIRE_SMOKE_DELAY) ;;
            *) continue ;;
        esac
        # The key is one of the names above, so this eval only tests whether that variable is already set.
        if eval "[ \"\${$key+set}\" = set ]"; then
            continue
        fi
        case "$value" in
            \"*\") value="${value#\"}"; value="${value%\"}" ;;
            \'*\') value="${value#\'}"; value="${value%\'}" ;;
        esac
        export "$key=$value"
    done <"$1"
}

: "${SQUIRE_APP_DIR:=}"
if [ -z "$SQUIRE_APP_DIR" ]; then
    SQUIRE_APP_DIR="$(cd "$(dirname "$0")/.." && pwd)"
fi
cd "$SQUIRE_APP_DIR"
squire_load_settings ./.env.squire

: "${SQUIRE_FRAMEWORK:=auto}"
: "${SQUIRE_ALLOW_ROOT:=0}"
: "${SQUIRE_REQUIRE_ENV:=auto}"
: "${SQUIRE_WRITABLE_DIRS:=}"
: "${SQUIRE_COMPOSER_AUDIT:=report}"
: "${SQUIRE_NPM:=auto}"
: "${SQUIRE_NPM_IGNORE_SCRIPTS:=1}"
: "${SQUIRE_NPM_BUILD_SCRIPT:=build}"
: "${SQUIRE_MIGRATE:=1}"
: "${SQUIRE_ARTISAN_OPTIMIZE:=1}"
: "${SQUIRE_PROCTOR:=auto}"
: "${SQUIRE_PROCTOR_BIN:=}"
: "${SQUIRE_PROCTOR_POLICY:=}"
: "${SQUIRE_PROCTOR_RECORD_DIR:=storage/squire}"
: "${SQUIRE_SMOKE:=1}"
: "${SQUIRE_SMOKE_URL:=}"
: "${SQUIRE_SMOKE_RETRIES:=5}"
: "${SQUIRE_SMOKE_DELAY:=2}"

# --- helpers --------------------------------------------------------------------------------------------------------

# Print the HTTP status of a URL, or 000 when nothing answers. curl first, then PHP, which the image always has.
http_status() {
    local url="$1" host_header="${2:-}"
    if command -v curl >/dev/null 2>&1; then
        local -a args=(--silent --output /dev/null --max-time 10 --write-out '%{http_code}')
        if [ -n "$host_header" ]; then
            args+=(--header "Host: ${host_header}")
        fi
        curl "${args[@]}" "$url" || true
    else
        # shellcheck disable=SC2016  # the PHP program is single-quoted on purpose
        php -r '
            $context = stream_context_create(["http" => ["timeout" => 10, "ignore_errors" => true,
                "header" => $argv[2] === "" ? "" : "Host: " . $argv[2]]]);
            @file_get_contents($argv[1], false, $context);
            preg_match("#^HTTP/\S+\s+(\d{3})#", $http_response_header[0] ?? "", $m);
            echo $m[1] ?? "000";
        ' -- "$url" "$host_header"
    fi
}

# --- start ----------------------------------------------------------------------------------------------------------

echo "[squire] release starting in $SQUIRE_APP_DIR at $(date -u '+%Y-%m-%dT%H:%M:%SZ')"

step "check preconditions"
if [ "$(id -u)" -eq 0 ] && [ "$SQUIRE_ALLOW_ROOT" != "1" ]; then
    fail "refusing to run as root: files it creates could not be written by the web process (set SQUIRE_ALLOW_ROOT=1 to override)"
fi
[ -f composer.json ] || fail "composer.json not found in $SQUIRE_APP_DIR"
[ -f composer.lock ] || fail "composer.lock not found; commit it, the lock file is the release (S13)"
command -v php >/dev/null 2>&1 || fail "php not found on PATH"
command -v composer >/dev/null 2>&1 || fail "composer not found on PATH"

framework="$SQUIRE_FRAMEWORK"
if [ "$framework" = "auto" ]; then
    if [ -f artisan ]; then framework="laravel"; else framework="raw"; fi
fi
case "$framework" in
    laravel | raw) ;;
    *) fail "SQUIRE_FRAMEWORK must be auto, laravel or raw; got '$framework'" ;;
esac
note "framework: $framework"

require_env="$SQUIRE_REQUIRE_ENV"
if [ "$require_env" = "auto" ]; then
    if [ "$framework" = "laravel" ]; then require_env=1; else require_env=0; fi
fi
if [ "$require_env" = "1" ] && [ ! -f .env ]; then
    fail ".env not found; create it from .env.example in the Citadel dashboard before the first release"
fi

if [ -z "$SQUIRE_SMOKE_URL" ]; then
    if [ "$framework" = "laravel" ]; then
        SQUIRE_SMOKE_URL="http://127.0.0.1:80/up"
    else
        SQUIRE_SMOKE_URL="http://127.0.0.1:80/up.php"
    fi
fi

step "create writable directories"
writable="$SQUIRE_WRITABLE_DIRS"
if [ -z "$writable" ] && [ "$framework" = "laravel" ]; then
    writable="storage bootstrap/cache"
fi
for dir in $writable; do
    mkdir -p "$dir"
    note "ready: $dir"
done

step "composer install from the lock file, without dev packages"
export COMPOSER_NO_INTERACTION=1
# Composer is installed or updated at every container start, so its version moves. Log it (S58).
composer --version
composer install --no-dev --optimize-autoloader --no-interaction --no-progress --prefer-dist

step "composer audit"
case "$SQUIRE_COMPOSER_AUDIT" in
    skip) note "skipped (SQUIRE_COMPOSER_AUDIT=skip)" ;;
    fail) composer audit --locked --no-interaction ;;
    report)
        audit_status=0
        composer audit --locked --no-interaction || audit_status=$?
        if [ "$audit_status" -ne 0 ]; then
            warn "composer audit exited $audit_status (report only; set SQUIRE_COMPOSER_AUDIT=fail to stop the release)"
        fi
        ;;
    *) fail "SQUIRE_COMPOSER_AUDIT must be report, fail or skip; got '$SQUIRE_COMPOSER_AUDIT'" ;;
esac

# PROCTOR runs before the frontend build and the migrations, so an enforced no-go stops the release before it touches
# the database. With a PROCTOR that has `proctor gate`, the step asks the deploy gate (ADR-0006) for a go or no-go,
# ratcheted against the verdict of the last release that went on; an older PROCTOR gets `proctor check`, as before.
proctor_mode="$SQUIRE_PROCTOR"
case "$proctor_mode" in
    auto | skip | enforce) ;;
    *) fail "SQUIRE_PROCTOR must be auto, skip or enforce; got '$proctor_mode'" ;;
esac
if [ "$proctor_mode" = "enforce" ]; then
    step "PROCTOR gate (enforced, a no-go stops the release)"
else
    step "PROCTOR gate (report only, never stops the release)"
fi
proctor_bin="$SQUIRE_PROCTOR_BIN"
if [ "$proctor_mode" = "skip" ]; then
    note "skipped (SQUIRE_PROCTOR=skip)"
else
    if [ -z "$proctor_bin" ]; then
        if [ -x vendor/bin/proctor ]; then
            proctor_bin="vendor/bin/proctor"
        elif command -v proctor >/dev/null 2>&1; then
            proctor_bin="proctor"
        fi
    fi
    if [ "$proctor_mode" = "enforce" ] && [ -n "$SQUIRE_PROCTOR_BIN" ] && [ ! -x "$SQUIRE_PROCTOR_BIN" ]; then
        fail "SQUIRE_PROCTOR_BIN is not an executable file: $SQUIRE_PROCTOR_BIN"
    fi
    if [ -z "$proctor_bin" ]; then
        if [ "$proctor_mode" = "enforce" ]; then
            fail "proctor not found (it is a dev dependency, so --no-dev leaves it out); set SQUIRE_PROCTOR_BIN to use it here"
        fi
        note "proctor not found (it is a dev dependency, so --no-dev leaves it out); set SQUIRE_PROCTOR_BIN to use it here"
    else
        # An explicit policy wins. When none is set, the policy CREED ships is used when it is installed; otherwise
        # PROCTOR runs on its own default policy. A missing CREED file never stops the release.
        policy_args=()
        if [ -n "$SQUIRE_PROCTOR_POLICY" ]; then
            policy_args=(--policy "$SQUIRE_PROCTOR_POLICY")
        elif [ -f vendor/waynestate/creed/policy/policy.json ]; then
            policy_args=(--policy vendor/waynestate/creed/policy/policy.json)
        else
            printf '%s\n' 'squire: CREED policy not installed; PROCTOR uses its own default policy'
        fi

        if "$proctor_bin" gate --help >/dev/null 2>&1; then
            # The verdict of every gated release is kept in the record folder; the verdict of the last release that
            # went on is the next baseline, so a failure already live is held and only a new one blocks.
            record_dir="$SQUIRE_PROCTOR_RECORD_DIR"
            mkdir -p "$record_dir"
            [ -f "$record_dir/.gitignore" ] || printf '%s\n' '*' >"$record_dir/.gitignore"
            verdict_file="$record_dir/proctor-gate.json"
            baseline_file="$record_dir/proctor-gate-baseline.json"
            gate_args=(gate --path . ${policy_args[@]+"${policy_args[@]}"} --record "$verdict_file")
            if [ -f "$baseline_file" ]; then
                gate_args+=(--baseline "$baseline_file")
                note "baseline: $baseline_file (the verdict of the last release that went on)"
            else
                note "no baseline yet: this is the first gated release, so every failure blocks"
            fi
            proctor_status=0
            "$proctor_bin" "${gate_args[@]}" || proctor_status=$?
            [ -f "$verdict_file" ] && note "verdict recorded in $verdict_file"
            case "$proctor_status" in
                0)
                    note "proctor gate: go"
                    if [ -f "$verdict_file" ]; then cp "$verdict_file" "$baseline_file"; fi
                    ;;
                1)
                    if [ "$proctor_mode" = "enforce" ]; then
                        fail "proctor gate: no-go (exit 1). The release stops before the frontend build and the migrations. Fix the BLOCK lines above, or record a dated override in policy-overrides.json, then re-run"
                    fi
                    warn "proctor gate: no-go (exit 1); the release goes on (report only; set SQUIRE_PROCTOR=enforce to stop it). Fix the BLOCK lines above in CI"
                    # The commit goes live anyway, so its verdict is the baseline the next release is measured against.
                    if [ -f "$verdict_file" ]; then cp "$verdict_file" "$baseline_file"; fi
                    ;;
                *)
                    if [ "$proctor_mode" = "enforce" ]; then
                        fail "proctor gate could not decide (exit $proctor_status)"
                    fi
                    warn "proctor gate could not decide (exit $proctor_status); the release goes on"
                    ;;
            esac
        else
            proctor_version="$("$proctor_bin" --version 2>/dev/null | head -n 1 || true)"
            warn "this PROCTOR (${proctor_version:-version unknown}) has no \`proctor gate\`, so the release runs \`proctor check\` instead, with no ratchet. Update waynestate/proctor to a release that has the gate"
            proctor_status=0
            "$proctor_bin" check --path . ${policy_args[@]+"${policy_args[@]}"} || proctor_status=$?
            case "$proctor_status" in
                0) note "proctor: pass" ;;
                1)
                    if [ "$proctor_mode" = "enforce" ]; then
                        fail "proctor reported failures (exit 1)"
                    fi
                    warn "proctor reported failures (exit 1); the release goes on, fix them in CI"
                    ;;
                *)
                    if [ "$proctor_mode" = "enforce" ]; then
                        fail "proctor itself failed (exit $proctor_status)"
                    fi
                    warn "proctor itself failed (exit $proctor_status); the release goes on"
                    ;;
            esac
        fi
    fi
fi

step "frontend install and build"
if [ "$SQUIRE_NPM" = "skip" ] || [ ! -f package.json ]; then
    note "skipped (no package.json, or SQUIRE_NPM=skip)"
else
    ignore_flag=""
    if [ "$SQUIRE_NPM_IGNORE_SCRIPTS" = "1" ]; then
        ignore_flag="--ignore-scripts"
    fi
    if [ -f package-lock.json ]; then
        command -v npm >/dev/null 2>&1 || fail "package-lock.json found but npm is not on PATH"
        npm --version
        # shellcheck disable=SC2086  # the flag is empty or one word
        npm ci $ignore_flag
        run_build=(npm run "$SQUIRE_NPM_BUILD_SCRIPT")
    elif [ -f pnpm-lock.yaml ]; then
        command -v corepack >/dev/null 2>&1 || fail "pnpm-lock.yaml found but corepack is not on PATH"
        # shellcheck disable=SC2086  # the flag is empty or one word
        corepack pnpm install --frozen-lockfile $ignore_flag
        run_build=(corepack pnpm run "$SQUIRE_NPM_BUILD_SCRIPT")
    else
        fail "package.json has no package-lock.json or pnpm-lock.yaml; commit a lock file, or set SQUIRE_NPM=skip if the built assets are committed"
    fi
    if grep -q "\"${SQUIRE_NPM_BUILD_SCRIPT}\"[[:space:]]*:" package.json; then
        "${run_build[@]}"
    else
        note "no \"${SQUIRE_NPM_BUILD_SCRIPT}\" script in package.json; nothing to build"
    fi
fi

if [ "$framework" = "laravel" ]; then
    step "laravel migrate"
    if [ "$SQUIRE_MIGRATE" = "1" ]; then
        php artisan migrate --force
    else
        note "skipped (SQUIRE_MIGRATE=0)"
    fi

    step "laravel optimize"
    if [ "$SQUIRE_ARTISAN_OPTIMIZE" = "1" ]; then
        # After this the config is cached and .env is no longer read, so env() works only inside config/ (S16).
        php artisan optimize
    else
        note "skipped (SQUIRE_ARTISAN_OPTIMIZE=0)"
    fi
fi

step "check Oracle (never fails the release)"
if [ -f oracle-check.php ]; then
    # Like the PROCTOR report above, a finding is logged and the release goes on: the check is never a gate.
    php oracle-check.php || true
else
    note "skipped (no oracle-check.php in the app root; the app does not use Oracle)"
fi

step "smoke test the health endpoint"
if [ "$SQUIRE_SMOKE" != "1" ]; then
    note "skipped (SQUIRE_SMOKE=0; Apache is not running inside the first-release hook)"
else
    smoke_host="${CITADEL_SERVER_NAME:-}"
    attempt=1
    status="000"
    while [ "$attempt" -le "$SQUIRE_SMOKE_RETRIES" ]; do
        status="$(http_status "$SQUIRE_SMOKE_URL" "$smoke_host")"
        note "attempt $attempt of $SQUIRE_SMOKE_RETRIES: $SQUIRE_SMOKE_URL -> HTTP $status"
        if [ "$status" = "200" ]; then
            break
        fi
        attempt=$((attempt + 1))
        if [ "$attempt" -le "$SQUIRE_SMOKE_RETRIES" ]; then
            sleep "$SQUIRE_SMOKE_DELAY"
        fi
    done
    [ "$status" = "200" ] || fail "$SQUIRE_SMOKE_URL answered HTTP $status, expected 200"
fi

echo "[squire] release ok in $(($(date +%s) - SQUIRE_STARTED_AT))s"
