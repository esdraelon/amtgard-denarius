#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

WEB_PROJECT="${WEB_PROJECT:-amtgard-denarius}"
WORKER_PROJECT="${WORKER_PROJECT:-amtgard-denarius-worker}"
APP_CONTAINER="${APP_CONTAINER:-amtgard-denarius}"

require_docker() {
    if ! docker info >/dev/null 2>&1; then
        echo "Docker is not running." >&2
        exit 1
    fi
}

dotenv_get() {
    local key="$1"
    local default="${2:-}"
    if [[ ! -f "$ROOT/.env" ]]; then
        echo "$default"
        return
    fi
    local line
    line="$(grep -E "^${key}=" "$ROOT/.env" | tail -1 || true)"
    if [[ -z "$line" ]]; then
        echo "$default"
        return
    fi
    local val="${line#*=}"
    val="${val//$'\r'/}"
    val="${val#\"}"
    val="${val%\"}"
    val="${val#\'}"
    val="${val%\'}"
    echo "$val"
}

compose_web_dev() {
    docker compose --project-directory "$ROOT" -p "$WEB_PROJECT" \
        -f docker/compose.prod.yml \
        -f docker/compose.blue.yml \
        -f docker/compose.dev.yml \
        "$@"
}

compose_worker_dev() {
    docker compose --project-directory "$ROOT" -p "$WORKER_PROJECT" \
        -f docker/compose.worker.yml \
        -f docker/compose.worker.dev.yml \
        "$@"
}

require_docker

if ! docker inspect "$APP_CONTAINER" >/dev/null 2>&1; then
    echo "App container ${APP_CONTAINER} is not present; nothing to restore."
    exit 0
fi

DEV_DB_HOST="$(dotenv_get DB_HOST amtgard-denarius-db)"
DEV_DB_NAME="$(dotenv_get DB_NAME denarius)"
DEV_SESSION_REDIS_HOST="$(dotenv_get SESSION_REDIS_HOST amtgard-denarius-sessions)"
DEV_REDIS_HOST="$(dotenv_get REDIS_HOST amtgard-denarius-sessions)"

echo "==> Restoring normal dev overlay (ENVIRONMENT=DEV, DB/Redis hosts from .env)..."
compose_web_dev up -d --force-recreate denariusapp

echo "==> Wiring php-fpm env for dev (DB/Redis hosts from .env)..."
docker exec "$APP_CONTAINER" bash -lc "
    POOL=/etc/php/8.4/fpm/pool.d/www.conf
    sed -i '/^env\[DB_HOST\]/d' \"\$POOL\"
    sed -i '/^env\[DB_NAME\]/d' \"\$POOL\"
    sed -i '/^env\[SESSION_REDIS_HOST\]/d' \"\$POOL\"
    sed -i '/^env\[REDIS_HOST\]/d' \"\$POOL\"
    sed -i '/^env\[ENVIRONMENT\]/d' \"\$POOL\"
    for key in TELLER_APPLICATION_ID TELLER_ENVIRONMENT TELLER_WEBHOOK_SECRET STRIPE_SECRET_KEY STRIPE_PUBLISHABLE_KEY STRIPE_WEBHOOK_SECRET PLAID_CLIENT_ID PLAID_SECRET; do
        sed -i \"/^env\\\\[\${key}\\\\]/d\" \"\$POOL\"
    done
    echo \"env[ENVIRONMENT] = DEV\" >> \"\$POOL\"
    echo \"env[DB_HOST] = ${DEV_DB_HOST}\" >> \"\$POOL\"
    echo \"env[DB_NAME] = ${DEV_DB_NAME}\" >> \"\$POOL\"
    echo \"env[SESSION_REDIS_HOST] = ${DEV_SESSION_REDIS_HOST}\" >> \"\$POOL\"
    echo \"env[REDIS_HOST] = ${DEV_REDIS_HOST}\" >> \"\$POOL\"
    service php8.4-fpm restart
"

environment="$(docker exec "$APP_CONTAINER" printenv ENVIRONMENT || true)"
if [[ "$environment" != "DEV" ]]; then
    echo "Expected ENVIRONMENT=DEV after integ-down, got: ${environment:-<unset>}" >&2
    exit 1
fi

db_host="$(docker exec "$APP_CONTAINER" printenv DB_HOST || true)"
session_redis_host="$(docker exec "$APP_CONTAINER" printenv SESSION_REDIS_HOST || true)"
redis_host="$(docker exec "$APP_CONTAINER" printenv REDIS_HOST || true)"
if [[ "$db_host" != "$DEV_DB_HOST" ]]; then
    echo "Expected DB_HOST=${DEV_DB_HOST} after integ-down, got: ${db_host:-<unset>}" >&2
    exit 1
fi
if [[ "$session_redis_host" != "$DEV_SESSION_REDIS_HOST" ]]; then
    echo "Expected SESSION_REDIS_HOST=${DEV_SESSION_REDIS_HOST} after integ-down, got: ${session_redis_host:-<unset>}" >&2
    exit 1
fi
if [[ "$redis_host" != "$DEV_REDIS_HOST" ]]; then
    echo "Expected REDIS_HOST=${DEV_REDIS_HOST} after integ-down, got: ${redis_host:-<unset>}" >&2
    exit 1
fi

if ! curl -sf --max-time 5 "http://localhost:37180/version" >/dev/null 2>&1; then
    echo "Dev app did not respond at http://localhost:37180/version after integ-down" >&2
    exit 1
fi

echo "==> Restoring ledger-worker for dev (.env DB/Redis)..."
compose_worker_dev up -d --force-recreate

echo "Dev stack restored."
