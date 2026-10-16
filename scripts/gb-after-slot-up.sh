#!/usr/bin/env bash
set -euo pipefail

slot="${1:?slot required}"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

WORKER_IMAGE="${WORKER_IMAGE:-amtgard-denarius-ledger-worker:latest}"
case "$slot" in
    blue) container="amtgard-denarius-blue" ;;
    green) container="amtgard-denarius-green" ;;
    *) exit 1 ;;
esac

if docker inspect "$container" >/dev/null 2>&1; then
    image_id="$(docker inspect -f '{{.Image}}' "$container")"
    docker tag "$image_id" "$WORKER_IMAGE"
fi
