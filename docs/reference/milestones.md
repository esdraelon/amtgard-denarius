# Denarius milestones

Work uses **stacked git branches** (one branch per milestone, each stacked on the prior). git-branchless or other stacked-branch tooling is optional. Each milestone is one branch. A milestone is committed after `composer test` (`src/` line coverage at least 95%) and `composer infection:ci` (MSI and covered MSI at least 80%). The `infection:ci` script runs PHPUnit once for PCOV coverage XML, copies JUnit beside it, then runs Infection with `--skip-initial-tests` so the duplicate PHPUnit pass is not killed (exit 143) under PCOV.

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

## Repository implementations

- Branch: `repository-impl`
- Each repository port has its own folder. The interface stays at the top of that folder, and the Active Record class sits in `Impl`.
- Line coverage: 97.68% (1933/1979).
- Infection covered MSI: 95%.

## Service modules

- Branch: `service-modules`
- Services are grouped into enrollment, ledger sync, kingdom pages, access, admin, and month. Admin commands, the cached month reader, and refresh jobs sit under `Impl`. Statement lines and display mode sit in their own modules so the statement folder stays small.
- Line coverage: 97.68% (1933/1979).
- Infection covered MSI: 95%.

## Provider framework

- Branch: `provider-framework`
- Shared ledger-provider types sit under `Domain/Bank/Provider/Framework`. Plaid, SimpleFIN, Stripe, and Teller sit under `Domain/Bank/Provider/Providers`.
- Line coverage: 97.68% (1933/1979).
- Infection covered MSI: 95%.

## Logging core

- Branch: `logging-core`
- `DenariusLog` facade, stderr JSON method log, request-id ambient context and middleware, redaction, and a PHPUnit recorder that fails unbalanced traces.
- Line coverage: 97.76% (2008/2054).
- Infection covered MSI: 94%.

## Logging HTTP

- Branch: `logging-http`
- Every method in `src/Controller` and `src/Utilities/Http` calls `DenariusLog::trace` or constructor `enter`, so a local request shows which HTTP handler ran.
- Line coverage: 97.83% (2070/2116).
- Infection covered MSI: 94%.

## Logging Auth

- Branch: `logging-auth`
- Every method in `src/Utilities/Auth`, `src/Domain/Access`, and `src/Service/Access` calls `DenariusLog::trace` or constructor `enter`, so a local request shows which permission decision ran.
- Line coverage: 97.90% (2145/2191).
- Infection covered MSI: 94%.

## Logging Persistence

- Branch: `logging-persistence`
- Every method in `src/Persistence` (repositories, records, and `Orm`) calls `DenariusLog::trace` or constructor `enter`, so a local request shows which persistence method ran.
- Line coverage: 97.99% (2245/2291).
- Infection covered MSI: 94%.

## Logging Services

- Branch: `logging-services`
- Every method in `src/Service` except `Service/Access`, and in `src/Worker`, calls `DenariusLog::trace` or constructor `enter`, so a local sync shows which job method ran.
- Line coverage: 97.96% (2401/2451).
- Infection covered MSI: 94%.

## Logging Bank

- Branch: `logging-bank`
- Every method in `src/Domain/Bank` (framework, readiness, notices, enrollment, and Stripe/Plaid/Teller/SimpleFIN providers) calls `DenariusLog::trace` or constructor `enter`, so a local connect or webhook shows which adapter ran.
- Line coverage: 98.11% (2698/2750).
- Infection covered MSI: 94%.

## Logging Rest

- Branch: `logging-rest`
- Every method in `src/Domain/Statement`, `src/Domain/Kingdom`, `src/Utilities/Setup`, `src/Utilities/Queue`, `src/Utilities/Session`, and `src/Utilities/Security` calls `DenariusLog::trace` or constructor `enter`, so the last unlogged methods show up in a local request.
- Line coverage: 98.23% (2885/2937).
- Infection covered MSI: 94%.

## Container routes wiring

- Branch: `container-routes-wiring`
- `ContainerResolutionOrderTest` boots `config/bootstrap.php`, resolves core services and controllers, and checks named routes against `config/routes.php` (IDP-style integration wiring test). PHPUnit `IDP_IAM_SERVICE_FORMAT` is a JSON array so `IdpClient` resolves under test.
- Line coverage: 98.23% (2885/2937).
- Infection covered MSI: not re-run on this branch.

## Log test support

- Branch: `log-test-support`
- `MethodLogAssert` helpers (`reset`, `assertTraced`, `assertConstructorEntered`, `assertAnyOfTraced`) on the bootstrap `RecordingMethodLog`; `TracedMethodCatalog` scans `src/` for `DenariusLog::trace` / `enter` call sites; unit tests in `MethodLogAssertTest` and `TracedMethodCatalogTest`. Documented catalog workflow in [method-log-tests.md](method-log-tests.md).
- Line coverage: 98.23% (2885/2937).
- Infection covered MSI: 94%.

## Log test HTTP

- Branch: `log-test-http`
- `TracedHttpMethodsTest` drives controller and HTTP utility paths with memory fakes and asserts every `TracedMethodCatalog` entry under `src/Controller` and `src/Utilities/Http` via `MethodLogAssert` (38 methods).
- Line coverage: 98.23% (2885/2937).
- Infection covered MSI: 94%.

## Log test auth

- Branch: `log-test-auth`
- `TracedAuthMethodsTest` asserts every `TracedMethodCatalog` entry under `src/Utilities/Auth`, `src/Domain/Access`, and `src/Service/Access` via `MethodLogAssert` (42 methods). `TracedMethodCatalog` maps trace sites to the enclosing class when a file defines more than one type (e.g. `ClaimOrn.php`).
- Line coverage: 98.26% (2886/2937).
- Infection covered MSI: not re-run locally (Infection initial PHPUnit run exited 143 with PCOV); prior stack tip was 94%.

## Log test persistence

- Branch: `log-test-persistence`
- `TracedPersistenceMethodsTest` and shared `PersistenceStoreArrange` exercise record builders, memory fakes, and MariaDB repository round-trips; assert every `TracedMethodCatalog` entry under `src/Persistence` via `MethodLogAssert` (52 methods). `StoreTest` reuses the same arrange helper.
- Line coverage: 98.26% (2886/2937).
- Infection covered MSI: 95%.

## Log test services

- Branch: `log-test-services`
- `TracedServicesMethodsTest` and shared `ServiceWorkerArrange` drive admin, enrollment, ledger, month cache, kingdom query/settings, bank connect, and worker paths with memory fakes; assert every `TracedMethodCatalog` entry under `src/Service` (except `Service/Access`) and `src/Worker` via `MethodLogAssert` (86 methods). `ControllerTest` clears session keys at the start of the combined controller test for random-order stability.
- Line coverage: 98.47% (2892/2937).
- Infection covered MSI: 94% (`composer infection` initial PHPUnit exit 143; skip-initial-tests with `build/coverage-xml`).

## Log test bank

- Branch: `log-test-bank`
- `TracedBankMethodsTest` and shared `BankDomainArrange` reuse adapter and registry tests plus `ServiceWorkerArrange` teller/webhook paths; assert every `TracedMethodCatalog` entry under `src/Domain/Bank` via `MethodLogAssert` (172 methods under PHPUnit bootstrap, including enrollment value types). Adapter tests expose `exerciseCurlForMethodLog()` for scripted curl without dead-port timeouts.
- Line coverage: 98.57% (2895/2937).
- Infection covered MSI: 97% (`composer infection` exit 143; skip-initial-tests with `build/coverage-xml`, `--threads=4`).

## Log test rest

- Branch: `log-test-rest`
- `TracedRestMethodsTest` and shared `RestDomainArrange` reuse `ApplicationTest`, `ProviderSetupTest`, and `LoggingCoreTest` paths with memory fakes; assert every `TracedMethodCatalog` entry under `src/Domain/Statement`, `src/Domain/Kingdom`, `src/Utilities/Setup`, `src/Utilities/Queue`, `src/Utilities/Session`, and `src/Utilities/Security` via `MethodLogAssert` (107 methods), plus the four `Utilities/Log` correlation helpers (494/494 catalog entries asserted across M-02–M-07).
- Line coverage: 98.57% (2895/2937).
- Infection covered MSI: 99% (`composer infection` exit 143; skip-initial-tests with `build/coverage-xml`, `--threads=4`).

## App bootstrap wiring

- Branch: `app-bootstrap-wiring`
- `AppBootstrapWiringTest` boots like `public/index.php` (bootstrap, `DenariusLog::install`, `middleware.php`, `routes.php` with `SESSION_REDIS_HOST` empty), resolves every route callable from the container, smoke-tests `GET /`, and asserts `POST /admin/grant` without CSRF returns 403.
- Line coverage: 98.63% (2959/3000).
- Infection covered MSI: 99% (`composer infection:ci`, `--threads=4`).

## Branch logging

- Branch: `branch-logging`
- `DenariusLog::debugBranch` / `infoBranch` / `warnBranch`, `BranchLogLevel`, and branch lines on `StderrMethodLog`; `PostCsrfMiddleware` replaces the inline CSRF closure; decision branches for CSRF reject, principal sync, webhook auth denial, and admin auth (`BranchLoggingTest` asserts branch records). Documented in [logging-spike.md](logging-spike.md) (M-08).
- Line coverage: 98.63% (2959/3000).
- Infection covered MSI: 99% (`composer infection:ci`, `--threads=4`).
- Log-tested branches: `csrf_reject`, `csrf_skip`, `csrf_ok`, `principal_sync_session`, `principal_sync_guest`, `webhook_auth_denied`, `auth_login_required`, `auth_admin_denied`.

## Infection reliable

- Branch: `infection-reliable`
- `composer infection:ci` generates `build/coverage-xml` and JUnit once, then runs Infection with `--skip-initial-tests` and `--threads=4` so PCOV does not kill the second PHPUnit pass (exit 143). Documented in this file’s gate paragraph and [method-log-tests.md](method-log-tests.md).
- Line coverage: 98.57% (2895/2937).
- Infection covered MSI: 99% (`composer infection:ci`).

## Log test catalog gate

- Branch: `log-test-catalog-gate`
- `TracedMethodCoverageManifest` maps each `TracedMethodCatalog` entry to an M-02–M-07 `Traced*MethodsTest` scope; `TracedMethodCatalogGateTest` fails when a trace site is unmapped. Documented in [method-log-tests.md](method-log-tests.md) (M-08).
- Line coverage: 98.57% (2895/2937).
- Infection covered MSI: 99% (skip-initial-tests with `build/coverage-xml`, `--threads=4`).

## UI IDP design

- Branch: `ui-idp-design` (stacked on `app-bootstrap-wiring`).
- Tailwind layout, fonts, and colors aligned with Amtgard IDP; IDP logo assets copied to `public/images/` as placeholders; Twig `base.twig`, macros, and styled admin/manage/kingdom/home templates; `appVersion` Twig global from `BuildInfo`.
- Includes FPM-safe `JsonStderrHandler` (`php://stderr` when `STDERR` is undefined) and bootstrap wiring assertions for HTML home.

## Publication docs privacy

- Branch: `publication-docs-privacy` (stacked on `publication-pattern-registry`).
- Manage and kingdom Twig copy documents disclosure tiers, quantization, and verification withholding; privacy policy describes the publication pipeline (copy only).
- Line coverage: 95.26% (4558/4785).
- Infection covered MSI: not re-run (Twig-only diff; parent stack at 95% covered MSI).

## Review category override (M-TAX-04)

- Branch: `review-category-override` (stacked on `ingest-categorizer` @ `8e85e81`).
- Manage review gains category metadata on queue rows, type-ahead search (`TaxonomyCategorySearch`), manager overrides (`TransactionReviewService::update`), uncategorized publish gate (HARD exempt), bulk same-counterparty/month, and pattern-create route stub. UI: `CategoryTypeahead` + `/manage/{slug}/taxonomy/categories` JSON.
- Line coverage: 95.02% (5453/5739).
- Infection covered MSI: 94% (skip-initial-tests, `build/coverage-xml`, `--threads=4`, ~16m).
- Log-tested branches: `transaction_review_rejected_category`, `transaction_review_rejected_uncategorized`, `transaction_review_category_set`, `taxonomy_category_search`.

## Ingest categorizer (M-TAX-03)

- Branch: `ingest-categorizer` (stacked on `transaction-category-schema` @ `a5497bc`).
- Matcher chain (`ManagerLockMatcher` → `ProviderHintMatcher` → `KeywordRuleMatcher` → `FallbackMatcher`), `TransactionCategorizer`, and `TransactionCategoryApplier` run in `TransactionSynchronizer` before publication apply. `TransactionRecategorizeJob`, `bin/recategorize-transactions.php`, and month cache keys include `taxonomy_version`.
- Line coverage: 95.14% (5245/5513).
- Infection covered MSI: 95% (skip-initial-tests, `--threads=4`, ~15m).
- Log-tested branches: `transaction_categorized`, `transaction_category_locked`, `transaction_category_fallback`, `transaction_recategorize_completed` (no description/counterparty in log context).

## Transaction category schema (M-TAX-02)

- Branch: `transaction-category-schema` (stacked on `taxonomy-catalog` @ `85e8ab5`).
- Phinx migration adds `provider_category`, `category_source`, `category_rule_id`, `category_confidence`, `category_suggested`, and `taxonomy_version`, with legacy `general`/unknown categories normalized to `uncategorized`. `TransactionRecordRebuilder` centralizes builder copies; ingest stores provider hints on `provider_category` and defaults taxonomy slug to `uncategorized`.
- Line coverage: 95.02% (4899/5156).
- Infection covered MSI: 95% (skip-initial-tests, `--threads=4`, ~24m).
- Log-tested branches: `transaction_provider_hint_recorded`, `transaction_category_schema_migrated`.

## Taxonomy catalog (M-TAX-01)

- Branch: `taxonomy-catalog` (stacked on `publication-docs-privacy` @ `c01b259`).
- Shared pack under `data/taxonomy/` (v1 slugs, keywords, provider hints, golden fixture). `TaxonomyCatalog` + `TaxonomyCatalogLoader` validate at boot; `DescriptionNormalizer` and per-provider `ProviderAmountSign` strategies (Plaid inverts positive outflows; Teller/Stripe/SimpleFin credit-positive) with no ingest wiring yet.
- Line coverage: 95.00% (4848/5103).
- Infection covered MSI: 95% (skip-initial-tests, `build/coverage-xml`, `--threads=max`, ~15m).
- Log-tested branches: `taxonomy_catalog_loaded`, `taxonomy_catalog_rejected` (`TaxonomyCatalogLoaderTest`).

## Publication pattern registry

- Branch: `publication-pattern-registry` (stacked on `publication-envelope-review`).
- `PatternRegistryStage` with `PublicationRulesetVersion::CURRENT` and SOFT `ProfessionalServicesSoftPattern`; merges `pattern_ids` into existing ingest HARD `publication_flags`; treasurer signal is `infoBranch` only (`publication_treasurer_alert`).
- Line coverage: 95.26% (4558/4785).
- Infection covered MSI: 95% (skip-initial-tests, `--threads=4`).
- Log-tested branches: `publication_soft_pattern_applied`, `publication_treasurer_alert`.

## Publication envelope review

- Branch: `publication-envelope-review` (stacked on `amount-and-balance-quantization`).
- `EnvelopeReviewStage` compares quantized line sums to the coarsened balance delta and fail-closes by withholding the published balance when tolerance is exceeded.
- Line coverage: 95.24% (4484/4708).
- Infection covered MSI: 95% (skip-initial-tests, `build/coverage-xml`, `--threads=4`, ~15m).
- Log-tested branches: `publication_envelope_leak`, `publication_envelope_ok`.

## Amount and balance quantization

- Branch: `amount-and-balance-quantization` (stacked on `display-mode-disclosure-tiers`).
- Phinx migration adds kingdom `amount_quantum_cents`, `balance_quantum_floor_cents`, `balance_quantum_ceiling_cents`, and `balance_quantum_step_cents` (defaults per `PublicationPlatformLimits` / threat model §5.1); wired through kingdom record, entity, repository, and rebuilder.
- `PublicationSettingsValidator` clamps amount and balance quantum settings; `AmountQuantizationStage` and `BalanceCoarseningStage` replace deferred pipeline slots (pull-round vs last published balance on the envelope).
- Line coverage: 95.24% (4460/4683).
- Infection covered MSI: 95% (skip-initial-tests, `--threads=4`, ~14m).
- Log-tested branches: `publication_amount_quantized`, `publication_balance_quantum`, `publication_balance_coarsened`, `amount_quantum_clamped`, `balance_quantum_clamped`.

## Display mode disclosure tiers

- Branch: `display-mode-disclosure-tiers` (stacked on `micro-deposit-hard-redact`).
- Replaces legacy `all` with **`less_redacted`** (Phinx data migration + `DisplayMode::fromStored` / settings canonicalization); manage tier labels updated; `LessRedactedPresenter` and `LineRedactionStage` shape public output from the pipeline.
- Line coverage: 95.24% (4282/4496).
- Infection covered MSI: 95% (skip-initial-tests, `--threads=4`, ~19m).
- Log-tested branches: `display_mode_legacy_all`, `publication_line_redacted_tier`.

## Micro-deposit hard redact

- Branch: `micro-deposit-hard-redact` (stacked on `transaction-review-ui`).
- Ingest-time HARD flags via `VerificationKeywordHardMatcher` and `MicroDepositPairReconciler`; persisted `publication_flags` JSON; `HardRedactionStage` stubs lines on public and manager pipeline paths (no exact verification amounts).
- Line coverage: 95.22% (4244/4457).
- Infection covered MSI: 95% (skip-initial-tests, `build/coverage-xml`, `--threads=4`, ~15m; 170 undetected covered mutants).
- Log-tested branches: `publication_hard_keyword`, `publication_micro_pair_mark`, `publication_hard_redact`.

## Transaction review UI

- Branch: `transaction-review-ui` (stacked on `statement-absence-reasons`).
- Manage **Transaction review** queue with publish/withhold POST actions; sets `published_at` via `TransactionReviewService` and busts month cache.
- Line coverage: 95.18% (4072/4278).
- Infection covered MSI: 96% (Unit coverage XML + skip-initial-tests, `--threads=4`, ~100m; 36 errors/98 timeouts in run but gate metric 96%).
- Log-tested branches: `transaction_review_published`, `transaction_review_withheld`, `transaction_review_rejected_embargo`, `transaction_review_queue_loaded`, and related reject paths.

## Statement absence reasons

- Branch: `statement-absence-reasons` (stacked on `publication-pipeline-core`).
- `StatementAbsenceClassifier` chooses **Unreviewed**, **Stale transactions**, or **No current transactions since {date}** when the public month statement has no rows; `kingdom.twig` shows an info alert via `absenceMessage`.
- Line coverage: 95.62% (3910/4089).
- Infection covered MSI: 95% (skip-initial-tests, Unit coverage XML, `--threads=4`, ~22m).
- Log-tested branches: `statement_absence_unreviewed`, `statement_absence_stale`, `statement_absence_no_since` (see `StatementAbsenceTest`).

## Publication pipeline core

- Branch: `publication-pipeline-core` (stacked on `transaction-publication-schema`).
- `PublicationEnvelope` and `PublicationPipeline` (chain of responsibility) with `EmbargoPublicationStage`, `PublicationStatusStage`, and deferred slots for pattern registry, quantization, redaction, aggregates, balance, and envelope review.
- `KingdomPageQuery` (public) and `ManagerKingdomPageQuery` (manager) run through `PublicationStatementService`; Redis `CachingMonthReader` caches post-pipeline public statements and busts on kingdom settings changes.
- Line coverage: 95.61% (3815/3990).
- Infection covered MSI: 95% (skip-initial-tests, Unit coverage XML, `--threads=4`, ~24m).
- Log-tested branches: `publication_embargo_withheld`, `publication_unpublished_withheld`, `publication_pipeline_complete`, `publication_public_read`, `publication_manager_read`, `publication_lines_loaded`, `month_cache_store_public_pipeline`, `kingdom_settings_month_cache_bust`, `publication_stage_deferred`.

## Transaction publication schema

- Branch: `transaction-publication-schema` (stacked on `ui-twig-components`).
- Phinx migration adds kingdom `embargo_days` (default 3) and `initial_backfill_completed_at`, plus transaction `published_at`, `publishable_after`, and `publication_flags`; kingdom audit columns match.
- `PublicationEmbargoCalculator` and `TransactionPublicationApplier` set `publishable_after` on sync (standard embargo and first-sync backfill amnesty); `KingdomPageQuery` reads only manager-published rows via `forKingdomPublished`.
- Manage settings include embargo days (1–7); public statements stay empty until M-04 publish actions.
- Line coverage: 95.44% (3684/3860).
- Infection covered MSI: 95% (skip-initial-tests, `--threads=4`, ~15m).
- Log-tested branches: `embargo_standard`, `embargo_backfill_amnesty`, `embargo_days_clamped`, `ledger_sync_backfill_amnesty`, `ledger_backfill_completed`.

## UI Twig components

- Branch: `ui-twig-components` (stacked on `ui-idp-design`).
- Bootstrap 5 (CDN) replaces Tailwind for grid, forms, tables, and alerts; IDP primary color via `--bs-primary`. Shared Twig pieces under `templates/components/` (page-header, section-card, alert, form-field-select, data-table). Home, manage, and kingdom refactored; connect and admin markup normalized. [ui-conventions.md](../planning/ui-conventions.md). Docs tree: `docs/planning/`, `docs/reference/`, [INDEX.md](../INDEX.md).
- Line coverage: 95.40% (3589/3762).
- Infection covered MSI: not re-run (Twig-only diff; parent stack reported 99% on `log-test-catalog-gate`).

## Planned: transaction publication

Threat model and pattern registry spec: [../planning/publication-threat-model.md](../planning/publication-threat-model.md) (research spike [Bank disclosure threat research](f7663302-ee4e-4964-8b2d-3b7ac6d9173a)). Doc index: [../README.md](../README.md), [../INDEX.md](../INDEX.md).

Product locks:

- Embargo from **`posted_on`**, kingdom **`embargo_days`** 1–7, before review/public release. **First onboarding backfill:** on initial ~90-day import, rows with `posted_on` older than `embargo_days` are review-eligible immediately; only the recent tail waits (see threat model).
- **Hard redact** micro-deposit verification patterns (including descriptor/`ACCTVERIFY` flows) for all roles; managers reconcile in the bank portal.
- **Pending review** never auto-publishes; public kingdom page shows **Unreviewed**, **Stale transactions**, or **No current transactions since {date}** when the statement body is empty.
- **No wide-open public tier:** replace `display_mode=all` with **`summarized`** (financial summary / future balance sheet & I&E), **`redacted`** (fully redacted lines), **`less_redacted`** (limited fields, quantized amounts). Public paths never emit raw provider cents.
- **Balance + transaction coupling:** **`balance_quantum`** fixed or **calculated from `n` lines since last published balance** (floor/ceiling configurable); **`EnvelopeReviewStage`** ensures balance jumps match quantized line nets ([../planning/publication-threat-model.md](../planning/publication-threat-model.md) §2.1, §6).
- **Extensibility:** versioned **`PublicationPattern`** ids + ordered **`PublicationPipeline`** stages (chain of responsibility).
- **Kingdom-tunable magic numbers** (embargo, amount/balance quantum floor/ceiling/**k**, pair window) with **platform minimums** so managers cannot publish cent-exact or overly fine balances (see threat model §5.1).

Suggested stack (one branch each):

1. `transaction-publication-schema` — columns, embargo, unpublished excluded from public read path
2. `publication-pipeline-core` — envelope, stages, manager vs public paths, cache hook
3. `statement-absence-reasons` — visibility-side reason banners
4. `transaction-review-ui` — manage queue, publish/withhold
5. `micro-deposit-hard-redact` — pairing + keyword HARD rules at ingest
6. `display-mode-disclosure-tiers` — migrate `all` → `less_redacted`; manage labels; presenters use pipeline output only
7. `amount-and-balance-quantization` — `amount_quantum`, `balance_quantum`, pull-round vs last published balance
8. `publication-envelope-review` — holistic leak tests (balance vs line sum, partial redaction windows)
9. `publication-pattern-registry` — versioned SOFT patterns, ruleset reprocess, treasurer alerts
10. `publication-docs-privacy` — manage and privacy copy
