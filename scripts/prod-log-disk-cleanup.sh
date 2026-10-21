#!/usr/bin/env bash
# One-time prod cleanup after deploying host log binds + json-file rotation.
# Run on the Denarius host with sudo. Adjust GB_BIN / DENARIUS_ROOT if needed.
set -euo pipefail

DENARIUS_ROOT="${DENARIUS_ROOT:-/var/www/denarius.amtgard.com}"
GB_BIN="${GB_BIN:-/var/gb/generic-green-blue-deployment/bin/gb}"
HEV_CONFIG="${HEV_CONFIG:-/etc/host-env-vault/config.toml}"
LOG_ROOT="${DENARIUS_HOST_LOG_DIR:-/var/log/amtgard-denarius}"

echo "== Stop worker (recreates json-file log handle) =="
sudo docker stop amtgard-denarius-ledger-worker 2>/dev/null || true
sudo docker rm amtgard-denarius-ledger-worker 2>/dev/null || true

echo "== Remove orphaned huge container json logs (if any) =="
sudo find /var/lib/docker/containers -name '*-json.log' -size +500M -print -delete 2>/dev/null || true

echo "== Prepare host log dirs =="
sudo install -d -m 0755 \
  "${LOG_ROOT}/web-blue" \
  "${LOG_ROOT}/web-green" \
  "${LOG_ROOT}/worker"
sudo cp "${DENARIUS_ROOT}/host/logrotate/amtgard-denarius" /etc/logrotate.d/amtgard-denarius 2>/dev/null || true

echo "== Drop legacy named log volumes (safe if stacks are down/recreated) =="
sudo docker volume rm amtgard-denarius-blue-logs amtgard-denarius-green-logs 2>/dev/null || true

echo "== Redeploy (recreates worker + web slots with new mounts) =="
if [[ -f "${HEV_CONFIG}" ]]; then
  sudo "${GB_BIN}" --hev-config "${HEV_CONFIG}" "${DENARIUS_ROOT}" deploy
else
  sudo "${GB_BIN}" "${DENARIUS_ROOT}" deploy
fi

echo "== Disk after cleanup =="
df -h / /var/lib/docker 2>/dev/null || df -h /
