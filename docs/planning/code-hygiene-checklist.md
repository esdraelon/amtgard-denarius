# Code design pattern hygiene

Phase D integ complete — hygiene unblocked.

Skill: `code-hygiene` — structure and named patterns only; gates stay green.  
Execution plan: Cursor plan **Post-PR-3 hygiene** (`post-pr-3-hygiene` branch).

| # | Phase | Branch | Done |
|---|--------|--------|------|
| T0 | Test plan agent → `docs/planning/post-pr-3-hygiene-test-plan.md` (95%+ touch-surface coverage spec) | `post-pr-3-hygiene` | [x] |
| T1 | Test implement agent (unit + integ per test plan) | `post-pr-3-hygiene` | [x] |
| H0 | Inventory doc → `docs/planning/code-hygiene-plan.md` | `post-pr-3-hygiene` | [x] |
| H1a | Manage Twig/JS DRY (`categorySearchUrl`, typeahead script) | `post-pr-3-hygiene` | [x] |
| H1b | Taxonomy assign consolidation; remove legacy search/picker | `post-pr-3-hygiene` | [x] |
| H1c | `ManagePagePresenter` + CSRF helper; slim `ManagerController` | `post-pr-3-hygiene` | [x] |
| A1 | Bump `amtgard/active-record-orm` to **1.7.0** (^1.7); align `aaro-extensions` | `post-pr-3-hygiene` | [x] |
| A2 | `docs/planning/aaro-1.7-performance-review.md` — AARO loops vs direct SQL (1.7 10×–500× context) | `post-pr-3-hygiene` | [x] |
| PR | Push + GitHub PR vs `main` (includes hygiene + AARO work) | — | [ ] |

**H0:** Run hygiene review on `src/`; write `docs/planning/code-hygiene-plan.md` with class-size violations, missing pattern names, strategy-injection opportunities. No production refactor in H0-only commit.
