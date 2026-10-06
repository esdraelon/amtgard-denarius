# Denarius statement publication: security and privacy threat model

Research spike for kingdom treasury bank transactions published to members at **public**, **registered**, or **kingdom-only** visibility. Threat actors include external readers, compromised accounts, and **legacy managers** who may reuse published ledger data to complete account verification, identity challenges, or social engineering at banks and fintechs.

**Published line fields (today):** `postedOn`, `amountCents`, `category`, `description`, `counterparty`, `status`, `accountName`. Redaction must apply **before** presenters and the Redis month cache so managers and APIs cannot accidentally leak raw values on HARD-tier rows.

**Product mandates (non-negotiable):**

- **Hard redact** detected “two small deposits” (credits under $1.00) for **everyone**, including managers.
- **Embargo** from **`postedOn`** for **`embargo_days`** ∈ **1–7** before a row is eligible for publication review.
- **Unreviewed** rows never auto-publish; public views explain absence (**Unreviewed**, **Stale transactions**, **No current transactions since …**).
- **Goal:** Published statements must not supply enough signal to finish micro-deposit, descriptor-code, or amount-based verification on the kingdom account elsewhere.

Implementation uses a **`PublicationPipeline`** (chain of responsibility) plus an extensible **`PublicationPattern` registry**. See [../reference/milestones.md](../reference/milestones.md) (planned stack at end), [../README.md](../README.md), and [../INDEX.md](../INDEX.md).

**Document version:** `threat-model/v2` (extend via new pattern ids and pipeline stages; do not fork ad hoc logic in presenters).

---

## 1. Attack scenarios

### 1.1 Micro-deposit verification (two amounts)

Attacker links kingdom routing/account at a fintech, receives two credits (typically $0.01–$0.99), and needs **exact cent amounts** from activity or a statement mirror.

| Field | Abuse |
|-------|--------|
| `amountCents` | Exact match to verification UI |
| `postedOn` | Identifies which two rows belong to one attempt |
| `description` / `counterparty` | May name STRIPE, PAYPAL, ACCTVERIFY |
| `accountName` | Disambiguates multiple kingdom accounts |

References: [NACHA Micro-Entries](https://www.nacha.org/micro-entries), [Stripe micro-deposits](https://stripe.com/resources/more/what-is-micro-deposit-verification-here-is-how-it-works), [PayPal bank confirm](https://www.paypal.com/us/cshelp/article/how-do-i-confirm-my-bank-account-with-paypal-help185).

### 1.2 Descriptor-code verification (single cent + statement text)

One **$0.01** credit; secret is in **description** (Plaid `#ABC … ACCTVERIFY`, Stripe `SM` + 4 chars, NACHA **`ACCTVERIFY`**). Amount-only redaction is insufficient.

References: [Plaid Same-Day micro-deposits](https://plaid.com/docs/auth/coverage/same-day/), [Stripe verify_microdeposits](https://docs.stripe.com/api/setup_intents/verify_microdeposits).

### 1.3 Identity / KBA (“out-of-wallet”)

Multiple-choice questions from transaction history: amount, date, merchant. Published **`description`**, **`counterparty`**, exact **`amountCents`** + **`postedOn`** enable answers.

Reference: [FFIEC authentication guidance (exact payment amount as shared secret)](https://archive.fdic.gov/view/fdic/8782/fdic_8782_DS2.pdf).

### 1.4 ACH fraud and activity probing

Statements do not expose account numbers, but exact activity supports BEC pretext and confirms live accounts via sub-$1 probing ([NEACH micro-entries](https://www.neach.org/Solutions/Trends-Research/member-update-the-importance-of-micro-entries)).

### 1.5 Social engineering

Public kingdom identity plus published transaction truth increases credibility for fake verification calls ([CFPB phishing FAQ](https://www.consumerfinance.gov/ask-cfpb/i-received-an-email-from-my-bank-or-credit-union-asking-me-to-verify-my-account-information-what-should-i-do-en-999/)).

---

## 2. HARD vs SOFT redaction

| Tier | Audience | Behavior |
|------|----------|----------|
| **HARD** | Nobody (managers, exports, cache) | Stub only; no exact cents or verification text |
| **SOFT** | Managers full; public redacted presenter | Drop `description` / `counterparty` on lower tiers |
| **EMBARGO** | Public withheld until `postedOn + embargo_days` | Managers may see pending full detail except HARD rows |

**HARD triggers (minimum v1):**

1. Two+ credits **`1 ≤ amountCents ≤ 99`** on same account within pairing window (PO rule).
2. Verification **keywords / regex** in description (ACCTVERIFY, Plaid `#XXX`, Stripe `SMxxxx`, etc.).
3. **$0.01** credit with verification keyword or long description.
4. Offset **net-zero** micro credit/debit clusters (PayPal-style reversal).

**HARD stub:** keep `postedOn`, optional category label “Bank verification (withheld)”, `status`, `accountName`; omit description/counterparty; amount as band (“Under $1 (withheld)”), never exact cents.

**SOFT:** professional-services **PII** (counterparty/description naming **accountants, lawyers**, and similar individual providers), check numbers, other identifiable vendors when matched—managers see all; public gets redacted fields. Kingdom treasuries rarely need payroll/medical categories; do not optimize patterns for those. **HARD overrides every public disclosure tier** (see §8).

---

## 2.1 Balance side-channels (transaction + balance together)

Denarius does **not** publish bank balances today, but Stripe FC already requests **balances** for refresh. Any future **balance** or **running-total** field must be designed with the same pipeline as transactions.

**Attack:** Publish exact (or fine-grained) **account balance** while **withholding or quantizing** individual lines. An observer watches balance deltas across syncs and solves for hidden amounts (especially when only one or two lines in a period are HARD-redacted or embargoed). Same logic applies to **month net change** if it is exact while line amounts are fuzzy.

**Principles:**

1. **One publication snapshot per period** — For a given kingdom month (or publish batch), compute **coarse balances and line totals from the same quantized ledger**, not from raw provider truth mixed with redacted lines.
2. **Coarse balances** — Publish balances rounded to a kingdom **`balance_quantum`**, never cents, unless the entire published transaction set for that account uses the **same** quantum and **no** HARD-withheld rows remain unresolved in that window.
3. **Configurable or calculated quantum** — **`balance_quantum`** may be a fixed kingdom setting (**$5 … $100**) or **derived at publish time** from activity since the last published balance snapshot:
   - Let **`n`** = count of **published** (or publish-candidate) lines appended since **`last_balance_published_at`** (or since the transaction that last moved the published balance).
   - Let **`Q₀`** = kingdom **balance quantum floor**, **`Q₁`** = **ceiling**, **`k`** = dollars added per extra transaction (all kingdom-adjustable within **platform guardrails** — §5.1).
   - Example: **`Q = clamp(Q₀, Q₀ + k × max(0, n − 1), Q₁)`** — few new lines → finer balance (still no cents); many new lines → coarser balance so an observer cannot solve a large underdetermined system from one balance jump.
   - Alternative: **`Q = Q₀ × ceil(sqrt(n))`** or tie **`k`** to **`amount_quantum`** so line and balance grain stay aligned.
   - **Envelope review** must use the **same Q** used to quantize the balance delta implied by those **`n`** lines; if implied net is smaller than **Q**, withhold balance update or bump **Q** up for that period.
4. **Stable / pull-rounded balances** — When showing a series of balance points, round each displayed balance **relative to the last published balance** using the **calculated Q for that batch** so small true movements do not reveal cent-level verification credits.
5. **Optional entropy** — For high-threat kingdoms, add bounded noise to published balance buckets (document max error); only if needed after (2)–(4).
6. **No balance without reconciliation** — Pipeline stage **`BalanceReconciliationGate`**: if published lines do not **reconcile mod quantum** to a balance change, do not publish a finer balance; show **“Balance unavailable (pending review)”** or a coarser band only.

**Review implication:** Balance mutation (sync ingest, publish batch, display mode change) must pass the **same** pipeline and a **holistic envelope check** (§7), not a separate “balance formatter.”

---

## 3. Detection heuristics (registry)

### Amount

- `AMT-MICRO-CREDIT`: credit, 1–99 cents → pairing candidate.
- `AMT-ONE-CENT`: strong descriptor-flow signal.
- `AMT-OFFSET-NET`: credits &lt; $1 + debit within 3 business days summing to credits → HARD cluster.

### Timing (recommended defaults)

| Parameter | Value |
|-----------|--------|
| `PAIR_WINDOW_BUSINESS_DAYS` | **7** |
| `PAIR_WINDOW_CALENDAR_FALLBACK` | **10** (same account) |
| `OFFSET_DEBIT_DAYS` | **3** business days |
| `DEFAULT_EMBARGO_DAYS` | **3** (kingdom configurable **1–7**) |

**Standard embargo:** `now >= endOfDay(postedOn) + embargo_days` before a row is eligible for manager review / public release (timezone TBD in implementation).

**Initial onboarding backfill:** Providers often return roughly **90 days** of history on first connect. Without a carve-out, treasurers would wait unnecessarily on old activity that is already “cold” for verification abuse.

- On the kingdom’s **first successful ledger sync** after connect (or first sync per published account—pick one rule in implementation and store `initial_backfill_completed_at`), classify each ingested row:
  - **`posted_on <= today - embargo_days`** → eligible for **immediate manager review** (still requires explicit publish; pattern HARD rules still apply). Treat as outside the embargo window because dwell time in the real world already exceeds `embargo_days`.
  - **`posted_on > today - embargo_days`** → normal embargo: `publishable_after = endOfDay(posted_on) + embargo_days`.
- Equivalently: on first fetch of ~90 days, managers can release up to **`~90 - embargo_days`** of calendar history right away; only the **recent tail** (length `embargo_days`) waits.
- **Subsequent syncs** use standard embargo only (no repeat backfill amnesty).
- **Security:** backfill amnesty does **not** skip HARD redaction or envelope review; it only skips the artificial dwell clock for old `posted_on` dates.

### Keywords (normalize, case-insensitive)

Hard: `ACCTVERIFY`, `MICRODEP`, `VERIFY` + brand context, regex `\#([A-Z]{3})\b` near ACCTVERIFY, `\bSM[A-Z0-9]{4}\b`.

Soft: professional-services tokens (`ATTORNEY`, `LAW`, `CPA`, `ACCOUNT`, `LLC` + person-shaped counterparty), check patterns; optional match against kingdom-known professional names.

### Pairing (spec)

On ingest/update, for each credit with `amountCents < 100` on an account, count peers in window; if **≥ 2**, mark **HARD** on all cluster members. Re-run on rule changes for windowed history.

---

## 4. Amount obfuscation (public presentation)

| Strategy | Use |
|----------|-----|
| **HARD stub** | No exact amount anywhere |
| **Quantized cents** | Default for **less redacted** tier—not raw provider cents |
| **Floor to dollar** | Kingdom `amount_quantum` baseline |
| **Quantize $2 / $3 / $10** | Anti-KBA; must match balance quantum strategy |
| **Clamp sub-$5 public cents** | Optional defense-in-depth |

Do not use rounding alone on verification rows—use HARD stub. **Avoid “exact cents on public”** even for SOFT rows unless a future explicit kingdom waiver exists and passes envelope review.

---

## 5. Extensible threat model

### 5.1 Kingdom publication settings (adjustable, anti–foot-gun)

All “magic numbers” are **per-kingdom** where noted, stored with the kingdom record (or settings table), validated on save in **`PublicationSettingsValidator`**. Managers cannot set values below platform **minimum safe** bounds; UI shows allowed range.

| Setting | Purpose | Kingdom range (example) | Platform minimum (hard floor) |
|---------|---------|-------------------------|--------------------------------|
| **`embargo_days`** | Dwell after `posted_on` | 1–7 | 1 |
| **`amount_quantum_cents`** | Public line amount grain | $1–$50 (100–5000¢) | **$1** (100¢); never 0 or cents-exact |
| **`balance_quantum_floor_cents`** (**Q₀**) | Finest balance bucket | $5–$50 | **$5** (500¢) |
| **`balance_quantum_ceiling_cents`** (**Q₁**) | Coarsest balance bucket | **Q₀**–$500+ | **Q₀**; ceiling ≥ floor |
| **`balance_quantum_step_cents`** (**k**) | Extra coarsening per txn since last balance publish | $0–$25 | 0 |
| **`pair_window_calendar_days`** | Micro-deposit pairing | 7–21 | 7 |
| **`disclosure_tier`** | summarized / redacted / less_redacted | enum | cannot select removed `all` |

**Foot-gun rules:**

- Reject **`amount_quantum_cents` < 100** and **`balance_quantum_floor_cents` < 500** (tunable constants in one class).
- Reject **`balance_quantum_ceiling_cents < balance_quantum_floor_cents`**.
- If **`less_redacted`** is selected, **`amount_quantum_cents`** must be ≥ **$1**; optional stricter default **$5** for public kingdoms.
- Changing settings **invalidates** month cache and may require **re-run envelope review** for open periods.

**Pattern registry**

- Each rule implements `PublicationPattern`: stable **`id`**, **`version`**, **`tier`** (HARD/SOFT/EMBARGO), **`match(TransactionContext)`**, **`annotate(PublicationFlags)`**.
- New threats = **new id + version**; store matched **`pattern_ids`** on each transaction for audit and reprocessing.
- **`ruleset_version`** on kingdom or global config; background job **re-scans** windowed history when ruleset bumps.

**Pipeline extensibility**

- Stages implement `PublicationStage`: **`process(PublicationEnvelope $in): PublicationEnvelope`**.
- Envelope carries: raw lines, flags, proposed public lines, proposed balances, target **disclosure tier**, period bounds, **`last_published_balance`** snapshot.
- Register stages in container order; tests inject fake stages.

**Threat model doc**

- Bump **`threat-model/vN`** in this file when semantics change; link from release notes.

---

## 6. Publication pipeline (chain of responsibility + envelope review)

Recommended stage order:

1. **`EmbargoStage`** — drop or mark ineligible by `posted_on + embargo_days`.
2. **`PatternRegistryStage`** — apply HARD/SOFT flags (§3).
3. **`PublicationStatusStage`** — only manager-**published** rows continue.
4. **`AmountQuantizationStage`** — kingdom `amount_quantum` on surviving lines.
5. **`LineRedactionStage`** — strip fields by tier + SOFT rules.
6. **`AggregateStage`** — category / income-expense totals from **quantized** lines only.
7. **`BalanceCoarseningStage`** — apply `balance_quantum`, pull-round vs last published.
8. **`EnvelopeReviewStage`** — **holistic leak check** (required):
   - No field in output may be **finer** than another when an attacker could cross-resolve (balance vs sum of lines, count of txs vs net change, sequential month nets).
   - Fail closed: downgrade to summarized-only or withhold balance for that period.
9. **`CacheSerializeStage`** — only envelope **after** stage 8 is cached or sent to Twig/API.

Managers use a **parallel path**: **`ManagerReviewEnvelope`** with full provider truth except **HARD** rows (still stubbed). Manager path must never be written to Redis month keys.

```text
ingest → persist raw → PatternRegistry (flags)
public read → PublicationPipeline → EnvelopeReview → presenter → cache
```

---

## 7. Disclosure tiers (replacing “wide open”)

Today’s `display_mode=all` exposes description, counterparty, and exact amounts—a **wide open** tier that conflicts with verification and KBA threats. Planned kingdom-facing options:

| Tier (stored value) | Member sees | Purpose |
|---------------------|-------------|---------|
| **`summarized`** | **Financial summary** — category totals (v1); later balance sheet / income & expense aggregates from quantized data | Accountability without transaction-level KBA |
| **`redacted`** | **Fully redacted** — date, category, **quantized** amount (no description/counterparty) | Default-safe line listing |
| **`less_redacted`** | **Less redacted** — adds account name and limited description/counterparty rules (SOFT redaction still applies; amounts **quantized**) | Transparency with guardrails |

**Migration:** map legacy **`all` → `less_redacted`** with forced quantization + publication pipeline; do not expose raw cents on public paths.

**Visibility** (`public` / `registered` / `kingdom_only`) stays **who** can open the page; disclosure tier is **what shape** the data takes after the pipeline.

---

## 8. Engineering checklist

1. Detect at **ingest**; persist `publication_flags` / `pattern_ids` / `ruleset_version`.
2. **All public output** through **`PublicationPipeline`** including aggregates and balances.
3. **`EnvelopeReviewStage`** tests: balance delta vs redacted lines, single hidden micro-deposit in period, mode switch `less_redacted` → `summarized`.
4. Manager path bypasses **publish** gates only, not **HARD** pattern stubs.
5. **Tests:** $0.32/$0.45 pair, descriptor codes, offset debit, embargo, balance quantum reconciliation.
6. Treasurer alert (out-of-band) on HARD match—not on public feed.

---

## 9. Reference URLs

NACHA micro-entries and Phase 2 velocity: https://www.nacha.org/micro-entries , https://www.nacha.org/rules/micro-entries-phase-2  
ACH timing: https://www.nacha.org/content/how-ach-payments-work  
Plaid: https://plaid.com/resources/payments/micro-deposit-verification/ , https://plaid.com/docs/auth/coverage/same-day/  
Stripe: https://docs.stripe.com/api/setup_intents/verify_microdeposits  
FFIEC shared secrets: https://archive.fdic.gov/view/fdic/8782/fdic_8782_DS2.pdf  

---

*Threat guidance for publication policy design; not legal advice. Bank-side MFA, alerts, and role revocation remain required for legacy managers.*
