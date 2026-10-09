# Transaction publication — orchestrator checklist

Source: [publication-threat-model.md](publication-threat-model.md), [milestones.md](../reference/milestones.md#planned-transaction-publication).

Stack base: `ui-twig-components` @ `dacfa14` (unless a later publication branch is the tip).

- [x] **M-01** `transaction-publication-schema` — DB columns, kingdom settings (embargo_days, backfill flags), transaction publish state; public read path excludes unpublished rows
- [x] **M-02** `publication-pipeline-core` — envelope, stages, manager vs public paths, month cache hook
- [x] **M-03** `statement-absence-reasons` — Unreviewed / Stale / No current transactions banners
- [x] **M-04** `transaction-review-ui` — manage queue, publish/withhold actions
- [x] **M-05** `micro-deposit-hard-redact` — pairing + keyword HARD rules at ingest
- [x] **M-06** `display-mode-disclosure-tiers` — migrate `all` → `less_redacted`; manage labels; presenters use pipeline only
- [x] **M-07** `amount-and-balance-quantization` — amount_quantum, balance_quantum, pull-round vs last published balance
- [x] **M-08** `publication-envelope-review` — holistic leak tests (balance vs line sum, partial redaction windows)
- [x] **M-09** `publication-pattern-registry` — versioned SOFT patterns, ruleset reprocess, treasurer alerts
- [x] **M-10** `publication-docs-privacy` — manage and privacy copy
