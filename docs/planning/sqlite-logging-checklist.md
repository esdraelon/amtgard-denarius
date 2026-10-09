# SQLite method log bundles (IDP-aligned)

Local logging today: **stderr JSON** + optional **`logs/method-trace.jsonl`** when `APP_DEBUG` (see [logging-spike.md](../reference/logging-spike.md)). That is **not** the IDP target of hourly **SQLite WAL** files + spool + writer + query CLI.

Target matches [IDP logging-spike design](https://github.com/amtgard/amtgard-idp/blob/main/agent/cursor/logging-spike/design-plan.md): hot path appends JSONL to spool (non-blocking); **`bin/log-sqlite-writer.php`** is the only process that opens SQLite.

Stack from **`fix-kingdom`** tip. Serial milestones, one branch + one commit each. Do not push unless the user asks.

| # | Branch | Done |
|---|--------|------|
| L1 | `stack/denarius-log-l1-spool-sqlite-writer` | [x] |
| L2 | `stack/denarius-log-l2-container-wire-docker` | [x] |
| L3 | `stack/denarius-log-l3-denarius-logs-cli` | [x] |
| L4 | `stack/denarius-log-l4-docs-milestone` | [x] |

**L1:** `LogPathResolver`, `SqliteLogSchema`, `JsonLogSpoolHandler`, `LogSpoolDrainer`, `bin/log-sqlite-writer.php`, unit tests. Gates: `composer test`.

**L2:** Wire spool handler in `MethodLog` factory; `.env.example` `LOG_ROOT`, `LOG_SPOOL_ENABLED`; optional `docker/compose.log-writer.yml` profile. Gates: `composer test`.

**L3:** `bin/denarius-logs.php` — `query --request-id=`, `bundle --request-id=` (jsonl export). Tests with temp dirs. Gates: `composer test`.

**L4:** Update `docs/reference/logging-spike.md`, append `docs/reference/milestones.md`.
