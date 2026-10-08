# Denarius HTTP integ — orchestrator checklist

Plan: [dev-integ-coverage-plan.md](./dev-integ-coverage-plan.md).  
**Start only after SQLite logging milestones (L1–L4) are complete.** Serial queue; same rules as [branch-orchestrator](https://github.com/amtgard/amtgard-idp) (no push unless user asks).

## Phase C — Isolation

| # | Branch | Done |
|---|--------|------|
| C0 | `stack/denarius-integ-c0-phpunit-split` | [x] |
| C1 | `stack/denarius-integ-c1-integ-infra-compose` | [x] |
| C2 | `stack/denarius-integ-c2-integ-wire-hosts` | [x] |
| C2b | integ harness polish (bootstrap, smoke `/version`, JUnit, php-di 7) | [x] |
| C3 | `stack/denarius-integ-c3-per-test-reseed` | [x] |
| C4 | `stack/denarius-integ-c4-http-support` | [x] |
| C5 | `stack/denarius-integ-c5-outbound-stubs` | [x] |
| C6 | `stack/denarius-integ-c6-worker-integ` | [x] |
| C7 | `stack/denarius-integ-c7-route-matrix-doc` | [ ] |

## Phase D — Route coverage (~90% matrix)

| # | Branch | Done |
|---|--------|------|
| D1 | `stack/denarius-integ-d1-public-static` | [ ] |
| D2 | `stack/denarius-integ-d2-auth-session` | [ ] |
| D3 | `stack/denarius-integ-d3-webhooks` | [ ] |
| D4 | `stack/denarius-integ-d4-kingdom-public` | [ ] |
| D5 | `stack/denarius-integ-d5-admin-read` | [ ] |
| D6 | `stack/denarius-integ-d6-admin-write` | [ ] |
| D7 | `stack/denarius-integ-d7-manage-read` | [ ] |
| D8 | `stack/denarius-integ-d8-manage-settings-enrollment` | [ ] |
| D9 | `stack/denarius-integ-d9-transactions` | [ ] |
| D10 | `stack/denarius-integ-d10-patterns` | [ ] |
| D11 | `stack/denarius-integ-d11-simplefin-return` | [ ] |
| D12 | `stack/denarius-integ-d12-manage-connect-refresh` | [ ] |
| D13 | `stack/denarius-integ-d13-auth-negatives` | [ ] |
| D14 | `stack/denarius-integ-d14-matrix-gate` | [ ] |

**Done when:** route matrix ≥ 90% in-scope routes; `./scripts/integ.sh` green with `--order-by=random`.
