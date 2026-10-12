# Code hygiene plan (H0 inventory)

Post-PR-3 stack on `post-pr-3-hygiene`. Test plan: [post-pr-3-hygiene-test-plan.md](./post-pr-3-hygiene-test-plan.md).

## Class size (>500 lines)

| Class | Lines (approx.) | Milestone |
|-------|-----------------|-----------|
| `ManagerController` | ~498 after H1c | `ManagePagePresenter` Facade + `ManageKingdomAccess` Guard |
| `KingdomPatternService` | 486 | Defer H2 patterns theme |
| `TaxonomyCatalogLoader` | 494 | Defer H2 YAML seed-only |

## Pattern / duplication

| Issue | Resolution |
|-------|------------|
| CSRF blocks in `ManagerController` | `ManageCsrfGuard` Template Method (H1c) |
| `KingdomCategoryAssigner` + `TaxonomyCategoryPicker` | Catalog + `CategoryDisplayInput` (H1b) |
| `TaxonomyCategorySearch` dead vs `KingdomScopedCategorySearch` | Delete legacy (H1b) |
| Twig `categorySearchUrl` repeated | `ManagerController::page` var (H1a) |
| `category-typeahead.js` loaded twice | Single block in `manage.twig` (H1a) |

## H2 backlog

- Auto-categorization on `category_id` only (retire slug `TaxonomyCatalog` from matchers).
- `TaxonomyCatalogLoader` shrink when YAML is seed-only.
