# Global categories (FK) — migration spike

## Goal

- One **`categories`** table shared by all kingdoms.
- **`transactions.category_id`** and **`kingdom_category_rules.category_id`** FK → **`categories.id`** (no slug string on those rows).
- **Copy-on-write:** changing a label never `UPDATE`s a row that is referenced; it inserts a new `categories` row and moves **`category_lineages.current_category_id`** for that **`lineage_key`**. Existing transactions keep their original **`category_id`** (historical label frozen).
- Typing a new label on assign (e.g. “Transactionations” instead of “Transactions”) creates a **new lineage** or **fork** via `CategoryCatalog::forkLabel()` / `createWithLabel()` — no error.

## DDL (implemented)

Migration: `db/migrations/20261010120000_global_categories.php`

- `categories` — immutable rows (`lineage_key`, `label`, `flows_json`, sensitivity, `assignable`, `supersedes_id`, `created_at`)
- `category_lineages` — `lineage_key` (PK) → `current_category_id` (picker + new assignments)
- Backfill from `taxonomy.json` + `kingdom_custom_categories`, then drop string columns and custom table
- FK constraints on `transactions` and `kingdom_category_rules`

## Application (in progress)

| Piece | Status |
|-------|--------|
| `CategoryCatalog` interface + `CategorySnapshot` | Done |
| `MemoryCategoryCatalog` / `CategoryCatalogFixture` (tests) | Done |
| `GlobalCategoriesMigrator` + Phinx store | Done |
| `DbCategoryCatalog` (runtime load from MariaDB) | TODO |
| `TransactionRecord.categoryId` + remove string | TODO |
| Matchers still emit slug; categorizer resolves → id | TODO |
| Typeahead `category_id` hidden field | TODO |
| Remove `KingdomCustomCategory*` | After cutover |

## Matcher / JSON pack

Keyword and provider-hint JSON keep **`lineage_key`** strings (= today’s slugs). Runtime resolves to **`current_category_id`** via `CategoryCatalog::currentIdForLineageKey()`.

## Agent spike reference

Full file-level plan: explore agent transcript `69e26924-039b-4b0e-b51d-b0f22859ea21`.
