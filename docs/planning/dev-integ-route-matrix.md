# Denarius HTTP integ — route matrix

Maps every route in `config/routes.php` to live HTTP integration coverage. Update this file when Phase D milestones add cases.

Plan and exclusions: [dev-integ-coverage-plan.md](./dev-integ-coverage-plan.md). Orchestrator checklist: [dev-integ-coverage-checklist.md](./dev-integ-coverage-checklist.md).

## Covered values

| Value | Meaning |
|-------|---------|
| **y** | At least one HTTP integ test hits this route with a meaningful assertion. |
| **n** | In scope; not covered yet (Phase D backlog). |
| **excluded** | Documented out of the 90% gate denominator (see plan **Exclusions**). |

## Summary (D2)

| Metric | Count |
|--------|------:|
| Routes in `config/routes.php` | 36 |
| **excluded** | 4 |
| In scope (total − excluded) | 32 |
| **y** (covered today) | 6 |
| **n** (uncovered in scope) | 26 |
| Coverage `y / in-scope` | 18.8% (target ≥ 90% at D14) |

**`GET /`**, **`GET /version`**, and **`GET /privacy-policy`** (`PublicStaticTest`, `VersionEndpointTest`); **`GET /login`**, **`GET /logout`**, and logged-out **`GET /admin`** gate (`AuthSessionTest`). Phase D fills the matrix; D14 adds `bin/check-integ-route-coverage.php`.

## Matrix

| Method | Path | Covered | Test class | Notes |
|--------|------|---------|------------|-------|
| GET | `/` | y | `PublicStaticTest` | Home landing; Denarius + sign-in markers |
| GET | `/version` | y | `VersionEndpointTest` | JSON `version` key; harness smoke |
| GET | `/privacy-policy` | y | `PublicStaticTest` | Privacy policy body + contact email |
| POST | `/webhooks/teller` | n | — | D3 webhooks; signed body via stub ledger |
| POST | `/webhooks/stripe` | n | — | D3 webhooks; `Stripe-Signature` |
| POST | `/webhooks/plaid` | n | — | D3 webhooks; `Plaid-Verification` JWT |
| GET | `/login` | y | `AuthSessionTest` | Redirect to IDP `/oauth/authorize` |
| GET | `/oauth/callback` | excluded | — | Plan exclusion: authorization-code exchange needs live IdP browser redirect; D2/D13 cover login, logout, and auth negatives without this row |
| GET | `/logout` | y | `AuthSessionTest` | Clears session; redirects home (requires `IntegAuth::loginViaIdp`) |
| GET | `/admin` | y | `AuthSessionTest` | D2: unauthenticated redirect to `/login`; D5 adds admin read |
| GET | `/admin/kingdoms` | n | — | D5 admin read |
| POST | `/admin/kingdoms/sync` | n | — | D6 admin write; ORK list stubbed in integ |
| GET | `/admin/principal-suggestions` | n | — | D5 admin read |
| POST | `/admin/grant` | n | — | D6 admin write; CSRF + admin role |
| GET | `/manage/{slug}` | n | — | D7 manage read |
| POST | `/manage/{slug}/settings` | n | — | D8 manage settings / enrollment |
| GET | `/manage/{slug}/connect` | n | — | D12 manage connect / refresh |
| POST | `/manage/{slug}/connect` | n | — | D12 manage connect / refresh |
| POST | `/manage/{slug}/enrollment` | n | — | D8 manage settings / enrollment |
| POST | `/manage/{slug}/disconnect` | n | — | D12 manage connect / refresh |
| POST | `/manage/{slug}/accounts` | n | — | D12 manage connect / refresh |
| POST | `/manage/{slug}/refresh` | n | — | D12 manage connect / refresh |
| POST | `/manage/{slug}/transactions/publish` | excluded | — | Plan exclusion: legacy single-row publish; superseded by `POST …/transactions/review` (D9); unit tests retain behavior |
| POST | `/manage/{slug}/transactions/withhold` | excluded | — | Plan exclusion: legacy single-row withhold; superseded by review batch (D9) |
| POST | `/manage/{slug}/transactions/update` | excluded | — | Plan exclusion: legacy per-row taxonomy POST; primary integ path is review + taxonomy search (D9); form may still post here in UI |
| POST | `/manage/{slug}/transactions/review` | n | — | D9 transactions (batch publish / redact / embargo) |
| GET | `/manage/{slug}/taxonomy/categories` | n | — | D7 manage read (typeahead JSON) |
| GET | `/manage/{slug}/patterns` | n | — | D10 patterns |
| GET | `/manage/{slug}/patterns/new` | n | — | D10 patterns |
| POST | `/manage/{slug}/patterns/bulk` | n | — | D10 patterns |
| POST | `/manage/{slug}/patterns` | n | — | D10 patterns (create) |
| POST | `/manage/{slug}/patterns/{ruleId}` | n | — | D10 patterns (update) |
| POST | `/manage/{slug}/patterns/{ruleId}/delete` | n | — | D10 patterns (delete) |
| GET | `/bank/simplefin/return` | n | — | D11 SimpleFIN return |
| POST | `/bank/simplefin/return` | n | — | D11 SimpleFIN return |
| GET | `/{slug}` | n | — | D4 kingdom public page (`SessionMiddleware` + `SyncPrincipalMiddleware`) |
