#!/usr/bin/env bash
# squire-entrypoint: one image, several process types, for the portable path (OpenShift, Coolify, raw Docker).
#
# The portable image (.squire/portable.Dockerfile) installs this file as /usr/local/bin/squire-entrypoint. Citadel
# never runs it: Citadel generates its own Dockerfile, and .squire/Dockerfile mirrors that one unchanged.
#
# What the container is comes from its first argument, else SQUIRE_ROLE, else web:
#
#   web        Apache and mod_php on SQUIRE_PORT (else PORT, else 8080); SIGTERM is a graceful stop
#   worker     php artisan queue:work --tries=3 --max-time=3600 --sleep=3   (SQUIRE_WORKER_COMMAND replaces it)
#   scheduler  php artisan schedule:work                                    (SQUIRE_SCHEDULER_COMMAND replaces it)
#   migrate    php artisan migrate --force, once per release, as a job       (SQUIRE_MIGRATE_COMMAND replaces it)
#   health     the image health check: health.php for web; the other roles have no endpoint to ask
#
# Arguments after a role are passed on (`worker --queue=high,default`; a later option wins), and options alone go to
# the SQUIRE_ROLE process (Compose `command: ["--queue=high"]` beside SQUIRE_ROLE=worker). Any other first argument
# is run as a command, so `docker run IMAGE php artisan about` works. The Laravel roles run `php artisan optimize`
# first, so config is cached when the container starts and never at image build (WELD W10); SQUIRE_ARTISAN_OPTIMIZE=0
# skips it. A queue worker and schedule:work both finish their current work on SIGTERM, so a rolling deploy replaces
# them without a restart signal; scripts/release.sh's SQUIRE_ARTISAN_RESTART step stays the Citadel answer.
#
# A squire who only polishes armor is a valet. This one also mucks out the stables and keeps the night watch.
set -euo pipefail

say() {
    printf '[squire] %s\n' "$*" >&2
}

die() {
    printf '[squire] FAILED: %s\n' "$*" >&2
    exit 64
}

# squire-secrets: begin
# Secrets arrive as files at run time, never in an image layer. Each regular file in SQUIRE_SECRETS_DIR
# (default /run/secrets, where Docker, Compose and Kubernetes mount them) whose name is an environment variable name
# becomes that variable, with one trailing line ending trimmed. A variable the environment already sets wins. Only names
# are ever printed, never values. This block is the same in scripts/release.sh and the fallback entrypoint.
squire_load_secrets() {
    local dir="${SQUIRE_SECRETS_DIR:-/run/secrets}" file name value loaded=""
    [ -d "$dir" ] || return 0
    for file in "$dir"/*; do
        [ -f "$file" ] || continue
        name="${file##*/}"
        case "$name" in
            '' | [!A-Za-z_]* | *[!A-Za-z0-9_]*) continue ;;
            PATH | HOME | IFS | ENV | BASH_ENV | SHELL | SHELLOPTS | PS4 | LD_* | SQUIRE_SECRETS_DIR) continue ;;
        esac
        # The name holds only letters, digits and underscores, so this eval only tests whether it is already set.
        if eval "[ \"\${$name+set}\" = set ]"; then
            continue
        fi
        if [ ! -r "$file" ]; then
            printf '[squire] WARNING: secret %s in %s is not readable by this user; skipped\n' "$name" "$dir" >&2
            continue
        fi
        value="$(cat "$file"; printf x)"
        value="${value%x}"
        value="${value%$'\n'}"
        value="${value%$'\r'}"
        export "$name=$value"
        loaded="$loaded $name"
    done
    if [ -n "$loaded" ]; then
        printf '[squire] secrets from %s:%s\n' "$dir" "$loaded" >&2
    fi
    return 0
}
# squire-secrets: end

app="${SQUIRE_APP_DIR:-/citadel/src}"
kit="${SQUIRE_KIT_DIR:-.}"
role_file="${SQUIRE_ROLE_FILE:-/tmp/squire-role}"

# The port Apache listens on and the health check asks: SQUIRE_PORT, else PORT (the name Heroku-style platforms and
# Coolify set), else 8080. A user that is not root cannot bind below 1024, so that is refused up front.
squire_port() {
    local port="${SQUIRE_PORT:-${PORT:-8080}}"
    case "$port" in
        '' | *[!0-9]*) die "SQUIRE_PORT (or PORT) must be a port number; got '$port'" ;;
    esac
    if [ "$port" -gt 65535 ]; then
        die "SQUIRE_PORT (or PORT) must be 65535 or lower; got '$port'"
    fi
    if [ "$port" -lt 1024 ] && [ "$(id -u)" -ne 0 ]; then
        die "SQUIRE_PORT (or PORT) is $port, but a user that is not root cannot listen below 1024; use 8080"
    fi
    printf '%s' "$port"
}

is_laravel() {
    [ -f "$app/artisan" ]
}

# Config, routes, views and events cached for this container's environment, at start (WELD W10).
optimize() {
    if ! is_laravel || [ "${SQUIRE_ARTISAN_OPTIMIZE:-1}" = "0" ]; then
        return 0
    fi
    say "php artisan optimize"
    php artisan optimize >&2
}

# Run a command given as one string in an environment variable, after optimize when the second argument is 1. read -a
# splits it on spaces and never expands a glob.
exec_command_from() {
    local name="$1" optimize_first="$2" words=()
    shift 2
    read -r -a words <<<"${!name}"
    [ "${#words[@]}" -gt 0 ] || die "$name is set but empty"
    if [ "$optimize_first" = 1 ]; then optimize; fi
    say "$name: ${words[*]} $*"
    exec "${words[@]}" "$@"
}

run_web() {
    SQUIRE_PORT="$(squire_port)"
    export SQUIRE_PORT
    optimize
    say "web: Apache on port $SQUIRE_PORT as uid $(id -u), gid $(id -g)"
    # Apache stops gracefully on SIGWINCH (in-flight requests finish) and at once on SIGTERM, which is what every
    # platform sends. So this shell stays as a small init: it turns SIGTERM and SIGINT into SIGWINCH for Apache, then
    # exits with Apache's own status. The queue roles below exec PHP directly, because PHP handles SIGTERM itself.
    local child="" status=0
    trap 'if [ -n "$child" ]; then kill -WINCH "$child" 2>/dev/null || true; fi' TERM INT WINCH
    apache2-foreground "$@" &
    child=$!
    while :; do
        if wait "$child"; then status=0; else status=$?; fi
        kill -0 "$child" 2>/dev/null || break
    done
    exit "$status"
}

run_worker() {
    if [ -n "${SQUIRE_WORKER_COMMAND+set}" ]; then
        exec_command_from SQUIRE_WORKER_COMMAND 1 "$@"
    fi
    is_laravel || die "worker: $app has no artisan, so there is no default queue worker; set SQUIRE_WORKER_COMMAND to the command that runs your queue"
    optimize
    say "worker: php artisan queue:work --tries=3 --max-time=3600 --sleep=3 $*"
    # --max-time recycles the process every hour (the platform restarts it); --tries=3 stops a failing job retrying
    # forever. The default --timeout (60) stays below the default retry_after (90), so no job runs twice (WELD W20).
    exec php artisan queue:work --tries=3 --max-time=3600 --sleep=3 "$@"
}

run_scheduler() {
    if [ -n "${SQUIRE_SCHEDULER_COMMAND+set}" ]; then
        exec_command_from SQUIRE_SCHEDULER_COMMAND 1 "$@"
    fi
    is_laravel || die "scheduler: $app has no artisan, so there is no default scheduler; set SQUIRE_SCHEDULER_COMMAND to the command that runs your schedule"
    optimize
    say "scheduler: php artisan schedule:work $*"
    exec php artisan schedule:work "$@"
}

run_migrate() {
    if [ -n "${SQUIRE_MIGRATE_COMMAND+set}" ]; then
        exec_command_from SQUIRE_MIGRATE_COMMAND 0 "$@"
    fi
    if ! is_laravel; then
        say "migrate: $app has no artisan and SQUIRE_MIGRATE_COMMAND is not set; nothing to migrate"
        exit 0
    fi
    say "migrate: php artisan migrate --force $*"
    exec php artisan migrate --force "$@"
}

run_health() {
    local role
    role="$(cat "$role_file" 2>/dev/null || true)"
    role="${role:-${SQUIRE_ROLE:-web}}"
    if [ "$role" != "web" ]; then
        printf 'healthy: the %s role has no HTTP endpoint; it is healthy while its process runs\n' "$role"
        exit 0
    fi
    SQUIRE_HEALTH_PORT="${SQUIRE_HEALTH_PORT:-$(squire_port)}"
    export SQUIRE_HEALTH_PORT
    exec php "$app/$kit/health.php"
}

role="${SQUIRE_ROLE:-web}"
if [ "$#" -gt 0 ]; then
    case "$1" in
        web | worker | scheduler | migrate | health)
            role="$1"
            shift
            ;;
        apache2-foreground)
            # The base image's own command, passed on by a platform that copies it: that is the web role.
            role="web"
            shift
            ;;
        -*)
            # Options only: they go to the SQUIRE_ROLE process (Apache's flags for web, the way the official PHP
            # images take them; `--queue=high` for a worker).
            ;;
        *)
            role="command"
            ;;
    esac
fi

if [ "$role" = "health" ]; then
    cd "$app"
    run_health
fi

squire_load_secrets || exit 1
cd "$app" || die "the app folder $app does not exist"

case "$role" in
    web | worker | scheduler | migrate)
        # The health check runs in its own process and cannot see this one's arguments, so leave the role for it.
        printf '%s\n' "$role" >"$role_file" 2>/dev/null || true
        ;;
esac

case "$role" in
    web) run_web "$@" ;;
    worker) run_worker "$@" ;;
    scheduler) run_scheduler "$@" ;;
    migrate) run_migrate "$@" ;;
    command)
        say "running: $*"
        exec "$@"
        ;;
    *) die "SQUIRE_ROLE must be web, worker, scheduler or migrate; got '$role'" ;;
esac
