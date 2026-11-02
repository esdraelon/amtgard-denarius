#!/usr/bin/env bash
# Migrate Denarius secrets into host-env-vault transition mode (encrypted host store).
# Usage:
#   migrate-denarius-secrets-to-host-store.sh /path/to/denarius [--from auto|plaintext|local]
#       [--backend sqlite|flat] [--hev-config PATH] [--non-interactive]
set -euo pipefail

usage() {
    cat <<'EOF'
Usage: migrate-denarius-secrets-to-host-store.sh INSTALL_DIR [options]

  INSTALL_DIR   Denarius checkout or production tree (/var/www/denarius.amtgard.com).

Options:
  --from auto|plaintext|local   Source layout (default: auto)
  --backend sqlite|flat         New profile store files (default: sqlite)
  --hev-config PATH             host-config.toml (default: vault dev-shared/host-config.toml)
  --non-interactive             Required for non-TTY store import
  -h, --help

Invokes host-env-vault store init/import, validate, sync; patches host-config for host-store.
EOF
}

INSTALL_DIR=""
FROM="auto"
BACKEND="sqlite"
HEV_CONFIG=""
NON_INTERACTIVE=0

while [[ $# -gt 0 ]]; do
    case "$1" in
        -h | --help)
            usage
            exit 0
            ;;
        --from)
            FROM="$2"
            shift 2
            ;;
        --backend)
            BACKEND="$2"
            shift 2
            ;;
        --hev-config)
            HEV_CONFIG="$2"
            shift 2
            ;;
        --non-interactive)
            NON_INTERACTIVE=1
            shift
            ;;
        -*)
            echo "unknown option: $1" >&2
            usage >&2
            exit 1
            ;;
        *)
            if [[ -z "$INSTALL_DIR" ]]; then
                INSTALL_DIR="$1"
                shift
            else
                echo "unexpected argument: $1" >&2
                exit 1
            fi
            ;;
    esac
done

if [[ -z "$INSTALL_DIR" ]]; then
    usage >&2
    exit 1
fi

INSTALL_DIR="$(cd "$INSTALL_DIR" && pwd)"
ROOT="$INSTALL_DIR"

# shellcheck source=host-env/lib/resolve-hev.sh disable=SC1091
source "${ROOT}/host-env/lib/resolve-hev.sh"

HEV_REPO="$(denarius_hev_repo "$ROOT")"
if [[ -z "$HEV_REPO" ]]; then
    echo "host-env-vault checkout not found (set GB_HEV_REPO or use ../host-env-vault)" >&2
    exit 1
fi

if [[ -z "$HEV_CONFIG" ]]; then
    if [[ -n "${GB_HEV_CONFIG:-}" && -f "${GB_HEV_CONFIG}" ]]; then
        HEV_CONFIG="$GB_HEV_CONFIG"
    elif [[ -f "${HEV_REPO}/dev-shared/host-config.toml" ]]; then
        HEV_CONFIG="${HEV_REPO}/dev-shared/host-config.toml"
    else
        echo "missing host-config.toml; pass --hev-config" >&2
        exit 1
    fi
fi
HEV_CONFIG="$(cd "$(dirname "$HEV_CONFIG")" && pwd)/$(basename "$HEV_CONFIG")"
DEV_SHARED="$(dirname "$HEV_CONFIG")"
SECRETS_DIR="${DEV_SHARED}/secrets"
STATE_DIR="${DEV_SHARED}/state"
STORE_DIR="${STATE_DIR}/store"
MANIFESTS_DIR="${STATE_DIR}/manifests"
MANIFEST_SRC="${ROOT}/host-env/manifests"

HEV_BIN="$(denarius_hev_bin "$HEV_REPO")"

hev() {
    denarius_hev_invoke "$HEV_REPO" "$HEV_BIN" "$@"
}

hev_store() {
    local -a store_args=(store)
    [[ "$NON_INTERACTIVE" -eq 1 ]] && store_args=(store --non-interactive)
    hev --config dev-shared/host-config.toml "${store_args[@]}" "$@"
}

echo "==> Refresh Denarius catalog fragment"
export GB_ROOT="$ROOT"
bash "${ROOT}/scripts/gb-pre-hev-sync.sh"

if [[ ! -f "${SECRETS_DIR}/catalog.json" ]]; then
    echo "missing ${SECRETS_DIR}/catalog.json (run host-env-vault local-shared-bootstrap.sh)" >&2
    exit 1
fi

detect_from() {
    if [[ "$FROM" != "auto" ]]; then
        echo "$FROM"
        return
    fi
    if [[ -f "${SECRETS_DIR}/denarius-web.env" ]]; then
        echo "local"
        return
    fi
    if [[ -f "${ROOT}/.env" ]]; then
        echo "plaintext"
        return
    fi
    echo "local"
}

FROM="$(detect_from)"
IMPORT_DIR=""
IMPORT_CLEANUP=""

if [[ "$FROM" == "plaintext" ]]; then
    if [[ ! -f "${ROOT}/.env" ]]; then
        echo "plaintext mode requires ${ROOT}/.env" >&2
        exit 1
    fi
    if ! command -v jq >/dev/null 2>&1; then
        echo "jq required for plaintext extraction" >&2
        exit 1
    fi
    IMPORT_DIR="$(mktemp -d "${TMPDIR:-/tmp}/denarius-secrets-import.XXXXXX")"
    IMPORT_CLEANUP="$IMPORT_DIR"
    cp "${SECRETS_DIR}/catalog.json" "${IMPORT_DIR}/catalog.json"
    mkdir -p "${IMPORT_DIR}/files"

    extract_env_value() {
        local key="$1"
        local line
        line="$(grep -E "^${key}=" "${ROOT}/.env" | tail -1 || true)"
        [[ -z "$line" ]] && return
        echo "${line#*=}"
    }

    for profile in denarius-web denarius-worker denarius-connect; do
        env_file="${IMPORT_DIR}/${profile}.env"
        : >"$env_file"
        while IFS= read -r key; do
            [[ -z "$key" ]] && continue
            val="$(extract_env_value "$key")"
            [[ -n "$val" ]] && printf '%s=%s\n' "$key" "$val" >>"$env_file"
        done < <(jq -r --arg p "$profile" '.profiles[] | select(.name == $p) | (.inject // [])[]' \
            "${IMPORT_DIR}/catalog.json")

        while IFS= read -r fname; do
            [[ -z "$fname" ]] && continue
            src=""
            if [[ -f "${SECRETS_DIR}/files/${fname}" ]]; then
                src="${SECRETS_DIR}/files/${fname}"
            elif [[ -f "${ROOT}/keys/${fname}" ]]; then
                src="${ROOT}/keys/${fname}"
            fi
            [[ -n "$src" ]] && cp "$src" "${IMPORT_DIR}/files/${fname}"
        done < <(jq -r --arg p "$profile" '.profiles[] | select(.name == $p) | (.inject_files // [])[]' \
            "${IMPORT_DIR}/catalog.json")
    done
    echo "==> Staged plaintext .env -> ${IMPORT_DIR}"
elif [[ "$FROM" == "local" ]]; then
    IMPORT_DIR="$(mktemp -d "${TMPDIR:-/tmp}/denarius-secrets-local.XXXXXX")"
    IMPORT_CLEANUP="$IMPORT_DIR"
    cp -a "${SECRETS_DIR}/." "${IMPORT_DIR}/"
    echo "==> Import from local dev secrets (staging ${IMPORT_DIR})"
else
    echo "unsupported --from ${FROM}" >&2
    exit 1
fi

mkdir -p "$STORE_DIR" "$MANIFESTS_DIR"
cp "${MANIFEST_SRC}/"*.env-host-vault "$MANIFESTS_DIR/"

DENARIUS_CATALOG_DIR="${STATE_DIR}/denarius-local-catalog"
mkdir -p "$DENARIUS_CATALOG_DIR"
jq '{profiles: [.profiles[] | select(.name | startswith("denarius-"))]}' \
    "${SECRETS_DIR}/catalog.json" >"${DENARIUS_CATALOG_DIR}/catalog.json"
for profile in denarius-web denarius-worker denarius-connect; do
    ln -sf "../secrets/${profile}.env" "${DENARIUS_CATALOG_DIR}/${profile}.env"
done
ln -sfn "../secrets/files" "${DENARIUS_CATALOG_DIR}/files"

patch_host_config() {
    local cfg="$HEV_CONFIG"
    local tmp
    tmp="$(mktemp)"
    if grep -q '^\[sync\]' "$cfg"; then
        awk -v secrets="$DENARIUS_CATALOG_DIR" -v store="$STORE_DIR" -v manifests="$MANIFESTS_DIR" '
            BEGIN { in_sync=0 }
            /^\[sync\]/ { in_sync=1; print; next }
            /^\[/ && $0 != "[sync]" { in_sync=0 }
            in_sync && /^source *=/ { print "source = \"host-store\""; next }
            in_sync && /^local_secrets_dir *=/ { print "local_secrets_dir = \"" secrets "\""; next }
            in_sync && /^store_dir *=/ { print "store_dir = \"" store "\""; next }
            in_sync && /^manifests_dir *=/ { print "manifests_dir = \"" manifests "\""; next }
            { print }
        ' "$cfg" >"$tmp"
    else
        cat "$cfg" >"$tmp"
        cat >>"$tmp" <<EOF

[sync]
source = "host-store"
local_secrets_dir = "${DENARIUS_CATALOG_DIR}"
store_dir = "${STORE_DIR}"
manifests_dir = "${MANIFESTS_DIR}"
EOF
    fi
    grep -q '^source = "host-store"' "$tmp" || {
        echo "failed to patch host-config [sync] source" >&2
        exit 1
    }
    grep -q '^store_dir' "$tmp" || printf 'store_dir = "%s"\nmanifests_dir = "%s"\n' "$STORE_DIR" "$MANIFESTS_DIR" >>"$tmp"
    mv "$tmp" "$cfg"
}

echo "==> patch host-config.toml (host-store)"
patch_host_config

echo "==> store init"
hev_store init

echo "==> store import (--backend ${BACKEND})"
for profile in denarius-web denarius-worker denarius-connect; do
    hev_store import --from-dir "$IMPORT_DIR" --profile "$profile" --backend "$BACKEND"
done

[[ -n "$IMPORT_CLEANUP" ]] && rm -rf "$IMPORT_CLEANUP"

echo "==> validate (Denarius profiles)"
validate_ok=1
for profile in denarius-web denarius-worker denarius-connect; do
    if ! hev --config dev-shared/host-config.toml validate --source host-store --profile "$profile"; then
        validate_ok=0
        echo "validate failed for ${profile} (fill ${SECRETS_DIR}/${profile}.env and re-run import)" >&2
    fi
done
[[ "$validate_ok" -eq 1 ]] || exit 1

echo "==> sync (Denarius catalog slice)"
hev --config dev-shared/host-config.toml sync --source host-store

echo "==> Done. Switch local gb mode:"
echo "    ${ROOT}/scripts/denarius-secrets-mode.sh host-store"
