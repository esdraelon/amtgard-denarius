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

## Plaid adapter

- Branch: `plaid-adapter`
- Plaid is admitted when `PLAID_CLIENT_ID` and `PLAID_SECRET` are both set, after Stripe and before Teller. Institution search answers yes or no for US transaction coverage. Connect returns a Link token. The stored secret is the access token and the enrollment id is the Item id.
- `Plaid-Verification` is an ES256 JWT. The body hash and a five-minute issued-at window have to match. Transaction sync is drained inside the adapter for the previous calendar month through today.
- Line coverage: 97.50% (1674/1717).
- Infection covered MSI: 93%.

## SimpleFIN adapter

- Branch: `simplefin-adapter`
- SimpleFIN is always admitted, after Stripe, Plaid, and Teller. It has no platform secret. A setup token is base64 of a claim URL on a SimpleFIN host. The claim runs once and the access URL is the stored secret.
- Coverage stays unknown, so it is the paste-token fallback after the earlier providers are skipped. There is no webhook. Transaction reads use the previous calendar month through today.
- Line coverage: 97.53% (1818/1864).
- Infection covered MSI: 93%.

## Manage connect

- Branch: `manage-connect`
- The manage page asks for the bank name and posts it to `/manage/{slug}/connect`. The registry mounts Stripe, Plaid, Teller, or a SimpleFIN setup token, in that order. "My bank is not listed" adds the current provider to the skipped list and mounts the next one.
- A kingdom that already connected through Teller passes that enrollment id back into Teller Connect. Stripe's widget uses `STRIPE_PUBLISHABLE_KEY`.
- Line coverage: 97.60% (1870/1916).
- Infection covered MSI: 94%.

## Provider setup

- Branch: `provider-setup`
- `bin/provider-setup.php` prints the human steps for Stripe, Plaid, Teller, and SimpleFIN. Secret prompts hide terminal echo. Stripe and Plaid are checked with a read-only request. Teller is checked by reading the certificate and key paths. SimpleFIN has no platform secret.
- Verified values are written to a mode 0600 env fragment. The script does not print those values, and `provider.env` is gitignored.
- Line coverage: 97.70% (2000/2047).
- Infection covered MSI: 94%.

## Bank connector folders

- Branch: `bank-connector-folders`
- Stripe, Plaid, Teller, and SimpleFIN sit under `Bank`, and each vendor API interface sits beside its connector. Ledger notices sit under `Bank/Notice`. Setup stays a CLI package.
- Line coverage: 97.70% (2000/2047).
- Infection covered MSI: 94%.

## Colocated ports

- Branch: `colocated-ports`
- `PolicyGateway` sits beside `IdpPolicyGateway`. `MessageQueue`, `KingdomRefreshQueue`, and `KeyValueStore` sit beside their queue adapters. `IdpPolicyGateway` remains the `idp-php-client` adapter.
- Line coverage: 97.70% (2000/2047).
- Infection covered MSI: 94%.

## Browser kingdom list

- Branch: `browser-kingdom-list`
- The admin page loads `Kingdom/GetKingdoms` in the browser and posts the ORK id and name. Denarius no longer calls ORK, caches the directory, or refreshes it from the worker.
- Stored `ork_kingdom_id` values stay. They identify a kingdom in Denarius.
- Line coverage: 97.77% (1926/1970).
- Infection covered MSI: 95%.

## Repository names

- Branch: `repository-names`
- Kingdom, account, principal, secret, transaction, and role-grant access lives on the Active Record repositories. Each port sits beside its repository. The Aaro store wrappers and the `Contract` store interfaces are gone.
- Line coverage: 97.68% (1933/1979).
- Infection covered MSI: 95%.

## Persistence records

- Branch: `persistence-records`
- Kingdom, account, principal, role-grant, and transaction records sit under `Persistence/Record`, next to the repositories that build them.
- Line coverage: 97.68% (1933/1979).
- Infection covered MSI: 94%.

## Utility modules

- Branch: `utility-modules`
- Auth, HTTP, queue, security, session, and setup sit under `Utilities`. They stay siblings: the only cross-use is HTTP middleware reading the current actor, and that actor is also used outside HTTP. Queue and setup ports sit above an `Impl` folder. Setup is split into client, guide, I/O, field, and env.
- Line coverage: 97.68% (1933/1979).
- Infection covered MSI: 95%.

## Domain bank

- Branch: `domain-bank`
- Bank moves under Domain. Stripe, Plaid, Teller, and SimpleFIN sit under Providers. Enrollment, notices, readiness, the provider registry, and shared support are separate modules. Statements and access are grouped the same way, with each port above an `Impl` folder.
- Line coverage: 97.68% (1933/1979).
- Infection covered MSI: 95%.
