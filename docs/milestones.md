# Denarius milestones

Work is stacked with git-branchless. Each milestone is one branch. A milestone is committed after `src/` line coverage is at least 95% and Infection MSI and covered MSI are at least 80%.

## Baseline

- Branch: `main`
- Commit: `bf4ea83`
- Slim application, Docker overlays, Phinx schema, Teller enrollment, and the MariaDB ledger.

## Design pattern review

- Branch: `design-pattern-review`
- Display modes, visibility, webhook events, refresh jobs, and admin commands are strategies. The container builds the registries that select them.
- Optional is not used as a parameter or return type.
- Line coverage: 96.46% (1062/1101).
- Infection covered MSI: 89%.

## Next

- Redacted rows keep the date and the amount, and omit the description and the counterparty.
- Transactions stay in MySQL. Redis serves month views and remains up across a blue-green install.
