#!/usr/bin/env bash
# scripts/parity.sh: run the app locally on the Citadel PHP image, behind a proxy, with MySQL 5.6 (Linux and macOS).
# The Windows twin is scripts/parity.ps1; both do the same thing. Written for bash 3.2 (the macOS default).
#
#   scripts/parity.sh up [--fallback]   build and start (add --fallback if you do not have the real Citadel image)
#   scripts/parity.sh down              stop and remove the containers (data volumes stay)
#   scripts/parity.sh reset             stop and DELETE the data volumes
#   scripts/parity.sh logs [service]    follow logs
#   scripts/parity.sh shell [service]   open a shell in a container (default: app)
#   scripts/parity.sh pin               print image digests to paste into .env.squire (S67)
#   scripts/parity.sh status            show container state and health
#
# A squire never rides out alone: this brings the proxy and the database along.
set -euo pipefail

cd "$(dirname "$0")/.."

COMPOSE_FILE="docker-compose.squire.yml"
ENV_FILE=".env.squire"
FALLBACK_TAG="squire/php-parity-base:8.4"

die() {
    echo "parity: $*" >&2
    exit 1
}

command -v docker >/dev/null 2>&1 || die "docker is not installed or not on PATH"
docker compose version >/dev/null 2>&1 || die "'docker compose' (Compose v2) is required"
[ -f "$COMPOSE_FILE" ] || die "$COMPOSE_FILE not found; run 'vendor/bin/squire init' first"

if [ ! -f "$ENV_FILE" ]; then
    [ -f ".env.squire.example" ] || die "$ENV_FILE and .env.squire.example are both missing"
    cp .env.squire.example "$ENV_FILE"
    echo "parity: created $ENV_FILE from the example; edit the passwords, then run this again" >&2
    exit 1
fi

compose() {
    docker compose --env-file "$ENV_FILE" -f "$COMPOSE_FILE" "$@"
}

cmd="${1:-up}"
[ "$#" -eq 0 ] || shift

case "$cmd" in
    up)
        use_fallback=0
        for arg in "$@"; do
            case "$arg" in
                --fallback) use_fallback=1 ;;
                *) die "unknown option for up: $arg" ;;
            esac
        done
        if [ "$use_fallback" -eq 1 ]; then
            echo "parity: building the fallback base image $FALLBACK_TAG (an approximation of Citadel's image)"
            docker build \
                --build-arg "CITADEL_SOURCE_UID=$(id -u)" \
                --build-arg "CITADEL_SOURCE_GID=$(id -g)" \
                -f .squire/base-fallback.Dockerfile -t "$FALLBACK_TAG" .squire
            export SQUIRE_BASE_IMAGE="$FALLBACK_TAG"
        else
            base="$(grep -E '^SQUIRE_BASE_IMAGE=' "$ENV_FILE" | tail -n 1 | cut -d= -f2- || true)"
            base="${base:-citadel/php-apache:8.4r5}"
            if ! docker image inspect "$base" >/dev/null 2>&1; then
                die "base image '$base' is not on this machine. Build it from the Citadel repository (php citadel build_images), pull it from your registry, or re-run with --fallback."
            fi
        fi
        if [ "$(uname -m)" = "arm64" ] || [ "$(uname -m)" = "aarch64" ]; then
            echo "parity: Apple Silicon or ARM detected. MySQL 5.6 and the real Citadel images are amd64, so Docker emulates them; builds can take 30 minutes or more." >&2
        fi
        compose up --build -d --wait
        echo "parity: up. Open http://127.0.0.1:$(grep -E '^SQUIRE_HTTP_PORT=' "$ENV_FILE" | tail -n 1 | cut -d= -f2- || echo 8080)"
        ;;
    down) compose down ;;
    reset) compose down --volumes ;;
    logs) compose logs --follow "$@" ;;
    shell) compose exec "${1:-app}" bash ;;
    status) compose ps ;;
    pin)
        for image in $(compose config --images | sort -u); do
            case "$image" in
                squire-local/*) continue ;;
            esac
            digest="$(docker image inspect --format '{{index .RepoDigests 0}}' "$image" 2>/dev/null || true)"
            echo "${image}  ->  ${digest:-not pulled yet (run 'up' first)}"
        done
        ;;
    *) die "unknown command '$cmd' (up, down, reset, logs, shell, status, pin)" ;;
esac
