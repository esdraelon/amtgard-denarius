# AARO PDO migration — orchestrator checklist

Stack from **`post-pr-3-hygiene`** tip (or **`main`** after PR #4 merge).  
Workers: [development](file:///Users/inoahsmi/.cursor/skills/development/SKILL.md) + **benchmark gate** (below).

**Benchmark gate (A1+):** After unit/mutation gates, run `composer benchmark:aaro-reads -- --iterations=5` against a DB with `--seed=5000` (or document host + kingdom id). Append a one-line summary to [aaro-pdo-migration-benchmarks.md](aaro-pdo-migration-benchmarks.md). Milestones **A3/A4** must compare PDO vs AARO columns before merge.

| # | Milestone | Branch | Done |
|---|-----------|--------|------|
| A0 | Plan + checklist + microbenchmark harness | `stack/aaro-migration-plan` → `stack/aaro-read-benchmark` | [x] |
| A1 | `BankConnectionReset` via AARO repositories | `stack/aaro-bank-reset-repos` | [x] |
| A2 | Category/lineage AARO repos; catalog writes on AARO | `stack/aaro-category-repos` | [x] |
| A3 | `AaroTransactionReadDriver` + feature toggle (PDO default) | `stack/aaro-transaction-read-driver` | [x] |
| A4 | Cutover to AARO reads if benchmarks pass; remove PDO driver | `stack/aaro-transaction-read-cutover` | [ ] |

**Resume from:** _(orchestrator sets, e.g. `Resume from: A2`)_
