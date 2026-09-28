# Denarius milestones

Work is stacked with git-branchless. Each milestone is one branch. A milestone is committed after `src/` line coverage is at least 95% and Infection MSI and covered MSI are at least 80%.

## Baseline

- Branch: `main`
- Commit: `bf4ea83`
- Slim application, Docker overlays, Phinx schema, Teller enrollment, and the MariaDB ledger.

## Design pattern review

- Branch: `design-pattern-review`
- Display modes, visibility, webhook events, refresh jobs, and admin commands are strategies. The container builds the registries that select them.
- Optional is not used as a parameter or return type.
- Line coverage: 96.46% (1062/1101).
- Infection covered MSI: 89%.

## Redacted rows

- Branch: `redacted-rows`
- A redacted month keeps the date, amount, category, status, and account name.
- Description and counterparty are omitted.
- Line coverage: 96.47% (1065/1104).
- Infection covered MSI: 89%.

## Redis month cache

- Branch: `redis-month-cache`
- Transactions stay in MariaDB. A month page is served from Redis DB 0 and rebuilt from MariaDB on a miss.
- A sync or a published-account change bumps a generation key, so the next read misses.
- The shared Redis container stays up across a blue-green install. Session flush uses Redis DB 1 and does not clear month keys. `INSTALL_REBUILD_SESSIONS=1` is the opt-in wipe.
- Line coverage: 96.50% (1132/1173).
- Infection covered MSI: 89%.
