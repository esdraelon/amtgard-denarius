# Denarius DEV integ — HTTP route coverage plan

Companion to [dev-integ-coverage-checklist.md](./dev-integ-coverage-checklist.md). Mirrors IDP `phpunit.integ.xml`, `composer integ`, `scripts/integ.sh`, per-test reseed, and route matrix.

## Goals

1. **~90% route coverage** — every route in `config/routes.php` mapped to at least one live HTTP integ case or a documented exclusion.
2. **IDP-style harness** — separate PHPUnit config; `composer test` stays unit-only (95% line + Infection).
3. **Dev safety** — integ MariaDB + session Redis; `integ-down` restores DEV without wiping dev volumes.

**Primary metric:** `docs/planning/dev-integ-route-matrix.md` — covered / (total − excluded) ≥ 0.90.

## Current state

| Layer | Today |
|--------|--------|
| Routes | 36 in `config/routes.php` |
| HTTP integ | **None** — no `phpunit.integ.xml`, no `composer integ` |
| `tests/Integration/` | 3 classes, MariaDB/persistence only (included in `composer test`) |
| Docker | `scripts/dev-up.sh`; integ DB/Redis in `docker/compose.integ-infra.yml` (C1); web overlay pending C2 |

## Architecture target

- Project `amtgard-denarius-integ`: `docker/compose.integ-infra.yml` — DB `amtgard-denarius-db-integ` (host port **36317**, schema `denarius_integ`, volumes `amtgard-denarius-integ-data-db`), Redis `amtgard-denarius-sessions-integ` (volume `amtgard-denarius-integ-session-data`). Start: `docker compose -p amtgard-denarius-integ -f docker/compose.integ-infra.yml up -d`. Integ env vars are commented in `.env.example`.
- Web overlay: `ENVIRONMENT=DEV_INTEG`, integ hosts, migrate + seed integ DB only.
- **IDP prerequisite:** IDP integ stack (`localhost:37080`) with Denarius OAuth client + redirect `http://localhost:37180/oauth/callback`.

See checklist for phased milestones C0–C7 (harness) then D1–D14 (coverage).

## Gates

| Command | Purpose |
|---------|---------|
| `composer test` | Unit (+ optional persistence suite); 95% / Infection 80% |
| `./scripts/integ.sh` | Full HTTP integ run |
| `bin/check-integ-route-coverage.php` | Matrix ≥ 90% (D14) |

## Estimated scope

~58–72 HTTP integ test methods for ~32 in-scope routes (after exclusions).
