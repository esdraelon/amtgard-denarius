#!/usr/bin/env bash
# Sourced from .greenblue after GB_HEV_* defaults. Overrides from .gb/secrets-mode.
set -euo pipefail

_denarius_secrets_mode_file="${GB_ROOT:-}/.gb/secrets-mode"
_denarius_secrets_mode="local"
if [[ -f "$_denarius_secrets_mode_file" ]]; then
    _denarius_secrets_mode="$(tr -d '[:space:]' < "$_denarius_secrets_mode_file")"
fi

case "$_denarius_secrets_mode" in
    plaintext)
        GB_HEV_SYNC_ON_DEV_UP="0"
        GB_HEV_RENDER_ON_DEV_UP="0"
        GB_HEV_SYNC_ON_DEPLOY="0"
        GB_HEV_RENDER_ON_DEPLOY="0"
        GB_HEV_MOUNT_MAP=""
        ;;
    local)
        GB_HEV_SYNC_SOURCE="local"
        GB_HEV_SYNC_ON_DEV_UP="${GB_HEV_SYNC_ON_DEV_UP:-1}"
        GB_HEV_RENDER_ON_DEV_UP="${GB_HEV_RENDER_ON_DEV_UP:-1}"
        GB_HEV_MOUNT_MAP="${GB_HEV_MOUNT_MAP:-denarius-web:DENARIUS_HEV_WEB_SECRETS denarius-worker:DENARIUS_HEV_WORKER_SECRETS denarius-connect:DENARIUS_HEV_CONNECT_SECRETS}"
        ;;
    host-store)
        GB_HEV_SYNC_SOURCE="host-store"
        GB_HEV_SYNC_ON_DEV_UP="${GB_HEV_SYNC_ON_DEV_UP:-1}"
        GB_HEV_RENDER_ON_DEV_UP="${GB_HEV_RENDER_ON_DEV_UP:-1}"
        GB_HEV_MOUNT_MAP="${GB_HEV_MOUNT_MAP:-denarius-web:DENARIUS_HEV_WEB_SECRETS denarius-worker:DENARIUS_HEV_WORKER_SECRETS denarius-connect:DENARIUS_HEV_CONNECT_SECRETS}"
        ;;
    *)
        echo "host-env/load-secrets-mode.sh: unknown mode '${_denarius_secrets_mode}' in ${_denarius_secrets_mode_file}" >&2
        exit 1
        ;;
esac

export GB_HEV_SYNC_SOURCE GB_HEV_SYNC_ON_DEV_UP GB_HEV_RENDER_ON_DEV_UP
export GB_HEV_SYNC_ON_DEPLOY GB_HEV_RENDER_ON_DEPLOY GB_HEV_MOUNT_MAP
