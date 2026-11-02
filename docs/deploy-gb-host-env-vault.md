# Deploy: generic-green-blue + host-env-vault (Amtgard Denarius)

Operator runbook for **interrupt-free** deploys: install the green-blue toolkit and vault agent, stand up a **local intermediary vault** on a laptop (before AWS SSM on production), validate inject mounts in Docker, then use **`gb deploy`** / **`gb revert`** on the host.

Denarius uses three inject profiles: **`denarius-web`**, **`denarius-worker`**, and **`denarius-connect`** (Stripe Connect secrets on a separate mount). Secrets arrive as **`/run/secrets/secrets.env`**, optional **`/run/secrets-connect`**, and **`files/*`** inside containers.

Related docs:

- [host-env/README.md](../host-env/README.md) — catalog fragment, profiles, secrets-mode scripts
- [generic-green-blue-deployment](https://github.com/esdraelon/generic-green-blue-deployment) — toolkit (hooks, HEV flags)
- [host-env-vault](https://github.com/esdraelon/host-env-vault) — sync, render, **host-store** transition, SSM cutover

---

## Architecture (what happens on `gb deploy`)

```text
┌─────────────────────────────────────────────────────────────────┐
│ Host (laptop or production)                                      │
│  .greenblue          gb deploy                                   │
│       │                  │                                       │
│       ▼                  ▼                                       │
│  gb-pre-hev-sync.sh ──► catalog fragment → vault dev-shared      │
│       │                  │                                       │
│       ▼                  ▼                                       │
│  host-env-vault sync ──► .hev bundles in state_dir               │
│       │                  │                                       │
│       ▼                  ▼                                       │
│  host-env-vault render ► {render_root}/{profile}/current/        │
│                          secrets.env + files/                    │
│       │                  │                                       │
│       ▼                  ▼                                       │
│  GB_HEV_MOUNT_MAP ─────► .gb/runtime.env                         │
│  (denarius-web → DENARIUS_HEV_WEB_SECRETS, etc.)                 │
│       │                  │                                       │
│       ▼                  ▼                                       │
│  docker compose ────────► mount …/current → /run/secrets:ro      │
│       │                  │                                       │
│       ▼                  ▼                                       │
│  PHP bootstrap ─────────► .env (config) then secrets.env         │
└─────────────────────────────────────────────────────────────────┘
```

**Blue-green (production):** inactive slot (**blue** **37180** / **green** **37181**) is built and health-checked; host nginx switches; previous slot stays up for **`gb revert`**. Sessions Redis and the ledger worker are **sidecars**, not slot-swapped.

**Dev:** bind-mounted source, stacks **sessions**, **web**, **worker**; HEV sync/render on **`gb deploy`** when enabled in `.greenblue.example.dev`.

---

## Prerequisites

| Requirement | Dev (laptop) | Production (host) |
| --- | --- | --- |
| Docker + Compose v2 | Yes | Yes |
| **generic-green-blue-deployment** | Sibling repo or `/var/gb/…` | `/var/gb/generic-green-blue-deployment` |
| **host-env-vault** binary | `cargo build --release` or `PATH` | `/usr/local/bin` or package |
| App repo | `amtgard-denarius` clone | `/var/www/denarius.amtgard.com` (typical) |
| Secrets | `host-env-vault/dev-shared/secrets/` (gitignored) | SSM + `/etc/host-env-vault/config.toml` |
| Host `.env` | Non-secrets + dev overrides | DB host, URLs, feature flags — not vault crypto |
| PHP image | **8.4.27+** (Dockerfile gate CVE-2026-109618/619) | Same |

---

## Phase 1 — Install generic-green-blue (`gb`)

```bash
sudo mkdir -p /var/gb
sudo git clone https://github.com/esdraelon/generic-green-blue-deployment.git \
  /var/gb/generic-green-blue-deployment
/var/gb/generic-green-blue-deployment/tests/smoke.sh
```

Dev sibling checkout: `../generic-green-blue-deployment/bin/gb "$PWD" deploy` from the Denarius root (directory containing `.greenblue`).

---

## Phase 2 — Install host-env-vault

### Laptop

```bash
cd /path/to/host-env-vault
cargo build --release
./scripts/local-shared-bootstrap.sh
```

Set in `.greenblue` if needed:

```bash
GB_HEV_REPO="/path/to/host-env-vault"
GB_HEV_CONFIG="${GB_HEV_VAULT_ROOT}/dev-shared/host-config.toml"
```

### Production (SSM — later)

1. **`host-env-vault`** setup + systemd sync timer (vault README).
2. **`/etc/host-env-vault/config.toml`** with **`[sync] source = "ssm"`**.
3. **`GB_HEV_SYNC_ON_DEPLOY=1`**, **`GB_HEV_RENDER_ON_DEPLOY=1`** in `.greenblue.prod`.
4. Cut over from laptop **host-store** via **`host-env-vault store export`** ([transition.md](https://github.com/esdraelon/host-env-vault/blob/main/docs/transition.md) in vault repo).

---

## Phase 3 — Local secrets posture (pick one)

| Mode | Script | `[sync] source` | When |
| --- | --- | --- | --- |
| Plaintext `.env` | `scripts/denarius-secrets-mode.sh plaintext` | (HEV sync/render off) | Legacy / privacy-only prod |
| Dev profile files | `scripts/denarius-secrets-mode.sh local` | `local` | Laptop `.env` under `dev-shared/secrets/` |
| Intermediary encrypted store | `scripts/denarius-secrets-mode.sh host-store` | `host-store` | After migration script; prod-like on laptop |

`.greenblue.example.dev` sources **`host-env/load-secrets-mode.sh`**, which reads **`.gb/secrets-mode`**.

### 3.1 Catalog fragment

**`scripts/gb-pre-hev-sync.sh`** copies **`host-env/catalog.d/30-denarius.json`** into the vault **`dev-shared/catalog.d/`** and refreshes **`secrets/catalog.json`**.

| Profile | Role | Inject (summary) |
| --- | --- | --- |
| `denarius-web` | Web slot | `DB_PASS`, `IDP_CLIENT_SECRET`, `STRIPE_PUBLISHABLE_KEY` |
| `denarius-worker` | Ledger worker | Provider keys, `APP_KEY`, `DB_PASS`; files `teller.pem`, `teller.key` |
| `denarius-connect` | Connect mount | `STRIPE_SECRET_KEY`, `STRIPE_PUBLISHABLE_KEY` → `/run/secrets-connect` |

Manifests (names only): **`host-env/manifests/*.env-host-vault`**.

### 3.2 Seed dev secrets

```bash
cp host-env/secrets.example/denarius-web.env \
   /path/to/host-env-vault/dev-shared/secrets/denarius-web.env
cp host-env/secrets.example/denarius-worker.env \
   /path/to/host-env-vault/dev-shared/secrets/denarius-worker.env
cp host-env/secrets.example/denarius-connect.env \
   /path/to/host-env-vault/dev-shared/secrets/denarius-connect.env
```

**Empty catalog inject values break `validate` / `sync`.** Fill every key or use placeholders for dev.

Copy Teller PEMs to **`dev-shared/secrets/files/`**.

### 3.3 Migrate to intermediary **host-store** (SQLite)

From the Denarius install root (laptop or **`/var/www/denarius.amtgard.com`**):

```bash
./scripts/migrate-denarius-secrets-to-host-store.sh "$PWD" \
  --from auto \
  --backend sqlite \
  --non-interactive
```

- **`--from auto`**: existing vault profile **`.env`** files → **local**; else split host **`.env`** → **plaintext** (requires **`jq`**).
- Writes encrypted **`{profile}.secrets.db`** under **`dev-shared/state/store/`**.
- Installs manifests, builds **`state/denarius-local-catalog/`** (Denarius-only catalog slice for shared vault trees), patches **`host-config.toml`**, runs **`validate`** + **`sync`**.

Then:

```bash
./scripts/denarius-secrets-mode.sh host-store
```

### 3.4 Application config

```bash
cp .greenblue.example.dev .greenblue
cp .env.example .env
```

Dev **`GB_HEV_MOUNT_MAP`**: `denarius-web:DENARIUS_HEV_WEB_SECRETS denarius-worker:DENARIUS_HEV_WORKER_SECRETS denarius-connect:DENARIUS_HEV_CONNECT_SECRETS`.

---

## Phase 4 — Test local Docker

```bash
/path/to/generic-green-blue-deployment/bin/gb /full/path/to/amtgard-denarius deploy
```

Verify inject:

```bash
docker exec amtgard-denarius bash -lc 'test -f /run/secrets/secrets.env && echo ok'
docker exec amtgard-denarius bash -lc 'test -f /run/secrets-connect/secrets.env && echo ok || true'
curl -sf http://127.0.0.1:37180/ | head
```

Optional: `composer test` inside the dev web container.

---

## Phase 5 — Production deploy

### One-time

```bash
sudo git clone … /var/www/denarius.amtgard.com
cd /var/www/denarius.amtgard.com
sudo cp .greenblue.example.prod .greenblue
sudo cp .env.example .env
sudo install -d -m 0755 /var/log/amtgard-denarius/{web-blue,web-green,worker}
```

Configure SSM vault; uncomment **`GB_HEV_CONFIG`** in `.greenblue`.

### Routine deploy

```bash
sudo /var/gb/generic-green-blue-deployment/bin/gb \
  --hev-config /etc/host-env-vault/config.toml \
  /var/www/denarius.amtgard.com deploy
```

**Rollback (nginx):**

```bash
sudo /var/gb/generic-green-blue-deployment/bin/gb /var/www/denarius.amtgard.com revert
```

**Note:** Legacy **`install.sh`** was removed after PR #8; use **`gb`** only. Hosts still on old **`install.sh`** must **`git reset --hard origin/main`** before first **`gb`** deploy.

---

## Migration plan: plain `.env` → local → host-store → SSM

| Phase | Secrets | Operator action |
| --- | --- | --- |
| **A** | Host `.env` + `keys/` | Privacy-only / legacy; rotate off plaintext |
| **B** | `dev-shared/secrets/*.env` | **`denarius-secrets-mode.sh local`** + **`gb deploy`** |
| **C** | Encrypted **host-store** (SQLite) | **`migrate-denarius-secrets-to-host-store.sh`** + **`host-store`** mode |
| **D** | SSM Parameter Store | **`store export`**, flip **`source = "ssm"`**, prod **`gb deploy`** |

Production interim (no SSM yet): run migrate on **`/var/www/denarius.amtgard.com`** with **`--from plaintext`**, point **`GB_HEV_CONFIG`** at host **`config.toml`** with **`host-store`**, deploy with **`gb`**.

---

## Troubleshooting

| Symptom | Fix |
| --- | --- |
| `git pull --ff-only` failed (old prod) | **`git fetch && git reset --hard origin/main`**; use **`gb`**, not **`install.sh`** |
| Sync/validate fails on empty key | Fill all inject keys in profile **`.env`** or store |
| Shared vault validates idp profiles | Denarius migrate uses **`denarius-local-catalog`** slice; idp uses its own migrate path |
| No inject mount | Run **`gb deploy`** with HEV on; check **`.gb/runtime.env`** |
| Worker disk growth | **`APP_DEBUG=false`**, worker down if unused; see host log docs in **`host-env/README.md`** |

---

## Quick reference

```bash
# Local deploy
../generic-green-blue-deployment/bin/gb "$PWD" deploy

# Secrets posture
./scripts/denarius-secrets-mode.sh local|host-store|plaintext|status

# Intermediary vault migration
./scripts/migrate-denarius-secrets-to-host-store.sh "$PWD" --from auto --non-interactive

# Prod
sudo /var/gb/generic-green-blue-deployment/bin/gb \
  --hev-config /etc/host-env-vault/config.toml \
  /var/www/denarius.amtgard.com deploy
```
