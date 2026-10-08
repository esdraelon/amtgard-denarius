#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

NETWORK="${NETWORK:-amtgard-denarius-shared}"
INTEG_PROJECT="${INTEG_PROJECT:-amtgard-denarius-integ}"
WEB_PROJECT="${WEB_PROJECT:-amtgard-denarius}"
SESSIONS_PROJECT="${SESSIONS_PROJECT:-amtgard-denarius-sessions}"
WORKER_PROJECT="${WORKER_PROJECT:-amtgard-denarius-worker}"
APP_CONTAINER="${APP_CONTAINER:-amtgard-denarius}"
INTEG_DB_CONTAINER="${INTEG_DB_CONTAINER:-amtgard-denarius-db-integ}"
INTEG_SESSIONS_CONTAINER="${INTEG_SESSIONS_CONTAINER:-amtgard-denarius-sessions-integ}"

require_docker() {
    if ! docker info >/dev/null 2>&1; then
        echo "Docker is not running." >&2
        exit 1
    fi
}

compose_integ_infra() {
    docker compose --project-directory "$ROOT" -p "$INTEG_PROJECT" \
        -f docker/compose.integ-infra.yml \
        "$@"
}

compose_sessions() {
    docker compose --project-directory "$ROOT" -p "$SESSIONS_PROJECT" \
        -f docker/compose.sessions.yml \
        -f docker/compose.sessions.dev.yml \
        "$@"
}

compose_web_dev() {
    docker compose --project-directory "$ROOT" -p "$WEB_PROJECT" \
        -f docker/compose.prod.yml \
        -f docker/compose.blue.yml \
        -f docker/compose.dev.yml \
        "$@"
}

compose_web_integ() {
    docker compose --project-directory "$ROOT" -p "$WEB_PROJECT" \
        -f docker/compose.prod.yml \
        -f docker/compose.blue.yml \
        -f docker/compose.dev.yml \
        -f docker/compose.integ.yml \
        "$@"
}

compose_worker() {
    docker compose --project-directory "$ROOT" -p "$WORKER_PROJECT" \
        -f docker/compose.worker.yml \
        -f docker/compose.worker.dev.yml \
        -f docker/compose.worker.integ.yml \
        "$@"
}

ensure_shared_network() {
    if docker network inspect "$NETWORK" >/dev/null 2>&1; then
        return
    fi
    echo "==> Creating Docker network ${NETWORK}..."
    docker network create "$NETWORK"
}

wait_for_integ_db() {
    local attempt
    for attempt in $(seq 1 30); do
        if docker exec "$INTEG_DB_CONTAINER" mariadb-admin ping -uroot -proot --silent >/dev/null 2>&1; then
            return 0
        fi
        sleep 1
    done
    echo "Timed out waiting for MariaDB in ${INTEG_DB_CONTAINER}" >&2
    return 1
}

wait_for_app() {
    local attempt
    for attempt in $(seq 1 30); do
        if curl -sf --max-time 2 "http://localhost:37180/version" >/dev/null 2>&1; then
            return 0
        fi
        sleep 1
    done
    echo "Timed out waiting for http://localhost:37180/version" >&2
    return 1
}

require_docker
ensure_shared_network

echo "==> Starting integ infra (${INTEG_PROJECT}: DB + session Redis)..."
compose_integ_infra up -d
wait_for_integ_db

echo "==> Starting sessions (${SESSIONS_PROJECT})..."
compose_sessions up -d

echo "==> Applying integ overlay on web stack (${WEB_PROJECT})..."
compose_web_integ up -d --build --remove-orphans --force-recreate denariusapp

echo "==> Wiring php-fpm env for integ (DB/Redis hosts, ENVIRONMENT)..."
DB_HOST="$(docker exec "$APP_CONTAINER" printenv DB_HOST || true)"
DB_NAME="$(docker exec "$APP_CONTAINER" printenv DB_NAME || true)"
SESSION_REDIS_HOST="$(docker exec "$APP_CONTAINER" printenv SESSION_REDIS_HOST || true)"
REDIS_HOST="$(docker exec "$APP_CONTAINER" printenv REDIS_HOST || true)"
docker exec "$APP_CONTAINER" bash -lc "
    POOL=/etc/php/8.4/fpm/pool.d/www.conf
    sed -i '/^env\[DB_HOST\]/d' \"\$POOL\"
    sed -i '/^env\[DB_NAME\]/d' \"\$POOL\"
    sed -i '/^env\[SESSION_REDIS_HOST\]/d' \"\$POOL\"
    sed -i '/^env\[REDIS_HOST\]/d' \"\$POOL\"
    sed -i '/^env\[ENVIRONMENT\]/d' \"\$POOL\"
    echo \"env[ENVIRONMENT] = DEV_INTEG\" >> \"\$POOL\"
    echo \"env[DB_HOST] = ${DB_HOST}\" >> \"\$POOL\"
    echo \"env[DB_NAME] = ${DB_NAME}\" >> \"\$POOL\"
    echo \"env[SESSION_REDIS_HOST] = ${SESSION_REDIS_HOST}\" >> \"\$POOL\"
    echo \"env[REDIS_HOST] = ${REDIS_HOST}\" >> \"\$POOL\"
    service php8.4-fpm restart
"

echo "==> Flushing integ session Redis..."
SESSION_REDIS_DB="$(docker exec "$APP_CONTAINER" printenv SESSION_REDIS_DB || echo 1)"
docker exec "$INTEG_SESSIONS_CONTAINER" redis-cli -n "$SESSION_REDIS_DB" FLUSHDB

echo "==> Flushing integ ledger Redis (month cache + refresh queue)..."
LEDGER_REDIS_DB="$(docker exec "$APP_CONTAINER" printenv REDIS_DB || echo 0)"
docker exec "$INTEG_SESSIONS_CONTAINER" redis-cli -n "$LEDGER_REDIS_DB" FLUSHDB

echo "==> Migrating integ database (schema ${DB_NAME} on ${INTEG_DB_CONTAINER})..."
docker exec "$APP_CONTAINER" bash -lc \
    'cd /var/www/denarius.amtgard.com && vendor/robmorgan/phinx/bin/phinx migrate'

echo "==> Seeding integ fixtures..."
docker exec "$APP_CONTAINER" bash -lc \
    'cd /var/www/denarius.amtgard.com && php tests/Integration/seed.php'

echo "==> Starting ledger-worker (${WORKER_PROJECT})..."
compose_worker up -d --build

echo "==> Waiting for app health..."
wait_for_app

echo "Integ stack is up (ENVIRONMENT=DEV_INTEG, DB_HOST=${DB_HOST}, DB_NAME=${DB_NAME}, SESSION_REDIS_HOST=${SESSION_REDIS_HOST})."
