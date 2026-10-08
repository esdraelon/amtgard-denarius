# Stash reconcile plan (publication first)

Base: `fix-kingdom` @ `e6192c9`. Source: `backup/wip-pre-taxonomy` (from former `stash@{1}`).

Publication slices (stacked, ~≤300 LOC/commit): P1 ledger-sync-status → P2a month-cache read → P2b invalidate/queue → P3 manager-redact flags → P4 review-month → P5a selections service → P5b batch UI → P6 bank-disconnect → P7 stripe refresh → P8 statement sort → P9 admin suggester → I1 dev infra → C1/C2 categorization batch (after publication green).

See agent spike notes in chat; drop stashes only after slice content is on branch tip.
