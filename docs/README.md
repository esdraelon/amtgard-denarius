# Denarius documentation

All agent, planning, and reference markdown lives under `docs/`. **Full file list:** [INDEX.md](INDEX.md).

## Active planning

Work not yet fully shipped in `main`. Agents should read these first for current intent.

| Document | Description |
|----------|-------------|
| [planning/README.md](planning/README.md) | Planning folder guide |
| [planning/publication-threat-model.md](planning/publication-threat-model.md) | Transaction publication, redaction, pipeline, kingdom settings (in progress) |
| [planning/ui-conventions.md](planning/ui-conventions.md) | Server-rendered UI: Twig components + Bootstrap 5 |

Planned implementation stack: end of [reference/milestones.md](reference/milestones.md) → **Planned: transaction publication**.

## Reference (completed / historical)

Shipped milestones, spikes, and test conventions. Cite when implementing; do not treat as the active backlog.

| Document | Description |
|----------|-------------|
| [reference/README.md](reference/README.md) | Reference folder guide |
| [reference/milestones.md](reference/milestones.md) | Stacked-branch milestone log (completed branches + planned section) |
| [reference/logging-spike.md](reference/logging-spike.md) | Logging design spike (M-01, M-08 branches) |
| [reference/method-log-tests.md](reference/method-log-tests.md) | Method trace catalog and PHPUnit gate tests |

## For agents

1. **New feature work** → `planning/` + append outcomes to `reference/milestones.md` when a branch merges.
2. **Logging / trace tests** → `reference/logging-spike.md`, `reference/method-log-tests.md`.
3. **Publication security** → `planning/publication-threat-model.md` only (until implemented and summarized in milestones).
4. **New manage/kingdom UI** → `planning/ui-conventions.md` and `templates/components/`.
