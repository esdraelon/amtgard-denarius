# Post-PR-3 hygiene — test plan (T0)

**Branch:** `post-pr-3-hygiene`  
**Scope:** Test-first coverage for orchestration milestones H1a–H1c (H0 doc-only). Production refactors follow T1 in separate commits.  
**Gates:** [development](file:///Users/inoahsmi/.cursor/skills/development/SKILL.md) — **≥95% line** on each PHP unit in the touch surface below (per-class clover after `composer test`); **≥80% Infection** on hygiene PHP changes at orchestrator milestones; integ rows green via `./scripts/integ.sh`.

**Baseline coverage snapshot** (PHPUnit `--coverage-text`, subset filters, pre-hygiene):

| Class / file | Lines (approx.) | Baseline line % | Notes |
|--------------|-----------------|-----------------|-------|
| `KingdomCategoryAssigner` | 308 | **35%** | Two public-path tests only |
| `TaxonomyCategoryPicker` | 140 | **71%** | Four tests; private `resolve` branches thin |
| `TaxonomyCategorySearch` | 74 | **91%** | Deleted in H1b — migrate then remove file |
| `KingdomScopedCategorySearch` | 32 | **100%** | Exercised via `CategorySearchQueryTest` |
| `CategorySearchQuery` | 22 | **~partial** | Only prefix strip paths |
| `TransactionReviewQueue` | 233 | **93%** | Via `TransactionReviewTest` |
| `KingdomPatternService` | 486 | **77%** | Via `KingdomCategoryPatternsTest` |
| `ManagerController` | 613 | **low in isolation** | Broad coverage via `TracedHttpMethodsTest`, `ManageConnectTest`, `KingdomCategoryPatternsTest`, `CoverageTest` |
| `CategoryDisplayInput` | *new H1b* | 0% | T1 adds full unit suite (test-first) |
| `ManagePagePresenter` / `ManageCsrfGuard` / `ManageKingdomAccess` | H1c | — | **`ManagePagePresenterTest`**, **`ManageCsrfGuardTest`**, **`ManageKingdomAccessTest`** (follow-up on `post-pr-3-hygiene`) |

---

## H1c testing strategy (T0 decision)

| Phase | `ManagePagePresenter` | `ManageCsrfGuard` | `ManagerController` |
|-------|----------------------|-------------------|---------------------|
| **T1** | Do **not** create classes or require 95% on them | Same | Extend **characterization** via existing controller tests + integ HTML/JSON; no presenter stubs unless autoload blocks unrelated T1 work |
| **H1c** | Add class + **`ManagePagePresenterTest`** in the **same commit** as extraction | Add helper + **`ManageCsrfGuardTest`** in same commit | Slim controller; re-run per-file clover; fill gaps with moved tests |

T1 may add **minimal stub tests** only if `KingdomCategoryAssigner` / display parsing tests need a real `CategoryDisplayInput` type before H1b lands — prefer **`CategoryDisplayInputTest`** as the primary T1 deliverable for display parsing.

---

## 1. Touch surface table

| File / class | Milestone | Pattern (target) | Role | Existing tests | Coverage gap | Path to ≥95% line |
|--------------|-----------|------------------|------|----------------|--------------|-------------------|
| `src/Controller/ManagerController.php` | H1a, H1c | Thin controller; H1c **Facade** via presenter | `page()` twig vars (`categorySearchUrl`), `categorySearch`, CSRF posts | `TracedHttpMethodsTest`, `ManageConnectTest`, `ControllerTest`, `KingdomCategoryPatternsTest`, `CoverageTest`; integ `ManageReadTest` | H1a: no unit assert on `categorySearchUrl` in twig context; H1c: large `page()` / post helpers | T1: unit cases for `categorySearch` empty/invalid flow (extend traced test or `ManagerControllerCategorySearchTest`); integ HTML asserts (§3). H1c: move `page()` assembly to presenter tests; controller tests keep route/HTTP edges |
| `templates/manage.twig`, manage partials, `pattern-form.twig` | H1a | DRY include | Single typeahead script; shared search URL | `ManageReadTest` (partial) | Duplicate script risk; hardcoded URLs in partials | Integ: one `category-typeahead.js` per response; `data-search-url` on review + patterns (§3) |
| `public/js/category-typeahead.js` | H1a optional | — | Client typeahead | None (by design) | N/A | **Integ/manual only** (§5) |
| `src/Domain/Taxonomy/CategoryDisplayInput.php` | H1b | **Value Object** | Parse `Expense:` / `Income:` display; label extraction (from picker + assigner) | None (new) | 100% gap | T1: **`CategoryDisplayInputTest`** — full method catalog (§2.1) |
| `src/Service/Ledger/KingdomCategoryAssigner.php` | H1b | **Facade** | Resolve pattern/review bodies to category ids | `KingdomCategoryAssignerTest` (2 methods) | **~65%** lines untested (`resolve`, fork, legacy slug, `category_id` paths) | Extend **`KingdomCategoryAssignerTest`** (§2.2); after H1b drop `TaxonomyCategoryPicker` dependency — update `Strategies::categoryAssigner()` |
| `src/Domain/Taxonomy/KingdomScopedCategorySearch.php` | H1b | **Facade** | Kingdom catalog search for JSON API | `CategorySearchQueryTest::testKingdomSearchFiltersByFlowAndIgnoresPrefixInQuery` | Empty query / null flow edge cases | Add **`KingdomScopedCategorySearchTest`** or extend `CategorySearchQueryTest` (§2.3) |
| `src/Domain/Taxonomy/CategorySearchQuery.php` | H1b | **Value Object** | Normalize typeahead query | `CategorySearchQueryTest::testNormalizeStripsDisplayFlowPrefix` | `transfer:` prefix, blank query, no-match passthrough | §2.4 |
| `src/Domain/Taxonomy/TaxonomyCategorySearch.php` | H1b delete | — | Legacy slug-only search | `TaxonomyCategorySearchTest` | N/A — **delete class** | §4 migration |
| `src/Domain/Taxonomy/TaxonomyCategoryPicker.php` | H1b delete | — | Legacy slug resolution | `TaxonomyCategoryPickerTest` | N/A — **delete class** | §4 migration → `CategoryDisplayInput` + assigner |
| `src/Service/Ledger/TransactionReviewQueue.php` | H1b | **Facade** | Review rows + display via assigner/catalog | `TransactionReviewTest` (queue branches, logging) | `buildRow` stored display empty vs set; status `embargoed` / `published` / `pending` | §2.5 |
| `src/Service/Ledger/KingdomPatternService.php` | H1b | **Facade** | `enrichFormPrefill`, `listViews` display fields | `KingdomCategoryPatternsTest` | `enrichFormPrefill` categoryId 0; multi-flow `listViews`; post-H1b direct `CategoryCatalog` read paths if assigner trimmed | §2.6 |
| `src/Controller/ManagePagePresenter.php` | H1c | **Facade** | Assemble manage/pattern twig models | — | 100% until H1c | **H1c commit** — §2.7 |
| `src/Controller/ManageCsrfGuard.php` (or `Service/...` per H1c) | H1c | **Template Method** | Validate CSRF on manage posts | Partial via post tests | Extracted lines | **H1c commit** — §2.8 |
| `config/container.php` | H1b | — | Wire search/assigner | `ContainerResolutionOrderTest` | Remove picker/search factories | Update resolution test after deletion |
| `tests/Unit/Strategies.php` | H1b | — | Test doubles | — | `categorySearch()` returns deleted type | Point helpers at `KingdomScopedCategorySearch`; drop picker from `categoryAssigner()` |

**Out of touch surface (no new 95% obligation):** H0 docs, A1/A2 dependency and perf docs, `TaxonomyCatalog` YAML H2 backlog.

---

## 2. Unit test catalog

Convention: **Given / When / Then** per method. Prefer extending existing classes over new files unless noted.

### 2.1 `CategoryDisplayInput` — new `tests/Unit/CategoryDisplayInputTest.php`

Expected public API (H1b); adjust names to match implementation:

| Test method | Given | When | Then |
|-------------|-------|------|------|
| `testLabelFromDisplayStripsExpensePrefix` | Display `Expense: Site rental` | `labelFromDisplay()` | Returns `Site rental` |
| `testLabelFromDisplayStripsIncomePrefixCaseInsensitive` | ` income : dues ` | `labelFromDisplay()` | Returns `dues` |
| `testLabelFromDisplayReturnsPlainLabelUnchanged` | `Custom label` | `labelFromDisplay()` | Returns `Custom label` |
| `testLabelFromDisplayEmptyString` | `''` or whitespace | `labelFromDisplay()` | Returns `''` |
| `testParsePrefixedDisplayExpense` | `Expense: Feast groceries` | `parsePrefixedDisplay()` | Flow expense, label `Feast groceries` |
| `testParsePrefixedDisplayIncome` | `Income: Member dues` | `parsePrefixedDisplay()` | Flow income, label `Member dues` |
| `testParsePrefixedDisplayNonPrefixedReturnsNull` | `Feast groceries` | `parsePrefixedDisplay()` | `null` |
| `testFlowHintFromDisplayIncomePrefix` | Display starting with `Income:` | `flowHintFromDisplay()` (if exposed) | `TransactionFlow::Income` |
| `testFlowHintFromDisplayDefaultsExpense` | Unprefixed label | `flowHintFromDisplay()` | `TransactionFlow::Expense` |

Migrate semantic intent from deleted picker tests: exact label match rules move to assigner + catalog tests; display parsing stays here.

### 2.2 `KingdomCategoryAssigner` — extend `tests/Unit/KingdomCategoryAssignerTest.php`

| Test method | Given | When | Then |
|-------------|-------|------|------|
| `testResolveForPatternUsesCategoryIdWhenPresent` | Body with `category_id` + matching display | `resolveForPattern()` | Returns same id; no fork |
| `testResolveForPatternForksWhenDisplayLabelDiffers` | Valid `category_id`, display with new label text | `resolveForPattern()` | New id from `forkLabel`; lineage preserved |
| `testResolveForPatternMapsDisplayToBundledSlug` | Empty id, `category_display` `Expense: Event supplies` | `resolveForPattern()` | Id for `expense.event_supplies` |
| `testResolveForPatternRejectsUncategorizedDisplay` | Display `Expense: Transfer` or uncategorized slug | `resolveForPattern()` | `InvalidArgumentException` (replaces picker test) |
| `testResolveForPatternLegacyCategorySlug` | `category` slug only, no display | `resolveForPattern()` | Resolves stored slug via catalog |
| `testResolveForReviewUsesAmountFlowForInvalidDisplay` | Unknown display, negative amount | `resolveForReview()` | Creates kingdom lineage (existing test extended) |
| `testResolveForReviewRejectsWrongFlowCategory` | Display matching income label, expense amount | `resolveForReview()` | `InvalidArgumentException` |
| `testResolveForReviewUsesHiddenSlugWhenDisplayEmpty` | Legacy slug + amount | `resolveForReview()` | Taxonomy slug id |
| `testResolveForPatternRequiresCategoryNameWhenEmpty` | Empty body | `resolveForPattern()` | `InvalidArgumentException` with pattern message |
| `testAnchorAmountCentsParsesOrNull` | Body with/without `pattern_anchor_amount_cents` | `anchorAmountCents()` | int or null |
| `testFlowForAndPermitsFlowDelegateToCatalog` | Known category id | `flowFor()`, `permitsFlow()` | Match fixture catalog |
| `testIsKingdomScopedTrueForCustomLineage` | Category id with `k{kingdomId}.` key | `isKingdomScoped()` | true/false per kingdom |
| `testIsUncategorizedUsesCatalogSentinel` | Uncategorized id | `isUncategorized()` | true |
| `testDisplayAndLabelForDelegateToCatalog` | Fixture category | `displayFor()`, `labelFor()`, `lineageKeyFor()` | Stable strings |

Use `CategoryCatalogFixture` + `MemoryKingdoms`; after H1b constructor drops picker — inject `CategoryDisplayInput` + `TaxonomyCatalog` + `CategoryCatalog` only.

### 2.3 `KingdomScopedCategorySearch` — `tests/Unit/KingdomScopedCategorySearchTest.php` (or extend `CategorySearchQueryTest`)

| Test method | Given | When | Then |
|-------------|-------|------|------|
| `testSearchDelegatesWithNormalizedQuery` | Catalog fixture, kingdom id 1 | `search($kingdom, 'Expense: rent', Expense)` | Results match catalog search; labels contain needle |
| `testSearchEmptyQueryReturnsFlowFilteredSet` | Non-empty catalog | `search($kingdom, '', Expense)` | All expense rows (bounded count > 0) |
| `testSearchNullFlowNotUsedByController` | — | Document: controller passes non-null flow; optional direct call with catalog mock | — |

Keep existing `CategorySearchQueryTest::testKingdomSearchFiltersByFlowAndIgnoresPrefixInQuery`; avoid duplicate assertions.

### 2.4 `CategorySearchQuery` — extend `tests/Unit/CategorySearchQueryTest.php`

| Test method | Given | When | Then |
|-------------|-------|------|------|
| `testNormalizeStripsTransferPrefix` | `Transfer: foo` | `normalize()` | `foo` |
| `testNormalizeBlankReturnsEmpty` | `   ` | `normalize()` | `''` |
| `testNormalizeWithoutPrefixUnchanged` | `site rental` | `normalize()` | `site rental` |

### 2.5 `TransactionReviewQueue` — extend `tests/Unit/TransactionReviewTest.php`

| Test method | Given | When | Then |
|-------------|-------|------|------|
| `testRowsForManagePopulatesStoredCategoryDisplay` | Candidate with categorized line | `rowsForManage()` | `storedCategoryDisplay` matches assigner display |
| `testRowsForManageLeavesDisplayEmptyWhenUncategorized` | Uncategorized line | `rowsForManage()` | `storedCategoryId` 0, display `''` |
| `testManageReviewReturnsMonthAndRowsSingleLoad` | Multiple months of candidates | `manageReview()` | Tuple month + rows; month matches request or latest |
| `testRowsForManageUncategorizedOnlySortsByConfidence` | Mix categorized/uncategorized | `rowsForManage(..., true)` | Only uncategorized; confidence ascending |
| `testReviewRowPublicationStatusEmbargoed` | Line with future `publishableAfter`, fixed `$now` | `rowsForManage()` | Row status `embargoed` |
| `testReviewRowPublicationStatusPublished` | Line with `publishedAt` set | `rowsForManage()` | status `published` |
| `testReviewRowPublicationStatusPending` | Categorized, not published, embargo open | `rowsForManage()` | status `pending` |

Assert branch logs where existing tests use `MethodLogAssert` (`transaction_review_queue_loaded`, `transaction_review_month_current`).

### 2.6 `KingdomPatternService` — extend `tests/Unit/KingdomCategoryPatternsTest.php`

| Test method | Given | When | Then |
|-------------|-------|------|------|
| `testEnrichFormPrefillBlankDisplayWhenNoCategoryId` | Prefill without category id | `enrichFormPrefill()` | `categoryDisplay` === `''` |
| `testEnrichFormPrefillUsesAssignerDisplayWhenCategoryIdSet` | Prefill with expense category id | `enrichFormPrefill()` | `Expense: …` display string |
| `testListViewsAddsCategoryDisplayAndFlow` | Kingdom with saved pattern rule | `listViews()` | Each view has `categoryDisplay`, `categoryFlow` |
| `testSaveNewUsesAssignerResolveForPattern` | Valid pattern POST body | `saveNew()` | Rule persisted with resolved category id (existing tests may cover — add if clover shows gap) |

After H1b: if display read paths use `CategoryCatalog` directly at queue/list sites, add regression test that **assigner is not called** for read-only display (mock/spy assigner) — only if refactor introduces that split.

### 2.7 `ManagePagePresenter` — **H1c milestone only** — `tests/Unit/ManagePagePresenterTest.php`

| Test method | Given | When | Then |
|-------------|-------|------|------|
| `testBuildManagePageIncludesCategorySearchUrl` | Kingdom slug `golden-plains`, review tab | `presentManage(...)` | Twig model contains `categorySearchUrl` => `/manage/golden-plains/taxonomy/categories` |
| `testBuildManagePageReviewQueueAndMonth` | Stub queue returning fixed month/rows | `presentManage(...)` | Keys `reviewQueue`, `reviewMonth`, `reviewPrevious`, `reviewNext` |
| `testBuildManagePagePatternsTabLoadsPatternList` | `manageTab` patterns | `presentManage(...)` | Non-empty `patterns` when service returns rules |
| `testBuildManagePageSettingsTabOmitsReviewScriptsData` | settings tab | `presentManage(...)` | No pattern-only keys; tab flag correct |

Use stub implementations for `TransactionReviewQueue`, `KingdomPatternService`, `HtmlRenderer` (capture template + vars).

### 2.8 `ManageCsrfGuard` — **H1c milestone only** — `tests/Unit/ManageCsrfGuardTest.php`

| Test method | Given | When | Then |
|-------------|-------|------|------|
| `testValidTokenAllowsRequest` | Session CSRF matches body | `assertValid($request, $body)` | No exception |
| `testInvalidTokenThrowsOrReturns403` | Mismatch token | `assertValid(...)` | Security failure per project convention |
| `testMissingTokenRejected` | Empty csrf field | `assertValid(...)` | Rejected |

Mirror behavior currently inlined in `ManagerController` private post helpers before extraction.

### 2.9 `ManagerController` — T1 additions (keep in existing files where possible)

| Test method | File | Given | When | Then |
|-------------|------|-------|------|------|
| `testCategorySearchEmptyFlowReturnsEmptyResults` | `TracedHttpMethodsTest` or new unit test | Query without `flow` | `categorySearch()` | JSON `{results: []}` |
| `testCategorySearchInvalidFlowReturnsEmptyResults` | same | `flow=not-a-flow` | `categorySearch()` | Empty results |
| `testCategorySearchValidRequestReturnsJson` | same | `flow=expense`, `q=site` | `categorySearch()` | 200 + non-empty results (stub kingdom) |

Do **not** block T1 on 95% of entire `ManagerController.php`; milestone gate for controller is **post-H1c** combined with presenter tests. T1 must still green **`composer test`** (project-wide 95% on `src/`).

---

## 3. Integration test catalog

Extend **`tests/Integration/Http/ManageReadTest.php`** (live HTTP, kingdom manager auth). All methods use `MANAGE_PREFIX` / `IntegFixtures::KINGDOM_SLUG`.

| Test method | Given | When | Then |
|-------------|-------|------|------|
| `testManageReviewTabTypeaheadUsesCategorySearchUrl` | Logged-in manager | `GET /manage/{slug}?tab=review` (default) | Body contains `data-search-url="/manage/{slug}/taxonomy/categories"` (or equivalent in typeahead root) |
| `testManagePatternsTabTypeaheadUsesCategorySearchUrl` | Manager | `GET /manage/{slug}/patterns` | Same URL in pattern list typeahead markup |
| `testManageReviewTabIncludesCategoryTypeaheadScriptOnce` | Manager | `GET` manage index review | Count of `<script src="/js/category-typeahead.js"></script>` === **1** |
| `testManagePatternsTabIncludesCategoryTypeaheadScriptOnce` | Manager | `GET` patterns tab | Same single script assert |
| `testManageTaxonomyCategorySearchReturnsJson` | *existing* | `GET .../taxonomy/categories?flow=expense&q=rent` | 200, JSON `results`, contains `expense.site_rental` lineage |
| `testManageTaxonomyCategorySearchEmptyFlow` | Manager | `GET .../taxonomy/categories?q=rent` (no flow) | 200, `results` empty array |
| `testManageTaxonomyCategorySearchIncomeFlow` | Manager | `GET .../taxonomy/categories?flow=income&q=dues` | All rows `flow` === `income` |
| `testManageTaxonomyCategorySearchKingdomScopedResult` | Fixture DB with kingdom-local category (if seeded) | `GET` with query matching custom label | Result includes `k{kingdomId}.` lineage key *(skip or narrow if fixture lacks row)* |
| `testManagePatternNewFormUsesCategorySearchUrl` | *extend* `testManagePatternNewFormRendersForKingdomManager` | `GET .../patterns/new` | Assert `data-search-url` matches manage taxonomy route (H1a may switch from inline twig to shared var — URL must stay) |

**H1a acceptance:** After refactor, `pattern-form.twig` and manage partials must not hardcode divergent URLs; integ tests above fail if `categorySearchUrl` drifts.

**Not integ (remain unit):** `CategorySearchQuery::normalize`, assigner fork logic.

---

## 4. Deletion and migration tests

### 4.1 Remove `TaxonomyCategorySearch`

| Action | Detail |
|--------|--------|
| Delete | `src/Domain/Taxonomy/TaxonomyCategorySearch.php`, container binding, `Strategies::categorySearch()` |
| Delete tests | `tests/Unit/TaxonomyCategorySearchTest.php` after migration |
| Migrate semantics | `testSearchFiltersByFlowAndQuery` → covered by **`ManageReadTest`** JSON + **`KingdomScopedCategorySearchTest`** (catalog-backed, includes kingdom lineages) |
| Migrate semantics | `testSearchOmitsSystemSlugs` → **`KingdomScopedCategorySearchTest::testSearchOmitsSystemCategories`** (assert no `system.` / uncategorized in results for manager search) |
| Update arrange | `tests/Support/RestDomainArrange.php`: replace `(new TaxonomyCategorySearch(...))->search(...)` with `KingdomScopedCategorySearch` + fixture kingdom |
| Update arrange | `tests/Support/ServiceWorkerArrange.php`: same replacement for worker coverage path |
| Negative | Assert **no references** remain: `grep TaxonomyCategorySearch src tests config` → empty (CI grep or unit meta-test optional) |

### 4.2 Remove `TaxonomyCategoryPicker`

| Action | Detail |
|--------|--------|
| Delete | `src/Domain/Taxonomy/TaxonomyCategoryPicker.php`, container binding |
| Delete tests | `tests/Unit/TaxonomyCategoryPickerTest.php` after migration |
| Migrate to `CategoryDisplayInputTest` | Display prefix parsing rows (§2.1) |
| Migrate to `KingdomCategoryAssignerTest` | `testResolveForPatternUsesHiddenSlugWhenPresent` → assigner with `category` slug field |
| Migrate to `KingdomCategoryAssignerTest` | `testResolveForPatternUsesDisplayLabelWhenSlugMissing` → `category_display` only |
| Migrate to `KingdomCategoryAssignerTest` | `testResolveForReviewRejectsUnknownDisplay` → same exception type/message intent |
| Migrate to `KingdomCategoryAssignerTest` | `testResolveForPatternDoesNotMapExpenseTransferLabelToTransferCategory` → uncategorized/system rejection |
| Update | `Strategies::categoryAssigner()` — remove `TaxonomyCategoryPicker` construction |
| Update | `RestDomainArrange` picker exercise → assigner `resolveForPattern` / `resolveForReview` |

### 4.3 Container / resolution

| Test | Given | When | Then |
|------|-------|------|------|
| Extend `ContainerResolutionOrderTest` | App container | Resolve `KingdomCategoryAssigner`, `KingdomScopedCategorySearch`, `ManagerController` | Success; **`TaxonomyCategoryPicker` / `TaxonomyCategorySearch` not registered** (post-H1b) |

---

## 5. Out of scope for 95% (explicit)

| Asset | Coverage approach |
|-------|-------------------|
| `public/js/category-typeahead.js` | **Integration/manual only**: integ asserts script tag present once; optional manual check typeahead debounce/save indicator. No PHPUnit JS harness unless project adds one. |
| Twig templates | Asserted via integ HTML substring tests (§3), not line coverage |
| H0 documentation | No tests |

Optional H1a JS `bind()` split: behavior unchanged — still integ/manual.

---

## 6. Gate commands

### Full unit gate (T1 + each orchestrator milestone)

```bash
cd "/Users/inoahsmi/Library/CloudStorage/GoogleDrive-en.gannim@gmail.com/My Drive/Personal/Development/ORK4/amtgard-denarius"
composer test
```

Generates `build/coverage.xml` and enforces **95%** project line coverage via `bin/check-coverage.php`.

### Per-class coverage audit (touch-surface sign-off)

After implementing tests for a class:

```bash
php vendor/bin/phpunit --coverage-text --colors=never \
  --filter 'CategoryDisplayInputTest|KingdomCategoryAssignerTest|CategorySearchQueryTest|KingdomScopedCategorySearchTest|TransactionReviewTest|KingdomCategoryPatternsTest'
```

Inspect the table row for the target class (goal **≥95%** lines on that class/file). For H1c:

```bash
php vendor/bin/phpunit --coverage-text --colors=never \
  --filter 'ManagePagePresenterTest|ManageCsrfGuardTest|TracedHttpMethodsTest'
```

### Integration subset (hygiene-related)

Requires Docker dev stack:

```bash
./scripts/integ.sh
```

**Focused subset** (faster feedback while iterating):

```bash
composer integ -- --filter ManageReadTest
```

Optional pattern flows:

```bash
composer integ -- --filter 'ManageReadTest|ManagePatternsTest'
```

### Infection (orchestrator milestones H1a–H1c)

```bash
composer infection
# or CI parity:
composer infection:ci
```

---

## 7. T1 implementation checklist

- [ ] Add `CategoryDisplayInputTest` (full §2.1) — may use test-first class stub in `src/` only if needed for autoload; prefer testing via public API once H1b adds file
- [ ] Extend `KingdomCategoryAssignerTest` (§2.2)
- [ ] Extend `CategorySearchQueryTest` + `KingdomScopedCategorySearchTest` (§2.3–2.4)
- [ ] Extend `TransactionReviewTest` (§2.5)
- [ ] Extend `KingdomCategoryPatternsTest` (§2.6)
- [ ] Extend `ManageReadTest` (§3)
- [ ] Extend `TracedHttpMethodsTest` / controller tests for `categorySearch` edges (§2.9)
- [ ] Document migration tasks for §4 — **execute file deletes with H1b**, not in T1
- [ ] **Defer** `ManagePagePresenterTest` / `ManageCsrfGuardTest` to **H1c commit**
- [ ] `composer test` green; `composer integ -- --filter ManageReadTest` green
- [ ] Update [code-hygiene-checklist.md](./code-hygiene-checklist.md) T0/T1 checkboxes when complete

---

## 8. Document sections (index)

1. Touch surface table with patterns, baselines, and ≥95% paths  
2. Unit test catalog (§2.1–2.9) with named methods and Given/When/Then  
3. Integration catalog (`ManageReadTest` extensions)  
4. Deletion/migration for `TaxonomyCategorySearch` and `TaxonomyCategoryPicker`  
5. JS integ/manual note (`category-typeahead.js`)  
6. Gate commands (`composer test`, per-class coverage, `./scripts/integ.sh`)  
7. T1 checklist and H1c co-commit rule for presenter/CSRF tests  
