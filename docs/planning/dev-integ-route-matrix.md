# Denarius HTTP integ — route matrix

Maps every route in `config/routes.php` to live HTTP integration coverage. Update this file when Phase D milestones add cases.

Plan and exclusions: [dev-integ-coverage-plan.md](./dev-integ-coverage-plan.md). Orchestrator checklist: [dev-integ-coverage-checklist.md](./dev-integ-coverage-checklist.md).

## Covered values

| Value | Meaning |
|-------|---------|
| **y** | At least one HTTP integ test hits this route with a meaningful assertion. |
| **n** | In scope; not covered yet (Phase D backlog). |
| **excluded** | Documented out of the 90% gate denominator (see plan **Exclusions**). |

## Summary (D7)

| Metric | Count |
|--------|------:|
| Routes in `config/routes.php` | 36 |
| **excluded** | 4 |
| In scope (total − excluded) | 32 |
| **y** (covered today) | 19 |
| **n** (uncovered in scope) | 13 |
| Coverage `y / in-scope` | 59.38% (target ≥ 90% at D14) |

**`GET /`**, **`GET /version`**, and **`GET /privacy-policy`** (`PublicStaticTest`, `VersionEndpointTest`); **`GET /login`**, **`GET /logout`**, and logged-out **`GET /admin`** gate (`AuthSessionTest`); **`POST /webhooks/teller`**, **`POST /webhooks/stripe`**, and **`POST /webhooks/plaid`** with verifier-aligned signatures (`WebhooksTest`, no session cookies); **`GET /{slug}`** for seed kingdom **`golden-plains`** and unknown slug 404 (`KingdomPageTest`); bootstrap-admin **`GET /admin`**, **`GET /admin/kingdoms`**, and **`GET /admin/principal-suggestions`** (`AdminReadTest`, `IntegAuth::loginViaIdp`); **`POST /admin/kingdoms/sync`** (CSRF + browser-style ORK JSON) and **`POST /admin/grant`** (CSRF + form fields from principal section, grant kingdom manager) (`AdminWriteTest`); kingdom-manager **`GET /manage/golden-plains`**, **`GET …/connect`** (302 to manage index), **`GET …/patterns`**, **`GET …/patterns/new`**, and **`GET …/taxonomy/categories?q=rent`** (JSON typeahead) (`ManageReadTest`, `IntegAuth::loginKingdomManagerViaIdpOrSkip` grants seed manager then logs in as `integ-manager@example.com`). Phase D fills the matrix; D14 adds `bin/check-integ-route-coverage.php`.

## Matrix

| Method | Path | Covered | Test class | Notes |
|--------|------|---------|------------|-------|
| GET | `/` | y | `PublicStaticTest` | Home landing; Denarius + sign-in markers |
| GET | `/version` | y | `VersionEndpointTest` | JSON `version` key; harness smoke |
| GET | `/privacy-policy` | y | `PublicStaticTest` | Privacy policy body + contact email |
| POST | `/webhooks/teller` | y | `WebhooksTest` | Signed JSON; `Teller-Signature` via `WebhookSignatureFixtures` |
| POST | `/webhooks/stripe` | y | `WebhooksTest` | Signed JSON; `Stripe-Signature` via `WebhookSignatureFixtures` |
| POST | `/webhooks/plaid` | y | `WebhooksTest` | Signed JSON; `Plaid-Verification` JWT (integ Plaid JWK) |
| GET | `/login` | y | `AuthSessionTest` | Redirect to IDP `/oauth/authorize` |
| GET | `/oauth/callback` | excluded | — | Plan exclusion: authorization-code exchange needs live IdP browser redirect; D2/D13 cover login, logout, and auth negatives without this row |
| GET | `/logout` | y | `AuthSessionTest` | Clears session; redirects home (requires `IntegAuth::loginViaIdp`) |
| GET | `/admin` | y | `AuthSessionTest`, `AdminReadTest` | D2: unauthenticated redirect to `/login`; D5: bootstrap admin HTML |
| GET | `/admin/kingdoms` | y | `AdminReadTest` | JSON ORK kingdom directory (bundled seed) |
| POST | `/admin/kingdoms/sync` | y | `AdminWriteTest` | CSRF JSON body; imports ORK GetKingdoms payload |
| GET | `/admin/principal-suggestions` | y | `AdminReadTest` | JSON typeahead; short `q` empty; seed admin email via IdP Client IAM |
| POST | `/admin/grant` | y | `AdminWriteTest` | CSRF + grant-manager for seed manager / Golden Plains |
| GET | `/manage/{slug}` | y | `ManageReadTest` | Seed slug `golden-plains`; kingdom manager session |
| POST | `/manage/{slug}/settings` | n | — | D8 manage settings / enrollment |
| GET | `/manage/{slug}/connect` | y | `ManageReadTest` | 302 redirect to manage index (POST connect in D12) |
| POST | `/manage/{slug}/connect` | n | — | D12 manage connect / refresh |
| POST | `/manage/{slug}/enrollment` | n | — | D8 manage settings / enrollment |
| POST | `/manage/{slug}/disconnect` | n | — | D12 manage connect / refresh |
| POST | `/manage/{slug}/accounts` | n | — | D12 manage connect / refresh |
| POST | `/manage/{slug}/refresh` | n | — | D12 manage connect / refresh |
| POST | `/manage/{slug}/transactions/publish` | excluded | — | Plan exclusion: legacy single-row publish; superseded by `POST …/transactions/review` (D9); unit tests retain behavior |
| POST | `/manage/{slug}/transactions/withhold` | excluded | — | Plan exclusion: legacy single-row withhold; superseded by review batch (D9) |
| POST | `/manage/{slug}/transactions/update` | excluded | — | Plan exclusion: legacy per-row taxonomy POST; primary integ path is review + taxonomy search (D9); form may still post here in UI |
| POST | `/manage/{slug}/transactions/review` | n | — | D9 transactions (batch publish / redact / embargo) |
| GET | `/manage/{slug}/taxonomy/categories` | y | `ManageReadTest` | JSON typeahead; `q=rent` matches `expense.site_rental` |
| GET | `/manage/{slug}/patterns` | y | `ManageReadTest` | Patterns list HTML (writes in D10) |
| GET | `/manage/{slug}/patterns/new` | y | `ManageReadTest` | New pattern form (create POST in D10) |
| POST | `/manage/{slug}/patterns/bulk` | n | — | D10 patterns |
| POST | `/manage/{slug}/patterns` | n | — | D10 patterns (create) |
| POST | `/manage/{slug}/patterns/{ruleId}` | n | — | D10 patterns (update) |
| POST | `/manage/{slug}/patterns/{ruleId}/delete` | n | — | D10 patterns (delete) |
| GET | `/bank/simplefin/return` | n | — | D11 SimpleFIN return |
| POST | `/bank/simplefin/return` | n | — | D11 SimpleFIN return |
| GET | `/{slug}` | y | `KingdomPageTest` | Public statement page; seed slug `golden-plains` (public visibility); unknown slug 404 |
