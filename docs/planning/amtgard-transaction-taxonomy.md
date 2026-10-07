# Amtgard transaction taxonomy and categorization

Design for a shared, kingdom-relevant category taxonomy applied to every synced bank transaction. Categories feed the **summarized** (category totals) and **redacted** (date + category + quantized amount) disclosure tiers, so a meaningless `general` bucket makes both tiers useless. Milestones: [amtgard-taxonomy-checklist.md](amtgard-taxonomy-checklist.md). Threat context: [publication-threat-model.md](publication-threat-model.md).

**Document version:** `taxonomy-design/v1.1` (taxonomy data itself is versioned separately as `taxonomy/vN`, see §3.3).

---

## 1. Problem

| Today | Consequence |
|-------|-------------|
| `TransactionRecord::$category` defaults to `general` | Summarized tier shows one bucket |
| Stripe FC and SimpleFin hardcode `general` | No signal at all for two of four providers |
| Plaid passes `personal_finance_category.primary` raw (`FOOD_AND_DRINK`, `GENERAL_MERCHANDISE`) | Consumer taxonomy, not treasury; raw provider strings reach public output |
| Teller passes `details.category` raw (`dining`, `groceries`) | Same; a different vocabulary per provider |
| `TransactionPublicationApplier::apply` copies `incoming->getCategory()` on every sync | Any manager edit would be overwritten on the next sync |
| Eight call sites hand-copy every `TransactionRecord` field (`TransactionHardRedactAnnotator`, `MicroDepositPairReconciler`, pipeline stages, …) | Adding category metadata columns means editing every copy unless consolidated |

Goal: one **closed** taxonomy shared by all kingdoms, applied automatically at ingest from shared matchers, **confirmed or overridden by a manager** during publication review, and required before a row can be published.

---

## 2. Taxonomy model

### 2.1 Flow

`TransactionFlow` enum (backed string):

| Value | Meaning | Summarized tier |
|-------|---------|-----------------|
| `income` | Money into the kingdom from outside | Income section |
| `expense` | Money out of the kingdom to outside | Expense section |
| `transfer` | Money moving between kingdom-controlled accounts (same org) | Shown separately; excluded from income/expense totals |

Flow is a property of the **category**, not stored separately. Each category declares the flow(s) it permits. The sign of the amount only produces the **default** flow.

**Sign normalization (verify in M-TAX-01):** `Money::centsFromDecimal` keeps the provider's sign. Plaid reports outflows as **positive** amounts, while Teller, SimpleFin, and Stripe FC report outflows as negative. The categorizer must take a `ProviderAmountSign` strategy per provider, or the providers must normalize to "credit positive" before ingest. Pick one; do not branch on provider id inside the categorizer.

### 2.2 Category slug

Format: `<flow>.<name>`, lowercase snake case, ≤ 48 chars (the `category` column is `varchar(64)`). Slugs are **stable identifiers**; labels may change. Never reuse a retired slug for a different meaning.

Special slugs:

| Slug | Purpose |
|------|---------|
| `uncategorized` | Fallback when no matcher reaches the auto-accept threshold. **Blocks publication.** |
| `system.bank_verification` | Label for HARD-redacted rows (matches the existing “Bank verification (withheld)” stub). Never assigned by matchers or managers; set by the HARD stub path only. |

Legacy `general` and raw provider strings are migrated to `uncategorized`, with the raw value kept in `provider_category` (§5).

### 2.3 Taxonomy v1 (locked; managers may split further via patterns)

Default taxonomy stays **granular** (separate slugs for feast tickets vs event gate, site rental vs site deposit, and so on). Managers choose the right slug per row; shared matchers only narrow the search space.

**Income**

| Slug | Label | Typical sources |
|------|-------|-----------------|
| `income.event_gate` | Event gate / site fees collected | Square/Stripe payouts tagged by event, cash deposits after events |
| `income.feast_tickets` | Feast tickets | Feast sales |
| `income.dues` | Dues and memberships | Park/kingdom dues |
| `income.chapter_remittance` | Remittance from chapters | Parks/shires remitting to kingdom |
| `income.donations` | Donations | Member gifts, PayPal Giving |
| `income.fundraising` | Fundraising | Auctions, raffles, bake sales |
| `income.merchandise` | Merchandise sales | T-shirts, patches, tokens |
| `income.refund_received` | Refunds received | Vendor refunds, deposit returns |
| `income.interest` | Interest | Bank interest |
| `income.other` | Other income | Manager-assigned only |

**Expense**

| Slug | Label | Typical sources |
|------|-------|-----------------|
| `expense.site_rental` | Site rental | Parks departments, campgrounds, scout camps, county fairgrounds, reservation systems |
| `expense.site_deposit` | Site deposits | Refundable reservation deposits |
| `expense.insurance` | Insurance | Event/liability insurance carriers, certificate fees |
| `expense.feast_groceries` | Feast groceries | Grocery chains, warehouse clubs, restaurant supply |
| `expense.event_supplies` | Event supplies | Hardware, ice, firewood, port-a-johns, tents |
| `expense.storage` | Storage | Self-storage facilities, trailer storage |
| `expense.documentation` | Documentation and printing | Print shops, office supply, copy centers |
| `expense.awards_regalia` | Awards and regalia | Belts, medallions, scroll supplies, trophies |
| `expense.loaner_gear` | Loaner gear and materials | Foam, PVC, core, fabric for loaner weapons/garb |
| `expense.postage_shipping` | Postage and shipping | USPS, UPS, FedEx |
| `expense.software_web` | Software and web hosting | Domains, hosting, SaaS |
| `expense.payment_processing` | Payment processing fees | Square, Stripe, PayPal fees |
| `expense.bank_fees` | Bank fees | Monthly service, overdraft, wire fees |
| `expense.government_fees` | Filings, licenses, taxes | Secretary of state, IRS, permits |
| `expense.professional_services` | Professional services | CPA, legal (also SOFT PII pattern; §7) |
| `expense.reimbursement` | Member reimbursements | Payments to members for receipts (§7: names never published) |
| `expense.travel` | Travel | Mileage, fuel, lodging for officers |
| `expense.charity` | Charitable giving | Food banks, sponsored causes |
| `expense.refund_issued` | Refunds issued | Event cancellations |
| `expense.other` | Other expenses | Manager-assigned only |

**Transfer**

| Slug | Label | Typical sources |
|------|-------|-----------------|
| `transfer.internal` | Transfer between kingdom accounts | “ONLINE TRANSFER TO SAV ####” |

**Processor payouts** (Square/Stripe/PayPal batch deposits) are **income**, not transfers. Shared matchers do **not** auto-target `*.other`, so processor deposits stay `uncategorized` until the treasurer picks an income slug or adds a **kingdom pattern** (M-TAX-06). Retired slug `transfer.processor_payout` maps to `uncategorized`.

**Chapter ↔ kingdom bank movements** are out of scope for v1; use income (`income.chapter_remittance`) or expense categories from the kingdom’s perspective, not a dedicated transfer slug.

`*.other` slugs are never auto-assigned, so “Other” only contains things a manager deliberately placed there.

---

## 3. Data files

### 3.1 Location and layout

```text
data/taxonomy/
  taxonomy.json              # categories, flows, labels, taxonomy version
  matchers/
    provider-hints.json      # provider category -> slug, per provider id
    keywords.json            # shared keyword/regex rules (all regions)
  fixtures/
    golden.json              # normalized descriptions -> expected slug (test corpus)
```

Files are committed, code-reviewed, and shared by every kingdom. They follow the `data/ork-kingdoms.bundled.json` precedent: repo data, loaded at boot, no DB table for shared rules.

### 3.2 Rule shape (`keywords.json`)

```json
{
  "taxonomyVersion": "taxonomy/v1",
  "rules": [
    {
      "id": "kw.site_rental.state_parks",
      "category": "expense.site_rental",
      "fields": ["description", "counterparty"],
      "match": { "type": "regex", "pattern": "\\b(STATE PARKS?|PARKS? (AND|&) REC(REATION)?|RECREATION\\.GOV|RESERVEAMERICA)\\b" },
      "flows": ["expense"],
      "confidence": 85
    },
    {
      "id": "kw.insurance.event_carriers",
      "category": "expense.insurance",
      "fields": ["counterparty", "description"],
      "match": { "type": "anyOf", "tokens": ["K&K INSURANCE", "EVENTHELPER", "THE EVENT HELPER"] },
      "flows": ["expense"],
      "confidence": 90
    }
  ]
}
```

- `id` is stable and namespaced (`kw.`, `hint.`, `region.<pack>.`). Stored on the row as `category_rule_id`.
- `match.type` ∈ `token` | `anyOf` | `regex`. Regexes are anchored with word boundaries, compiled once, and checked for catastrophic backtracking by a loader test (a bounded-length fuzz of each pattern).
- `flows` limits the rule to a sign. A rule for “AMAZON” as `expense.event_supplies` must not fire on an Amazon refund credit.
- `confidence` is 0–100 and set by the rule author.

### 3.3 Versioning

- `taxonomy.json` carries `taxonomyVersion` (`taxonomy/v1`). Bump it when slugs are added, retired, or relabeled, or when a matcher change should re-run over history.
- Each categorized row stores `taxonomy_version`. A bump enqueues a recategorize pass (M-TAX-03) over rows whose `category_source` is not `manager`.
- Retired slugs map forward through a `retired: { "old.slug": "new.slug" }` block in `taxonomy.json`. They are never deleted outright.

### 3.4 Loading

`TaxonomyCatalog` (Repository over files) is built by `TaxonomyCatalogLoader` (Builder) from `data/taxonomy/`. It is registered in `config/container.php` as a singleton factory, and the path comes from `EnvironmentLoader` so tests can point at fixture packs. Validation runs at load and fails closed on any of these:

- unknown category slug in a rule
- rule `flows` not permitted by its category
- duplicate rule id
- regex that fails to compile or exceeds the backtracking budget
- `system.*` or `*.other` targeted by a matcher rule

**Region:** Denarius is US-only. v1 ships a single shared `keywords.json` (US merchants and agencies). No per-kingdom region packs.

---

## 4. Matcher pipeline

### 4.1 Shape

```text
ProviderTransaction ─▶ CategorizationInput (normalized) ─▶ CategoryMatcherChain ─▶ CategoryDecision
                                                              │
                         ManagerLockMatcher ─ ProviderHintMatcher ─ KeywordRuleMatcher ─ (KingdomRuleMatcher, later) ─ FallbackMatcher
```

| Class | Pattern | Role |
|-------|---------|------|
| `TransactionCategorizer` | Facade | Entry point used by ingest and recategorize; returns a `CategoryDecision` |
| `CategorizationInput` | Value Object (Builder) | Normalized description, normalized counterparty, provider id, provider category, default flow, existing decision |
| `DescriptionNormalizer` | Strategy | Uppercases, strips POS prefixes (`POS DEBIT`, `SQ *`, `TST*`, `PAYPAL *`, `CHECKCARD`), strips runs of 4+ digits, store numbers, and `#…` tokens, collapses whitespace |
| `CategoryMatcher` | Strategy interface | `match(CategorizationInput): ?CategoryMatch` |
| `CategoryMatcherChain` | Chain of Responsibility | Ordered matchers from the container. First match at or above `AUTO_ACCEPT` wins, otherwise the best suggestion is kept |
| `CategoryMatch` / `CategoryDecision` | Value Object | `slug`, `source`, `ruleId`, `confidence`, `suggestedSlug` |

Matchers are injected as an ordered list in `config/container.php`. Tests replace the list with fakes; no config `if` blocks.

### 4.2 Matcher order (v1)

1. **`ManagerLockMatcher`**: if the existing row has `category_source = manager`, return it unchanged (confidence 100). This is what makes overrides survive re-sync.
2. **`ProviderHintMatcher`**: maps `provider_category` through `provider-hints.json` (e.g. Plaid `BANK_FEES` → `expense.bank_fees`, Plaid `TRANSFER_IN`/`TRANSFER_OUT` → `transfer.internal`, Teller `groceries` → `expense.feast_groceries`). Hints are low confidence (40–70) by design because consumer taxonomies misfile kingdom spending. Grocery runs are feast groceries for a kingdom, not personal food.
3. **`KeywordRuleMatcher`**: shared `keywords.json` plus any enabled regional packs, against normalized description and counterparty. When several rules match, the highest confidence wins, ties go to the longer pattern, then to file order.
4. **`KingdomRuleMatcher`** *(M-TAX-06)*: per-kingdom patterns created from review (“always file `JOE'S STORAGE` as storage”). Stored in `kingdom_category_rules`. Runs above shared keywords because a kingdom knows its own vendors.
5. **`FallbackMatcher`**: returns `uncategorized`, confidence 0, keeping the best sub-threshold match as `suggestedSlug`.

### 4.3 Confidence

| Band | Range | Effect |
|------|-------|--------|
| Auto-accept | ≥ 70 (`CategoryConfidence::AUTO_ACCEPT`, one constant) | Row gets the slug; **eligible to publish without a separate confirm step**; review shows an “auto” badge |
| Suggest | 1–69 | Row stays `uncategorized` for publish; type-ahead pre-fills `suggestedSlug` |
| None | 0 | `uncategorized`, no suggestion |

Confidence is stored (`category_confidence`) so the threshold can change later without re-matching, and so the review queue can sort “least sure first”. Auto-accepted rows still appear in review so treasurers can fix mistakes or add patterns afterward.

### 4.4 Category source

`CategorySource` enum: `manager` | `kingdom_rule` | `shared_rule` | `provider_hint` | `fallback`. It is stored per row and drives the lock and the review badges. It is **never** included in public output.

---

## 5. Persistence

Migration on `transactions` (Phinx; no ad-hoc DDL):

| Column | Type | Notes |
|--------|------|-------|
| `category` | existing `varchar(64)` | Now always a taxonomy slug |
| `provider_category` | `varchar(64) NULL` | Raw provider hint (Plaid primary, Teller category). Manager-only, never published |
| `category_source` | `varchar(16) NOT NULL DEFAULT 'fallback'` | `CategorySource` |
| `category_rule_id` | `varchar(64) NULL` | Matched rule id, for audit and debugging |
| `category_confidence` | `tinyint unsigned NOT NULL DEFAULT 0` | 0–100 |
| `category_suggested` | `varchar(64) NULL` | Best sub-threshold slug |
| `taxonomy_version` | `varchar(16) NULL` | Version that produced the decision |

Data migration: `category = 'general'` or any value not in the catalog becomes `provider_category = <old value>` (unless it was `general`) and `category = 'uncategorized'`, `category_source = 'fallback'`. The recategorize pass then fills in real slugs.

`ProviderTransaction::$category` is renamed in meaning to **provider hint**. Stripe FC and SimpleFin send `''` instead of `general`, and Plaid and Teller send their raw value. The synchronizer no longer writes provider strings into `category`.

**Prerequisite hygiene:** route every hand-copied `TransactionRecord::builder()` chain through `TransactionRecordRebuilder` before adding columns, so new fields cannot be silently dropped. This covers `TransactionHardRedactAnnotator`, `MicroDepositPairReconciler`, `TransactionPublicationApplier`, and the pipeline stages that rebuild `PublicationCandidateLine`. A test asserts that the rebuilder round-trips every constructor property, using reflection on the Builder.

AARO only: `find` / `save` / `upsert`. The recategorize pass pages by kingdom with the existing repository finders; no `query()`.

---

## 6. Integration points

### 6.1 Ingest: `TransactionSynchronizer` / `TransactionPublicationApplier`

```text
storePage(row)
  → record(kingdom, accountId, row)            # provider_category = row.category hint
  → categorizer.decide(input, existing)        # NEW: TransactionCategoryApplier
  → publication.apply(kingdom, incoming, …)    # existing embargo + HARD
  → transactions.upsert(...)
```

- A new collaborator, `TransactionCategoryApplier`, injected into `TransactionSynchronizer`, runs before `TransactionPublicationApplier::apply`. It reads the existing row once and passes it to the categorizer so `ManagerLockMatcher` can act.
- `TransactionPublicationApplier::apply` stops copying `incoming->getCategory()` blindly and copies the full category block from the categorized record.
- Logs: `transaction_categorized` (debug: slug, source, rule id, confidence; **no description text**), `transaction_category_locked` (debug), `transaction_category_fallback` (info, counts per sync only, to avoid flooding).
- Pending → posted transitions keep the decision unless the description changes, in which case the row is re-matched (still respecting the manager lock).

### 6.2 Review: `TransactionReviewQueue` / `TransactionReviewService`

- `TransactionReviewRow` gains `category`, `categoryLabel`, `categorySource`, `categorySuggested`, `categoryConfidence`.
- `rowsForManage` supports an `uncategorized` filter and sorts least-confident first within a month when the filter is on.
- `TransactionReviewService::update` row shape gains `category: string`. It is validated against `TaxonomyCatalog` (known slug, flow compatible with the amount sign, not `system.*`). On change it writes `category_source = manager`, `confidence = 100`, `rule_id = null`. On unchanged auto slugs it leaves the source alone. Invalid slugs throw `InvalidArgumentException` and log `transaction_review_rejected_category`.
- **Category picker (type-ahead):** one combobox per row, not a long `<select>`. The treasurer types to filter **flow + label** (e.g. “expense site”, “income gate”). Options are built from `TaxonomyCatalog`, restricted to flows allowed for the row’s sign (after amount normalization). Keyboard: arrow keys, Enter to commit, Escape to revert. `suggestedSlug` and auto slug pre-fill the input. No free-text categories in v1—only closed slugs.
- **Workflow:** treasurers can **sweep** the month: assign categories and publish without touching patterns. Patterns are optional and never block save/publish.
- **“Create pattern…”** (per row, non-modal blocking): opens the pattern editor (same page or slide-over) pre-filled from the row’s normalized description/counterparty and the chosen category. Saving the pattern does **not** require leaving the queue; dismiss returns to the same month view. Bulk: “Apply category to same counterparty this month” remains; M-TAX-06 adds “Create pattern from this counterparty” and a **Patterns** tab to edit rules collectively.
- `MonthInvalidator::invalidate` already runs for touched months, so category edits invalidate the cache the same way publish edits do.

### 6.2.1 Kingdom patterns (M-TAX-06)

Treasurers maintain **kingdom-only** matcher rules (tokens/regex on description and counterparty → slug). Patterns are created from review, edited in bulk, and deleted individually. Saving a pattern enqueues a **recategorize** pass for that kingdom (non-manager rows only). Patterns never export to public output and never appear in logs (rule id only). See §7.8.

### 6.3 Publication gate

**Primary gate (review service):** `TransactionReviewService::publish` throws `InvalidArgumentException('Choose a category before publishing this transaction.')` when `category = uncategorized`, and logs `transaction_review_rejected_uncategorized`. Within `update`, a row whose submitted category is `uncategorized` with `publish = true` is rejected the same way. HARD-redacted rows are exempt: they publish as `system.bank_verification` stubs regardless.

**Defense in depth (public pipeline):** add a **`CategoryLabelStage`** to `PublicationPipelineFactory::forPublicRead()` after `PatternRegistryStage` and before `AmountQuantizationStage`. It:

- maps the slug to its public label from `TaxonomyCatalog`
- maps any slug **not in the catalog**, including legacy `general`, raw Plaid strings, and retired slugs with no forward mapping, to `uncategorized` / “Uncategorized”, so raw provider vocabulary can never reach public output
- forces `system.bank_verification` for HARD rows, overriding the slug
- logs `publication_category_unknown_slug` at debug with the slug only

It does **not** drop lines. Dropping a published line would change totals that `EnvelopeReviewStage` and `BalanceCoarseningStage` reconcile against.

**Summarized tier:** `SummarizedPresenter` buckets by slug, emits flow sections (income, expense, transfer), excludes transfers from net, and shows labels rather than slugs. Small-bucket rollup uses kingdom setting **`summarized_category_min_lines`** (platform floor **2**, kingdom may raise). Applied per **calendar month** displayed. See §7.

### 6.4 Month cache

`MonthStatementCacheCodec` already serializes `category`. Add `taxonomy_version` to the month cache key namespace (`MonthCacheKeys`) so a taxonomy bump does not serve stale labels. Recategorize passes call `MonthInvalidator::forget(kingdomId)` once per kingdom, not once per row.

### 6.5 Recategorize pass

`TransactionRecategorizeJob` (worker job, same shape as `LedgerRefreshJob`) plus `bin/recategorize-transactions.php` for operators. It re-runs the categorizer over rows where `category_source != manager` and either `taxonomy_version` differs from the catalog or the category is `uncategorized`. It is idempotent, pages per kingdom, and logs `transaction_recategorize_completed` with counts per kingdom.

---

## 7. Threat model notes

Categories are published at every disclosure tier, including the most redacted. They must add accountability signal without carrying identity.

1. **Closed vocabulary only.** The public value is always a label from `taxonomy.json`. Nothing derived from `description`, `counterparty`, or `provider_category` is ever published. No free-text categories, and no manager-authored labels in v1.
2. **Metadata stays manager-side.** `provider_category`, `category_rule_id`, `category_source`, `category_confidence`, and `category_suggested` are excluded from `PublicationCandidateLine`'s public projection, the month cache, and the API. A test asserts the public payload keys.
3. **Rule ids leak vendors.** `kw.site_rental.camp_xyz` names a vendor, which is why rule ids are never published.
4. **Small-bucket re-identification (summarized tier).** One `expense.professional_services` line of ~$1,200 in a month tells readers “the kingdom paid a lawyer”. Likewise one `expense.reimbursement` line next to a public event schedule can point to a member. `SummarizedPresenter` rolls buckets with fewer than **`summarized_category_min_lines`** (default **2**, configurable per kingdom, platform minimum 2) into the flow's “Other” bucket for that month.
5. **Sensitive categories in the redacted tier.** `expense.professional_services` and `expense.reimbursement` rows on line-level tiers can be relabeled to a parent label (“Services”, “Reimbursements”). They are already SOFT-pattern territory per the threat model §2. Proposal: a taxonomy `sensitivity: soft` flag; `less_redacted` shows the label, while `redacted` and `summarized` apply the rollup in (4).
6. **KBA.** “Which merchant did you pay?” questions need the merchant, and a category is coarser. The accepted residual risk is that a category plus a quantized amount plus a date narrows KBA answers somewhat, and quantization (§4 of the threat model) remains the main control.
7. **HARD rows.** The categorizer must not score verification rows as `income.refund_received` or similar. The HARD path forces `system.bank_verification`, and matchers are forbidden from targeting `system.*`. Matching runs on the raw description before HARD stubbing, but its outputs (slug and rule id) carry no verification text.
8. **Kingdom learned rules (post-v1).** Patterns are derived from counterparties and may contain personal names, for example a member reimbursed by Zelle. They are stored manager-only, can be deleted, are never exported, and are excluded from logs (log the rule id, not the pattern).
9. **Shared matchers are public in the repo.** Knowing that “RECREATION.GOV → site rental” gives an attacker nothing beyond what the category label already discloses.
10. **Logs.** `DenariusLog` lines for categorization log the slug, source, rule id, and confidence, never description or counterparty text. A logging test asserts this on its own.

Bump [publication-threat-model.md](publication-threat-model.md) to `threat-model/v3` when `CategoryLabelStage` and the small-bucket rollup ship (M-TAX-05).

---

## 8. Out of scope for v1

- Split transactions (one Costco run covering feast groceries and event supplies). v1 assigns one category per row.
- Budgets or per-event tagging (“Spring Coronation”), which would be a separate dimension from category.
- ML or LLM classification. If ever added, it is just another `CategoryMatcher` and must run in-process with no third-party egress of descriptions.

---

## 9. Product decisions (locked 2026-10-07)

| # | Decision |
|---|----------|
| 1 | **Granular default taxonomy** — keep separate slugs; managers pick the right one per transaction. |
| 2 | **No production legacy** — not live yet; no migration policy for old published rows. |
| 3 | **Processor payouts = income** — treasurer selects the income slug (gate, dues, etc.); no transfer slug. |
| 4 | **Summarized rollup** — `summarized_category_min_lines`, default **2**, kingdom-configurable (floor 2), per calendar month. |
| 5 | **US-only matchers** — single shared `keywords.json`; no regional packs. |
| 6 | **Auto-publish threshold** — confidence ≥ 70 assigns the slug and **counts as categorized for publish** without a separate confirm click. |
| 7 | **Chapter bank transfers** — not supported; use normal income/expense slugs from the kingdom view. |
| 8 | **Review UX** — type-ahead category search; optional **Create pattern…** that never blocks categorization/publish; patterns editable later individually or in bulk (M-TAX-06). |
