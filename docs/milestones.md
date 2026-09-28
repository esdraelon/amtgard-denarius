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

## Ledger provider

- Branch: `ledger-provider`
- Enrollment, sync, and webhooks talk to a `LedgerProvider`. Teller is one adapter behind that port.
- A different bank is a container binding. Denarius notice actions stay `refresh` and `disconnect`.
- Line coverage: 96.82% (1189/1228).
- Infection covered MSI: 90%.

## Provider registry

- Branch: `provider-registry`
- A `LedgerProviderRegistry` walks configured providers in order. A rejected institution, or one the manager skipped, falls through to the next provider. An unknown id resolves to a missing provider.
- Teller reports unknown coverage when its application id is present, and no coverage when the id or the institution name is blank.
- Line coverage: 96.93% (1233/1272).
- Infection covered MSI: 91%.

## Kingdom provider

- Branch: `kingdom-provider`
- A kingdom stores the provider chosen when it connects. Sync and webhooks look the kingdom up by that provider and enrollment id, so a later fallback does not move an existing link.
- Line coverage: 97.00% (1263/1302).
- Infection covered MSI: 90%.

## Stripe adapter

- Branch: `stripe-adapter`
- Stripe Financial Connections is admitted when `STRIPE_SECRET_KEY` is set, ahead of Teller. Connect creates a customer and a transactions session. The stored secret is the Stripe customer id. Account refresh and disconnect webhooks verify `Stripe-Signature`.
- Transaction reads cover the previous calendar month through today. A later page in the same sync does not call Stripe again.
- Line coverage: 97.27% (1459/1500).
- Infection covered MSI: 91%.
