# Amtgard transaction taxonomy — orchestrator checklist

Source: [amtgard-transaction-taxonomy.md](amtgard-transaction-taxonomy.md), [publication-threat-model.md](publication-threat-model.md).

Stack base for **M-TAX-03+:** `transaction-category-schema` (M-TAX-02). **M-TAX-02** stacks on `taxonomy-catalog` @ `85e8ab5`. Pre-taxonomy WIP remains in git stash `wip-pre-taxonomy` if you need month-cache / site-nav work back.

**Gates for every milestone** (from the development rules):

- `composer test` green. Line coverage of `src/` ≥ **95%**. Infection MSI and covered MSI ≥ **80%**.
- Every new decision branch emits a `DenariusLog` trace/debug/info line, and a test asserts those lines on their own.
- New services are registered in `config/container.php`. Builder-style services get explicit factories. The thick container test asserts the wiring by reflection, not just `instanceof`.
- Every new class names its design pattern in its docblock. No class over 500 lines and no method over 80.
- AARO `find` / `save` / `upsert` only. No `query()`.
- Record the branch, coverage fraction, and covered MSI in [../reference/milestones.md](../reference/milestones.md), tick the box here, and make a single commit.

---

- [x] **M-TAX-01** `taxonomy-catalog` — stacked on the publication tip
  - **Scope:** `data/taxonomy/taxonomy.json` (v1 slugs from design §2.3, plus `uncategorized` and `system.bank_verification`), `matchers/provider-hints.json` (Plaid primary values and Teller categories), `matchers/keywords.json` (initial shared rules), `fixtures/golden.json`. Add the `TransactionFlow`, `CategorySource`, and `CategoryConfidence` constants. Add `TaxonomyCatalog` (Repository) and `TaxonomyCatalogLoader` (Builder), with the path supplied by `EnvironmentLoader`, and `DescriptionNormalizer` (Strategy). Decide and implement amount-sign normalization: a `ProviderAmountSign` strategy per provider, or credit-positive normalization in providers.
  - **No behavior change:** nothing reads the catalog yet.
  - **Tests:** loader fail-closed cases (unknown slug, bad flow, duplicate id, matcher targeting `system.*` or `*.other`, regex compile failure, backtracking budget); normalizer table tests (POS prefixes, digit runs, store numbers); catalog label lookup; retired-slug forward mapping; Plaid sign fixture.
  - **Gates:** standard, plus the loader validation log lines (`taxonomy_catalog_loaded`, `taxonomy_catalog_rejected`).

- [x] **M-TAX-02** `transaction-category-schema` — stacked on `taxonomy-catalog`
  - **Scope:** First route every hand-copied `TransactionRecord::builder()` chain (`TransactionHardRedactAnnotator`, `MicroDepositPairReconciler`, `TransactionPublicationApplier`, pipeline line rebuilds) through `TransactionRecordRebuilder`. Then add a Phinx migration with `provider_category`, `category_source`, `category_rule_id`, `category_confidence`, `category_suggested`, and `taxonomy_version`, plus a data migration (`general` and unknown values become `uncategorized`, with the raw value moved to `provider_category`). Update the entity, record, and repository mapping. Treat `ProviderTransaction::$category` as a provider hint: Stripe FC and SimpleFin send `''`, Plaid and Teller send raw values, and the synchronizer writes the hint to `provider_category`, not `category`. Change the `TransactionRecord` default to `uncategorized`.
  - **Tests:** a reflection test that the rebuilder round-trips every property; repository round trip of the new columns; migration up/down against the test DB; provider adapter tests updated (hint, not category); `ApplicationTest` / persistence arrange fixtures.
  - **Gates:** standard. Existing publication tests stay green with `uncategorized` in place of `general`.

- [x] **M-TAX-03** `ingest-categorizer` — stacked on `transaction-category-schema`
  - **Scope:** `CategorizationInput` (Value Object, Builder), the `CategoryMatcher` interface, `ManagerLockMatcher`, `ProviderHintMatcher`, `KeywordRuleMatcher`, `FallbackMatcher`, `CategoryMatcherChain` (Chain of Responsibility, ordered list injected in the container), `TransactionCategorizer` (Facade), and `TransactionCategoryApplier` wired into `TransactionSynchronizer::storePage` before `TransactionPublicationApplier::apply`. The manager lock survives re-sync. Pending → posted re-matches only when the description changes. Add `TransactionRecategorizeJob` (worker) and `bin/recategorize-transactions.php` for rows that are not manager-sourced and have a stale `taxonomy_version` or are `uncategorized`, with one `MonthInvalidator::forget` per kingdom. Add `taxonomy_version` to the `MonthCacheKeys` namespace.
  - **Tests:** a golden corpus run (`fixtures/golden.json`) with a precision floor; tie-break order; confidence bands (auto / suggest / none); a flow mismatch rule not firing on a refund credit; manager lock across two syncs; recategorize idempotence; log lines (`transaction_categorized`, `transaction_category_locked`, `transaction_category_fallback`, `transaction_recategorize_completed`) **asserting that no description or counterparty text appears in the log context**.
  - **Gates:** standard.

- [x] **M-TAX-04** `review-category-override` — stacked on `ingest-categorizer` @ `8e85e81`
  - **Scope:** `TransactionReviewRow` gains category, label, source, suggested, and confidence. `TransactionReviewQueue` adds an `uncategorized` filter and a least-confident sort. `TransactionReviewService::update` accepts `category` and validates it (known slug, flow matches sign, not `system.*`); an override sets source `manager` and confidence 100. Publish is gated: `publish()` and `update(publish=true)` reject `uncategorized`, except HARD rows. Rows with auto-accepted categories (≥ 70) publish without a separate confirm step.
  - **UI:** type-ahead combobox (`CategoryTypeahead` or shared component) showing **flow + label**, filtered by allowed flows for the row sign; pre-fill from auto or suggested slug. Bulk “same counterparty this month”. **Create pattern…** link per row opens pattern editor route with query params (non-blocking; save optional). Full pattern CRUD lands in M-TAX-06; M-TAX-04 only wires the link + stub or minimal save if ready.
  - **API:** JSON endpoint or embedded catalog slice for type-ahead search (slug, label, flow) — manager-authenticated only.
  - **Tests:** controller form round trip; type-ahead search filters by flow; rejected unknown slug; rejected `system.bank_verification`; rejected flow mismatch; publish blocked on `uncategorized`; auto ≥ 70 publishes; HARD row exempt; months invalidated on category-only edits; log lines (`transaction_review_rejected_category`, `transaction_review_rejected_uncategorized`, `transaction_review_category_set`).
  - **Gates:** standard.

- [x] **M-TAX-05** `public-category-presentation` — stacked on `review-category-override` @ milestone tip
  - **Scope:** add `CategoryLabelStage` to `PublicationPipelineFactory::forPublicRead()` after `PatternRegistryStage`. It maps slugs to labels, maps unknown slugs and raw provider strings to “Uncategorized”, and forces `system.bank_verification` on HARD rows. It never drops lines. `SummarizedPresenter` gets flow sections, excludes transfers from net, and rolls up small buckets using kingdom **`summarized_category_min_lines`** (default 2, floor in `PublicationPlatformLimits`, validated in `PublicationSettingsValidator`). The `redacted` and `summarized` tiers relabel sensitivity-soft categories. A public payload key test asserts that no `provider_category`, `category_rule_id`, `category_source`, `category_confidence`, or `category_suggested` appears in public output, the month cache, or the API. Update privacy copy on manage and kingdom pages. Bump the threat model to `threat-model/v3`, and move design highlights into `reference/milestones.md`.
  - **Tests:** a pipeline stage test with a legacy `general` row and a raw `FOOD_AND_DRINK` row reaching public output as “Uncategorized”; HARD label override; envelope review totals unchanged by the stage; small-bucket rollup at `k-1`, `k`, and `k+1`; transfer exclusion; disclosure tier matrix (summarized / redacted / less_redacted × soft category); log line `publication_category_unknown_slug`.
  - **Gates:** standard.

- [ ] **M-TAX-06** `kingdom-category-patterns` — stacked on `public-category-presentation`
  - **Scope:** `kingdom_category_rules` table + repository; `KingdomRuleMatcher` in the chain (above shared keywords). Manage **Patterns** tab: list, create, edit, delete kingdom rules; create from review pre-fill. Saving triggers kingdom-scoped recategorize (non-manager rows). **Create pattern…** from M-TAX-04 posts here. Bulk edit patterns without re-walking transactions. Patterns never logged with description text (rule id only).
  - **Tests:** matcher priority over shared rules; recategorize after pattern save; CRUD authorization; delete pattern stops matching on next recategorize; log lines (`kingdom_pattern_saved`, `kingdom_pattern_deleted`).
  - **Gates:** standard.

---

**Post-v1 (not scheduled):** platform PR workflow to extend shared `keywords.json`; split-transaction tagging; optional LLM matcher with no egress.
