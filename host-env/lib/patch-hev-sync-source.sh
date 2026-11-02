#!/usr/bin/env bash
# Patch host-config.toml [sync] source for Denarius dev (source, do not execute).
set -euo pipefail

hev_patch_sync_source() {
    local cfg="$1"
    local mode="$2"
    local dev_shared="$3"
    local secrets_dir="${dev_shared}/secrets"
    local denarius_catalog="${dev_shared}/state/denarius-local-catalog"
    local store_dir="${dev_shared}/state/store"
    local manifests_dir="${dev_shared}/state/manifests"

    local local_secrets=""
    local source=""
    case "$mode" in
        local)
            source="local"
            local_secrets="$secrets_dir"
            ;;
        host-store)
            source="host-store"
            local_secrets="$denarius_catalog"
            ;;
        plaintext)
            return 0
            ;;
        *)
            echo "hev_patch_sync_source: unknown mode ${mode}" >&2
            return 1
            ;;
    esac

    local tmp
    tmp="$(mktemp)"
    awk -v src="$source" -v secrets="$local_secrets" -v store="$store_dir" -v manifests="$manifests_dir" '
        BEGIN { in_sync=0 }
        /^\[sync\]/ { in_sync=1; print; next }
        /^\[/ && $0 != "[sync]" { in_sync=0 }
        in_sync && /^source *=/ { print "source = \"" src "\""; next }
        in_sync && /^local_secrets_dir *=/ { print "local_secrets_dir = \"" secrets "\""; next }
        in_sync && /^store_dir *=/ { print "store_dir = \"" store "\""; next }
        in_sync && /^manifests_dir *=/ { print "manifests_dir = \"" manifests "\""; next }
        { print }
    ' "$cfg" >"$tmp"
    mv "$tmp" "$cfg"
}
