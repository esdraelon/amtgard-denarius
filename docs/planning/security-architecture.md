# Denarius and shared-host security architecture

Planning document for **at-rest protection**, **secret handling**, and **container tiering**. Complements [publication-threat-model.md](publication-threat-model.md) (what members see after publish) with controls for **DB/backup exfil**, **logs**, and **provider API blast radius**.

**Scope:** Denarius (`denarius.amtgard.com`) and sibling apps on the same host—notably the Amtgard IDP (`idp.amtgard.com`)—that need similar separation of **data at rest** from **runtime secrets**.

**Status:** Design for review; not implemented.

**Document version:** `security-arch/v1`

---

## 1. Goals and non-goals

### Goals

1. **Backup / DB-only exfil** must not yield usable bank tokens or readable ledger sensitive fields.
2. **Stripe (and similar) platform keys** split so a compromise of the everyday sync path cannot mint new Financial Connections sessions or customers.
3. **Logs and operator artifacts** must not carry transaction material (amounts, descriptions, counterparty, raw provider bodies).
4. **Shared host pattern** so Denarius and IDP do not each invent a one-off secret story; one host-side mechanism injects the right material into the right container.

### Non-goals

- **Stopping a fully compromised app process.** If attacker code runs as Denarius PHP with the same privileges as legitimate sync/review code, they can decrypt and call providers for that tier. Crypto and KMS do not fix RCE; tiering and blast-radius reduction do.
- **Replacing publication HARD/SOFT/embargo.** Encryption at rest protects dumps; publication pipeline protects members and verification abuse.

---

## 2. Threat actors (what each control addresses)

| Adversary | Typical access | Primary controls |
|-----------|----------------|------------------|
| **Offline DB / backup thief** | MariaDB dump, replica, old volume snapshot; **no** live app secrets | Field encryption + KMS-wrapped DEKs; enrollment tokens in ciphertext; secrets **excluded from backups** |
| **DB read-only user / leaked SELECT** | Reporting creds, SQL injection limited to read | Same ciphertext; no unwrap key on DB host |
| **RCE in web container (`www-data`)** | Env for **web** tier, session, IDP client secret if mis-placed | **No** sync Stripe key, no bulk KMS unwrap on web; stripped image, no shell |
| **RCE in worker container** | Sync key, KMS decrypt for ledger, provider tokens | Restricted Stripe key; audit; rate limits; incident response |
| **Container escape to host** | Other containers, IAM roles on host | Separate IAM per task; encrypted local secret cache keyed to host identity; minimal host agents |
| **Insider with deploy access** | Change task defs, read agent cache | Policy, audit, break-glass; out of scope for pure tech |

**KMS is mainly for:** pairing **encrypted data** with **keys that are not stored beside backups**, and optional **per-tier IAM** (only worker may `Decrypt`). It is **not** a substitute for www-data containment.

**Container sandboxing is mainly for:** limiting **elevation and lateral movement** (escape, reading neighbor apps, host root). Treat **RCE inside the app container as game over for that container’s injected secrets**.

---

## 3. Host-side secret agent (shared Denarius + IDP)

### 3.1 Intent

Avoid long-lived plaintext **master** secrets in:

- git, compose files committed to disk on dev laptops (use `.env.example` only),
- database backups,
- every container’s environment at rest on disk when containers are stopped.

Instead: a **host-level agent** (single service per machine or per trust zone):

1. **Authenticates to KMS** (cloud KMS, or org HSM) with a **host IAM role** or mTLS identity.
2. **Polls on a schedule** (e.g. once per hour, plus on boot and on SIGHUP) to refresh **wrapped secret bundles** for each **consumer app + tier**.
3. **Stores bundles locally only in encrypted form** (wrapped by KMS or host TPM); plaintext exists briefly in agent memory when refreshing or serving a deploy hook.
4. **Injects at deploy or on demand:**
   - **Deploy time:** compose/systemd hook writes a **runtime-only** env file or Docker secret into a tmpfs mount consumed by `docker compose up`.
   - **Run time (optional):** Unix socket or HTTP on `127.0.0.1` — container asks agent for `denarius-worker/sync` material; agent returns short-lived session key or plaintext secret over authenticated local channel.

Denarius and IDP register **profiles** with the agent (e.g. `denarius-web`, `denarius-worker`, `denarius-connect`, `idp-web`, `idp-worker`), each mapping to a KMS secret ARN or path and an **allowlist of container names** that may receive injection.

### 3.2 “Keep KMS lively” polling

Hourly poll serves multiple purposes:

- **Refresh** before cloud secret rotation or KMS key policy changes break deploys.
- **Detect drift** (agent logs failure; alerting).
- **Re-wrap** local encrypted cache if KMS EDEK rotation is policy.
- **Warm path** so deploy does not block on cold KMS latency.

Local encrypted cache on disk is **not** a second plaintext backup: it should be **useless off-host** (KMS `Decrypt` requires host identity). Document recovery: new host assumes role, agent repopulates cache.

### 3.3 Injection surfaces

| Surface | When | Notes |
|---------|------|--------|
| **Pre-start hook** | `docker compose up`, systemd `ExecStartPre` | Preferred for static tier secrets (Stripe sync key, webhook HMAC) |
| **Docker secrets / tmpfs env** | Container create | Mount read-only; `0400`; no env in image layers |
| **Local agent socket** | Rare dynamic unwrap | Optional for DEK unwrap without putting CMK in env; worker-only |

Apps **never** bake secrets into images. Production compose references **external** secrets populated by the agent.

### 3.4 Multi-app on one host

Shared agent **does not** mean shared secrets:

- Separate KMS paths per app and tier.
- Agent enforces **container name → profile** mapping so a compromised IDP container cannot request `denarius-worker` material.
- Denarius and IDP document their profiles in each repo; host runbook lists KMS ARNs and poll schedule once.

IDP likely needs: signing keys, DB encryption, OAuth client secrets for downstream apps, session/redis credentials—same pattern (web vs worker split if applicable).

---

## 4. Container tiers (Denarius)

```text
[Internet]
    → denarius-web     (manage UI, public statements, FC publishable key only)
    → denarius-worker  (ledger sync, webhooks, KMS ledger unwrap, restricted Stripe)
    → denarius-connect (optional) FC session/customer/subscribe; elevated Stripe; minimal code path

MariaDB / Redis — backups exclude secret injection paths and env files
Host agent — KMS poll, encrypted cache, inject into tiers above
```

| Tier | Holds (examples) | Must not hold |
|------|------------------|---------------|
| **web** | `STRIPE_PUBLISHABLE_KEY`, IDP client creds for login, session/redis | `STRIPE_SECRET_KEY` (any), provider bearer decrypt for bulk sync, KMS CMK |
| **worker** | `STRIPE_SECRET_KEY` (sync/restricted), `APP_KEY` or ledger KMS client, Plaid/Teller secrets, webhook verify secrets | FC session create, customer create (move to connect tier) |
| **connect** | `STRIPE_SECRET_KEY` (connect/restricted), short-lived | Transaction DB, broad KMS |

Webhook handling should live on **worker** (or dedicated **webhook** tier), not web—verification secret stays off the public PHP-FPM pool.

---

## 5. Bank linking and enrollment material

### Current shape (Stripe FC)

- Platform calls use **`STRIPE_SECRET_KEY`** on the server; per-kingdom “token” is effectively **Customer id** (`cus_*`), encrypted in `enrollment_secrets` but also stored as **`kingdoms.enrollment_id`** for webhook lookup.
- **DB-only exfil:** `cus_*` + `fca_*` are not bearer tokens without platform secret; Plaid-style tokens need **`APP_KEY`** to decrypt ciphertext.

### Direction

1. **Split Stripe keys** (§6)—stolen sync key cannot complete new links.
2. **Treat `enrollment_id` as Tier-2 metadata** for Stripe, or store **HMAC lookup** with key available only on webhook worker (optional; DB dump hides reversible mapping).
3. **Do not duplicate Tier-1 secrets** in plaintext columns when avoidable; document what is metadata vs secret in runbooks.

Provider tokens (Plaid/Teller/SimpleFIN) remain **Tier-1**: ciphertext in DB, unwrap key from agent/KMS on worker only.

---

## 6. Stripe API key split

| Key profile | Stripe permissions (conceptual) | Used by |
|-------------|----------------------------------|---------|
| **connect** | Customers write, FC Sessions write, FC Accounts subscribe | connect tier or rare admin task |
| **sync** | FC Accounts read, Transactions read, refresh/subscribe as required by Stripe FC API | worker only |
| **webhooks** | Signing secret only | worker webhook handler |

Validate exact restricted-key scopes in Stripe Dashboard when implementing. **Refresh/subscribe are POSTs** but are not money movement—they belong on **sync**, not connect.

Add **IP allowlist** on Stripe where supported.

---

## 7. Ledger field encryption (hardest: manager UX)

### 7.1 Data classification

| Column / field | At rest | Rationale |
|----------------|---------|-----------|
| `kingdom_id`, `posted_on`, provider txn id, account id | Cleartext | Coarse queries, review month navigation |
| `publication_flags`, `published_at`, `publishable_after`, category ids | Cleartext | Pipeline, gates, aggregates |
| `amount_cents`, `description`, `counterparty`, sensitive provider hints | **Encrypt** (AEAD, per-kingdom DEK) | Backup exfil |
| Redis month cache | Pipeline output only (already mandated by publication threat model) | No raw provider fields |

**DEK:** one per kingdom (or per kingdom + rotation epoch); wrapped by KMS or host agent master; worker unwraps for ingest, review, batch jobs.

### 7.2 Background paths (straightforward)

These already scan or touch many rows; they should run on **worker** with decrypt in memory only:

- **Ingest / sync** — decrypt after provider fetch; encrypt before `upsert`; run ingest HARD annotator and categorizer on plaintext in process.
- **Ruleset / pattern registry re-scan** — batch decrypt kingdom window; update `publication_flags`; persist (see publication threat model `ruleset_version`).
- **Kingdom pattern apply / recategorize** — same as today’s server-side loops; no browser change except preview scope (§7.3).

### 7.3 Manager UX (impact area — plan carefully)

Today, manage **review** is month-scoped (`TransactionReviewQueue`), which aligns with encryption. **Pattern wizard preview** currently loads **all kingdom transactions** and returns description/counterparty/amount for matches (`KingdomPatternReviewWizard::previewMatches`)—the main UX tension.

**Recommended product/tech split:**

| Flow | UX approach |
|------|-------------|
| **Transaction review** (publish/redact/embargo, taxonomy) | Server decrypt **only rows for selected `review_month`** (and published accounts). No change to what managers see; change **where** decrypt runs (worker or web with narrow API to worker). |
| **Pattern create — anchor from one row** | Preview defaults to **anchor transaction’s month** (or ±1 month configurable). Show match count + **paginated** sample rows (e.g. 20 per page). |
| **Pattern create — “whole kingdom history”** | **Async job** on worker: treasurer submits draft rule → job id → poll or email → full match list or CSV download link (authenticated, short TTL). UI copy: “Full history scan runs in the background; large kingdoms may take a few minutes.” |
| **Pattern preview API** | Never log response bodies; cap synchronous preview row count; document limits in manage UI. |
| **Alternative (minimal)** | “Examples only” mode: show 5–10 sample matches + total count without paging entire history in one JSON response. |

**Do not** ship bulk decrypted ledger JSON to the browser for client-side filtering—worse trust model and no win for PHP manage app.

**Publication** unchanged: public paths still use `PublicationPipeline` only; encryption at rest is invisible to members.

### 7.4 Implementation notes (later)

- Introduce a **LedgerFieldCipher** (or column-level codec) behind `TransactionRepository` so controllers stay unaware.
- **Migration:** encrypt existing rows in a one-off worker job; dual-read during rollout if needed.
- **Tests:** unit tests use in-memory fake cipher; integ uses fixed test DEK from env (test-only).

---

## 8. Log redaction

Extend [logging-spike.md](../reference/logging-spike.md) contract:

- **Redact keys** (nested): add `description`, `counterparty`, `amount`, `amount_cents`, `body`, `payload`, `transaction`, and provider-specific row blobs—or adopt **ledger channel allowlist** (`kingdom_id`, `month`, `count`, opaque ids).
- **Never** log webhook raw bodies or Stripe/Plaid HTTP response bodies in branch context.
- **Audit** `Domain/Bank` and `Service/Ledger` `debugBranch`/`infoBranch` contexts.
- **`last_sync_error`** on kingdom rows: sanitize provider error text if it could echo sensitive payloads (policy: generic message to DB, detail in restricted ops log).

---

## 9. Relation to publication threat model

| Layer | Document | Protects |
|-------|----------|----------|
| Member-facing disclosure | [publication-threat-model.md](publication-threat-model.md) | Verification abuse, KBA, micro-deposits on **published** statements |
| Storage and ops | This doc | Backup thief, logs, provider key abuse, container blast radius |

Both apply to manager paths: HARD stubs remain even for managers; encryption protects **persistence and logs**, not **intent to show treasurers full detail** on authorized review screens.

---

## 10. Open decisions (review checklist)

- [ ] Agent implementation: custom daemon vs Vault Agent vs cloud-specific (SSM + systemd)—pick one for Amtgard host.
- [ ] Poll interval default (1h) vs event-driven refresh on KMS rotation webhook.
- [ ] Whether web tier calls worker over loopback for **review month decrypt** or web gets limited `kms:Decrypt` on kingdom DEK only.
- [ ] Stripe **connect** as separate container vs one-shot `docker run` during connect POST only.
- [ ] Pattern preview: default month window vs async-only for full kingdom.
- [ ] IDP profile names and KMS path convention shared across repos.
- [ ] Milestone stack order: log redaction + Stripe split before ledger encryption vs parallel tracks.

---

## 11. Suggested implementation order (non-binding)

1. **Log redaction** — low UX risk, immediate win.
2. **Stripe key split + tier env** — agent injects different keys into web vs worker (even before full KMS agent, use deploy hooks).
3. **Host secret agent v1** — poll KMS, encrypted local cache, inject at deploy; Denarius + IDP profiles.
4. **Ledger field encryption** — repository codec + worker ingest; then manage review month path; then pattern preview caps + async full scan.

---

*Planning guidance for engineering and ops review; not legal advice.*
