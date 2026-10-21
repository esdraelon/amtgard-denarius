# Denarius + host-env-vault (local dev)

Tier profiles match [docs/planning/security-architecture.md](../docs/planning/security-architecture.md): **denarius-web**, **denarius-worker**, **denarius-connect**.

## One-time laptop setup

1. Bootstrap shared vault (from the **host-env-vault** checkout):

   ```bash
   cd ../host-env-vault/Untitled   # or your host-env-vault root
   ./scripts/local-shared-bootstrap.sh
   ```

2. Denarius catalog fragment — `scripts/gb-pre-hev-sync.sh` (from `.greenblue` on dev load and prod `GB_HOOK_PRE_DEPLOY`) copies `host-env/catalog.d/30-denarius.json` into the vault’s `dev-shared/catalog.d/` and refreshes `secrets/catalog.json` when merge scripts exist.

   **Local dev mode:** `host-config.toml` uses `[sync] source = "local"`. Match that with `GB_HEV_SYNC_SOURCE=local` in `.greenblue` (see `.greenblue.example.dev`).

   **macOS render:** omit `"gid"` in the laptop catalog (as in `30-denarius.json`) so `render` does not `chown root:www-data`. On Linux production hosts, add `"gid": 33` per profile (see `catalog.d/31-denarius-linux-gid.json.example`).

3. Seed secret **values** (gitignored on the vault side):

   ```bash
   cp host-env/secrets.example/denarius-web.env \
      ../host-env-vault/Untitled/dev-shared/secrets/denarius-web.env
   cp host-env/secrets.example/denarius-worker.env \
      ../host-env-vault/Untitled/dev-shared/secrets/denarius-worker.env
   cp host-env/secrets.example/denarius-connect.env \
      ../host-env-vault/Untitled/dev-shared/secrets/denarius-connect.env
   ```

   Edit those files with real values. Copy Teller PEM material into `dev-shared/secrets/files/teller.pem` and `teller.key` when using Teller.

4. Configure **generic-green-blue** (once per machine):

   ```bash
   cp .greenblue.example.dev .greenblue
   # edit GB_HEV_* overrides if the vault is not at ../host-env-vault/Untitled
   ```

5. Start Denarius (generic-green-blue only). The first argument to `gb` is the **Denarius repo root** (where `.greenblue` lives), not the gb toolkit checkout:

   ```bash
   cd /full/path/to/amtgard-denarius
   ../generic-green-blue-deployment/bin/gb "$PWD" deploy
   ```

   Same path may be passed explicitly from any cwd. `gb` resolves it to an absolute path and sets `GB_ROOT` from it. (`GB_MODE=dev` in `.greenblue` selects the bind-mount dev stack; HEV sync/render uses `GB_HEV_VAULT_ROOT`.)

   Compose mount wiring comes from `GB_HEV_MOUNT_MAP` → `{repo}/.gb/runtime.env` (not application `.env`).

## Host logs

Application logs and log bundles are bind-mounted under **`${DENARIUS_HOST_LOG_DIR:-/var/log/amtgard-denarius}`** (see `docker/compose.*.yml`). Before the first deploy on a host:

```bash
sudo install -d -m 0755 /var/log/amtgard-denarius/{web-blue,web-green,worker,dev-web,dev-worker}
sudo chown -R "$(id -un):$(id -gn)" /var/log/amtgard-denarius   # dev laptop; prod: www-data or container uid
sudo cp host/logrotate/amtgard-denarius /etc/logrotate.d/amtgard-denarius
```

Each container also uses Docker **`json-file`** logging with **`max-size=100m`** and **`max-file=3`** (~300 MB cap on stdout/stderr). Optional **`host/logrotate/amtgard-denarius`** keeps on-disk JSONL **`rotate 3`** (daily).

Production web and worker stacks set **`APP_DEBUG=false`** and **`LOG_SPOOL_ENABLED=false`** in compose so idle workers do not flood stderr.

## Production

Copy `.greenblue.example.prod` to `/var/www/denarius.amtgard.com/.greenblue` on the host. Deploy from the toolkit install:

```bash
/var/gb/generic-green-blue-deployment/bin/gb \
  --hev-config /etc/host-env-vault/config.toml \
  /var/www/denarius.amtgard.com deploy
```

## Migration from plain `.env`

| Phase | Behavior |
| --- | --- |
| **Now** | `.env` holds non-secret config; `secrets.env` on `/run/secrets` loads via **Dotenv safeLoad** (does not override `.env`). |
| **Next** | Move provider and IDP secrets out of `.env` into vault profile env files; keep only hosts, ports, and feature flags in `.env`. |
| **Prod** | `gb deploy` renders inject trees before the inactive slot starts; Stripe **connect** uses `StripeConnectSecretSupplier` on `ManagerController` (runtime socket or connect inject mount, not global env). Worker sync secrets stay on `denarius-worker` inject. |

PHPUnit and `APP_ENV=testing` continue to use `phpunit.xml` env defaults; no vault mount required.
