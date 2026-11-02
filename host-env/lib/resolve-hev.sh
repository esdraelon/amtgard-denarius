#!/usr/bin/env bash
# Shared path resolution for Denarius host-env scripts (source, do not execute).
set -euo pipefail

denarius_hev_repo() {
    if [[ -n "${GB_HEV_REPO:-}" && -f "${GB_HEV_REPO}/Cargo.toml" ]]; then
        echo "$(cd "${GB_HEV_REPO}" && pwd)"
        return
    fi
    local root="${1:-}"
    for candidate in \
        "${root}/../host-env-vault" \
        "${root}/../host-env-vault/Untitled"; do
        if [[ -f "${candidate}/Cargo.toml" ]]; then
            echo "$(cd "${candidate}" && pwd)"
            return
        fi
    done
    echo ""
}

denarius_hev_bin() {
    local repo="${1:-}"
    if [[ -n "${GB_HEV_BIN:-}" ]] && command -v "${GB_HEV_BIN}" >/dev/null 2>&1; then
        echo "${GB_HEV_BIN}"
        return
    fi
    if command -v host-env-vault >/dev/null 2>&1; then
        echo host-env-vault
        return
    fi
    if [[ -n "$repo" && -x "${repo}/target/release/host-env-vault" ]]; then
        echo "${repo}/target/release/host-env-vault"
        return
    fi
    if [[ -n "$repo" && -f "${repo}/Cargo.toml" ]]; then
        (cd "$repo" && cargo build --release >/dev/null)
        echo "${repo}/target/release/host-env-vault"
        return
    fi
    return 1
}

denarius_hev_dev_shared() {
    local repo="${1:-}"
    local config="${2:-}"
    if [[ -n "$config" && -f "$config" ]]; then
        dirname "$(cd "$(dirname "$config")" && pwd)"
        return
    fi
    if [[ -n "${GB_HEV_VAULT_ROOT:-}" && -d "${GB_HEV_VAULT_ROOT}/dev-shared" ]]; then
        echo "$(cd "${GB_HEV_VAULT_ROOT}/dev-shared" && pwd)"
        return
    fi
    if [[ -n "$repo" && -d "${repo}/dev-shared" ]]; then
        echo "$(cd "${repo}/dev-shared" && pwd)"
        return
    fi
    return 1
}

denarius_hev_invoke() {
    local repo="$1"
    shift
    if [[ -n "$repo" && -d "$repo" ]]; then
        (cd "$repo" && "$@")
    else
        "$@"
    fi
}
