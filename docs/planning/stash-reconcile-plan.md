# Stash reconcile plan (publication first)

Base: `fix-kingdom` @ `e6192c9`. Source: `backup/wip-pre-taxonomy` @ `4795d02` (same commit as `stash@{0}` "wip-pre-taxonomy"; former `stash@{1}`). Stripe-only extras came from the unreachable `stripe-wip` stash commit `c03e843`.

Publication slices (stacked, ~≤300 LOC/commit): P1 ledger-sync-status → P2a month-cache read → P2b invalidate/queue → P3 manager-redact flags → P4 review-month → P5a selections service → P5b batch UI → P6 bank-disconnect → P7 stripe refresh → P8 statement sort → P9 admin suggester → I1 dev infra → C1/C2 categorization batch (after publication green).

## Status

| Slice | Branch | Commit | State |
| --- | --- | --- | --- |
| P1 | `publication/p01-ledger-sync-status` | `1f66300` | Done |
| P2a | `publication/p02a-month-cache-read` | `d949e55` | Done |
| P2b | `publication/p02b-month-cache-queue-invalidate` | `6b463aa` | Done |
| P3 | `publication/p03-manager-redact-embargo-flags` | `598e23b` | Done |
| P4 | `publication/p04-review-month-pagination` | `f1057f7` | Done |
| P5a | `publication/p05a-apply-publication-selections` | `40de804` | Done |
| P5b | `publication/p05b-review-batch-form-ui` | `ca07cac` | Done |
| P6 | `publication/p06-bank-disconnect-reset` | `a96bee3` | Done |
| P7 | `publication/p07-stripe-refresh-and-errors` | `3643391` | Done (includes `stripe-wip` balances permission and `refreshed_balance` webhook) |
| P8 | `publication/p08-statement-posted-sort` | `5ab1090` | Done |
| P9 | `publication/p09-admin-principal-suggester` | `1e27d1a` | Done |
| I1 | `publication/i01-dev-docker-networks` | `9ec1d52` | Done |
| P10 | `publication/p10-admin-idp-lookup-hints` | tip | Done (residual admin copy and ORK example ids) |
| C1 | — | — | Not scheduled in this pass; the file-by-file audit found no remaining categorization batch code beyond P5a/P5b |
| C2 | — | — | Skipped: `/transactions/update` is the live per-row category form, and single-row publish reports embargo/category errors that batch apply skips |

Every slice passed `composer test` (≥95% line coverage); see [milestones.md](../reference/milestones.md) for per-slice coverage and scoped Infection.

## Not ported from `backup/wip-pre-taxonomy`

- **Inline initial sync on connect** (`EnrollmentService` calls `TransactionSynchronizer::sync` after queueing, logging `enrollment_initial_sync_deferred` on failure). Not ported: it would run a provider sync inside the connect request (Stripe can now block up to 45 s in `StripeTransactionRefreshWait`) and touch every `EnrollmentService` construction in tests. Connect still queues the ledger worker. Needs a product decision.
- **Multi-column `PrincipalRepository::searchByEmail`** (email, IdP id, kingdom name). Superseded by P9's IdP suggester; admin no longer calls it.
- **`BootstrapAdmins::idpUserIds()`**: unused accessor.
- **Admin "no ORK kingdom directory" warning**: superseded; the tip falls back to `data/ork-kingdoms.bundled.json` and refreshes from ORK.
- Test-side differences track the source refactors above and the taxonomy stack.

`stash@{0}` kept because of the unported inline initial sync. Its content is also on `backup/wip-pre-taxonomy`, so `git stash drop stash@{0}` loses nothing once that decision is made.
