# AARO vs PDO microbenchmark log

Recorded by `composer benchmark:aaro-reads` (see `bin/benchmark-aaro-reads.php`).

| Date | Branch / commit | Kingdom id | Seed rows | Iterations | PDO list p50/p95 (ms) | AARO list p50/p95 (ms) | PDO find p50 (ms) | AARO find p50 (ms) | Catalog reload PDO (ms) | Notes |
|------|-----------------|------------|-----------|------------|------------------------|-------------------------|-------------------|--------------------|-------------------------|-------|
| 2026-10-11 | `stack/aaro-transaction-read-cutover` | 58 | 2000 | 5 | 32.95 / 37.53 | 524.21 / 622.08 | 0.36 | 0.88 | 0.60 | **p95 ratio 16.57** — cutover blocked; keep PDO default |

**Cutover policy:** [aaro-pdo-migration-plan.md](aaro-pdo-migration-plan.md#cutover-policy).
