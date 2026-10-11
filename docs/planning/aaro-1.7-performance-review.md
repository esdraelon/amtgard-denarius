# AARO 1.7 performance review (Denarius)

**Upgrade:** `amtgard/active-record-orm` v1.6.0 → **v1.7.0** on `post-pr-3-hygiene`.  
**Context:** AARO 1.7 improves `find` / `next` iterator loops roughly **10×–500×** vs earlier 1.x; Denarius should stay idiomatic AARO for admin-scale collections unless profiling proves otherwise.

## Inventory

| Path | Pattern | Notes |
|------|---------|--------|
| `KingdomRepository`, `AccountRepository`, `PrincipalRepository`, `RoleGrantRepository`, `KingdomCategoryRuleRepository` | AARO `find()` / `next()` loops via `collected()` | Small cardinalities (kingdoms, grants, rules per kingdom) |
| `OrmTransactionRepository` | AARO writes + `fetchBy` | Single-row upsert/publish paths |
| `PdoTransactionReadDriver` | **Direct PDO** | Kingdom transaction lists — high row count |
| `DbCategoryCatalog` | **Direct PDO** bulk reload | Full `categories` + `category_lineages` read at startup |
| `TransactionRepository` | **Facade** | Reads → PDO driver; writes → ORM |

## Decision matrix

| Call site | Recommendation | Rationale |
|-----------|----------------|-----------|
| Manage/admin repository lists | **Keep AARO 1.7** | Loop cost now low; audit/entity mapping preserved |
| `listForKingdom` transactions | **Keep PDO** | Already optimized; month-scoped publication reads |
| Category catalog reload | **Keep PDO** | Single query set; not ORM’s sweet spot |
| `OrmTransactionRepository::markPublished` | **Keep AARO** (optional micro-optim later) | Double `fetchBy` per call is 2 round-trips, not N+1 loops; revisit only if traced hot |

## Measurements

No dedicated benchmark harness added. Qualitative: kingdom/rule list sizes in production are orders of magnitude below transaction month scans; **1.7 loop gains remove the main historical reason to duplicate ORM logic in PDO** for those lists.

## Follow-on (optional)

- If manage latency traces show `collected()` hot, profile with fixture DB before adding drivers.
- Do **not** convert `DbCategoryCatalog` or `PdoTransactionReadDriver` to AARO without a measured regression.

## Conclusion

**Stay the course:** PDO for bulk reads, AARO 1.7 for entity repositories and transaction writes. No code changes required beyond the dependency bump for this review.
