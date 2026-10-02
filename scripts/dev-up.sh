#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"
NETWORK="${NETWORK:-amtgard-denarius-shared}"
WEB_PROJECT="${WEB_PROJECT:-amtgard-denarius}"
SESSIONS_PROJECT="${SESSIONS_PROJECT:-amtgard-denarius-sessions}"
WORKER_PROJECT="${WORKER_PROJECT:-amtgard-denarius-worker}"

ensure_shared_network() {
    if docker network inspect "$NETWORK" >/dev/null 2>&1; then
        return
    fi
    docker network create "$NETWORK"
}

compose_sessions() {
    docker compose --project-directory "$ROOT" -p "$SESSIONS_PROJECT" \
        -f docker/compose.sessions.yml \
        -f docker/compose.sessions.dev.yml \
        "$@"
}

compose_web() {
    docker compose --project-directory "$ROOT" -p "$WEB_PROJECT" \
        -f docker/compose.prod.yml \
        -f docker/compose.blue.yml \
        -f docker/compose.dev.yml \
        "$@"
}

compose_worker() {
    docker compose --project-directory "$ROOT" -p "$WORKER_PROJECT" \
        -f docker/compose.worker.yml \
        -f docker/compose.worker.dev.yml \
        "$@"
}

up() {
    ensure_shared_network
    compose_sessions up -d
    compose_web up -d --build --remove-orphans
    compose_worker up -d --build
}

case "${1:-up}" in
    up) up ;;
    test) compose_web --profile test run --rm test ;;
    *) echo "Usage: $0 [up|test]" >&2; exit 1 ;;
esac
